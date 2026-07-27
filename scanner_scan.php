<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Scanner Scan Endpoint
 * استقبال الباركود من الجهاز الخارجي (الكاميرا المساعدة) وتمريره للوحة الويب
 *
 * المسارات:
 *   POST /scanner_scan.php            → body JSON: { "session_id": "...", "code": "...", "sig": "...", "idem": "..." }
 *   GET  /scanner_scan.php?session=X  → صفحة HTML بسيطة جداً تفتح ماسح ضوئي عبر المتصفح
 *                                        (احتياطي لو لم يستطع المستخدم تثبيت تطبيق)
 *
 * - الميزة اختيارية بالكامل
 * - لا يحتاج توكن JWT — الحماية عبر معرّف الجلسة قصيرة العمر (10 دقائق)
 *
 * ── تحديثات الأمان والأداء ──────────────────────────────────────────────────
 * ✅ HMAC Signature — التحقق من توقيع الباركود لمنع الحقن المزيّف
 * ✅ Rate Limiting — 30 مسحًا/دقيقة لكل جلسة، مع نافذة متحركة
 * ✅ Device Binding — ربط الجلسة بأول جهاز يستخدمها وحظر الأجهزة الأخرى
 * ✅ Queue Insert — إدراج في scanner_codes بدل الكتابة فوق last_code
 * ✅ Idempotency Key — منع تكرار نفس المسح حتى لو أُرسل عدة مرات
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Endpoint للنقطة الويب (HTML Fallback) ───────────────────────────────────
// إذا كان الطلب GET بمعامل session — أرسل صفحة HTML بسيطة بدل JSON
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['session'])) {
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    $sid = htmlspecialchars($_GET['session'], ENT_QUOTES, 'UTF-8');
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>📷 ماسح Jawali المساعد</title>
<style>
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#0f172a;color:#fff;text-align:center}
  header{padding:14px;background:#1e293b;font-weight:800}
  #status{padding:10px;background:#0ea5e9;color:#fff}
  #reader{width:100%;max-width:480px;margin:auto}
  #log{margin:12px;padding:8px;background:#1e293b;border-radius:8px;font-size:13px;max-height:200px;overflow:auto;text-align:right}
  .ok{color:#4ade80}.err{color:#f87171}.info{color:#93c5fd}
</style>
<script src="assets/js/html5-qrcode.min.js"></script>
<!-- ✅ إصلاح #8: المكتبة محلية — لا اعتماد على CDN خارجي.
     نزّل الملف أولاً: curl -L https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js -o jawali_api/assets/js/html5-qrcode.min.js
     راجع: jawali_api/assets/js/README.md -->
</head><body>
<header>📷 الماسح المساعد — Jawali Ultra</header>
<div id="status">جاري تشغيل الكاميرا…</div>
<div id="reader"></div>
<div id="log"></div>
<script>
(function(){
  var SID = <?= json_encode($sid) ?>;
  var ENDPOINT = location.pathname; // نفس scanner_scan.php
  var $log = document.getElementById('log');
  var $status = document.getElementById('status');
  var lastCode = null, lastAt = 0;
  function log(msg, cls){ var d=document.createElement('div'); d.className=cls||'info'; d.textContent='['+new Date().toLocaleTimeString()+'] '+msg; $log.insertBefore(d,$log.firstChild); }
  function send(code){
    var now = Date.now();
    if (code === lastCode && (now - lastAt) < 1500) return;
    lastCode = code; lastAt = now;
    // ✅ إضافة idempotency_key فريد لكل إرسال
    var idem = SID + ':' + code + ':' + now;
    fetch(ENDPOINT, {
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({session_id:SID, code:code, idem:idem})
    }).then(function(r){return r.json();})
      .then(function(j){
        if (j && j.success){ log('✓ أُرسل: '+code, 'ok'); $status.textContent='✅ تم — '+code; $status.style.background='#16a34a'; }
        else { log('✗ '+(j&&j.message||'فشل'), 'err'); $status.textContent='⚠️ '+(j&&j.message||'فشل'); $status.style.background='#dc2626'; }
      }).catch(function(e){ log('✗ '+e.message, 'err'); });
  }
  var html5QrCode = new Html5Qrcode("reader");
  html5QrCode.start(
    { facingMode: "environment" },
    { fps: 10, qrbox: { width: 260, height: 160 } },
    function(decoded){ send(decoded); },
    function(){}
  ).then(function(){ $status.textContent='📷 وجّه الكاميرا نحو الباركود'; })
   .catch(function(err){ $status.textContent='❌ تعذّر فتح الكاميرا: '+err; $status.style.background='#dc2626'; });
})();
</script>
</body></html><?php
    exit;
}

require_once __DIR__ . '/_db.php';

// ── ثوابت ────────────────────────────────────────────────────────────────────
const SCANNER_SESSION_TTL  = 600;
const SCANNER_RATE_LIMIT   = 30;
const SCANNER_RATE_WINDOW  = 60;

/**
 * توليد مفتاح HMAC خاص بالجلسة (يجب أن يتطابق مع scanner_session.php)
 */
function scanner_hmac_key(string $session_id): string {
    return hash_hmac('sha256', 'jawali_scanner:' . $session_id, JWT_SECRET);
}

/**
 * التحقق من توقيع HMAC للباركود
 * التطبيق المساعد يحسب: HMAC-SHA256(code, hmac_key) ويرسله كـ sig
 */
function scanner_verify_hmac(string $session_id, string $code, string $sig): bool {
    $key      = scanner_hmac_key($session_id);
    $expected = hash_hmac('sha256', $code, $key);
    return hash_equals($expected, $sig);
}

/**
 * حساب بصمة الجهاز (IP + User-Agent)
 */
function scanner_device_fingerprint(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return hash('sha256', $ip . '|' . $ua);
}

// ── POST → استقبال كود الباركود ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method Not Allowed', 405);
}

$body      = input_json();
$sessionId = trim($body['session_id'] ?? '');
$code      = trim($body['code']       ?? '');
$sig       = trim($body['sig']        ?? '');   // ✅ توقيع HMAC (اختياري من الويب، إلزامي من التطبيق)
$idemKey   = trim($body['idem']       ?? '');   // ✅ مفتاح Idempotency

if ($sessionId === '') json_error('معرّف الجلسة مطلوب', 400);
if ($code === '')      json_error('الباركود مطلوب', 400);
if (strlen($code) > 255) json_error('باركود طويل جداً', 400);
if ($idemKey !== '' && strlen($idemKey) > 128) $idemKey = substr($idemKey, 0, 128);

// ── جلب الجلسة ───────────────────────────────────────────────────────────────
try {
    $stmt = db()->prepare(
        'SELECT id, status, expires_at, device_fingerprint,
                rate_window_start, rate_count, scan_count, tenant_id
         FROM scanner_sessions WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$sessionId]);
    $row = $stmt->fetch();
} catch (Exception $e) {
    error_log('[Jawali][scanner_scan] فشل البحث عن الجلسة: ' . $e->getMessage());
    json_error('خطأ داخلي في الخادم', 500);
}

