<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET':
        // ✅ إصلاح #5: حماية GET بالمصادقة
        require_auth();
        $rows = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();
        json_ok($rows);
        break;

    case 'POST': {
        require_auth();
        $b = input_json();
        $name = trim($b['name'] ?? '');
        if ($name === '') json_error('اسم المورد مطلوب');
        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        // (يستخدم قيد UNIQUE على عمود name المُعرَّف في المخطط)
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (name, phone, email, category, balance, orders, rating, last_order)
             VALUES (?,?,?,?,?,?,?,?)
             ON CONFLICT (name) DO UPDATE SET
                phone = EXCLUDED.phone, email = EXCLUDED.email,
                category = EXCLUDED.category, balance = EXCLUDED.balance,
                orders = EXCLUDED.orders, rating = EXCLUDED.rating,
                last_order = EXCLUDED.last_order'
        );
        $stmt->execute([
            $name,
            $b['phone'] ?? null,
            $b['email'] ?? null,
            $b['category'] ?? 'عام',
            (float)($b['balance'] ?? 0),
            (int)  ($b['orders']  ?? 0),
            (float)($b['rating']  ?? 4.5),
            $b['lastOrder'] ?? $b['last_order'] ?? date('Y-m-d H:i:s'),
        ]);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
