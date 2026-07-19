<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Developer Panel: مساعدات مصادقة خاصة بالمطوّر
 * منفصلة تماماً عن نظام مستخدمي التطبيق (جداول users/pro_requests إلخ) —
 * هذا نظام دخول واحد فقط، خاص بالمطوّر، لا يظهر أبداً لأي مستخدم عادي.
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';

// ── إعدادات لوحة المطوّر (من .env) ───────────────────────────────────────────
define('DEV_PASSWORD_HASH', getenv('DEV_PANEL_PASSWORD_HASH') ?: '');
define('DEV_JWT_SECRET', getenv('DEV_JWT_SECRET') ?: JWT_SECRET . '::dev-panel');
define('DEV_JWT_EXPIRE', 60 * 60 * 12); // 12 ساعة

function dev_jwt_create(): string {
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload = ['sub' => 'developer', 'iat' => time(), 'exp' => time() + DEV_JWT_EXPIRE];
    $h = b64url_encode(json_encode($header));
    $p = b64url_encode(json_encode($payload));
    $sig = hash_hmac('sha256', "$h.$p", DEV_JWT_SECRET, true);
    return "$h.$p." . b64url_encode($sig);
}

function dev_jwt_verify(?string $token): bool {
    if (!$token) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$h, $p, $sig] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", DEV_JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return false;
    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($payload)) return false;
    if (($payload['exp'] ?? 0) < time()) return false;
    if (($payload['sub'] ?? '') !== 'developer') return false;
    return true;
}

function dev_require_auth(): void {
    if (!dev_jwt_verify(bearer_token())) {
        json_error('غير مصرح — يرجى تسجيل الدخول للوحة المطوّر', 401);
    }
}
