<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Task 8 — نقطة نهاية اختيارية لِـ cron حقيقي على مستوى الخادم (لمن يملك
 * صلاحية جدولة على استضافته) — تكمُّلية للاستدعاء "الفرصي" الموجود أصلاً
 * داخل auth.php (يعمل بدون أي إعداد إضافي حتى بدون cron حقيقي).
 *
 * تشغّل هذه النقطة نسخاً احتياطياً تلقائياً لكل متجر نشط لم يحن له نسخة
 * احتياطية منذ ≥24 ساعة، دفعة واحدة — مفيدة للمتاجر غير النشطة (لا تسجيل
 * دخول متكرر) التي لن يُشغِّل تسجيل دخولها القليل الاستدعاء الفرصي بانتظام.
 *
 * الحماية: مفتاح سري في الاستعلام (?key=...) — يُقارَن بأمان (hash_equals)
 * مع BACKUP_CRON_SECRET (متغيّر بيئة، أو مُشتَق افتراضياً من JWT_SECRET حتى
 * يعمل الاستدعاء دون أي إعداد بيئة إضافي).
 *
 * مثال إعداد Cron حقيقي (اختياري، على من يملك صلاحية الاستضافة):
 *   0 3 * * * curl -s "https://api.example.com/backup_cron.php?key=SECRET" >/dev/null
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_backup_helpers.php';

// معالجة كل المتاجر النشطة قد تستغرق دقائق (استعلامات شبكية متعددة لكل متجر) —
// نلغي حدّ زمن تنفيذ PHP الافتراضي (30 ثانية على بعض استضافات php-fpm) حتى لا
// يُقطَع الطلب في منتصف الدفعة. يُفضَّل استدعاء هذه النقطة في وقت هادئ (ليلاً)
// مع مهلة curl سخية كافية (raw مثال: curl -s -m 600 ...).
set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method Not Allowed', 405);

$secret = getenv('BACKUP_CRON_SECRET') ?: (JWT_SECRET . '::backup-cron');
$given  = (string)($_GET['key'] ?? '');
if ($given === '' || !hash_equals($secret, $given)) {
    json_error('غير مصرح', 401);
}

$pdo = db();
// ملاحظة: عمود is_active في tenants من نوع smallint (لا boolean) — استخدم = 1
$tenants = $pdo->query('SELECT id FROM tenants WHERE is_active = 1 ORDER BY id')->fetchAll();

$results = [];
foreach ($tenants as $t) {
    $tid = (int)$t['id'];
    try {
        if (auto_backup_due($pdo, $tid)) {
            $id = create_tenant_backup($pdo, $tid, true, 'auto:cron');
            $results[] = ['tenant_id' => $tid, 'created' => true, 'backup_id' => $id];
        } else {
            $results[] = ['tenant_id' => $tid, 'created' => false, 'reason' => 'not_due'];
        }
    } catch (Throwable $e) {
        // Throwable (لا Exception فقط) لضمان عدم إسقاط كامل الطلب بسبب متجر واحد
        error_log('[Jawali][backup_cron] فشل نسخ احتياطي لمتجر #' . $tid . ': ' . $e->getMessage());
        $results[] = ['tenant_id' => $tid, 'created' => false, 'reason' => 'error'];
    }
}

json_ok(['processed' => count($results), 'results' => $results]);
