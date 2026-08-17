<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 💵 Jawali Ultra — API دفعات سداد الذمم
 * تسجيل المدفوعات بعملتين (ر.ي / دولار) مع تحديث رصيد القيد تلقائياً
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /credit_payments.php                       — قائمة كل الدفعات
 *   GET    /credit_payments.php?credit_id=CR-XXX      — دفعات قيد محدد
 *   GET    /credit_payments.php?customer=770000001    — دفعات عميل
 *   GET    /credit_payments.php?id=PM-XXX             — دفعة محددة
 *   POST   /credit_payments.php                       — تسجيل دفعة جديدة
 *   DELETE /credit_payments.php?id=PM-XXX             — حذف دفعة (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    // ─────────────────────────────────────────────────────────────────────────
    // GET
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM credit_payments WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $tenantId]);
            json_ok($stmt->fetch() ?: []);
        }
        $sql  = 'SELECT * FROM credit_payments WHERE tenant_id = ?';
        $args = [$tenantId];
        if (!empty($_GET['credit_id'])) {
            $sql .= ' AND credit_id = ?';
            $args[] = $_GET['credit_id'];
        }
        if (!empty($_GET['customer'])) {
            $sql .= ' AND customer_phone = ?';
            $args[] = $_GET['customer'];
        }
        $sql .= ' ORDER BY date DESC LIMIT 1000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — تسجيل دفعة سداد + تحديث رصيد القيد تلقائياً
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $body = input_json();

        $id        = $body['id']        ?? ('PM-' . round(microtime(true) * 1000));
        $creditId  = trim($body['credit_id']  ?? $body['creditId']  ?? '');
        $currency  = strtoupper($body['currency'] ?? 'YER');
        $amount    = (float)($body['amount'] ?? 0);
        $rateInput = (float)($body['exchange_rate'] ?? $body['exchangeRate'] ?? 0);
        $method_   = $body['method'] ?? 'نقدي';
        $notes     = $body['notes']  ?? '';
        // 🆕 (فحص معماري شامل — ربط حقيقي محاسبة↔عملاء↔ذمم): الصندوق/البنك
        // الذي استُلمت فيه الدفعة فعلياً — اختياري، يُترك فارغاً إن لم
        // تُحدَّد أي وسيلة استلام نقدية موثَّقة بعد.
        $cashAccountId = trim($body['cash_account_id'] ?? $body['cashAccountId'] ?? '');

        if ($creditId === '' || $amount <= 0) {
            json_error('credit_id و amount مطلوبان');
        }

        // اجلب القيد الأساسي
        $stmt = $pdo->prepare('SELECT * FROM credits WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$creditId, $tenantId]);
        $credit = $stmt->fetch();
        if (!$credit) json_error('قيد الدَّين غير موجود', 404);

        // سعر الصرف: من الطلب أو من القيد الأصلي
        $rate = $rateInput > 0 ? $rateInput : (float)$credit['exchange_rate'];
        if ($rate <= 0) $rate = 530;

        // احسب المعادل بكلا العملتين
        if ($currency === 'USD') {
            $amountUsd = $amount;
            $amountYer = $amount * $rate;
        } else {
            $amountYer = $amount;
            $amountUsd = $amount / $rate;
        }
        $customerPhone = $body['customer_phone']
            ?? $body['customerPhone']
            ?? $credit['customer_phone'];

        try {
            $pdo->beginTransaction();

            // 🆕 0) إن كانت الدفعة مرتبطة بصندوق/بنك فعلي: نفس بالضبط نمط
            // vouchers.php (قفل الصف FOR UPDATE + تحقق تطابق العملة + تحديث
            // الرصيد فوراً + تسجيل حركة صندوق) — يضمن أن التحصيل النقدي
            // الموثَّق هنا هو نفسه الذي يُستخدم لاحقاً كأساس الترحيل
            // المحاسبي التلقائي الصحيح (مدين: الصندوق ← دائن: الذمم المدينة).
            if ($cashAccountId !== '') {
                $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
                $accStmt->execute([$cashAccountId, $tenantId]);
                $acc = $accStmt->fetch();
                if (!$acc) {
                    $pdo->rollBack();
                    json_error('الصندوق/الحساب غير موجود', 404);
                }
                if ($acc['currency'] !== $currency) {
                    $pdo->rollBack();
                    json_error(
                        'عملة الدفعة (' . $currency . ') لا تطابق عملة الصندوق (' . $acc['currency'] . ')'
                    );
                }

                $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$amount, $cashAccountId, $tenantId]);

                $txId = 'TX-' . round(microtime(true) * 1000);
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $tenantId, $cashAccountId, 'تحصيل دفعة ذمم',
                    $amount, $currency,
                    "دفعة $id على القيد $creditId",
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);
            }

            // 1) أدرج الدفعة
            $ins = $pdo->prepare(
                'INSERT INTO credit_payments
                   (id, tenant_id, credit_id, customer_phone, amount_yer, amount_usd,
                    exchange_rate, currency, method, date, notes, cash_account_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, NULLIF(?, \'\'))'
            );
            $ins->execute([
                $id, $tenantId, $creditId, $customerPhone,
                $amountYer, $amountUsd, $rate,
                $currency, $method_, $notes, $cashAccountId,
            ]);

            // 2) حدّث رصيد القيد
            $newPaidYer = (float)$credit['paid_yer'] + $amountYer;
            $newPaidUsd = (float)$credit['paid_usd'] + $amountUsd;
            $remaining  = (float)$credit['amount_yer'] - $newPaidYer;

            $newStatus = 'مفتوح';
            if ($remaining < 0.01) {
                $newStatus = 'مسدّد بالكامل';
            } elseif ($newPaidYer > 0) {
                $isOverdue = !empty($credit['due_date'])
                    && strtotime($credit['due_date']) < time();
                $newStatus = $isOverdue ? 'متأخر' : 'مسدّد جزئياً';
            }

            $upd = $pdo->prepare(
                'UPDATE credits SET paid_yer = ?, paid_usd = ?, status = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $upd->execute([$newPaidYer, $newPaidUsd, $newStatus, $creditId, $tenantId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][credit_payments] فشل تسجيل الدفعة: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("payment $id on credit $creditId amount=$amountYer YER ($currency) cash_account=" . ($cashAccountId ?: 'none'), null, 'info', $tenantId);
        json_ok([
            'success'         => true,
            'id'              => $id,
            'amount_yer'      => $amountYer,
            'amount_usd'      => $amountUsd,
            'exchange_rate'   => $rate,
            'new_status'      => $newStatus,
            'remaining_yer'   => max(0, $remaining),
            'cash_account_id' => $cashAccountId,
        ]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف دفعة (مدير فقط) + إعادة احتساب رصيد القيد
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');

        try {
            $pdo->beginTransaction();
            // اجلب الدفعة (قفل صف — يمنع حذفاً متزامناً مزدوجاً يعكس أثر
            // الصندوق مرتين، بنفس نمط الحماية في vouchers.php DELETE)
            $stmt = $pdo->prepare('SELECT * FROM credit_payments WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$id, $tenantId]);
            $pmt = $stmt->fetch();
            if (!$pmt) {
                $pdo->rollBack();
                json_error('الدفعة غير موجودة', 404);
            }

            // 🆕 (فحص معماري شامل — سلامة البيانات): إن كانت الدفعة مرتبطة
            // بصندوق فعلي، يجب عكس أثرها النقدي عند الحذف — وإلا يبقى رصيد
            // الصندوق مُتضخِّماً بمبلغ دفعة لم تعد موجودة (بنفس تماماً منطق
            // عكس أثر السند المحذوف في vouchers.php).
            if (!empty($pmt['cash_account_id'])) {
                // المبلغ الذي فعلياً دخل الصندوق هو بعملة الدفعة الأصلية
                // (amount_yer إن كانت العملة YER، أو amount_usd إن كانت USD)
                // — تماماً كما وُثِّق عند الإنشاء (حارس تطابق العملة يمنع
                // ربط صندوق بعملة مخالفة أصلاً).
                $reverseAmount = (strtoupper($pmt['currency'] ?? 'YER') === 'USD')
                    ? (float)$pmt['amount_usd']
                    : (float)$pmt['amount_yer'];
                $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$reverseAmount, $pmt['cash_account_id'], $tenantId]);

                $txId = 'TX-' . round(microtime(true) * 1000);
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $tenantId, $pmt['cash_account_id'], 'عكس دفعة ذمم محذوفة',
                    -$reverseAmount, $pmt['currency'] ?? 'YER',
                    "عكس دفعة $id",
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);
            }

            // احذف الدفعة
            $pdo->prepare('DELETE FROM credit_payments WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            // أعد احتساب القيد
            $sum = $pdo->prepare(
                'SELECT COALESCE(SUM(amount_yer),0) AS y, COALESCE(SUM(amount_usd),0) AS u
                 FROM credit_payments WHERE credit_id = ? AND tenant_id = ?'
            );
            $sum->execute([$pmt['credit_id'], $tenantId]);
            $row = $sum->fetch();
            // 🛠️ ملاحظة إصلاح: يجب تحويد نوع المُعامِلات صراحةً إلى numeric
            // عند مقارنتها بحرفٍ رقمي عاري (0) — وإلا يحاول PostgreSQL
            // تخمين نوع المُعامِل كـ integer فيفشل مع قيم عشرية مثل
            // "20000.00" (خطأ SQLSTATE 22P02 اكتُشف أثناء اختبار التكامل
            // الحي بعد نشر إصلاح cash_account_id — تم إصلاحه فوراً).
            $paidYer = (float)$row['y'];
            $pdo->prepare(
                'UPDATE credits SET paid_yer = ?, paid_usd = ?,
                   status = CASE
                     WHEN (amount_yer - ?::numeric) < 0.01 THEN \'مسدّد بالكامل\'
                     WHEN ?::numeric > 0 THEN \'مسدّد جزئياً\'
                     ELSE \'مفتوح\'
                   END
                 WHERE id = ? AND tenant_id = ?'
            )->execute([
                $paidYer, $row['u'], $paidYer, $paidYer, $pmt['credit_id'], $tenantId,
            ]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][credit_payments] فشل الحذف: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("delete payment $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
