<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 📒 Jawali Ultra — API سجل حركات المخزون (Inventory Movements Ledger)
 * ─────────────────────────────────────────────────────────────────────────────
 * السياق (فحص معماري شامل — طلب المستخدم الصريح بالفصل بين "ملف الأصناف"
 * و"إدارة المخزون"): هذا الملف هو نقطة الدخول الموحَّدة لكل حركات المخزون
 * الجديدة (توريد/صرف/جرد/رصيد افتتاحي) وتوفير "سجل ومراجعة حركات المخازن".
 * التحويل بين المخازن يبقى في warehouses.php (action=transfer) كما هو —
 * لكنه أيضاً يسجّل حركتين (صادر/وارد) في نفس سجل inventory_movements حتى
 * يظهر ضمن نفس سجل المراجعة الموحَّد (راجع التعديل المُضاف في warehouses.php).
 *
 * Endpoints:
 *   GET  /inventory_movements.php                                — سجل الحركات (فلاتر اختيارية)
 *        ?warehouse_id=WH-XXX&product_sku=SKU&type=receipt&limit=200
 *   POST /inventory_movements.php?action=receipt   — سند توريد مخزني (زيادة)
 *   POST /inventory_movements.php?action=issue     — سند صرف مخزني (نقص)
 *   POST /inventory_movements.php?action=opening   — رصيد افتتاحي لصنف داخل مخزن
 *   POST /inventory_movements.php?action=stocktake — تسوية فرق جرد (بالسبب)
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

