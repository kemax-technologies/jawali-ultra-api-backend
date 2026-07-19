<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra 5.0 — XAMPP API
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
    'http://localhost:5500',   // لوحة الكاشير - Live Server
    'http://127.0.0.1:5500',  // لوحة الكاشير - Live Server
    'http://localhost:8080',   // لوحة الكاشير - بديل
    'http://127.0.0.1:8080',  // لوحة الكاشير - بديل
    'https://jawali-dev-panel.pages.dev', // لوحة تحكم المطوّر (Cloudflare Pages)
    'https://5061-ivf2f8x49hes7put1szwa-82b888ba.sandbox.novita.ai', // معاينة لوحة المطوّر داخل الـ sandbox الحالي
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
// ✅ السماح لأي subdomain فرعي لـ *.pages.dev الخاص بمشروع لوحة المطوّر (نسخ Preview)
$originHost = $origin !== '' ? (parse_url($origin, PHP_URL_HOST) ?? '') : '';
if ($originHost !== '' && str_ends_with($originHost, '.jawali-dev-panel.pages.dev')) {
    $allowedOrigins[] = $origin;
}
// ✅ السماح لأي نطاق فرعي من sandbox.novita.ai (بيئة التطوير الحالية) لمعاينة لوحة المطوّر
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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

function require_auth(): array {
    $payload = jwt_verify(bearer_token());
    if (!$payload) json_error('انتهت صلاحية الجلسة', 401);
    return $payload;
}

// ✅ مساعد جديد: التحقق من دور المدير
function require_admin(): array {
    $auth = require_auth();
    if (($auth['role'] ?? '') !== 'مدير') {
        json_error('غير مصرح — يتطلب صلاحية مدير', 403);
    }
    return $auth;
}

// ── سجل تدقيق ────────────────────────────────────────────────────────────────
function audit(string $action, ?string $email = null, string $level = 'info'): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO audit_log (action, user_email, ip_address, user_agent) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            "[$level] $action",
            $email ?? ($_SERVER['HTTP_X_USER_EMAIL'] ?? null),
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240),
        ]);
    } catch (Exception $e) { /* تجاهل أخطاء السجل */ }
}
