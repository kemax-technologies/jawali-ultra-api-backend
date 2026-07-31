<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Task 8 — نظام النسخ الاحتياطي والاستعادة المركزي والتلقائي (Server-Side)
 * ─────────────────────────────────────────────────────────────────────────────
 * دوال مساعدة مشتركة تُستخدم من backups.php (الواجهة المستخدمة من التطبيق)،
 * ومن auth.php (الاستدعاء الفرصي عند تسجيل الدخول)، ومن backup_cron.php
 * (الاستدعاء الحقيقي عبر cron إن توفّر على الخادم).
 *
 * كل نسخة احتياطية = لقطة كاملة (JSONB) لكل الجداول التشغيلية لمتجر واحد
 * (tenant_id)، باستثناء:
 *   - users            (بيانات حساسة/مصادقة — تُستثنى كليّاً حتى من اللقطة،
 *                        تفادياً لأي تسريب/استعادة كلمات مرور قديمة)
 *   - audit_log        (سجل تدقيق غير قابل للتعديل — Task 7 — له آلية
 *                        استمرارية مستقلة، لا حاجة لتكراره هنا، ولا يجوز
 *                        أصلاً أن تُدرِج الاستعادة صفوفاً فيه لأنها ستُرفض
 *                        بواسطة Trigger عدم القابلية للتعديل)
 *   - scanner_codes / scanner_sessions / pro_requests (بيانات مؤقتة/تشغيلية
 *                        بحتة لا قيمة لها في استعادة كارثة)
 */
require_once __DIR__ . '/_db.php';

// ── ترتيب الجداول القابلة للاستعادة (يطابق تماماً الترتيب المُثبَت والمُختبَر
//    فعلياً في dev_tenants.php لحذف متجر كامل — انظر Task 7) ──────────────────
// ترتيب الحذف: الأعمق اعتماداً أولاً (children) ثم الأقل اعتماداً (parents).
const BACKUP_RESTORE_DELETE_ORDER = [
    'invoice_items',
    'journal_entry_lines',
    'money_transfers',
    'payroll_runs',
    'vouchers',
    'cash_transactions',
    'credit_payments',
    'warehouse_transfers',
    'warehouse_stock',
    'invoices',
    'journal_entries',
    'chart_of_accounts',   // بعد فكّ parent_id (انظر أدناه)
    'cash_accounts',
    'warehouses',
    'credits',
    'expenses',
    'returns',
    'purchase_returns',
    'purchases',
    'quotations',
    'assets',
    'employees',
    'suppliers',
    'customers',
    'products',
    'settings',
    'branches',
];

// ترتيب الإدراج: عكس ترتيب الحذف تماماً (parents أولاً ثم children)
const BACKUP_RESTORE_INSERT_ORDER = [
    'branches',
    'settings',
    'products',
    'customers',
    'suppliers',
    'employees',
    'assets',
    'quotations',
    'purchases',
    'purchase_returns',
    'returns',
    'expenses',
    'credits',
    'warehouses',
    'cash_accounts',
    'chart_of_accounts',   // يُدرَج بـ parent_id = NULL مؤقتاً (self-ref)
    'journal_entries',
    'invoices',
    'warehouse_stock',
    'warehouse_transfers',
    'credit_payments',
    'cash_transactions',
    'vouchers',
    'payroll_runs',
    'money_transfers',
    'journal_entry_lines',
    'invoice_items',
];

/** أقصى عدد صفوف نجلبها من جدول واحد ضمن نسخة احتياطية (حماية من نمو غير
 *  منطقي فقط — لا يُتوقَّع الوصول إليه في الاستخدام التجاري الطبيعي). */
const BACKUP_MAX_ROWS_PER_TABLE = 50000;

/** بعض الجداول تستخدم مفتاحاً طبيعياً مُركَّباً بدلاً من عمود id مستقل —
 *  يجب استخدام عمود الترتيب الصحيح الموجود فعلاً في كل جدول. */
const BACKUP_ORDER_COLUMN = [
    'settings'  => 'setting_key',
    'products'  => 'sku',
    'customers' => 'phone',
];

