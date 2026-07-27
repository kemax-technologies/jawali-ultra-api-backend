<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * 🏢 Jawali Ultra — API وحدة الأصول الثابتة (المرحلة 6 من إعادة التصميم)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Endpoints:
 *   GET    /assets.php               — قائمة الأصول النشطة
 *   POST   /assets.php               — إنشاء/تحديث أصل
 *   DELETE /assets.php?id=AS-XXX     — تعطيل (حذف منطقي) أصل
 */

require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $stmt = $pdo->prepare(
            'SELECT * FROM assets WHERE is_active = TRUE AND tenant_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $b = input_json();

        $id = trim($b['id'] ?? '');
        $name = trim($b['name'] ?? '');
        if ($name === '') json_error('اسم الأصل مطلوب');
        $isNew = $id === '';
        if ($isNew) $id = 'AS-' . round(microtime(true) * 1000);

        $category         = $b['category'] ?? 'أخرى';
        $purchaseDate     = $b['purchaseDate'] ?? $b['purchase_date'] ?? date('Y-m-d');
        $purchaseValue    = (float)($b['purchaseValue'] ?? $b['purchase_value'] ?? 0);
        $currentValue     = (float)($b['currentValue'] ?? $b['current_value'] ?? $purchaseValue);
        $depreciationRate = (float)($b['depreciationRate'] ?? $b['depreciation_rate'] ?? 0);
        $location         = $b['location'] ?? '';
        $serialNumber     = $b['serialNumber'] ?? $b['serial_number'] ?? '';
        $status           = $b['status'] ?? 'نشط';
        $notes            = $b['notes'] ?? '';

        if ($isNew) {
            $stmt = $pdo->prepare(
                'INSERT INTO assets
                    (id, name, category, purchase_date, purchase_value, current_value,
                     depreciation_rate, location, serial_number, status, notes, tenant_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $id, $name, $category, $purchaseDate, $purchaseValue, $currentValue,
                $depreciationRate, $location, $serialNumber, $status, $notes, $tenantId,
            ]);
            audit("create asset $id ($name)", null, 'info', $tenantId);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE assets SET
                    name = ?, category = ?, purchase_date = ?, purchase_value = ?,
                    current_value = ?, depreciation_rate = ?, location = ?,
                    serial_number = ?, status = ?, notes = ?
                 WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([
                $name, $category, $purchaseDate, $purchaseValue, $currentValue,
                $depreciationRate, $location, $serialNumber, $status, $notes, $id, $tenantId,
            ]);
            if ($stmt->rowCount() === 0) {
                json_error('الأصل غير موجود في متجرك', 404);
            }
            audit("update asset $id ($name)", null, 'info', $tenantId);
        }
        json_ok(['success' => true, 'id' => $id]);
        break;
    }

    case 'DELETE': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id مطلوب');
        $stmt = $pdo->prepare('UPDATE assets SET is_active = FALSE WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        if ($stmt->rowCount() === 0) {
            json_error('الأصل غير موجود في متجرك', 404);
        }
        audit("deactivate asset $id", null, 'warning', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
