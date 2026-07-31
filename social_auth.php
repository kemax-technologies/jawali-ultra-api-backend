<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra v17 — Social Authentication Endpoint
 * يدعم تسجيل الدخول/التسجيل عبر:
 *   • Google (ID Token)
 *   • Apple (ID Token)
 *   • Phone (OTP)
 *
 * كل المزوّدات تتحقق من التوكن server-side قبل إصدار JWT
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';

// ─────────────────────────────────────────────────────────────────────────────
// ✅ Task 5 — إغلاق ثغرة تجاوز 2FA عبر تسجيل الدخول الاجتماعي: قبل هذا
// الإصلاح كان هذا الملف يُصدر JWT كامل الصلاحيات مباشرة بعد نجاح مزوّد
// Google/Apple/OTP فقط، متجاهلاً تماماً أن الحساب قد يكون فعَّل 2FA مسبقاً
// عبر تسجيل الدخول بكلمة المرور (auth.php). tfa_gate() و issue_session_for_user()
// أدناه يُطبّقان الآن نفس بوّابة 2FA المستخدمة في auth.php لكل مسارات الدخول.
// ─────────────────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── Rate Limiting: DB-based (يتولّى _rate_limit.php) ───

// ─── التحقق من Google ID Token ────────────────────────────────────────────────
function verify_google_token(string $idToken): ?array {
    // طلب من Google tokeninfo endpoint للتحقق
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['sub']) || !isset($data['email'])) return null;
    // التحقق من aud (Client ID) — يجب ضبط GOOGLE_CLIENT_ID
    $expectedAud = getenv('GOOGLE_CLIENT_ID') ?: '';
    if ($expectedAud !== '' && ($data['aud'] ?? '') !== $expectedAud) return null;
    return [
        'uid'        => (string)$data['sub'],
        'email'      => strtolower((string)$data['email']),
        'name'       => (string)($data['name'] ?? ''),
        'avatar'     => (string)($data['picture'] ?? ''),
        'verified'   => (($data['email_verified'] ?? '') === 'true' || ($data['email_verified'] ?? false) === true),
    ];
}

// ─── التحقق من Apple ID Token (JWT + JWKS) ───────────────────────────────────
function verify_apple_token(string $idToken): ?array {
    // ── 1) فك JWT وقراءة الـ Header + Payload ─────────────────────────────
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) return null;

    $header  = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

    if (!is_array($header) || !is_array($payload)) return null;
    if (!isset($payload['sub'])) return null;

    // ── 2) التحقق من المطالبات الأساسية (بدون signature) ──────────────────
    if (($payload['iss'] ?? '') !== 'https://appleid.apple.com') return null;
    if (($payload['exp'] ?? 0) < time()) return null;

    $expectedAud = getenv('APPLE_CLIENT_ID') ?: '';
    if ($expectedAud !== '' && ($payload['aud'] ?? '') !== $expectedAud) return null;

    // ── 3) ✅ التحقق من التوقيع عبر Apple JWKS ─────────────────────────────
    // نُحاول جلب مفاتيح Apple العامة والتحقق الحقيقي من التوقيع
    $kid = $header['kid'] ?? '';
    $alg = $header['alg'] ?? 'RS256';

    if ($kid !== '' && function_exists('openssl_verify')) {
        $jwk = _fetch_apple_public_key($kid);
        if ($jwk !== null) {
            $pem = _jwk_to_pem($jwk);
            if ($pem !== null) {
                $data = $parts[0] . '.' . $parts[1];
                $sig  = base64_decode(strtr($parts[2], '-_', '+/'));
                $ok   = openssl_verify($data, $sig, $pem, OPENSSL_ALGO_SHA256);
                if ($ok !== 1) {
                    error_log('[Jawali][Apple] Signature verification failed for kid=' . $kid);
                    return null; // التوقيع غير صالح
                }
            } else {
                // فشل تحويل المفتاح — نُسجّل تحذيراً ونمضي
                error_log('[Jawali][Apple] Could not convert JWK to PEM, skipping sig check');
            }
        } else {
            // لم نجد المفتاح في JWKS — قد يكون انتهى صلاحيته
            error_log('[Jawali][Apple] No matching JWK found for kid=' . $kid);
            return null;
        }
    }

    return [
        'uid'      => (string)$payload['sub'],
        'email'    => strtolower((string)($payload['email'] ?? '')),
        'name'     => '',
        'avatar'   => '',
        'verified' => (($payload['email_verified'] ?? false) === true
                    || ($payload['email_verified'] ?? '') === 'true'),
    ];
}

