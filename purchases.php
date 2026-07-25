<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        require_auth();  // ✅ إصلاح: حماية GET بالمصادقة
        $rows = $pdo->query('SELECT * FROM purchases ORDER BY date DESC LIMIT 200')->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['items_json'])) $r['items'] = json_decode($r['items_json'], true) ?: [];
        }
        json_ok($rows);
        break;
    }
    case 'POST': {
        require_auth();
        $b   = input_json();
        $id  = trim($b['id'] ?? '');
        if ($id === '') $id = 'PO-' . time();
        $items = $b['items'] ?? [];
        // 🆕 المرحلة 7 من إعادة التصميم: خصم + طريقة دفع + صندوق نقدي
        $discount      = (float)($b['discount'] ?? 0);
        $paymentMethod = trim($b['paymentMethod'] ?? $b['payment_method'] ?? 'نقدي');
        $cashAccountId = trim($b['cashAccountId'] ?? $b['cash_account_id'] ?? '');
        $total         = (float)($b['total'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO purchases (id, supplier_name, subtotal, discount, tax, total, status, items_json, date, payment_method, cash_account_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,NULLIF(?, \'\'))'
            );
            $stmt->execute([
                $id,
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
                $up = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE sku = ?');
                foreach ($items as $it) {
                    $sku = $it['sku'] ?? '';
                    $qty = (int)($it['qty'] ?? 0);
                    if ($sku !== '' && $qty > 0) $up->execute([$qty, $sku]);
                }
            }

            // 💰 المرحلة 7: إذا كانت المشتريات نقدية ومرتبطة بصندوق، اخصم المبلغ
            // من رصيد الصندوق تلقائياً + سجّل حركة صندوق (المشتريات الآجلة لا
            // تُخصم هنا لأنها تُدار عبر رصيد المورد بشكل منفصل)
            if ($cashAccountId !== '' && $paymentMethod !== 'آجل' && $total > 0) {
                $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE id = ? LIMIT 1');
                $accStmt->execute([$cashAccountId]);
                $acc = $accStmt->fetch();
                if ($acc) {
                    $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE id = ?')
                        ->execute([$total, $cashAccountId]);
                    $txId = 'TX-' . round(microtime(true) * 1000);
                    $pdo->prepare(
                        'INSERT INTO cash_transactions (id, account_id, type, amount, currency, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $txId, $cashAccountId, 'مشتريات', -$total, $acc['currency'],
                        "فاتورة شراء $id", $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][purchases] فشل حفظ فاتورة الشراء: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }
        audit("purchase $id");
        json_ok(['success' => true, 'id' => $id]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
