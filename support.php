<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🎧 Jawali Ultra — نظام الدعم الفني (تذاكر حقيقية + ردود من الإدارة)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * تدفّق العمل:
 *   1) أي مستخدم مسجّل يمكنه فتح تذكرة دعم (موضوع + رسالة أولى).
 *   2) مستخدمو Pro يحصلون تلقائياً على أولوية أعلى (priority = 'high')
 *      وتُعلَّم تذكرتهم is_pro_ticket = 1 لتظهر أولاً لمطوّر التطبيق.
 *   3) 🔒 إدارة كل تذاكر الدعم (عرض تذاكر الجميع، الرد كإدارة، تغيير الحالة/
 *      الأولوية، الإغلاق) هي صلاحية حصرية لمطوّر التطبيق فقط عبر لوحة تحكم
 *      منفصلة تماماً (dev_support.php + dev_require_auth()). صلاحية "مدير"
 *      داخل المتجر لا تمنح أي وصول إداري لتذاكر الدعم — هذه صلاحية منصّة
 *      (Platform-level)، وليست صلاحية متجر (Tenant-level). لذلك
 *      support_is_admin() أدناه مُعطَّلة عمداً (ترجع false دائماً) لضمان أن
 *      كل مستخدم — بما فيهم "مدير" — يرى فقط تذاكره الخاصة عبر هذا الملف.
 *   4) المستخدم (أي دور) يرى فقط تذاكره الخاصة ومحادثاتها عبر هذا الملف.
 *
 * Endpoints:
 *   POST /support.php?action=create   — فتح تذكرة جديدة (تسجيل دخول مطلوب)
 *   GET  /support.php?action=list     — تذاكر المستخدم الحالي فقط (لا يوجد عرض إداري هنا)
 *   GET  /support.php?action=messages&ticket_id=X — محادثة تذكرة (تخص المستخدم الحالي فقط)
 *   POST /support.php?action=reply    — إضافة رد من المستخدم على تذكرته الخاصة
 *   POST /support.php?action=update   — 🔒 محظور — مطوّر التطبيق فقط (dev_support.php)
 */

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/_rate_limit.php';

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ── ضمان وجود الجدولين (احتياطي — يجب أن يكونا موجودين مسبقاً) ─────────────────
function support_ensure_tables(): void {
    static $done = false;
    if ($done) return;
    db()->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id SERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            user_email VARCHAR(160) NOT NULL,
            subject VARCHAR(200) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            is_pro_ticket SMALLINT NOT NULL DEFAULT 0,
            tenant_id INTEGER,
            created_at TIMESTAMP NOT NULL DEFAULT now(),
            updated_at TIMESTAMP NOT NULL DEFAULT now()
        );
        CREATE INDEX IF NOT EXISTS idx_support_tickets_user ON support_tickets(user_id);
        CREATE INDEX IF NOT EXISTS idx_support_tickets_status ON support_tickets(status);
        CREATE INDEX IF NOT EXISTS idx_support_tickets_priority ON support_tickets(priority);
        CREATE INDEX IF NOT EXISTS idx_support_tickets_tenant ON support_tickets(tenant_id);

        CREATE TABLE IF NOT EXISTS support_messages (
            id SERIAL PRIMARY KEY,
            ticket_id INT NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
            sender_role VARCHAR(20) NOT NULL,
            sender_email VARCHAR(160),
            message TEXT NOT NULL,
            tenant_id INTEGER,
            created_at TIMESTAMP NOT NULL DEFAULT now()
        );
        CREATE INDEX IF NOT EXISTS idx_support_messages_ticket ON support_messages(ticket_id);
        CREATE INDEX IF NOT EXISTS idx_support_messages_tenant ON support_messages(tenant_id);
    ");
    // ✅ Multi-Tenant: ترقية آمنة إن كانت الجداول موجودة مسبقاً بدون tenant_id
    $upgrades = [
        "ALTER TABLE support_tickets ADD COLUMN IF NOT EXISTS tenant_id INTEGER",
        "ALTER TABLE support_messages ADD COLUMN IF NOT EXISTS tenant_id INTEGER",
    ];
    foreach ($upgrades as $sql) {
        try { db()->exec($sql); } catch (Exception $e) { /* العمود موجود مسبقاً */ }
    }
    $done = true;
}
support_ensure_tables();