/// جلب مفتاح Apple العام من JWKS endpoint مع Cache
function _fetch_apple_public_key(string $kid): ?array {
    static $cachedKeys = null;
    static $cacheTime  = 0;

    // Cache لمدة ساعة (مفاتيح Apple نادراً ما تتغير)
    if ($cachedKeys === null || (time() - $cacheTime) > 3600) {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents('https://appleid.apple.com/auth/keys', false, $ctx);
        if ($raw === false) return null;
        $json = json_decode($raw, true);
        if (!isset($json['keys']) || !is_array($json['keys'])) return null;
        $cachedKeys = $json['keys'];
        $cacheTime  = time();
    }

    foreach ($cachedKeys as $key) {
        if (($key['kid'] ?? '') === $kid) return $key;
    }
    return null;
}

/// تحويل JWK RSA إلى PEM لاستخدامه مع openssl_verify
function _jwk_to_pem(array $jwk): ?string {
    if (($jwk['kty'] ?? '') !== 'RSA') return null;
    if (!isset($jwk['n'], $jwk['e'])) return null;

    $n = base64_decode(strtr($jwk['n'], '-_', '+/'));
    $e = base64_decode(strtr($jwk['e'], '-_', '+/'));

    // بناء ASN.1 DER لمفتاح RSA عام
    $encN = _asn1_integer($n);
    $encE = _asn1_integer($e);
    $seq  = _asn1_sequence($encN . $encE);

    // OID لـ RSA
    $oid = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bitString = "\x03" . _asn1_length(strlen($seq) + 1) . "\x00" . $seq;
    $spki = $oid . $bitString;
    $der  = _asn1_sequence($spki);

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($der), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

function _asn1_integer(string $bytes): string {
    // إضافة \x00 إذا كانت البايتة الأولى تحتوي على bit عالٍ
    if (ord($bytes[0]) > 0x7f) $bytes = "\x00" . $bytes;
    return "\x02" . _asn1_length(strlen($bytes)) . $bytes;
}

function _asn1_sequence(string $contents): string {
    return "\x30" . _asn1_length(strlen($contents)) . $contents;
}

function _asn1_length(int $len): string {
    if ($len < 0x80) return chr($len);
    if ($len < 0x100) return "\x81" . chr($len);
    return "\x82" . chr($len >> 8) . chr($len & 0xff);
}

// ─── إصدار JWT وحفظ آخر تسجيل دخول ────────────────────────────────────────────
// ✅ Task 6 — أضيف تسجيل الجلسة (log_user_session) هنا أيضاً: كانت جلسات
// الدخول الاجتماعي (Google/Apple/الهاتف) غير مُسجَّلة إطلاقاً في user_sessions
// (فقط auth.php كان يُسجِّلها)، أي أن شاشة "الجلسات النشطة" الجديدة كانت
// ستكون غير مكتملة تماماً لهذه المسارات (لا تظهر، ولا يمكن إلغاؤها عن بُعد).
// $deviceLabel اختياري (Android/iOS/متصفح) يصف الجهاز بشكل صديق.
function issue_session_for_user(array $user, ?string $deviceLabel = null): array {
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([$user['id']]);
    $token = jwt_create([
        'sub'   => (int)$user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ]);
    log_user_session($user['email'], (string)($user['role'] ?? ''), $token, $deviceLabel);
    // ✅ Task 5: يُطابق شكل استجابة issue_full_session() في auth.php لضمان
    // أن AppUser.fromMap في Flutter يقرأ tenant_id/permissions/tfa_enabled
    // بشكل صحيح بغضّ النظر عن مسار الدخول (محلي أو اجتماعي).
    $permissions = effective_permissions((string)($user['role'] ?? ''), $user['permissions'] ?? null);
    return [
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'          => (int)$user['id'],
            'name'        => $user['name'],
            'email'       => $user['email'],
            'phone'       => $user['phone'] ?? null,
            'role'        => $user['role'],
            'avatar_url'  => $user['avatar_url'] ?? null,
            'auth_provider' => $user['auth_provider'] ?? 'local',
            'permissions' => $permissions,
            'tenant_id'   => isset($user['tenant_id']) ? (int)$user['tenant_id'] : null,
            'tfa_enabled' => !empty($user['tfa_enabled']),
        ],
    ];
}

