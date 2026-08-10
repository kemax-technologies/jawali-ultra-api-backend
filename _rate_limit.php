<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Rate Limiting بـ DB — يعمل مع JWT stateless (بديل $_SESSION)
 *
 * لماذا؟ التطبيق يستخدم JWT (بدون session cookies)، لذا كل طلب من Flutter
 * يبدأ session جديدة في PHP، مما يجعل rate limit المعتمد على $_SESSION
 * عديم الجدوى فعلياً.
 *
 * الحل: تخزين العدّاد في جدول rate_limits المفهرس بـ (bucket, ip).
 * ─────────────────────────────────────────────────────────────────────────────
 */

function rl_ensure_table(): void {
    static $done = false;
    if ($done) return;
    // ✅ تحويل PostgreSQL: DATETIME→TIMESTAMP، فهرس منفصل بدل INDEX داخل CREATE TABLE، إزالة ENGINE/CHARSET (خاصة بـ MySQL)
    db()->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            bucket      VARCHAR(80)  NOT NULL,
            client_key  VARCHAR(120) NOT NULL,
            attempts    INT          NOT NULL DEFAULT 0,
            window_start TIMESTAMP   NOT NULL,
            PRIMARY KEY (bucket, client_key)
        );
        CREATE INDEX IF NOT EXISTS idx_window ON rate_limits (window_start);
    ");
    $done = true;
}

/**
 * فرض حد أعلى لعدد المحاولات.
 * @param string $bucket   اسم الميزة (login, otp_verify, reset, ...)
 * @param string $key      المُعرِّف (عادةً email|ip أو phone|ip)
 * @param int    $max      أقصى عدد محاولات
 * @param int    $windowSec نافذة الوقت بالثواني
 *
 * يستدعي json_error(429) إذا تجاوز.
 *
 * ✅ مراجعة أداء عميقة (طلب المستخدم — تسريع 2FA/الدخول): كانت هذه الدالة
 * تنفّذ SELECT ثم UPDATE أو INSERT بشكل منفصل — دوماً round-trip واحد
 * لقراءة الحالة + round-trip ثانٍ للكتابة (أي DB latency مضاعف على كل طلب
 * دخول أو تحقق 2FA). استُبدلت بعملية UPSERT ذرّية واحدة (INSERT ... ON
 * CONFLICT DO UPDATE) تُعيد العدّاد الفعلي مباشرة عبر RETURNING — تُنجز نفس
 * المنطق تماماً (تصفير عند انتهاء النافذة، زيادة العدّاد غير ذلك) في
 * round-trip واحد فقط بدل اثنين، وبأمان تزامني أفضل (بدون فجوة race
 * بين SELECT والكتابة تحت حمل عالٍ).
 */
function rl_check(string $bucket, string $key, int $max = 5, int $windowSec = 900): void {
    rl_ensure_table();
    $pdo = db();
    $now = time();

    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (bucket, client_key, attempts, window_start)
         VALUES (?, ?, 1, to_timestamp(?))
         ON CONFLICT (bucket, client_key) DO UPDATE SET
             attempts = CASE
                 WHEN EXTRACT(EPOCH FROM (to_timestamp(?) - rate_limits.window_start)) >= ?
                     THEN 1
                     ELSE rate_limits.attempts + 1
             END,
             window_start = CASE
                 WHEN EXTRACT(EPOCH FROM (to_timestamp(?) - rate_limits.window_start)) >= ?
                     THEN to_timestamp(?)
                     ELSE rate_limits.window_start
             END
         RETURNING attempts, EXTRACT(EPOCH FROM window_start) AS w'
    );
    $stmt->execute([$bucket, $key, $now, $now, $windowSec, $now, $windowSec, $now]);
    $row = $stmt->fetch();

    // fallback نظري بحت (لا يجب أن يحدث أبداً مع RETURNING) — لا نمنع الطلب
    if (!$row) return;

    $attempts = (int)$row['attempts'];
    $windowStart = (int)$row['w'];
    if ($attempts > $max) {
        $elapsed = $now - $windowStart;
        $retry = max(1, $windowSec - $elapsed);
        header('Retry-After: ' . $retry);
        json_error(
            'عدد المحاولات تجاوز الحد. حاول بعد ' . ceil($retry / 60) . ' دقيقة.',
            429
        );
    }
}

/**
 * مسح عدّاد بعد نجاح العملية (مثل تسجيل دخول ناجح).
 */
function rl_clear(string $bucket, string $key): void {
    try {
        db()->prepare('DELETE FROM rate_limits WHERE bucket = ? AND client_key = ?')
            ->execute([$bucket, $key]);
    } catch (Exception $e) { /* تجاهل */ }
}

/**
 * تنظيف السجلات القديمة (يمكن استدعاؤها من cron).
 */
function rl_gc(int $olderThanSec = 3600): void {
    try {
        db()->prepare(
            'DELETE FROM rate_limits WHERE window_start < to_timestamp(? - ?)'
        )->execute([time(), $olderThanSec]);
    } catch (Exception $e) { /* تجاهل */ }
}
