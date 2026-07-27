<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🎧 Jawali Ultra — نظام الدعم الفني (تذاكر حقيقية + ردود من الإدارة)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * تدفّق العمل:
 *   1) أي مستخدم مسجّل يمكنه فتح تذكرة دعم (موضوع + رسالة أولى).
 *   2) مستخدمو Pro يحصلون تلقائياً على أولوية أعلى (priority = 'high')
 *      وتُعلَّم تذكرتهم is_pro_ticket = 1 لتظهر أولاً للإدارة.
 *   3) المدير (صلاحية "مدير") يرى كل التذاكر ويمكنه الرد أو تغيير الحالة/الأولوية.
 *   4) المستخدم يرى فقط تذاكره الخاصة ومحادثاتها.
 *
 * Endpoints:
 *   POST /support.php?action=create   — فتح تذكرة جديدة (تسجيل دخول مطلوب)
 *   GET  /support.php?action=list     — تذاكر المستخدم (أو الكل إن كان مديراً)
 *   GET  /support.php?action=messages&ticket_id=X — محادثة تذكرة
 *   POST /support.php?action=reply    — إضافة رد على تذكرة
 *   POST /support.php?action=update   — تغيير حالة/أولوية تذكرة (مدير فقط)
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
            created_at TIMESTAMP NOT NULL DEFAULT now(),
            updated_at TIMESTAMP NOT NULL DEFAULT now()
        );
        CREATE INDEX IF NOT EXISTS idx_support_tickets_user ON support_tickets(user_id);
        CREATE INDEX IF NOT EXISTS idx_support_tickets_status ON support_tickets(status);
        CREATE INDEX IF NOT EXISTS idx_support_tickets_priority ON support_tickets(priority);

        CREATE TABLE IF NOT EXISTS support_messages (
            id SERIAL PRIMARY KEY,
            ticket_id INT NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
            sender_role VARCHAR(20) NOT NULL,
            sender_email VARCHAR(160),
            message TEXT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT now()
        );
        CREATE INDEX IF NOT EXISTS idx_support_messages_ticket ON support_messages(ticket_id);
    ");
    $done = true;
}
support_ensure_tables();

function support_is_admin(array $auth): bool {
    return ($auth['role'] ?? '') === 'مدير';
}

