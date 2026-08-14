<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🧾 Jawali Ultra — API سندات القبض والصرف (المرحلة 5 من إعادة التصميم)
 * سند قبض (receipt) = إيداع في صندوق/بنك | سند صرف (payment) = سحب من صندوق/بنك
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /vouchers.php                          — قائمة كل السندات
 *   GET    /vouchers.php?type=receipt             — سندات القبض فقط
 *   GET    /vouchers.php?type=payment             — سندات الصرف فقط
 *   GET    /vouchers.php?id=V-XXX                 — سند محدد
 *   POST   /vouchers.php                          — إنشاء سند جديد (يحدّث رصيد الصندوق تلقائياً)
 *   DELETE /vouchers.php?id=V-XXX                 — حذف سند (مدير فقط) + عكس أثره على الرصيد
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

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$_GET['id'], $tenantId]);
            json_ok($stmt->fetch() ?: []);
        }

        $sql  = 'SELECT * FROM vouchers WHERE tenant_id = ?';
        $args = [$tenantId];
        if (!empty($_GET['type'])) {
            $sql .= ' AND type = ?';
            $args[] = $_GET['type'];
        }
        $sql .= ' ORDER BY date DESC LIMIT 1000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST — إنشاء سند قبض/صرف + تحديث رصيد الصندوق (إن وُجد)
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $body = input_json();

        $type = $body['type'] ?? 'receipt'; // receipt | payment
        if (!in_array($type, ['receipt', 'payment'], true)) {
            json_error('type يجب أن يكون receipt أو payment');
        }

        $partyName  = trim($body['party_name']  ?? $body['partyName']  ?? '');
        $partyPhone = trim($body['party_phone'] ?? $body['partyPhone'] ?? '');
        $amount     = (float)($body['amount'] ?? 0);
        $currency   = strtoupper($body['currency'] ?? 'YER');
        $category   = $body['category'] ?? '';
        $description = $body['description'] ?? $body['notes'] ?? '';
        $cashAccountId = trim($body['cash_account_id'] ?? $body['cashAccountId'] ?? '');

        if ($amount <= 0) json_error('amount مطلوب ويجب أن يكون أكبر من صفر');
        if ($partyName === '') json_error('party_name مطلوب');

        $id = $body['id'] ?? ('V-' . round(microtime(true) * 1000));
        $voucherNumber = $body['voucher_number'] ?? $body['voucherNumber']
            ?? (($type === 'receipt') ? 'REC-' : 'PAY-') . substr($id, -6);

        try {
            $pdo->beginTransaction();

            // إذا مرتبط بصندوق: حدّث الرصيد + سجل حركة صندوق
            if ($cashAccountId !== '') {
                // 🔧 إصلاح جوهري خطير (فحص شامل لنظام الصناديق والبنوك — منع
                // الازدواجية/التعارض في البيانات): كان الفحص عن الصندوق/كفاية
                // الرصيد يتم بـ SELECT عادي بلا قفل صف (FOR UPDATE)، وجملة
                // UPDATE اللاحقة تُنفَّذ بلا أي شرط على الرصيد الحالي في
                // WHERE. نفس نمط ثغرة السباق الموثّقة في cashboxes.php أعلاه:
                // طلبا سند صرف متزامنان من نفس الصندوق (رصيده يكفي لسند واحد
                // فقط) كانا يمكن أن يجتازا فحص الكفاية معاً فيصبح الرصيد
                // سالباً. الإصلاح: قفل الصف بـ FOR UPDATE، ولسند الصرف تحديداً
                // اشتراط "balance >= amount" صراحة في WHERE مع فحص rowCount()
                // كدفاع أخير.
                $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
                $accStmt->execute([$cashAccountId, $tenantId]);
                $acc = $accStmt->fetch();
                if (!$acc) {
                    $pdo->rollBack();
                    json_error('الصندوق/الحساب غير موجود', 404);
                }

                // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك): منع
                // إنشاء سند بعملة تخالف عملة الصندوق المرتبط — بدون هذا
                // الفحص كان يُطبَّق المبلغ حرفياً (مثلاً 100 دولار) على
                // رصيد صندوق بعملة أخرى (ريال) بلا أي تحويل بسعر الصرف،
                // ما يُفسد الرصيد الفعلي فوراً. (طبقة حماية خادم مطابقة
                // للحارس المُضاف مسبقاً على مستوى العميل في app_store.dart)
                if ($acc['currency'] !== $currency) {
                    $pdo->rollBack();
                    json_error(
                        'عملة السند (' . $currency . ') لا تطابق عملة الصندوق (' . $acc['currency'] . ')'
                    );
                }

                if ($type === 'payment' && (float)$acc['balance'] < $amount) {
                    $pdo->rollBack();
                    json_error('الرصيد غير كافٍ في الصندوق المحدد');
                }

                $delta = ($type === 'receipt') ? $amount : -$amount;
                if ($type === 'payment') {
                    $updAcc = $pdo->prepare(
                        'UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ? AND balance >= ?'
                    );
                    $updAcc->execute([$delta, $cashAccountId, $tenantId, $amount]);
                    if ($updAcc->rowCount() === 0) {
                        $pdo->rollBack();
                        json_error('الرصيد غير كافٍ في الصندوق المحدد');
                    }
                } else {
                    $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$delta, $cashAccountId, $tenantId]);
                }

                $txId = 'TX-' . round(microtime(true) * 1000);
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $tenantId, $cashAccountId,
                    $type === 'receipt' ? 'سند قبض' : 'سند صرف',
                    $amount, $currency,
                    "سند $voucherNumber - $partyName",
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);
            }

            // أدرج السند نفسه
            $ins = $pdo->prepare(
                'INSERT INTO vouchers
                   (id, tenant_id, type, voucher_number, party_name, party_phone, cash_account_id,
                    amount, currency, category, description, date, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?, ?, ?, NOW(), ?)'
            );
            $ins->execute([
                $id, $tenantId, $type, $voucherNumber, $partyName, $partyPhone, $cashAccountId,
                $amount, $currency, $category, $description,
                $_SERVER['HTTP_X_USER_EMAIL'] ?? '',
            ]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][vouchers] فشل إنشاء السند: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("create voucher $id type=$type amount=$amount $currency party=$partyName", null, 'info', $tenantId);
        json_ok([
            'success'        => true,
            'id'             => $id,
            'voucher_number' => $voucherNumber,
        ]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE — حذف سند (مدير فقط) + عكس أثره على رصيد الصندوق إن وُجد
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');

        try {
            $pdo->beginTransaction();
            // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك — منع
            // الازدواجية/التعارض في البيانات): قفل صف السند بـ FOR UPDATE —
            // بدونه، طلبا حذف متزامنان لنفس السند (نادر لكن ممكن) كانا
            // يمكن أن يقرآ نفس البيانات معاً قبل حذف أيٍّ منهما للسجل،
            // فيُعكَس أثر السند على رصيد الصندوق *مرتين* رغم وجود سند واحد
            // فقط. القفل يجعل الطلب الثاني ينتظر انتهاء معاملة الأول، وعندها
            // لن يجد السند (محذوف بالفعل) ويُرفض بأمان بخطأ 404.
            $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$id, $tenantId]);
            $v = $stmt->fetch();
            if (!$v) {
                $pdo->rollBack();
                json_error('السند غير موجود', 404);
            }

            // اعكس أثر السند على الصندوق إن كان مرتبطاً
            if (!empty($v['cash_account_id'])) {
                $delta = ($v['type'] === 'receipt') ? -(float)$v['amount'] : (float)$v['amount'];
                $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$delta, $v['cash_account_id'], $tenantId]);

                $txId = 'TX-' . round(microtime(true) * 1000);
                $pdo->prepare(
                    'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $tenantId, $v['cash_account_id'], 'عكس سند محذوف',
                    $v['amount'], $v['currency'],
                    "عكس سند {$v['voucher_number']}",
                    $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                ]);
            }

            $pdo->prepare('DELETE FROM vouchers WHERE id = ? AND tenant_id = ?')->execute([$id, $tenantId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][vouchers] فشل الحذف: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("delete voucher $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
