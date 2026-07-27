<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🧾 Jawali Ultra — API الفواتير
 * ─────────────────────────────────────────────────────────────────────────────
 * Endpoints:
 *   GET    /invoices.php                    — قائمة الفواتير (مع فلترة تاريخ)
 *   GET    /invoices.php?id=INV-XXX         — فاتورة محدّدة
 *   POST   /invoices.php                    — إنشاء/تحديث فاتورة (upsert)
 *   POST   /invoices.php?action=cancel&id=… — إلغاء فاتورة (يتطلب صلاحية
 *          cancelInvoice) — يعكس أثرها على المخزون/الصندوق دون حذف السجل
 *   DELETE /invoices.php?id=INV-XXX         — حذف نهائي (يتطلب صلاحية
 *          deleteInvoice) — يُفضَّل تأكيد كلمة مرور مدير على العميل قبل
 *          الاستدعاء عبر auth.php?action=confirm_admin
 */
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $id    = $_GET['id']    ?? '';
        $from  = $_GET['from']  ?? '';
        $to    = $_GET['to']    ?? '';
        $limit = (int)($_GET['limit'] ?? 200);
        if ($id !== '') {
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? AND id = ? LIMIT 1');
            $stmt->execute([$tenantId, $id]);
            $inv = $stmt->fetch();
            if ($inv && !empty($inv['items_json'])) {
                $inv['items'] = json_decode($inv['items_json'], true) ?: [];
            }
            json_ok($inv ?: []);
        }
        $sql  = 'SELECT * FROM invoices WHERE tenant_id = ?';
        $args = [$tenantId];
        if ($from !== '') { $sql .= ' AND date >= ?'; $args[] = $from; }
        if ($to   !== '') { $sql .= ' AND date <= ?'; $args[] = $to;   }
        $sql .= ' ORDER BY date DESC LIMIT ' . max(1, $limit);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['items_json'])) {
                $r['items'] = json_decode($r['items_json'], true) ?: [];
            }
        }
        json_ok($rows);
        break;
    }

    case 'POST': {
        $action = $_GET['action'] ?? '';

        // ─────────────────────────────────────────────────────────────────
        // POST ?action=cancel — إلغاء فاتورة (بدون حذف السجل) + عكس أثرها
        // على المخزون والصندوق النقدي. يتطلب صلاحية "cancelInvoice" الدقيقة.
        // ─────────────────────────────────────────────────────────────────
        if ($action === 'cancel') {
            $auth = require_permission('cancelInvoice');
            $tenantId = tenant_id_from_auth($auth);
            $id = trim($_GET['id'] ?? (input_json()['id'] ?? ''));
            if ($id === '') json_error('id مطلوب');

            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? AND id = ? LIMIT 1');
            $stmt->execute([$tenantId, $id]);
            $inv = $stmt->fetch();
            if (!$inv) json_error('الفاتورة غير موجودة', 404);
            if (($inv['status'] ?? '') === 'ملغاة') {
                json_error('الفاتورة ملغاة مسبقاً');
            }

            $pdo->beginTransaction();
            try {
                _reverse_invoice_effects($pdo, $inv, $auth['email'] ?? null, $tenantId);
                $pdo->prepare("UPDATE invoices SET status = 'ملغاة' WHERE tenant_id = ? AND id = ?")->execute([$tenantId, $id]);
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][invoices] فشل إلغاء الفاتورة: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }
            audit("إلغاء فاتورة $id", $auth['email'] ?? null, 'info', $tenantId);
            json_ok(['success' => true, 'id' => $id, 'status' => 'ملغاة']);
            break;
        }

        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $body = input_json();
        $id   = trim($body['id'] ?? '');
        if ($id === '') $id = 'INV-' . time();

        $items = $body['items'] ?? [];
        if (!is_array($items)) $items = [];

        // 🏬 المرحلة 11: ربط الفاتورة بمخزن (لخصم المخزون منه) وصندوق نقدي
        // (لزيادة رصيده تلقائياً عند البيع النقدي) — كلاهما اختياري.
        $warehouseId   = trim($body['warehouse_id']    ?? $body['warehouseId']    ?? '');
        $cashAccountId = trim($body['cash_account_id'] ?? $body['cashAccountId'] ?? '');
        $paymentMethod = $body['payment_method'] ?? $body['paymentMethod'] ?? 'نقدي';
        $total         = (float)($body['total'] ?? 0);

        $pdo->beginTransaction();
        try {
            // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
            // ✅ Multi-Tenant: tenant_id يُدرج في كل فاتورة جديدة؛ ON CONFLICT يبقى
            // على (id) لأن معرّف الفاتورة (INV-timestamp) غير قابل للتصادم عملياً
            // بين المتاجر، لكن نُبقي شرط tenant_id في كل التحديثات اللاحقة للأمان.
            $stmt = $pdo->prepare(
                'INSERT INTO invoices
                 (id, tenant_id, customer_phone, user_email, date, subtotal, discount, tax, total, payment_method, status, items_json, warehouse_id, cash_account_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULLIF(?, \'\'),NULLIF(?, \'\'))
                 ON CONFLICT (id) DO UPDATE SET
                    customer_phone = EXCLUDED.customer_phone,
                    subtotal = EXCLUDED.subtotal, discount = EXCLUDED.discount,
                    tax = EXCLUDED.tax, total = EXCLUDED.total,
                    payment_method = EXCLUDED.payment_method, status = EXCLUDED.status,
                    items_json = EXCLUDED.items_json,
                    warehouse_id = EXCLUDED.warehouse_id,
                    cash_account_id = EXCLUDED.cash_account_id
                 WHERE invoices.tenant_id = EXCLUDED.tenant_id'
            );
            $stmt->execute([
                $id,
                $tenantId,
                $body['customer_phone'] ?? null,
                $body['user_email']     ?? null,
                $body['date'] ?? date('Y-m-d H:i:s'),
                (float)($body['subtotal'] ?? 0),
                (float)($body['discount'] ?? 0),
                (float)($body['tax']      ?? 0),
                $total,
                $paymentMethod,
                $body['status'] ?? 'مدفوعة',
                json_encode($items, JSON_UNESCAPED_UNICODE),
                $warehouseId,
                $cashAccountId,
            ]);

            // تحديث بنود الفاتورة وخصم المخزون (المخزون العام + مخزون المخزن المحدد إن وُجد)
            // ✅ Multi-Tenant: كل تحديث على products/warehouse_stock مقيّد بـ tenant_id
            // لأن sku لم يعد فريداً إلا ضمن نطاق المتجر الواحد (tenant_id, sku)
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $insItem  = $pdo->prepare('INSERT INTO invoice_items (invoice_id, product_sku, name, price, qty, line_total, unit_type, unit_label, pack_factor, base_qty) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $decStock = $pdo->prepare('UPDATE products SET stock = GREATEST(0, stock - ?), sold = sold + ? WHERE tenant_id = ? AND sku = ?');
            $decWhStock = $pdo->prepare(
                'UPDATE warehouse_stock SET stock = GREATEST(0, stock - ?) WHERE tenant_id = ? AND warehouse_id = ? AND product_sku = ?'
            );
            foreach ($items as $it) {
                $sku      = $it['sku']   ?? null;
                $name     = $it['name']  ?? '';
                $price    = (float)($it['price'] ?? 0);
                $qty      = (int)  ($it['qty']   ?? 1);
                $baseQty  = (int)  ($it['base_qty'] ?? $it['baseQty'] ?? $qty);
                $insItem->execute([
                    $id, $sku, $name, $price, $qty, $price * $qty,
                    $it['unit_type']   ?? 'piece',
                    $it['unit_label']  ?? 'قطعة',
                    (int)($it['pack_factor'] ?? 1),
                    $baseQty,
                ]);
                if ($sku) {
                    $decStock->execute([$baseQty, $baseQty, $tenantId, $sku]);
                    if ($warehouseId !== '') {
                        $decWhStock->execute([$baseQty, $tenantId, $warehouseId, $sku]);
                    }
                }
            }

            // 💰 المرحلة 11: إذا كانت الفاتورة نقدية ومرتبطة بصندوق، أضف المبلغ
            // تلقائياً لرصيد الصندوق + سجّل حركة صندوق (البيع الآجل لا يُضاف هنا
            // لأنه يُدار عبر نظام الذمم/credits بشكل منفصل)
            if ($cashAccountId !== '' && $paymentMethod !== 'آجل' && $total > 0) {
                $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE tenant_id = ? AND id = ? LIMIT 1');
                $accStmt->execute([$tenantId, $cashAccountId]);
                $acc = $accStmt->fetch();
                if ($acc) {
                    $pdo->prepare('UPDATE cash_accounts SET balance = balance + ? WHERE tenant_id = ? AND id = ?')
                        ->execute([$total, $tenantId, $cashAccountId]);
                    $txId = 'TX-' . round(microtime(true) * 1000);
                    $pdo->prepare(
                        'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $txId, $tenantId, $cashAccountId, 'مبيعات نقطة البيع', $total, $acc['currency'],
                        "فاتورة $id", $_SERVER['HTTP_X_USER_EMAIL'] ?? null,
                    ]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][invoices] فشل حفظ الفاتورة: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }
        audit("invoice $id");
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE ?id=XXX — حذف نهائي لسجل الفاتورة (وبنودها تلقائياً عبر CASCADE)
    // + عكس أثرها على المخزون/الصندوق إن لم تكن قد أُلغيت مسبقاً بالفعل.
    // يتطلب صلاحية "deleteInvoice" الدقيقة — عملية حساسة يجب أن يُسبقها على
    // العميل تأكيد كلمة مرور مدير عبر auth.php?action=confirm_admin.
    // ─────────────────────────────────────────────────────────────────────────
    case 'DELETE': {
        $auth = require_permission('deleteInvoice');
        $tenantId = tenant_id_from_auth($auth);
        $id = trim($_GET['id'] ?? '');
        if ($id === '') json_error('id مطلوب');

        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? AND id = ? LIMIT 1');
        $stmt->execute([$tenantId, $id]);
        $inv = $stmt->fetch();
        if (!$inv) json_error('الفاتورة غير موجودة', 404);

        $pdo->beginTransaction();
        try {
            // لا نعكس الأثر مرتين إن كانت الفاتورة مُلغاة مسبقاً (عُكس أثرها
            // بالفعل عند الإلغاء) — نعكسه فقط إن كانت لا تزال "فعّالة"
            if (($inv['status'] ?? '') !== 'ملغاة') {
                _reverse_invoice_effects($pdo, $inv, $auth['email'] ?? null, $tenantId);
            }
            $pdo->prepare('DELETE FROM invoices WHERE tenant_id = ? AND id = ?')->execute([$tenantId, $id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('[Jawali][invoices] فشل حذف الفاتورة: ' . $e->getMessage());
            json_error('خطأ داخلي في الخادم', 500);
        }
        audit("حذف فاتورة $id", $auth['email'] ?? null, 'warning', $tenantId);
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}

/**
 * يعكس أثر فاتورة على المخزون (استرجاع الكميات المخصومة) والصندوق النقدي
 * (استرجاع المبلغ المضاف + تسجيل حركة عكسية) — تُستخدم من مسارَي الإلغاء
 * (POST ?action=cancel) والحذف (DELETE) لتجنّب تكرار الكود.
 */
function _reverse_invoice_effects(PDO $pdo, array $inv, ?string $byEmail, int $tenantId): void {
    $id           = $inv['id'];
    $warehouseId  = $inv['warehouse_id']    ?? '';
    $cashAccountId = $inv['cash_account_id'] ?? '';
    $paymentMethod = $inv['payment_method']  ?? '';
    $total         = (float)($inv['total']   ?? 0);

    // استرجاع الكميات إلى المخزون (العام + مخزن محدد إن وُجد)
    // ✅ Multi-Tenant: كل تحديث مقيّد بـ tenant_id لأن sku فريد فقط ضمن المتجر
    $itemsStmt = $pdo->prepare('SELECT product_sku, base_qty, qty FROM invoice_items WHERE invoice_id = ?');
    $itemsStmt->execute([$id]);
    $incStock   = $pdo->prepare('UPDATE products SET stock = stock + ?, sold = GREATEST(0, sold - ?) WHERE tenant_id = ? AND sku = ?');
    $incWhStock = $pdo->prepare('UPDATE warehouse_stock SET stock = stock + ? WHERE tenant_id = ? AND warehouse_id = ? AND product_sku = ?');
    foreach ($itemsStmt->fetchAll() as $it) {
        $sku     = $it['product_sku'] ?? null;
        $baseQty = (int)($it['base_qty'] ?? $it['qty'] ?? 0);
        if (!$sku || $baseQty <= 0) continue;
        $incStock->execute([$baseQty, $baseQty, $tenantId, $sku]);
        if ($warehouseId !== '') {
            $incWhStock->execute([$baseQty, $tenantId, $warehouseId, $sku]);
        }
    }

    // استرجاع رصيد الصندوق النقدي (عكس تماماً لما حدث عند إنشاء الفاتورة)
    if ($cashAccountId !== '' && $paymentMethod !== 'آجل' && $total > 0) {
        $accStmt = $pdo->prepare('SELECT * FROM cash_accounts WHERE tenant_id = ? AND id = ? LIMIT 1');
        $accStmt->execute([$tenantId, $cashAccountId]);
        $acc = $accStmt->fetch();
        if ($acc) {
            $pdo->prepare('UPDATE cash_accounts SET balance = balance - ? WHERE tenant_id = ? AND id = ?')
                ->execute([$total, $tenantId, $cashAccountId]);
            $txId = 'TX-' . round(microtime(true) * 1000);
            $pdo->prepare(
                'INSERT INTO cash_transactions (id, tenant_id, account_id, type, amount, currency, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $txId, $tenantId, $cashAccountId, 'عكس فاتورة (إلغاء/حذف)', -$total, $acc['currency'],
                "عكس أثر فاتورة $id", $byEmail,
            ]);
        }
    }
}
