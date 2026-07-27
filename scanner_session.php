<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Scanner Session API
 * إنشاء/إدارة جلسات الكاميرا المساعدة (Scanner Pairing Sessions)
 *
 * المسارات:
 *   POST   /scanner_session.php             → إنشاء جلسة جديدة (تستجيب بـ session_id + qr_payload)
 *   GET    /scanner_session.php?id=XXX      → فحص حالة الجلسة (status, last_code, expires_at)
 *   DELETE /scanner_session.php?id=XXX      → إنهاء الجلسة يدوياً
 *
 * - الجلسة تنتهي تلقائياً بعد 10 دقائق (TTL)
 * - الميزة اختيارية بالكامل: لا تؤثر على بقية التطبيق
 * - يستخدم نفس قاعدة البيانات jawali_db مع جدول مستقل scanner_sessions
 *   يُنشأ تلقائياً عند أول استدعاء (Self-bootstrap)
 *
 * ── تحديثات الأمان والأداء ──────────────────────────────────────────────────
 * ✅ نظام Queue للباركودات (جدول scanner_codes) — لا يضيع أي مسح
 * ✅ Rate Limiting ذكي — 30 مسحًا في الدقيقة لكل جلسة
 * ✅ ربط الجلسة بأول جهاز يستخدمها (device_fingerprint)
 * ✅ HMAC Signature للباركود — hmac_key مضمّن في QR لمنع التلاعب
 * ✅ last_code_id للاستعلام الفعّال بدل الـ timestamp
 * ✅ Idempotency Key — يمنع تكرار نفس العملية
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';

// مدة صلاحية الجلسة بالثواني (10 دقائق)
const SCANNER_SESSION_TTL  = 600;
// أقصى عدد مسحات في الدقيقة لكل جلسة
const SCANNER_RATE_LIMIT   = 30;
// نافذة Rate Limiting بالثواني
const SCANNER_RATE_WINDOW  = 60;

/**
 * توليد مفتاح HMAC خاص بالجلسة (مشتق من JWT_SECRET + session_id)
 * يُرسَل للتطبيق المساعد داخل حمولة QR
 */
function scanner_hmac_key(string $session_id): string {
    return hash_hmac('sha256', 'jawali_scanner:' . $session_id, JWT_SECRET);
}

/**
 * إنشاء الجداول إن لم تكن موجودة (Self-bootstrap — يجعل الميزة Plug & Play)
 *
 * ✅ تحويل PostgreSQL/Supabase (كانت هذه الدالة تُنفّذ DDL خام بصيغة MySQL):
 *    - BIGINT ... AUTO_INCREMENT PRIMARY KEY      → BIGSERIAL PRIMARY KEY
 *    - DATETIME                                    → TIMESTAMP
 *    - INDEX (...) داخل CREATE TABLE               → CREATE INDEX منفصلة بعده
 *    - UNIQUE KEY name (...)                       → CONSTRAINT name UNIQUE (...)
 *    - ENGINE=InnoDB DEFAULT CHARSET=... COLLATE=... → أُزيلت (غير مطلوبة)
 *    - ALTER TABLE ADD COLUMN (بدون IF NOT EXISTS، معتمِدة على try/catch)
 *      → ADD COLUMN IF NOT EXISTS الأصلية في PostgreSQL (أنظف ولا تحتاج try/catch)
 */
