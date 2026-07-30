-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 000: توثيق/إعادة-إنشاء الجداول الأساسية المفقودة من ملفات SQL
-- ═══════════════════════════════════════════════════════════════════════════
--
-- ⚠️ السياق (لماذا هذا الملف ضروري):
--   ملف الترحيل 001_multi_tenant.sql ينفّذ عبارات "ALTER TABLE ... ADD COLUMN
--   tenant_id" على 15 جدولاً من الجداول أدناه — وهذا يفترض ضمنياً أن هذه
--   الجداول كانت موجودة بالفعل قبل تنفيذ 001 (لأن ALTER TABLE يتطلب جدولاً
--   موجوداً مسبقاً). لكن عبارات CREATE TABLE الأصلية لهذه الجداول لم تكن
--   موجودة في أي ملف SQL متتبَّع بالمستودع (jawali_db.sql / jawali_db_postgres.sql
--   / ملفات الترقيع الإدارية) — على الأرجح لأنها أُنشئت مباشرة في قاعدة بيانات
--   Supabase الإنتاجية (عبر SQL Editor يدوياً) أثناء مراحل تطوير سابقة (المراحل
--   3، 5، 6، 7، 8، 9، 10 من "إعادة التصميم" المذكورة في تعليقات ملفات الـ API)
--   دون أن تُحفَظ نسخة موثّقة منها في نظام التحكم بالإصدار (Git).
--
--   هذا الملف يسدّ فجوة التوثيق هذه بإعادة إنشاء هذه الجداول بصيغتها الأصلية
--   (بدون tenant_id — يُضاف لاحقاً بواسطة 001) بالاعتماد على القراءة الدقيقة
--   لكل عمود يُستخدم فعلياً في أكواد PHP الحيّة (accounting.php, employees.php,
--   transfers.php, warehouses.php, cashboxes.php, assets.php, vouchers.php,
--   quotations.php, purchase_returns.php).
--
-- ✅ الأمان (لا تعارض مع البيانات الحالية أو مع 001):
--   • كل عبارة CREATE TABLE هنا تستخدم IF NOT EXISTS — إذا كان الجدول موجوداً
--     بالفعل في قاعدة بيانات الإنتاج (وهو كذلك، لأن 001 يعمل عليها بنجاح منذ
--     فترة) فلن يُنفَّذ أي شيء، ولن تُمسّ بياناته الحالية إطلاقاً بأي شكل.
--   • هذا الملف لا يحتوي على أي ALTER TABLE أو DROP أو TRUNCATE — فقط
--     CREATE TABLE IF NOT EXISTS + CREATE INDEX IF NOT EXISTS، أي أنه غير
--     مدمِّر بطبيعته (non-destructive) بحكم التصميم.
--   • تسمية الملف تبدأ بـ "000" (أصغر رقمياً من "001") بحيث لو أُعيد تشغيل
--     الترحيلات بترتيبها من الصفر على قاعدة بيانات فارغة تماماً (كإعداد بيئة
--     تطوير/اختبار جديدة)، فسيُنفَّذ هذا الملف أولاً فيُنشئ الجداول بصيغتها
--     الأساسية (بدون tenant_id)، ثم يُنفَّذ 001 بعده تماماً كما كان يحدث في
--     التاريخ الفعلي، فيضيف tenant_id ويُطبّق كل قيود FK/الفهارس/المفاتيح
--     المركّبة بدون أي خطأ "relation does not exist".
--   • لا علاقة له إطلاقاً بالجداول ذاتية-الترحيل (self-migrating) الموجودة
--     أصلاً بصيغة CREATE TABLE IF NOT EXISTS داخل الكود مباشرة:
--     support_tickets/support_messages (support.php)، rate_limits
--     (_rate_limit.php)، scanner_codes/scanner_sessions (scanner_session.php)
--     — تلك الجداول تبقى بمعزل تام عن هذا الملف ولا تُذكر هنا إطلاقاً.
--   • pro_requests و app_control غير مذكورين هنا عمداً — انظر الملف
--     002_pro_requests_and_app_control.sql (لأنهما لا يُشار إليهما إطلاقاً في
--     001، وpro_requests يحتاج tenant_id مبنياً فيه منذ الإنشاء مع مرجع
--     FK إلى tenants(id) التي لا تُنشأ إلا داخل 001 نفسه).
-- ═══════════════════════════════════════════════════════════════════════════