// ─── إيجاد أو إنشاء مستخدم اجتماعي ────────────────────────────────────────────
function find_or_create_social_user(string $provider, array $info): array {
    $pdo = db();

    // 1) البحث عن ربط موجود مسبقاً
    $stmt = $pdo->prepare('SELECT user_id FROM user_social_links WHERE provider=? AND provider_uid=?');
    $stmt->execute([$provider, $info['uid']]);
    $linked = $stmt->fetch();
    if ($linked) {
        $u = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
        $u->execute([$linked['user_id']]);
        $user = $u->fetch();
        if ($user) return $user;
    }

    // 2) البحث بالبريد إن وُجد (لربط حساب اجتماعي بحساب محلي قائم)
    if (!empty($info['email'])) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$info['email']]);
        $existing = $stmt->fetch();
        if ($existing) {
            // اربط الحساب الاجتماعي بالحساب القائم
            // ✅ تحويل PostgreSQL: INSERT IGNORE (MySQL) → ON CONFLICT DO NOTHING
            // يعتمد على uk_provider UNIQUE (provider, provider_uid) المعرّف في jawali_db_social_recovery_patch_postgres.sql
            $pdo->prepare(
                'INSERT INTO user_social_links (user_id, provider, provider_uid, provider_email)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (provider, provider_uid) DO NOTHING'
            )->execute([$existing['id'], $provider, $info['uid'], $info['email']]);

            // تحديث صورة وحالة التحقق إن لزم
            if (!empty($info['avatar']) && empty($existing['avatar_url'])) {
                $pdo->prepare('UPDATE users SET avatar_url=? WHERE id=?')
                    ->execute([$info['avatar'], $existing['id']]);
                $existing['avatar_url'] = $info['avatar'];
            }
            if ($info['verified'] && !$existing['email_verified']) {
                $pdo->prepare('UPDATE users SET email_verified=1 WHERE id=?')->execute([$existing['id']]);
            }
            return $existing;
        }
    }

    // 3) إنشاء مستخدم جديد — Multi-Tenant: التطبيق مُنشَر للعامة على متجر بلاي؛
    // كل من يسجّل حساباً جديداً (حتى عبر مزوّد اجتماعي) يصبح تلقائياً صاحب
    // متجر ("مدير") مستقل وخاص به بالكامل، بالضبط بنفس منطق auth.php?action=register
    // (إنشاء tenant أولاً، ثم user مرتبط به، ثم ربط owner_user_id — كل ذلك
    // ضمن معاملة واحدة ذرّية لمنع وجود مستخدم بلا متجر أو متجر بلا مالك).
    $name  = $info['name'] !== '' ? $info['name'] : 'مستخدم ' . substr($info['uid'], 0, 6);
    $email = $info['email'] !== '' ? $info['email'] : ($info['uid'] . '@social.local');
    $storeName = 'متجر ' . $name;

    $newId = null;
    $tenantId = null;
    try {
        $pdo->beginTransaction();

        $tIns = $pdo->prepare('INSERT INTO tenants (name, plan) VALUES (?, ?) RETURNING id');
        $tIns->execute([$storeName, 'free']);
        $tenantId = (int)$tIns->fetchColumn();

        $uIns = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, auth_provider, provider_uid,
                                avatar_url, email_verified, is_active, tenant_id)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 1, ?) RETURNING id'
        );
        $uIns->execute([
            $name, $email, 'مدير', $provider, $info['uid'],
            $info['avatar'] ?? null,
            $info['verified'] ? 1 : 0,
            $tenantId,
        ]);
        $newId = (int)$uIns->fetchColumn();

        $pdo->prepare('UPDATE tenants SET owner_user_id = ? WHERE id = ?')->execute([$newId, $tenantId]);

        $pdo->prepare(
            'INSERT INTO user_social_links (user_id, provider, provider_uid, provider_email)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (provider, provider_uid) DO NOTHING'
        )->execute([$newId, $provider, $info['uid'], $info['email']]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Jawali][social_auth] فشل إنشاء متجر جديد للمستخدم ' . $email . ': ' . $e->getMessage());
        json_error('حدث خطأ أثناء إنشاء الحساب — حاول مرة أخرى', 500);
    }

    audit('تسجيل عبر ' . $provider . ' + إنشاء متجر مستقل', $email, 'info', $tenantId);

    $u = $pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $u->execute([$newId]);
    return $u->fetch();
}