// 🔒 مُعطَّلة عمداً: إدارة تذاكر الدعم (رؤية الكل + الرد كإدارة + الإغلاق) هي
// صلاحية منصّة حصرية لمطوّر التطبيق عبر dev_support.php فقط — وليست صلاحية
// "مدير" المتجر مهما كان دوره. لذلك هذه الدالة ترجع false دائماً هنا، مما
// يضمن أن كل طلب عبر support.php (بما فيه من لديه دور "مدير") يُعامَل كمستخدم
// عادي يرى فقط تذاكره الخاصة. لا تُعِد تفعيلها لربط هذا الملف بصلاحية "مدير".
function support_is_admin(array $auth): bool {
    return false;
}

/** يتأكد أن التذكرة تخص المستخدم الحالي (support_is_admin دائماً false هنا) — يرجع سجل التذكرة أو ينهي بخطأ */
function support_load_ticket_for(PDO $pdo, int $ticketId, array $auth, int $tenantId): array {
    $stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ? AND tenant_id = ?');
    $stmt->execute([$ticketId, $tenantId]);
    $ticket = $stmt->fetch();
    if (!$ticket) json_error('التذكرة غير موجودة', 404);
    if (!support_is_admin($auth) && (int)$ticket['user_id'] !== (int)$auth['sub']) {
        json_error('غير مصرح بالوصول لهذه التذكرة', 403);
    }
    return $ticket;
}