-- ── 💰 الصناديق والحسابات البنكية (cashboxes.php) ────────────────────────────
CREATE TABLE IF NOT EXISTS cash_accounts (
    id             VARCHAR(40)  PRIMARY KEY,
    name           VARCHAR(160) NOT NULL,
    type           VARCHAR(20)  NOT NULL DEFAULT 'نقدي',   -- نقدي | بنك
    currency       VARCHAR(8)   NOT NULL DEFAULT 'YER',
    balance        DECIMAL(14,2) NOT NULL DEFAULT 0,
    account_number VARCHAR(80),
    bank_name      VARCHAR(160),
    notes          TEXT,
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at     TIMESTAMP    DEFAULT NOW()
);

-- ── حركات الصناديق (إيداع/سحب/تحويل/سند/راتب) ────────────────────────────────
CREATE TABLE IF NOT EXISTS cash_transactions (
    id                  VARCHAR(40)  PRIMARY KEY,
    account_id          VARCHAR(40)  NOT NULL,
    type                VARCHAR(40)  NOT NULL,
    amount              DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency            VARCHAR(8)   DEFAULT 'YER',
    related_account_id  VARCHAR(40),
    notes               TEXT,
    created_by          VARCHAR(160),
    created_at          TIMESTAMP    DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_cash_tx_account ON cash_transactions(account_id);

-- ── 👥 الموظفون (employees.php) ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employees (
    id           VARCHAR(40)  PRIMARY KEY,
    name         VARCHAR(160) NOT NULL,
    phone        VARCHAR(32),
    job_title    VARCHAR(120),
    department   VARCHAR(120),
    base_salary  DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency     VARCHAR(8)   NOT NULL DEFAULT 'YER',
    hire_date    DATE,
    status       VARCHAR(20)  NOT NULL DEFAULT 'active',
    notes        TEXT,
    created_at   TIMESTAMP    DEFAULT NOW()
);

-- ── سجلات صرف الرواتب ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payroll_runs (
    id              VARCHAR(40)  PRIMARY KEY,
    employee_id     VARCHAR(40)  NOT NULL,
    period          VARCHAR(40)  NOT NULL,
    base_salary     DECIMAL(14,2) NOT NULL DEFAULT 0,
    allowances      DECIMAL(14,2) NOT NULL DEFAULT 0,
    deductions      DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_amount      DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency        VARCHAR(8)   NOT NULL DEFAULT 'YER',
    cash_account_id VARCHAR(40),
    status          VARCHAR(20)  NOT NULL DEFAULT 'paid',
    paid_at         TIMESTAMP,
    notes           TEXT,
    created_at      TIMESTAMP    DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_payroll_employee ON payroll_runs(employee_id);

-- ── 📒 دليل الحسابات (accounting.php) ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS chart_of_accounts (
    id              VARCHAR(60)  PRIMARY KEY,
    code            VARCHAR(40)  NOT NULL UNIQUE,
    name            VARCHAR(160) NOT NULL,
    type            VARCHAR(20)  NOT NULL DEFAULT 'asset', -- asset|liability|equity|revenue|expense
    parent_id       VARCHAR(60),
    opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes           TEXT,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMP    DEFAULT NOW()
);

-- ── القيود اليومية (رؤوس) ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS journal_entries (
    id            VARCHAR(60)  PRIMARY KEY,
    entry_number  VARCHAR(60),
    date          TIMESTAMP    NOT NULL DEFAULT NOW(),
    description   TEXT,
    reference     VARCHAR(160),
    status        VARCHAR(20)  NOT NULL DEFAULT 'posted', -- posted | void
    created_by    VARCHAR(160),
    created_at    TIMESTAMP    DEFAULT NOW()
);

-- ── سطور القيود اليومية (مدين/دائن) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS journal_entry_lines (
    id          VARCHAR(80)  PRIMARY KEY,
    entry_id    VARCHAR(60)  NOT NULL,
    account_id  VARCHAR(60)  NOT NULL,
    debit       DECIMAL(14,2) NOT NULL DEFAULT 0,
    credit      DECIMAL(14,2) NOT NULL DEFAULT 0,
    notes       TEXT
);
CREATE INDEX IF NOT EXISTS idx_jel_entry   ON journal_entry_lines(entry_id);
CREATE INDEX IF NOT EXISTS idx_jel_account ON journal_entry_lines(account_id);

-- ── 💸 التحويلات المالية بين الأفراد (transfers.php) ─────────────────────────
CREATE TABLE IF NOT EXISTS money_transfers (
    id                      VARCHAR(40)  PRIMARY KEY,
    sender_name             VARCHAR(160) NOT NULL,
    sender_phone            VARCHAR(40)  NOT NULL,
    receiver_name           VARCHAR(160) NOT NULL,
    receiver_phone          VARCHAR(40)  NOT NULL,
    amount                  DECIMAL(14,2) NOT NULL DEFAULT 0,
    commission              DECIMAL(14,2) NOT NULL DEFAULT 0,
    total                   DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency                VARCHAR(8)   DEFAULT 'YER',
    cash_account_id         VARCHAR(40),
    receive_code            VARCHAR(20)  NOT NULL,
    status                  VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|completed|cancelled
    payout_cash_account_id  VARCHAR(40),
    completed_by            VARCHAR(160),
    completed_at            TIMESTAMP,
    notes                   TEXT,
    created_by              VARCHAR(160),
    created_at              TIMESTAMP    DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_transfers_code ON money_transfers(receive_code);

-- ── 🏬 المخازن (warehouses.php) ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS warehouses (
    id          VARCHAR(40)  PRIMARY KEY,
    name        VARCHAR(160) NOT NULL,
    location    VARCHAR(255),
    is_default  BOOLEAN      NOT NULL DEFAULT FALSE,
    is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
    notes       TEXT,
    created_at  TIMESTAMP    DEFAULT NOW()
);

-- ── مخزون كل مخزن (لكل SKU) ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS warehouse_stock (
    id            VARCHAR(40) PRIMARY KEY,
    warehouse_id  VARCHAR(40) NOT NULL,
    product_sku   VARCHAR(64) NOT NULL,
    stock         INT         NOT NULL DEFAULT 0,
    UNIQUE (warehouse_id, product_sku)
);

-- ── تحويلات المخزون بين المخازن ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS warehouse_transfers (
    id                  VARCHAR(40) PRIMARY KEY,
    from_warehouse_id   VARCHAR(40) NOT NULL,
    to_warehouse_id     VARCHAR(40) NOT NULL,
    product_sku         VARCHAR(64) NOT NULL,
    qty                 INT         NOT NULL DEFAULT 0,
    notes               TEXT,
    created_by          VARCHAR(160),
    date                TIMESTAMP   DEFAULT NOW()
);

-- ── 📋 عروض الأسعار وطلبات الشراء (quotations.php) ───────────────────────────
CREATE TABLE IF NOT EXISTS quotations (
    id                    VARCHAR(40)  PRIMARY KEY,
    quote_number          VARCHAR(60),
    type                  VARCHAR(20)  NOT NULL, -- sale | purchase
    party_name            VARCHAR(160) NOT NULL,
    party_phone           VARCHAR(40),
    items_json            TEXT,
    subtotal              DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount              DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax                   DECIMAL(14,2) NOT NULL DEFAULT 0,
    total                 DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency              VARCHAR(8)   DEFAULT 'YER',
    valid_until           DATE,
    status                VARCHAR(20)  NOT NULL DEFAULT 'draft', -- draft|sent|accepted|rejected|converted|expired
    converted_invoice_id  VARCHAR(40),
    notes                 TEXT,
    date                  TIMESTAMP    DEFAULT NOW(),
    created_by            VARCHAR(160)
);

-- ── 🔄 مرتجعات الشراء (purchase_returns.php) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS purchase_returns (
    id             VARCHAR(40)  PRIMARY KEY,
    purchase_id    VARCHAR(40),
    supplier_name  VARCHAR(160),
    reason         VARCHAR(255),
    amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
    items_json     TEXT,
    date           TIMESTAMP    DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_purchase_returns_purchase ON purchase_returns(purchase_id);

-- ── 🏢 الأصول الثابتة (assets.php) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assets (
    id                 VARCHAR(40)  PRIMARY KEY,
    name               VARCHAR(160) NOT NULL,
    category           VARCHAR(80)  DEFAULT 'أخرى',
    purchase_date      DATE,
    purchase_value     DECIMAL(14,2) NOT NULL DEFAULT 0,
    current_value      DECIMAL(14,2) NOT NULL DEFAULT 0,
    depreciation_rate  DECIMAL(6,2)  NOT NULL DEFAULT 0,
    location           VARCHAR(160),
    serial_number      VARCHAR(120),
    status             VARCHAR(20)  DEFAULT 'نشط',
    notes              TEXT,
    is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at         TIMESTAMP    DEFAULT NOW()
);

-- ── 🧾 سندات القبض والصرف (vouchers.php) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS vouchers (
    id              VARCHAR(40)  PRIMARY KEY,
    type            VARCHAR(20)  NOT NULL, -- receipt | payment
    voucher_number  VARCHAR(60),
    party_name      VARCHAR(160) NOT NULL,
    party_phone     VARCHAR(40),
    cash_account_id VARCHAR(40),
    amount          DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency        VARCHAR(8)   DEFAULT 'YER',
    category        VARCHAR(80),
    description     TEXT,
    date            TIMESTAMP    DEFAULT NOW(),
    created_by      VARCHAR(160)
);
