<?php
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';
require_once __DIR__ . '/_totp.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── Rate Limiting: يعمل عبر _rate_limit.php (DB-based, JWT-compatible) ───

// ─── التحقق من قوة كلمة المرور ──────────────────────────────────────────────
function validate_password_strength(string $password): ?string {
    if (strlen($password) < 8)               return 'كلمة المرور يجب أن تكون 8 أحرف على الأقل';
    if (!preg_match('/[A-Z]/', $password))   return 'يجب أن تحتوي على حرف كبير واحد على الأقل';
    if (!preg_match('/[a-z]/', $password))   return 'يجب أن تحتوي على حرف صغير واحد على الأقل';
    if (!preg_match('/[0-9]/', $password))   return 'يجب أن تحتوي على رقم واحد على الأقل';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) return 'يجب أن تحتوي على رمز خاص (!@#$%^&*)';
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// ✅ Task 5 — التحقق من 2FA على مستوى الخادم (سابقاً كان محلياً 100% في
// Flutter فقط، بدون أي معرفة من الخادم — أي عميل يتجاوز واجهة التطبيق كان
// يحصل على JWT كامل الصلاحيات بعد كلمة المرور فقط، حتى لو كان 2FA "مفعَّلاً"
// على الحساب). الآن: يُصدر الخادم JWT الحقيقي فقط بعد نجاح كلمة المرور +
// (إن كان tfa_enabled=1) التحقق الفعلي من رمز TOTP الصحيح.
// ─────────────────────────────────────────────────────────────────────────────

