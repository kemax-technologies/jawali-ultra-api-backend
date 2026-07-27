<?php
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';

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
            'SELECT id, name, email, password_hash, role, is_active, permissions FROM users WHERE email = ? LIMIT 1'
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

        $token = jwt_create([
            'sub'   => (int)$user['id'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ]);

        // 🔐 تسجيل الجهاز/الجلسة لكل الأدوار (تعميم — لم يعد مقيّداً بـ 'مدير')
        log_user_session($email, (string)($user['role'] ?? ''), $token);
        audit('تسجيل دخول (' . ($user['role'] ?? '') . ')', $email);

        // ✅ إبقاء تسجيل admin_sessions القديم للمدير/المالك فقط (توافقية خلفية
        // مع لوحة الأدمن الويب التي لا تزال تقرأ من هذا الجدول تحديداً)
        if (($user['role'] ?? '') === 'مدير' || ($user['role'] ?? '') === 'المالك') {
            try {
                db()->prepare(
                    'INSERT INTO admin_sessions (user_email, token_hash, ip_address, user_agent, expires_at)
                     VALUES (?, ?, ?, ?, NOW() + INTERVAL \'7 days\')'
                )->execute([
                    $email,
                    hash('sha256', $token),
                    $ip,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240),
                ]);
            } catch (Exception $e) { /* الجدول قد لا يكون موجوداً بعد — تجاهل بأمان */ }
        }

        $permissions = effective_permissions((string)($user['role'] ?? ''), $user['permissions'] ?? null);

        json_ok([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'          => (int)$user['id'],
                'name'        => $user['name'],
                'email'       => $user['email'],
                'role'        => $user['role'],
                'permissions' => $permissions,
            ],
        ]);
        break;

    case 'register':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $body  = input_json();
        $name  = trim($body['name']  ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = (string)($body['password'] ?? '');
        // ✅ إصلاح #2: الدور الافتراضي ثابت دائماً — لا يُقبل role من المستخدم
        // إنشاء حسابات المدراء يتم فقط من لوحة الإدارة أو Seeder داخلي
        $role = 'كاشير';

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

        $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) json_error('البريد الإلكتروني مستخدم بالفعل', 409);

        // ✅ bcrypt cost=12 للأمان
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $ins  = db()->prepare(
            'INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$name, $email, $hash, $role]);

        audit('تسجيل مستخدم جديد', $email);
        json_ok(['success' => true, 'id' => (int)db()->lastInsertId()]);
        break;

    case 'me':
        $payload = require_auth();
        $stmt = db()->prepare('SELECT id, name, email, role, branch_code FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $u = $stmt->fetch();
        if (!$u) json_error('المستخدم غير موجود', 404);
        $u['permissions'] = $payload['permissions'] ?? [];
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

    // ✅ تأكيد كلمة مرور المدير/المالك — يُستخدم في العمليات الحساسة (حذف
    // فاتورة، خصم كبير...) دون الحاجة لتسجيل خروج المستخدم الحالي وتسجيل
    // دخول المدير بدلاً منه. يتطلب Authorization الحالي (أي مستخدم) + بيانات
    // مدير/مالك صريحة في الـ body.
    case 'confirm_admin':
        if ($method !== 'POST') json_error('استخدم POST', 405);
        require_auth();
        $body = input_json();
        $adminEmail = strtolower(trim($body['admin_email'] ?? ''));
        $adminPass  = (string)($body['admin_password'] ?? '');
        if ($adminEmail === '' || $adminPass === '') {
            json_error('بيانات المدير مطلوبة للتأكيد');
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('confirm_admin', $adminEmail . '|' . $ip, 5, 900);

        $stmt = db()->prepare(
            'SELECT id, role, password_hash, is_active FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$adminEmail]);
        $admin = $stmt->fetch();
        if (!$admin || !$admin['is_active'] ||
            !in_array($admin['role'], ['مدير', 'المالك'], true) ||
            !password_verify($adminPass, $admin['password_hash'] ?? '')
        ) {
            usleep(300000);
            audit('فشل تأكيد صلاحية مدير للعملية الحساسة', $adminEmail, 'warning');
            json_error('بيانات المدير غير صحيحة', 401);
        }
        rl_clear('confirm_admin', $adminEmail . '|' . $ip);
        audit('تأكيد صلاحية مدير لعملية حساسة', $adminEmail);
        json_ok(['success' => true]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
