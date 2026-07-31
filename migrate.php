<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Task 11 — أداة تشغيل/تتبّع ملفات الترحيل (Migration Runner)
 * ─────────────────────────────────────────────────────────────────────────────
 * راجع migrations/README.md للتوثيق الكامل لآلية العمل وقواعد كتابة ترحيل جديد.
 *
 * الاستخدام (من سطر الأوامر على الخادم، داخل مجلد jawali_api):
 *   php migrate.php status              اعرض حالة كل ملفات الترحيل (مُطبَّق/معلَّق)
 *   php migrate.php apply               طبِّق كل الترحيلات المعلَّقة بالترتيب
 *   php migrate.php backfill <ملف1> ... سجِّل ملفات مُطبَّقة يدوياً من قبل (بدون تنفيذ SQL)
 *
 * 🔒 قرار أمني مقصود: action=apply و action=backfill يعملان فقط من CLI مباشرة
 * على الخادم (php_sapi_name() === 'cli') — action=status فقط مسموح أيضاً عبر
 * HTTP (بمفتاح سري، لنفس نمط backup_cron.php) للاطّلاع السريع عن بُعد. تنفيذ
 * ترحيلات هيكلية فعلية (قد تُغيّر/تحذف بيانات) يجب أن يبقى محصوراً بمن يملك
 * وصولاً مباشراً لسطر الأوامر على الخادم — لا عبر أي رابط HTTP حتى لو تسرّب
 * مفتاح سري لاحقاً.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/_db.php';

$isCli = (php_sapi_name() === 'cli');
$pdo = db();

// ── جدول التتبّع نفسه — يُنشأ احترازياً هنا (IF NOT EXISTS) قبل أي شيء آخر ──
// (راجع التعليق التصميمي في migrations/008_schema_migrations_tracking.sql)
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        id            SERIAL PRIMARY KEY,
        filename      VARCHAR(255) NOT NULL UNIQUE,
        checksum      VARCHAR(64)  NOT NULL,
        applied_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
        applied_by    VARCHAR(20)  NOT NULL DEFAULT \'manual\',
        execution_ms  INTEGER
    )'
);

// ── تحديد الإجراء المطلوب + حماية HTTP (لـ status فقط) ──────────────────────
if ($isCli) {
    global $argv;
    $action = $argv[1] ?? 'status';
    $extra = array_slice($argv, 2);
} else {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_error('Method Not Allowed', 405);
    }
    $secret = getenv('MIGRATE_STATUS_SECRET') ?: (JWT_SECRET . '::migrate-status');
    $given = (string) ($_GET['key'] ?? '');
    if ($given === '' || !hash_equals($secret, $given)) {
        json_error('غير مصرح', 401);
    }
    $action = 'status'; // 🔒 عبر HTTP: status فقط، بصرف النظر عن أي قيمة أخرى مُرسَلة
    $extra = [];
}

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql');
sort($files, SORT_STRING); // أسماء 000_، 001_، ... تُرتَّب رقمياً بالترتيب الأبجدي

$appliedRows = $pdo->query('SELECT filename, checksum FROM schema_migrations')
    ->fetchAll(PDO::FETCH_ASSOC);
$applied = [];
foreach ($appliedRows as $row) {
    $applied[$row['filename']] = $row['checksum'];
}

$pending = [];
$mismatched = [];
foreach ($files as $f) {
    $name = basename($f);
    $checksum = hash('sha256', file_get_contents($f));
    if (!isset($applied[$name])) {
        $pending[] = $name;
    } elseif ($applied[$name] !== $checksum) {
        // ⚠️ الملف تغيّر بعد أن كان مُطبَّقاً — خطأ منهجي؛ الترحيلات المُطبَّقة
        // يجب أن تبقى ثابتة للأبد (راجع migrations/README.md).
        $mismatched[] = $name;
    }
}

function migrate_output(bool $isCli, array $data): void
{
    if ($isCli) {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                echo "$k:\n";
                if (empty($v)) {
                    echo "  (لا يوجد)\n";
                } else {
                    foreach ($v as $item) {
                        echo "  - $item\n";
                    }
                }
            } else {
                echo "$k: $v\n";
            }
        }
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

