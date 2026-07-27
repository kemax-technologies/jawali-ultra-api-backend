<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 👑 Jawali Ultra — منطق مشترك لموافقة/رفض طلبات ترقية Pro
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ✅ تحسين: كان منطق "الموافقة/الرفض" (تفعيل is_pro، حساب تاريخ الانتهاء،
 *    تحديث حالة الطلب) مكرراً حرفياً بين pro.php (لوحة تحكم التطبيق) و
 *    dev_pro.php (لوحة تحكم المطوّر) — مما يخلق خطر انحراف الاثنين عن بعضهما
 *    مستقبلاً عند أي تعديل يُطبَّق على ملف واحد وينسى الآخر.
 *
 * ⚠️ ملاحظة مهمة: هذا الملف لا يغيّر شيئاً في فصل الصلاحيات (auth) — كل من
 *    pro.php و dev_pro.php يستمر باستخدام نظام مصادقته الخاص به
 *    (require_admin() مقابل dev_require_auth()) قبل استدعاء هذه الدوال.
 *    الدوال هنا تنفّذ فقط منطق قاعدة البيانات المشترك بعد نجاح المصادقة.
 */

// ── مدة كل خطة بالأيام (مصدر واحد للحقيقة) ───────────────────────────────────
if (!defined('PRO_PLAN_DAYS')) {
    define('PRO_PLAN_DAYS_ARR', ['yearly' => 365, 'monthly' => 30]);
}

/**
 * الموافقة على طلب ترقية Pro وتفعيل الحساب فعلياً.
 *
 * @param PDO    $pdo
 * @param int    $requestId
 * @param string $reviewedBy  البريد الإلكتروني للمدير، أو 'developer' من لوحة المطوّر
 * @param int|null $tenantId  Multi-Tenant: مُمرَّر من pro.php لتقييد البحث بمتجر
 *                            المدير الحالي؛ null من dev_pro.php (بلا تقييد — كل المتاجر)
 * @return array ['request' => array, 'expires_at' => string]
 * @throws Exception في حال عدم وجود الطلب أو كونه غير معلّق (pending)
 */
function pro_approve_request(PDO $pdo, int $requestId, string $reviewedBy, ?int $tenantId = null): array {
    // ✅ Multi-Tenant: عند تمرير $tenantId (من pro.php — لوحة تحكم المتجر) يُقيَّد
    // البحث بمتجر المدير الحالي فقط. عند تركه null (من dev_pro.php — لوحة تحكم
    // المطوّر) يبقى البحث بلا تقييد عمداً لأن المطوّر يدير كل المتاجر.
    if ($tenantId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM pro_requests WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$requestId, $tenantId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM pro_requests WHERE id = ?');
        $stmt->execute([$requestId]);
    }
    $req = $stmt->fetch();
    if (!$req) {
        json_error($tenantId !== null ? 'الطلب غير موجود في متجرك' : 'الطلب غير موجود', 404);
    }
    if ($req['status'] !== 'pending') {
        json_error('تمت مراجعة هذا الطلب مسبقاً', 409);
    }

    $days = PRO_PLAN_DAYS_ARR[$req['plan']] ?? 365;
    $expiresAt = (new DateTime())->modify("+{$days} days")->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE users SET is_pro = 1, pro_plan = ?, pro_expires_at = ?, pro_activated_at = now()
             WHERE id = ?'
        )->execute([$req['plan'], $expiresAt, $req['user_id']]);

        $pdo->prepare(
            "UPDATE pro_requests SET status = 'approved', reviewed_by = ?, reviewed_at = now()
             WHERE id = ?"
        )->execute([$reviewedBy, $requestId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('[Jawali][pro_approve_request] ' . $e->getMessage());
        json_error('خطأ داخلي في الخادم', 500);
    }

    return ['request' => $req, 'expires_at' => $expiresAt];
}

/**
 * رفض طلب ترقية Pro.
 *
 * @param PDO    $pdo
 * @param int    $requestId
 * @param string $reviewedBy
 * @param string $reason
 * @return array الطلب قبل الرفض (لأغراض التسجيل/audit)
 */
function pro_reject_request(PDO $pdo, int $requestId, string $reviewedBy, string $reason, ?int $tenantId = null): array {
    if ($tenantId !== null) {
        $stmt = $pdo->prepare('SELECT * FROM pro_requests WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$requestId, $tenantId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM pro_requests WHERE id = ?');
        $stmt->execute([$requestId]);
    }
    $req = $stmt->fetch();
    if (!$req) {
        json_error($tenantId !== null ? 'الطلب غير موجود في متجرك' : 'الطلب غير موجود', 404);
    }
    if ($req['status'] !== 'pending') {
        json_error('تمت مراجعة هذا الطلب مسبقاً', 409);
    }

    $pdo->prepare(
        "UPDATE pro_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = now(), reject_reason = ?
         WHERE id = ?"
    )->execute([$reviewedBy, $reason, $requestId]);

    return $req;
}
