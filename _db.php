<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Backend API (PHP + PostgreSQL/Supabase)
 * ملف الاتصال بقاعدة البيانات + المساعدات المشتركة
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── تحميل متغيرات البيئة من ملف .env (إذا وُجد) ──────────────────────────────
(function () {
    $envFile = __DIR__ . '/.env';
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if ($key !== '' && getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
})();

// ── إعدادات الاتصال بـ Supabase (PostgreSQL) — عدّل عند الحاجة ─────────────────
// 💡 احصل على هذه القيم من: Supabase Dashboard → Project Settings → Database
//    - DB_HOST: مثل db.xxxxxxxxxxxx.supabase.co (اتصال مباشر)
//               أو aws-0-xxxx.pooler.supabase.com (Connection Pooler، مُفضَّل للخوادم المشتركة)
//    - DB_PORT: 5432 للاتصال المباشر (Session mode) — الأنسب لـ PHP/PDO التقليدي
//               أو 6543 لِـ Transaction pooler (لا يدعم بعض ميزات PDO مثل prepared
//               statements المستمرة عبر الطلبات، لذا 5432 هو الموصى به هنا)
//    - DB_SSLMODE: 'require' إلزامي تقريبًا مع Supabase (اتصال مشفّر عبر SSL/TLS)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 5432));
define('DB_NAME', getenv('DB_NAME') ?: 'postgres');
define('DB_USER', getenv('DB_USER') ?: 'postgres');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_SSLMODE', getenv('DB_SSLMODE') ?: 'require');

// ✅ إصلاح #16 (مُحسَّن): JWT_SECRET إلزامي — لا قيمة احتياطية عشوائية
(function () {
    $secret = getenv('JWT_SECRET');
    if ($secret === false || $secret === '') {
        // رسالة آمنة للعميل — لا تكشف السبب الحقيقي
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => false, 'message' => 'خطأ داخلي في الخادم'],
            JSON_UNESCAPED_UNICODE
        );
        // تسجيل السبب الحقيقي في سجل الخادم فقط
        error_log('[Jawali] FATAL: متغير البيئة JWT_SECRET غير مضبوط. أوقف التطبيق.');
        exit;
    }
    define('JWT_SECRET', $secret);
})();
define('JWT_EXPIRE', 60 * 60 * 24 * 7); // 7 أيام

// ── إعدادات الأمان ────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');

