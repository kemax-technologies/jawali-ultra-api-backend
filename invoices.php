<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        require_auth();
        $id    = $_GET['id']    ?? '';
        $from  = $_GET['from']  ?? '';
        $to    = $_GET['to']    ?? '';
        $limit = (int)($_GET['limit'] ?? 200);
        if ($id !== '') {
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            if ($inv && !empty($inv['items_json'])) {
                $inv['items'] = json_decode($inv['items_json'], true) ?: [];
            }
            json_ok($inv ?: []);
        }
        $sql  = 'SELECT * FROM invoices WHERE 1=1';
        $args = [];
        if ($from !== '') { $sql .= ' AND date >= ?'; $args[] = $from; }
        if ($to   !== '') { $sql .= ' AND date <= ?'; $args[] = $to;   }
        $sql .= ' ORDER BY date DESC LIMIT ' . max(1, $limit);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['items_json'])) {
                $r['items'] = json_decode($r['items_json'], true) ?: [];
            }
        }
        json_ok($rows);
        break;
    }

    case 'POST': {
        require_auth();
        $body = input_json();
        $id   = trim($body['id'] ?? '');
        if ($id === '') $id = 'INV-' . time();

        $items = $body['items'] ?? [];
        if (!is_array($items)) $items = [];

        $pdo->beginTransaction();
        try {
            // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                 (id, customer_phone, user_email, date, subtotal, discount, tax, total, payment_method, status, items_json)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)
                 ON CONFLICT (id) DO UPDATE SET
                    customer_phone = EXCLUDED.customer_phone,
                    subtotal = EXCLUDED.subtotal, discount = EXCLUDED.discount,
                    tax = EXCLUDED.tax, total = EXCLUDED.total,
                    payment_method = EXCLUDED.payment_method, status = EXCLUDED.status,
                    items_json = EXCLUDED.items_json'
            );
            $stmt->execute([
                $id,
                $body['customer_phone'] ?? null,
                $body['user_email']     ?? null,
                $body['date'] ?? date('Y-m-d H:i:s'),
                (float)($body['subtotal'] ?? 0),
                (float)($body['discount'] ?? 0),
                (float)($body['tax']      ?? 0),
                (float)($body['total']    ?? 0),
                $body['payment_method'] ?? $body['paymentMethod'] ?? 'نقدي',
                $body['status'] ?? 'مدفوعة',
                json_encode($items, JSON_UNESCAPED_UNICODE),
            ]);

            // تحديث بنود الفاتورة وخصم المخزون
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $insItem  = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_sku, name, price, qty, line_total, unit_type, unit_label, pack_factor, base_qty) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $decStock = $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?), sold = sold + ? WHERE sku = ?');
            foreach ($items as $it) {
                $sku   = $it['sku']   ?? null;
                $name  = $it['name']  ?? '';
                $price = (float)($it['price'] ?? 0);
                $qty   = (int)  ($it['qty']   ?? 1);
                $insItem->execute([
                    $id, $sku, $name, $price, $qty, $price * $qty,
                    $it['unit_type']   ?? 'piece',
                    $it['unit_label']  ?? 'قطعة',
                    (int)($it['pack_factor'] ?? 1),
                    (int)($it['base_qty']    ?? $qty),
                ]);
                if ($sku) $decStock->execute([$qty, $qty, $sku]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][invoices] فشل حفظ الفاتورة: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }
        audit("invoice $id");
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
