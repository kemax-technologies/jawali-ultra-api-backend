<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — تصدير نسخة احتياطية JSON من أهم بيانات المشروع (محمي)
 * GET → يُنزّل ملف JSON يحتوي: المستخدمون (بدون كلمات المرور)، الفروع،
 *        المنتجات، العملاء، الإعدادات، حالة التحكم بالتطبيق، آخر 200 فاتورة
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method Not Allowed', 405);

$pdo = db();

$backup = [
    'generated_at' => date('c'),
    'users' => $pdo->query(
        'SELECT id, name, email, role, is_active, branch_code, phone, is_pro, pro_plan,
                pro_expires_at, created_at, last_login_at
         FROM users ORDER BY id'
    )->fetchAll(),
    'branches' => $pdo->query('SELECT * FROM branches ORDER BY id')->fetchAll(),
    'products' => $pdo->query('SELECT * FROM products ORDER BY sku')->fetchAll(),
    'customers' => $pdo->query('SELECT * FROM customers ORDER BY name')->fetchAll(),
    'suppliers' => $pdo->query('SELECT * FROM suppliers ORDER BY id')->fetchAll(),
    'settings' => $pdo->query('SELECT * FROM settings')->fetchAll(),
    'app_control' => $pdo->query('SELECT * FROM app_control WHERE id = 1')->fetch() ?: null,
    'recent_invoices' => $pdo->query(
        'SELECT * FROM invoices ORDER BY date DESC LIMIT 200'
    )->fetchAll(),
];

audit('dev_panel: تصدير نسخة احتياطية JSON', 'developer');

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="jawali_backup_' . date('Y-m-d_His') . '.json"');
echo json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
