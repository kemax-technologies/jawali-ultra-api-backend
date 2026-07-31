<?php
require_once __DIR__ . '/_db.php';

// ✅ إصلاح #4: جميع التقارير تتطلب مصادقة
$auth = require_auth();
$tenantId = tenant_id_from_auth($auth);

$pdo   = db();
$type  = $_GET['type'] ?? '';
$from  = $_GET['from'] ?? '';
$to    = $_GET['to']   ?? '';
$limit = max(1, min(1000, (int)($_GET['limit'] ?? 100)));

function date_filter(string $col, string $from, string $to): array {
    // ✅ العمود مقيّد بقائمة مسموح بها لمنع SQL injection
    $allowedCols = ['date', 'created_at'];
    if (!in_array($col, $allowedCols, true)) {
        return ['', []];
    }
    $sql  = '';
    $args = [];
    // ✅ تحويل PostgreSQL: علامات backtick (MySQL) غير مدعومة — استخدام double-quote للـ identifier
    if ($from !== '') { $sql .= " AND \"$col\" >= ?"; $args[] = $from; }
    if ($to   !== '') { $sql .= " AND \"$col\" <= ?"; $args[] = $to;   }
    return [$sql, $args];
}

switch ($type) {
    // ── لوحة التحكم ──────────────────────────────────────────────────────────
    case 'dashboard': {
        // ✅ إصلاح تجاوز صلاحيات: كانت متاحة لأي مستخدم مصادَق بغض النظر عن
        // دوره؛ الآن تتطلب صلاحية "reports" الدقيقة (كاشير مثلاً لا يملكها
        // افتراضياً ولا يجب أن يرى لوحة تحكم المتجر المالية).
        ensure_permission($auth, 'reports');
        $totalsStmt = $pdo->prepare(
            'SELECT
                COALESCE(SUM(total),0)    AS total_sales,
                COALESCE(COUNT(*),0)      AS total_invoices,
                COALESCE(SUM(discount),0) AS total_discounts,
                COALESCE(SUM(tax),0)      AS total_taxes
             FROM invoices WHERE tenant_id = ?'
        );
        $totalsStmt->execute([$tenantId]);
        $totals = $totalsStmt->fetch();

        $todayStmt = $pdo->prepare(
            // ✅ تحويل PostgreSQL: CURDATE() (MySQL) → CURRENT_DATE
            "SELECT COALESCE(SUM(total),0) AS today_sales, COUNT(*) AS today_invoices
             FROM invoices WHERE DATE(date) = CURRENT_DATE AND tenant_id = ?"
        );
        $todayStmt->execute([$tenantId]);
        $today = $todayStmt->fetch();

        $stocksStmt = $pdo->prepare(
            'SELECT COUNT(*) AS total_products,
                    SUM(CASE WHEN stock <= 5 THEN 1 ELSE 0 END) AS low_stock,
                    SUM(CASE WHEN stock = 0  THEN 1 ELSE 0 END) AS out_of_stock,
                    COALESCE(SUM(price*stock), 0) AS inventory_value
             FROM products WHERE tenant_id = ?'
        );
        $stocksStmt->execute([$tenantId]);
        $stocks = $stocksStmt->fetch();

        $expensesStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(value),0) AS total FROM expenses WHERE tenant_id = ?'
        );
        $expensesStmt->execute([$tenantId]);
        $expenses = $expensesStmt->fetch();

        json_ok([
            'totals'   => $totals,
            'today'    => $today,
            'stocks'   => $stocks,
            'expenses' => $expenses,
        ]);
        break;
    }

    // ── تقرير المبيعات ───────────────────────────────────────────────────────
    case 'sales': {
        // ✅ إصلاح تجاوز صلاحيات: يتطلب صلاحية "reports" الدقيقة.
        ensure_permission($auth, 'reports');
        [$where, $args] = date_filter('date', $from, $to);
        $stmt = $pdo->prepare(
            "SELECT DATE(date) AS day,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(total),0)    AS revenue,
                    COALESCE(SUM(discount),0) AS discounts,
                    COALESCE(SUM(tax),0)      AS taxes
             FROM invoices WHERE tenant_id = ? $where
             GROUP BY DATE(date) ORDER BY day DESC LIMIT $limit"
        );
        $stmt->execute(array_merge([$tenantId], $args));
        $rows = $stmt->fetchAll();

        $sumStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_invoices,
                    COALESCE(SUM(total),0)    AS total_revenue,
                    COALESCE(SUM(discount),0) AS total_discounts,
                    COALESCE(SUM(tax),0)      AS total_taxes
             FROM invoices WHERE tenant_id = ? $where"
        );
        $sumStmt->execute(array_merge([$tenantId], $args));
        json_ok(['rows' => $rows, 'totals' => $sumStmt->fetch(), 'from' => $from, 'to' => $to]);
        break;
    }

    // ── تقرير الربحية ────────────────────────────────────────────────────────
    case 'profit': {
        // ✅ إصلاح تجاوز صلاحيات: الربحية معلومة مالية حساسة — تتطلب صلاحية
        // "profits" الدقيقة بدل مجرد تسجيل الدخول.
        ensure_permission($auth, 'profits');
        [$where, $args] = date_filter('date', $from, $to);
        $rev = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS v FROM invoices WHERE tenant_id = ? $where");
        $rev->execute(array_merge([$tenantId], $args));
        $revenue = (float)($rev->fetch()['v'] ?? 0);

        $exp = $pdo->prepare("SELECT COALESCE(SUM(value),0) AS v FROM expenses WHERE tenant_id = ? $where");
        $exp->execute(array_merge([$tenantId], $args));
        $expenses = (float)($exp->fetch()['v'] ?? 0);

        $profit = $revenue - $expenses;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
        json_ok([
            'revenue'       => $revenue,
            'expenses'      => $expenses,
            'gross_profit'  => $profit,
            'profit_margin' => round($margin, 2),
            'from'          => $from,
            'to'            => $to,
        ]);
        break;
    }

    // ── أعلى المنتجات ────────────────────────────────────────────────────────
    case 'top_products': {
        // ✅ إصلاح تجاوز صلاحيات: يتطلب صلاحية "reports" الدقيقة.
        ensure_permission($auth, 'reports');
        $stmt = $pdo->prepare(
            'SELECT sku, name, sold, price, (price*sold) AS revenue
             FROM products WHERE tenant_id = ? ORDER BY sold DESC LIMIT 20'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        json_ok($rows);
        break;
    }

    // ── تقرير المخزون ────────────────────────────────────────────────────────
    case 'inventory': {
        // ✅ إصلاح تجاوز صلاحيات: يتطلب صلاحية "reports" الدقيقة.
        ensure_permission($auth, 'reports');
        $stmt = $pdo->prepare(
            'SELECT * FROM products WHERE tenant_id = ? ORDER BY stock ASC LIMIT 500'
        );
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        json_ok($rows);
        break;
    }

    // ── تحليلات ──────────────────────────────────────────────────────────────
    case 'analytics': {
        // ✅ إصلاح تجاوز صلاحيات: يتطلب صلاحية "reports" الدقيقة.
        ensure_permission($auth, 'reports');
        // ✅ تحويل PostgreSQL: "" (علامتان مزدوجتان) هي identifier فاضي في Postgres، لا سلسلة نصية — استُخدمت علامة مفردة ''
        $byCategoryStmt = $pdo->prepare(
            'SELECT category, COUNT(*) AS count,
                    COALESCE(SUM(sold),0) AS units_sold,
                    COALESCE(SUM(price*sold),0) AS revenue
             FROM products WHERE category IS NOT NULL AND category <> \'\' AND tenant_id = ?
             GROUP BY category ORDER BY revenue DESC'
        );
        $byCategoryStmt->execute([$tenantId]);
        $byCategory = $byCategoryStmt->fetchAll();

        $byPaymentStmt = $pdo->prepare(
            'SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total),0) AS total
             FROM invoices WHERE tenant_id = ? GROUP BY payment_method'
        );
        $byPaymentStmt->execute([$tenantId]);
        $byPayment = $byPaymentStmt->fetchAll();

        json_ok(['categories' => $byCategory, 'payments' => $byPayment]);
        break;
    }

    // ── سجل التدقيق ──────────────────────────────────────────────────────────
    case 'audit': {
        // ✅ إصلاح دقة الصلاحيات: كان مقيّداً بفحص دور حرفي "مدير" فقط، بينما
        // "محاسب" و"مدير فرع" يملكان صلاحية activityLog ضمن صلاحياتهما
        // الافتراضية. التصحيح: استخدام الصلاحية الدقيقة بدل الدور الحرفي —
        // نفس الإصلاح المطبَّق في audit.php لضمان الاتساق بين المسارين.
        ensure_permission($auth, 'activityLog');
        $stmt = $pdo->prepare(
            'SELECT * FROM audit_log WHERE tenant_id = ? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    default:
        json_error('نوع التقرير غير معروف. الأنواع المدعومة: dashboard, sales, profit, top_products, inventory, analytics, audit', 400);
}
