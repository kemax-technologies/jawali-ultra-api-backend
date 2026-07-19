<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        require_auth();  // ✅ إصلاح: حماية GET بالمصادقة
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        json_ok($out);
        break;
    }
    case 'POST': {
        require_auth();
        $b = input_json();
        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
        );
        foreach ($b as $k => $v) {
            if (!is_string($k)) continue;
            $stmt->execute([$k, is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)]);
        }
        json_ok(['success' => true, 'updated' => count($b)]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
