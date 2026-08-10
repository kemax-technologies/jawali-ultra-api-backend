<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 💳 Jawali Ultra — API الذمم المالية والديون
 * تسعير مزدوج (ر.ي + دولار) مع تتبع المدفوعات والاستحقاقات
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /credits.php                       — قائمة كل الذمم
 *   GET    /credits.php?id=CR-XXX             — قيد محدد
 *   GET    /credits.php?customer=770000001    — كل ديون عميل
 *   GET    /credits.php?status=مفتوح          — تصفية بالحالة
 *   GET    /credits.php?overdue=1             — الذمم المتأخرة فقط
 *   GET    /credits.php?summary=1             — ملخص إحصائي
 *   POST   /credits.php                       — إنشاء قيد دَين جديد
 *   PUT    /credits.php?id=CR-XXX             — تحديث القيد
 *   DELETE /credits.php?id=CR-XXX             — حذف القيد (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    // ─────────────────────────────────────────────────────────────────────────
    // GET — جلب الذمم
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        // ملخص إحصائي
        if (isset($_GET['summary'])) {
            // 🆕 نفصل هنا بوضوح بين قيود "عليه" (debit — ذمم مستحقة لنا)
            // وقيود "له" (credit — أرصدة مستحقة للعملاء علينا)، بدلاً من
            // جمعهما معاً في حقل واحد مضلِّل. هذا يطابق نفس منطق التمييز
            // المُطبَّق في تطبيق Flutter (AppStore.totalCreditRemaining
            // تحتسب "عليه" فقط، وAppStore.totalPayableToCustomers تحتسب
            // "له" فقط) — حتى لا يُعطي هذا الـ endpoint نتائج تُخالف تلك
            // المستخدَمة فعلياً في الواجهة إذا استُخدم مستقبلاً.
            $stmt = $pdo->prepare("
                SELECT
                  COUNT(*)                        AS total_credits,
                  SUM(amount_yer)                 AS total_amount_yer,
                  SUM(amount_usd)                 AS total_amount_usd,
                  SUM(paid_yer)                   AS total_paid_yer,
                  SUM(paid_usd)                   AS total_paid_usd,
                  SUM(CASE WHEN direction = 'debit'
                           THEN (amount_yer - paid_yer) ELSE 0 END) AS total_remaining_yer,
                  SUM(CASE WHEN direction = 'debit'
                           THEN (amount_usd - paid_usd) ELSE 0 END) AS total_remaining_usd,
                  SUM(CASE WHEN direction = 'credit'
                           THEN (amount_yer - paid_yer) ELSE 0 END) AS total_payable_yer,
                  SUM(CASE WHEN direction = 'credit'
                           THEN (amount_usd - paid_usd) ELSE 0 END) AS total_payable_usd,
                  SUM(CASE WHEN (amount_yer - paid_yer) > 0.01 THEN 1 ELSE 0 END) AS open_count,
                  SUM(CASE WHEN due_date IS NOT NULL AND due_date < NOW()
                              AND (amount_yer - paid_yer) > 0.01 THEN 1 ELSE 0 END) AS overdue_count
                FROM credits
                WHERE tenant_id = ?
            ");
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch();
            json_ok($row ?: []);
        }

        // قيد محدد بالـ ID
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM credits WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $tenantId]);
            $credit = $stmt->fetch();
            if (!$credit) json_error('القيد غير موجود', 404);
            // إرفاق سجل الدفعات
            $pmts = $pdo->prepare('SELECT * FROM credit_payments WHERE credit_id = ? AND tenant_id = ? ORDER BY date DESC');
            $pmts->execute([$_GET['id'], $tenantId]);
            $credit['payments'] = $pmts->fetchAll();
            json_ok($credit);
        }

        // فلاتر متعددة
        $sql  = 'SELECT * FROM credits WHERE tenant_id = ?';
        $args = [$tenantId];
        if (!empty($_GET['customer'])) {
            $sql .= ' AND customer_phone = ?';
            $args[] = $_GET['customer'];
        }
        if (!empty($_GET['invoice'])) {
            $sql .= ' AND invoice_id = ?';
            $args[] = $_GET['invoice'];
        }
        if (!empty($_GET['status'])) {
            $sql .= ' AND status = ?';
            $args[] = $_GET['status'];
        }
        if (!empty($_GET['overdue'])) {
            $sql .= ' AND due_date IS NOT NULL AND due_date < NOW() AND (amount_yer - paid_yer) > 0.01';
        }
        if (!empty($_GET['open'])) {
            $sql .= ' AND (amount_yer - paid_yer) > 0.01';
        }
        $sql .= ' ORDER BY date DESC LIMIT 1000';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — إنشاء قيد دَين جديد (عند البيع الآجل)
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $body = input_json();

        $id            = $body['id']            ?? ('CR-' . round(microtime(true) * 1000));
        $customerPhone = trim($body['customer_phone'] ?? $body['customerPhone'] ?? '');
        $invoiceId     = trim($body['invoice_id']     ?? $body['invoiceId']     ?? '');
        $amountYer     = (float)($body['amount_yer']  ?? $body['amountYer']    ?? 0);
        $exchangeRate  = (float)($body['exchange_rate'] ?? $body['exchangeRate'] ?? 530);
        $amountUsd     = (float)($body['amount_usd']  ?? $body['amountUsd']
                          ?? ($exchangeRate > 0 ? $amountYer / $exchangeRate : 0));
        $dueDate       = $body['due_date']  ?? $body['dueDate']  ?? null;
        $notes         = $body['notes']     ?? '';
        $status        = $body['status']    ?? 'مفتوح';
        // 🆕 اتجاه القيد: 'debit' = عليه (مستحق من العميل — الافتراضي
        // التاريخي)، 'credit' = له (مستحق للعميل). يُتحقَّق من القيمة هنا
        // أيضاً على مستوى التطبيق كطبقة دفاع ثانية إلى جانب قيد CHECK في
        // قاعدة البيانات (راجع migrations/010_add_credit_direction.sql).
        $direction     = $body['direction'] ?? 'debit';
        if (!in_array($direction, ['debit', 'credit'], true)) {
            $direction = 'debit';
        }

        if ($customerPhone === '' || $invoiceId === '' || $amountYer <= 0) {
            json_error('customer_phone و invoice_id و amount_yer مطلوبة');
        }

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE ... VALUES(col)
        //    → ON CONFLICT (id) DO UPDATE SET ... = EXCLUDED.col
        $stmt = $pdo->prepare(
            'INSERT INTO credits
               (id, tenant_id, customer_phone, invoice_id, amount_yer, amount_usd, exchange_rate,
                paid_yer, paid_usd, status, date, due_date, notes, direction)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW(), ?, ?, ?)
             ON CONFLICT (id) DO UPDATE SET
                amount_yer    = EXCLUDED.amount_yer,
                amount_usd    = EXCLUDED.amount_usd,
                exchange_rate = EXCLUDED.exchange_rate,
                status        = EXCLUDED.status,
                due_date      = EXCLUDED.due_date,
                notes         = EXCLUDED.notes,
                direction     = EXCLUDED.direction
             WHERE credits.tenant_id = EXCLUDED.tenant_id'
        );
        $stmt->execute([
            $id, $tenantId, $customerPhone, $invoiceId,
            $amountYer, $amountUsd, $exchangeRate,
            $status, $dueDate, $notes, $direction,
        ]);
        audit("create credit $id for $customerPhone amount=$amountYer YER", null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT — تحديث قيد (تاريخ الاستحقاق، الملاحظات، الحالة)
    // ─────────────────────────────────────────────────────────────────────────
    case 'PUT': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $body = input_json();
        $sets = [];
        $args = [];
        foreach (['due_date' => 'due_date', 'dueDate' => 'due_date',
                  'notes' => 'notes', 'status' => 'status',
                  'direction' => 'direction'] as $k => $col) {
            if (array_key_exists($k, $body)) {
                $val = $body[$k];
                // 🆕 حماية إضافية عند تحديث الاتجاه عبر PUT — لا نقبل إلا
                // 'debit' أو 'credit' (راجع نفس القيد في مسار POST أعلاه).
                if ($col === 'direction' && !in_array($val, ['debit', 'credit'], true)) {
                    continue;
                }
                $sets[] = "$col = ?";
                $args[] = $val;
            }
        }
        if (!$sets) json_error('لا توجد حقول للتحديث');
        $args[] = $id;
        $args[] = $tenantId;
        $stmt = $pdo->prepare('UPDATE credits SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?');
        $stmt->execute($args);
        if ($stmt->rowCount() === 0) json_error('القيد غير موجود في متجرك', 404);
        audit("update credit $id", null, 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف قيد (للمدير فقط)
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $stmt = $pdo->prepare('DELETE FROM credits WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        if ($stmt->rowCount() === 0) json_error('القيد غير موجود في متجرك', 404);
        audit("delete credit $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
