<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ✅ إصلاح #7: جميع عمليات المستخدمين تتطلب دور مدير
$auth = require_admin();
// ✅ Multi-Tenant: كل عملية هنا مقيّدة بمتجر المدير الحالي فقط — يمنع أي
// مدير متجر من رؤية/تعديل/حذف مستخدمي متجر آخر.
$tenantId = tenant_id_from_auth($auth);

switch ($method) {
    case 'GET': {
        $stmt = $pdo->prepare(
            'SELECT id, name, email, role, is_active, permissions, created_at FROM users WHERE tenant_id = ? ORDER BY id DESC'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['effective_permissions'] = effective_permissions((string)$r['role'], $r['permissions']);
            $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'], true) : null;
        }
        json_ok($rows);
        break;
    }
    case 'POST': {
        $b     = input_json();
        $name  = trim($b['name']  ?? '');
        $email = strtolower(trim($b['email'] ?? ''));
        // ✅ الأدوار التسعة الكاملة (RBAC) — أي دور خارج هذه القائمة يتحول
        // بصمت إلى "كاشير" (الدور الأقل صلاحية والأكثر أماناً كافتراضي)
        $role = in_array($b['role'] ?? '', APP_ROLES, true) ? $b['role'] : 'كاشير';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error('البريد الإلكتروني غير صالح');
        }

        $hash = !empty($b['password'])
            ? password_hash($b['password'], PASSWORD_BCRYPT, ['cost' => 12])
            : null;

        // 🆕 صلاحيات دقيقة مخصّصة (اختيارية) — إن أُرسلت override لكل مفتاح
        // من APP_PERMISSIONS، وإلا تبقى NULL (تُستخدم صلاحيات الدور الافتراضية)
        $permissionsJson = null;
        if (isset($b['permissions']) && is_array($b['permissions'])) {
            $filtered = [];
            foreach ($b['permissions'] as $k => $v) {
                if (in_array($k, APP_PERMISSIONS, true)) $filtered[$k] = (bool)$v;
            }
            $permissionsJson = json_encode($filtered, JSON_UNESCAPED_UNICODE);
        }

        // ✅ Multi-Tenant: البريد الإلكتروني فريد عالمياً — إن كان مستخدَماً من
        // متجر آخر يجب منع الاستيلاء عليه/تعديله عبر هذا الـ endpoint.
        $existing = $pdo->prepare('SELECT tenant_id FROM users WHERE email = ? LIMIT 1');
        $existing->execute([$email]);
        $existingRow = $existing->fetch();
        if ($existingRow && (int)$existingRow['tenant_id'] !== $tenantId) {
            json_error('البريد الإلكتروني مستخدم في متجر آخر', 409);
        }

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE ... VALUES(col)
        //    → ON CONFLICT DO UPDATE SET ... = EXCLUDED.col
        //    COALESCE(VALUES(password_hash), users.password_hash) يترجم مباشرة
        //    إلى COALESCE(EXCLUDED.password_hash, users.password_hash) — نفس المنطق
        //    (يحافظ على كلمة المرور القديمة إذا لم تُرسَل كلمة مرور جديدة)
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, permissions, tenant_id)
             VALUES (?,?,?,?,?,?)
             ON CONFLICT (email) DO UPDATE SET
                name = EXCLUDED.name, role = EXCLUDED.role,
                permissions = COALESCE(EXCLUDED.permissions, users.permissions),
                password_hash = COALESCE(EXCLUDED.password_hash, users.password_hash)
             WHERE users.tenant_id = EXCLUDED.tenant_id'
        );
        $stmt->execute([$name, $email, $hash, $role, $permissionsJson, $tenantId]);
        audit("upsert user $email (role=$role)", $auth['email'] ?? null, 'info', $tenantId);
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
        $stmt = $pdo->prepare('UPDATE users SET is_active = 0 WHERE email = ? AND tenant_id = ?');
        $stmt->execute([$email, $tenantId]);
        if ($stmt->rowCount() === 0) {
            json_error('المستخدم غير موجود في متجرك', 404);
        }
        audit("deactivate user $email", $auth['email'] ?? null, 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
