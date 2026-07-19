<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        require_auth();
        $phone  = $_GET['phone']  ?? '';
        $tier   = $_GET['tier']   ?? '';
        $search = $_GET['search'] ?? '';
        if ($phone !== '') {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            json_ok($stmt->fetch() ?: []);
        }
        $sql  = 'SELECT * FROM customers WHERE 1=1';
        $args = [];
        if ($tier !== '')   { $sql .= ' AND tier = ?';                  $args[] = $tier; }
        // ✅ تحويل PostgreSQL: LIKE حساس لحالة الأحرف في Postgres (بخلاف MySQL
        //    الذي يكون غير حساس افتراضيًا) — استُخدم ILIKE لإبقاء سلوك البحث كما كان
        if ($search !== '') { $sql .= ' AND (name ILIKE ? OR phone ILIKE ?)';
                              $args[] = "%$search%"; $args[] = "%$search%"; }
        $sql .= ' ORDER BY spent DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }

    case 'POST': {
        require_auth();
        $body  = input_json();
        $phone = trim($body['phone'] ?? '');
        $name  = trim($body['name']  ?? '');
        if ($phone === '' || $name === '') json_error('الاسم والهاتف مطلوبان');

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        $stmt = $pdo->prepare(
            'INSERT INTO customers (phone, name, email, spent, visits, days_since_last, tier, notes)
             VALUES (?,?,?,?,?,?,?,?)
             ON CONFLICT (phone) DO UPDATE SET
                name = EXCLUDED.name, email = EXCLUDED.email,
                spent = EXCLUDED.spent, visits = EXCLUDED.visits,
                days_since_last = EXCLUDED.days_since_last,
                tier = EXCLUDED.tier, notes = EXCLUDED.notes'
        );
        $stmt->execute([
            $phone, $name,
            $body['email'] ?? null,
            (float)($body['spent']  ?? 0),
            (int)  ($body['visits'] ?? 0),
            (int)  ($body['daysSinceLastPurchase'] ?? $body['days_since_last'] ?? 0),
            $body['tier']  ?? 'عادي',
            $body['notes'] ?? null,
        ]);
        audit("upsert customer $phone");
        json_ok(['success' => true, 'phone' => $phone]);
        break;
    }

    case 'DELETE': {
        require_auth();
        $phone = $_GET['phone'] ?? '';
        if ($phone === '') json_error('phone مطلوب');
        $pdo->prepare('DELETE FROM customers WHERE phone = ?')->execute([$phone]);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