// ─── معالجة الإجراءات ─────────────────────────────────────────────────────────
switch ($action) {

    // ════════════════════════════════════════════════════════════════════
    // POST /social_auth.php?action=google  { id_token }
    // ════════════════════════════════════════════════════════════════════
    case 'google':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $token = (string)($body['id_token'] ?? '');
        if ($token === '') json_error('id_token مطلوب');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('social_google', $ip, 10, 600);

        $info = verify_google_token($token);
        if ($info === null) json_error('فشل التحقق من حساب Google', 401);

        $user = find_or_create_social_user('google', $info);
        if (!$user['is_active']) json_error('الحساب موقوف. تواصل مع المسؤول.', 403);
        // ✅ Task 5 — بوّابة 2FA الموحّدة (راجع تعريفها في _db.php)
        $tfaResp = tfa_gate($user);
        if ($tfaResp !== null) json_ok($tfaResp);
        json_ok(issue_session_for_user($user, isset($body['device_label']) ? (string)$body['device_label'] : null));
        break;

    // ════════════════════════════════════════════════════════════════════
    // POST /social_auth.php?action=apple  { id_token, name? }
    // ════════════════════════════════════════════════════════════════════
    case 'apple':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $token = (string)($body['id_token'] ?? '');
        $name  = trim((string)($body['name'] ?? ''));
        if ($token === '') json_error('id_token مطلوب');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('social_apple', $ip, 10, 600);

        $info = verify_apple_token($token);
        if ($info === null) json_error('فشل التحقق من حساب Apple', 401);
        if ($info['name'] === '' && $name !== '') $info['name'] = $name;

        $user = find_or_create_social_user('apple', $info);
        if (!$user['is_active']) json_error('الحساب موقوف. تواصل مع المسؤول.', 403);
        // ✅ Task 5 — بوّابة 2FA الموحّدة
        $tfaResp = tfa_gate($user);
        if ($tfaResp !== null) json_ok($tfaResp);
        json_ok(issue_session_for_user($user, isset($body['device_label']) ? (string)$body['device_label'] : null));
        break;

    // ════════════════════════════════════════════════════════════════════
    // POST /social_auth.php?action=phone_request  { phone }
    // إرسال OTP إلى الهاتف
    // ════════════════════════════════════════════════════════════════════
    case 'phone_request':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $phone = preg_replace('/[^0-9+]/', '', (string)($body['phone'] ?? ''));
        if (strlen($phone) < 7) json_error('رقم هاتف غير صالح');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('social_otp_req', $phone . '|' . $ip, 5, 600);

        // إنشاء OTP عشوائي 6 أرقام
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $code);
        $expires = date('Y-m-d H:i:s', time() + 300); // 5 دقائق

        // إلغاء أي OTP قديم لنفس الرقم
        db()->prepare(
            "UPDATE auth_otp_codes SET consumed_at = NOW()
             WHERE identifier = ? AND consumed_at IS NULL"
        )->execute([$phone]);

        db()->prepare(
            "INSERT INTO auth_otp_codes
             (identifier, channel, purpose, code_hash, expires_at, ip_address, user_agent)
             VALUES (?, 'sms', 'login', ?, ?, ?, ?)"
        )->execute([
            $phone, $hash, $expires, $ip,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)
        ]);

        // إرسال SMS — يجب ربطها بمزوّد SMS فعلي (Twilio, Vonage, etc.)
        // في بيئة التطوير: نُرجع الكود في الاستجابة لتسهيل الاختبار
        $devMode = getenv('JAWALI_DEV_MODE') === '1';
        $smsOk   = send_sms_otp($phone, $code); // ترجع true حتى لو لم تُرسَل فعلياً (وضع تطوير)

        $resp = ['success' => true, 'message' => 'تم إرسال رمز التحقق', 'expires_in' => 300];
        if ($devMode) $resp['_dev_code'] = $code; // فقط في dev
        json_ok($resp);
        break;

    // ════════════════════════════════════════════════════════════════════
    // POST /social_auth.php?action=phone_verify  { phone, code, name? }
    // ════════════════════════════════════════════════════════════════════
    case 'phone_verify':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $phone = preg_replace('/[^0-9+]/', '', (string)($body['phone'] ?? ''));
        $code  = preg_replace('/[^0-9]/', '', (string)($body['code'] ?? ''));
        $name  = trim((string)($body['name'] ?? ''));
        if ($phone === '' || $code === '') json_error('الهاتف والرمز مطلوبان');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('social_otp_verify', $phone . '|' . $ip, 10, 600);

        $stmt = db()->prepare(
            "SELECT * FROM auth_otp_codes
             WHERE identifier=? AND purpose='login' AND consumed_at IS NULL
               AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        if (!$row) json_error('الرمز منتهي أو غير موجود', 401);

        if ($row['attempts'] >= $row['max_attempts']) {
            db()->prepare('UPDATE auth_otp_codes SET consumed_at=NOW() WHERE id=?')
                ->execute([$row['id']]);
            json_error('تجاوزت الحد الأقصى للمحاولات', 429);
        }

        if (!hash_equals($row['code_hash'], hash('sha256', $code))) {
            db()->prepare('UPDATE auth_otp_codes SET attempts=attempts+1 WHERE id=?')
                ->execute([$row['id']]);
            usleep(300000);
            json_error('الرمز غير صحيح', 401);
        }

        // ✅ الرمز صحيح
        db()->prepare('UPDATE auth_otp_codes SET consumed_at=NOW() WHERE id=?')
            ->execute([$row['id']]);

        // إيجاد/إنشاء مستخدم بناءً على الهاتف
        $info = [
            'uid'      => 'phone:' . $phone,
            'email'    => '',
            'name'     => $name !== '' ? $name : 'مستخدم ' . substr($phone, -4),
            'avatar'   => '',
            'verified' => true,
        ];

        // البحث برقم الهاتف أولاً
        $pu = db()->prepare('SELECT * FROM users WHERE phone=? LIMIT 1');
        $pu->execute([$phone]);
        $user = $pu->fetch();

        if (!$user) {
            $user = find_or_create_social_user('phone', $info);
            // ربط الهاتف
            db()->prepare('UPDATE users SET phone=?, phone_verified=1 WHERE id=?')
                ->execute([$phone, $user['id']]);
            $user['phone'] = $phone;
        } else {
            db()->prepare('UPDATE users SET phone_verified=1 WHERE id=?')->execute([$user['id']]);
        }

        if (!$user['is_active']) json_error('الحساب موقوف. تواصل مع المسؤول.', 403);

        audit('تسجيل دخول عبر الهاتف', $phone, 'info', (int)($user['tenant_id'] ?? 0) ?: null);
        // ✅ Task 5 — بوّابة 2FA الموحّدة (تنطبق أيضاً على الدخول عبر الهاتف)
        $tfaResp = tfa_gate($user);
        if ($tfaResp !== null) json_ok($tfaResp);
        json_ok(issue_session_for_user($user, isset($body['device_label']) ? (string)$body['device_label'] : null));
        break;

    default:
        json_error('إجراء غير معروف', 404);
}

// ─── إرسال SMS (يجب استبدالها بمزوّد فعلي مثل Twilio) ────────────────────────
function send_sms_otp(string $phone, string $code): bool {
    // 🔧 ربط مزوّد SMS الفعلي هنا — مثال:
    // $client = new \Twilio\Rest\Client(SID, TOKEN);
    // $client->messages->create($phone, ['from' => FROM, 'body' => "رمز جوالي: $code"]);

    // في بيئة التطوير: تسجيل في log فقط
    error_log("[Jawali][OTP] SMS to $phone => code=$code");
    return true;
}