function scanner_ensure_tables(): void {
    // ── جدول الجلسات ─────────────────────────────────────────────────────────
    $sql_sessions = "
    CREATE TABLE IF NOT EXISTS scanner_sessions (
        id                  VARCHAR(64)  NOT NULL PRIMARY KEY,
        owner_email         VARCHAR(190),
        branch_code         VARCHAR(64),
        status              VARCHAR(20)  NOT NULL DEFAULT 'pending',
        last_code           VARCHAR(255),
        last_code_at        TIMESTAMP,
        last_code_id        BIGINT,
        scan_count          INT          NOT NULL DEFAULT 0,
        device_fingerprint  VARCHAR(64),
        rate_window_start   TIMESTAMP,
        rate_count          INT          NOT NULL DEFAULT 0,
        created_at          TIMESTAMP    NOT NULL,
        expires_at          TIMESTAMP    NOT NULL,
        client_ip           VARCHAR(64),
        user_agent          VARCHAR(255),
        tenant_id           INTEGER
    )";
    db()->exec($sql_sessions);
    db()->exec("CREATE INDEX IF NOT EXISTS idx_status  ON scanner_sessions (status)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_expires ON scanner_sessions (expires_at)");

    // ── إضافة الأعمدة الجديدة إن كانت قاعدة البيانات قديمة (Upgrade-safe) ──
    // PostgreSQL يدعم "IF NOT EXISTS" أصليًا هنا، فلا حاجة لـ try/catch
    $upgrades = [
        "ALTER TABLE scanner_sessions ADD COLUMN IF NOT EXISTS last_code_id BIGINT",
        "ALTER TABLE scanner_sessions ADD COLUMN IF NOT EXISTS device_fingerprint VARCHAR(64)",
        "ALTER TABLE scanner_sessions ADD COLUMN IF NOT EXISTS rate_window_start TIMESTAMP",
        "ALTER TABLE scanner_sessions ADD COLUMN IF NOT EXISTS rate_count INT NOT NULL DEFAULT 0",
        "ALTER TABLE scanner_sessions ADD COLUMN IF NOT EXISTS tenant_id INTEGER",
        "ALTER TABLE scanner_codes ADD COLUMN IF NOT EXISTS tenant_id INTEGER",
    ];
    foreach ($upgrades as $sql) {
        try { db()->exec($sql); } catch (Exception $e) { /* العمود موجود مسبقاً */ }
    }

    // ── جدول طابور الباركودات (Queue) ────────────────────────────────────────
    $sql_codes = "
    CREATE TABLE IF NOT EXISTS scanner_codes (
        id               BIGSERIAL    PRIMARY KEY,
        session_id       VARCHAR(64)  NOT NULL,
        code             VARCHAR(255) NOT NULL,
        idempotency_key  VARCHAR(128),
        received_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
        tenant_id        INTEGER,
        CONSTRAINT uq_idempotency UNIQUE (session_id, idempotency_key)
    )";
    db()->exec($sql_codes);
    db()->exec("CREATE INDEX IF NOT EXISTS idx_session    ON scanner_codes (session_id)");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_session_id ON scanner_codes (session_id, id)");
}

/**
 * توليد معرّف جلسة عشوائي آمن
 */
function scanner_make_id(): string {
    return 'scn_' . bin2hex(random_bytes(12)); // 28 char total
}

/**
 * تنظيف الجلسات المنتهية
 */
