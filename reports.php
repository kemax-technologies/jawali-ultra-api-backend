<?php
require_once __DIR__ . '/_db.php';

// ✅ إصلاح #4: جميع التقارير تتطلب مصادقة
$auth = require_auth();

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
        $totals = $pdo->query(
            'SELECT
                COALESCE(SUM(total),0)    AS total_sales,
                COALESCE(COUNT(*),0)      AS total_invoices,
                COALESCE(SUM(discount),0) AS total_discounts,
                COALESCE(SUM(tax),0)      AS total_taxes
             FROM invoices'
        )->fetch();
        $today = $pdo->query(
            // ✅ تحويل PostgreSQL: CURDATE() (MySQL) → CURRENT_DATE
            "SELECT COALESCE(SUM(total),0) AS today_sales, COUNT(*) AS today_invoices
             FROM invoices WHERE DATE(date) = CURRENT_DATE"
        )->fetch();
        $stocks = $pdo->query(
            'SELECT COUNT(*) AS total_products,
                    SUM(CASE WHEN stock <= 5 THEN 1 ELSE 0 END) AS low_stock,
                    SUM(CASE WHEN stock = 0  THEN 1 ELSE 0 END) AS out_of_stock,
                    COALESCE(SUM(price*stock), 0) AS inventory_value
             FROM products'
        )->fetch();
        $expenses = $pdo->query(
            'SELECT COALESCE(SUM(value),0) AS total FROM expenses'
        )->fetch();
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
        [$where, $args] = date_filter('date', $from, $to);
        $stmt = $pdo->prepare(
            "SELECT DATE(date) AS day,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(total),0)    AS revenue,
                    COALESCE(SUM(discount),0) AS discounts,
                    COALESCE(SUM(tax),0)      AS taxes
             FROM invoices WHERE 1=1 $where
             GROUP BY DATE(date) ORDER BY day DESC LIMIT $limit"
        );
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $sumStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total_invoices,
                    COALESCE(SUM(total),0)    AS total_revenue,
                    COALESCE(SUM(discount),0) AS total_discounts,
                    COALESCE(SUM(tax),0)      AS total_taxes
             FROM invoices WHERE 1=1 $where"
        );
        $sumStmt->execute($args);
        json_ok(['rows' => $rows, 'totals' => $sumStmt->fetch(), 'from' => $from, 'to' => $to]);
        break;
    }

    // ── تقرير الربحية ────────────────────────────────────────────────────────
    case 'profit': {
        [$where, $args] = date_filter('date', $from, $to);
        $rev = $pdo->prepare("SELECT COALESCE(SUM(total),0) AS v FROM invoices WHERE 1=1 $where");
        $rev->execute($args);
        $revenue = (float)($rev->fetch()['v'] ?? 0);

        $exp = $pdo->prepare("SELECT COALESCE(SUM(value),0) AS v FROM expenses WHERE 1=1 $where");
        $exp->execute($args);
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
        $rows = $pdo->query(
            'SELECT sku, name, sold, price, (price*sold) AS revenue
             FROM products ORDER BY sold DESC LIMIT 20'
        )->fetchAll();
        json_ok($rows);
        break;
    }

    // ── تقرير المخزون ────────────────────────────────────────────────────────
    case 'inventory': {
        $rows = $pdo->query(
            'SELECT * FROM products ORDER BY stock ASC LIMIT 500'
        )->fetchAll();
        json_ok($rows);
        break;
    }

    // ── تحليلات ──────────────────────────────────────────────────────────────
    case 'analytics': {
        // ✅ تحويل PostgreSQL: "" (علامتان مزدوجتان) هي identifier فاضي في Postgres، لا سلسلة نصية — استُخدمت علامة مفردة ''
        $byCategory = $pdo->query(
            'SELECT category, COUNT(*) AS count,
                    COALESCE(SUM(sold),0) AS units_sold,
                    COALESCE(SUM(price*sold),0) AS revenue
             FROM products WHERE category IS NOT NULL AND category <> \'\'
             GROUP BY category ORDER BY revenue DESC'
        )->fetchAll();
        $byPayment = $pdo->query(
            'SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total),0) AS total
             FROM invoices GROUP BY payment_method'
        )->fetchAll();
        json_ok(['categories' => $byCategory, 'payments' => $byPayment]);
        break;
    }

    // ── سجل التدقيق — للمديرين فقط ──────────────────────────────────────────
    case 'audit': {
        // ✅ سجل التدقيق للمدير فقط
        if (($auth['role'] ?? '') !== 'مدير') {
            json_error('غير مصرح — يتطلب صلاحية مدير', 403);
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute();
        json_ok($stmt->fetchAll());
        break;
    }

    default:
        json_error('نوع التقرير غير معروف. الأنواع المدعومة: dashboard, sales, profit, top_products, inventory, analytics, audit', 400);
}