// ✅ إصلاح #10: CORS مقيّد بدلاً من Access-Control-Allow-Origin: *
$allowedOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://10.0.2.2',         // محاكي Android
    'capacitor://localhost',   // تطبيق Capacitor
    'ionic://localhost',       // تطبيق Ionic
    'http://localhost:5500',   // تطوير محلي - Live Server
    'http://127.0.0.1:5500',  // تطوير محلي - Live Server
    'http://localhost:8080',   // تطوير محلي - بديل
    'http://127.0.0.1:8080',  // تطوير محلي - بديل
    'https://jawali-dev-panel.pages.dev', // لوحة تحكم المطوّر (Cloudflare Pages)
    'https://jawali-ultra.pages.dev', // ✅ نسخة الويب الكاملة المطابقة لتطبيق APK (الاسم الرسمي)
    'https://5061-ivf2f8x49hes7put1szwa-82b888ba.sandbox.novita.ai', // معاينة لوحة المطوّر داخل الـ sandbox الحالي
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// ✅ السماح لأي subdomain فرعي لـ *.pages.dev الخاص بمشاريع لوحات الويب (نسخ Preview)
$originHost = $origin !== '' ? (parse_url($origin, PHP_URL_HOST) ?? '') : '';
if ($originHost !== '' && str_ends_with($originHost, '.jawali-dev-panel.pages.dev')) {
    $allowedOrigins[] = $origin;
}
if ($originHost !== '' && str_ends_with($originHost, '.jawali-ultra.pages.dev')) {
    $allowedOrigins[] = $origin;
}
// ✅ السماح لأي نطاق فرعي من sandbox.novita.ai (بيئة التطوير الحالية) لمعاينة لوحات الويب
if ($originHost !== '' && str_ends_with($originHost, '.sandbox.novita.ai')) {
    $allowedOrigins[] = $origin;
}
if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
} elseif ($origin === '') {
    // ✅ إصلاح: طلب من تطبيق native (Flutter/Android/iOS) — لا يوجد Origin
    // لا نرُد Access-Control-Allow-Origin إطلاقاً: التطبيقات native لا تتأثر بـ CORS
    // وهذا يمنع CSRF من حقن طلبات من مواقع خارجية تتظاهر بالطلب المباشر
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// ✅ Task 11: ($_SERVER['REQUEST_METHOD'] ?? '') — يمنع تحذير "Undefined array
// key" عند تشغيل هذا الملف من سطر الأوامر (CLI)، مثل migrate.php، حيث لا
// يُعرَّف REQUEST_METHOD إطلاقاً (لا يوجد طلب HTTP). لا يُغيّر أي سلوك HTTP.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

mb_internal_encoding('UTF-8');

// ── دالة الاتصال PDO (PostgreSQL / Supabase) ──────────────────────────────────
// ✅ تم تحويل الاتصال من mysql:... (pdo_mysql) إلى pgsql:... (pdo_pgsql)
//    ملاحظات التحويل:
//    - لا يوجد "charset=utf8mb4" في DSN الخاص بـ Postgres — الترميز UTF-8
//      هو الافتراضي في قواعد بيانات Supabase، ولا حاجة لأي إعداد إضافي.
//    - أُضيف "sslmode=require" لأن Supabase يتطلب اتصالاً مشفّرًا بشكل شبه دائم.
//    - أُزيل استدعاء "SET NAMES utf8mb4 ..." لأنه أمر MySQL خاص، لا مكافئ له
//      ولا حاجة له في PostgreSQL.
//    - يتطلب تفعيل امتداد pdo_pgsql في PHP (extension=pdo_pgsql في php.ini)
//      بدلاً من pdo_mysql. تحقق بالأمر: php -m | grep pgsql
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';sslmode=' . DB_SSLMODE;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Exception $e) {
        // ✅ إصلاح #7: لا نكشف تفاصيل الاتصال للمستخدم
        error_log('[Jawali][db] فشل الاتصال بقاعدة البيانات: ' . $e->getMessage());
        json_error('خطأ داخلي في الخادم', 500);
    }
    return $pdo;
}

// ── مساعدات JSON ──────────────────────────────────────────────────────────────
function json_ok($data = []): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function input_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── JWT بسيط (HS256) ─────────────────────────────────────────────────────────
function b64url_encode(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}
function b64url_decode(string $s): string {
    $remainder = strlen($s) % 4;
    if ($remainder) $s .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($s, '-_', '+/'));
}

function jwt_create(array $payload): string {
    $header = ['typ' => 'JWT', 'alg' => 'HS256'];
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRE;
    $h = b64url_encode(json_encode($header,  JSON_UNESCAPED_UNICODE));
    $p = b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = hash_hmac('sha256', "$h.$p", JWT_SECRET, true);
    return "$h.$p." . b64url_encode($sig);
}

