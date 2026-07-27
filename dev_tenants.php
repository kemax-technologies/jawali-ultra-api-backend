<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة المتاجر (Tenants) في نظام SaaS متعدد المتاجر — محمي
 * GET  ?action=list                → قائمة كل المتاجر + إحصائيات ملخّصة لكل متجر
 * GET  ?action=view&id=ID          → تفاصيل متجر واحد (المستخدمون + إحصائياته)
 * POST { action: 'toggle_active'|'change_plan'|'delete', tenant_id }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        // ✅ إحصائيات ملخّصة لكل متجر: عدد المستخدمين، عدد الفواتير، الإيرادات،
        // اسم/بريد المالك — كل ذلك عبر LEFT JOIN بسيطة بدون N+1 queries.
        $rows = $pdo->query(
            "SELECT
                t.id, t.name, t.plan, t.is_active, t.created_at, t.owner_user_id,
                owner.name  AS owner_name,
                owner.email AS owner_email,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users_count,
                (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id) AS invoices_count,
                (SELECT COALESCE(SUM(i.total), 0) FROM invoices i WHERE i.tenant_id = t.id) AS total_revenue,
                (SELECT COUNT(*) FROM products p WHERE p.tenant_id = t.id) AS products_count
             FROM tenants t
             LEFT JOIN users owner ON owner.id = t.owner_user_id
             ORDER BY t.id DESC"
        )->fetchAll();
        json_ok($rows);
    }

    if ($action === 'view') {
        $tenantId = (int)($_GET['id'] ?? 0);
        if ($tenantId <= 0) json_error('id مطلوب', 400);

        $t = $pdo->prepare(
            "SELECT t.*, owner.name AS owner_name, owner.email AS owner_email
             FROM tenants t
             LEFT JOIN users owner ON owner.id = t.owner_user_id
             WHERE t.id = ? LIMIT 1"
        );
        $t->execute([$tenantId]);
        $tenant = $t->fetch();
        if (!$tenant) json_error('المتجر غير موجود', 404);

        $usersStmt = $pdo->prepare(
            'SELECT id, name, email, role, is_active, is_pro, created_at, last_login_at
             FROM users WHERE tenant_id = ? ORDER BY id ASC'
        );
        $usersStmt->execute([$tenantId]);
        $tenant['users'] = $usersStmt->fetchAll();

        $stats = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM invoices WHERE tenant_id = ?) AS invoices_count,
                (SELECT COALESCE(SUM(total), 0) FROM invoices WHERE tenant_id = ?) AS total_revenue,
                (SELECT COUNT(*) FROM products WHERE tenant_id = ?) AS products_count,
                (SELECT COUNT(*) FROM customers WHERE tenant_id = ?) AS customers_count"
        );
        $stats->execute([$tenantId, $tenantId, $tenantId, $tenantId]);
        $tenant['stats'] = $stats->fetch();

        json_ok($tenant);
    }

    json_error('إجراء غير معروف', 400);
}

if ($method === 'POST') {
    $b = input_json();
    $action = $b['action'] ?? '';
    $tenantId = (int)($b['tenant_id'] ?? 0);
    if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);

    $exists = $pdo->prepare('SELECT 1 FROM tenants WHERE id = ?');
    $exists->execute([$tenantId]);
    if (!$exists->fetch()) json_error('المتجر غير موجود', 404);

    switch ($action) {
        case 'toggle_active': {
            // ✅ تعليق/إلغاء تعليق متجر كامل — يمنع فوراً كل مستخدمي المتجر من
            // تسجيل الدخول أو تنفيذ أي طلب API (يُنفَّذ عبر فحص tenants.is_active
            // داخل require_auth() في _db.php).
            $row = $pdo->prepare('SELECT is_active FROM tenants WHERE id = ?');
            $row->execute([$tenantId]);
            $cur = $row->fetchColumn();
            $new = $cur ? 0 : 1;
            $pdo->prepare('UPDATE tenants SET is_active = ? WHERE id = ?')->execute([$new, $tenantId]);
            audit("dev_panel: toggle_active tenant#$tenantId → $new", 'developer', 'warning', $tenantId);
            json_ok(['success' => true, 'is_active' => (bool)$new]);
            break;
        }
        case 'change_plan': {
            $plan = trim((string)($b['plan'] ?? ''));
            if (!in_array($plan, ['free', 'pro'], true)) json_error('خطة غير صالحة', 400);
            $pdo->prepare('UPDATE tenants SET plan = ? WHERE id = ?')->execute([$plan, $tenantId]);
            audit("dev_panel: change_plan tenant#$tenantId → $plan", 'developer', 'info', $tenantId);
            json_ok(['success' => true]);
            break;
        }
        case 'delete': {
            // ⚠️ حذف متجر كامل مع كل بياناته (مستخدمين، فواتير، منتجات...) —
            // إجراء لا يمكن التراجع عنه، مقيَّد لحماية المتاجر الأساسية.
            if ($tenantId === 1) json_error('لا يمكن حذف المتجر الرئيسي', 400);
            try {
                $pdo->beginTransaction();
                // فكّ الحلقة الدائرية (tenants.owner_user_id <-> users.tenant_id)
                // قبل الحذف الفعلي لتجنّب انتهاك قيود FK.
                $pdo->prepare('UPDATE tenants SET owner_user_id = NULL WHERE id = ?')->execute([$tenantId]);
                $pdo->prepare('DELETE FROM users WHERE tenant_id = ?')->execute([$tenantId]);
                $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]);
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[Jawali][dev_tenants] فشل حذف المتجر #' . $tenantId . ': ' . $e->getMessage());
                json_error('تعذّر حذف المتجر — قد تحتوي بيانات مرتبطة يتعذّر حذفها', 500);
            }
            audit("dev_panel: delete tenant#$tenantId", 'developer', 'warning', null);
            json_ok(['success' => true]);
            break;
        }
        default:
            json_error('إجراء غير معروف', 400);
    }
}

json_error('Method Not Allowed', 405);
