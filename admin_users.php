<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Admin API: إدارة المستخدمين الموسّعة
 * GET    admin_users.php                  → قائمة المستخدمين + الفرع
 * GET    admin_users.php?id=X             → مستخدم محدد + إحصائيات
 * POST   admin_users.php                  → إنشاء/تحديث (يدعم branch_code)
 * POST   admin_users.php?action=reset     → إعادة تعيين كلمة المرور
 * POST   admin_users.php?action=toggle    → تفعيل/تعطيل
 * POST   admin_users.php?action=move      → نقل مستخدم لفرع آخر
 * DELETE admin_users.php?email=X          → تعطيل (soft delete)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_admin_db.php';

$auth   = require_admin_web();
$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'GET': {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare(
                'SELECT u.id, u.name, u.email, u.role, u.is_active, u.branch_code, u.permissions, u.created_at,
                        b.name AS branch_name
                 FROM users u
                 LEFT JOIN branches b ON b.code = u.branch_code
                 WHERE u.id = ? LIMIT 1'
            );
            $stmt->execute([(int)$_GET['id']]);
            $u = $stmt->fetch();
            if (!$u) json_error('المستخدم غير موجود', 404);
            $u['effective_permissions'] = effective_permissions((string)$u['role'], $u['permissions']);
            $u['permissions'] = $u['permissions'] ? json_decode($u['permissions'], true) : null;

            // إحصائيات الأداء
            $stats = $pdo->prepare(
                'SELECT COUNT(*) AS invoices,
                        COALESCE(SUM(total),0)   AS sales,
                        COALESCE(AVG(total),0)   AS avg_invoice,
                        MAX(date)                AS last_activity
                 FROM invoices WHERE user_email = ?'
            );
            $stats->execute([$u['email']]);
            $u['stats'] = $stats->fetch();
            json_ok($u);
        }

        $branch = current_branch();
        $sql = 'SELECT u.id, u.name, u.email, u.role, u.is_active, u.branch_code, u.permissions, u.created_at,
                       b.name AS branch_name,
                       (SELECT COUNT(*)            FROM invoices i WHERE i.user_email=u.email) AS invoices_count,
                       (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.user_email=u.email) AS total_sales,
                       (SELECT MAX(date)           FROM invoices i WHERE i.user_email=u.email) AS last_activity
                FROM users u
                LEFT JOIN branches b ON b.code = u.branch_code';
        $args = [];
        if ($branch) { $sql .= ' WHERE u.branch_code = ?'; $args[] = $branch; }
        $sql .= ' ORDER BY u.id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['effective_permissions'] = effective_permissions((string)$r['role'], $r['permissions']);
            $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'], true) : null;
        }
        json_ok($rows);
        break;
    }

    case 'POST': {
        $b = input_json();

        // ── إعادة تعيين كلمة المرور ────────────────────────────────────────
        if ($action === 'reset') {
            $email = strtolower(trim($b['email'] ?? ''));
            $pass  = (string)($b['password'] ?? '');
            if ($email === '' || strlen($pass) < 6) {
                json_error('البريد وكلمة مرور 6 أحرف على الأقل');
            }
            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
                ->execute([$hash, $email]);
            audit("reset password for $email", $auth['email'] ?? null);
            json_ok(['success' => true]);
        }

        // ── تفعيل / تعطيل ──────────────────────────────────────────────────
        if ($action === 'toggle') {
            $email = strtolower(trim($b['email'] ?? ''));
            if ($email === '') json_error('البريد مطلوب');
            if ($email === ($auth['email'] ?? '')) {
                json_error('لا يمكنك تعطيل حسابك الخاص', 400);
            }
            $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE email = ?')
                ->execute([$email]);
            audit("toggle user $email", $auth['email'] ?? null);
            json_ok(['success' => true]);
        }

        // ── نقل المستخدم بين الفروع ────────────────────────────────────────
        if ($action === 'move') {
            $email = strtolower(trim($b['email'] ?? ''));
            $code  = strtoupper(trim($b['branch_code'] ?? ''));
            if ($email === '' || $code === '') json_error('البريد ورمز الفرع مطلوبان');
            // تحقق من وجود الفرع
            $exists = $pdo->prepare('SELECT 1 FROM branches WHERE code = ? AND is_active = 1');
            $exists->execute([$code]);
            if (!$exists->fetch()) json_error('الفرع غير موجود أو غير نشط');
            $pdo->prepare('UPDATE users SET branch_code = ? WHERE email = ?')
                ->execute([$code, $email]);
            audit("move user $email to $code", $auth['email'] ?? null);
            json_ok(['success' => true]);
        }

        // ── إنشاء/تحديث (الإجراء الافتراضي) ────────────────────────────────
        $name   = trim($b['name'] ?? '');
        $email  = strtolower(trim($b['email'] ?? ''));
        // ✅ الأدوار التسعة الكاملة (RBAC)
        $role   = in_array($b['role'] ?? '', APP_ROLES, true) ? $b['role'] : 'كاشير';
        $branch = strtoupper(trim($b['branch_code'] ?? 'MAIN'));
        $active = isset($b['is_active']) ? (int)!!$b['is_active'] : 1;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error('البريد الإلكتروني غير صالح');
        }
        if ($name === '') json_error('الاسم مطلوب');

        // 🆕 صلاحيات دقيقة مخصّصة (اختيارية)
        $permissionsJson = null;
        if (isset($b['permissions']) && is_array($b['permissions'])) {
            $filtered = [];
            foreach ($b['permissions'] as $k => $v) {
                if (in_array($k, APP_PERMISSIONS, true)) $filtered[$k] = (bool)$v;
            }
            $permissionsJson = json_encode($filtered, JSON_UNESCAPED_UNICODE);
        }

        $hash = !empty($b['password'])
            ? password_hash($b['password'], PASSWORD_BCRYPT, ['cost' => 12])
            : null;

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE ... VALUES(col) → ON CONFLICT (email) DO UPDATE SET ... EXCLUDED.col
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, branch_code, is_active, permissions)
             VALUES (?,?,?,?,?,?,?)
             ON CONFLICT (email) DO UPDATE SET
                name = EXCLUDED.name, role = EXCLUDED.role,
                branch_code = EXCLUDED.branch_code, is_active = EXCLUDED.is_active,
                permissions = COALESCE(EXCLUDED.permissions, users.permissions),
                password_hash = COALESCE(EXCLUDED.password_hash, users.password_hash)'
        );
        $stmt->execute([$name, $email, $hash, $role, $branch, $active, $permissionsJson]);
        audit("upsert user $email (branch=$branch, role=$role)", $auth['email'] ?? null);
        json_ok(['success' => true]);
        break;
    }

    case 'DELETE': {
        $email = strtolower(trim($_GET['email'] ?? ''));
        if ($email === '') json_error('email مطلوب');
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
