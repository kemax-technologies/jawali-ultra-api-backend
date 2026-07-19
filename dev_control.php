<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — تحديث حالة التحكم بالتطبيق (محمي بمصادقة المطوّر)
 * GET  → إرجاع الحالة الحالية
 * POST → تحديث أي من الحقول المرسلة فقط
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $row = $pdo->query('SELECT * FROM app_control WHERE id = 1')->fetch();
    json_ok($row ?: []);
}

if ($method === 'POST') {
    $b = input_json();
    $fields = [
        'maintenance_mode'     => 'int',
        'maintenance_message'  => 'str',
        'force_update'         => 'int',
        'min_supported_build'  => 'int',
        'latest_build'         => 'int',
        'latest_apk_url'       => 'str',
        'announcement_title'   => 'str',
        'announcement_body'    => 'str',
        'announcement_active'  => 'int',
    ];

    $sets = [];
    $params = [];
    foreach ($fields as $col => $type) {
        if (!array_key_exists($col, $b)) continue;
        $sets[] = "$col = ?";
        $params[] = $type === 'int' ? (int)$b[$col] : (string)$b[$col];
    }

    if (empty($sets)) {
        json_error('لا توجد حقول للتحديث', 400);
    }

    $sets[] = 'updated_at = now()';
    $sql = 'UPDATE app_control SET ' . implode(', ', $sets) . ' WHERE id = 1';
    $pdo->prepare($sql)->execute($params);

    audit('dev_panel_control_update: ' . implode(',', array_keys($b)), 'developer');

    $row = $pdo->query('SELECT * FROM app_control WHERE id = 1')->fetch();
    json_ok($row);
}

json_error('Method Not Allowed', 405);