function jwt_verify(?string $token): ?array {
    if (!$token) return null;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $sig] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($payload)) return null;
    if (($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

// ── استخراج التوكن من الترويسة ───────────────────────────────────────────────
function bearer_token(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) return substr($hdr, 7);
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 🛂 نظام الأدوار التسعة + الصلاحيات الدقيقة (RBAC)
// ─────────────────────────────────────────────────────────────────────────────
// كل الأدوار المدعومة في النظام (المفاتيح تُخزَّن بنفس النص العربي في عمود
// users.role — لا حاجة لترحيل الحسابات القديمة: "مدير"/"كاشير"/"موظف" تبقى
// كما هي وتُعامَل كأدوار صالحة ضمن هذه القائمة الموسّعة).
// ✅ تحديث بنية SaaS متعدد المتاجر: أُزيل دور "المالك" من هذا النظام —
// "مالك التطبيق" (صاحب منصة Jawali Ultra كاملة) أصبح يُدار حصراً عبر لوحة
// المطوّر المستقلة (dev_panel + _dev_db.php)، وليس عبر جدول users/الأدوار
// العادية. كل حساب "مدير" هنا هو الآن صاحب متجر (Tenant) مستقل يدير متجره
// فقط، ولا صلة له بإدارة المنصة أو المتاجر الأخرى.
define('APP_ROLES', [
    'مدير', 'محاسب', 'أمين مخزن', 'كاشير',
    'موظف مبيعات', 'مراقب', 'مدير فرع', 'خدمة عملاء',
    'موظف', // دور قديم عام — يُعامَل كصلاحيات محدودة شبيهة بموظف المبيعات
]);

// جميع مفاتيح الصلاحيات الدقيقة القابلة للتفعيل/الإلغاء لكل مستخدم
define('APP_PERMISSIONS', [
    'sell', 'purchase', 'returns', 'discounts', 'editPrice', 'deleteInvoice',
    'cancelInvoice', 'openDrawer', 'manageInventory', 'printBarcode',
    'editProducts', 'reports', 'financialReports', 'profits', 'settings',
    'manageUsers', 'backup', 'activityLog', 'manageBranches', 'manageTaxes',
    'manageCurrencies', 'managePaymentMethods', 'manageOffers',
    'approveSensitive', 'deleteSystem', 'manageLicense',
]);

// الصلاحيات الافتراضية لكل دور — تُطابق تماماً مصفوفة RolePermissions في
// Flutter (lib/config/permissions.dart) — أي تعديل هنا يجب أن يُقابله تعديل هناك.
function role_default_permissions(string $role): array {
    $all = APP_PERMISSIONS;
    $none = [];
    switch ($role) {
        case 'مدير':
            // "مدير" = صاحب المتجر (Tenant Owner) — كل صلاحيات إدارة متجره،
            // باستثناء صلاحيات مستوى المنصة (deleteSystem/manageLicense) التي
            // تبقى حصراً لمالك التطبيق عبر لوحة المطوّر.
            return array_values(array_diff($all, ['deleteSystem', 'manageLicense']));
        case 'محاسب':
            return ['reports', 'financialReports', 'profits', 'activityLog', 'approveSensitive'];
        case 'أمين مخزن':
            return ['manageInventory', 'editProducts', 'printBarcode', 'purchase'];
        case 'كاشير':
            return ['sell', 'openDrawer', 'discounts'];
        case 'موظف مبيعات':
            return ['sell', 'discounts', 'reports'];
        case 'مراقب':
            return ['reports', 'financialReports', 'approveSensitive', 'discounts', 'returns'];
        case 'مدير فرع':
            return array_values(array_diff($all, [
                'deleteSystem', 'manageLicense', 'manageBranches', 'backup',
            ]));
        case 'خدمة عملاء':
            return ['returns', 'reports'];
        case 'موظف':
            return ['sell', 'reports'];
        default:
            return $none;
    }
}

// دمج صلاحيات الدور الافتراضية مع تخصيصات المستخدم (JSONB) — أي مفتاح موجود
// صريحاً في overrides (true/false) يتفوّق على الافتراضي؛ باقي المفاتيح تبقى
// كما هي في افتراضي الدور.
function effective_permissions(string $role, $permissionsJson): array {
    $defaults = role_default_permissions($role);
    $effective = array_fill_keys($defaults, true);
    foreach (APP_PERMISSIONS as $key) {
        if (!isset($effective[$key])) $effective[$key] = false;
    }
    $overrides = [];
    if (is_string($permissionsJson) && $permissionsJson !== '') {
        $decoded = json_decode($permissionsJson, true);
        if (is_array($decoded)) $overrides = $decoded;
    } elseif (is_array($permissionsJson)) {
        $overrides = $permissionsJson;
    }
    foreach ($overrides as $key => $val) {
        if (in_array($key, APP_PERMISSIONS, true)) {
            $effective[$key] = (bool)$val;
        }
    }
    return array_keys(array_filter($effective));
}

// ── مدة انتهاء الجلسة لعدم النشاط (بالدقائق) — قابلة للتهيئة عبر .env ─────────
define('SESSION_IDLE_TIMEOUT_MIN', (int)(getenv('SESSION_IDLE_TIMEOUT_MIN') ?: 30));

// ── تسجيل جلسة دخول جديدة لأي دور (تعميم admin_sessions القديم) ─────────────
// ✅ Task 6 — device_info أصبح يقبل تسمية صديقة للجهاز يُرسلها تطبيق Flutter
// نفسه (مثل "Android" أو "iOS" أو "متصفح ويب") بدل الاعتماد فقط على
// HTTP_User-Agent الخام (غالباً غير مفيد لعميل HTTP في Dart، مثل
// "Dart/3.9 (dart:io)")، لتظهر شاشة "الجلسات النشطة" بشكل مفيد للمستخدم.
function log_user_session(string $email, string $role, string $token, ?string $deviceLabel = null): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240);
        $device = $deviceLabel !== null && trim($deviceLabel) !== ''
            ? substr(trim($deviceLabel), 0, 240)
            : ($ua !== '' ? $ua : 'جهاز غير معروف');
        db()->prepare(
            'INSERT INTO user_sessions (user_email, role, token_hash, device_info, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW() + INTERVAL \'7 days\')'
        )->execute([$email, $role, hash('sha256', $token), $device, $ip, $ua]);
    } catch (Exception $e) { /* تجاهل بأمان — لا نمنع تسجيل الدخول لهذا الخطأ */ }
}