function scanner_cleanup(): void {
    try {
        db()->exec(
            "UPDATE scanner_sessions
             SET status='expired'
             WHERE status IN ('pending','active') AND expires_at < NOW()"
        );
    } catch (Exception $e) { /* تجاهل */ }
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
try {
    scanner_ensure_tables();
    scanner_cleanup();
} catch (Exception $e) {
    // ✅ إصلاح #7: لا نكشف تفاصيل الخطأ
    error_log('[Jawali][scanner_session] فشل التهيئة: ' . $e->getMessage());
    json_error('خطأ داخلي في الخادم', 500);
}

$method = $_SERVER['REQUEST_METHOD'];

// ── POST → إنشاء جلسة جديدة ─────────────────────────────────────────────────
if ($method === 'POST') {
    // ✅ إصلاح #4: require_auth() إلزامي — يوقف التنفيذ فوراً إذا لم يكن JWT صالحاً
    $auth   = require_auth();
    $tenantId = tenant_id_from_auth($auth);
    $email  = $auth['email']       ?? null;
    $branch = $_SERVER['HTTP_X_BRANCH_CODE'] ?? ($auth['branch_code'] ?? null);

    $id        = scanner_make_id();
    $createdAt = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + SCANNER_SESSION_TTL);

    // ── توليد مفتاح HMAC الخاص بالجلسة ──────────────────────────────────────
    $hmacKey = scanner_hmac_key($id);

    try {
        // ✅ تحويل PostgreSQL: علامات الاقتباس المزدوجة "pending" تُفسَّر كاسم
        // عمود/جدول في Postgres، لا كنص حرفي — استُبدلت بعلامات مفردة 'pending'
        $stmt = db()->prepare(
            "INSERT INTO scanner_sessions
             (id, owner_email, branch_code, status, created_at, expires_at, client_ip, user_agent, tenant_id)
             VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $id,
            $email,
            $branch,
            $createdAt,
            $expiresAt,
            $_SERVER['REMOTE_ADDR'] ?? '',
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240),
            $tenantId,
        ]);
    } catch (Exception $e) {
        error_log('[Jawali][scanner_session] فشل إنشاء الجلسة: ' . $e->getMessage());
        json_error('خطأ داخلي في الخادم', 500);
    }

    // محتوى QR — يضم العنوان الأساسي + معرّف الجلسة + مفتاح HMAC
    // التطبيق المساعد يقرأ هذا ويستخدمه لإرسال الباركودات مع توقيع HMAC
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    $qrPayload = [
        'app'        => 'jawali_scanner',
        'v'          => 2,
        'session_id' => $id,
        'api_base'   => $base,
        'expires_at' => $expiresAt,
        'hmac_key'   => $hmacKey,
        'rate_limit' => SCANNER_RATE_LIMIT,
    ];

    audit("scanner_session_create id=$id", $email, 'info', $tenantId);

    json_ok([
        'success'    => true,
        'session_id' => $id,
        'api_base'   => $base,
        'qr_payload' => json_encode($qrPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'qr_text'    => $base . '/scanner_scan.php?session=' . $id, // بديل بسيط للقراءة
        'expires_at' => $expiresAt,
        'ttl_secs'   => SCANNER_SESSION_TTL,
    ]);
}

