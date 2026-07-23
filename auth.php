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
            'SELECT id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1'
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

        audit('تسجيل دخول', $email);

        // ✅ تسجيل جلسة لوحة المدير الويب (لتتبّع last_seen في admin_sessions)
        if (($user['role'] ?? '') === 'مدير') {
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

        json_ok([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'    => (int)$user['id'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role'],
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
        $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $u = $stmt->fetch();
        if (!$u) json_error('المستخدم غير موجود', 404);
        json_ok(['success' => true, 'user' => $u]);
        break;

    case 'logout':
        // المصادقة عبر JWT — العميل يمسح التوكن محلياً
        json_ok(['success' => true]);
        break;

    default:
        json_error('إجراء غير معروف', 404);
}
