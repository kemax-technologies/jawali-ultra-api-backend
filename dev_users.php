<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إدارة المستخدمين (تفعيل/تعطيل/سحب Pro) — محمي
 * POST { action: 'toggle_active'|'revoke_pro'|'delete', user_id }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method Not Allowed', 405);
}

$b = input_json();
$action = $b['action'] ?? '';
$userId = (int)($b['user_id'] ?? 0);

if ($userId <= 0) json_error('user_id مطلوب', 400);

switch ($action) {
    case 'toggle_active': {
        $row = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
        $row->execute([$userId]);
        $cur = $row->fetchColumn();
        if ($cur === false) json_error('المستخدم غير موجود', 404);
        $new = $cur ? 0 : 1;
        $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$new, $userId]);
        audit("dev_panel: toggle_active user#$userId → $new", 'developer');
        json_ok(['success' => true, 'is_active' => (bool)$new]);
        break;
    }
    case 'revoke_pro': {
        $pdo->prepare('UPDATE users SET is_pro = 0 WHERE id = ?')->execute([$userId]);
        audit("dev_panel: revoke_pro user#$userId", 'developer');
        json_ok(['success' => true]);
        break;
    }
    case 'grant_pro': {
        $days = (int)($b['days'] ?? 365);
        $pdo->prepare(
            "UPDATE users SET is_pro = 1, pro_plan = ?, pro_expires_at = now() + (? || ' days')::interval, pro_activated_at = now() WHERE id = ?"
        )->execute([$days >= 300 ? 'yearly' : 'monthly', $days, $userId]);
        audit("dev_panel: grant_pro user#$userId days=$days", 'developer');
        json_ok(['success' => true]);
        break;
    }
    case 'delete': {
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        audit("dev_panel: delete user#$userId", 'developer');
        json_ok(['success' => true]);
        break;
    }
    default:
        json_error('إجراء غير معروف', 400);
}
