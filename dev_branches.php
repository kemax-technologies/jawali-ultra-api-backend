<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة الفروع (محمي بمصادقة المطوّر)
 * GET    dev_branches.php           → قائمة كل الفروع + إحصائيات
 * POST   dev_branches.php           → إنشاء/تحديث فرع (upsert by code)
 * DELETE dev_branches.php?code=X    → تعطيل فرع (soft delete)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET': {
        $rows = $pdo->query(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM users     u WHERE u.branch_code = b.code AND u.is_active = 1) AS users_count,
                    (SELECT COUNT(*) FROM invoices  i WHERE i.branch_code = b.code)                   AS invoices_count,
                    (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.branch_code = b.code)      AS total_sales
             FROM branches b
             ORDER BY b.is_main DESC, b.is_active DESC, b.id ASC'
        )->fetchAll();
        json_ok($rows);
        break;
    }

    case 'POST': {
        $b = input_json();
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
            $pdo->exec('UPDATE branches SET is_main = 0');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO branches (code, name, address, phone, manager, is_active, is_main, notes)
             VALUES (?,?,?,?,?,?,?,?)
             ON CONFLICT (code) DO UPDATE SET
                name=EXCLUDED.name, address=EXCLUDED.address, phone=EXCLUDED.phone,
                manager=EXCLUDED.manager, is_active=EXCLUDED.is_active,
                is_main=EXCLUDED.is_main, notes=EXCLUDED.notes'
        );
        $stmt->execute([$code, $name, $address, $phone, $manager, $active, $isMain, $notes]);

        audit("dev_panel: upsert branch $code", 'developer');
        json_ok(['success' => true, 'code' => $code]);
        break;
    }

    case 'DELETE': {
        $code = strtoupper(trim($_GET['code'] ?? ''));
        if ($code === '') json_error('رمز الفرع مطلوب');
        if ($code === 'MAIN') json_error('لا يمكن حذف الفرع الرئيسي', 400);

        $pdo->prepare('UPDATE branches SET is_active = 0 WHERE code = ?')->execute([$code]);
        audit("dev_panel: deactivate branch $code", 'developer');
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
