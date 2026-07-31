<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة المتاجر (Tenants) في نظام SaaS متعدد المتاجر — محمي
 * GET  ?action=list                → قائمة كل المتاجر + إحصائيات ملخّصة لكل متجر
 * GET  ?action=view&id=ID          → تفاصيل متجر واحد (المستخدمون + إحصائياته)
 * POST { action: 'toggle_active'|'change_plan'|'delete', tenant_id }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        // ⚠️ أمان (إلزامي): صاحب المنصّة لا يملك أي متجر ولا يجوز أن يرى بياناته
        // التشغيلية (فواتير/إيرادات/مخزون/عملاء) — دوره يقتصر على إدارة الخطة
        // (مجانية/Pro) وتفعيل/تعليق المتجر فقط. لذلك تم عمداً حذف
        // invoices_count/total_revenue/products_count من هذا الاستعلام.
        // عدد المستخدمين (users_count) هو بيان حسابي إداري فقط، وليس بياناً
        // تشغيليّاً، لذلك يبقى مسموحاً لضبط الخطة والتراخيص.
        $rows = $pdo->query(
            "SELECT
                t.id, t.name, t.plan, t.is_active, t.created_at, t.owner_user_id,
                owner.name  AS owner_name,
                owner.email AS owner_email,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users_count
             FROM tenants t
             LEFT JOIN users owner ON owner.id = t.owner_user_id
             ORDER BY t.id DESC"
        )->fetchAll();
        json_ok($rows);
    }

    if ($action === 'view') {
        $tenantId = (int)($_GET['id'] ?? 0);
        if ($tenantId <= 0) json_error('id مطلوب', 400);

        $t = $pdo->prepare(
            "SELECT t.id, t.name, t.plan, t.is_active, t.created_at, t.owner_user_id,
                    owner.name AS owner_name, owner.email AS owner_email
             FROM tenants t
             LEFT JOIN users owner ON owner.id = t.owner_user_id
             WHERE t.id = ? LIMIT 1"
        );
        $t->execute([$tenantId]);
        $tenant = $t->fetch();
        if (!$tenant) json_error('المتجر غير موجود', 404);

        // ✅ بيانات حساب فقط (بلا بيانات تشغيلية) — لدعم قرارات Pro/التفعيل حصراً
        $usersStmt = $pdo->prepare(
            'SELECT id, name, email, role, is_active, is_pro, created_at, last_login_at
             FROM users WHERE tenant_id = ? ORDER BY id ASC'
        );
        $usersStmt->execute([$tenantId]);
        $tenant['users'] = $usersStmt->fetchAll();

        // ⚠️ أمان (إلزامي): لا نُرجع أي إحصائيات تشغيلية (فواتير/إيرادات/
        // منتجات/عملاء) لهذا المتجر — صاحب المنصّة لا صلاحية له على بيانات
        // تشغيل أي متجر بتاتاً. فقط عدد الحسابات (users_count) كبيان إداري.
        $tenant['stats'] = [
            'users_count' => count($tenant['users']),
        ];

        json_ok($tenant);
    }

    json_error('إجراء غير معروف', 400);
}

