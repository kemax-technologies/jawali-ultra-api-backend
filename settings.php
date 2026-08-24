<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

switch ($method) {
    case 'GET': {
        $auth = require_auth();  // ✅ إصلاح: حماية GET بالمصادقة
        $tenantId = tenant_id_from_auth($auth);
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM settings WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = $r['setting_value'];
        json_ok($out);
        break;
    }
    case 'POST': {
        // ✅ إصلاح تجاوز صلاحيات: تعديل إعدادات المتجر يتطلب صلاحية
        // "settings" الدقيقة بدل مجرد تسجيل الدخول — كانت متاحة سابقاً لأي
        // مستخدم مصادَق بغض النظر عن دوره.
        $auth = require_permission('settings');
        $tenantId = tenant_id_from_auth($auth);
        $b = input_json();
        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        // ✅ Multi-Tenant: المفتاح المركب الجديد هو (tenant_id, setting_key)
        $stmt = $pdo->prepare(
            'INSERT INTO settings (tenant_id, setting_key, setting_value) VALUES (?, ?, ?)
             ON CONFLICT (tenant_id, setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value'
        );
        $updatedKeys = [];
        foreach ($b as $k => $v) {
            if (!is_string($k)) continue;
            $stmt->execute([$tenantId, $k, is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)]);
            $updatedKeys[] = $k;
        }
        // 🔧 إصلاح نقص في سجل التدقيق: كان هذا هو الملف الوحيد ذو معالج كتابة
        // (POST) في كامل الباك-إند بلا أي استدعاء audit() — أي أن تعديل
        // إعدادات المتجر (الضرائب، طرق الدفع، بيانات الفوترة، ...) لم يكن
        // يُترك أي أثر في سجل التدقيق على عكس كل نقاط النهاية الأخرى
        // (products.php، employees.php، إلخ). التصحيح: تسجيل مفاتيح
        // الإعدادات المُحدَّثة فعلياً.
        if (!empty($updatedKeys)) {
            audit(
                'تحديث إعدادات المتجر: ' . implode(', ', $updatedKeys),
                null,
                'info',
                $tenantId
            );
        }
        json_ok(['success' => true, 'updated' => count($b)]);
        break;
    }
    default:
        json_error('Method Not Allowed', 405);
}
