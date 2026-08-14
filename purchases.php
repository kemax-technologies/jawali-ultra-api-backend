<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();  // ✅ إصلاح: حماية GET بالمصادقة
        $tenantId = tenant_id_from_auth($auth);
        $stmt = $pdo->prepare('SELECT * FROM purchases WHERE tenant_id = ? ORDER BY date DESC LIMIT 200');
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['items_json'])) $r['items'] = json_decode($r['items_json'], true) ?: [];
        }
        json_ok($rows);
        break;
    }
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $b   = input_json();
        $id  = trim($b['id'] ?? '');
        if ($id === '') $id = 'PO-' . time();
        $items = $b['items'] ?? [];
        // 🆕 المرحلة 7 من إعادة التصميم: خصم + طريقة دفع + صندوق نقدي
        $discount      = (float)($b['discount'] ?? 0);
        $paymentMethod = trim($b['paymentMethod'] ?? $b['payment_method'] ?? 'نقدي');
        $cashAccountId = trim($b['cashAccountId'] ?? $b['cash_account_id'] ?? '');
        $total         = (float)($b['total'] ?? 0);

        // 🔧 إصلاح جوهري (فحص شامل لنظام الصناديق والبنوك — حالات حدّية):
        // لم يكن هذا الملف يرفض مبلغ فاتورة شراء صفري أو سالب إطلاقاً، على
        // خلاف vouchers.php وtransfers.php وcashboxes.php التي ترفض جميعها
        // amount <= 0 صراحة. فاتورة شراء بمبلغ سالب أو صفري (مع بنود فعلية)
        // غير منطقية مالياً ويمكن أن تُخفي خطأ إدخال (نسيان تعبئة total) أو
        // تُنتج قيداً في cash_transactions لا معنى له. الإصلاح: رفض القيمة
        // الصفرية/السالبة بنفس نمط باقي الملفات — إلا إن لم تحتوِ الفاتورة
        // على أي بنود إطلاقاً (حالة نادرة/غير متوقعة عملياً لكن نتركها بلا
        // رفض تحسباً لاستخدامات مستقبلية كسجل ملاحظة بلا قيمة مالية).
        if ($total <= 0 && is_array($items) && count($items) > 0) {
            json_error('إجمالي فاتورة الشراء يجب أن يكون أكبر من صفر');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO purchases (id, tenant_id, supplier_name, subtotal, discount, tax, total, status, items_json, date, payment_method, cash_account_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,NULLIF(?, \'\'))'
            );
            $stmt->execute([
                $id,
                $tenantId,
                $b['supplier']      ?? $b['supplier_name'] ?? 'مورد',
                (float)($b['subtotal'] ?? 0),
                $discount,
                (float)($b['tax']      ?? 0),
                $total,
                $b['status'] ?? 'مستلمة',
                json_encode($items, JSON_UNESCAPED_UNICODE),
                $b['date'] ?? date('Y-m-d H:i:s'),
                $paymentMethod,
                $cashAccountId,
            ]);

            // زيادة المخزون عند الاستلام
            if (($b['status'] ?? 'مستلمة') === 'مستلمة' && is_array($items)) {
                $up = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE sku = ? AND tenant_id = ?');
                foreach ($items as $it) {
                    $sku = $it['sku'] ?? '';
                    $qty = (int)($it['qty'] ?? 0);
                    if ($sku !== '' && $qty > 0) $up->execute([$qty, $sku, $tenantId]);
                }
            }

            // 💰 المرحلة 7: إذا كانت المشتريات نقدية ومرتبطة بصندوق، اخصم المبلغ
            // من رصيد الصندوق تلقائياً + سجّل حركة صندوق (المشتريات الآجلة لا
            // تُخصم هنا لأنها تُدار عبر رصيد المورد بشكل منفصل)
            //
            // 🔧 إصلاح جوهري خطير (فحص شامل لنظام الصناديق والبنوك — عدم تطابق
            // العملة): لا يوجد حقل "currency" على فاتورة الشراء (كل المبالغ
            // بالريال اليمني ضمنياً — نفس المشكلة المكتشفة والمُصلَحة للتو في
            // invoices.php لفواتير البيع)، وقائمة اختيار الصندوق في شاشة
            // المشتريات (procurement_screen.dart) تعرض كل الصناديق بلا تمييز
            // للعملة. كان هذا الكود يخصم "$total" (ريال يمني حتماً) حرفياً من
            // أي صندوق يُختار — لو كان بعملة أخرى (دولار/ريال سعودي) لفسد
            // رصيده فوراً. الإصلاح: رفض العملية إن كانت عملة الصندوق ليست
            // "YER".
            //
            // 🔧 إصلاح جوهري خطير آخر (ثغرة سباق/Race Condition — قد تجعل
            // الرصيد سالباً): كان الفحص عن الصندوق يتم بـ SELECT عادي بلا قفل
            // صف (FOR UPDATE) وبلا أي فحص لكفاية الرصيد قبل الخصم، وجملة
            // UPDATE لا تشترط أي شيء في WHERE — نفس نمط الثغرة المُصلَحة في
            // باقي ملفات الصناديق. الإصلاح: قفل الصف، فحص كفاية الرصيد، ثم
            // اشتراط "balance >= amount" في WHERE مع فحص rowCount() كدفاع
            // أخير.
            if ($cashAccountId !== '' && $paymentMethod !== 'آجل' && $total > 0) {
                $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? AND tenant_id = ? LIMIT 1 FOR UPDATE');
                $accStmt->execute([$cashAccountId, $tenantId]);
                $acc = $accStmt->fetch();
                if ($acc) {
                    if ($acc['currency'] !== 'YER') {
                        throw new Exception(
                            'لا يمكن ربط فاتورة شراء (مبالغها بالريال اليمني ضمنياً) بصندوق بعملة '
                            . $acc['currency'] . ' — اختر صندوقاً بعملة YER'
                        );
                    }
                    if ((float)$acc['balance'] < $total) {
                        throw new Exception('الرصيد غير كافٍ في الصندوق المحدد لسداد فاتورة الشراء');
                    }
                    $updAcc = $pdo->prepare(
                        'UPDATE cash_accounts SET balance = balance - ? WHERE id = ? AND tenant_id = ? AND balance >= ?'
                    );
                    $updAcc->execute([$total, $cashAccountId, $tenantId, $total]);
                    if ($updAcc->rowCount() === 0) {
                        throw new Exception('الرصيد غير كافٍ في الصندوق المحدد لسداد فاتورة الشراء');
                    }
                    $txId = 'TX-' . round(microtime(true) * 1000);
                    $pdo->prepare(
                        'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $txId, $tenantId, $cashAccountId, 'مشتريات', -$total, $acc['currency'],
                        "فاتورة شراء $id", $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][purchases] فشل حفظ فاتورة الشراء: ' . $e->getMessage());
            json_error($e->getMessage() ?: 'خطأ داخلي في الخادم', 500);
        }
        audit("purchase $id", null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $id]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