/** يضمن وجود سطر warehouse_stock (ينشئه بصفر إن لم يوجد) ويُرجع الرصيد الحالي مع قفل الصف. */
function _wm_lock_or_init_stock(PDO $pdo, int $tenantId, string $warehouseId, string $sku): int {
    $pdo->prepare(
        'INSERT INTO warehouse_stock (id, warehouse_id, product_sku, stock, tenant_id)
         VALUES (?, ?, ?, 0, ?)
         ON CONFLICT (warehouse_id, product_sku) DO NOTHING'
    )->execute(['WS-' . round(microtime(true) * 1000) . '-' . random_int(100, 999), $warehouseId, $sku, $tenantId]);

    $stmt = $pdo->prepare(
        'SELECT stock FROM warehouse_stock WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([$warehouseId, $sku, $tenantId]);
    $row = $stmt->fetch();
    return $row ? (int)$row['stock'] : 0;
}

/** يُدرج حركة مخزون واحدة في السجل الموحَّد. */
function _wm_insert_movement(
    PDO $pdo, int $tenantId, string $warehouseId, string $sku, string $type,
    string $direction, int $qty, int $before, int $after, string $reason,
    string $documentNumber, ?string $referenceId, ?string $createdBy
): string {
    $id = 'IM-' . round(microtime(true) * 1000) . '-' . random_int(100, 999);
    $pdo->prepare(
        'INSERT INTO inventory_movements
            (id, tenant_id, warehouse_id, product_sku, movement_type, direction, qty,
             balance_before, balance_after, reason, document_number, reference_id, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $id, $tenantId, $warehouseId, $sku, $type, $direction, $qty,
        $before, $after, $reason, $documentNumber, $referenceId, $createdBy,
    ]);
    return $id;
}

switch ($method) {
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);

        $warehouseId = trim($_GET['warehouse_id'] ?? '');
        $sku         = trim($_GET['product_sku']  ?? '');
        $type        = trim($_GET['type']         ?? '');
        $limit       = min(2000, max(1, (int)($_GET['limit'] ?? 300)));

        $sql  = 'SELECT * FROM inventory_movements WHERE tenant_id = ?';
        $args = [$tenantId];
        if ($warehouseId !== '') { $sql .= ' AND warehouse_id = ?'; $args[] = $warehouseId; }
        if ($sku !== '')         { $sql .= ' AND product_sku = ?';  $args[] = $sku; }
        if ($type !== '')        { $sql .= ' AND movement_type = ?'; $args[] = $type; }
        $sql .= ' ORDER BY date DESC LIMIT ' . $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        json_ok($stmt->fetchAll());
        break;
    }

    case 'POST': {
        $auth = require_permission('manageInventory');
        $tenantId = tenant_id_from_auth($auth);
        $action = $_GET['action'] ?? '';
        $body   = input_json();
        $byEmail = $_SERVER['HTTP_X_USER_EMAIL'] ?? null;

        $warehouseId = trim($body['warehouse_id'] ?? $body['warehouseId'] ?? '');
        $sku         = trim($body['product_sku']  ?? $body['productSku']  ?? '');
        $qty         = (int)($body['qty'] ?? 0);
        $reason      = trim($body['reason'] ?? $body['notes'] ?? '');

        if ($warehouseId === '' || $sku === '') {
            json_error('warehouse_id و product_sku مطلوبان');
        }

        // ── 📥 سند توريد مخزني (زيادة) ───────────────────────────────────────
        if ($action === 'receipt' || $action === 'opening') {
            if ($qty <= 0) json_error('qty يجب أن تكون أكبر من صفر');
            $movementType = $action === 'opening' ? 'opening' : 'receipt';
            $docPrefix    = $action === 'opening' ? 'OPN' : 'GRN';

            try {
                $pdo->beginTransaction();

                // ✅ الرصيد الافتتاحي: يُرفض إن وُجدت حركة افتتاحية سابقة لنفس
                // الصنف/المخزن — يُمنع تكراره (مرة واحدة فقط لكل صنف/مخزن).
                if ($action === 'opening') {
                    $chk = $pdo->prepare(
                        "SELECT 1 FROM inventory_movements
                         WHERE tenant_id = ? AND warehouse_id = ? AND product_sku = ? AND movement_type = 'opening'
                         LIMIT 1"
                    );
                    $chk->execute([$tenantId, $warehouseId, $sku]);
                    if ($chk->fetch()) {
                        $pdo->rollBack();
                        json_error('تم تسجيل رصيد افتتاحي لهذا الصنف في هذا المخزن من قبل');
                    }
                }

                $before = _wm_lock_or_init_stock($pdo, $tenantId, $warehouseId, $sku);
                $after  = $before + $qty;

                $pdo->prepare(
                    'UPDATE warehouse_stock SET stock = ? WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ?'
                )->execute([$after, $warehouseId, $sku, $tenantId]);

                // 🔗 مزامنة الرصيد العام (products.stock) — نفس نمط التحويل/الفواتير
                $pdo->prepare(
                    'UPDATE products SET stock = stock + ? WHERE tenant_id = ? AND sku = ?'
                )->execute([$qty, $tenantId, $sku]);

                $docNumber = $docPrefix . '-' . round(microtime(true) * 1000);
                $movId = _wm_insert_movement(
                    $pdo, $tenantId, $warehouseId, $sku, $movementType, 'in',
                    $qty, $before, $after,
                    $reason !== '' ? $reason : ($action === 'opening' ? 'رصيد افتتاحي' : 'توريد مخزني'),
                    $docNumber, null, $byEmail
                );

                $pdo->commit();
                audit("inventory $movementType $sku qty=$qty warehouse=$warehouseId", $byEmail, 'info', $tenantId);
                json_ok(['success' => true, 'id' => $movId, 'document_number' => $docNumber, 'balance_after' => $after]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][inventory_movements] فشل التوريد: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }
            break;
        }

        // ── 📤 سند صرف مخزني (نقص) ───────────────────────────────────────────
        if ($action === 'issue') {
            if ($qty <= 0) json_error('qty يجب أن تكون أكبر من صفر');

            try {
                $pdo->beginTransaction();

                $before = _wm_lock_or_init_stock($pdo, $tenantId, $warehouseId, $sku);
                if ($before < $qty) {
                    $pdo->rollBack();
                    json_error('الكمية غير كافية في المخزن لإتمام الصرف');
                }
                $after = $before - $qty;

                $pdo->prepare(
                    'UPDATE warehouse_stock SET stock = ? WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ?'
                )->execute([$after, $warehouseId, $sku, $tenantId]);

                $pdo->prepare(
                    'UPDATE products SET stock = GREATEST(0, stock - ?) WHERE tenant_id = ? AND sku = ?'
                )->execute([$qty, $tenantId, $sku]);

                $docNumber = 'ISS-' . round(microtime(true) * 1000);
                $movId = _wm_insert_movement(
                    $pdo, $tenantId, $warehouseId, $sku, 'issue', 'out',
                    $qty, $before, $after,
                    $reason !== '' ? $reason : 'صرف مخزني',
                    $docNumber, null, $byEmail
                );

                $pdo->commit();
                audit("inventory issue $sku qty=$qty warehouse=$warehouseId", $byEmail, 'info', $tenantId);
                json_ok(['success' => true, 'id' => $movId, 'document_number' => $docNumber, 'balance_after' => $after]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][inventory_movements] فشل الصرف: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }
            break;
        }

        // ── 📊 تسوية فرق جرد (زيادة أو نقص + سبب إلزامي) ─────────────────────
        if ($action === 'stocktake') {
            $countedQty = (int)($body['counted_qty'] ?? $body['countedQty'] ?? -1);
            if ($countedQty < 0) json_error('counted_qty مطلوبة (الكمية الفعلية المجرودة)');
            if ($reason === '') json_error('سبب فرق الجرد إلزامي');

            try {
                $pdo->beginTransaction();

                $before = _wm_lock_or_init_stock($pdo, $tenantId, $warehouseId, $sku);
                $diff   = $countedQty - $before;

                if ($diff === 0) {
                    $pdo->rollBack();
                    json_ok(['success' => true, 'no_change' => true, 'balance_after' => $before]);
                    break;
                }

                $after = $countedQty;
                $pdo->prepare(
                    'UPDATE warehouse_stock SET stock = ? WHERE warehouse_id = ? AND product_sku = ? AND tenant_id = ?'
                )->execute([$after, $warehouseId, $sku, $tenantId]);

                if ($diff > 0) {
                    $pdo->prepare(
                        'UPDATE products SET stock = stock + ? WHERE tenant_id = ? AND sku = ?'
                    )->execute([$diff, $tenantId, $sku]);
                } else {
                    $pdo->prepare(
                        'UPDATE products SET stock = GREATEST(0, stock - ?) WHERE tenant_id = ? AND sku = ?'
                    )->execute([abs($diff), $tenantId, $sku]);
                }

                $docNumber = 'STK-' . round(microtime(true) * 1000);
                $movId = _wm_insert_movement(
                    $pdo, $tenantId, $warehouseId, $sku, 'stocktake_adjustment',
                    $diff > 0 ? 'in' : 'out', abs($diff), $before, $after,
                    $reason, $docNumber, null, $byEmail
                );

                $pdo->commit();
                audit("inventory stocktake $sku diff=$diff warehouse=$warehouseId reason=$reason", $byEmail, 'info', $tenantId);
                json_ok(['success' => true, 'id' => $movId, 'document_number' => $docNumber, 'diff' => $diff, 'balance_after' => $after]);
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[Jawali][inventory_movements] فشل تسوية الجرد: ' . $e->getMessage());
                json_error('خطأ داخلي في الخادم', 500);
            }
            break;
        }

        json_error('action غير معروف — استخدم receipt أو issue أو opening أو stocktake');
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
