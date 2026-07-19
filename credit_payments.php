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
        require_auth();
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM credit_payments WHERE id = ? LIMIT 1');
            $stmt->execute([$_GET['id']]);
            json_ok($stmt->fetch() ?: []);
        }
        $sql  = 'SELECT * FROM credit_payments WHERE 1=1';
        $args = [];
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
        require_auth();
        $body = input_json();

        $id        = $body['id']        ?? ('PM-' . round(microtime(true) * 1000));
        $creditId  = trim($body['credit_id']  ?? $body['creditId']  ?? '');
        $currency  = strtoupper($body['currency'] ?? 'YER');
        $amount    = (float)($body['amount'] ?? 0);
        $rateInput = (float)($body['exchange_rate'] ?? $body['exchangeRate'] ?? 0);
        $method_   = $body['method'] ?? 'نقدي';
        $notes     = $body['notes']  ?? '';

        if ($creditId === '' || $amount <= 0) {
            json_error('credit_id و amount مطلوبان');
        }

        // اجلب القيد الأساسي
        $stmt = $pdo->prepare('SELECT * FROM credits WHERE id = ? LIMIT 1');
        $stmt->execute([$creditId]);
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

            // 1) أدرج الدفعة
            $ins = $pdo->prepare(
                'INSERT INTO credit_payments
                   (id, credit_id, customer_phone, amount_yer, amount_usd,
                    exchange_rate, currency, method, date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
            );
            $ins->execute([
                $id, $creditId, $customerPhone,
                $amountYer, $amountUsd, $rate,
                $currency, $method_, $notes,
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
                 WHERE id = ?'
            );
            $upd->execute([$newPaidYer, $newPaidUsd, $newStatus, $creditId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][credit_payments] فشل تسجيل الدفعة: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("payment $id on credit $creditId amount=$amountYer YER ($currency)");
        json_ok([
            'success'         => true,
            'id'              => $id,
            'amount_yer'      => $amountYer,
            'amount_usd'      => $amountUsd,
            'exchange_rate'   => $rate,
            'new_status'      => $newStatus,
            'remaining_yer'   => max(0, $remaining),
        ]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف دفعة (مدير فقط) + إعادة احتساب رصيد القيد
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        require_admin();
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');

        try {
            $pdo->beginTransaction();
            // اجلب الدفعة
            $stmt = $pdo->prepare('SELECT * FROM credit_payments WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $pmt = $stmt->fetch();
            if (!$pmt) {
                $pdo->rollBack();
                json_error('الدفعة غير موجودة', 404);
            }
            // احذف الدفعة
            $pdo->prepare('DELETE FROM credit_payments WHERE id = ?')->execute([$id]);
            // أعد احتساب القيد
            $sum = $pdo->prepare(
                'SELECT COALESCE(SUM(amount_yer),0) AS y, COALESCE(SUM(amount_usd),0) AS u
                 FROM credit_payments WHERE credit_id = ?'
            );
            $sum->execute([$pmt['credit_id']]);
            $row = $sum->fetch();
            $pdo->prepare(
                'UPDATE credits SET paid_yer = ?, paid_usd = ?,
                   status = CASE
                     WHEN (amount_yer - ?) < 0.01 THEN \'مسدّد بالكامل\'
                     WHEN ? > 0 THEN \'مسدّد جزئياً\'
                     ELSE \'مفتوح\'
                   END
                 WHERE id = ?'
            )->execute([
                $row['y'], $row['u'], $row['y'], $row['y'], $pmt['credit_id'],
            ]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][credit_payments] فشل الحذف: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("delete payment $id", null, 'warning');
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