// ── GET → فحص الحالة (Polling) ──────────────────────────────────────────────
if ($method === 'GET') {
    $auth = require_auth();
    $tenantId = tenant_id_from_auth($auth);
    $id = trim($_GET['id'] ?? '');
    if ($id === '') json_error('معرّف الجلسة مطلوب', 400);

    // ── دعم Server-Sent Events (SSE) عبر ?stream=1 ─────────────────────────
    if (!empty($_GET['stream'])) {
        // إعادة ضبط الترويسات لـ SSE
        @header_remove('Content-Type');
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) { ob_end_flush(); }
        @ob_implicit_flush(true);

        // ✅ نستخدم last_id لتحديد آخر باركود تمت معالجته (فعّال وآمن)
        $lastId   = (int)($_GET['last_id'] ?? 0);
        $started  = time();
        $deadline = $started + SCANNER_SESSION_TTL + 30;

        while (time() < $deadline) {
            try {
                $stmt = db()->prepare(
                    'SELECT id, status, scan_count, expires_at
                     FROM scanner_sessions WHERE id = ? AND tenant_id = ? LIMIT 1'
                );
                $stmt->execute([$id, $tenantId]);
                $row = $stmt->fetch();
            } catch (Exception $e) { $row = null; }

            if (!$row) {
                echo "event: error\n";
                echo 'data: ' . json_encode(['message' => 'الجلسة غير موجودة']) . "\n\n";
                @flush();
                break;
            }

            // تحقق من انتهاء الصلاحية
            if (strtotime($row['expires_at']) < time()) {
                try {
                    db()->prepare("UPDATE scanner_sessions SET status='expired' WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
                } catch (Exception $e) {}
                echo "event: expired\n";
                echo 'data: ' . json_encode(['session_id' => $id, 'expires_at' => $row['expires_at']]) . "\n\n";
                @flush();
                break;
            }

            // ✅ جلب الباركودات الجديدة من Queue بدءًا من last_id
            try {
                $stmtQ = db()->prepare(
                    'SELECT id, code, received_at
                     FROM scanner_codes
                     WHERE session_id = ? AND id > ? AND tenant_id = ?
                     ORDER BY id ASC
                     LIMIT 10'
                );
                $stmtQ->execute([$id, $lastId, $tenantId]);
                $newCodes = $stmtQ->fetchAll();
            } catch (Exception $e) { $newCodes = []; }

            if (!empty($newCodes)) {
                foreach ($newCodes as $codeRow) {
                    echo "event: scan\n";
                    echo 'data: ' . json_encode([
                        'session_id' => $id,
                        'code'       => $codeRow['code'],
                        'at'         => $codeRow['received_at'],
                        'code_id'    => (int)$codeRow['id'],
                        'scan_count' => (int)$row['scan_count'],
                    ], JSON_UNESCAPED_UNICODE) . "\n\n";
                    @flush();
                    $lastId = (int)$codeRow['id'];
                }
            } else {
                // Heartbeat كل ~5 ثوانٍ
                echo ": ping " . time() . "\n\n";
                @flush();
            }

            if (connection_aborted()) break;
            // فاصل اقتراع الجدول من جهة الخادم
            usleep(800 * 1000); // 0.8s
        }

        exit;
    }

    // ── GET عادي → JSON snapshot ──────────────────────────────────────────
    try {
        $stmt = db()->prepare(
            'SELECT id, status, last_code, last_code_at, last_code_id, scan_count, expires_at, created_at
             FROM scanner_sessions WHERE id = ? AND tenant_id = ? LIMIT 1'
        );
        $stmt->execute([$id, $tenantId]);
        $row = $stmt->fetch();
    } catch (Exception $e) {
        error_log('[Jawali][scanner_session] فشل قراءة الجلسة: ' . $e->getMessage());
        json_error('خطأ داخلي في الخادم', 500);
    }

    if (!$row) json_error('الجلسة غير موجودة', 404);

    // تحقق من انتهاء الصلاحية
    if ($row['status'] !== 'expired' && strtotime($row['expires_at']) < time()) {
        try {
            db()->prepare("UPDATE scanner_sessions SET status='expired' WHERE id=? AND tenant_id=?")->execute([$id, $tenantId]);
        } catch (Exception $e) {}
        $row['status'] = 'expired';
    }

    json_ok([
        'success'      => true,
        'session_id'   => $row['id'],
        'status'       => $row['status'],
        'last_code'    => $row['last_code'],
        'last_code_at' => $row['last_code_at'],
        'last_code_id' => (int)($row['last_code_id'] ?? 0),
        'scan_count'   => (int)$row['scan_count'],
        'expires_at'   => $row['expires_at'],
        'created_at'   => $row['created_at'],
        'now'          => date('Y-m-d H:i:s'),
    ]);
}

// ── DELETE → إنهاء الجلسة ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    // ✅ إصلاح #4: حماية DELETE بالمصادقة أيضاً
    $auth = require_auth();
    $tenantId = tenant_id_from_auth($auth);
    $id = trim($_GET['id'] ?? '');
    if ($id === '') json_error('معرّف الجلسة مطلوب', 400);
    try {
        $stmt = db()->prepare("UPDATE scanner_sessions SET status='closed' WHERE id=? AND tenant_id=?");
        $stmt->execute([$id, $tenantId]);
        if ($stmt->rowCount() === 0) {
            json_error('الجلسة غير موجودة في متجرك', 404);
        }
    } catch (Exception $e) {
        // ✅ إصلاح #7: لا نكشف تفاصيل الخطأ للمستخدم
        error_log('[Jawali][scanner_session] فشل إنهاء الجلسة: ' . $e->getMessage());
        json_error('خطأ داخلي في الخادم', 500);
    }
    json_ok(['success' => true, 'session_id' => $id, 'status' => 'closed']);
}

json_error('Method Not Allowed', 405);
