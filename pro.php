<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 👑 Jawali Ultra — نظام تفعيل الترقية Pro (حقيقي — عبر تحويل بنكي + مراجعة إدارية)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * تدفّق العمل:
 *   1) المستخدم يحوّل المبلغ إلى حساب بنكي، ثم يرسل رقم/مرجع التحويل من داخل
 *      التطبيق → ينشئ طلب ترقية بحالة "pending" (لا يُفعَّل شيء تلقائياً).
 *   2) 🔒 مراجعة الطلب (موافقة/رفض/إلغاء) هي صلاحية حصرية لمطوّر التطبيق فقط
 *      عبر لوحة تحكم منفصلة تماماً (dev_pro.php + dev_require_auth()) —
 *      مدير المتجر ("مدير") لا يملك ولا يجب أن يملك صلاحية الموافقة على
 *      طلبات الترقية المدفوعة، لأن هذه صلاحية على مستوى المنصّة (Platform-
 *      level)، وليست على مستوى المتجر (Tenant-level). لذلك الإجراءات أدناه
 *      (list/approve/reject/revoke) محظورة صراحةً على "مدير" وتتطلب توكن
 *      المطوّر (dev_require_auth عبر Authorization: Bearer <dev_token>) —
 *      انظر pro_deny_store_admin() أدناه.
 *   3) عند الموافقة: يُفعَّل الحساب فعلياً في قاعدة البيانات (is_pro=1) مع
 *      تاريخ انتهاء حسب الخطة (سنوية = 365 يوم، شهرية = 30 يوم).
 *   4) التطبيق يتحقق من حالة Pro دائماً من الخادم (لا يعتمد على تخزين محلي) —
 *      فلا يمكن التلاعب بالحالة من الجهاز نفسه.
 *
 * Endpoints:
 *   POST /pro.php?action=request   — إنشاء طلب ترقية جديد (يتطلب تسجيل دخول)
 *   GET  /pro.php?action=status    — حالة Pro الحالية للمستخدم + آخر طلب
 *   GET  /pro.php?action=list      — 🔒 مطوّر التطبيق فقط (dev_pro.php/dev_stats.php)
 *   POST /pro.php?action=approve   — 🔒 مطوّر التطبيق فقط (dev_pro.php)
 *   POST /pro.php?action=reject    — 🔒 مطوّر التطبيق فقط (dev_pro.php)
 *   POST /pro.php?action=revoke    — 🔒 مطوّر التطبيق فقط (dev_users.php)
 */

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';
require_once __DIR__ . '/_pro_helpers.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// 🔒 حجب صريح: إدارة طلبات Pro (عرض/موافقة/رفض/إلغاء) ليست من صلاحية مدير
// المتجر ("مدير") — هذه صلاحية منصّة (Platform-level) حصرية لمطوّر التطبيق
// عبر لوحة تحكم منفصلة تماماً (dev_pro.php + dev_stats.php + dev_users.php
// بمصادقة dev_require_auth()). حتى لو امتلك مدير متجر توكن "مدير" صالحاً،
// يُرفض الطلب هنا فوراً (403) بدل تفويضه عبر require_admin() كما كان سابقاً.
function pro_deny_store_admin(): void {
    json_error(
        'هذا الإجراء متاح فقط عبر لوحة تحكم مطوّر التطبيق — ليس من صلاحية مدير المتجر',
        403
    );
}

// ── تحديث تلقائي: تنزيل حالة Pro عند انتهاء الصلاحية ─────────────────────────
function pro_autoexpire(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT is_pro, pro_plan, pro_expires_at, pro_activated_at FROM users WHERE id = ?'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) return ['is_pro' => false, 'pro_plan' => null, 'pro_expires_at' => null];

    $isPro = (int)($row['is_pro'] ?? 0) === 1;
    $expiresAt = $row['pro_expires_at'];
    if ($isPro && $expiresAt !== null && strtotime($expiresAt) < time()) {
        // ✅ انتهت صلاحية الاشتراك — تنزيل تلقائي
        $pdo->prepare('UPDATE users SET is_pro = 0 WHERE id = ?')->execute([$userId]);
        $isPro = false;
    }
    return [
        'is_pro'          => $isPro,
        'pro_plan'        => $row['pro_plan'],
        'pro_expires_at'  => $row['pro_expires_at'],
        'pro_activated_at' => $row['pro_activated_at'],
    ];
}

