<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إحصائيات حية من قاعدة البيانات الفعلية (محمي)
 * GET ?action=overview → إحصائيات عامة على مستوى المنصّة (حسابات/تفعيل/Pro فقط)
 * GET ?action=users    → قائمة كل المستخدمين (بيانات حساب فقط — بلا بيانات تشغيلية)
 * GET ?action=pro      → قائمة طلبات ترقية Pro (كل الحالات)
 * GET ?action=audit    → آخر 50 حدث أمني في سجل التدقيق (تسجيل دخول/إجراءات إدارية)
 *
 * ⚠️ ملاحظة أمان (إلزامية): صاحب المنصّة (المطوّر) لا يملك tenant_id ولا يتبع
 * أي متجر — لذلك لا يجوز أن يرى أي بيانات تشغيلية لأي متجر (فواتير/إيرادات/
 * منتجات/عملاء/مخزون) بتاتاً، ولو كانت مجمّعة (aggregate) عبر كل المتاجر.
 * دوره يقتصر حصراً على إدارة ترقيات Pro وحسابات المستخدمين على مستوى المنصّة.
 * تم عمداً حذف total_invoices/total_revenue/total_products/total_customers/
 * daily_sales من overview لأنها بيانات تشغيلية تخص المتاجر ولا تخص المطوّر.
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$action = $_GET['action'] ?? 'overview';

if ($action === 'overview') {
    $totalTenants  = (int)$pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
    $activeTenants = (int)$pdo->query('SELECT COUNT(*) FROM tenants WHERE is_active = 1')->fetchColumn();
    $totalUsers   = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $activeUsers  = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn();
    $proUsers     = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_pro = 1')->fetchColumn();
    $pendingPro   = (int)$pdo->query("SELECT COUNT(*) FROM pro_requests WHERE status = 'pending'")->fetchColumn();

    // ✅ بيانات حساب فقط (بلا أي مؤشر تشغيلي/مالي) — للسياق الإداري لمهام Pro فقط
    $recentUsers = $pdo->query(
        'SELECT id, name, email, role, is_pro, is_active, created_at FROM users ORDER BY id DESC LIMIT 8'
    )->fetchAll();

    json_ok([
        'total_tenants'   => $totalTenants,
        'active_tenants'  => $activeTenants,
        'total_users'     => $totalUsers,
        'active_users'    => $activeUsers,
        'pro_users'       => $proUsers,
        'pending_pro_requests' => $pendingPro,
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
    // ⚠️ أمان: نعرض فقط سجلات إجراءات المطوّر نفسه على المنصّة (dev_panel_*)
    // أو محاولات دخول لوحة المطوّر — أبداً أحداث تشغيلية داخل متجر (فواتير/
    // مبيعات/مخزون) حتى لو ظهرت بصيغة مجمّعة، لأن ذلك يكشف نشاط المتاجر.
    $rows = $pdo->prepare(
        "SELECT id, action, user_email, ip_address, created_at
         FROM audit_log
         WHERE user_email = 'developer' OR action LIKE 'dev_panel%'
         ORDER BY id DESC LIMIT 50"
    );
    $rows->execute();
    json_ok($rows->fetchAll());
}

json_error('إجراء غير معروف', 400);
