<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة فروع متجر مُحدَّد (محمي بمصادقة المطوّر)
 * ✅ إصلاح Multi-Tenant: الفروع مقيّدة بـ tenant_id الآن — يجب تمرير
 *    ?tenant_id=X (GET/DELETE) أو tenant_id في body (POST) لتحديد أي متجر.
 * GET    dev_branches.php?tenant_id=X          → قائمة فروع المتجر + إحصائيات
 * POST   dev_branches.php  { tenant_id, ... }   → إنشاء/تحديث فرع (upsert by tenant_id+code)
 * DELETE dev_branches.php?tenant_id=X&code=Y    → تعطيل فرع (soft delete)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': {
        $tenantId = (int)($_GET['tenant_id'] ?? 0);
        if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);
        $stmt = $pdo->prepare(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM users     u WHERE u.tenant_id = b.tenant_id AND u.branch_code = b.code AND u.is_active = 1) AS users_count,
                    (SELECT COUNT(*) FROM invoices  i WHERE i.tenant_id = b.tenant_id AND i.branch_code = b.code)                   AS invoices_count,
                    (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.tenant_id = b.tenant_id AND i.branch_code = b.code)      AS total_sales
             FROM branches b
             WHERE b.tenant_id = ?
             ORDER BY b.is_main DESC, b.is_active DESC, b.id ASC'
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    case 'POST': {
        $b = input_json();
        $tenantId = (int)($b['tenant_id'] ?? 0);
        if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);
        $code    = strtoupper(trim($b['code'] ?? ''));
        $name    = trim($b['name'] ?? '');
        $address = trim($b['address'] ?? '');
        $phone   = trim($b['phone'] ?? '');
        $manager = trim($b['manager'] ?? '');
        $active  = !empty($b['is_active']) ? 1 : 0;
        $isMain  = !empty($b['is_main'])   ? 1 : 0;
        $notes   = trim($b['notes'] ?? '');

        if ($code === '' || $name === '') {
            json_error('رمز الفرع والاسم مطلوبان');
        }
        if (!preg_match('/^[A-Z0-9_\-]{2,40}$/', $code)) {
            json_error('رمز الفرع يجب أن يحتوي على حروف إنجليزية كبيرة وأرقام فقط');
        }

        if ($isMain) {
            $pdo->prepare('UPDATE branches SET is_main = 0 WHERE tenant_id = ?')->execute([$tenantId]);
        }

        // ✅ المفتاح الفريد الفعلي في القاعدة هو (tenant_id, code)
        $stmt = $pdo->prepare(
            'INSERT INTO branches (tenant_id, code, name, address, phone, manager, is_active, is_main, notes)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON CONFLICT (tenant_id, code) DO UPDATE SET
                name=EXCLUDED.name, address=EXCLUDED.address, phone=EXCLUDED.phone,
                manager=EXCLUDED.manager, is_active=EXCLUDED.is_active,
                is_main=EXCLUDED.is_main, notes=EXCLUDED.notes'
        );
        $stmt->execute([$tenantId, $code, $name, $address, $phone, $manager, $active, $isMain, $notes]);

        audit("dev_panel: upsert branch $code (tenant#$tenantId)", 'developer', 'info', $tenantId);
        json_ok(['success' => true, 'code' => $code]);
        break;
    }

    case 'DELETE': {
        $tenantId = (int)($_GET['tenant_id'] ?? 0);
        if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);
        $code = strtoupper(trim($_GET['code'] ?? ''));
        if ($code === '') json_error('رمز الفرع مطلوب');
        if ($code === 'MAIN') json_error('لا يمكن حذف الفرع الرئيسي', 400);

        $pdo->prepare('UPDATE branches SET is_active = 0 WHERE tenant_id = ? AND code = ?')->execute([$tenantId, $code]);
        audit("dev_panel: deactivate branch $code (tenant#$tenantId)", 'developer', 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
