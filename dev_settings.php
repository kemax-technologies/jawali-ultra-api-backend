<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * لوحة تحكم المطوّر — إعدادات النشاط التجاري العامة لمتجر مُحدَّد (محمي)
 * ✅ إصلاح Multi-Tenant: settings الآن مفتاحها الأولي الفعلي هو (tenant_id, setting_key)
 *    يجب تمرير tenant_id في الطلب لتحديد المتجر المراد.
 * GET  ?tenant_id=X → إرجاع كل إعدادات المتجر كـ key-value
 * POST { tenant_id, ...settings } → تحديث أي عدد من الإعدادات (upsert)
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_dev_db.php';

dev_require_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $tenantId = (int)($_GET['tenant_id'] ?? 0);
    if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);
    $stmt = $pdo->prepare('SELECT setting_key, setting_value, updated_at FROM settings WHERE tenant_id = ? ORDER BY setting_key');
    $stmt->execute([$tenantId]);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $r) {
        $map[$r['setting_key']] = $r['setting_value'];
    }
    json_ok(['settings' => $map, 'raw' => $rows]);
}

if ($method === 'POST') {
    $b = input_json();
    $tenantId = (int)($b['tenant_id'] ?? 0);
    if ($tenantId <= 0) json_error('tenant_id مطلوب', 400);
    unset($b['tenant_id']);
    if (empty($b) || !is_array($b)) json_error('لا توجد إعدادات للتحديث', 400);

    $stmt = $pdo->prepare(
        'INSERT INTO settings (tenant_id, setting_key, setting_value, updated_at)
         VALUES (?, ?, ?, now())
         ON CONFLICT (tenant_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = now()'
    );
    $updated = [];
    foreach ($b as $key => $value) {
        $key = trim((string)$key);
        if ($key === '') continue;
        $stmt->execute([$tenantId, $key, (string)$value]);
        $updated[] = $key;
    }

    audit('dev_panel: update settings (' . implode(',', $updated) . ')', 'developer', 'info', $tenantId);
    json_ok(['success' => true, 'updated' => $updated]);
}

json_error('Method Not Allowed', 405);
