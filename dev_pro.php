<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — الموافقة/رفض طلبات ترقية Pro (محمي بتوكن المطوّر)
 * منفصل تماماً عن pro.php الرئيسي (الذي يتطلب توكن مستخدم بصلاحية مدير) —
 * هذا الملف يستخدم dev_require_auth() الخاص بلوحة المطوّر فقط.
 *
 * ✅ تحسين: منطق الموافقة/الرفض الفعلي (تفعيل is_pro، حساب تاريخ الانتهاء،
 *    تحديث حالة الطلب) أصبح موحّداً في _pro_helpers.php ويُستخدم من هنا ومن
 *    pro.php الرئيسي، لمنع انحراف التنفيذ بين لوحتي التحكم مستقبلاً.
 *    فصل المصادقة (auth) نفسه يبقى كما هو تماماً — لا تغيير أمني هنا.
 *
 * POST { action: 'approve'|'reject', request_id, reason? }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';
require_once __DIR__ . '/_pro_helpers.php';

dev_require_auth();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method Not Allowed', 405);
}

$b = input_json();
$action = $b['action'] ?? '';
$requestId = (int)($b['request_id'] ?? 0);
if ($requestId <= 0) json_error('request_id مطلوب', 400);

switch ($action) {
    case 'approve': {
        $result = pro_approve_request($pdo, $requestId, 'developer');
        $req = $result['request'];
        $expiresAt = $result['expires_at'];

        // ✅ إصلاح: تمرير tenant_id الخاص بالطلب (متوفر ضمن $req عبر SELECT *)
        // لضمان ظهور حدث التفعيل في سجل تدقيق المتجر المعني، لا بلا متجر.
        audit("dev_panel: تفعيل Pro للمستخدم {$req['user_email']} (خطة {$req['plan']})", 'developer', 'info', (int)($req['tenant_id'] ?? 0) ?: null);
        json_ok(['success' => true, 'message' => 'تم تفعيل الحساب Pro بنجاح', 'expires_at' => $expiresAt]);
        break;
    }

    case 'reject': {
        $reason = trim((string)($b['reason'] ?? 'لم يتم تأكيد التحويل'));
        $req = pro_reject_request($pdo, $requestId, 'developer', $reason);

        // ✅ إصلاح: نفس منطق التفعيل أعلاه — تمرير tenant_id الخاص بالطلب.
        audit("dev_panel: رفض طلب ترقية Pro للمستخدم {$req['user_email']}", 'developer', 'info', (int)($req['tenant_id'] ?? 0) ?: null);
        json_ok(['success' => true, 'message' => 'تم رفض الطلب']);
        break;
    }

    default:
        json_error('إجراء غير معروف', 400);
}
