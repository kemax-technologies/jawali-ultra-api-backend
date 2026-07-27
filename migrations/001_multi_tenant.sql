-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 001: تحويل النظام إلى SaaS متعدد المتاجر (Multi-Tenant)
-- كل متجر (Tenant) له بياناته المستقلة تمامًا عن أي متجر آخر
-- تنفيذ ضمن معاملة واحدة (Transaction) — يُلغى كليًا عند أي خطأ
-- ═══════════════════════════════════════════════════════════════════════════

BEGIN;

-- ── 1. جدول المتاجر (Tenants) ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tenants (
    id             SERIAL PRIMARY KEY,
    name           VARCHAR(160) NOT NULL DEFAULT 'متجر جديد',
    owner_user_id  INTEGER,
    plan           VARCHAR(20)  NOT NULL DEFAULT 'free',
    is_active      SMALLINT     NOT NULL DEFAULT 1,
    created_at     TIMESTAMP    NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_tenants_owner ON tenants(owner_user_id);

-- ── 2. إضافة tenant_id لكل الجداول ذات الصلة (nullable أولاً للـ backfill) ───
ALTER TABLE users               ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE products            ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE invoices            ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE invoice_items       ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE customers           ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE suppliers           ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE branches            ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE employees           ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE expenses            ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE credits             ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE credit_payments     ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE purchases           ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE purchase_returns    ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE quotations          ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE returns             ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE vouchers            ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE cash_accounts       ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE cash_transactions   ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE warehouses          ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE warehouse_stock     ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE warehouse_transfers ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE money_transfers     ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE settings            ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE chart_of_accounts   ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE journal_entries     ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE journal_entry_lines ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE payroll_runs        ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE assets              ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE scanner_codes       ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE scanner_sessions    ADD COLUMN IF NOT EXISTS tenant_id INTEGER;
ALTER TABLE audit_log           ADD COLUMN IF NOT EXISTS tenant_id INTEGER;

-- ── 3. إنشاء المتجر الرئيسي (المتجر القديم قبل هذا التحديث) ─────────────────
-- كل البيانات التجارية الحالية (منتجات/فواتير/عملاء) تخص هذا المتجر
INSERT INTO tenants (id, name, owner_user_id, plan)
VALUES (1, 'المتجر الرئيسي', 1, 'pro')
ON CONFLICT (id) DO NOTHING;
-- ضبط تسلسل SERIAL بعد الإدخال اليدوي بمعرّف صريح =1
SELECT setval('tenants_id_seq', (SELECT MAX(id) FROM tenants));

-- كل حساب "مدير" آخر موجود مسبقًا (غير id=1) يصبح صاحب متجر مستقل جديد فارغ
INSERT INTO tenants (name, owner_user_id, plan)
SELECT 'متجر ' || u.name, u.id, 'free'
FROM users u
WHERE u.role = 'مدير' AND u.id <> 1
  AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.owner_user_id = u.id);

-- أي حساب مسجَّل ذاتيًا سابقًا بدور "كاشير" (غير الحساب التجريبي cashier@jawali.com)
-- يصبح "مدير" مالكًا لمتجره الخاص المستقل — يطابق النموذج الجديد:
-- كل تسجيل ذاتي جديد = صاحب متجر مستقل (مدير) لا موظف تابع لأحد
UPDATE users SET role = 'مدير'
WHERE role = 'كاشير' AND email <> 'cashier@jawali.com'
  AND id NOT IN (SELECT owner_user_id FROM tenants WHERE owner_user_id IS NOT NULL);

INSERT INTO tenants (name, owner_user_id, plan)
SELECT 'متجر ' || u.name, u.id, 'free'
FROM users u
WHERE u.role = 'مدير'
  AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.owner_user_id = u.id);

-- ── 4. Backfill: ربط كل مستخدم بمتجره ────────────────────────────────────────
UPDATE users u SET tenant_id = t.id
FROM tenants t WHERE t.owner_user_id = u.id AND u.tenant_id IS NULL;

-- الموظفون التابعون (كاشير/محاسب/... التجريبيون المتبقون) يتبعون المتجر الرئيسي
UPDATE users SET tenant_id = 1 WHERE tenant_id IS NULL;

-- ── 5. Backfill: كل البيانات التجارية القديمة تخص المتجر الرئيسي (tenant_id=1) ─
UPDATE products            SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE invoices            SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE invoice_items       SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE customers           SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE suppliers           SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE branches            SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE employees           SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE expenses            SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE credits             SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE credit_payments     SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE purchases           SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE purchase_returns    SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE quotations          SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE returns             SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE vouchers            SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE cash_accounts       SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE cash_transactions   SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE warehouses          SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE warehouse_stock     SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE warehouse_transfers SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE money_transfers     SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE settings            SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE chart_of_accounts   SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE journal_entries     SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE journal_entry_lines SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE payroll_runs        SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE assets              SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE scanner_codes       SET tenant_id = 1 WHERE tenant_id IS NULL;
UPDATE scanner_sessions    SET tenant_id = 1 WHERE tenant_id IS NULL;

