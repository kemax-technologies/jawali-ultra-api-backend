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
        // 🔧 إصلاح جوهري (فحص شامل — مراجعة ثانية): كان الكود يعتمد على
        // $pdo->lastInsertId() في جملة منفصلة عن INSERT بلا معاملة صريحة
        // تُغلِّفهما معاً. اتصال قاعدة البيانات يمر عبر Supabase PgBouncer في
        // وضع "Transaction Pooler" (منفذ 6543 — انظر _db.php) حيث يُخصَّص
        // اتصال backend فعلي فقط لمدة المعاملة/الجملة الواحدة، ثم يُعاد فوراً
        // إلى المجمّع (pool). بما أن lastInsertId() لـ PostgreSQL يعتمد على
        // lastval() (قيمة الـ sequence في *جلسة* الاتصال الحالية)، فإن وصول
        // جملتي INSERT وSELECT lastval() (استعلام lastInsertId() الداخلي)
        // إلى اتصالي backend مختلفين تحت الحمل المتزامن كان قادراً على إفشال
        // lastval() برسالة "lastval is not yet defined in this session" —
        // استثناء PDOException غير مُعالَج، مطابق تماماً لعطل PostgreSQL
        // boolean-binding التاريخي في warehouses.php (فشل ظاهري للمستخدم رغم
        // نجاح الإدراج فعلياً في القاعدة، مع خطر تكرار المصروف عند إعادة
        // المحاولة لعدم وجود idempotency هنا). الإصلاح الجذري: استخدام
        // "RETURNING id" الخاص بـ PostgreSQL ضمن جملة INSERT نفسها — لا يحتاج
        // استعلاماً ثانياً منفصلاً إطلاقاً، فهو آمن ذرّياً بغضّ النظر عن أي
        // تبديل اتصال backend من PgBouncer.
        $stmt = $pdo->prepare(
            'INSERT INTO expenses (tenant_id, title, value, category, notes, date)
             VALUES (?,?,?,?,?,?) RETURNING id'
        );
        $stmt->execute([
            $tenantId,
            $b['title'] ?? $b['name'] ?? 'مصروف',
            (float)($b['value'] ?? 0),
            $b['category'] ?? '',
            $b['notes']    ?? null,
            $b['date']     ?? date('Y-m-d H:i:s'),
        ]);
        $newId = (int)$stmt->fetchColumn();
        audit("create expense value=" . (float)($b['value'] ?? 0), null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $newId]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
