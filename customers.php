<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $phone  = $_GET['phone']  ?? '';
        $tier   = $_GET['tier']   ?? '';
        $search = $_GET['search'] ?? '';
        if ($phone !== '') {
            $stmt = $pdo->prepare('SELECT * FROM customers WHERE tenant_id = ? AND phone = ? LIMIT 1');
            $stmt->execute([$tenantId, $phone]);
            json_ok($stmt->fetch() ?: []);
        }
        $sql  = 'SELECT * FROM customers WHERE tenant_id = ?';
        $args = [$tenantId];
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
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $body  = input_json();
        $phone = trim($body['phone'] ?? '');
        $name  = trim($body['name']  ?? '');
        if ($phone === '' || $name === '') json_error('الاسم والهاتف مطلوبان');

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        // 🆕 حقول القالب الموحّد: العنوان، حد الدين، الرصيد الافتتاحي (مدين/دائن)،
        // طريقة التواصل، الرقم الضريبي — لمطابقة النظام المرجعي
        $stmt = $pdo->prepare(
            'INSERT INTO customers (tenant_id, phone, name, email, spent, visits, days_since_last, tier, notes,
                address, debt_limit, opening_balance, opening_is_debit, messaging_method, tax_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (tenant_id, phone) DO UPDATE SET
                name = EXCLUDED.name, email = EXCLUDED.email,
                spent = EXCLUDED.spent, visits = EXCLUDED.visits,
                days_since_last = EXCLUDED.days_since_last,
                tier = EXCLUDED.tier, notes = EXCLUDED.notes,
                address = EXCLUDED.address, debt_limit = EXCLUDED.debt_limit,
                opening_balance = EXCLUDED.opening_balance,
                opening_is_debit = EXCLUDED.opening_is_debit,
                messaging_method = EXCLUDED.messaging_method,
                tax_id = EXCLUDED.tax_id'
        );
        $stmt->execute([
            $tenantId, $phone, $name,
            $body['email'] ?? null,
            (float)($body['spent']  ?? 0),
            (int)  ($body['visits'] ?? 0),
            (int)  ($body['daysSinceLastPurchase'] ?? $body['days_since_last'] ?? 0),
            $body['tier']  ?? 'عادي',
            $body['notes'] ?? null,
            $body['address'] ?? null,
            (float)($body['debtLimit'] ?? $body['debt_limit'] ?? 0),
            (float)($body['openingBalance'] ?? $body['opening_balance'] ?? 0),
            !empty($body['openingIsDebit'] ?? $body['opening_is_debit'] ?? true) ? 1 : 0,
            $body['messagingMethod'] ?? $body['messaging_method'] ?? 'بدون',
            $body['taxId'] ?? $body['tax_id'] ?? null,
        ]);
        audit("upsert customer $phone", null, 'info', $tenantId);
        json_ok(['success' => true, 'phone' => $phone]);
        break;
    }

    case 'DELETE': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $phone = $_GET['phone'] ?? '';
        if ($phone === '') json_error('phone مطلوب');
        $pdo->prepare('DELETE FROM customers WHERE tenant_id = ? AND phone = ?')->execute([$tenantId, $phone]);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