-- ── 6. فرض NOT NULL بعد اكتمال الـ backfill ─────────────────────────────────
ALTER TABLE users               ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE products            ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE invoices            ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE invoice_items       ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE customers           ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE suppliers           ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE branches            ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE employees           ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE expenses            ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE credits             ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE credit_payments     ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE purchases           ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE purchase_returns    ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE quotations          ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE returns             ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE vouchers            ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE cash_accounts       ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE cash_transactions   ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE warehouses          ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE warehouse_stock     ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE warehouse_transfers ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE money_transfers     ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE settings            ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE chart_of_accounts   ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE journal_entries     ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE journal_entry_lines ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE payroll_runs        ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE assets              ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE scanner_codes       ALTER COLUMN tenant_id SET NOT NULL;
ALTER TABLE scanner_sessions    ALTER COLUMN tenant_id SET NOT NULL;
-- audit_log يبقى nullable (سجلات مستوى منصة/مطوّر بدون tenant محدد ممكنة)

-- ── 7. إضافة قيد FK لكل tenant_id يشير إلى tenants(id) — يمنع بيانات يتيمة ───
ALTER TABLE users               ADD CONSTRAINT fk_users_tenant               FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE products            ADD CONSTRAINT fk_products_tenant            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE invoices            ADD CONSTRAINT fk_invoices_tenant            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE invoice_items       ADD CONSTRAINT fk_invoice_items_tenant       FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE customers           ADD CONSTRAINT fk_customers_tenant           FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE suppliers           ADD CONSTRAINT fk_suppliers_tenant           FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE branches            ADD CONSTRAINT fk_branches_tenant            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE employees           ADD CONSTRAINT fk_employees_tenant          FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE expenses            ADD CONSTRAINT fk_expenses_tenant            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE credits             ADD CONSTRAINT fk_credits_tenant             FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE credit_payments     ADD CONSTRAINT fk_credit_payments_tenant     FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE purchases           ADD CONSTRAINT fk_purchases_tenant          FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE purchase_returns    ADD CONSTRAINT fk_purchase_returns_tenant    FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE quotations          ADD CONSTRAINT fk_quotations_tenant          FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE returns             ADD CONSTRAINT fk_returns_tenant             FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE vouchers            ADD CONSTRAINT fk_vouchers_tenant            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE cash_accounts       ADD CONSTRAINT fk_cash_accounts_tenant       FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE cash_transactions   ADD CONSTRAINT fk_cash_transactions_tenant   FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE warehouses          ADD CONSTRAINT fk_warehouses_tenant          FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE warehouse_stock     ADD CONSTRAINT fk_warehouse_stock_tenant     FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE warehouse_transfers ADD CONSTRAINT fk_warehouse_transfers_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE money_transfers     ADD CONSTRAINT fk_money_transfers_tenant     FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE settings            ADD CONSTRAINT fk_settings_tenant            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE chart_of_accounts   ADD CONSTRAINT fk_coa_tenant                 FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE journal_entries     ADD CONSTRAINT fk_je_tenant                  FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE journal_entry_lines ADD CONSTRAINT fk_jel_tenant                 FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE payroll_runs        ADD CONSTRAINT fk_payroll_tenant             FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE assets              ADD CONSTRAINT fk_assets_tenant              FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE scanner_codes       ADD CONSTRAINT fk_scanner_codes_tenant       FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE scanner_sessions    ADD CONSTRAINT fk_scanner_sessions_tenant    FOREIGN KEY (tenant_id) REFERENCES tenants(id);

