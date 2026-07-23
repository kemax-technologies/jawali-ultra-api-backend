<?php
require_once __DIR__ . '/_db.php';

// ✅ إصلاح جديد: نقطة نهاية للمرتجعات (returns) — كان الجدول موجوداً في قاعدة
//    البيانات بدون أي ملف PHP يخدمه، فكانت المرتجعات تُسجَّل محلياً فقط في
//    التطبيق ولا تصل إلى الخادم أبداً.

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        require_auth();
        $invoiceId = trim($_GET['invoice_id'] ?? $_GET['invoiceId'] ?? '');
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM returns WHERE invoice_id = ? ORDER BY date DESC LIMIT 200'
            );
            $stmt->execute([$invoiceId]);
        } else {
            $stmt = $pdo->query('SELECT * FROM returns ORDER BY date DESC LIMIT 200');
        }
        json_ok($stmt->fetchAll());
        break;
    }
    case 'POST': {
        require_auth();
        $b = input_json();
        $id = trim($b['id'] ?? '');
        if ($id === '') $id = 'RT-' . time();
        $invoiceId = trim($b['invoiceId'] ?? $b['invoice_id'] ?? '');
        $amount    = (float)($b['amount'] ?? 0);
        $reason    = trim($b['reason'] ?? '') ?: 'مرتجع';
        $date      = $b['date'] ?? date('Y-m-d H:i:s');

        if ($invoiceId === '') json_error('رقم الفاتورة مطلوب');

        $stmt = $pdo->prepare(
            'INSERT INTO returns (id, invoice_id, reason, amount, date)
             VALUES (?,?,?,?,?)
             ON CONFLICT (id) DO UPDATE SET
                invoice_id = EXCLUDED.invoice_id,
                reason     = EXCLUDED.reason,
                amount     = EXCLUDED.amount,
                date       = EXCLUDED.date'
        );
        $stmt->execute([$id, $invoiceId, $reason, $amount, $date]);

        // 📦 إعادة المخزون عند تسجيل المرتجع (إن أُرسلت عناصر المرتجع)
        $items = $b['items'] ?? [];
        if (is_array($items)) {
            $up = $pdo->prepare(
                'UPDATE products SET stock = stock + ?, sold = GREATEST(sold - ?, 0)
                 WHERE sku = ? OR name = ?'
            );
            foreach ($items as $it) {
                $sku  = $it['sku']  ?? '';
                $name = $it['name'] ?? '';
                $qty  = (int)($it['qty'] ?? 0);
                if (($sku !== '' || $name !== '') && $qty > 0) {
                    $up->execute([$qty, $qty, $sku, $name]);
                }
            }
        }

        audit("تسجيل مرتجع $id للفاتورة $invoiceId بقيمة $amount");
        json_ok(['success' => true, 'id' => $id]);
        break;
    }
    case 'DELETE': {
        $auth = require_admin();
        $id = trim($_GET['id'] ?? '');
        if ($id === '') json_error('id مطلوب');
        $pdo->prepare('DELETE FROM returns WHERE id = ?')->execute([$id]);
        audit("حذف مرتجع $id", $auth['email'] ?? null);
        json_ok(['success' => true]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