/** يبني محتوى اللقطة الكاملة لمتجر واحد من قاعدة البيانات الحيّة مباشرة. */
function build_tenant_backup_payload(PDO $pdo, int $tenantId): array {
    $payload = [
        'schema_version' => 1,
        'tenant_id'       => $tenantId,
        'generated_at'    => date('c'),
    ];

    foreach (BACKUP_RESTORE_INSERT_ORDER as $table) {
        $orderCol = BACKUP_ORDER_COLUMN[$table] ?? 'id';
        $stmt = $pdo->prepare(
            "SELECT * FROM \"{$table}\" WHERE tenant_id = ? ORDER BY \"{$orderCol}\" LIMIT ?"
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, BACKUP_MAX_ROWS_PER_TABLE, PDO::PARAM_INT);
        $stmt->execute();
        $payload[$table] = $stmt->fetchAll();
    }

    // 📋 لقطة معلوماتية فقط لحسابات المستخدمين (بدون كلمة مرور) — لا تُستعاد
    // أبداً، فقط للرجوع البصري عند الحاجة (من يعمل بالمتجر وقت النسخة).
    $u = $pdo->prepare(
        'SELECT id, name, email, role, is_active, branch_code, created_at
         FROM users WHERE tenant_id = ? ORDER BY id'
    );
    $u->execute([$tenantId]);
    $payload['_users_snapshot_readonly'] = $u->fetchAll();

    return $payload;
}