switch ($action) {
    // ═════════════════════════════════════════════════════════════════════════
    // POST — إنشاء طلب ترقية جديد (المستخدم بعد التحويل البنكي)
    // ═════════════════════════════════════════════════════════════════════════
    case 'request': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $userId = (int)$auth['sub'];
        $email  = (string)$auth['email'];

        // ✅ Rate limit: حماية من إرسال طلبات متكررة بلا حدود
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('pro_request', $email . '|' . $ip, 5, 3600);

        $body   = input_json();
        $plan   = in_array($body['plan'] ?? '', ['yearly', 'monthly'], true)
            ? $body['plan'] : 'yearly';
        $amount   = trim((string)($body['amount'] ?? ''));
        $currency = trim((string)($body['currency'] ?? ''));
        $bankAccount = trim((string)($body['bank_account'] ?? ''));
        $reference   = trim((string)($body['transfer_reference'] ?? ''));
        $senderName  = trim((string)($body['sender_name'] ?? ''));
        $notes       = trim((string)($body['notes'] ?? ''));

        if ($reference === '') {
            json_error('رقم/مرجع التحويل مطلوب لمراجعة الطلب');
        }

        // منع تكرار نفس رقم المرجع
        $dup = $pdo->prepare(
            'SELECT id FROM pro_requests WHERE transfer_reference = ? AND status <> \'rejected\''
        );
        $dup->execute([$reference]);
        if ($dup->fetch()) {
            json_error('رقم التحويل هذا مُستخدم في طلب آخر بالفعل', 409);
        }

        // منع تكرار طلب معلّق لنفس المستخدم
        $pending = $pdo->prepare(
            "SELECT id FROM pro_requests WHERE user_id = ? AND status = 'pending'"
        );
        $pending->execute([$userId]);
        if ($pending->fetch()) {
            json_error('لديك طلب ترقية قيد المراجعة بالفعل — يرجى الانتظار', 409);
        }

        $ins = $pdo->prepare(
            'INSERT INTO pro_requests
                (user_id, user_email, plan, amount, currency, bank_account,
                 transfer_reference, sender_name, notes, status, tenant_id)
             VALUES (?,?,?,?,?,?,?,?,?, \'pending\', ?)'
        );
        $ins->execute([
            $userId, $email, $plan, $amount, $currency, $bankAccount,
            $reference, $senderName, $notes, $tenantId,
        ]);

        audit('طلب ترقية Pro جديد', $email, 'info', $tenantId);
        json_ok([
            'success' => true,
            'message' => 'تم استلام طلبك وسيتم تفعيل حسابك بعد مراجعة التحويل',
            'request_id' => (int)$pdo->lastInsertId(),
        ]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GET — حالة Pro الحالية + آخر طلب لهذا المستخدم
    // ═════════════════════════════════════════════════════════════════════════
    case 'status': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $userId = (int)$auth['sub'];

        $status = pro_autoexpire($pdo, $userId);

        $stmt = $pdo->prepare(
            'SELECT id, plan, status, transfer_reference, reject_reason, created_at, reviewed_at
             FROM pro_requests WHERE user_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, $tenantId]);
        $lastRequest = $stmt->fetch() ?: null;

        json_ok([
            'success'      => true,
            'is_pro'       => $status['is_pro'],
            'pro_plan'     => $status['pro_plan'],
            'pro_expires_at' => $status['pro_expires_at'],
            'last_request' => $lastRequest,
        ]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GET — قائمة كل الطلبات — 🔒 محظور على مدير المتجر (انظر dev_stats.php)
    // ═════════════════════════════════════════════════════════════════════════
    case 'list': {
        // 🔒 صلاحية مطوّر التطبيق حصرياً — استخدم dev_stats.php?action=pro
        pro_deny_store_admin();
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — الموافقة على طلب (تفعيل حقيقي لحساب المستخدم)
    // ═════════════════════════════════════════════════════════════════════════
    case 'approve': {
        // 🔒 صلاحية مطوّر التطبيق حصرياً — استخدم dev_pro.php
        pro_deny_store_admin();
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — رفض طلب
    // ═════════════════════════════════════════════════════════════════════════
    case 'reject': {
        // 🔒 صلاحية مطوّر التطبيق حصرياً — استخدم dev_pro.php
        pro_deny_store_admin();
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — إلغاء تفعيل Pro عن مستخدم (مثلاً بعد انتهاء اتفاق أو استرجاع مبلغ)
    // ═════════════════════════════════════════════════════════════════════════
    case 'revoke': {
        // 🔒 صلاحية مطوّر التطبيق حصرياً — استخدم dev_users.php?action=revoke_pro
        pro_deny_store_admin();
        break;
    }

    default:
        json_error('إجراء غير معروف', 404);
}