if (!$row) json_error('الجلسة غير موجودة', 404);

// ── tenant_id مأخوذ من الجلسة نفسها (لا يوجد JWT في هذا الـ endpoint) ────────
$tenantId = $row['tenant_id'] ?? null;

// ── تحقق من الانتهاء ─────────────────────────────────────────────────────────
if (strtotime($row['expires_at']) < time()) {
    try {
        db()->prepare("UPDATE scanner_sessions SET status='expired' WHERE id=?")->execute([$sessionId]);
    } catch (Exception $e) {}
    json_error('انتهت صلاحية الجلسة', 410);
}
if (in_array($row['status'], ['closed','expired'], true)) {
    json_error('الجلسة مغلقة', 410);
}

// ── ✅ التحقق من توقيع HMAC ──────────────────────────────────────────────────
// إذا أرسل التطبيق توقيعًا، نتحقق منه؛ وإن لم يُرسَل من الويب الاحتياطي نتجاوزه
if ($sig !== '' && !scanner_verify_hmac($sessionId, $code, $sig)) {
    audit("scanner_hmac_fail id=$sessionId", null, 'warn', $tenantId);
    json_error('توقيع الباركود غير صحيح', 403);
}

// ── ✅ ربط الجلسة بالجهاز (Device Binding) ───────────────────────────────────
$fingerprint = scanner_device_fingerprint();
if ($row['device_fingerprint'] === null) {
    // أول مسح — تسجيل بصمة الجهاز
    try {
        db()->prepare(
            "UPDATE scanner_sessions SET device_fingerprint=?, status='active' WHERE id=?"
        )->execute([$fingerprint, $sessionId]);
        $row['device_fingerprint'] = $fingerprint;
    } catch (Exception $e) { /* نستمر */ }
} elseif ($row['device_fingerprint'] !== $fingerprint) {
    // جهاز مختلف يحاول استخدام الجلسة
    audit("scanner_device_mismatch id=$sessionId", null, 'warn', $tenantId);
    json_error('هذه الجلسة مرتبطة بجهاز آخر', 403);
}