// ✅ إعادة بناء require_auth: يتحقق من JWT + الجلسة الفعلية (لدعم التعطيل
// الفوري وانتهاء الجلسة عند الخمول) — يُعيد payload مُغنّى بالدور/الحالة/
// الصلاحيات الفعلية المُحدَّثة من قاعدة البيانات مباشرة (وليس من التوكن القديم).
function require_auth(): array {
    $payload = jwt_verify(bearer_token());
    if (!$payload) json_error('انتهت صلاحية الجلسة', 401);

    $token = bearer_token();
    $hash  = hash('sha256', (string)$token);

    // ✅ Multi-Tenant: تحديد tenant_id إلزامي دائماً من قاعدة البيانات الحيّة —
    // لا يُسمح مطلقاً بأي مسار احتياطي (fallback) يُكمِّل الطلب بدون tenant_id
    // محقَّق، لأن ذلك قد يُسرِّب بيانات متجر لآخر. أي فشل في جلبه = رفض الطلب.
    try {
        $pdo = db();
        // تحقق فوري من حالة المستخدم (تعطيل فوري) + جلب الدور/الصلاحيات/tenant_id
        $u = $pdo->prepare('SELECT role, is_active, permissions, branch_code, tenant_id FROM users WHERE email = ? LIMIT 1');
        $u->execute([$payload['email'] ?? '']);
        $user = $u->fetch();
        if (!$user || !$user['is_active']) {
            json_error('تم تعطيل هذا الحساب أو حذفه', 401);
        }
        if (!isset($user['tenant_id']) || $user['tenant_id'] === null) {
            // ✅ حالة غير طبيعية إطلاقاً بعد الترحيل (migration) — رفض صارم
            // بدلاً من افتراض أي متجر افتراضي (يمنع أي تسريب بيانات محتمل).
            error_log('[Jawali][require_auth] خطأ حرج: مستخدم بلا tenant_id — email=' . ($payload['email'] ?? ''));
            json_error('خطأ في تهيئة الحساب — تواصل مع الدعم', 500);
        }

        // ✅ Multi-Tenant: تحقق من حالة المتجر (Tenant) نفسه — لوحة المطوّر
        // قادرة على تعليق/حظر متجر كامل (tenants.is_active = 0) عند الحاجة
        // (مثل مخالفة الشروط أو تجاوز الحد المجاني)، فيُحظر كل مستخدمي ذلك
        // المتجر فوراً بغض النظر عن حالة حسابهم الفردي (is_active).
        $t = $pdo->prepare('SELECT is_active FROM tenants WHERE id = ? LIMIT 1');
        $t->execute([(int)$user['tenant_id']]);
        $tenantActive = $t->fetchColumn();
        if ($tenantActive === false || !(int)$tenantActive) {
            json_error('تم تعليق هذا المتجر — يرجى التواصل مع الدعم', 403);
        }

        // تحقق من الجلسة نفسها (خمول / إلغاء) — إن لم يُعثر على سجل جلسة (حسابات
        // قديمة قبل التفعيل) نتجاوز بأمان دون رفض الطلب.
        $s = $pdo->prepare(
            'SELECT id, last_seen, revoked FROM user_sessions WHERE token_hash = ? LIMIT 1'
        );
        $s->execute([$hash]);
        $session = $s->fetch();
        if ($session) {
            if ((int)$session['revoked'] === 1) {
                json_error('تم إنهاء هذه الجلسة — سجّل الدخول مرة أخرى', 401);
            }
            $idleSeconds = SESSION_IDLE_TIMEOUT_MIN * 60;
            $lastSeen = strtotime($session['last_seen'] . ' UTC');
            if ($lastSeen !== false && (time() - $lastSeen) > $idleSeconds) {
                $pdo->prepare('UPDATE user_sessions SET revoked = 1 WHERE id = ?')->execute([$session['id']]);
                json_error('انتهت الجلسة لعدم النشاط — سجّل الدخول مرة أخرى', 401);
            }
            $pdo->prepare('UPDATE user_sessions SET last_seen = NOW() WHERE id = ?')->execute([$session['id']]);
        }

        // 🔄 الدور/الصلاحيات/tenant_id دائماً من قاعدة البيانات الحيّة (لا من
        // التوكن) — بذلك يسري أي تغيير فوراً بدون انتظار تسجيل دخول جديد.
        $payload['role']        = $user['role'];
        $payload['branch_code'] = $user['branch_code'];
        $payload['tenant_id']   = (int)$user['tenant_id'];
        $payload['permissions'] = effective_permissions($user['role'], $user['permissions']);
    } catch (Exception $e) {
        // ✅ Multi-Tenant: فشل الاتصال بقاعدة البيانات هنا يعني عدم إمكانية
        // تحديد tenant_id بثقة — لا يجوز إكمال الطلب على التوكن القديم فقط
        // (قد يحمل بيانات دور/صلاحيات قديمة غير محدَّثة). نرفض الطلب بأمان.
        error_log('[Jawali][require_auth] خطأ: ' . $e->getMessage());
        json_error('خطأ داخلي في الخادم', 500);
    }

    return $payload;
}

