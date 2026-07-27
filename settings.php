<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();  // ✅ إصلاح: حماية GET بالمصادقة
        $tenantId = tenant_id_from_auth($auth);
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        json_ok($out);
        break;
    }
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $b = input_json();
        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        // ✅ Multi-Tenant: المفتاح المركب الجديد هو (tenant_id, setting_key)
        $stmt = $pdo->prepare(
            'INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON CONFLICT (tenant_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
        );
        foreach ($b as $k => $v) {
            if (!is_string($k)) continue;
            $stmt->execute([$tenantId, $k, is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)]);
        }
        json_ok(['success' => true, 'updated' => count($b)]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
