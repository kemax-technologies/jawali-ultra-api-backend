<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — الموافقة/رفض طلبات ترقية Pro (محمي بتوكن المطوّر)
 * منفصل تماماً عن pro.php الرئيسي (الذي يتطلب توكن مستخدم بصلاحية مدير) —
 * هذا الملف يستخدم dev_require_auth() الخاص بلوحة المطوّر فقط.
 *
 * POST { action: 'approve'|'reject', request_id, reason? }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method Not Allowed', 405);
}

const DEV_PRO_PLAN_DAYS = ['yearly' => 365, 'monthly' => 30];

$b = input_json();
$action = $b['action'] ?? '';
$requestId = (int)($b['request_id'] ?? 0);
if ($requestId <= 0) json_error('request_id مطلوب', 400);

$stmt = $pdo->prepare('SELECT * FROM pro_requests WHERE id = ?');
$stmt->execute([$requestId]);
$req = $stmt->fetch();
if (!$req) json_error('الطلب غير موجود', 404);

switch ($action) {
    case 'approve': {
        if ($req['status'] !== 'pending') json_error('تمت مراجعة هذا الطلب مسبقاً', 409);

        $days = DEV_PRO_PLAN_DAYS[$req['plan']] ?? 365;
        $expiresAt = (new DateTime())->modify("+{$days} days")->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE users SET is_pro = 1, pro_plan = ?, pro_expires_at = ?, pro_activated_at = now()
                 WHERE id = ?'
            )->execute([$req['plan'], $expiresAt, $req['user_id']]);

            $pdo->prepare(
                "UPDATE pro_requests SET status = 'approved', reviewed_by = 'developer', reviewed_at = now()
                 WHERE id = ?"
            )->execute([$requestId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][dev_pro.approve] ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }

        audit("dev_panel: تفعيل Pro للمستخدم {$req['user_email']} (خطة {$req['plan']})", 'developer');
        json_ok(['success' => true, 'message' => 'تم تفعيل الحساب Pro بنجاح', 'expires_at' => $expiresAt]);
        break;
    }

    case 'reject': {
        if ($req['status'] !== 'pending') json_error('تمت مراجعة هذا الطلب مسبقاً', 409);
        $reason = trim((string)($b['reason'] ?? 'لم يتم تأكيد التحويل'));

        $pdo->prepare(
            "UPDATE pro_requests SET status = 'rejected', reviewed_by = 'developer', reviewed_at = now(), reject_reason = ?
             WHERE id = ?"
        )->execute([$reason, $requestId]);

        audit("dev_panel: رفض طلب ترقية Pro للمستخدم {$req['user_email']}", 'developer');
        json_ok(['success' => true, 'message' => 'تم رفض الطلب']);
        break;
    }

    default:
        json_error('إجراء غير معروف', 400);
}
