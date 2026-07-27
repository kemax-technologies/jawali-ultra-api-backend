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

// ── إنشاء مستخدم جديد (لا يحتاج user_id) ────────────────────────────────────
if ($action === 'create') {
    $name  = trim((string)($b['name'] ?? ''));
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $pass  = (string)($b['password'] ?? '');
    $role  = in_array($b['role'] ?? '', ['مدير', 'كاشير', 'موظف'], true) ? $b['role'] : 'كاشير';
    $branch = strtoupper(trim((string)($b['branch_code'] ?? 'MAIN')));

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('الاسم والبريد الإلكتروني الصحيح مطلوبان');
    }
    if (strlen($pass) < 6) json_error('كلمة المرور يجب أن تكون 6 أحرف على الأقل');

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    try {
        $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, branch_code, is_active)
             VALUES (?,?,?,?,?,1)'
        )->execute([$name, $email, $hash, $role, $branch]);
    } catch (Exception $e) {
        json_error('تعذّر إنشاء المستخدم — قد يكون البريد مستخدماً مسبقاً', 400);
    }

    audit("dev_panel: create user $email (role=$role)", 'developer');
    json_ok(['success' => true]);
}

$userId = (int)($b['user_id'] ?? 0);

if ($userId <= 0) json_error('user_id مطلوب', 400);

switch ($action) {
    case 'reset_password': {
        $pass = (string)($b['password'] ?? '');
        if (strlen($pass) < 6) json_error('كلمة المرور يجب أن تكون 6 أحرف على الأقل');
        $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
        audit("dev_panel: reset_password user#$userId", 'developer');
        json_ok(['success' => true]);
        break;
    }
    case 'change_role': {
        $role = $b['role'] ?? '';
        if (!in_array($role, ['مدير', 'كاشير', 'موظف'], true)) json_error('دور غير صالح', 400);
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
        audit("dev_panel: change_role user#$userId → $role", 'developer');
        json_ok(['success' => true]);
        break;
    }
    case 'change_branch': {
        $code = strtoupper(trim((string)($b['branch_code'] ?? '')));
        if ($code === '') json_error('رمز الفرع مطلوب', 400);
        $exists = $pdo->prepare('SELECT 1 FROM branches WHERE code = ?');
        $exists->execute([$code]);
        if (!$exists->fetch()) json_error('الفرع غير موجود', 404);
        $pdo->prepare('UPDATE users SET branch_code = ? WHERE id = ?')->execute([$code, $userId]);
        audit("dev_panel: change_branch user#$userId → $code", 'developer');
        json_ok(['success' => true]);
        break;
    }
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
