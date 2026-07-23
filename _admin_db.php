<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Admin Web Panel API
 * ملف الاتصال + المساعدات + المصادقة الخاصة بلوحة المدير الويب
 * ملاحظة: تم نشر هذا الملف في نفس مجلد الـ API الرئيسي (وليس في مجلد فرعي منفصل)
 * لذلك يستخدم require_once على _db.php مباشرة من نفس المجلد.
 * المهندس/ كامل عيسى كامل المغلس — 734375821
 * ─────────────────────────────────────────────────────────────────────────────
 */

// إعادة استخدام طبقة الاتصال الأصلية (نفس المجلد الآن بعد النشر الموحّد)
require_once __DIR__ . '/_db.php';

// ── CORS موسّع للوحة الويب (محلي فقط — CORS الأساسي في _db.php يغطي الإنتاج) ──
$webOrigins = [
    'http://localhost',
    'http://127.0.0.1',
    'http://localhost:8080',
    'http://127.0.0.1:8080',
    'http://localhost:5173',
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $webOrigins, true)) {
    // حذف الترويسة السابقة إن وُجدت لتجنب التكرار
    header_remove('Access-Control-Allow-Origin');
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
}

// ── مساعد: جلب الفرع المحدد من رأس الطلب أو من Query ─────────────────────────
function current_branch(): ?string {
    $b = $_GET['branch'] ?? ($_SERVER['HTTP_X_BRANCH_CODE'] ?? null);
    if ($b === null || $b === '' || strtolower($b) === 'all') return null;
    // قبول الحروف والأرقام والشرطة فقط
    if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $b)) return null;
    return $b;
}

// ── مساعد: تطبيق فلتر الفرع على شرط WHERE ────────────────────────────────────
function branch_filter(string $col = 'branch_code'): array {
    $b = current_branch();
    if ($b === null) return ['', []];
    // ✅ تحويل PostgreSQL: علامات backtick (MySQL) غير مدعومة — استُخدمت علامة اقتباس مزدوجة "" للـ identifier
    return [" AND \"$col\" = ?", [$b]];
}

// ── مساعد: تنسيق أرقام كبيرة بأمان ───────────────────────────────────────────
function num($v, int $dec = 2): float {
    return round((float)($v ?? 0), $dec);
}

// ── مساعد: التحقق من صلاحية المدير + تسجيل الجلسة ───────────────────────────
function require_admin_web(): array {
    $auth = require_admin();
    // تحديث آخر مشاهدة في جلسات المدير
    try {
        $token = bearer_token();
        if ($token) {
            $hash = hash('sha256', $token);
            db()->prepare(
                'UPDATE admin_sessions SET last_seen = NOW() WHERE token_hash = ? AND revoked = 0'
            )->execute([$hash]);
        }
    } catch (Exception $e) { /* تجاهل */ }
    return $auth;
}

// ── مساعد: إرجاع آخر يوم/أسبوع/شهر بصيغة Y-m-d ───────────────────────────────
function range_period(string $period): array {
    $today = new DateTime('today');
    switch ($period) {
        case 'today':
            return [$today->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        case 'yesterday':
            $y = (clone $today)->modify('-1 day');
            return [$y->format('Y-m-d 00:00:00'), $y->format('Y-m-d 23:59:59')];
        case 'week':
            $w = (clone $today)->modify('-6 days');
            return [$w->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        case 'month':
            $m = (clone $today)->modify('first day of this month');
            return [$m->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        case 'year':
            $yr = (clone $today)->modify('first day of January this year');
            return [$yr->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
        default:
            $m = (clone $today)->modify('-29 days');
            return [$m->format('Y-m-d 00:00:00'), $today->format('Y-m-d 23:59:59')];
    }
}
