<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * Jawali Ultra — Admin Dashboard API (تقارير شاملة + رؤية مباشرة)
 * GET admin_dashboard.php?type=overview       → ملخّص شامل
 * GET admin_dashboard.php?type=branches       → أداء كل الفروع
 * GET admin_dashboard.php?type=realtime       → تحديث حي (آخر دقيقة/ساعة/يوم)
 * GET admin_dashboard.php?type=sales_chart    → بيانات رسم المبيعات اليومية
 * GET admin_dashboard.php?type=top_cashiers   → أعلى الكاشيرات
 * GET admin_dashboard.php?type=alerts         → تنبيهات (مخزون منخفض/ذمم متأخرة)
 * GET admin_dashboard.php?type=compare        → مقارنة بين الفروع (period)
 * كل المسارات تتطلب صلاحية مدير، وتدعم ?branch=CODE
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_admin_db.php';

$auth = require_admin_web();
$tenantId = tenant_id_from_auth($auth);
$pdo  = db();
$type = $_GET['type'] ?? 'overview';
$period = $_GET['period'] ?? 'today';
[$bWhere, $bArgs] = branch_filter('branch_code');
// ✅ Multi-Tenant: كل الاستعلامات في هذا الملف يجب أن تُقيَّد بمتجر (tenant) المستخدم
// الحالي — نُضيف شرط tenant_id قبل شرط الفرع (bWhere) في كل مكان، ونضع tenant_id
// كأول عنصر في مصفوفة args المقابلة (بترتيب ظهور علامات الاستفهام ? في الاستعلام).
$tWhere = ' AND tenant_id = ?';
$tbWhere = $tWhere . $bWhere;
$tbArgs  = array_merge([$tenantId], $bArgs);

