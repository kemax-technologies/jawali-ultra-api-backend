<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $from = $_GET['from'] ?? '';
        $to   = $_GET['to']   ?? '';
        $cat  = $_GET['category'] ?? '';
        $sql  = 'SELECT * FROM expenses WHERE tenant_id = ?';
        $args = [$tenantId];
        if ($from !== '') { $sql .= ' AND date >= ?';     $args[] = $from; }
        if ($to   !== '') { $sql .= ' AND date <= ?';     $args[] = $to;   }
        if ($cat  !== '') { $sql .= ' AND category = ?'; $args[] = $cat;  }
        $sql .= ' ORDER BY date DESC LIMIT 500';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $b = input_json();
        $stmt = $pdo->prepare(
            'INSERT INTO expenses (tenant_id, title, value, category, notes, date) VALUES (?,?,?,?,?,?)'
        );
        $stmt->execute([
            $tenantId,
            $b['title'] ?? $b['name'] ?? 'مصروف',
            (float)($b['value'] ?? 0),
            $b['category'] ?? '',
            $b['notes']    ?? null,
            $b['date']     ?? date('Y-m-d H:i:s'),
        ]);
        audit("create expense value=" . (float)($b['value'] ?? 0), null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