if ($method === 'POST') {
    $b = input_json();
    $action = $b['action'] ?? '';
    $tenantId = (int)($b['tenant_id'] ?? 0);
    if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);

    $exists = $pdo->prepare('SELECT 1 FROM tenants WHERE id = ?');
    $exists->execute([$tenantId]);
    if (!$exists->fetch()) json_error('المتجر غير موجود', 404);

    switch ($action) {
        case 'toggle_active': {
            // ✅ تعليق/إلغاء تعليق متجر كامل — يمنع فوراً كل مستخدمي المتجر من
            // تسجيل الدخول أو تنفيذ أي طلب API (يُنفَّذ عبر فحص tenants.is_active
            // داخل require_auth() في _db.php).
            $row = $pdo->prepare('SELECT is_active FROM tenants WHERE id = ?');
            $row->execute([$tenantId]);
            $cur = $row->fetchColumn();
            $new = $cur ? 0 : 1;
            $pdo->prepare('UPDATE tenants SET is_active = ? WHERE id = ?')->execute([$new, $tenantId]);
            audit("dev_panel: toggle_active tenant#$tenantId → $new", 'developer', 'warning', $tenantId);
            json_ok(['success' => true, 'is_active' => (bool)$new]);
            break;
        }
        case 'change_plan': {
            $plan = trim((string)($b['plan'] ?? ''));
            if (!in_array($plan, ['free', 'pro'], true)) json_error('خطة غير صالحة', 400);
            $pdo->prepare('UPDATE tenants SET plan = ? WHERE id = ?')->execute([$plan, $tenantId]);
            audit("dev_panel: change_plan tenant#$tenantId → $plan", 'developer', 'info', $tenantId);
            json_ok(['success' => true]);
            break;
        }
        case 'delete': {
            // ⚠️ حذف متجر كامل مع كل بياناته (مستخدمين، فواتير، منتجات...) —
            // إجراء لا يمكن التراجع عنه، مقيَّد لحماية المتاجر الأساسية.
            //
            // ✅ إصلاح حرج: جميع قيود المفاتيح الأجنبية (Foreign Keys) المرتبطة
            // بـ tenant_id في قاعدة البيانات معرَّفة بـ "ON DELETE NO ACTION"
            // (لا CASCADE) — أي أن حذف صف "tenants" مباشرةً (كما كان الكود
            // القديم يفعل) كان يفشل دائماً بمجرد وجود صف واحد مرتبط في أي من
            // ~30 جدولاً تابعاً (فواتير، منتجات، عملاء...)، مما يجعل ميزة حذف
            // متجر مُعطَّلة فعلياً لأي متجر نشط بالفعل. الحل: حذف كل الجداول
            // التابعة يدوياً بترتيب يحترم تسلسل المفاتيح الأجنبية بينها (من
            // الأبعد اعتماداً إلى الأقرب)، ثم المستخدمين، ثم المتجر نفسه.
            if ($tenantId === 1) json_error('لا يمكن حذف المتجر الرئيسي', 400);
            try {
                $pdo->beginTransaction();

                // ✅ Task 7 — audit_log أصبح غير قابل للتعديل/الحذف على مستوى
                // قاعدة البيانات (Trigger) إلا ضمن هذا الاستثناء المقصود
                // بالذات: حذف متجر بالكامل هنا. هذا المتغيّر محلي للمعاملة
                // (transaction-scoped GUC) ويُعاد تلقائياً لقيمته الافتراضية
                // عند COMMIT/ROLLBACK — لا يمكن أن يبقى مفعَّلاً بالخطأ.
                $pdo->exec("SET LOCAL jawali.allow_audit_purge = 'on'");

                // فكّ الحلقة الدائرية (tenants.owner_user_id <-> users.tenant_id)
                // قبل الحذف الفعلي لتجنّب انتهاك قيود FK.
                $pdo->prepare('UPDATE tenants SET owner_user_id = NULL WHERE id = ?')->execute([$tenantId]);

                // فكّ الحلقة الذاتية في شجرة الحسابات (parent_id يشير لنفس الجدول)
                $pdo->prepare('UPDATE chart_of_accounts SET parent_id = NULL WHERE tenant_id = ?')->execute([$tenantId]);

                // ترتيب الحذف: الجداول "الأعمق" (التي تعتمد على غيرها بمفاتيح
                // أجنبية NO ACTION) أولاً، ثم الجداول التي تعتمد عليها، وأخيراً
                // المستخدمون والمتجر نفسه.
                $tablesInOrder = [
                    'journal_entry_lines',   // entry_id → journal_entries, account_id → chart_of_accounts
                    'money_transfers',       // cash_account_id / payout_cash_account_id → cash_accounts
                    'payroll_runs',          // employee_id → employees, cash_account_id → cash_accounts
                    'vouchers',              // cash_account_id → cash_accounts
                    'cash_transactions',     // account_id → cash_accounts (CASCADE أصلاً، لكن نحذفها صريحاً للأمان)
                    'credit_payments',       // credit_id → credits (CASCADE أصلاً)
                    'invoice_items',         // invoice_id → invoices (CASCADE أصلاً)
                    'invoices',              // warehouse_id / cash_account_id → warehouses / cash_accounts
                    'warehouse_transfers',   // from/to_warehouse_id → warehouses
                    'warehouse_stock',       // warehouse_id → warehouses
                    'journal_entries',       // (بعد حذف journal_entry_lines التابعة لها)
                    'chart_of_accounts',     // (بعد فكّ parent_id أعلاه)
                    'employees',             // (بعد حذف payroll_runs التابعة لها)
                    'cash_accounts',         // (بعد حذف كل ما يشير إليها أعلاه)
                    'warehouses',            // (بعد حذف كل ما يشير إليها أعلاه)
                    'credits',               // (بعد حذف credit_payments التابعة لها)
                    'pro_requests',
                    'products',
                    'customers',
                    'suppliers',
                    'purchases',
                    'purchase_returns',
                    'quotations',
                    'returns',
                    'expenses',
                    'assets',
                    'scanner_codes',
                    'scanner_sessions',
                    'settings',
                    'branches',
                    'audit_log',
                ];
                foreach ($tablesInOrder as $table) {
                    $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = ?")->execute([$tenantId]);
                }

                // المستخدمون أخيراً (يُلغي تلقائياً عبر CASCADE: password_reset_tokens،
                // support_tickets ← support_messages، user_social_links، pro_requests
                // إن وُجدت أي بقايا مرتبطة بمستخدمين هذا المتجر تحديداً)
                $pdo->prepare('DELETE FROM users WHERE tenant_id = ?')->execute([$tenantId]);

                $pdo->prepare('DELETE FROM tenants WHERE id = ?')->execute([$tenantId]);
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[Jawali][dev_tenants] فشل حذف المتجر #' . $tenantId . ': ' . $e->getMessage());
                json_error('تعذّر حذف المتجر — قد تحتوي بيانات مرتبطة يتعذّر حذفها', 500);
            }
            audit("dev_panel: delete tenant#$tenantId", 'developer', 'warning', null);
            json_ok(['success' => true]);
            break;
        }
        default:
            json_error('إجراء غير معروف', 400);
    }
}

json_error('Method Not Allowed', 405);