// ✅ التحقق من دور المدير (صاحب المتجر — أعلى دور ضمن المتجر الواحد)
function require_admin(): array {
    $auth = require_auth();
    $role = $auth['role'] ?? '';
    if ($role !== 'مدير') {
        json_error('غير مصرح — يتطلب صلاحية مدير', 403);
    }
    return $auth;
}
// ملاحظة: لا يوجد "require_owner" على مستوى تطبيق المتجر بعد الآن — العمليات
// الحساسة جداً (حذف النظام، الترخيص، إدارة كل المتاجر) تُنفَّذ فقط من لوحة
// المطوّر المستقلة (dev_*.php + dev_require_auth) وليس من هذا الملف.

// ✅ التحقق من دور محدد ضمن قائمة مسموحة
function require_role(array $allowedRoles): array {
    $auth = require_auth();
    if (!in_array($auth['role'] ?? '', $allowedRoles, true)) {
        json_error('غير مصرح — دورك الحالي لا يملك هذه الصلاحية', 403);
    }
    return $auth;
}

// ✅ التحقق من صلاحية دقيقة محدّدة (permission-based) بدلاً من الدور فقط
function require_permission(string $permission): array {
    $auth = require_auth();
    $perms = $auth['permissions'] ?? [];
    if (!in_array($permission, $perms, true)) {
        json_error('غير مصرح — تحتاج صلاحية "' . $permission . '"', 403);
    }
    return $auth;
}

// ✅ فحص صلاحية دقيقة من مصفوفة $auth مُستخرجة مسبقاً (بدون إعادة استدعاء
// require_auth() وإعادة الاستعلام من قاعدة البيانات). يُستخدم في الملفات التي
// تحتاج صلاحيات مختلفة لكل action/type ضمن نفس الطلب (مثل reports.php) حيث
// require_auth() نُفِّذ مرة واحدة بالفعل في أعلى الملف.
function ensure_permission(array $auth, string $permission): void {
    $perms = $auth['permissions'] ?? [];
    if (!in_array($permission, $perms, true)) {
        json_error('غير مصرح — تحتاج صلاحية "' . $permission . '"', 403);
    }
}

// ── سجل تدقيق ────────────────────────────────────────────────────────────────
// ✅ Multi-Tenant: $tenantId اختياري — يُمرَّر من الملف المستدعي عند توفره
// (عادة من require_auth()['tenant_id']) لربط كل حدث بمتجره بدقة.
function audit(string $action, ?string $email = null, string $level = 'info', ?int $tenantId = null): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (action, user_email, ip_address, user_agent, tenant_id) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            "[$level] $action",
            $email ?? ($_SERVER['HTTP_X_USER_EMAIL'] ?? null),
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240),
            $tenantId,
        ]);
    } catch (Exception $e) { /* تجاهل أخطاء السجل */ }
}

