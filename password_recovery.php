<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra v17 — Password Recovery Endpoint
 * نظام استعادة كلمة المرور بشكل آمن:
 *   • طلب رابط/رمز عبر البريد الإلكتروني أو الهاتف
 *   • التحقق من الرمز أو التوكن
 *   • تعيين كلمة مرور جديدة
 *
 * إجراءات الأمان:
 *   • SHA-256 لتخزين الرموز (لا plaintext)
 *   • صلاحية محدودة 15 دقيقة
 *   • Rate limiting صارم
 *   • Timing-safe comparison
 *   • عدم الكشف عن وجود الحساب من عدمه (Account Enumeration Protection)
 *   • سجل كامل لكل محاولة
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';
require_once __DIR__ . '/_notify.php';
require_once __DIR__ . '/_supabase.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── Rate Limiting: DB-based (يتولّى _rate_limit.php) ───

// ─── تسجيل كل محاولة (سواء نجحت أم لا) ───────────────────────────────────────
function log_recovery(string $identifier, bool $success): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    db()->prepare(
        'INSERT INTO password_recovery_log (identifier, ip_address, success) VALUES (?, ?, ?)'
    )->execute([$identifier, $ip, $success ? 1 : 0]);
}

// ─── البحث عن مستخدم بالبريد أو الهاتف ───────────────────────────────────────
function find_user_by_identifier(string $identifier, string $channel): ?array {
    if ($channel === 'email') {
        $stmt = db()->prepare('SELECT * FROM users WHERE email=? AND is_active=1 LIMIT 1');
        $stmt->execute([strtolower($identifier)]);
    } else {
        $stmt = db()->prepare('SELECT * FROM users WHERE phone=? AND is_active=1 LIMIT 1');
        $stmt->execute([$identifier]);
    }
    $u = $stmt->fetch();
    return $u ?: null;
}

// ─── التحقق من قوة كلمة المرور ──────────────────────────────────────────────
function pw_strength_check(string $p): ?string {
    if (strlen($p) < 8)               return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    if (!preg_match('/[A-Z]/', $p))   return 'يجب أن تحتوي على حرف كبير واحد على الأقل';
    if (!preg_match('/[a-z]/', $p))   return 'يجب أن تحتوي على حرف صغير واحد على الأقل';
    if (!preg_match('/[0-9]/', $p))   return 'يجب أن تحتوي على رقم واحد على الأقل';
    if (!preg_match('/[^A-Za-z0-9]/', $p)) return 'يجب أن تحتوي على رمز خاص (!@#$%^&*)';
    return null;
}

// ─── إرسال البريد الإلكتروني الحقيقي (SendGrid — انظر _notify.php) ───────────
function send_recovery_email(string $email, string $code, string $token, string $name): bool {
    $subject = 'استعادة كلمة المرور — Jawali Ultra';
    $body = "مرحباً $name،\n\n"
          . "تلقّينا طلباً لاستعادة كلمة مرور حسابك في Jawali Ultra.\n\n"
          . "🔑 رمز التحقق: $code\n"
          . "صالح لمدة 15 دقيقة.\n\n"
          . "إذا لم تطلب هذا، تجاهل الرسالة وسيظل حسابك آمناً.\n\n"
          . "— فريق Jawali Ultra";
    $html = '<div dir="rtl" style="font-family:sans-serif">'
          . "<p>مرحباً $name،</p>"
          . '<p>تلقّينا طلباً لاستعادة كلمة مرور حسابك في Jawali Ultra.</p>'
          . "<p style=\"font-size:22px;font-weight:bold;\">🔑 رمز التحقق: $code</p>"
          . '<p>صالح لمدة 15 دقيقة.</p>'
          . '<p>إذا لم تطلب هذا، تجاهل الرسالة وسيظل حسابك آمناً.</p>'
          . '<p>— فريق Jawali Ultra</p></div>';

    error_log("[Jawali][Recovery] Email to $email => token=$token");
    return jawali_send_email($email, $subject, $body, $html);
}

// ─── إرسال SMS الاستعادة الحقيقي (Twilio — انظر _notify.php) ─────────────────
function send_recovery_sms(string $phone, string $code): bool {
    return jawali_send_sms($phone, "رمز استعادة كلمة المرور — جوالي: $code (صالح 15 دقيقة)");
}

