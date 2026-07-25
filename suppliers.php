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
        // 🆕 حقول القالب الموحّد: العنوان، حد الدين، الرصيد الافتتاحي (مدين/دائن)،
        // طريقة التواصل، الرقم الضريبي — لمطابقة النظام المرجعي
        $stmt = $pdo->prepare(
            'INSERT INTO suppliers (name, phone, email, category, balance, orders, rating, last_order,
                address, debt_limit, opening_balance, opening_is_debit, messaging_method, tax_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (name) DO UPDATE SET
                phone = EXCLUDED.phone, email = EXCLUDED.email,
                category = EXCLUDED.category, balance = EXCLUDED.balance,
                orders = EXCLUDED.orders, rating = EXCLUDED.rating,
                last_order = EXCLUDED.last_order,
                address = EXCLUDED.address, debt_limit = EXCLUDED.debt_limit,
                opening_balance = EXCLUDED.opening_balance,
                opening_is_debit = EXCLUDED.opening_is_debit,
                messaging_method = EXCLUDED.messaging_method,
                tax_id = EXCLUDED.tax_id'
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
            $b['address'] ?? null,
            (float)($b['debtLimit'] ?? $b['debt_limit'] ?? 0),
            (float)($b['openingBalance'] ?? $b['opening_balance'] ?? 0),
            !empty($b['openingIsDebit'] ?? $b['opening_is_debit'] ?? true) ? 1 : 0,
            $b['messagingMethod'] ?? $b['messaging_method'] ?? 'بدون',
            $b['taxId'] ?? $b['tax_id'] ?? null,
        ]);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
