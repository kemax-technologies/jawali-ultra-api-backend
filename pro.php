<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 👑 Jawali Ultra — نظام تفعيل الترقية Pro (حقيقي — عبر تحويل بنكي + مراجعة إدارية)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * تدفّق العمل:
 *   1) المستخدم يحوّل المبلغ إلى حساب بنكي، ثم يرسل رقم/مرجع التحويل من داخل
 *      التطبيق → ينشئ طلب ترقية بحالة "pending" (لا يُفعَّل شيء تلقائياً).
 *   2) المدير (صلاحية "مدير" في نفس النظام) يراجع الطلب من لوحة التحكم
 *      داخل التطبيق، ثم يوافق أو يرفض.
 *   3) عند الموافقة: يُفعَّل الحساب فعلياً في قاعدة البيانات (is_pro=1) مع
 *      تاريخ انتهاء حسب الخطة (سنوية = 365 يوم، شهرية = 30 يوم).
 *   4) التطبيق يتحقق من حالة Pro دائماً من الخادم (لا يعتمد على تخزين محلي) —
 *      فلا يمكن التلاعب بالحالة من الجهاز نفسه.
 *
 * Endpoints:
 *   POST /pro.php?action=request   — إنشاء طلب ترقية جديد (يتطلب تسجيل دخول)
 *   GET  /pro.php?action=status    — حالة Pro الحالية للمستخدم + آخر طلب
 *   GET  /pro.php?action=list      — قائمة كل الطلبات (مدير فقط)
 *   POST /pro.php?action=approve   — الموافقة على طلب (مدير فقط)
 *   POST /pro.php?action=reject    — رفض طلب (مدير فقط)
 *   POST /pro.php?action=revoke    — إلغاء تفعيل Pro عن مستخدم (مدير فقط)
 */

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';
require_once __DIR__ . '/_pro_helpers.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

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
                 transfer_reference, sender_name, notes, status)
             VALUES (?,?,?,?,?,?,?,?,?, \'pending\')'
        );
        $ins->execute([
            $userId, $email, $plan, $amount, $currency, $bankAccount,
            $reference, $senderName, $notes,
        ]);

        audit('طلب ترقية Pro جديد', $email);
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
        $userId = (int)$auth['sub'];

        $status = pro_autoexpire($pdo, $userId);

        $stmt = $pdo->prepare(
            'SELECT id, plan, status, transfer_reference, reject_reason, created_at, reviewed_at
             FROM pro_requests WHERE user_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
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
    // GET — قائمة كل الطلبات (لوحة تحكم المدير)
    // ═════════════════════════════════════════════════════════════════════════
    case 'list': {
        require_admin();
        $statusFilter = $_GET['status'] ?? '';
        if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
            $stmt = $pdo->prepare(
                'SELECT pr.*, u.name AS user_name
                 FROM pro_requests pr JOIN users u ON u.id = pr.user_id
                 WHERE pr.status = ? ORDER BY pr.id DESC'
            );
            $stmt->execute([$statusFilter]);
        } else {
            $stmt = $pdo->query(
                'SELECT pr.*, u.name AS user_name
                 FROM pro_requests pr JOIN users u ON u.id = pr.user_id
                 ORDER BY pr.id DESC LIMIT 200'
            );
        }
        json_ok(['success' => true, 'requests' => $stmt->fetchAll()]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — الموافقة على طلب (تفعيل حقيقي لحساب المستخدم)
    // ═════════════════════════════════════════════════════════════════════════
    case 'approve': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $admin = require_admin();
        $body = input_json();
        $requestId = (int)($body['request_id'] ?? 0);
        if ($requestId <= 0) json_error('request_id مطلوب');

        // ✅ تحسين: منطق الموافقة موحّد الآن في _pro_helpers.php ويُستخدم من
        //    pro.php و dev_pro.php لمنع انحراف التنفيذ بين لوحتي التحكم.
        $result = pro_approve_request($pdo, $requestId, $admin['email'] ?? '');
        $req = $result['request'];
        $expiresAt = $result['expires_at'];

        audit("تفعيل Pro للمستخدم {$req['user_email']} (خطة {$req['plan']})", $admin['email'] ?? null);
        json_ok(['success' => true, 'message' => 'تم تفعيل الحساب Pro بنجاح', 'expires_at' => $expiresAt]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — رفض طلب
    // ═════════════════════════════════════════════════════════════════════════
    case 'reject': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $admin = require_admin();
        $body = input_json();
        $requestId = (int)($body['request_id'] ?? 0);
        $reason = trim((string)($body['reason'] ?? 'لم يتم تأكيد التحويل'));
        if ($requestId <= 0) json_error('request_id مطلوب');

        $req = pro_reject_request($pdo, $requestId, $admin['email'] ?? '', $reason);

        audit("رفض طلب ترقية Pro للمستخدم {$req['user_email']}", $admin['email'] ?? null);
        json_ok(['success' => true, 'message' => 'تم رفض الطلب']);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — إلغاء تفعيل Pro عن مستخدم (مثلاً بعد انتهاء اتفاق أو استرجاع مبلغ)
    // ═════════════════════════════════════════════════════════════════════════
    case 'revoke': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $admin = require_admin();
        $body = input_json();
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '') json_error('email مطلوب');

        $stmt = $pdo->prepare('UPDATE users SET is_pro = 0 WHERE email = ?');
        $stmt->execute([$email]);

        audit("إلغاء تفعيل Pro للمستخدم $email", $admin['email'] ?? null);
        json_ok(['success' => true, 'message' => 'تم إلغاء التفعيل']);
        break;
    }

    default:
        json_error('إجراء غير معروف', 404);
}
