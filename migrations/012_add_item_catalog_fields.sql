-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 012: توسيع ملف الأصناف (products) بحقول العلامة التجارية،
-- تاريخ الانتهاء، وحالة تفعيل الصنف
-- ─────────────────────────────────────────────────────────────────────────────
-- السياق (فحص معماري شامل لوحدة المخزون بناءً على طلب المستخدم الصريح):
-- ملف الأصناف (products) يجب أن يكون المصدر الوحيد لتعريف بيانات الصنف
-- الأساسية. كان الجدول يدعم بالفعل: sku, name, category, price, cost,
-- barcode, image_url, base_unit/pack_unit/pack_factor/pack_price/
-- pack_barcode/allow_pack_sale — لكنه كان يفتقر لثلاثة حقول طلبها المستخدم
-- صراحةً ضمن "ملف الأصناف": العلامة التجارية (brand)، تاريخ انتهاء
-- الصلاحية (expiry_date)، وحالة تفعيل الصنف (is_active — نشِط/موقوف).
--
-- كل الأعمدة الثلاثة اختيارية تماماً (NULL-safe افتراضياً) ولا تؤثر على أي
-- بيانات موجودة: is_active افتراضياً TRUE (كل الأصناف الحالية تبقى نشِطة
-- تلقائياً دون أي تدخل يدوي)، brand و expiry_date اختياريان (NULL يعني
-- "غير محدد" — لا يُكسر أي منتج لا يملك علامة تجارية أو تاريخ انتهاء).
-- ─────────────────────────────────────────────────────────────────────────────

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS brand VARCHAR(120),
    ADD COLUMN IF NOT EXISTS expiry_date DATE,
    ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;

COMMENT ON COLUMN products.brand IS
    'العلامة التجارية للصنف (اختياري) — جزء من بيانات ملف الأصناف الأساسية.';

COMMENT ON COLUMN products.expiry_date IS
    'تاريخ انتهاء صلاحية الصنف (اختياري، لا ينطبق على كل الأصناف). NULL = لا ينطبق.';

COMMENT ON COLUMN products.is_active IS
    'حالة تفعيل الصنف: TRUE = نشِط (افتراضي لكل الأصناف الحالية والجديدة)، FALSE = موقوف (لا يظهر في نقاط البيع الجديدة لكن يبقى في السجل التاريخي).';

-- فهرس يُسرّع فلترة/عرض الأصناف النشِطة فقط في شاشات نقطة البيع والمخزون
CREATE INDEX IF NOT EXISTS idx_products_is_active ON products(tenant_id, is_active);

-- فهرس يُسرّع البحث عن الأصناف القريبة من الانتهاء (تنبيهات الصلاحية)
CREATE INDEX IF NOT EXISTS idx_products_expiry_date ON products(tenant_id, expiry_date)
    WHERE expiry_date IS NOT NULL;
