<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Task 8 — النسخ الاحتياطي والاستعادة المركزي (Server-Side, تلقائي + يدوي)
 * ─────────────────────────────────────────────────────────────────────────────
 * يختلف هذا تماماً عن النسخ الاحتياطي الحالي في تطبيق الجوّال (JSON محلي /
 * مشاركة / Google Drive شخصي) — هنا: نسخ مركزية على قاعدة بيانات المنصّة،
 * تُنشأ تلقائياً بشكل دوري (بفرصة كل تسجيل دخول ناجح — انظر auth.php) أو
 * يدوياً بضغطة زر، وتُستعاد عبر معاملة (transaction) واحدة على مستوى الخادم
 * تحذف الحالة الحالية للمتجر وتُدرِج لقطة سابقة — أسرع وأكثر أماناً من دفع
 * آلاف السجلات عبر الشبكة عنصراً بعنصر.
 *
 * GET  ?action=list                     → قائمة نسخ هذا المتجر (بيانات وصفية فقط)
 * GET  ?action=download&id=ID           → محتوى نسخة واحدة كاملة (JSON)
 * POST { action: 'create' }             → نسخة يدوية فورية
 * POST { action: 'restore', backup_id, confirm: true }  → استعادة (مدير فقط)
 * POST { action: 'delete', backup_id }  → حذف نسخة واحدة
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_backup_helpers.php';

$pdo    = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $auth     = require_permission('backup');
    $tenantId = tenant_id_from_auth($auth);
    $action   = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $stmt = $pdo->prepare(
            'SELECT id, size_bytes, is_automatic, triggered_by, created_at
             FROM tenant_backups WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 100'
        );
        $stmt->execute([$tenantId]);
        json_ok(['backups' => $stmt->fetchAll()]);
    }

    if ($action === 'download') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_error('id مطلوب', 400);
        // ⚠️ أمان (إلزامي): tenant_id ضمن شرط WHERE لضمان عدم تسريب نسخة
        // احتياطية لمتجر آخر عبر تخمين رقم id.
        $stmt = $pdo->prepare('SELECT backup_data, created_at FROM tenant_backups WHERE id = ? AND tenant_id = ? LIMIT 1');
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
        if (!$row) json_error('النسخة الاحتياطية غير موجودة', 404);

        audit('backups: تنزيل نسخة احتياطية #' . $id, $auth['email'] ?? null, 'info', $tenantId);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="jawali_backup_' . $id . '.json"');
        echo $row['backup_data'];
        exit;
    }

    json_error('إجراء غير معروف', 400);
}

if ($method === 'POST') {
    $auth     = require_permission('backup');
    $tenantId = tenant_id_from_auth($auth);
    $b        = input_json();
    $action   = $b['action'] ?? '';

    switch ($action) {
        case 'create': {
            try {
                $id = create_tenant_backup($pdo, $tenantId, false, $auth['email'] ?? null);
            } catch (Exception $e) {
                error_log('[Jawali][backups] فشل إنشاء نسخة احتياطية يدوية لمتجر #' . $tenantId . ': ' . $e->getMessage());
                json_error('تعذّر إنشاء النسخة الاحتياطية', 500);
            }
            audit('backups: إنشاء نسخة احتياطية يدوية #' . $id, $auth['email'] ?? null, 'info', $tenantId);
            json_ok(['success' => true, 'backup_id' => $id]);
            break;
        }

        case 'restore': {
            // ⚠️ إجراء حرج جداً: يستبدل كل بيانات المتجر التشغيلية الحالية
            // بلقطة سابقة — مقيَّد بدور "مدير" فقط + يتطلّب تأكيداً صريحاً.
            if (($auth['role'] ?? '') !== 'مدير') {
                json_error('غير مصرح — استعادة نسخة احتياطية تتطلّب صلاحية مدير', 403);
            }
            if (empty($b['confirm'])) {
                json_error('يتطلّب تأكيداً صريحاً (confirm=true) — هذا إجراء لا يمكن التراجع عنه', 400);
            }
            $backupId = (int)($b['backup_id'] ?? 0);
            if ($backupId <= 0) json_error('backup_id مطلوب', 400);

            $stmt = $pdo->prepare('SELECT backup_data FROM tenant_backups WHERE id = ? AND tenant_id = ? LIMIT 1');
            $stmt->execute([$backupId, $tenantId]);
            $row = $stmt->fetch();
            if (!$row) json_error('النسخة الاحتياطية غير موجودة', 404);

            $backupData = json_decode($row['backup_data'], true);
            if (!is_array($backupData)) json_error('محتوى النسخة الاحتياطية تالف', 500);

            // 🛡️ نسخة أمان تلقائية "قبل الاستعادة" — إن أخطأ المستخدم اختيار
            // النسخة الخاطئة، يبقى دائماً بإمكانه التراجع فوراً لآخر حالة.
            try {
                create_tenant_backup($pdo, $tenantId, false, ($auth['email'] ?? null) . ':pre_restore_safety');
            } catch (Exception $e) {
                error_log('[Jawali][backups] تحذير: فشل إنشاء نسخة أمان قبل الاستعادة لمتجر #' . $tenantId . ': ' . $e->getMessage());
            }

            try {
                $stats = restore_tenant_backup($pdo, $tenantId, $backupData);
            } catch (Exception $e) {
                error_log('[Jawali][backups] فشل استعادة نسخة #' . $backupId . ' لمتجر #' . $tenantId . ': ' . $e->getMessage());
                audit('backups: فشل استعادة نسخة احتياطية #' . $backupId, $auth['email'] ?? null, 'critical', $tenantId);
                json_error('تعذّرت الاستعادة — تم التراجع عن كل التغييرات بأمان (Rollback)', 500);
            }

            audit('backups: استعادة نسخة احتياطية #' . $backupId, $auth['email'] ?? null, 'critical', $tenantId);
            json_ok(['success' => true, 'stats' => $stats]);
            break;
        }

        case 'delete': {
            $backupId = (int)($b['backup_id'] ?? 0);
            if ($backupId <= 0) json_error('backup_id مطلوب', 400);
            $stmt = $pdo->prepare('DELETE FROM tenant_backups WHERE id = ? AND tenant_id = ?');
            $stmt->execute([$backupId, $tenantId]);
            if ($stmt->rowCount() === 0) json_error('النسخة الاحتياطية غير موجودة', 404);
            audit('backups: حذف نسخة احتياطية #' . $backupId, $auth['email'] ?? null, 'info', $tenantId);
            json_ok(['success' => true]);
            break;
        }

        default:
            json_error('إجراء غير معروف', 400);
    }
    exit;
}

json_error('Method Not Allowed', 405);
