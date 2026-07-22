<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Supabase Auth REST Helper (بريد إلكتروني حقيقي بدون أي مزوّد
 * خارجي مدفوع — يستخدم SMTP المدمج المجاني في Supabase Auth فقط)
 *
 * الاستخدام الوحيد لهذا الملف: إرسال والتحقق من رمز OTP (6 أرقام) عبر البريد
 * الإلكتروني باستخدام واجهة Supabase Auth العامة (/auth/v1/otp و /auth/v1/verify)
 * — لا حاجة لأي حساب SendGrid/Mailgun/غيره، ولا أي تكلفة إضافية.
 *
 * ⚠️ يُستخدم فقط لقناة البريد الإلكتروني في استعادة كلمة المرور. قناة SMS
 * تبقى كما هي (عبر _notify.php + Twilio) — Supabase لا توفّر بديلاً مجانياً
 * لـ SMS لأنها بحاجة دوماً لمزوّد اتصالات خارجي (قيد تقني من Supabase ذاتها،
 * وليس فقط من التطبيق).
 * ─────────────────────────────────────────────────────────────────────────────
 */

define('SUPABASE_URL', getenv('SUPABASE_URL') ?: '');
define('SUPABASE_ANON_KEY', getenv('SUPABASE_ANON_KEY') ?: '');

/** يُعلن سنتينل خاص لتمييز صفوف OTP المُدارة عبر Supabase (بدل sha256 hash محلي) */
const SUPABASE_OTP_SENTINEL = '__SUPABASE_MANAGED__';

function _supabase_configured(): bool {
    return SUPABASE_URL !== '' && SUPABASE_ANON_KEY !== '';
}

function _supabase_post(string $path, array $body): array {
    $url = rtrim(SUPABASE_URL, '/') . $path;
    $headers = [
        'apikey: ' . SUPABASE_ANON_KEY,
        'Content-Type: application/json',
    ];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $json = json_decode((string)$resp, true);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => $json, 'raw' => $resp, 'error' => $err];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => json_encode($body),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/(\d{3})/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    $json = json_decode((string)$resp, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'json' => $json, 'raw' => $resp, 'error' => $resp === false ? 'file_get_contents_failed' : ''];
}

/**
 * إرسال رمز OTP حقيقي إلى البريد الإلكتروني عبر Supabase Auth.
 * ⚠️ يُستخدَم فقط بعد التحقق من وجود المستخدم فعلاً في قاعدة بياناتنا
 * (لمنع استغلال الميزة في إرسال رسائل لأي بريد عشوائي — Account Enumeration/Spam Protection).
 */
function supabase_send_email_otp(string $email): bool {
    if (!_supabase_configured()) {
        error_log("[Jawali][Supabase-OTP-DEV] (SUPABASE_URL/ANON_KEY غير مضبوطة) email=$email");
        return true; // وضع تطوير — لا نُفشل الطلب الأصلي
    }
    $result = _supabase_post('/auth/v1/otp', [
        'email'       => $email,
        'create_user' => true, // ينشئ حساب Supabase Auth "ظِلّي" تلقائياً إذا لم يكن موجوداً
    ]);
    if (!$result['ok']) {
        error_log("[Jawali][Supabase-OTP-FAIL] email=$email code={$result['code']} body=" . substr((string)$result['raw'], 0, 300));
        return false;
    }
    error_log("[Jawali][Supabase-OTP-OK] email=$email");
    return true;
}

/** التحقق من رمز OTP الذي أرسله Supabase عبر البريد الإلكتروني */
function supabase_verify_email_otp(string $email, string $code): bool {
    if (!_supabase_configured()) {
        // وضع تطوير فقط: نقبل الرمز إن كان JAWALI_DEV_MODE=1 (لتسهيل الاختبار قبل الربط)
        return getenv('JAWALI_DEV_MODE') === '1';
    }
    $result = _supabase_post('/auth/v1/verify', [
        'type'  => 'email',
        'email' => $email,
        'token' => $code,
    ]);
    if (!$result['ok']) {
        error_log("[Jawali][Supabase-Verify-FAIL] email=$email code={$result['code']} body=" . substr((string)$result['raw'], 0, 300));
        return false;
    }
    error_log("[Jawali][Supabase-Verify-OK] email=$email");
    return true;
}
