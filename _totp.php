<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔐 TOTP (RFC 6238) — تنفيذ خادم مطابق تماماً لخوارزمية العميل (Flutter)
 * الموجودة في lib/services/two_factor_service.dart (HMAC-SHA1, Base32,
 * 6 أرقام, دورة 30 ثانية, نافذة تسامح ±1 خطوة).
 *
 * لا توجد مكتبة Composer لـ TOTP مثبَّتة في هذا المشروع (composer.json يحتوي
 * فقط على امتدادات PHP الأساسية: pdo/pdo_pgsql/json/mbstring) — لذلك هذا
 * التنفيذ مكتوب يدوياً بالكامل باستخدام دوال PHP القياسية فقط (hash_hmac،
 * random_bytes) دون أي اعتماديات خارجية إضافية.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Base32 (RFC 4648) ─────────────────────────────────────────────────────────
function totp_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $len = strlen($data); $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $output .= $alphabet[bindec($chunk)];
    }
    while (strlen($output) % 8 !== 0) $output .= '=';
    return $output;
}

function totp_base32_decode(string $base32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $base32 = strtoupper(rtrim(trim($base32), '='));
    $bits = '';
    for ($i = 0, $len = strlen($base32); $i < $len; $i++) {
        $pos = strpos($alphabet, $base32[$i]);
        if ($pos === false) continue; // تجاهل أي رمز غير صالح بأمان (يطابق سلوك Dart)
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $output = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $output .= chr(bindec($byte));
    }
    return $output;
}

// ── توليد سر عشوائي (20 بايت → Base32) ────────────────────────────────────────
function totp_generate_secret(): string {
    return totp_base32_encode(random_bytes(20));
}

// ── حساب رمز TOTP لخطوة زمنية محدَّدة ─────────────────────────────────────────
function totp_generate(
    string $base32Secret,
    int $digits = 6,
    int $period = 30,
    ?int $timestamp = null
): string {
    $secretBytes = totp_base32_decode($base32Secret);
    $time = $timestamp ?? time();
    $counter = intdiv($time, $period);

    // 8 بايت big-endian (32 بت عُليا صفر عملياً + 32 بت سُفلى تحمل القيمة) —
    // يطابق بالضبط ByteData.setUint32(0, T>>32)/setUint32(4, T&0xFFFFFFFF) في Dart
    $binCounter = pack('N2', $counter >> 32, $counter & 0xFFFFFFFF);

    $hash = hash_hmac('sha1', $binCounter, $secretBytes, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;

    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);

    $otp = $truncated % (10 ** $digits);
    return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
}

// ── التحقق من الرمز (نافذة ±1 خطوة، مطابقة لتسامح العميل) ─────────────────────
function totp_verify(
    string $base32Secret,
    string $code,
    int $digits = 6,
    int $period = 30
): bool {
    $code = trim($code);
    if (strlen($code) !== $digits || !ctype_digit($code)) return false;

    $now = time();
    for ($delta = -1; $delta <= 1; $delta++) {
        $t = $now + ($delta * $period);
        $expected = totp_generate($base32Secret, $digits, $period, $t);
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}

// ── رابط otpauth:// لتطبيقات المصادقة (Google Authenticator / Authy...) ───────
function totp_build_otpauth_url(
    string $secret,
    string $email,
    string $issuer = 'جوالي أولترا'
): string {
    $encodedEmail = rawurlencode($email);
    $encodedIssuer = rawurlencode($issuer);
    return "otpauth://totp/{$encodedIssuer}:{$encodedEmail}"
        . "?secret={$secret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
}