switch ($type) {

    // ── نظرة عامة شاملة ─────────────────────────────────────────────────────
    case 'overview': {
        // إجماليات تاريخية
        $totals = $pdo->prepare(
            "SELECT
                COALESCE(SUM(total),0)    AS total_sales,
                COUNT(*)                  AS total_invoices,
                COALESCE(SUM(discount),0) AS total_discounts,
                COALESCE(SUM(tax),0)      AS total_taxes,
                COALESCE(AVG(total),0)    AS avg_invoice
             FROM invoices WHERE 1=1 $tbWhere"
        );
        $totals->execute($tbArgs);
        $tot = $totals->fetch();

        // اليوم
        // ✅ تحويل PostgreSQL: CURDATE() (MySQL) → CURRENT_DATE
        $today = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) AS sales, COUNT(*) AS invoices
             FROM invoices WHERE DATE(date)=CURRENT_DATE $tbWhere"
        );
        $today->execute($tbArgs);
        $td = $today->fetch();

        // الأسبوع
        // ✅ تحويل PostgreSQL: DATE_SUB(CURDATE(), INTERVAL 7 DAY) (MySQL) → CURRENT_DATE - INTERVAL '7 days'
        $week = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) AS sales, COUNT(*) AS invoices
             FROM invoices WHERE date >= CURRENT_DATE - INTERVAL '7 days' $tbWhere"
        );
        $week->execute($tbArgs);
        $wk = $week->fetch();

        // الشهر
        // ✅ تحويل PostgreSQL: DATE_FORMAT(CURDATE(),'%Y-%m-01') (MySQL) → DATE_TRUNC('month', CURRENT_DATE)
        $month = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) AS sales, COUNT(*) AS invoices
             FROM invoices WHERE date >= DATE_TRUNC('month', CURRENT_DATE) $tbWhere"
        );
        $month->execute($tbArgs);
        $mn = $month->fetch();

        // المخزون
        $stocks = $pdo->prepare(
            "SELECT COUNT(*) AS total_products,
                    SUM(CASE WHEN stock <= 5 THEN 1 ELSE 0 END) AS low_stock,
                    SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) AS out_of_stock,
                    COALESCE(SUM(price*stock),0) AS inventory_value
             FROM products WHERE 1=1 $tbWhere"
        );
        $stocks->execute($tbArgs);
        $st = $stocks->fetch();

        // المصروفات
        $exp = $pdo->prepare(
            "SELECT COALESCE(SUM(value),0) AS total,
                    COALESCE(SUM(CASE WHEN DATE(date)=CURRENT_DATE THEN value ELSE 0 END),0) AS today
             FROM expenses WHERE 1=1 $tbWhere"
        );
        $exp->execute($tbArgs);
        $ex = $exp->fetch();

        // العملاء (خاصّون بالمتجر الحالي)
        $custStmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN tier='VIP' THEN 1 ELSE 0 END) AS vip
             FROM customers WHERE tenant_id = ?"
        );
        $custStmt->execute([$tenantId]);
        $cust = $custStmt->fetch();

        // المستخدمون
        $users = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN role='مدير'  THEN 1 ELSE 0 END) AS admins,
                    SUM(CASE WHEN role='كاشير' THEN 1 ELSE 0 END) AS cashiers,
                    SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active
             FROM users WHERE 1=1 $tbWhere"
        );
        $users->execute($tbArgs);
        $us = $users->fetch();

        // الذمم (الديون)
        $credits = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(amount_yer - paid_yer),0) AS open_balance,
                    SUM(CASE WHEN status='مفتوح'        THEN 1 ELSE 0 END) AS open_count,
                    SUM(CASE WHEN due_date IS NOT NULL AND due_date < NOW() AND (amount_yer-paid_yer) > 0
                             THEN 1 ELSE 0 END) AS overdue
             FROM credits WHERE 1=1 $tbWhere"
        );
        $credits->execute($tbArgs);
        $cr = $credits->fetch();

        // عدد الفروع النشطة (ضمن المتجر الحالي فقط)
        $branchCountStmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE is_active=1 AND tenant_id = ?');
        $branchCountStmt->execute([$tenantId]);
        $branchCount = (int)$branchCountStmt->fetchColumn();

        json_ok([
            'totals'    => $tot,
            'today'     => $td,
            'week'      => $wk,
            'month'     => $mn,
            'stocks'    => $st,
            'expenses'  => $ex,
            'customers' => $cust,
            'users'     => $us,
            'credits'   => $cr,
            'branches'  => ['active' => $branchCount],
            'profit'    => [
                'gross_today' => num($td['sales']) - num($ex['today']),
                'gross_total' => num($tot['total_sales']) - num($ex['total']),
            ],
            'generated_at' => date('c'),
            'branch_filter' => current_branch(),
        ]);
        break;
    }

    // ── أداء كل الفروع (للمقارنة) ───────────────────────────────────────────
    case 'branches': {
        $stmt = $pdo->prepare(
            "SELECT b.code, b.name, b.is_main, b.is_active, b.manager,
                    (SELECT COUNT(*) FROM users    u WHERE u.branch_code=b.code AND u.tenant_id=b.tenant_id AND u.is_active=1) AS users_count,
                    (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.branch_code=b.code AND i.tenant_id=b.tenant_id)     AS total_sales,
                    (SELECT COALESCE(SUM(total),0) FROM invoices i WHERE i.branch_code=b.code AND i.tenant_id=b.tenant_id AND DATE(i.date)=CURRENT_DATE) AS today_sales,
                    (SELECT COUNT(*) FROM invoices i WHERE i.branch_code=b.code AND i.tenant_id=b.tenant_id AND DATE(i.date)=CURRENT_DATE) AS today_invoices,
                    (SELECT COALESCE(SUM(price*stock),0) FROM products p WHERE p.branch_code=b.code AND p.tenant_id=b.tenant_id) AS inventory_value,
                    (SELECT COUNT(*) FROM products p WHERE p.branch_code=b.code AND p.tenant_id=b.tenant_id AND p.stock<=5)     AS low_stock,
                    (SELECT COALESCE(SUM(amount_yer-paid_yer),0) FROM credits c WHERE c.branch_code=b.code AND c.tenant_id=b.tenant_id) AS open_credits
             FROM branches b
             WHERE b.tenant_id = ?
             ORDER BY today_sales DESC"
        );
        $stmt->execute([$tenantId]);
        json_ok($stmt->fetchAll());
        break;
    }

    // ── تحديث حي (مباشر) ────────────────────────────────────────────────────
    case 'realtime': {
        // آخر 60 دقيقة + آخر 10 فواتير
        // ✅ تحويل PostgreSQL: DATE_SUB(NOW(), INTERVAL 1 HOUR) (MySQL) → NOW() - INTERVAL '1 hour'
        $last1h = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) AS sales, COUNT(*) AS invoices
             FROM invoices WHERE date >= NOW() - INTERVAL '1 hour' $tbWhere"
        );
        $last1h->execute($tbArgs);

        $last24h = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) AS sales, COUNT(*) AS invoices
             FROM invoices WHERE date >= NOW() - INTERVAL '24 hours' $tbWhere"
        );
        $last24h->execute($tbArgs);

        $recent = $pdo->prepare(
            "SELECT id, customer_phone, user_email, total, payment_method, status, date, branch_code
             FROM invoices WHERE 1=1 $tbWhere ORDER BY date DESC LIMIT 10"
        );
        $recent->execute($tbArgs);

        // فعالية المستخدمين (آخر 60 دقيقة)
        $activity = $pdo->prepare(
            "SELECT user_email, COUNT(*) AS invoices, COALESCE(SUM(total),0) AS sales
             FROM invoices
             WHERE date >= NOW() - INTERVAL '1 hour' $tbWhere
             GROUP BY user_email ORDER BY sales DESC LIMIT 10"
        );
        $activity->execute($tbArgs);

        // مبيعات كل ساعة (آخر 24 ساعة)
        // ✅ تحويل PostgreSQL: DATE_FORMAT(date,'%Y-%m-%d %H:00:00') (MySQL) → TO_CHAR(date, 'YYYY-MM-DD HH24:00:00')
        $hourly = $pdo->prepare(
            "SELECT TO_CHAR(date, 'YYYY-MM-DD HH24:00:00') AS hour,
                    COALESCE(SUM(total),0) AS sales,
                    COUNT(*) AS invoices
             FROM invoices
             WHERE date >= NOW() - INTERVAL '24 hours' $tbWhere
             GROUP BY hour ORDER BY hour ASC"
        );
        $hourly->execute($tbArgs);

        json_ok([
            'last_hour'     => $last1h->fetch(),
            'last_24h'      => $last24h->fetch(),
            'recent_invoices'=> $recent->fetchAll(),
            'activity'      => $activity->fetchAll(),
            'hourly_chart'  => $hourly->fetchAll(),
            'server_time'   => date('c'),
            'tick'          => time(),
        ]);
        break;
    }

    // ── رسم المبيعات (شهر/أسبوع/سنة) ────────────────────────────────────────
    case 'sales_chart': {
        [$from, $to] = range_period($period);
        $stmt = $pdo->prepare(
            "SELECT DATE(date) AS day,
                    COALESCE(SUM(total),0)    AS sales,
                    COUNT(*)                  AS invoices,
                    COALESCE(SUM(discount),0) AS discounts
             FROM invoices
             WHERE date BETWEEN ? AND ? $tbWhere
             GROUP BY DATE(date)
             ORDER BY day ASC"
        );
        $stmt->execute(array_merge([$from, $to], $tbArgs));
        json_ok([
            'period' => $period,
            'from'   => $from,
            'to'     => $to,
            'rows'   => $stmt->fetchAll(),
        ]);
        break;
    }

    // ── أعلى الكاشيرات أداءً ────────────────────────────────────────────────
    case 'top_cashiers': {
        [$from, $to] = range_period($period);
        $stmt = $pdo->prepare(
            "SELECT i.user_email,
                    u.name AS user_name,
                    u.role AS user_role,
                    COUNT(*) AS invoices,
                    COALESCE(SUM(i.total),0) AS sales,
                    COALESCE(AVG(i.total),0) AS avg_invoice
             FROM invoices i
             LEFT JOIN users u ON u.email = i.user_email
             WHERE i.date BETWEEN ? AND ? $tbWhere
             GROUP BY i.user_email
             ORDER BY sales DESC
             LIMIT 20"
        );
        $stmt->execute(array_merge([$from, $to], $tbArgs));
        json_ok($stmt->fetchAll());
        break;
    }

    // ── تنبيهات النظام ──────────────────────────────────────────────────────
    case 'alerts': {
        $alerts = [];

        // مخزون منخفض
        $low = $pdo->prepare(
            "SELECT sku, name, stock, branch_code FROM products
             WHERE stock <= 5 $tbWhere ORDER BY stock ASC LIMIT 30"
        );
        $low->execute($tbArgs);
        foreach ($low->fetchAll() as $p) {
            $alerts[] = [
                'level'   => $p['stock'] == 0 ? 'critical' : 'warning',
                'type'    => 'low_stock',
                'title'   => $p['stock'] == 0 ? 'منتج نفد من المخزون' : 'مخزون منخفض',
                'message' => "{$p['name']} — متبقّي {$p['stock']} ({$p['sku']})",
                'branch'  => $p['branch_code'] ?? 'MAIN',
            ];
        }

        // ذمم متأخرة
        $od = $pdo->prepare(
            "SELECT id, customer_phone, amount_yer, paid_yer, due_date, branch_code
             FROM credits
             WHERE due_date IS NOT NULL AND due_date < NOW()
               AND (amount_yer - paid_yer) > 0 $tbWhere
             ORDER BY due_date ASC LIMIT 30"
        );
        $od->execute($tbArgs);
        foreach ($od->fetchAll() as $c) {
            $remain = num($c['amount_yer']) - num($c['paid_yer']);
            $alerts[] = [
                'level'   => 'warning',
                'type'    => 'overdue_credit',
                'title'   => 'ذمة متأخرة',
                'message' => "العميل {$c['customer_phone']} — متبقّي ".number_format($remain,2)." ر.ي — استحقاق {$c['due_date']}",
                'branch'  => $c['branch_code'] ?? 'MAIN',
            ];
        }

        // فروع غير نشطة (ضمن المتجر الحالي فقط)
        $inactiveStmt = $pdo->prepare(
            "SELECT code, name FROM branches WHERE is_active = 0 AND tenant_id = ?"
        );
        $inactiveStmt->execute([$tenantId]);
        $inactive = $inactiveStmt->fetchAll();
        foreach ($inactive as $b) {
            $alerts[] = [
                'level'   => 'info',
                'type'    => 'inactive_branch',
                'title'   => 'فرع غير نشط',
                'message' => "{$b['name']} ({$b['code']})",
                'branch'  => $b['code'],
            ];
        }

        json_ok([
            'count'  => count($alerts),
            'alerts' => $alerts,
        ]);
        break;
    }

    // ── مقارنة بين الفروع للفترة المختارة ───────────────────────────────────
    case 'compare': {
        [$from, $to] = range_period($period);
        $rows = $pdo->prepare(
            "SELECT b.code, b.name,
                    COALESCE(SUM(i.total),0) AS sales,
                    COUNT(i.id) AS invoices,
                    COALESCE(SUM(i.discount),0) AS discounts,
                    COALESCE(AVG(i.total),0) AS avg_invoice
             FROM branches b
             LEFT JOIN invoices i
                ON i.branch_code = b.code AND i.tenant_id = b.tenant_id AND i.date BETWEEN ? AND ?
             WHERE b.is_active = 1 AND b.tenant_id = ?
             GROUP BY b.code, b.name
             ORDER BY sales DESC"
        );
        $rows->execute([$from, $to, $tenantId]);
        json_ok([
            'period' => $period,
            'from'   => $from,
            'to'     => $to,
            'rows'   => $rows->fetchAll(),
        ]);
        break;
    }

    default:
        json_error('نوع غير معروف. الأنواع المدعومة: overview, branches, realtime, sales_chart, top_cashiers, alerts, compare', 400);
}
