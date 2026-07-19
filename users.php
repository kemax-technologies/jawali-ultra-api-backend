<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ✅ إصلاح #7: جميع عمليات المستخدمين تتطلب دور مدير
$auth = require_admin();

switch ($method) {
    case 'GET': {
        $rows = $pdo->query(
            'SELECT id, name, email, role, is_active, created_at FROM users ORDER BY id DESC'
        )->fetchAll();
        json_ok($rows);
        break;
    }
    case 'POST': {
        $b     = input_json();
        $name  = trim($b['name']  ?? '');
        $email = strtolower(trim($b['email'] ?? ''));
        $role  = in_array($b['role'] ?? '', ['مدير', 'كاشير'], true) ? $b['role'] : 'كاشير';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error('البريد الإلكتروني غير صالح');
        }

        $hash = !empty($b['password'])
            ? password_hash($b['password'], PASSWORD_BCRYPT, ['cost' => 12])
            : null;

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE ... VALUES(col)
        //    → ON CONFLICT DO UPDATE SET ... = EXCLUDED.col
        //    COALESCE(VALUES(password_hash), users.password_hash) يترجم مباشرة
        //    إلى COALESCE(EXCLUDED.password_hash, users.password_hash) — نفس المنطق
        //    (يحافظ على كلمة المرور القديمة إذا لم تُرسَل كلمة مرور جديدة)
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role)
             VALUES (?,?,?,?)
             ON CONFLICT (email) DO UPDATE SET
                name = EXCLUDED.name, role = EXCLUDED.role,
                password_hash = COALESCE(EXCLUDED.password_hash, users.password_hash)'
        );
        $stmt->execute([$name, $email, $hash, $role]);
        audit("upsert user $email", $auth['email'] ?? null);
        json_ok(['success' => true]);
        break;
    }
    case 'DELETE': {
        $email = strtolower(trim($_GET['email'] ?? ''));
        if ($email === '') json_error('email مطلوب');
        // ✅ لا يمكن حذف المدير الحالي
        if ($email === ($auth['email'] ?? '')) {
            json_error('لا يمكنك حذف حسابك الخاص', 400);
        }
        $pdo->prepare('UPDATE users SET is_active = 0 WHERE email = ?')->execute([$email]);
        audit("deactivate user $email", $auth['email'] ?? null);
        json_ok(['success' => true]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