/** يتأكد أن التذكرة تخص المستخدم الحالي أو أنه مدير — يرجع سجل التذكرة أو ينهي بخطأ */
function support_load_ticket_for(PDO $pdo, int $ticketId, array $auth): array {
    $stmt = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
    $stmt->execute([$ticketId]);
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
        $auth   = require_auth();
        $userId = (int)$auth['sub'];
        $email  = (string)$auth['email'];

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
                    (user_id, user_email, subject, status, priority, is_pro_ticket)
                 VALUES (?,?,?, \'open\', ?, ?)'
            );
            $ins->execute([$userId, $email, $subject, $priority, $isPro ? 1 : 0]);
            $ticketId = (int)$pdo->lastInsertId();

            $msg = $pdo->prepare(
                'INSERT INTO support_messages (ticket_id, sender_role, sender_email, message)
                 VALUES (?, \'user\', ?, ?)'
            );
            $msg->execute([$ticketId, $email, $message]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][support.create] ' . $e->getMessage());
            json_error('تعذّر إنشاء التذكرة', 500);
        }

        audit('فتح تذكرة دعم فني جديدة #' . $ticketId, $email);
        json_ok([
            'success'   => true,
            'message'   => 'تم استلام تذكرتك، سيتم الرد عليك في أقرب وقت',
            'ticket_id' => $ticketId,
            'priority'  => $priority,
        ]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GET — قائمة التذاكر (المستخدم يرى تذاكره فقط، المدير يرى الكل)
    // ═════════════════════════════════════════════════════════════════════════
    case 'list': {
        $auth = require_auth();

        if (support_is_admin($auth)) {
            $statusFilter = $_GET['status'] ?? '';
            if ($statusFilter !== '' && in_array($statusFilter, ['open', 'answered', 'closed'], true)) {
                $stmt = $pdo->prepare(
                    'SELECT * FROM support_tickets WHERE status = ?
                     ORDER BY is_pro_ticket DESC, id DESC LIMIT 300'
                );
                $stmt->execute([$statusFilter]);
            } else {
                $stmt = $pdo->query(
                    'SELECT * FROM support_tickets
                     ORDER BY is_pro_ticket DESC, id DESC LIMIT 300'
                );
            }
        } else {
            $stmt = $pdo->prepare(
                'SELECT * FROM support_tickets WHERE user_id = ? ORDER BY id DESC LIMIT 200'
            );
            $stmt->execute([(int)$auth['sub']]);
        }

        json_ok(['success' => true, 'tickets' => $stmt->fetchAll()]);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // GET — محادثة تذكرة محدّدة
    // ═════════════════════════════════════════════════════════════════════════
    case 'messages': {
        $auth = require_auth();
        $ticketId = (int)($_GET['ticket_id'] ?? 0);
        if ($ticketId <= 0) json_error('ticket_id مطلوب');

        $ticket = support_load_ticket_for($pdo, $ticketId, $auth);

        $stmt = $pdo->prepare(
            'SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$ticketId]);

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
        $auth = require_auth();

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

        $ticket  = support_load_ticket_for($pdo, $ticketId, $auth);
        $isAdmin = support_is_admin($auth);

        if ($ticket['status'] === 'closed') {
            json_error('هذه التذكرة مغلقة — لا يمكن إضافة ردود جديدة', 409);
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO support_messages (ticket_id, sender_role, sender_email, message)
                 VALUES (?, ?, ?, ?)'
            );
            $ins->execute([
                $ticketId,
                $isAdmin ? 'admin' : 'user',
                (string)($auth['email'] ?? ''),
                $message,
            ]);

            // ✅ حالة التذكرة: رد المدير → answered، رد المستخدم يعيدها إلى open
            $newStatus = $isAdmin ? 'answered' : 'open';
            $pdo->prepare(
                'UPDATE support_tickets SET status = ?, updated_at = now() WHERE id = ?'
            )->execute([$newStatus, $ticketId]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][support.reply] ' . $e->getMessage());
            json_error('تعذّر إضافة الرد', 500);
        }

        audit(($isAdmin ? 'رد المدير على' : 'رد المستخدم على') . " تذكرة دعم #$ticketId", (string)($auth['email'] ?? ''));
        json_ok(['success' => true, 'message' => 'تم إضافة الرد']);
        break;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // POST — تحديث حالة/أولوية تذكرة (مدير فقط) — مثل الإغلاق
    // ═════════════════════════════════════════════════════════════════════════
    case 'update': {
        if ($method !== 'POST') json_error('استخدم POST', 405);
        $admin = require_admin();
        $body = input_json();
        $ticketId = (int)($body['ticket_id'] ?? 0);
        if ($ticketId <= 0) json_error('ticket_id مطلوب');

        $stmt = $pdo->prepare('SELECT id FROM support_tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        if (!$stmt->fetch()) json_error('التذكرة غير موجودة', 404);

        $fields = [];
        $values = [];
        if (isset($body['status']) && in_array($body['status'], ['open', 'answered', 'closed'], true)) {
            $fields[] = 'status = ?';
            $values[] = $body['status'];
        }
        if (isset($body['priority']) && in_array($body['priority'], ['normal', 'high', 'urgent'], true)) {
            $fields[] = 'priority = ?';
            $values[] = $body['priority'];
        }
        if (empty($fields)) json_error('لا توجد تغييرات صالحة لتطبيقها');

        $fields[] = 'updated_at = now()';
        $values[] = $ticketId;
        $pdo->prepare('UPDATE support_tickets SET ' . implode(', ', $fields) . ' WHERE id = ?')
            ->execute($values);

        audit("تحديث تذكرة دعم #$ticketId", $admin['email'] ?? null);
        json_ok(['success' => true, 'message' => 'تم التحديث']);
        break;
    }

    default:
        json_error('إجراء غير معروف', 404);
}
