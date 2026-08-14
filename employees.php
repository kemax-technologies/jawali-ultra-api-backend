<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 👥 Jawali Ultra — API الموظفين والرواتب (المرحلة 6 من إعادة التصميم)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /employees.php                          — قائمة الموظفين
 *   GET    /employees.php?id=EMP-XXX               — موظف محدد
 *   GET    /employees.php?payroll=1                — كل عمليات صرف الرواتب
 *   GET    /employees.php?payroll=1&employee_id=X  — رواتب موظف محدد
 *   POST   /employees.php                          — إنشاء/تحديث موظف
 *   POST   /employees.php?action=pay               — صرف راتب (ينشئ payroll_run ويحدّث الصندوق)
 *   DELETE /employees.php?id=EMP-XXX               — تعطيل موظف (مدير فقط)
 *   DELETE /employees.php?payroll=1&id=PR-XXX      — حذف سجل راتب (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    // ─────────────────────────────────────────────────────────────────────────
    // GET
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        if (isset($_GET['payroll'])) {
            $sql  = 'SELECT * FROM payroll_runs WHERE tenant_id = ?';
            $args = [$tenantId];
            if (!empty($_GET['employee_id'])) {
                $sql .= ' AND employee_id = ?';
                $args[] = $_GET['employee_id'];
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 1000';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            json_ok($stmt->fetchAll());
        }

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM employees WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $tenantId]);
            json_ok($stmt->fetch() ?: []);
        }

        $stmt = $pdo->prepare('SELECT * FROM employees WHERE tenant_id = ? ORDER BY created_at ASC');
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — إنشاء/تحديث موظف، أو صرف راتب
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $action = $_GET['action'] ?? '';
        $body   = input_json();

        // ── صرف راتب ────────────────────────────────────────────────────────
        if ($action === 'pay') {
            $employeeId = trim($body['employee_id'] ?? $body['employeeId'] ?? '');
            $period     = trim($body['period'] ?? '');
            $allowances = (float)($body['allowances'] ?? 0);
            $deductions = (float)($body['deductions'] ?? 0);
            $cashAccountId = trim($body['cash_account_id'] ?? $body['cashAccountId'] ?? '');
            $notes = $body['notes'] ?? '';

            if ($employeeId === '' || $period === '') {
                json_error('employee_id و period مطلوبان');
            }

            $empStmt = $pdo->prepare('SELECT * FROM employees WHERE id = ? AND tenant_id = ? LIMIT 1');
            $empStmt->execute([$employeeId, $tenantId]);
            $emp = $empStmt->fetch();
            if (!$emp) json_error('الموظف غير موجود', 404);

            $baseSalary = (float)$emp['base_salary'];
            $netAmount  = $baseSalary + $allowances - $deductions;
            if ($netAmount < 0) json_error('صافي الراتب لا يمكن أن يكون سالباً');

            $id = 'PR-' . round(microtime(true) * 1000);

            try {
                $pdo->beginTransaction();

                if ($cashAccountId !== '') {
                    // 🔧 إصلاح جوهري خطير (فحص شامل لنظام الصناديق والبنوك —
                    // منع الازدواجية/التعارض في البيانات): نفس نمط ثغرة السباق
                    // الموثّقة في cashboxes.php/vouchers.php/transfers.php —
                    // قفل الصف بـ FOR UPDATE واشتراط "balance >= netAmount"
                    // صراحة في WHERE الخاص بالخصم (مع فحص rowCount()) لمنع صرف
                    // راتبين متزامنين من نفس الصندوق من إفراغه لرصيد سالب.
                    $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
                    $accStmt->execute([$cashAccountId, $tenantId]);
                    $acc = $accStmt->fetch();
                    if (!$acc) {
                        $pdo->rollBack();
                        json_error('الصندوق المحدد غير موجود', 404);
                    }
                    // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك): منع صرف
                    // راتب بعملة تخالف عملة الصندوق المحدد — نفس نمط الثغرة
                    // المُصلَحة سابقاً في cashboxes.php/vouchers.php/transfers.php.
                    // بدون هذا الفحص كان يُخصَم الرقم الحرفي لصافي الراتب (بعملة
                    // الموظف) من صندوق بعملة مختلفة بلا أي تحويل بسعر الصرف.
                    if ($acc['currency'] !== $emp['currency']) {
                        $pdo->rollBack();
                        json_error(
                            'عملة الموظف (' . $emp['currency'] . ') لا تطابق عملة الصندوق (' . $acc['currency'] . ')'
                        );
                    }
                    if ((float)$acc['balance'] < $netAmount) {
                        $pdo->rollBack();
                        json_error('الرصيد غير كافٍ في الصندوق المحدد لصرف الراتب');
                    }
                    $updAcc = $pdo->prepare(
                        'UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ? AND balance >= ?'
                    );
                    $updAcc->execute([$netAmount, $cashAccountId, $tenantId, $netAmount]);
                    if ($updAcc->rowCount() === 0) {
                        $pdo->rollBack();
                        json_error('الرصيد غير كافٍ في الصندوق المحدد لصرف الراتب');
                    }

                    $txId = 'TX-' . round(microtime(true) * 1000);
                    $pdo->prepare(
                        'INSERT INTO cash_transactions (id, account_id, type, amount, currency, notes, created_by, tenant_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $txId, $cashAccountId, 'صرف راتب', $netAmount, $emp['currency'],
                        "راتب {$emp['name']} - $period", $_SERVER['HTTP_X_USER_EMAIL'] ?? null, $tenantId,
                    ]);
                }

                $ins = $pdo->prepare(
                    'INSERT INTO payroll_runs
                       (id, employee_id, period, base_salary, allowances, deductions,
                        net_amount, currency, cash_account_id, status, paid_at, notes, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), \'paid\', NOW(), ?, ?)'
                );
                $ins->execute([
                    $id, $employeeId, $period, $baseSalary, $allowances, $deductions,
                    $netAmount, $emp['currency'], $cashAccountId, $notes, $tenantId,
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][employees] فشل صرف الراتب: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }

            audit("pay salary $id employee=$employeeId period=$period net=$netAmount", null, 'info', $tenantId);
            json_ok(['success' => true, 'id' => $id, 'net_amount' => $netAmount]);
            break;
        }

        // ── إنشاء/تحديث موظف ────────────────────────────────────────────────
        $id   = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? '');
        if ($name === '') json_error('اسم الموظف مطلوب');
        $isNew = $id === '';
        if ($isNew) $id = 'EMP-' . round(microtime(true) * 1000);

        $phone      = $body['phone']      ?? '';
        $jobTitle   = $body['job_title']  ?? $body['jobTitle']   ?? '';
        $department = $body['department'] ?? '';
        $baseSalary = (float)($body['base_salary'] ?? $body['baseSalary'] ?? 0);
        $currency   = strtoupper($body['currency'] ?? 'YER');
        $hireDate   = $body['hire_date']  ?? $body['hireDate']   ?? null;
        $status     = $body['status'] ?? 'active';
        $notes      = $body['notes'] ?? '';

        if ($isNew) {
            $stmt = $pdo->prepare(
                'INSERT INTO employees
                   (id, name, phone, job_title, department, base_salary, currency, hire_date, status, notes, tenant_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\')::date, ?, ?, ?)'
            );
            $stmt->execute([
                $id, $name, $phone, $jobTitle, $department, $baseSalary,
                $currency, $hireDate, $status, $notes, $tenantId,
            ]);
            audit("create employee $id ($name)", null, 'info', $tenantId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE employees SET
                    name = ?, phone = ?, job_title = ?, department = ?,
                    base_salary = ?, currency = ?, status = ?, notes = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([
                $name, $phone, $jobTitle, $department, $baseSalary,
                $currency, $status, $notes, $id, $tenantId,
            ]);
            if ($stmt->rowCount() === 0) json_error('الموظف غير موجود في متجرك', 404);
            audit("update employee $id ($name)", null, 'info', $tenantId);
        }
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);

        if (isset($_GET['payroll'])) {
            $id = $_GET['id'] ?? '';
            if ($id === '') json_error('id مطلوب');
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM payroll_runs WHERE id = ? AND tenant_id = ? LIMIT 1');
                $stmt->execute([$id, $tenantId]);
                $pr = $stmt->fetch();
                if (!$pr) {
                    $pdo->rollBack();
                    json_error('سجل الراتب غير موجود', 404);
                }
                if (!empty($pr['cash_account_id']) && $pr['status'] === 'paid') {
                    $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$pr['net_amount'], $pr['cash_account_id'], $tenantId]);
                    $txId = 'TX-' . round(microtime(true) * 1000);
                    $pdo->prepare(
                        'INSERT INTO cash_transactions (id, account_id, type, amount, currency, notes, created_by, tenant_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $txId, $pr['cash_account_id'], 'عكس راتب محذوف',
                        $pr['net_amount'], $pr['currency'], "عكس راتب {$pr['period']}",
                        $_SERVER['HTTP_X_USER_EMAIL'] ?? null, $tenantId,
                    ]);
                }
                $pdo->prepare('DELETE FROM payroll_runs WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][employees] فشل حذف سجل الراتب: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }
            audit("delete payroll run $id", null, 'warning', $tenantId);
            json_ok(['success' => true]);
            break;
        }

        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $upd = $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE id = ? AND tenant_id = ?");
        $upd->execute([$id, $tenantId]);
        if ($upd->rowCount() === 0) json_error('الموظف غير موجود في متجرك', 404);
        audit("deactivate employee $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