switch ($action) {
    case 'status':
        migrate_output($isCli, [
            'إجمالي ملفات الترحيل الموجودة' => count($files),
            'مُطبَّقة ومسجَّلة' => count($applied),
            'معلَّقة (تحتاج تطبيق)' => $pending,
            'تحذير – ملفات مُطبَّقة تغيّر محتواها بعد التطبيق' => $mismatched,
        ]);
        break;

    case 'apply':
        if (!$isCli) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['success' => false, 'message' => 'تنفيذ الترحيلات مسموح فقط من سطر الأوامر (CLI) على الخادم مباشرة.'],
                JSON_UNESCAPED_UNICODE
            );
            exit(1);
        }
        if (!empty($mismatched)) {
            echo "⛔ رُفض التنفيذ: ملفات مُطبَّقة سابقاً تغيّر محتواها منذ ذلك:\n";
            foreach ($mismatched as $m) echo "   - $m\n";
            echo "لا تُعدَّل أبداً ملفات ترحيل مُطبَّقة مسبقاً — أضف ملف ترحيل جديد بدلاً من ذلك.\n";
            exit(1);
        }
        if (empty($pending)) {
            echo "✅ لا توجد ترحيلات معلَّقة — القاعدة محدَّثة بالكامل (" . count($applied) . " ملف مُطبَّق).\n";
            exit(0);
        }
        echo "سيتم تطبيق " . count($pending) . " ترحيل بالترتيب:\n";
        foreach ($pending as $p) echo "   - $p\n";
        echo "\n";
        foreach ($pending as $name) {
            $path = $dir . '/' . $name;
            $sql = file_get_contents($path);
            $checksum = hash('sha256', $sql);
            echo "▶ تطبيق $name ...\n";
            $start = microtime(true);
            // بعض الملفات (مثل 001) تحتوي BEGIN/COMMIT صريحة داخلها؛ إن لم تحتوِ
            // نُغلّفها بمعاملة هنا لضمان تراجع كامل تلقائي عند أي خطأ فيها.
            $hasOwnTransaction = (bool) preg_match('/^\s*BEGIN\s*;/im', $sql);
            try {
                if (!$hasOwnTransaction) $pdo->beginTransaction();
                $pdo->exec($sql);
                if (!$hasOwnTransaction) $pdo->commit();
                $ms = (int) round((microtime(true) - $start) * 1000);
                $ins = $pdo->prepare(
                    'INSERT INTO schema_migrations (filename, checksum, applied_by, execution_ms)
                     VALUES (:f, :c, :b, :m)'
                );
                $ins->execute(['f' => $name, 'c' => $checksum, 'b' => 'migrate_cli', 'm' => $ms]);
                echo "  ✅ تم بنجاح ({$ms}ms)\n";
            } catch (Throwable $e) {
                if (!$hasOwnTransaction && $pdo->inTransaction()) $pdo->rollBack();
                echo '  ❌ فشل: ' . $e->getMessage() . "\n";
                echo "⛔ توقّف التنفيذ عند أول فشل — الترحيلات اللاحقة (إن وُجدت) لم تُنفَّذ.\n";
                exit(1);
            }
        }
        echo "\n✅ تم تطبيق " . count($pending) . " ترحيل بنجاح.\n";
        break;

    case 'backfill':
        if (!$isCli) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['success' => false, 'message' => 'مسموح فقط من سطر الأوامر (CLI).'],
                JSON_UNESCAPED_UNICODE
            );
            exit(1);
        }
        if (empty($extra)) {
            echo "الاستخدام: php migrate.php backfill <filename1.sql> <filename2.sql> ...\n";
            echo "يُستخدم لتسجيل ملفات طُبِّقت يدوياً على القاعدة قبل وجود هذه الأداة —\n";
            echo "بدون إعادة تنفيذ SQL الخاص بها (فقط تسجيل checksum + تاريخ).\n";
            exit(1);
        }
        foreach ($extra as $name) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                echo "⚠️  تخطّي (الملف غير موجود): $name\n";
                continue;
            }
            $checksum = hash('sha256', file_get_contents($path));
            $ins = $pdo->prepare(
                'INSERT INTO schema_migrations (filename, checksum, applied_by)
                 VALUES (:f, :c, \'manual\')
                 ON CONFLICT (filename) DO NOTHING'
            );
            $ins->execute(['f' => $name, 'c' => $checksum]);
            echo "✅ سُجِّل كمُطبَّق مسبقاً (يدوياً): $name\n";
        }
        break;

    default:
        migrate_output($isCli, ['error' => 'إجراء غير معروف. الإجراءات المتاحة: status | apply | backfill']);
        exit(1);
}
