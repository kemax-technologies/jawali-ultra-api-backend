-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 013: سجل حركات المخزون الاحترافي (Inventory Movements Ledger)
-- ─────────────────────────────────────────────────────────────────────────────
-- السياق (فحص معماري شامل بناءً على طلب المستخدم الصريح — الفصل بين "ملف
-- الأصناف" و"إدارة المخزون"): كانت قاعدة البيانات تفتقر تماماً لأي جدول
-- يسجّل حركات المخزون بشكل موحّد وقابل للتتبع/المراجعة. الموجود سابقاً:
--   • warehouse_stock       → رصيد حالي فقط (لا تاريخ، لا سبب، لا نوع حركة)
--   • warehouse_transfers   → سجل تحويلات فقط (لا يغطي توريد/صرف/جرد/افتتاحي)
--
-- هذا الجدول الجديد هو المصدر الموحَّد لكل حركة مخزون منذ الآن فصاعداً:
-- توريد (receipt)، صرف (issue)، تحويل صادر/وارد (transfer_out/transfer_in)،
-- تسوية جرد (stocktake_adjustment)، ورصيد افتتاحي (opening) — يحقق طلب
-- المستخدم بأن يكون الرصيد الافتتاحي "حركة مخزون مرتبطة بالصنف والمخزن"
-- وليس رقماً ثابتاً داخل ملف الصنف، ويحقق أيضاً "سجل ومراجعة حركات
-- المخازن" بنفس البنية دون أي جدول إضافي.
--
-- ملاحظة تصميمية مهمة: هذا السجل يوثّق الحركات الجديدة (توريد/صرف/جرد/
-- افتتاحي/تحويل) اعتباراً من هذا الترحيل. حركات البيع/الشراء التاريخية عبر
-- invoices.php/purchases.php لا تُعاد كتابتها هنا حالياً (تبقى تُدار كما هي
-- عبر تحديث products.stock/warehouse_stock مباشرة) تجنّباً لأي تأثير على
-- الأداء أو المنطق المحاسبي القائم والمُختبر فعلاً — يمكن ربطها مستقبلاً
-- كتحسين منفصل إن رغب المستخدم.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS inventory_movements (
    id              VARCHAR(40)   PRIMARY KEY,
    tenant_id       INTEGER       NOT NULL,
    warehouse_id    VARCHAR(40)   NOT NULL,
    product_sku     VARCHAR(64)   NOT NULL,
    movement_type   VARCHAR(30)   NOT NULL, -- opening | receipt | issue | transfer_in | transfer_out | stocktake_adjustment
    direction       VARCHAR(4)    NOT NULL, -- 'in' | 'out'
    qty             INT           NOT NULL, -- كمية موجبة دائماً (الاتجاه يحدَّد عبر direction)
    balance_before  INT           NOT NULL DEFAULT 0,
    balance_after   INT           NOT NULL DEFAULT 0,
    reason          TEXT,                    -- سبب الحركة (إلزامي منطقياً لتسوية الجرد)
    document_number VARCHAR(40),             -- رقم السند القابل للعرض/الطباعة
    reference_id    VARCHAR(60),             -- ربط اختياري (مثال: معرّف عملية تحويل مرتبطة)
    created_by      VARCHAR(160),
    date            TIMESTAMP     NOT NULL DEFAULT NOW()
);

ALTER TABLE inventory_movements
    ADD CONSTRAINT fk_inventory_movements_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id);

CREATE INDEX IF NOT EXISTS idx_inv_movements_tenant       ON inventory_movements(tenant_id);
CREATE INDEX IF NOT EXISTS idx_inv_movements_wh_sku       ON inventory_movements(tenant_id, warehouse_id, product_sku);
CREATE INDEX IF NOT EXISTS idx_inv_movements_date         ON inventory_movements(tenant_id, date DESC);

COMMENT ON TABLE inventory_movements IS
    'سجل موحَّد وقابل للتتبع لكل حركات المخزون (توريد/صرف/تحويل/جرد/افتتاحي) — مصدر "سجل ومراجعة حركات المخازن".';
