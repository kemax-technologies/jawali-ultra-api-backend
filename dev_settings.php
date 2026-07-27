<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إعدادات النشاط التجاري العامة (محمي)
 * GET  → إرجاع كل الإعدادات كـ key-value
 * POST → تحديث أي عدد من الإعدادات (upsert)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value, updated_at FROM settings ORDER BY setting_key')->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['setting_key']] = $r['setting_value'];
    }
    json_ok(['settings' => $map, 'raw' => $rows]);
}

if ($method === 'POST') {
    $b = input_json();
    if (empty($b) || !is_array($b)) json_error('لا توجد إعدادات للتحديث', 400);

    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value, updated_at)
         VALUES (?, ?, now())
         ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()'
    );
    $updated = [];
    foreach ($b as $key => $value) {
        $key = trim((string)$key);
        if ($key === '') continue;
        $stmt->execute([$key, (string)$value]);
        $updated[] = $key;
    }

    audit('dev_panel: update settings (' . implode(',', $updated) . ')', 'developer');
    json_ok(['success' => true, 'updated' => $updated]);
}

json_error('Method Not Allowed', 405);
