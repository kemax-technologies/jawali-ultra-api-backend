<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إحصائيات حية من قاعدة البيانات الفعلية (محمي)
 * GET ?action=overview → إحصائيات عامة (مستخدمون، فواتير، إيرادات، Pro)
 * GET ?action=users    → قائمة كل المستخدمين
 * GET ?action=pro      → قائمة طلبات ترقية Pro (كل الحالات)
 * GET ?action=audit    → آخر 50 حدث في سجل التدقيق
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$action = $_GET['action'] ?? 'overview';

if ($action === 'overview') {
    $totalUsers   = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeUsers  = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $proUsers     = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_pro = 1')->fetchColumn();
    $pendingPro   = (int)$pdo->query("SELECT COUNT(*) FROM pro_requests WHERE status = 'pending'")->fetchColumn();
    $totalInvoices = (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
    $totalRevenue  = (float)$pdo->query('SELECT COALESCE(SUM(total),0) FROM invoices')->fetchColumn();
    $totalProducts = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $totalCustomers = (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn();

    // آخر 30 يوم — فواتير يومية (للرسم البياني)
    $daily = $pdo->query("
        SELECT TO_CHAR(date, 'YYYY-MM-DD') AS d, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
        FROM invoices
        WHERE date >= CURRENT_DATE - INTERVAL '30 days'
        GROUP BY d ORDER BY d ASC
    ")->fetchAll();

    $recentUsers = $pdo->query(
        'SELECT id, name, email, role, is_pro, is_active, created_at FROM users ORDER BY id DESC LIMIT 8'
    )->fetchAll();

    json_ok([
        'total_users'     => $totalUsers,
        'active_users'    => $activeUsers,
        'pro_users'       => $proUsers,
        'pending_pro_requests' => $pendingPro,
        'total_invoices'  => $totalInvoices,
        'total_revenue'   => $totalRevenue,
        'total_products'  => $totalProducts,
        'total_customers' => $totalCustomers,
        'daily_sales'     => $daily,
        'recent_users'    => $recentUsers,
        'server_time'     => date('c'),
    ]);
}

if ($action === 'users') {
    $rows = $pdo->query(
        'SELECT id, name, email, role, is_active, is_pro, pro_plan, pro_expires_at,
                created_at, last_login_at, branch_code, phone
         FROM users ORDER BY id DESC'
    )->fetchAll();
    json_ok($rows);
}

if ($action === 'pro') {
    $status = $_GET['status'] ?? '';
    $sql = 'SELECT pr.*, u.name AS user_name FROM pro_requests pr
            LEFT JOIN users u ON u.id = pr.user_id';
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE pr.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY pr.created_at DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok($stmt->fetchAll());
}

if ($action === 'audit') {
    $rows = $pdo->query(
        'SELECT id, action, user_email, ip_address, created_at
         FROM audit_log ORDER BY id DESC LIMIT 50'
    )->fetchAll();
    json_ok($rows);
}

json_error('إجراء غير معروف', 400);