switch ($action) {
    // ═════════════════════════════════════════════════════════════════════════
    // POST — فتح تذكرة دعم جديدة
    // ═════════════════════════════════════════════════════════════════════════
    case 'create': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth     = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $userId   = (int)$auth['sub'];
        $email    = (string)$auth['email'];

        // ✅ حماية من الإسبام: 10 تذاكر كحد أقصى كل ساعة لكل مستخدم
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('support_create', $email . '|' . $ip, 10, 3600);

        $body    = input_json();
        $subject = trim((string)($body['subject'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));
        if ($subject === '' || $message === '') {
            json_error('الموضوع والرسالة مطلوبان');
        }
        if (mb_strlen($subject) > 200) $subject = mb_substr($subject, 0, 200);
        if (mb_strlen($message) > 4000) $message = mb_substr($message, 0, 4000);

        // تحقق هل المستخدم Pro لتحديد الأولوية
        $u = $pdo->prepare('SELECT is_pro FROM users WHERE id = ?');
        $u->execute([$userId]);
        $isPro = (int)($u->fetchColumn() ?: 0) === 1;
        $priority = $isPro ? 'high' : 'normal';

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_tickets
                    (user_id, user_email, subject, status, priority, is_pro_ticket, tenant_id)
                 VALUES (?,?,?, \'open\', ?, ?, ?)'
            );
            $ins->execute([$userId, $email, $subject, $priority, $isPro ? 1 : 0, $tenantId]);
            $ticketId = (int)$pdo->lastInsertId();

            $msg = $pdo->prepare(
                'INSERT INTO support_messages (ticket_id, sender_role, sender_email, message, tenant_id)
                 VALUES (?, \'user\', ?, ?, ?)'
            );
            $msg->execute([$ticketId, $email, $message, $tenantId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][support.create] ' . $e->getMessage());
            json_error('تعذّر إنشاء التذكرة', 500);
        }

        audit('فتح تذكرة دعم فني جديدة #' . $ticketId, $email, 'info', $tenantId);
        json_ok([
            'success'   => true,
            'message'   => 'تم استلام تذكرتك، سيتم الرد عليك في أقرب وقت',
            'ticket_id' => $ticketId,
            'priority'  => $priority,
        ]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GET — قائمة التذاكر (يرى المستخدم تذاكره الخاصة فقط دائماً — لا يوجد
    // عرض إداري لكل التذاكر هنا مهما كان دور المستخدم؛ ذلك حصراً عبر
    // dev_support.php لمطوّر التطبيق)
    // ═════════════════════════════════════════════════════════════════════════
    case 'list': {
        $auth     = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        $stmt = $pdo->prepare(
            'SELECT * FROM support_tickets WHERE user_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 200'
        );
        $stmt->execute([(int)$auth['sub'], $tenantId]);

        json_ok(['success' => true, 'tickets' => $stmt->fetchAll()]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GET — محادثة تذكرة محدّدة
    // ═════════════════════════════════════════════════════════════════════════
    case 'messages': {
        $auth     = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        if ($ticketId <= 0) json_error('ticket_id مطلوب');

        $ticket = support_load_ticket_for($pdo, $ticketId, $auth, $tenantId);

        $stmt = $pdo->prepare(
            'SELECT * FROM support_messages WHERE ticket_id = ? AND tenant_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$ticketId, $tenantId]);

        json_ok([
            'success'  => true,
            'ticket'   => $ticket,
            'messages' => $stmt->fetchAll(),
        ]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — إضافة رد على تذكرة (من المستخدم أو من المدير)
    // ═════════════════════════════════════════════════════════════════════════
    case 'reply': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $auth     = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        // ✅ حماية من الإسبام: 30 رداً كحد أقصى كل ساعة لكل مستخدم/آي‌بي
        // (نفس نمط الحماية المطبَّق في action=create وباقي endpoints الحساسة)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        rl_check('support_reply', (string)($auth['email'] ?? $auth['sub']) . '|' . $ip, 30, 3600);

        $body = input_json();
        $ticketId = (int)($body['ticket_id'] ?? 0);
        $message  = trim((string)($body['message'] ?? ''));
        if ($ticketId <= 0) json_error('ticket_id مطلوب');
        if ($message === '') json_error('نص الرسالة مطلوب');
        if (mb_strlen($message) > 4000) $message = mb_substr($message, 0, 4000);

        $ticket  = support_load_ticket_for($pdo, $ticketId, $auth, $tenantId);
        $isAdmin = support_is_admin($auth);

        if ($ticket['status'] === 'closed') {
            json_error('هذه التذكرة مغلقة — لا يمكن إضافة ردود جديدة', 409);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_messages (ticket_id, sender_role, sender_email, message, tenant_id)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $ticketId,
                $isAdmin ? 'admin' : 'user',
                (string)($auth['email'] ?? ''),
                $message,
                $tenantId,
            ]);

            // ✅ حالة التذكرة: رد المدير → answered، رد المستخدم يعيدها إلى open
            $newStatus = $isAdmin ? 'answered' : 'open';
            $pdo->prepare(
                'UPDATE support_tickets SET status = ?, updated_at = now() WHERE id = ? AND tenant_id = ?'
            )->execute([$newStatus, $ticketId, $tenantId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][support.reply] ' . $e->getMessage());
            json_error('تعذّر إضافة الرد', 500);
        }

        audit(($isAdmin ? 'رد المدير على' : 'رد المستخدم على') . " تذكرة دعم #$ticketId", (string)($auth['email'] ?? ''), 'info', $tenantId);
        json_ok(['success' => true, 'message' => 'تم إضافة الرد']);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — تحديث حالة/أولوية تذكرة — 🔒 محظور، صلاحية مطوّر التطبيق حصرياً
    // (استخدم dev_support.php?action=update) — ليست من صلاحية مدير المتجر.
    // ═════════════════════════════════════════════════════════════════════════
    case 'update': {
        json_error(
            'هذا الإجراء متاح فقط عبر لوحة تحكم مطوّر التطبيق — ليس من صلاحية مدير المتجر',
            403
        );
        break;
    }

    default:
        json_error('إجراء غير معروف', 404);
}