// ─────────────────────────────────────────────────────────────────────────────
// ✅ Task 5 — بوّابة 2FA على مستوى الخادم، مشتركة بين auth.php (دخول محلي
// بالبريد/كلمة المرور) و social_auth.php (Google/Apple/الهاتف). قبل هذا
// التوحيد، كان تسجيل الدخول الاجتماعي يُصدر JWT كامل الصلاحيات مباشرة بعد
// نجاح مزوّد Google/Apple/OTP فقط، متجاهلاً تماماً أن الحساب قد يكون فعَّل
// 2FA مسبقاً عبر تسجيل الدخول بكلمة المرور — أي ثغرة تجاوز كاملة لحماية 2FA
// عبر تسجيل الدخول الاجتماعي. الآن: أي مسار دخول (محلي أو اجتماعي) يُستدعي
// نفس هذه البوّابة قبل إصدار أي جلسة حقيقية.
// ─────────────────────────────────────────────────────────────────────────────

// ينشئ سجل "دخول معلَّق" بانتظار رمز 2FA — رمز عشوائي (32 بايت) صالح 5 دقائق
// فقط، ولا صلاحية له مطلقاً لاستدعاء أي endpoint آخر (ليس JWT، لا يُقبل في
// require_auth()) — الاستخدام الوحيد الممكن له هو auth.php?action=verify_2fa.
function create_tfa_pending_token(int $userId): string {
    // تنظيف السجلات المنتهية بشكل عرضي (best-effort) — الجدول صغير جداً
    try { db()->exec('DELETE FROM tfa_pending_logins WHERE expires_at < NOW()'); } catch (Exception $e) { /* تجاهل */ }

    $raw  = bin2hex(random_bytes(32));
    $hash = hash('sha256', $raw);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    db()->prepare(
        'INSERT INTO tfa_pending_logins (user_id, token_hash, ip_address, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL \'5 minutes\')'
    )->execute([$userId, $hash, $ip]);
    return $raw;
}

// بوّابة موحّدة: تُعيد null إن كان 2FA غير مفعَّل لهذا الحساب (يُكمل المستدعي
// إصدار الجلسة الكاملة كالمعتاد)، أو Map جاهزة لإرسالها مباشرة عبر json_ok()
// إن كان 2FA مفعَّلاً (tfa_required=true + رمز دخول معلَّق) — بغضّ النظر عن
// طريقة الدخول الأولى (كلمة مرور / Google / Apple / هاتف).
function tfa_gate(array $user): ?array {
    if (empty($user['tfa_enabled'])) return null;
    $pendingToken = create_tfa_pending_token((int)$user['id']);
    audit(
        'تسجيل دخول (بيانات صحيحة) — بانتظار رمز 2FA',
        $user['email'] ?? null,
        'info',
        (int)($user['tenant_id'] ?? 0) ?: null
    );
    return [
        'success'      => true,
        'tfa_required' => true,
        'tfa_token'    => $pendingToken,
        'message'      => 'يتطلب هذا الحساب رمز المصادقة الثنائية',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// ✅ Multi-Tenant Helper: يُستخدم في كل ملف API لضمان تصفية كل استعلام
// بـ tenant_id الخاص بالمستخدم المسجّل دخوله حالياً — يمنع تسريب بيانات بين
// المتاجر. يُستدعى بعد require_auth()/require_admin()/... مباشرة.
// الاستخدام: $tenantId = tenant_id_from_auth($auth);
// ثم: 'SELECT * FROM products WHERE tenant_id = ? AND sku = ?' [$tenantId, $sku]
// ─────────────────────────────────────────────────────────────────────────────
function tenant_id_from_auth(array $auth): int {
    $tid = $auth['tenant_id'] ?? null;
    if ($tid === null) {
        // لا يجب أن يحدث هذا أبداً بعد require_auth() — أمان إضافي فقط
        error_log('[Jawali][tenant_id_from_auth] خطأ حرج: tenant_id غير موجود في auth payload');
        json_error('خطأ في تهيئة الحساب — تواصل مع الدعم', 500);
    }
    return (int)$tid;
}
