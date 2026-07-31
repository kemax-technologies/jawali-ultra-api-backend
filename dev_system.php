<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — صحة النظام والتشخيص العام (محمي)
 * GET → حالة الاتصال بقاعدة البيانات + إحصائيات كل الجداول + معلومات السيرفر
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method Not Allowed', 405);

$dbOk = true;
$dbError = null;
$tables = [];
try {
    $pdo = db();
    $tableNames = [
        'users', 'branches', 'products', 'customers', 'invoices', 'invoice_items',
        'suppliers', 'purchases', 'expenses', 'credits', 'cash_accounts',
        'cash_transactions', 'assets', 'employees', 'support_tickets',
        'pro_requests', 'audit_log', 'rate_limits', 'vouchers',
    ];
    foreach ($tableNames as $t) {
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
            $tables[$t] = $count;
        } catch (Exception $e) {
            $tables[$t] = null;
        }
    }
} catch (Exception $e) {
    $dbOk = false;
    $dbError = 'تعذّر الاتصال بقاعدة البيانات';
}

json_ok([
    'db_connected'   => $dbOk,
    'db_error'       => $dbError,
    'table_counts'   => $tables,
    'php_version'    => PHP_VERSION,
    'server_time'    => date('c'),
    'server_tz'      => date_default_timezone_get(),
    'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
]);
