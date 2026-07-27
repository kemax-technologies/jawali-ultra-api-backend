<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Scanner Poll Endpoint
 * استطلاع الباركود الجديد من التطبيق الرئيسي (Flutter POS)
 *
 * المسار:
 *   GET /scanner_poll.php?session_id=XXX&last_id=N
 *
 * الاستجابة:
 *   { success: true, codes: [{id, code, received_at},...], last_id: N, remaining: 540 }
 *   { success: true, codes: [], last_id: 0, ... }  ← لا يوجد باركود جديد بعد
 *
 * ── آلية العمل المُحسَّنة (Queue + last_code_id) ──────────────────────────
 * ✅ يُرجع قائمة الباركودات من جدول scanner_codes بدءًا من last_id
 * ✅ يستخدم last_id (BIGINT) بدل timestamp لضمان الترتيب الصحيح
 * ✅ دعم after (timestamp) للتوافق مع الإصدارات السابقة
 * ✅ لا يحذف السجلات — يحتفظ بـ scan_count والسجل التاريخي
 * ✅ استعلام فعّال على INDEX (session_id, id)
 *
 * - لا يحتاج توكن JWT — الحماية عبر معرّف الجلسة قصيرة العمر
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('استخدم GET', 405);
}

$sessionId = trim($_GET['session_id'] ?? '');
// ✅ last_id — المؤشر الرئيسي الجديد (BIGINT أسرع وأدق من timestamp)
$lastId    = (int)($_GET['last_id'] ?? 0);
// after — timestamp للتوافق مع الإصدارات السابقة (يُستخدم فقط إذا لم يُرسَل last_id)
$after     = trim($_GET['after'] ?? '');

if ($sessionId === '') json_error('session_id مطلوب', 400);

// ── جلب حالة الجلسة بأعمدة محددة فقط (لا SELECT *) ─────────────────────────
try {
    $stmt = db()->prepare(
        'SELECT id, status, last_code_id, scan_count, expires_at, tenant_id
         FROM scanner_sessions
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
} catch (Exception $e) {
    error_log('[Jawali][scanner_poll] فشل الاستعلام عن الجلسة: ' . $e->getMessage());
    json_error('خطأ داخلي في الخادم', 500);
}

if (!$row) {
    json_error('الجلسة غير موجودة', 404);
}

// ── tenant_id مأخوذ من الجلسة نفسها (لا يوجد JWT في هذا الـ endpoint) ────────
$tenantId = $row['tenant_id'] ?? null;

// ── التحقق من الانتهاء ────────────────────────────────────────────────────────
$expiresTs = strtotime($row['expires_at']);
$remaining = max(0, $expiresTs - time());

if ($row['status'] === 'expired' || $remaining === 0) {
    try {
        db()->prepare("UPDATE scanner_sessions SET status='expired' WHERE id=?")
            ->execute([$sessionId]);
    } catch (Exception $e) {}
    json_error('انتهت صلاحية الجلسة', 410);
}

if (in_array($row['status'], ['closed'], true)) {
    json_error('الجلسة مغلقة', 410);
}

// ── ✅ جلب الباركودات الجديدة من Queue باستخدام last_id (فعّال وآمن) ─────────
$newCodes = [];
$maxId    = $lastId;

try {
    if ($lastId > 0) {
        // ✅ الطريقة الجديدة: استعلام على INDEX (session_id, id) — سريع جداً
        $stmtQ = db()->prepare(
            'SELECT id, code, received_at
             FROM scanner_codes
             WHERE session_id = ? AND id > ? AND tenant_id = ?
             ORDER BY id ASC
             LIMIT 20'
        );
        $stmtQ->execute([$sessionId, $lastId, $tenantId]);
    } elseif ($after !== '') {
        // ✅ التوافق مع الإصدارات السابقة: استخدام after (timestamp)
        $stmtQ = db()->prepare(
            'SELECT id, code, received_at
             FROM scanner_codes
             WHERE session_id = ? AND received_at > ? AND tenant_id = ?
             ORDER BY id ASC
             LIMIT 20'
        );
        $stmtQ->execute([$sessionId, $after, $tenantId]);
    } else {
        // أول استعلام بدون مؤشر — جلب آخر باركود فقط للتوافق
        $stmtQ = db()->prepare(
            'SELECT id, code, received_at
             FROM scanner_codes
             WHERE session_id = ? AND tenant_id = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmtQ->execute([$sessionId, $tenantId]);
    }

    $rows = $stmtQ->fetchAll();
    foreach ($rows as $r) {
        $newCodes[] = [
            'id'          => (int)$r['id'],
            'code'        => $r['code'],
            'received_at' => $r['received_at'],
        ];
        if ((int)$r['id'] > $maxId) {
            $maxId = (int)$r['id'];
        }
    }
} catch (Exception $e) {
    error_log('[Jawali][scanner_poll] فشل جلب الباركودات: ' . $e->getMessage());
    json_error('خطأ داخلي في الخادم', 500);
}

// ── الاستجابة ────────────────────────────────────────────────────────────────
// للتوافق مع الإصدار السابق: نُبقي على حقل code و code_at بأول نتيجة (أو null)
$firstCode   = !empty($newCodes) ? $newCodes[0]['code']        : null;
$firstCodeAt = !empty($newCodes) ? $newCodes[0]['received_at'] : null;

json_ok([
    'success'     => true,
    // ✅ القائمة الكاملة من Queue
    'codes'       => $newCodes,
    // ✅ المؤشر الجديد — يُرسَل كـ last_id في الطلب التالي
    'last_id'     => $maxId,
    // للتوافق مع الإصدار السابق
    'code'        => $firstCode,
    'code_at'     => $firstCodeAt,
    'scan_count'  => (int)$row['scan_count'],
    'status'      => $row['status'],
    'remaining'   => $remaining,
]);
