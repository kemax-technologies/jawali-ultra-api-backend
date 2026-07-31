<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — عرض والرد على تذاكر الدعم الفني (محمي)
 * GET  ?action=list[&status=]        → قائمة التذاكر
 * GET  ?action=messages&ticket_id=X  → محادثة تذكرة
 * POST { action:'reply', ticket_id, message }              → رد كمطوّر
 * POST { action:'update', ticket_id, status?, priority? }  → تحديث حالة/أولوية
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($method === 'POST' ? (input_json()['action'] ?? '') : '');

if ($method === 'GET' && $action === 'list') {
    $status = $_GET['status'] ?? '';
    if ($status !== '' && in_array($status, ['open', 'answered', 'closed'], true)) {
        $stmt = $pdo->prepare(
            'SELECT * FROM support_tickets WHERE status = ? ORDER BY is_pro_ticket DESC, id DESC LIMIT 300'
        );
        $stmt->execute([$status]);
    } else {
        $stmt = $pdo->query('SELECT * FROM support_tickets ORDER BY is_pro_ticket DESC, id DESC LIMIT 300');
    }
    json_ok(['tickets' => $stmt->fetchAll()]);
}

if ($method === 'GET' && $action === 'messages') {
    $ticketId = (int)($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_error('ticket_id مطلوب');
    $t = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
    $t->execute([$ticketId]);
    $ticket = $t->fetch();
    if (!$ticket) json_error('التذكرة غير موجودة', 404);

    $m = $pdo->prepare('SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY id ASC');
    $m->execute([$ticketId]);
    json_ok(['ticket' => $ticket, 'messages' => $m->fetchAll()]);
}

if ($method === 'POST') {
    $b = input_json();
    $ticketId = (int)($b['ticket_id'] ?? 0);
    if ($ticketId <= 0) json_error('ticket_id مطلوب');

    // ✅ إصلاح: جلب tenant_id الخاص بالتذكرة المستهدفة مرة واحدة — يُستخدم
    // في تسجيل audit بمتجرها الصحيح لكل من 'reply' و 'update' أدناه.
    $ticketTenantRow = $pdo->prepare('SELECT tenant_id FROM support_tickets WHERE id = ?');
    $ticketTenantRow->execute([$ticketId]);
    $ticketTenantId = $ticketTenantRow->fetchColumn();
    $ticketTenantId = $ticketTenantId !== false ? ((int)$ticketTenantId ?: null) : null;

    if ($action === 'reply') {
        $message = trim((string)($b['message'] ?? ''));
        if ($message === '') json_error('نص الرد مطلوب');
        if (mb_strlen($message) > 4000) $message = mb_substr($message, 0, 4000);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO support_messages (ticket_id, sender_role, sender_email, message)
                 VALUES (?, 'developer', 'developer', ?)"
            )->execute([$ticketId, $message]);
            $pdo->prepare("UPDATE support_tickets SET status = 'answered', updated_at = now() WHERE id = ?")
                ->execute([$ticketId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            json_error('تعذّر إرسال الرد', 500);
        }

        audit("dev_panel: رد على تذكرة دعم #$ticketId", 'developer', 'info', $ticketTenantId);
        json_ok(['success' => true]);
    }

    if ($action === 'update') {
        $sets = [];
        $params = [];
        if (isset($b['status']) && in_array($b['status'], ['open', 'answered', 'closed'], true)) {
            $sets[] = 'status = ?';
            $params[] = $b['status'];
        }
        if (isset($b['priority']) && in_array($b['priority'], ['normal', 'high', 'urgent'], true)) {
            $sets[] = 'priority = ?';
            $params[] = $b['priority'];
        }
        if (empty($sets)) json_error('لا توجد حقول للتحديث', 400);

        $sets[] = 'updated_at = now()';
        $params[] = $ticketId;
        $pdo->prepare('UPDATE support_tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        audit("dev_panel: تحديث تذكرة دعم #$ticketId", 'developer', 'info', $ticketTenantId);
        json_ok(['success' => true]);
    }
}

json_error('طلب غير صالح', 400);