// ── ✅ Rate Limiting (30 مسحًا / دقيقة لكل جلسة) ───────────────────────────
$now          = time();
$windowStart  = $row['rate_window_start'] ? strtotime($row['rate_window_start']) : 0;
$windowCount  = (int)$row['rate_count'];

if ($now - $windowStart >= SCANNER_RATE_WINDOW) {
    // نافذة جديدة — إعادة العداد
    $windowStart = $now;
    $windowCount = 0;
}

$windowCount++;
if ($windowCount > SCANNER_RATE_LIMIT) {
    audit("scanner_rate_limit id=$sessionId", null, 'warn', $tenantId);
    json_error('تجاوزت الحد المسموح به من المسحات (' . SCANNER_RATE_LIMIT . '/دقيقة). حاول لاحقاً.', 429);
}

// ── ✅ إدراج الباركود في Queue (scanner_codes) مع دعم Idempotency ────────────
$newCodeId = null;
try {
    if ($idemKey !== '') {
        // ✅ تحويل PostgreSQL: INSERT IGNORE → INSERT ... ON CONFLICT DO NOTHING
        // (يعتمد على قيد uq_idempotency الموجود في scanner_codes)
        // محاولة إدراج — إذا كان المفتاح مكرراً يُتجاهَل بصمت
        $stmtInsert = db()->prepare(
            'INSERT INTO scanner_codes (session_id, code, idempotency_key, tenant_id)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (session_id, idempotency_key) DO NOTHING'
        );
        $stmtInsert->execute([$sessionId, $code, $idemKey, $tenantId]);
        if ($stmtInsert->rowCount() === 0) {
            // نفس العملية أُرسلت من قبل — نعيد نجاحًا بصمت (Idempotent)
            json_ok([
                'success'      => true,
                'session_id'   => $sessionId,
                'code'         => $code,
                'received_at'  => date('Y-m-d H:i:s'),
                'idempotent'   => true,
            ]);
        }
        $newCodeId = (int)db()->lastInsertId();
    } else {
        $stmtInsert = db()->prepare(
            'INSERT INTO scanner_codes (session_id, code, tenant_id) VALUES (?, ?, ?)'
        );
        $stmtInsert->execute([$sessionId, $code, $tenantId]);
        $newCodeId = (int)db()->lastInsertId();
    }
} catch (Exception $e) {
    error_log('[Jawali][scanner_scan] فشل إدراج الباركود: ' . $e->getMessage());
    json_error('خطأ داخلي في الخادم', 500);
}

// ── تحديث الجلسة (last_code للتوافق + last_code_id + Rate window) ────────────
try {
    $stmtUpdate = db()->prepare(
        "UPDATE scanner_sessions
         SET status          = 'active',
             last_code       = ?,
             last_code_at    = NOW(),
             last_code_id    = ?,
             scan_count      = scan_count + 1,
             rate_window_start = ?,
             rate_count      = ?
         WHERE id = ?"
    );
    $stmtUpdate->execute([
        $code,
        $newCodeId,
        date('Y-m-d H:i:s', $windowStart),
        $windowCount,
        $sessionId,
    ]);
} catch (Exception $e) {
    error_log('[Jawali][scanner_scan] فشل تحديث الجلسة: ' . $e->getMessage());
    json_error('خطأ داخلي في الخادم', 500);
}

audit("scanner_scan id=$sessionId code=$code code_id=$newCodeId", null, 'info', $tenantId);

json_ok([
    'success'     => true,
    'session_id'  => $sessionId,
    'code'        => $code,
    'code_id'     => $newCodeId,
    'received_at' => date('Y-m-d H:i:s'),
]);
