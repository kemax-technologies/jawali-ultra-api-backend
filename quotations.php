<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 📋 Jawali Ultra — API عروض الأسعار وطلبات الشراء (المرحلة 8 من إعادة التصميم)
 * type=sale  → عرض سعر يُرسل للعميل، يمكن تحويله لاحقاً إلى فاتورة بيع
 * type=purchase → طلب شراء يُرسل للمورد (سجل تفاوضي، لا يؤثر على المخزون مباشرة)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /quotations.php                       — قائمة كل عروض الأسعار/الطلبات
 *   GET    /quotations.php?type=sale             — عروض أسعار البيع فقط
 *   GET    /quotations.php?type=purchase         — طلبات الشراء فقط
 *   GET    /quotations.php?status=sent           — تصفية بالحالة
 *   GET    /quotations.php?id=Q-XXX              — عرض/طلب محدد
 *   POST   /quotations.php                       — إنشاء عرض سعر / طلب شراء جديد
 *   POST   /quotations.php?action=status         — تحديث الحالة (sent/accepted/rejected/expired)
 *   POST   /quotations.php?action=convert        — تحويل عرض بيع مقبول إلى فاتورة بيع فعلية
 *   DELETE /quotations.php?id=Q-XXX              — حذف عرض (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    // ─────────────────────────────────────────────────────────────────────────
    // GET
    // ─────────────────────────────────────────────────────────────────────────
    case 'GET': {
        require_auth();

        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare('SELECT * FROM quotations WHERE id = ? LIMIT 1');
            $stmt->execute([$_GET['id']]);
            $row = $stmt->fetch();
            if ($row) $row['items'] = json_decode($row['items_json'] ?? '[]', true) ?: [];
            json_ok($row ?: []);
        }

        $sql  = 'SELECT * FROM quotations WHERE 1=1';
        $args = [];
        if (!empty($_GET['type'])) {
            $sql .= ' AND type = ?';
            $args[] = $_GET['type'];
        }
        if (!empty($_GET['status'])) {
            $sql .= ' AND status = ?';
            $args[] = $_GET['status'];
        }
        $sql .= ' ORDER BY date DESC LIMIT 1000';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['items'] = json_decode($r['items_json'] ?? '[]', true) ?: [];
        }
        unset($r);
        json_ok($rows);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST
    // ─────────────────────────────────────────────────────────────────────────
    case 'POST': {
        require_auth();
        $action = $_GET['action'] ?? '';
        $body   = input_json();

        // ── تحديث الحالة ─────────────────────────────────────────────────────
        if ($action === 'status') {
            $id = trim($body['id'] ?? '');
            $status = $body['status'] ?? '';
            $allowed = ['draft', 'sent', 'accepted', 'rejected', 'converted', 'expired'];
            if ($id === '' || !in_array($status, $allowed, true)) {
                json_error('id و status صحيحان مطلوبان');
            }
            $pdo->prepare('UPDATE quotations SET status = ? WHERE id = ?')->execute([$status, $id]);
            audit("update quotation $id status=$status");
            json_ok(['success' => true]);
            break;
        }

        // ── تحويل عرض بيع مقبول إلى فاتورة ───────────────────────────────────
        if ($action === 'convert') {
            $id = trim($body['id'] ?? '');
            if ($id === '') json_error('id مطلوب');

            $stmt = $pdo->prepare('SELECT * FROM quotations WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $q = $stmt->fetch();
            if (!$q) json_error('عرض السعر غير موجود', 404);
            if ($q['type'] !== 'sale') json_error('لا يمكن تحويل طلب شراء إلى فاتورة');
            if ($q['status'] === 'converted') json_error('تم تحويل هذا العرض مسبقاً');

            $invoiceId = 'INV-' . round(microtime(true) * 1000);
            try {
                $pdo->beginTransaction();
                $pdo->prepare(
                    'INSERT INTO invoices
                       (id, customer_phone, user_email, date, subtotal, discount, tax, total,
                        payment_method, status, items_json)
                     VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $invoiceId, $q['party_phone'], $_SERVER['HTTP_X_USER_EMAIL'] ?? '',
                    $q['subtotal'], $q['discount'], $q['tax'], $q['total'],
                    'نقدي', 'مكتملة', $q['items_json'],
                ]);
                $pdo->prepare(
                    "UPDATE quotations SET status = 'converted', converted_invoice_id = ? WHERE id = ?"
                )->execute([$invoiceId, $id]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][quotations] فشل التحويل: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }

            audit("convert quotation $id to invoice $invoiceId");
            json_ok(['success' => true, 'invoice_id' => $invoiceId]);
            break;
        }

        // ── إنشاء عرض سعر / طلب شراء جديد ────────────────────────────────────
        $type = $body['type'] ?? 'sale';
        if (!in_array($type, ['sale', 'purchase'], true)) {
            json_error('type يجب أن يكون sale أو purchase');
        }
        $partyName  = trim($body['party_name']  ?? $body['partyName']  ?? '');
        $partyPhone = trim($body['party_phone'] ?? $body['partyPhone'] ?? '');
        $items      = $body['items'] ?? [];
        $subtotal   = (float)($body['subtotal'] ?? 0);
        $discount   = (float)($body['discount'] ?? 0);
        $tax        = (float)($body['tax'] ?? 0);
        $total      = (float)($body['total'] ?? ($subtotal - $discount + $tax));
        $currency   = strtoupper($body['currency'] ?? 'YER');
        $validUntil = $body['valid_until'] ?? $body['validUntil'] ?? null;
        $notes      = $body['notes'] ?? '';

        if ($partyName === '') json_error('party_name مطلوب');
        if (!is_array($items) || count($items) === 0) json_error('يجب إضافة عنصر واحد على الأقل');

        $id = $body['id'] ?? ('Q-' . round(microtime(true) * 1000));
        $quoteNumber = $body['quote_number'] ?? $body['quoteNumber']
            ?? (($type === 'sale') ? 'QT-' : 'PR-') . substr($id, -6);

        $stmt = $pdo->prepare(
            'INSERT INTO quotations
               (id, quote_number, type, party_name, party_phone, items_json,
                subtotal, discount, tax, total, currency, valid_until, status, notes, date, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\')::date, \'draft\', ?, NOW(), ?)'
        );
        $stmt->execute([
            $id, $quoteNumber, $type, $partyName, $partyPhone,
            json_encode($items, JSON_UNESCAPED_UNICODE),
            $subtotal, $discount, $tax, $total, $currency, $validUntil, $notes,
            $_SERVER['HTTP_X_USER_EMAIL'] ?? '',
        ]);

        audit("create quotation $id type=$type total=$total party=$partyName");
        json_ok(['success' => true, 'id' => $id, 'quote_number' => $quoteNumber]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        require_admin();
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $pdo->prepare('DELETE FROM quotations WHERE id = ?')->execute([$id]);
        audit("delete quotation $id", null, 'warning');
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
