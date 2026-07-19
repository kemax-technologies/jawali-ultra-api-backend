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
        $stmt = $pdo->prepare(
            'INSERT INTO purchases (id, supplier_name, subtotal, tax, total, status, items_json, date)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $id,
            $b['supplier']      ?? $b['supplier_name'] ?? 'مورد',
            (float)($b['subtotal'] ?? 0),
            (float)($b['tax']      ?? 0),
            (float)($b['total']    ?? 0),
            $b['status'] ?? 'مستلمة',
            json_encode($items, JSON_UNESCAPED_UNICODE),
            $b['date'] ?? date('Y-m-d H:i:s'),
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
        json_ok(['success' => true, 'id' => $id]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
