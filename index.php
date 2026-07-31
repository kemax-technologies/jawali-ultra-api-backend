<?php
require_once __DIR__ . '/_db.php';

// نقطة المعاينة الافتراضية — تفيد للتحقق من تشغيل API
try {
    $version = db()->query('SELECT VERSION() AS v')->fetch()['v'] ?? '?';
    json_ok([
        'app'        => 'Jawali Ultra API',
        'version'    => '9.0 — Credit & Dual Pricing',
        'status'     => 'ready',
        'database'   => $version,
        'server_time'=> date('c'),
        'features'   => [
            '💳 نظام البيع بالدَين والذمم المالية',
            '💱 تسعير مزدوج (ر.ي + دولار)',
            '💵 تتبع الدفعات وتواريخ الاستحقاق',
        ],
        'endpoints'  => [
            'auth.php?action=login',
            'auth.php?action=register',
            'auth.php?action=me',
            'products.php',
            'customers.php',
            'invoices.php',
            'expenses.php',
            'suppliers.php',
            'purchases.php',
            'returns.php',
            'returns.php?invoice_id={id}',
            'users.php',
            'settings.php',
            'sync.php',
            'audit.php',
            'reports.php?type=dashboard',
            'reports.php?type=sales',
            'reports.php?type=profit',
            'reports.php?type=top_products',
            'reports.php?type=inventory',
            'reports.php?type=analytics',
            // 💳 الذمم والتسعير المزدوج (جديد)
            'credits.php',
            'credits.php?summary=1',
            'credits.php?customer={phone}',
            'credits.php?overdue=1',
            'credit_payments.php',
            'credit_payments.php?credit_id={id}',
            'credit_payments.php?customer={phone}',
            // 💾 النسخ الاحتياطي المركزي التلقائي (جديد)
            'backups.php?action=list',
            'backups.php?action=download&id={id}',
            'backups.php (POST action=create|restore|delete)',
            'backup_cron.php?key={secret} (اختياري لـ cron حقيقي)',
            // 🗄️ إطار ترحيل قاعدة البيانات (جديد) — راجع migrations/README.md
            'migrate.php?key={secret} (اطّلاع status فقط عبر HTTP — apply/backfill من CLI فقط)',
            // 📷 الكاميرا المساعدة (اختياري)
            'scanner_session.php (POST)',
            'scanner_session.php?id={id}',
            'scanner_session.php?id={id}&stream=1 (SSE)',
            'scanner_scan.php (POST)',
            'scanner_scan.php?session={id} (HTML fallback)',
            // 👨‍💻 لوحة تحكم المطوّر (Developer Panel — منفصلة، مصادقة بكلمة مرور خاصة)
            'dev_auth.php?action=login',
            'dev_stats.php?action=overview',
            'dev_control.php',
        ],
        'developer'  => 'Eng. Kamel Issa Kamel Al-Maghles • 734375821',
    ]);
} catch (Exception $e) {
    error_log('[Jawali][index] قاعدة البيانات غير متاحة: ' . $e->getMessage());
    json_error('قاعدة البيانات غير متاحة. تأكد من إعدادات الاتصال بـ Supabase/PostgreSQL.', 500);
}
