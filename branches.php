<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Admin API: إدارة الفروع
 * GET    branches.php           → قائمة كل الفروع
 * GET    branches.php?id=X      → فرع محدد
 * POST   branches.php           → إنشاء/تحديث (upsert by code)
 * DELETE branches.php?code=X    → تعطيل (soft delete)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_admin_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        require_auth();
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ? LIMIT 1');
            $stmt->execute([(int)$_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) json_error('الفرع غير موجود', 404);
            json_ok($row);
        }
        // قائمة كاملة + إحصائية سريعة لكل فرع
        $rows = $pdo->query(
            'SELECT b.*,
                    (SELECT COUNT(*) FROM users     u WHERE u.branch_code = b.code AND u.is_active = 1) AS users_count,
                    (SELECT COUNT(*) FROM invoices  i WHERE i.branch_code = b.code)                   AS invoices_count,
                    (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.branch_code = b.code)      AS total_sales,
                    -- ✅ تحويل PostgreSQL: CURDATE() (MySQL) → CURRENT_DATE
                    (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.branch_code = b.code
                       AND DATE(i.date) = CURRENT_DATE)                                                AS today_sales
             FROM branches b
             ORDER BY b.is_main DESC, b.is_active DESC, b.id ASC'
        )->fetchAll();
        json_ok($rows);
        break;
    }

    case 'POST': {
        $auth = require_admin_web();
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

        // إن تم تعيين فرع كرئيسي، نزيل العلم عن الباقي
        if ($isMain) {
            $pdo->exec('UPDATE branches SET is_main = 0');
        }

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE ... VALUES(col) → ON CONFLICT (code) DO UPDATE SET ... EXCLUDED.col
        $stmt = $pdo->prepare(
            'INSERT INTO branches (code, name, address, phone, manager, is_active, is_main, notes)
             VALUES (?,?,?,?,?,?,?,?)
             ON CONFLICT (code) DO UPDATE SET
                name=EXCLUDED.name, address=EXCLUDED.address, phone=EXCLUDED.phone,
                manager=EXCLUDED.manager, is_active=EXCLUDED.is_active,
                is_main=EXCLUDED.is_main, notes=EXCLUDED.notes'
        );
        $stmt->execute([$code, $name, $address, $phone, $manager, $active, $isMain, $notes]);

        audit("upsert branch $code", $auth['email'] ?? null);
        json_ok(['success' => true, 'code' => $code]);
        break;
    }

    case 'DELETE': {
        $auth = require_admin_web();
        $code = strtoupper(trim($_GET['code'] ?? ''));
        if ($code === '') json_error('رمز الفرع مطلوب');
        if ($code === 'MAIN') json_error('لا يمكن حذف الفرع الرئيسي', 400);

        $pdo->prepare('UPDATE branches SET is_active = 0 WHERE code = ?')->execute([$code]);
        audit("deactivate branch $code", $auth['email'] ?? null);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
