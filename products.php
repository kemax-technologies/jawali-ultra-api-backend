<?php
require_once __DIR__ . '/_db.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ✅ دالة مساعدة: تعقيم محارف LIKE الخاصة (% و _)
function like_escape(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

switch ($method) {
    case 'GET': {
        // ✅ إصلاح #5: حماية GET بالمصادقة
        $auth = require_auth();
        $tenantId = tenant_id_from_auth($auth);
        $sku    = $_GET['sku']    ?? '';
        $search = $_GET['search'] ?? '';
        $barcode = $_GET['barcode'] ?? '';

        // 📦 بحث بالباركود — يدعم باركود القطعة وباركود الكرتون
        // ✅ تحويل PostgreSQL: علامات الاقتباس المزدوجة "..." تعني identifiers
        //    (اسم عمود/جدول) في Postgres دائمًا، لا نص حرفي كما في MySQL —
        //    استُبدلت بعلامات اقتباس مفردة 'pack'/'piece' لتبقى نصًا حرفيًا
        if ($barcode !== '') {
            $stmt = $pdo->prepare(
                "SELECT *,
                        CASE WHEN pack_barcode = ? THEN 'pack' ELSE 'piece' END AS matched_unit
                 FROM products
                 WHERE tenant_id = ? AND (barcode = ? OR pack_barcode = ?)
                 LIMIT 1"
            );
            $stmt->execute([$tenantId, $barcode, $barcode]);
            $row = $stmt->fetch();
            json_ok($row ?: []);
        }

        if ($sku !== '') {
            $stmt = $pdo->prepare('SELECT * FROM products WHERE tenant_id = ? AND sku = ? LIMIT 1');
            $stmt->execute([$tenantId, $sku]);
            $row = $stmt->fetch();
            json_ok($row ?: []);
        }
        if ($search !== '') {
            // ✅ إصلاح #12: تعقيم محارف LIKE
            // ✅ تحويل PostgreSQL: LIKE حساس لحالة الأحرف في Postgres، استُخدم
            //    ILIKE (غير حساس لحالة الأحرف) للحفاظ على سلوك البحث الأصلي
            $like = '%' . like_escape($search) . '%';
            $stmt = $pdo->prepare(
                "SELECT * FROM products
                 WHERE tenant_id = ? AND (name ILIKE ? OR sku ILIKE ? OR category ILIKE ?)
                 ORDER BY name LIMIT 200"
            );
            $stmt->execute([$tenantId, $like, $like, $like]);
            json_ok($stmt->fetchAll());
        }
        $rows = $pdo->prepare(
            'SELECT * FROM products WHERE tenant_id = ? ORDER BY name LIMIT 500'
        );
        $rows->execute([$tenantId]);
        json_ok($rows->fetchAll());
        break;
    }

    case 'POST': {
        // ✅ إصلاح تجاوز صلاحيات: كانت هذه العملية تُنشئ/تعدّل منتجات بمجرد
        // require_auth() فقط، فيستطيع أي مستخدم مصادَق (حتى كاشير أو خدمة
        // عملاء اللذين لا يملكان صلاحية editProducts افتراضياً) إنشاء/تعديل
        // منتجات. التصحيح: تقييدها بصلاحية "editProducts" الدقيقة.
        $auth = require_permission('editProducts');
        $tenantId = tenant_id_from_auth($auth);
        $body = input_json();
        $sku  = trim($body['sku'] ?? '');
        $name = trim($body['name'] ?? '');
        if ($sku === '' || $name === '') json_error('SKU والاسم مطلوبان');

        // 📦 الوحدات المتعددة (كرتون، قطعة)
        // 🛠️ إصلاح خلل حقيقي مكتشَف أثناء الفحص: عميل Flutter (StoreProduct.
        // toMap()) يرسل هذه الحقول بصيغة camelCase (baseUnit/packUnit/...)
        // بينما كان هذا المعالج يقرأ فقط الصيغة snake_case (base_unit/
        // pack_unit/...) — ما كان يعني أن كل قيم الوحدات المتعددة المُرسَلة
        // فعلياً من التطبيق كانت تُفقَد دائماً وتُستبدَل بالقيم الافتراضية
        // (تأكَّد هذا فعلياً: كل صف في قاعدة الإنتاج كان base_unit='قطعة' و
        // pack_unit='كرتون' بلا استثناء رغم إدخال المستخدم قيماً أخرى في
        // الواجهة). التصحيح: قبول الصيغتين معاً (نفس نمط warehouses.php).
        $baseUnit      = trim(($body['base_unit'] ?? $body['baseUnit']) ?? 'قطعة');
        $packUnit      = trim(($body['pack_unit'] ?? $body['packUnit']) ?? 'كرتون');
        $packFactor    = (int)(($body['pack_factor'] ?? $body['packFactor']) ?? 1);
        $packPrice     = (float)(($body['pack_price'] ?? $body['packPrice']) ?? 0);
        $packBarcode   = ($body['pack_barcode'] ?? $body['packBarcode']) ?? null;
        $allowPackSaleRaw = $body['allow_pack_sale'] ?? $body['allowPackSale'] ?? true;
        $allowPackSale = (int)(filter_var($allowPackSaleRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);

        // ✅ تحقق من القيم
        if ($packFactor < 1) $packFactor = 1;
        if ($baseUnit === '') $baseUnit = 'قطعة';

        // 🆕 حقول ملف الأصناف الموسّعة (Migration 012): العلامة التجارية،
        // تاريخ الانتهاء، وحالة تفعيل الصنف. جميعها اختيارية.
        $brand = $body['brand'] ?? null;
        if (is_string($brand)) { $brand = trim($brand); if ($brand === '') $brand = null; }
        $expiryDateRaw = $body['expiryDate'] ?? $body['expiry_date'] ?? null;
        $expiryDate = null;
        if (is_string($expiryDateRaw) && trim($expiryDateRaw) !== '') {
            // نقبل ISO8601 كامل (2025-01-01T00:00:00.000) أو تاريخاً مجرداً
            $ts = strtotime($expiryDateRaw);
            if ($ts !== false) $expiryDate = date('Y-m-d', $ts);
        }
        $isActiveRaw = $body['is_active'] ?? $body['isActive'] ?? true;
        $isActive = filter_var($isActiveRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) $isActive = true;

        // ✅ تحويل PostgreSQL: ON DUPLICATE KEY UPDATE → ON CONFLICT DO UPDATE SET
        $stmt = $pdo->prepare(
            'INSERT INTO products
                (tenant_id, sku, name, category, price, cost, stock, sold, barcode, image_url,
                 base_unit, pack_unit, pack_factor, pack_price, pack_barcode, allow_pack_sale,
                 brand, expiry_date, is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON CONFLICT (tenant_id, sku) DO UPDATE SET
                name            = EXCLUDED.name,
                category        = EXCLUDED.category,
                price           = EXCLUDED.price,
                cost            = EXCLUDED.cost,
                stock           = EXCLUDED.stock,
                sold            = EXCLUDED.sold,
                barcode         = EXCLUDED.barcode,
                image_url       = EXCLUDED.image_url,
                base_unit       = EXCLUDED.base_unit,
                pack_unit       = EXCLUDED.pack_unit,
                pack_factor     = EXCLUDED.pack_factor,
                pack_price      = EXCLUDED.pack_price,
                pack_barcode    = EXCLUDED.pack_barcode,
                allow_pack_sale = EXCLUDED.allow_pack_sale,
                brand           = EXCLUDED.brand,
                expiry_date     = EXCLUDED.expiry_date,
                is_active       = EXCLUDED.is_active'
        );
        $stmt->execute([
            $tenantId, $sku, $name,
            $body['category']   ?? '',
            (float)($body['price']  ?? 0),
            (float)($body['cost']   ?? 0),
            (int)  ($body['stock']  ?? 0),
            (int)  ($body['sold']   ?? 0),
            $body['barcode'] ?? null,
            ($body['imageUrl'] ?? $body['image_url']) ?? null,
            $baseUnit,
            $packUnit,
            $packFactor,
            $packPrice,
            $packBarcode,
            $allowPackSale,
            $brand,
            $expiryDate,
            $isActive,
        ]);
        audit("upsert product $sku (units: $baseUnit / $packUnit x$packFactor)", null, 'info', $tenantId);
        json_ok(['success' => true, 'sku' => $sku]);
        break;
    }

    case 'DELETE': {
        // ✅ إصلاح تجاوز صلاحيات: نفس منطق POST أعلاه — حذف منتج يتطلب
        // صلاحية "editProducts" الدقيقة بدل مجرد تسجيل الدخول.
        $auth = require_permission('editProducts');
        $tenantId = tenant_id_from_auth($auth);
        $sku = $_GET['sku'] ?? '';
        if ($sku === '') json_error('SKU مطلوب');
        $stmt = $pdo->prepare('DELETE FROM products WHERE tenant_id = ? AND sku = ?');
        $stmt->execute([$tenantId, $sku]);
        audit("delete product $sku", null, 'info', $tenantId);
        json_ok(['success' => true]);
        break;
    }

    default:
        json_error('Method Not Allowed', 405);
}
