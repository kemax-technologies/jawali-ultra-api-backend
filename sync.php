<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ✅ إصلاح #3: جميع عمليات المزامنة تتطلب مصادقة
$auth = require_auth();
$tenantId = tenant_id_from_auth($auth);

switch ($method) {
    // ── جلب كل البيانات للتطبيق (Pull) ────────────────────────────────────────
    case 'GET': {
        $productsStmt = $pdo->prepare('SELECT * FROM products WHERE tenant_id = ? ORDER BY name LIMIT 1000');
        $productsStmt->execute([$tenantId]);
        $products = $productsStmt->fetchAll();

        $customersStmt = $pdo->prepare('SELECT * FROM customers WHERE tenant_id = ? ORDER BY spent DESC LIMIT 1000');
        $customersStmt->execute([$tenantId]);
        $customers = $customersStmt->fetchAll();

        $invoicesStmt = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id = ? ORDER BY date DESC LIMIT 500');
        $invoicesStmt->execute([$tenantId]);
        $invoices = $invoicesStmt->fetchAll();
        foreach ($invoices as &$inv) {
            if (!empty($inv['items_json'])) {
                $inv['items'] = json_decode($inv['items_json'], true) ?: [];
            }
        }

        $expensesStmt = $pdo->prepare('SELECT * FROM expenses WHERE tenant_id = ? ORDER BY date DESC LIMIT 500');
        $expensesStmt->execute([$tenantId]);
        $expenses = $expensesStmt->fetchAll();

        $suppliersStmt = $pdo->prepare('SELECT * FROM suppliers WHERE tenant_id = ? ORDER BY name');
        $suppliersStmt->execute([$tenantId]);
        $suppliers = $suppliersStmt->fetchAll();

        json_ok([
            'products'    => $products,
            'customers'   => $customers,
            'invoices'    => $invoices,
            'expenses'    => $expenses,
            'suppliers'   => $suppliers,
            'server_time' => date('c'),
        ]);
        break;
    }

    // ── رفع الفواتير غير المُزامَنة من العميل (Push) ─────────────────────────
    case 'POST': {
        $b = input_json();
        $invoices = $b['invoices'] ?? [];
        if (!is_array($invoices)) json_error('invoices array required');

        $synced = 0;
        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        // ✅ Multi-Tenant: tenant_id يُدرج في كل فاتورة، وشرط WHERE يحمي من كتابة
        // فاتورة تابعة لمتجر آخر عند تطابق id بالخطأ (id يُفترض أن يكون فريدًا عالميًا
        // لكن هذا حزام أمان إضافي كما في invoices.php)
        $stmt = $pdo->prepare(
            'INSERT INTO invoices
             (id, tenant_id, customer_phone, user_email, date, subtotal, discount, tax, total, payment_method, status, items_json)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (id) DO UPDATE SET
                subtotal = EXCLUDED.subtotal, discount = EXCLUDED.discount,
                tax = EXCLUDED.tax, total = EXCLUDED.total,
                payment_method = EXCLUDED.payment_method, items_json = EXCLUDED.items_json
             WHERE invoices.tenant_id = EXCLUDED.tenant_id'
        );
        foreach ($invoices as $inv) {
            $id    = $inv['id'] ?? ('INV-' . time() . '-' . $synced);
            $items = $inv['items'] ?? [];
            try {
                $stmt->execute([
                    $id,
                    $tenantId,
                    $inv['customer_phone'] ?? null,
                    $auth['email']          ?? $inv['user_email'] ?? null,
                    $inv['date']           ?? date('Y-m-d H:i:s'),
                    (float)($inv['subtotal'] ?? 0),
                    (float)($inv['discount'] ?? 0),
                    (float)($inv['tax']      ?? 0),
                    (float)($inv['total']    ?? 0),
                    $inv['payment_method'] ?? $inv['paymentMethod'] ?? 'نقدي',
                    $inv['status'] ?? 'مدفوعة',
                    json_encode($items, JSON_UNESCAPED_UNICODE),
                ]);
                $synced++;
            } catch (Exception $e) {
                // ✅ إصلاح: تسجيل الفواتير المعطوبة للتحقيق بدل إخفاء الخطأ
                error_log('[Jawali][sync] Skipped invalid invoice #' . $id . ': ' . $e->getMessage());
            }
        }
        audit("sync push: $synced invoice(s)", $auth['email'] ?? null, 'info', $tenantId);
        json_ok(['success' => true, 'synced' => $synced]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
