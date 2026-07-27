<?php
require_once __DIR__ . '/_db.php';

// ─────────────────────────────────────────────────────────────────────────
// 🔄 مرتجعات الشراء — المرحلة 7 من إعادة التصميم (بطاقات فئات الفواتير)
// نظير جدول returns (مرتجعات البيع) لكن لمرتجعات المشتريات: إعادة بند إلى
// المورد، وخصم الكمية المرتجعة من المخزون + من رصيد المورد.
// ─────────────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $purchaseId = trim($_GET['purchase_id'] ?? $_GET['purchaseId'] ?? '');
        if ($purchaseId !== '') {
            $stmt = $pdo->prepare(
                'SELECT * FROM purchase_returns WHERE purchase_id = ? AND tenant_id = ? ORDER BY date DESC LIMIT 200'
            );
            $stmt->execute([$purchaseId, $tenantId]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM purchase_returns WHERE tenant_id = ? ORDER BY date DESC LIMIT 200');
            $stmt->execute([$tenantId]);
        }
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['items_json'])) $r['items'] = json_decode($r['items_json'], true) ?: [];
        }
        json_ok($rows);
        break;
    }
    case 'POST': {
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $b = input_json();
        $id = trim($b['id'] ?? '');
        if ($id === '') $id = 'PRT-' . round(microtime(true) * 1000);
        $purchaseId   = trim($b['purchaseId'] ?? $b['purchase_id'] ?? '');
        $supplierName = trim($b['supplierName'] ?? $b['supplier_name'] ?? 'مورد');
        $reason       = trim($b['reason'] ?? '') ?: 'مرتجع شراء';
        $amount       = (float)($b['amount'] ?? 0);
        $items        = $b['items'] ?? [];
        $date         = $b['date'] ?? date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO purchase_returns (id, tenant_id, purchase_id, supplier_name, reason, amount, items_json, date)
             VALUES (?,?,?,?,?,?,?,?)
             ON CONFLICT (id) DO UPDATE SET
                purchase_id   = EXCLUDED.purchase_id,
                supplier_name = EXCLUDED.supplier_name,
                reason        = EXCLUDED.reason,
                amount        = EXCLUDED.amount,
                items_json    = EXCLUDED.items_json,
                date          = EXCLUDED.date
             WHERE purchase_returns.tenant_id = EXCLUDED.tenant_id'
        );
        $stmt->execute([
            $id, $tenantId, $purchaseId, $supplierName, $reason, $amount,
            json_encode($items, JSON_UNESCAPED_UNICODE), $date,
        ]);

        // 📦 خصم الكمية المرتجعة من المخزون (تُعاد للمورد)
        if (is_array($items)) {
            $down = $pdo->prepare(
                'UPDATE products SET stock = GREATEST(0, stock - ?) WHERE (sku = ? OR name = ?) AND tenant_id = ?'
            );
            foreach ($items as $it) {
                $sku  = $it['sku']  ?? '';
                $name = $it['name'] ?? '';
                $qty  = (int)($it['qty'] ?? 0);
                if (($sku !== '' || $name !== '') && $qty > 0) {
                    $down->execute([$qty, $sku, $name, $tenantId]);
                }
            }
        }

        // 💰 تخفيض رصيد المورد بقيمة المرتجع (نُنقّص المديونية عليه)
        if ($supplierName !== '' && $amount > 0) {
            $pdo->prepare('UPDATE suppliers SET balance = balance - ? WHERE name = ? AND tenant_id = ?')
                ->execute([$amount, $supplierName, $tenantId]);
        }

        audit("مرتجع شراء $id للفاتورة $purchaseId بقيمة $amount", null, 'info', $tenantId);
        json_ok(['success' => true, 'id' => $id]);
        break;
    }
    case 'DELETE': {
        $auth = require_admin();
        $tenantId = tenant_id_from_auth($auth);
        $id = trim($_GET['id'] ?? '');
        if ($id === '') json_error('id مطلوب');
        $stmt = $pdo->prepare('DELETE FROM purchase_returns WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenantId]);
        if ($stmt->rowCount() === 0) json_error('المرتجع غير موجود في متجرك', 404);
        audit("حذف مرتجع شراء $id", $auth['email'] ?? null, 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