/** هل حان وقت نسخة احتياطية تلقائية جديدة لهذا المتجر؟ */
function auto_backup_due(PDO $pdo, int $tenantId, int $minIntervalHours = 24): bool {
    $stmt = $pdo->prepare(
        "SELECT created_at FROM tenant_backups
         WHERE tenant_id = ? AND is_automatic = TRUE
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([$tenantId]);
    $last = $stmt->fetchColumn();
    if (!$last) return true;
    $lastTs = strtotime($last . ' UTC');
    if ($lastTs === false) return true;
    return (time() - $lastTs) > ($minIntervalHours * 3600);
}

/** يُبقي فقط على آخر N نسخة تلقائية وآخر M نسخة يدوية لكل متجر (تنظيف دوري). */
function prune_tenant_backups(PDO $pdo, int $tenantId, int $keepAutomatic = 14, int $keepManual = 30): void {
    foreach ([['is_automatic' => true, 'keep' => $keepAutomatic], ['is_automatic' => false, 'keep' => $keepManual]] as $g) {
        $stmt = $pdo->prepare(
            "DELETE FROM tenant_backups
             WHERE tenant_id = ? AND is_automatic = ?
               AND id NOT IN (
                   SELECT id FROM tenant_backups
                   WHERE tenant_id = ? AND is_automatic = ?
                   ORDER BY created_at DESC LIMIT ?
               )"
        );
        $isAuto = $g['is_automatic'] ? 1 : 0;
        $stmt->execute([$tenantId, $isAuto, $tenantId, $isAuto, $g['keep']]);
    }
}

/** يبني وينشئ نسخة احتياطية جديدة فعلياً، ويُعيد الـ id الجديد. */
function create_tenant_backup(PDO $pdo, int $tenantId, bool $isAutomatic, ?string $triggeredBy = null): int {
    $payload = build_tenant_backup_payload($pdo, $tenantId);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $stmt = $pdo->prepare(
        'INSERT INTO tenant_backups (tenant_id, backup_data, size_bytes, is_automatic, triggered_by)
         VALUES (?, ?, ?, ?, ?) RETURNING id'
    );
    // ✅ إصلاح: قيمة PHP bool "false" تُرسَل بواسطة مشغّل pgsql عبر PDO كنص
    // فارغ "" بدلاً من قيمة boolean صالحة، فيفشل الإدراج بعمود boolean —
    // نحوّلها صريحاً إلى '1'/'0' (يقبلهما Postgres كنص boolean صالح دائماً).
    $stmt->execute([$tenantId, $json, strlen($json), $isAutomatic ? 1 : 0, $triggeredBy]);
    $id = (int)$stmt->fetchColumn();

    prune_tenant_backups($pdo, $tenantId);

    return $id;
}

/**
 * استدعاء "فرصي" آمن تماماً (best-effort) — يُستخدَم من نقاط مرور متكررة
 * (مثل تسجيل الدخول الناجح) لتوفير نسخ احتياطي تلقائي دوري حتى بدون أي
 * صلاحية جدولة (cron) حقيقية على الخادم (شائع في الاستضافة المشتركة).
 * لا يرمي أي استثناء أبداً — فشل النسخ الاحتياطي لا يجوز أن يعطّل أي طلب آخر.
 */
function maybe_auto_backup(PDO $pdo, int $tenantId): void {
    try {
        if (auto_backup_due($pdo, $tenantId)) {
            create_tenant_backup($pdo, $tenantId, true, 'auto:opportunistic');
        }
    } catch (Exception $e) {
        error_log('[Jawali][backup] فشل النسخ الاحتياطي التلقائي الفرصي لمتجر #' . $tenantId . ': ' . $e->getMessage());
    }
}

/**
 * يستعيد لقطة احتياطية كاملة لمتجر معيّن: يحذف الحالة الحالية للجداول
 * القابلة للاستعادة (بنفس ترتيب dev_tenants.php المُثبَت) ثم يُدرِج كل صفوف
 * اللقطة بترتيب معكوس يحترم القيود الأجنبية، ضمن Transaction واحدة تُلغى
 * بالكامل عند أي خطأ (Rollback) — لا نصف-استعادة أبداً.
 *
 * ⚠️ لا يلمس أبداً: users (بيانات مصادقة حساسة) أو audit_log (غير قابل
 * للتعديل — Task 7). العملية تتطلب مسبقاً require_admin() + تأكيد صريح من
 * المستدعي (backups.php) قبل الوصول إلى هذه الدالة.
 *
 * يُعيد مصفوفة إحصائية: [table => عدد الصفوف المُستعادة, ...]
 */
function restore_tenant_backup(PDO $pdo, int $tenantId, array $backupData): array {
    $stats = [];
    $alreadyInTx = $pdo->inTransaction();
    if (!$alreadyInTx) $pdo->beginTransaction();
    try {
        // 1) فكّ الحلقة الذاتية في شجرة الحسابات قبل الحذف (كما في dev_tenants.php)
        $pdo->prepare('UPDATE chart_of_accounts SET parent_id = NULL WHERE tenant_id = ?')
            ->execute([$tenantId]);

        // 2) حذف الحالة الحالية لكل الجداول القابلة للاستعادة (لهذا المتجر فقط)
        foreach (BACKUP_RESTORE_DELETE_ORDER as $table) {
            $pdo->prepare("DELETE FROM \"{$table}\" WHERE tenant_id = ?")->execute([$tenantId]);
        }

        // 3) إدراج صفوف اللقطة بالترتيب الصحيح (parents أولاً)
        //    chart_of_accounts تُدرَج بـ parent_id = NULL مؤقتاً لتفادي مشكلة
        //    الاعتماد الذاتي، ثم تُصحَّح في الخطوة 4 بعد اكتمال الإدراج.
        $chartParents = []; // id => original parent_id (لهذا المتجر فقط)

        foreach (BACKUP_RESTORE_INSERT_ORDER as $table) {
            $rows = $backupData[$table] ?? [];
            if (!is_array($rows) || count($rows) === 0) {
                $stats[$table] = 0;
                continue;
            }
            $inserted = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $row = $row; // كل الصفوف من نفس الجدول تحمل نفس الأعمدة (SELECT *)

                if ($table === 'chart_of_accounts' && array_key_exists('parent_id', $row)) {
                    if ($row['parent_id'] !== null) {
                        $chartParents[$row['id']] = $row['parent_id'];
                    }
                    $row['parent_id'] = null;
                }

                $cols = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($cols), '?'));
                $colsSql = implode(',', array_map(fn($c) => "\"{$c}\"", $cols));
                $sql = "INSERT INTO \"{$table}\" ({$colsSql}) VALUES ({$placeholders})";
                $ins = $pdo->prepare($sql);
                $ins->execute(array_values($row));
                $inserted++;
            }
            $stats[$table] = $inserted;
        }

        // 4) تصحيح parent_id في chart_of_accounts بعد اكتمال إدراج كل الصفوف
        foreach ($chartParents as $id => $parentId) {
            $pdo->prepare('UPDATE chart_of_accounts SET parent_id = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$parentId, $id, $tenantId]);
        }

        if (!$alreadyInTx) $pdo->commit();
    } catch (Exception $e) {
        if (!$alreadyInTx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $stats;
}
