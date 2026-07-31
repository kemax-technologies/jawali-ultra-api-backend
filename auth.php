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
            'SELECT id, name, email, password_hash, role, is_active, permissions, tenant_id FROM users WHERE email = ? LIMIT 1'
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
        // ✅ إصلاح: تمرير tenant_id الخاص بالمستخدم (متوفر مسبقاً من نتيجة
        // الاستعلام أعلاه) لضمان ظهور حدث تسجيل الدخول في سجل تدقيق متجره.
        audit('تسجيل دخول (' . ($user['role'] ?? '') . ')', $email, 'info', (int)($user['tenant_id'] ?? 0) ?: null);

        // ✅ تم حذف admin_web بالكامل من النظام — لم يعد هناك أي تسجيل في
        // admin_sessions هنا. تتبّع الجلسات لكل الأدوار يتم فقط عبر
        // user_sessions (انظر log_user_session أعلاه).

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
                // ✅ Multi-Tenant: يُفيد الواجهة (Flutter) في عرض سياق "متجرك"
                'tenant_id'   => isset($user['tenant_id']) ? (int)$user['tenant_id'] : null,
            ],
        ]);
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
        $stmt = db()->prepare('SELECT id, name, email, role, branch_code, tenant_id FROM users WHERE id = ?');
        $stmt->execute([$payload['sub']]);
        $u = $stmt->fetch();
        if (!$u) json_error('المستخدم غير موجود', 404);
        $u['permissions'] = $payload['permissions'] ?? [];
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
