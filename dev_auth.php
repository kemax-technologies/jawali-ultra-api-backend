<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — تسجيل الدخول (خاص بالمطوّر فقط)
 * POST ?action=login   { password }        → { token }
 * GET  ?action=verify   (Bearer token)      → { ok:true }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';
require_once __DIR__ . '/_rate_limit.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST' && $action === 'login') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // ✅ تحديد معدل المحاولات: 8 محاولات كل 15 دقيقة لكل IP لمنع Brute-force
    rl_check('dev_login', $ip, 8, 900);

    $b = input_json();
    $password = (string)($b['password'] ?? '');

    if (DEV_PASSWORD_HASH === '' || $password === '') {
        json_error('كلمة المرور غير صحيحة', 401);
    }

    if (!password_verify($password, DEV_PASSWORD_HASH)) {
        audit('dev_panel_login_failed', "ip:$ip");
        json_error('كلمة المرور غير صحيحة', 401);
    }

    rl_clear('dev_login', $ip);
    audit('dev_panel_login_success', "ip:$ip");
    json_ok(['token' => dev_jwt_create()]);
    exit;
}

if ($method === 'GET' && $action === 'verify') {
    dev_require_auth();
    json_ok(['ok' => true]);
    exit;
}

json_error('طلب غير صالح', 400);
