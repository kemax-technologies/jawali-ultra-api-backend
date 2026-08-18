<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🏬 Jawali Ultra — API تعدد المخازن (المرحلة 10 من إعادة التصميم)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /warehouses.php                              — قائمة المخازن
 *   GET    /warehouses.php?stock=1&warehouse_id=WH-XXX  — مخزون مخزن محدد
 *   GET    /warehouses.php?transfers=1                  — كل تحويلات المخزون بين المخازن
 *   POST   /warehouses.php                              — إنشاء/تحديث مخزن
 *   POST   /warehouses.php?action=transfer              — تحويل كمية منتج بين مخزنين
 *   DELETE /warehouses.php?id=WH-XXX                    — تعطيل مخزن (مدير فقط)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        if (isset($_GET['stock'])) {
            $warehouseId = $_GET['warehouse_id'] ?? '';
            $sql  = 'SELECT * FROM warehouse_stock WHERE tenant_id = ?';
            $args = [$tenantId];
            if ($warehouseId !== '') {
                $sql .= ' AND warehouse_id = ?';
                $args[] = $warehouseId;
            }
            $sql .= ' ORDER BY product_sku ASC LIMIT 2000';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            json_ok($stmt->fetchAll());
        }

        if (isset($_GET['transfers'])) {
            $stmt = $pdo->prepare(
                'SELECT * FROM warehouse_transfers WHERE tenant_id = ? ORDER BY date DESC LIMIT 500'
            );
            $stmt->execute([$tenantId]);
            json_ok($stmt->fetchAll());
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM warehouses WHERE is_active = TRUE AND tenant_id = ? ORDER BY is_default DESC, created_at ASC'
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $action = $_GET['action'] ?? '';
        $body   = input_json();

        // ── تحويل كمية بين مخزنين ────────────────────────────────────────────
        if ($action === 'transfer') {
            $fromId = trim($body['from_warehouse_id'] ?? $body['fromWarehouseId'] ?? '');
            $toId   = trim($body['to_warehouse_id']   ?? $body['toWarehouseId']   ?? '');
            $sku    = trim($body['product_sku']       ?? $body['productSku']     ?? '');
            $qty    = (int)($body['qty'] ?? 0);
            $notes  = $body['notes'] ?? '';

            if ($fromId === '' || $toId === '' || $sku === '' || $qty <= 0) {
                json_error('from_warehouse_id و to_warehouse_id و product_sku و qty مطلوبة');
            }
            if ($fromId === $toId) json_error('لا يمكن التحويل لنفس المخزن');

            try {
                $pdo->beginTransaction();

                $fromStock = $pdo->prepare(
                    'SELECT * FROM warehouse_stock WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ? LIMIT 1'
                );
                $fromStock->execute([$fromId, $sku, $tenantId]);
                $from = $fromStock->fetch();
                $availableQty = $from ? (int)$from['stock'] : 0;
                if ($availableQty < $qty) {
                    $pdo->rollBack();
                    json_error('الكمية غير كافية في المخزن المصدر');
                }

                $pdo->prepare(
                    'UPDATE warehouse_stock SET stock = stock - ? WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ?'
                )->execute([$qty, $fromId, $sku, $tenantId]);

                $pdo->prepare(
                    'INSERT INTO warehouse_stock (id, warehouse_id, product_sku, stock, tenant_id)
                     VALUES (?, ?, ?, ?, ?)
                     ON CONFLICT (warehouse_id, product_sku)
                     DO UPDATE SET stock = warehouse_stock.stock + EXCLUDED.stock
                     WHERE warehouse_stock.tenant_id = EXCLUDED.tenant_id'
                )->execute(['WS-' . round(microtime(true) * 1000), $toId, $sku, $qty, $tenantId]);

                $txId = 'WT-' . round(microtime(true) * 1000);
                $byEmail = $_SERVER['HTTP_X_USER_EMAIL'] ?? null;
                $pdo->prepare(
                    'INSERT INTO warehouse_transfers
                       (id, from_warehouse_id, to_warehouse_id, product_sku, qty, notes, created_by, tenant_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $txId, $fromId, $toId, $sku, $qty, $notes,
                    $byEmail, $tenantId,
                ]);

                // 📒 تسجيل حركتين في سجل حركات المخزون الموحَّد (صادر من
                // المصدر ووارد للوجهة) — يحقق "سجل ومراجعة حركات المخازن"
                // بنفس بنية التوريد/الصرف/الجرد (راجع inventory_movements.php).
                $toStockAfter = $pdo->prepare(
                    'SELECT stock FROM warehouse_stock WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ?'
                );
                $toStockAfter->execute([$toId, $sku, $tenantId]);
                $toAfter = (int)($toStockAfter->fetch()['stock'] ?? $qty);

                $pdo->prepare(
                    'INSERT INTO inventory_movements
                        (id, tenant_id, warehouse_id, product_sku, movement_type, direction, qty,
                         balance_before, balance_after, reason, document_number, reference_id, created_by)
                     VALUES (?,?,?,?,\'transfer_out\',\'out\',?,?,?,?,?,?,?)'
                )->execute([
                    'IM-' . round(microtime(true) * 1000) . '-1', $tenantId, $fromId, $sku,
                    $qty, $availableQty, $availableQty - $qty,
                    $notes !== '' ? $notes : 'تحويل مخزون بين المخازن', $txId, $txId, $byEmail,
                ]);
                $pdo->prepare(
                    'INSERT INTO inventory_movements
                        (id, tenant_id, warehouse_id, product_sku, movement_type, direction, qty,
                         balance_before, balance_after, reason, document_number, reference_id, created_by)
                     VALUES (?,?,?,?,\'transfer_in\',\'in\',?,?,?,?,?,?,?)'
                )->execute([
                    'IM-' . round(microtime(true) * 1000) . '-2', $tenantId, $toId, $sku,
                    $qty, $toAfter - $qty, $toAfter,
                    $notes !== '' ? $notes : 'تحويل مخزون بين المخازن', $txId, $txId, $byEmail,
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][warehouses] فشل التحويل: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }

            audit("warehouse transfer $sku qty=$qty from=$fromId to=$toId", null, 'info', $tenantId);
            json_ok(['success' => true, 'id' => $txId]);
            break;
        }

        // ── إنشاء/تحديث مخزن ─────────────────────────────────────────────────
        $id   = trim($body['id'] ?? '');
        $name = trim($body['name'] ?? '');
        if ($name === '') json_error('اسم المخزن مطلوب');
        $isNew = $id === '';
        if ($isNew) $id = 'WH-' . round(microtime(true) * 1000);

        $location  = $body['location'] ?? '';
        $isDefault = (bool)($body['is_default'] ?? $body['isDefault'] ?? false);
        $notes     = $body['notes'] ?? '';

        if ($isNew) {
            $stmt = $pdo->prepare(
                'INSERT INTO warehouses (id, name, location, is_default, notes, tenant_id) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$id, $name, $location, $isDefault, $notes, $tenantId]);
            audit("create warehouse $id ($name)", null, 'info', $tenantId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE warehouses SET name = ?, location = ?, notes = ? WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$name, $location, $notes, $id, $tenantId]);
            if ($stmt->rowCount() === 0) json_error('المخزن غير موجود في متجرك', 404);
            audit("update warehouse $id ($name)", null, 'info', $tenantId);
        }
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $upd = $pdo->prepare('UPDATE warehouses SET is_active = FALSE WHERE id = ? AND tenant_id = ?');
        $upd->execute([$id, $tenantId]);
        if ($upd->rowCount() === 0) json_error('المخزن غير موجود في متجرك', 404);
        audit("deactivate warehouse $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
