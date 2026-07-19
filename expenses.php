<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        require_auth();
        $from = $_GET['from'] ?? '';
        $to   = $_GET['to']   ?? '';
        $cat  = $_GET['category'] ?? '';
        $sql  = 'SELECT * FROM expenses WHERE 1=1';
        $args = [];
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
        require_auth();
        $b = input_json();
        $stmt = $pdo->prepare(
            'INSERT INTO expenses (title, value, category, notes, date) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([
            $b['title'] ?? $b['name'] ?? 'مصروف',
            (float)($b['value'] ?? 0),
            $b['category'] ?? '',
            $b['notes']    ?? null,
            $b['date']     ?? date('Y-m-d H:i:s'),
        ]);
        json_ok(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
