<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة الأمان: قفل المحاولات (Rate Limits)
 * GET  ?action=rate_limits                → قائمة القفولات الحالية النشِطة
 * POST { action:'clear_rate_limit', bucket, client_key } → إزالة قفل محدد
 * POST { action:'clear_all_rate_limits' } → إزالة كل القفولات (طوارئ)
 *
 * ✅ تم حذف admin_web بالكامل من النظام — أُزيلت معه إجراءات جلسات لوحة
 * المدير القديمة (sessions / revoke_session / revoke_all_sessions) التي كانت
 * تعمل حصراً على جدول admin_sessions المرتبط بتلك اللوحة المحذوفة.
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'POST' ? (input_json()['action'] ?? '') : '');

if ($method === 'GET' && $action === 'rate_limits') {
    $rows = $pdo->query(
        "SELECT bucket, client_key, attempts, window_start
         FROM rate_limits
         WHERE window_start >= now() - INTERVAL '24 hours'
         ORDER BY window_start DESC LIMIT 200"
    )->fetchAll();
    json_ok(['locks' => $rows]);
}

if ($method === 'POST' && $action === 'clear_rate_limit') {
    $b = input_json();
    $bucket = trim((string)($b['bucket'] ?? ''));
    $key = trim((string)($b['client_key'] ?? ''));
    if ($bucket === '' || $key === '') json_error('bucket و client_key مطلوبان');
    $pdo->prepare('DELETE FROM rate_limits WHERE bucket = ? AND client_key = ?')->execute([$bucket, $key]);
    audit("dev_panel: clear rate limit $bucket / $key", 'developer');
    json_ok(['success' => true]);
}

if ($method === 'POST' && $action === 'clear_all_rate_limits') {
    $pdo->exec('DELETE FROM rate_limits');
    audit('dev_panel: clear ALL rate limits', 'developer');
    json_ok(['success' => true]);
}

json_error('طلب غير صالح', 400);
