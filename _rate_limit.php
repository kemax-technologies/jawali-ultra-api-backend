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
 */
function rl_check(string $bucket, string $key, int $max = 5, int $windowSec = 900): void {
    rl_ensure_table();
    $pdo = db();

    // اجلب السجل الحالي
    // ✅ تحويل PostgreSQL: UNIX_TIMESTAMP() (MySQL) → EXTRACT(EPOCH FROM ...)
    $stmt = $pdo->prepare(
        'SELECT attempts, EXTRACT(EPOCH FROM window_start) AS w
         FROM rate_limits WHERE bucket = ? AND client_key = ? LIMIT 1'
    );
    $stmt->execute([$bucket, $key]);
    $row = $stmt->fetch();

    $now = time();
    if ($row) {
        $elapsed = $now - (int)$row['w'];
        if ($elapsed >= $windowSec) {
            // النافذة انتهت — أعد التصفير
            // ✅ تحويل PostgreSQL: FROM_UNIXTIME() (MySQL) → to_timestamp()
            $pdo->prepare(
                'UPDATE rate_limits SET attempts = 1, window_start = to_timestamp(?)
                 WHERE bucket = ? AND client_key = ?'
            )->execute([$now, $bucket, $key]);
            return;
        }
        if ((int)$row['attempts'] >= $max) {
            $retry = $windowSec - $elapsed;
            header('Retry-After: ' . $retry);
            json_error(
                'عدد المحاولات تجاوز الحد. حاول بعد ' . ceil($retry / 60) . ' دقيقة.',
                429
            );
        }
        $pdo->prepare(
            'UPDATE rate_limits SET attempts = attempts + 1
             WHERE bucket = ? AND client_key = ?'
        )->execute([$bucket, $key]);
    } else {
        $pdo->prepare(
            'INSERT INTO rate_limits (bucket, client_key, attempts, window_start)
             VALUES (?, ?, 1, to_timestamp(?))'
        )->execute([$bucket, $key, $now]);
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