-- ── 8. فهارس على tenant_id لضمان أداء جيد مع نمو البيانات (ملايين السجلات) ───
CREATE INDEX IF NOT EXISTS idx_users_tenant               ON users(tenant_id);
CREATE INDEX IF NOT EXISTS idx_products_tenant            ON products(tenant_id);
CREATE INDEX IF NOT EXISTS idx_invoices_tenant            ON invoices(tenant_id);
CREATE INDEX IF NOT EXISTS idx_invoice_items_tenant       ON invoice_items(tenant_id);
CREATE INDEX IF NOT EXISTS idx_customers_tenant           ON customers(tenant_id);
CREATE INDEX IF NOT EXISTS idx_suppliers_tenant           ON suppliers(tenant_id);
CREATE INDEX IF NOT EXISTS idx_branches_tenant            ON branches(tenant_id);
CREATE INDEX IF NOT EXISTS idx_employees_tenant           ON employees(tenant_id);
CREATE INDEX IF NOT EXISTS idx_expenses_tenant            ON expenses(tenant_id);
CREATE INDEX IF NOT EXISTS idx_credits_tenant             ON credits(tenant_id);
CREATE INDEX IF NOT EXISTS idx_credit_payments_tenant     ON credit_payments(tenant_id);
CREATE INDEX IF NOT EXISTS idx_purchases_tenant           ON purchases(tenant_id);
CREATE INDEX IF NOT EXISTS idx_purchase_returns_tenant    ON purchase_returns(tenant_id);
CREATE INDEX IF NOT EXISTS idx_quotations_tenant          ON quotations(tenant_id);
CREATE INDEX IF NOT EXISTS idx_returns_tenant             ON returns(tenant_id);
CREATE INDEX IF NOT EXISTS idx_vouchers_tenant            ON vouchers(tenant_id);
CREATE INDEX IF NOT EXISTS idx_cash_accounts_tenant       ON cash_accounts(tenant_id);
CREATE INDEX IF NOT EXISTS idx_cash_transactions_tenant   ON cash_transactions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_warehouses_tenant          ON warehouses(tenant_id);
CREATE INDEX IF NOT EXISTS idx_warehouse_stock_tenant     ON warehouse_stock(tenant_id);
CREATE INDEX IF NOT EXISTS idx_warehouse_transfers_tenant ON warehouse_transfers(tenant_id);
CREATE INDEX IF NOT EXISTS idx_money_transfers_tenant     ON money_transfers(tenant_id);
CREATE INDEX IF NOT EXISTS idx_settings_tenant            ON settings(tenant_id);
CREATE INDEX IF NOT EXISTS idx_coa_tenant                 ON chart_of_accounts(tenant_id);
CREATE INDEX IF NOT EXISTS idx_je_tenant                  ON journal_entries(tenant_id);
CREATE INDEX IF NOT EXISTS idx_jel_tenant                 ON journal_entry_lines(tenant_id);
CREATE INDEX IF NOT EXISTS idx_payroll_tenant             ON payroll_runs(tenant_id);
CREATE INDEX IF NOT EXISTS idx_assets_tenant              ON assets(tenant_id);
CREATE INDEX IF NOT EXISTS idx_scanner_codes_tenant       ON scanner_codes(tenant_id);
CREATE INDEX IF NOT EXISTS idx_scanner_sessions_tenant    ON scanner_sessions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_audit_tenant               ON audit_log(tenant_id);

-- ═══════════════════════════════════════════════════════════════════════════
-- ── 9. إعادة هيكلة المفاتيح الأساسية/الفريدة الطبيعية لتصبح مركّبة مع tenant_id
--     (بدون هذا، متجرين لا يمكنهما استخدام نفس SKU/رقم هاتف/رمز فرع/اسم مورّد)
-- ═══════════════════════════════════════════════════════════════════════════

-- products: PK كان (sku) وحدها → أصبح (tenant_id, sku)
ALTER TABLE products DROP CONSTRAINT products_pkey;
ALTER TABLE products ADD CONSTRAINT products_pkey PRIMARY KEY (tenant_id, sku);

-- customers: PK كان (phone) وحدها → أصبح (tenant_id, phone)
ALTER TABLE customers DROP CONSTRAINT customers_pkey;
ALTER TABLE customers ADD CONSTRAINT customers_pkey PRIMARY KEY (tenant_id, phone);

-- settings: PK كان (setting_key) وحدها → أصبح (tenant_id, setting_key)
ALTER TABLE settings DROP CONSTRAINT settings_pkey;
ALTER TABLE settings ADD CONSTRAINT settings_pkey PRIMARY KEY (tenant_id, setting_key);

-- branches: PK يبقى (id) — فقط قيد UNIQUE(code) يصبح UNIQUE(tenant_id, code)
ALTER TABLE branches DROP CONSTRAINT branches_code_key;
ALTER TABLE branches ADD CONSTRAINT branches_code_key UNIQUE (tenant_id, code);

-- chart_of_accounts: PK يبقى (id) — قيد UNIQUE(code) يصبح UNIQUE(tenant_id, code)
ALTER TABLE chart_of_accounts DROP CONSTRAINT chart_of_accounts_code_key;
ALTER TABLE chart_of_accounts ADD CONSTRAINT chart_of_accounts_code_key UNIQUE (tenant_id, code);

-- suppliers: PK يبقى (id) — قيد UNIQUE(name) يصبح UNIQUE(tenant_id, name)
ALTER TABLE suppliers DROP CONSTRAINT suppliers_name_key;
ALTER TABLE suppliers ADD CONSTRAINT suppliers_name_key UNIQUE (tenant_id, name);

-- warehouse_stock: قيد UNIQUE(warehouse_id, product_sku) يبقى كما هو صحيحًا
-- منطقيًا (warehouse_id نفسه فريد لكل متجر عبر tenant_id في warehouses، فلا
-- تعارض ممكن بين متجرين هنا أصلاً) — لا تعديل مطلوب.

COMMIT;