// يُصدر جلسة دخول كاملة (JWT + تسجيل الجلسة + سجل التدقيق) — نقطة مشتركة
// يستخدمها كلا مساري الدخول: المباشر (بدون 2FA) والمكتمل بعد التحقق (verify_2fa)
function issue_full_session(array $user): array {
    $token = jwt_create([
        'sub'   => (int)$user['id'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ]);

    // 🔐 تسجيل الجهاز/الجلسة لكل الأدوار
    log_user_session($user['email'], (string)($user['role'] ?? ''), $token);
    audit('تسجيل دخول (' . ($user['role'] ?? '') . ')', $user['email'], 'info', (int)($user['tenant_id'] ?? 0) ?: null);

    $permissions = effective_permissions((string)($user['role'] ?? ''), $user['permissions'] ?? null);

    return [
        'success' => true,
        'token'   => $token,
        'user'    => [
            'id'          => (int)$user['id'],
            'name'        => $user['name'],
            'email'       => $user['email'],
            'role'        => $user['role'],
            'permissions' => $permissions,
            // ✅ Multi-Tenant: يُفيد الواجهة (Flutter) في عرض سياق "متجرك"
            'tenant_id'   => isset($user['tenant_id']) ? (int)$user['tenant_id'] : null,
            // ✅ Task 5: حالة 2FA الحقيقية من الخادم (مصدر الحقيقة الآن)
            'tfa_enabled' => !empty($user['tfa_enabled']),
        ],
    ];
}

// ✅ ملاحظة: create_tfa_pending_token() و tfa_gate() الآن مُعرَّفتان في
// _db.php (مشتركتان مع social_auth.php) — أُزيل التعريف المحلي المكرر هنا.

switch ($action) {
    case 'login':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = (string)($body['password'] ?? '');
        if ($email === '' || $pass === '') json_error('البريد وكلمة المرور مطلوبان');

        // ✅ إصلاح #6: الاعتماد على REMOTE_ADDR فقط — HTTP_X_FORWARDED_FOR قابل للتزوير
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('login', $email . '|' . $ip, 5, 900);

        $stmt = db()->prepare(
            'SELECT id, name, email, password_hash, role, is_active, permissions, tenant_id, tfa_enabled, tfa_secret
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            usleep(300000); // 300ms — تأخير موحّد لمنع timing attack
            json_error('بيانات الدخول غير صحيحة', 401);
        }

        if (!password_verify($pass, $user['password_hash'] ?? '')) {
            usleep(300000);
            json_error('بيانات الدخول غير صحيحة', 401);
        }

        // ✅ نجاح — مسح سجل المحاولات الفاشلة
        rl_clear('login', $email . '|' . $ip);

        // ✅ إعادة تجزئة كلمة المرور تلقائيًا إن احتاجت ترقية (bcrypt upgrade)
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([$newHash, $user['id']]);
        }

        // ✅ Task 5: التحقق الفعلي من 2FA على مستوى الخادم — كلمة المرور وحدها
        // لم تعد كافية لإصدار JWT كامل الصلاحيات إن كان tfa_enabled=1 لهذا
        // الحساب. لا يُصدر أي توكن حقيقي هنا؛ فقط "رمز دخول معلَّق" قصير الأجل
        // (5 دقائق) يجب استبداله برمز TOTP صحيح عبر action=verify_2fa.
        $tfaResp = tfa_gate($user);
        if ($tfaResp !== null) json_ok($tfaResp);

        // ✅ تم حذف admin_web بالكامل من النظام — لم يعد هناك أي تسجيل في
        // admin_sessions هنا. تتبّع الجلسات لكل الأدوار يتم فقط عبر
        // user_sessions (انظر issue_full_session أعلاه).
        json_ok(issue_full_session($user));
        break;

    // ✅ Task 5 — الخطوة الثانية من تسجيل الدخول عند تفعيل 2FA: يستبدل
    // "tfa_token" المعلَّق (من action=login) + رمز TOTP صحيح بجلسة كاملة
    // (JWT حقيقي). هذا هو الإجراء الوحيد الذي يقبل tfa_token — لا يُقبل في
    // أي مكان آخر في النظام (ليس JWT أصلاً، بل رمز عشوائي مخزَّن في
    // tfa_pending_logins ومحدود الصلاحية).
    case 'verify_2fa':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body      = input_json();
        $tfaToken  = trim((string)($body['tfa_token'] ?? ''));
        $code      = trim((string)($body['code'] ?? ''));
        if ($tfaToken === '' || $code === '') json_error('رمز الدخول المؤقت ورمز التحقق مطلوبان');

        $ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $tokenHash = hash('sha256', $tfaToken);

        // ✅ تحديد سقف محاولات تخمين الرمز — بالمفتاح (توكن+IP) بدلاً من
        // البريد لأن العميل هنا لا يُرسل البريد أصلاً في هذا الطلب
        rl_check('verify_2fa', $tokenHash . '|' . $ip, 5, 900);

        $pend = db()->prepare(
            'SELECT user_id FROM tfa_pending_logins WHERE token_hash = ? AND expires_at > NOW() LIMIT 1'
        );
        $pend->execute([$tokenHash]);
        $pendingRow = $pend->fetch();
        if (!$pendingRow) {
            json_error('انتهت صلاحية جلسة الدخول المؤقتة — سجّل الدخول مرة أخرى', 401);
        }

        $stmt = db()->prepare(
            'SELECT id, name, email, role, is_active, permissions, tenant_id, tfa_enabled, tfa_secret
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$pendingRow['user_id']]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active'] || empty($user['tfa_enabled']) || empty($user['tfa_secret'])) {
            json_error('لا يمكن إكمال هذا الطلب — تواصل مع الدعم', 401);
        }

        if (!totp_verify((string)$user['tfa_secret'], $code)) {
            audit('فشل التحقق من رمز 2FA عند الدخول', $user['email'], 'warning', (int)($user['tenant_id'] ?? 0) ?: null);
            json_error('رمز التحقق غير صحيح', 401);
        }

        // ✅ نجاح — الرمز صحيح، مسح المحاولات + استهلاك رمز الدخول المؤقت (لمرة واحدة فقط)
        rl_clear('verify_2fa', $tokenHash . '|' . $ip);
        db()->prepare('DELETE FROM tfa_pending_logins WHERE token_hash = ?')->execute([$tokenHash]);

        json_ok(issue_full_session($user));
        break;

    // ✅ Task 5 — بدء إعداد 2FA: يُنشئ الخادم سراً جديداً (لا يُخزَّن نهائياً
    // إلا بعد تأكيد المستخدم للرمز عبر action=setup_2fa_confirm) ويُعيده
    // للعميل مع رابط otpauth:// لعرضه كـ QR / إدخال يدوي.
    case 'setup_2fa_init':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth = require_auth();
        $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('setup_2fa_init', ($auth['email'] ?? '') . '|' . $ip, 10, 3600);

        $secret = totp_generate_secret();
        json_ok([
            'success'     => true,
            'secret'      => $secret,
            'otpauth_url' => totp_build_otpauth_url($secret, (string)($auth['email'] ?? '')),
        ]);
        break;

    // ✅ Task 5 — تأكيد إعداد 2FA: يتحقق من أن المستخدم أدخل السر بنجاح في
    // تطبيق مصادقة حقيقي (Google Authenticator/Authy...) عبر رمز صحيح، ثم
    // يُخزِّن السر في قاعدة البيانات ويُفعِّل tfa_enabled=1 — من هذه اللحظة
    // فقط يُصبح 2FA مفروضاً فعلياً على مستوى الخادم لهذا الحساب.
    case 'setup_2fa_confirm':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth   = require_auth();
        $body   = input_json();
        $secret = trim((string)($body['secret'] ?? ''));
        $code   = trim((string)($body['code'] ?? ''));
        if ($secret === '' || $code === '') json_error('السر ورمز التحقق مطلوبان');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('setup_2fa_confirm', ($auth['email'] ?? '') . '|' . $ip, 8, 900);

        // ✅ 400 (لا 401): الجلسة أصلاً صالحة (نجح require_auth أعلاه) — الخطأ هنا
        // هو رمز TOTP خاطئ فقط، وليس مشكلة توكن/جلسة. استخدام 401 هنا كان
        // سيُفعِّل معالج ApiClient.onSessionExpired العام في Flutter (المصمَّم
        // لانتهاء صلاحية الجلسة) ويُسجِّل خروج المستخدم فعلياً بالخطأ فقط لأنه
        // أخطأ كتابة رمز التحقق — وهي مستخدم نشط بجلسة سليمة تماماً.
        if (!totp_verify($secret, $code)) {
            json_error('رمز التحقق غير صحيح — تأكد من مطابقة الوقت في هاتفك', 400);
        }
        rl_clear('setup_2fa_confirm', ($auth['email'] ?? '') . '|' . $ip);

        db()->prepare(
            'UPDATE users SET tfa_enabled = 1, tfa_secret = ?, tfa_enabled_at = NOW() WHERE id = ?'
        )->execute([$secret, $auth['sub']]);

        audit('تفعيل المصادقة الثنائية (2FA) على مستوى الخادم', $auth['email'] ?? null, 'info', tenant_id_from_auth($auth));
        json_ok(['success' => true]);
        break;

    // ✅ Task 5 — تعطيل 2FA: يتطلب تأكيد كلمة المرور الحالية دفاعاً عن جلسة
    // مسروقة/جهاز مفتوح لا يُمكِّن أي شخص من تعطيل الحماية بمجرد الوصول
    // للتطبيق مفتوحاً دون معرفة كلمة المرور.
    case 'disable_2fa':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth = require_auth();
        $body = input_json();
        $pass = (string)($body['password'] ?? '');
        if ($pass === '') json_error('كلمة المرور الحالية مطلوبة لتعطيل المصادقة الثنائية');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('disable_2fa', ($auth['email'] ?? '') . '|' . $ip, 5, 900);

        $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$auth['sub']]);
        $row = $stmt->fetch();
        // ✅ 400 (لا 401) — لنفس السبب أعلاه في setup_2fa_confirm: الجلسة
        // صالحة، الخطأ فقط في كلمة المرور المُدخَلة لتأكيد التعطيل.
        if (!$row || !password_verify($pass, $row['password_hash'] ?? '')) {
            usleep(300000);
            json_error('كلمة المرور غير صحيحة', 400);
        }
        rl_clear('disable_2fa', ($auth['email'] ?? '') . '|' . $ip);

        db()->prepare(
            'UPDATE users SET tfa_enabled = 0, tfa_secret = NULL, tfa_enabled_at = NULL WHERE id = ?'
        )->execute([$auth['sub']]);

        audit('تعطيل المصادقة الثنائية (2FA)', $auth['email'] ?? null, 'warning', tenant_id_from_auth($auth));
        json_ok(['success' => true]);
        break;

    case 'register':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $name  = trim($body['name']  ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = (string)($body['password'] ?? '');
        $storeName = trim($body['store_name'] ?? '');
        if ($storeName === '') $storeName = 'متجر ' . ($name !== '' ? $name : $email);

        // ✅ Multi-Tenant: التطبيق يُنشر للعامة على متجر بلاي — كل من يسجّل حساباً
        // جديداً بنفسه يصبح تلقائياً صاحب متجر ("مدير") مستقل وخاص به بالكامل،
        // معزول تماماً عن بيانات كل المتاجر الأخرى. لا يوجد "كاشير" افتراضي هنا؛
        // صاحب المتجر نفسه هو من يُنشئ حسابات الكاشير/الموظفين من لوحة إدارة متجره.
        $role = 'مدير';

        if ($name === '' || $email === '' || $pass === '') {
            json_error('جميع الحقول مطلوبة');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error('صيغة البريد الإلكتروني غير صحيحة');
        }

        // ✅ إصلاح أمني: تحديد عدد محاولات إنشاء الحسابات لمنع إنشاء حسابات
        // مزيّفة/spam بشكل آلي (نفس نمط login، لكن بحد أعلى لأن هذا إجراء
        // مشروع يقوم به المستخدمون الجدد بشكل طبيعي)
        $regIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('register', $regIp, 10, 3600);

        // ✅ التحقق من قوة كلمة المرور عند التسجيل
        $pwError = validate_password_strength($pass);
        if ($pwError !== null) json_error($pwError);

        $pdo = db();
        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) json_error('البريد الإلكتروني مستخدم بالفعل', 409);

        // ✅ bcrypt cost=12 للأمان
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);

        // ✅ Multi-Tenant: إنشاء "متجر" جديد + مستخدم "مدير" مالك له ضمن معاملة
        // واحدة ذرّية. العلاقة بين الجدولين دائرية (tenants.owner_user_id ->
        // users.id، و users.tenant_id -> tenants.id)، فنحلّها بالتسلسل التالي:
        //   1) إنشاء tenant بدون owner_user_id (NULL مؤقتاً)
        //   2) إنشاء user مرتبطاً بـ tenant_id الجديد فوراً (يحقق NOT NULL)
        //   3) تحديث owner_user_id على tenant لإكمال الربط الدائري
        // أي فشل في أي خطوة => rollback كامل (لا يبقى متجر بلا مالك أو مستخدم
        // بلا متجر أبداً).
        $tenantId = null;
        $userId   = null;
        try {
            $pdo->beginTransaction();

            $tIns = $pdo->prepare('INSERT INTO tenants (name, plan) VALUES (?, ?) RETURNING id');
            $tIns->execute([$storeName, 'free']);
            $tenantId = (int)$tIns->fetchColumn();

            $uIns = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, tenant_id) VALUES (?, ?, ?, ?, ?) RETURNING id'
            );
            $uIns->execute([$name, $email, $hash, $role, $tenantId]);
            $userId = (int)$uIns->fetchColumn();

            $pdo->prepare('UPDATE tenants SET owner_user_id = ? WHERE id = ?')->execute([$userId, $tenantId]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[Jawali][register] فشل إنشاء متجر جديد للمستخدم ' . $email . ': ' . $e->getMessage());
            json_error('حدث خطأ أثناء إنشاء الحساب — حاول مرة أخرى', 500);
        }

        audit('تسجيل مستخدم جديد + إنشاء متجر مستقل', $email, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $userId, 'tenant_id' => $tenantId]);
        break;

    case 'me':
        $payload = require_auth();
        $stmt = db()->prepare('SELECT id, name, email, role, branch_code, tenant_id, tfa_enabled FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $u = $stmt->fetch();
        if (!$u) json_error('المستخدم غير موجود', 404);
        $u['permissions'] = $payload['permissions'] ?? [];
        // ✅ Task 5: تحويل smallint (0/1) القادم من PostgreSQL إلى bool صريح
        $u['tfa_enabled'] = !empty($u['tfa_enabled']);
        // ✅ Multi-Tenant: اسم المتجر مفيد لعرضه في واجهة Flutter
        try {
            $t = db()->prepare('SELECT name FROM tenants WHERE id = ?');
            $t->execute([$u['tenant_id']]);
            $u['tenant_name'] = $t->fetchColumn() ?: null;
        } catch (Exception $e) { $u['tenant_name'] = null; }
        json_ok(['success' => true, 'user' => $u]);
        break;

    case 'logout':
        // ✅ إنهاء الجلسة فعلياً في قاعدة البيانات (لا يكفي مسح التوكن محلياً
        // فقط) — بذلك يمكن تعطيل الجلسة فوراً من لوحة التحكم أيضاً لاحقاً.
        $token = bearer_token();
        if ($token) {
            try {
                db()->prepare('UPDATE user_sessions SET revoked = 1 WHERE token_hash = ?')
                    ->execute([hash('sha256', $token)]);
            } catch (Exception $e) { /* تجاهل بأمان */ }
        }
        json_ok(['success' => true]);
        break;

    // ✅ تأكيد كلمة مرور مدير المتجر — يُستخدم في العمليات الحساسة (حذف
    // فاتورة، خصم كبير...) دون الحاجة لتسجيل خروج المستخدم الحالي وتسجيل
    // دخول المدير بدلاً منه. يتطلب Authorization الحالي (أي مستخدم) + بيانات
    // مدير صريحة في الـ body.
    // ✅ Multi-Tenant: المدير المؤكِّد يجب أن يكون من نفس متجر (tenant) الطالب
    // نفسه — يمنع تأكيد عملية حساسة في متجر باستخدام حساب مدير متجر آخر تماماً.
    case 'confirm_admin':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth = require_auth();
        $requesterTenantId = tenant_id_from_auth($auth);
        $body = input_json();
        $adminEmail = strtolower(trim($body['admin_email'] ?? ''));
        $adminPass  = (string)($body['admin_password'] ?? '');
        if ($adminEmail === '' || $adminPass === '') {
            json_error('بيانات المدير مطلوبة للتأكيد');
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('confirm_admin', $adminEmail . '|' . $ip, 5, 900);

        $stmt = db()->prepare(
            'SELECT id, role, password_hash, is_active, tenant_id FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$adminEmail]);
        $admin = $stmt->fetch();
        if (!$admin || !$admin['is_active'] ||
            $admin['role'] !== 'مدير' ||
            (int)($admin['tenant_id'] ?? -1) !== $requesterTenantId ||
            !password_verify($adminPass, $admin['password_hash'] ?? '')
        ) {
            usleep(300000);
            audit('فشل تأكيد صلاحية مدير للعملية الحساسة', $adminEmail, 'warning', $requesterTenantId);
            json_error('بيانات المدير غير صحيحة', 401);
        }
        rl_clear('confirm_admin', $adminEmail . '|' . $ip);
        audit('تأكيد صلاحية مدير لعملية حساسة', $adminEmail, 'info', $requesterTenantId);
        json_ok(['success' => true]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
