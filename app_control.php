<?php
/**
 * ─────────────────────────────────────────────────────────────────────────────
 * حالة التحكم بالتطبيق — endpoint عام يقرأه تطبيق Flutter عند كل تشغيل
 * (بدون مصادقة — بيانات عامة غير حساسة: وضع الصيانة / تحديث إجباري / إعلان)
 * GET → { maintenance_mode, maintenance_message, force_update,
 *          min_supported_build, latest_build, latest_apk_url,
 *          announcement_active, announcement_title, announcement_body }
 * ─────────────────────────────────────────────────────────────────────────────
 */
require_once __DIR__ . '/_db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method Not Allowed', 405);
}

$row = db()->query('SELECT * FROM app_control WHERE id = 1')->fetch();
if (!$row) {
    // لا يوجد صف — إعادة قيم افتراضية آمنة (لا صيانة، لا إجبار تحديث)
    json_ok([
        'maintenance_mode' => false,
        'maintenance_message' => '',
        'force_update' => false,
        'min_supported_build' => 1,
        'latest_build' => 1,
        'latest_apk_url' => '',
        'announcement_active' => false,
        'announcement_title' => '',
        'announcement_body' => '',
    ]);
}

json_ok([
    'maintenance_mode'     => (bool)$row['maintenance_mode'],
    'maintenance_message'  => $row['maintenance_message'] ?? '',
    'force_update'         => (bool)$row['force_update'],
    'min_supported_build'  => (int)$row['min_supported_build'],
    'latest_build'         => (int)$row['latest_build'],
    'latest_apk_url'       => $row['latest_apk_url'] ?? '',
    'announcement_active'  => (bool)$row['announcement_active'],
    'announcement_title'   => $row['announcement_title'] ?? '',
    'announcement_body'    => $row['announcement_body'] ?? '',
]);
