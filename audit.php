<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST': {
        // ✅ إصلاح #3: فرض require_auth() صراحةً على POST
        $payload = require_auth();
        $email   = $payload['email'] ?? null;
        $b       = input_json();
        $action  = trim($b['action'] ?? '');
        if ($action === '') json_error('action required');
        audit($action, $email);
        json_ok(['success' => true]);
        break;
    }
    case 'GET': {
        // ✅ إصلاح #3: حماية GET بالمصادقة + تقييد بدور المدير فقط
        $payload = require_admin();
        $limit   = min((int)($_GET['limit'] ?? 100), 1000);
        $stmt    = db()->prepare(
            'SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute();
        json_ok($stmt->fetchAll());
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
