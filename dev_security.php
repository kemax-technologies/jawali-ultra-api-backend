<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة الأمان: قفل المحاولات (Rate Limits) + جلسات المدير
 * GET  ?action=rate_limits                → قائمة القفولات الحالية النشِطة
 * POST { action:'clear_rate_limit', bucket, client_key } → إزالة قفل محدد
 * POST { action:'clear_all_rate_limits' } → إزالة كل القفولات (طوارئ)
 * GET  ?action=sessions                   → جلسات لوحة المدير النشِطة
 * POST { action:'revoke_session', id }     → إبطال جلسة مدير محددة
 * POST { action:'revoke_all_sessions' }    → إبطال كل جلسات المدير (طوارئ أمني)
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

if ($method === 'GET' && $action === 'sessions') {
    $rows = $pdo->query(
        "SELECT id, user_email, ip_address, user_agent, created_at, last_seen, expires_at, revoked
         FROM admin_sessions
         WHERE revoked = 0 AND expires_at > now()
         ORDER BY last_seen DESC LIMIT 200"
    )->fetchAll();
    json_ok(['sessions' => $rows]);
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

if ($method === 'POST' && $action === 'revoke_session') {
    $b = input_json();
    $id = (int)($b['id'] ?? 0);
    if ($id <= 0) json_error('id مطلوب');
    $pdo->prepare('UPDATE admin_sessions SET revoked = 1 WHERE id = ?')->execute([$id]);
    audit("dev_panel: revoke admin session #$id", 'developer');
    json_ok(['success' => true]);
}

if ($method === 'POST' && $action === 'revoke_all_sessions') {
    $pdo->exec('UPDATE admin_sessions SET revoked = 1 WHERE revoked = 0');
    audit('dev_panel: revoke ALL admin sessions', 'developer');
    json_ok(['success' => true]);
}

json_error('طلب غير صالح', 400);
