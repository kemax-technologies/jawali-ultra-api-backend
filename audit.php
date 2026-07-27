<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST': {
        // ✅ إصلاح #3: فرض require_auth() صراحةً على POST
        $payload  = require_auth();
        $tenantId = tenant_id_from_auth($payload);
        $email    = $payload['email'] ?? null;
        $b        = input_json();
        $action   = trim($b['action'] ?? '');
        if ($action === '') json_error('action required');
        audit($action, $email, 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }
    case 'GET': {
        // ✅ إصلاح #3: حماية GET بالمصادقة + تقييد بدور المدير فقط
        $payload  = require_admin();
        $tenantId = tenant_id_from_auth($payload);
        $limit    = min((int)($_GET['limit'] ?? 100), 1000);
        $stmt     = db()->prepare(
            'SELECT * FROM audit_log WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