// ─── معالجة الإجراءات ─────────────────────────────────────────────────────────
switch ($action) {

    // ════════════════════════════════════════════════════════════════════
    // POST /password_recovery.php?action=request
    // body: { identifier: "email_or_phone", channel: "email"|"sms" }
    //
    // ⚠️ مهم: نُرجع نفس الاستجابة سواء وُجد المستخدم أم لا
    // (لمنع Account Enumeration Attack)
    // ════════════════════════════════════════════════════════════════════
    case 'request':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body       = input_json();
        $identifier = trim((string)($body['identifier'] ?? ''));
        $channel    = strtolower(trim((string)($body['channel'] ?? 'email')));

        if ($identifier === '') json_error('البريد أو الهاتف مطلوب');
        if (!in_array($channel, ['email', 'sms'], true)) json_error('قناة غير مدعومة');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('recovery_req', $identifier . '|' . $ip, 3, 900);

        // تطبيع
        if ($channel === 'email') {
            $identifier = strtolower($identifier);
            if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
                json_error('صيغة البريد غير صحيحة');
            }
        } else {
            $identifier = preg_replace('/[^0-9+]/', '', $identifier);
            if (strlen($identifier) < 7) json_error('رقم هاتف غير صالح');
        }

        $user = find_user_by_identifier($identifier, $channel);

        // ✅ الاستجابة موحّدة لمنع Enumeration
        $genericResponse = [
            'success' => true,
            'message' => $channel === 'email'
                ? 'إن وُجد حساب بهذا البريد، فقد أُرسل إليه رمز الاستعادة.'
                : 'إن وُجد حساب بهذا الرقم، فقد أُرسل إليه رمز الاستعادة عبر SMS.',
            'expires_in' => 900,
        ];

        if (!$user) {
            log_recovery($identifier, false);
            // تأخير ثابت لمنع تحليل التوقيت
            usleep(400000);
            json_ok($genericResponse);
        }

        // ✅ قناة البريد الإلكتروني: يتولّى Supabase Auth توليد وإرسال رمز OTP
        // حقيقي فعلياً (عبر SMTP المدمج المجاني في Supabase — بدون أي مزوّد
        // خارجي). نُخزّن محلياً فقط سنتينل خاص (بدل الكود الحقيقي غير المعروف
        // لنا) للتحقق من الصلاحية/المحاولات، والتحقق الفعلي من الرمز يتم لاحقاً
        // عبر supabase_verify_email_otp() في خطوة verify_code.
        $isEmailChannel = $channel === 'email';
        $code = $isEmailChannel
            ? SUPABASE_OTP_SENTINEL
            : str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = $isEmailChannel ? SUPABASE_OTP_SENTINEL : hash('sha256', $code);

        // إنشاء توكن طويل (لروابط الاستعادة من البريد)
        $tokenRaw  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenRaw);
        $expires   = date('Y-m-d H:i:s', time() + 900); // 15 دقيقة

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // إلغاء أي رموز/توكنز قديمة لنفس المستخدم
            $pdo->prepare(
                "UPDATE auth_otp_codes SET consumed_at=NOW()
                 WHERE identifier=? AND purpose='reset' AND consumed_at IS NULL"
            )->execute([$identifier]);

            $pdo->prepare(
                "UPDATE password_reset_tokens SET consumed_at=NOW()
                 WHERE user_id=? AND consumed_at IS NULL"
            )->execute([$user['id']]);

            // حفظ OTP
            $pdo->prepare(
                "INSERT INTO auth_otp_codes
                 (identifier, channel, purpose, code_hash, expires_at, ip_address, user_agent)
                 VALUES (?, ?, 'reset', ?, ?, ?, ?)"
            )->execute([
                $identifier, $channel, $codeHash, $expires, $ip,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)
            ]);

            // حفظ Token
            $pdo->prepare(
                "INSERT INTO password_reset_tokens
                 (user_id, token_hash, channel, expires_at, ip_address)
                 VALUES (?, ?, ?, ?, ?)"
            )->execute([$user['id'], $tokenHash, $channel, $expires, $ip]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][Recovery] DB error: ' . $e->getMessage());
            json_error('خطأ داخلي', 500);
        }

        // إرسال
        if ($isEmailChannel) {
            // ✅ إرسال حقيقي عبر Supabase Auth (SMTP مجاني مدمج)
            supabase_send_email_otp($identifier);
        } else {
            send_recovery_sms($identifier, $code);
        }

        log_recovery($identifier, true);
        audit('طلب استعادة كلمة المرور', $identifier);

        // في بيئة التطوير فقط: نُرجع الكود لتسهيل الاختبار (لا معنى لذلك في
        // قناة البريد لأن الكود الحقيقي يُصدره Supabase ولا نعرفه محلياً)
        if (getenv('JAWALI_DEV_MODE') === '1' && !$isEmailChannel) {
            $genericResponse['_dev_code']  = $code;
            $genericResponse['_dev_token'] = $tokenRaw;
        }

        json_ok($genericResponse);
        break;

    // ════════════════════════════════════════════════════════════════════
    // POST /password_recovery.php?action=verify_code
    // body: { identifier, code }
    // يتحقق من OTP ويُرجع reset_token قصير لاستخدامه في reset
    // ════════════════════════════════════════════════════════════════════
    case 'verify_code':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body       = input_json();
        $identifier = trim((string)($body['identifier'] ?? ''));
        $code       = preg_replace('/[^0-9]/', '', (string)($body['code'] ?? ''));

        if ($identifier === '' || $code === '') json_error('البيانات ناقصة');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('recovery_verify', $identifier . '|' . $ip, 10, 900);

        // تطبيع
        $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
        if ($isEmail) $identifier = strtolower($identifier);
        else          $identifier = preg_replace('/[^0-9+]/', '', $identifier);

        $stmt = db()->prepare(
            "SELECT * FROM auth_otp_codes
             WHERE identifier=? AND purpose='reset' AND consumed_at IS NULL
               AND expires_at > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();

        if (!$row) {
            usleep(400000);
            json_error('الرمز منتهي أو غير صحيح', 401);
        }
        if ($row['attempts'] >= $row['max_attempts']) {
            db()->prepare('UPDATE auth_otp_codes SET consumed_at=NOW() WHERE id=?')
                ->execute([$row['id']]);
            json_error('تجاوزت الحد الأقصى للمحاولات', 429);
        }

        $rowIsEmailManaged = $row['code_hash'] === SUPABASE_OTP_SENTINEL;
        if ($rowIsEmailManaged) {
            // ✅ قناة البريد: التحقق الفعلي من الرمز يتم عبر Supabase Auth
            // مباشرة (هو من ولّده وأرسله فعلياً، لا نملك نسخة محلية منه)
            if (!supabase_verify_email_otp($identifier, $code)) {
                db()->prepare('UPDATE auth_otp_codes SET attempts=attempts+1 WHERE id=?')
                    ->execute([$row['id']]);
                usleep(300000);
                json_error('الرمز غير صحيح', 401);
            }
        } elseif (!hash_equals($row['code_hash'], hash('sha256', $code))) {
            db()->prepare('UPDATE auth_otp_codes SET attempts=attempts+1 WHERE id=?')
                ->execute([$row['id']]);
            usleep(300000);
            json_error('الرمز غير صحيح', 401);
        }

        // ✅ الرمز صحيح — لا نُلغيه الآن، سيُلغى عند تنفيذ reset
        // نُصدر reset_token قصير الأمد (5 دقائق) صالح للخطوة التالية فقط
        $userChannel = $row['channel'];
        $u = find_user_by_identifier($identifier, $userChannel === 'email' ? 'email' : 'sms');
        if (!$u) json_error('الحساب غير موجود', 404);

        $resetTokenRaw  = bin2hex(random_bytes(24));
        $resetTokenHash = hash('sha256', $resetTokenRaw);
        $expiresStep    = date('Y-m-d H:i:s', time() + 300); // 5 دقائق

        // إلغاء أي توكنز سابقة وإصدار جديد
        db()->prepare(
            "UPDATE password_reset_tokens SET consumed_at=NOW()
             WHERE user_id=? AND consumed_at IS NULL"
        )->execute([$u['id']]);

        db()->prepare(
            "INSERT INTO password_reset_tokens
             (user_id, token_hash, channel, expires_at, ip_address)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$u['id'], $resetTokenHash, $userChannel, $expiresStep, $ip]);

        // إغلاق الـ OTP الآن (تم التحقق منه)
        db()->prepare('UPDATE auth_otp_codes SET consumed_at=NOW() WHERE id=?')
            ->execute([$row['id']]);

        json_ok([
            'success'      => true,
            'reset_token'  => $resetTokenRaw,
            'expires_in'   => 300,
            'message'      => 'تم التحقق بنجاح. يمكنك الآن تعيين كلمة مرور جديدة.',
        ]);
        break;

    // ════════════════════════════════════════════════════════════════════
    // POST /password_recovery.php?action=reset
    // body: { reset_token, new_password }
    // ════════════════════════════════════════════════════════════════════
    case 'reset':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body        = input_json();
        $resetToken  = (string)($body['reset_token'] ?? '');
        $newPassword = (string)($body['new_password'] ?? '');

        if ($resetToken === '' || $newPassword === '') json_error('البيانات ناقصة');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('recovery_reset', $ip, 5, 900);

        $err = pw_strength_check($newPassword);
        if ($err !== null) json_error($err);

        $tokenHash = hash('sha256', $resetToken);
        $stmt = db()->prepare(
            "SELECT prt.*, u.email, u.name
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash=? AND prt.consumed_at IS NULL
               AND prt.expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        if (!$row) {
            usleep(400000);
            json_error('رمز إعادة التعيين غير صالح أو منتهي', 401);
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')
                ->execute([$newHash, $row['user_id']]);
            $pdo->prepare('UPDATE password_reset_tokens SET consumed_at=NOW() WHERE id=?')
                ->execute([$row['id']]);
            // إلغاء كل التوكنز الأخرى لهذا المستخدم احتياطاً
            $pdo->prepare(
                "UPDATE password_reset_tokens SET consumed_at=NOW()
                 WHERE user_id=? AND consumed_at IS NULL"
            )->execute([$row['user_id']]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][Recovery] reset error: ' . $e->getMessage());
            json_error('فشل تحديث كلمة المرور', 500);
        }

        log_recovery($row['email'], true);
        audit('إعادة تعيين كلمة المرور', $row['email']);

        json_ok([
            'success' => true,
            'message' => 'تم تحديث كلمة المرور بنجاح. سجّل دخولك الآن.',
        ]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
