<?php
require_once __DIR__ . '/_db.php';

// ✅ إصلاح جديد: نقطة نهاية للمرتجعات (returns) — كان الجدول موجوداً في قاعدة
//    البيانات بدون أي ملف PHP يخدمه، فكانت المرتجعات تُسجَّل محلياً فقط في
//    التطبيق ولا تصل إلى الخادم أبداً.

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $invoiceId = trim($_GET['invoice_id'] ?? $_GET['invoiceId'] ?? '');
        if ($invoiceId !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM returns WHERE invoice_id = ? AND tenant_id = ? ORDER BY date DESC LIMIT 200'
            );
            $stmt->execute([$invoiceId, $tenantId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM returns WHERE tenant_id = ? ORDER BY date DESC LIMIT 200');
            $stmt->execute([$tenantId]);
        }
        json_ok($stmt->fetchAll());
        break;
    }
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $b = input_json();
        $id = trim($b['id'] ?? '');
        // 🔧 إصلاح دقة المعرّف (نفس إصلاح invoices.php/purchases.php سابقاً):
        // time() بدقة الثانية يزيد فرص تعارض معرّفات حقيقية بين طلبين
        // متقاربين جداً؛ microtime بدقة الميلي ثانية يقضي على هذا الخطر
        // عملياً. (Flutter يُرسل دوماً معرّفه الخاص بدقة الميلي ثانية، فهذا
        // المسار احتياطي فقط لأي استدعاء مباشر لا يُرسل id).
        if ($id === '') $id = 'RT-' . round(microtime(true) * 1000);
        $invoiceId = trim($b['invoiceId'] ?? $b['invoice_id'] ?? '');
        $amount    = (float)($b['amount'] ?? 0);
        $reason    = trim($b['reason'] ?? '') ?: 'مرتجع';
        $date      = $b['date'] ?? date('Y-m-d H:i:s');

        if ($invoiceId === '') json_error('رقم الفاتورة مطلوب');

        // 🔧 إصلاح جوهري خطير (نفس فئة ثغرة idempotency-on-retry المُصلَحة
        // في invoices.php/purchases.php): نمط ON CONFLICT DO UPDATE أدناه
        // يجعل إدراج/تحديث سجل المرتجع نفسه idempotent بأمان، لكن تأثير
        // "إعادة المخزون" (زيادة stock) بعده كان يُعاد تنفيذه حرفياً في كل
        // استدعاء بلا أي حارس — أي أن إعادة محاولة من طابور الأوفلاين لمرتجع
        // نجح فعلاً على الخادم (فُقدت فقط استجابته) كانت تُضيف الكمية
        // المُرتجعة إلى المخزون *مرتين* لعملية إرجاع واحدة فقط. الإصلاح:
        // التحقق من وجود المرتجع فعلياً في قاعدة البيانات *قبل* أي تأثير
        // على المخزون — إن وُجد مسبقاً (تكرار طلب)، نُعيد نجاحاً idempotent
        // فوراً بلا أي تأثير إضافي.
        $existsStmt = $pdo->prepare('SELECT 1 FROM returns WHERE id = ? AND tenant_id = ? LIMIT 1');
        $existsStmt->execute([$id, $tenantId]);
        $alreadyExists = $existsStmt->fetchColumn() !== false;
        if ($alreadyExists) {
            audit("مرتجع $id (إعادة محاولة متجاهَلة — موجود بالفعل)", null, 'info', $tenantId);
            json_ok(['success' => true, 'id' => $id, 'idempotent' => true]);
            break;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO returns (id, tenant_id, invoice_id, reason, amount, date)
             VALUES (?,?,?,?,?,?)
             ON CONFLICT (id) DO UPDATE SET
                invoice_id = EXCLUDED.invoice_id,
                reason     = EXCLUDED.reason,
                amount     = EXCLUDED.amount,
                date       = EXCLUDED.date
             WHERE returns.tenant_id = EXCLUDED.tenant_id'
        );
        $stmt->execute([$id, $tenantId, $invoiceId, $reason, $amount, $date]);

        // 📦 إعادة المخزون عند تسجيل المرتجع (إن أُرسلت عناصر المرتجع) —
        // يُنفَّذ الآن فقط عند التأكد أعلاه أن هذا مرتجع *جديد فعلاً*.
        $items = $b['items'] ?? [];
        if (is_array($items)) {
            $up = $pdo->prepare(
                'UPDATE products SET stock = stock + ?, sold = GREATEST(sold - ?, 0)
                 WHERE (sku = ? OR name = ?) AND tenant_id = ?'
            );
            foreach ($items as $it) {
                $sku  = $it['sku']  ?? '';
                $name = $it['name'] ?? '';
                $qty  = (int)($it['qty'] ?? 0);
                if (($sku !== '' || $name !== '') && $qty > 0) {
                    $up->execute([$qty, $qty, $sku, $name, $tenantId]);
                }
            }
        }

        audit("تسجيل مرتجع $id للفاتورة $invoiceId بقيمة $amount", null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $id]);
        break;
    }
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = trim($_GET['id'] ?? '');
        if ($id === '') json_error('id مطلوب');
        $stmt = $pdo->prepare('DELETE FROM returns WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        if ($stmt->rowCount() === 0) json_error('المرتجع غير موجود في متجرك', 404);
        audit("حذف مرتجع $id", $auth['email'] ?? null, 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
