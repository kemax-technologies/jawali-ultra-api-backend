-- ═══════════════════════════════════════════════════════════════════════════
-- Migration 002: توثيق/إعادة-إنشاء جدولي pro_requests و app_control
-- ═══════════════════════════════════════════════════════════════════════════
--
-- ⚠️ السياق:
--   على عكس الجداول الـ 15 الموثَّقة في 000_bootstrap_missing_core_tables.sql
--   (التي يفترضها 001_multi_tenant.sql عبر ALTER TABLE)، هذان الجدولان غير
--   مذكورين إطلاقاً في 001 — أي أنهما أُنشئا مباشرة في قاعدة بيانات الإنتاج
--   في وقت لاحق لتنفيذ 001 (على الأرجح أثناء تطوير ميزة "ترقية Pro" ولوحة
--   تحكم المطوّر)، ومن ثم لم تُحفَظ أي نسخة موثّقة من عبارات CREATE TABLE
--   الخاصة بهما في نظام التحكم بالإصدار.
--
--   الدليل على ترتيب الإنشاء (بعد 001 لا قبله):
--     • pro_requests يحتوي عمود tenant_id مبنياً فيه منذ اللحظة الأولى
--       (يُقرأ في pro.php وdev_pro.php وdev_tenants.php دون أي ALTER منفصل)،
--       وهو ما يستلزم وجود جدول tenants (الذي لا يُنشأ إلا داخل 001) ليصحّ
--       ربطه بقيد FK — لذا لا بد أن يكون قد أُنشئ بعد نجاح 001.
--     • app_control جدول عام غير مرتبط بأي متجر (tenant-agnostic) بتصميم
--       متعمَّد — صف تحكم واحد وحيد (id=1) يخص كل النظام (وضع الصيانة/
--       التحديث الإجباري/الإعلانات)، ولذلك لا يحتوي على tenant_id أصلاً ولا
--       علاقة له بترتيب 001 من الأساس.
--
-- ✅ الأمان (idempotent وغير مدمِّر):
--   • CREATE TABLE IF NOT EXISTS فقط — إن كان الجدول موجوداً بالفعل (وهو
--     كذلك في الإنتاج) فلن يُنفَّذ أي شيء ولن تُمسّ بياناته.
--   • قيد FK على tenant_id في pro_requests يُضاف بصيغة منفصلة محمية بفحص
--     "IF NOT EXISTS" ضمني عبر DO $$ ... EXCEPTION عشان لا يفشل الترحيل لو
--     كان الجدول (والقيد) موجودَين مسبقاً بصيغة مطابقة تماماً.
--   • تُدرَج تلقائياً بذرة (seed) صف واحد افتراضي في app_control (id=1) فقط
--     إذا كان الجدول فارغاً تماماً — عبر ON CONFLICT DO NOTHING، بحيث لا
--     يُكرَّر أو يُستبدَل أي صف id=1 موجود بالفعل في الإنتاج.
-- ═══════════════════════════════════════════════════════════════════════════

-- ── 👑 طلبات ترقية Pro (pro.php + _pro_helpers.php + dev_pro.php) ────────────
CREATE TABLE IF NOT EXISTS pro_requests (
    id                  SERIAL       PRIMARY KEY,
    user_id             INTEGER      NOT NULL,
    user_email          VARCHAR(160) NOT NULL,
    plan                VARCHAR(20)  NOT NULL DEFAULT 'yearly', -- yearly | monthly
    amount              VARCHAR(40),
    currency            VARCHAR(8),
    bank_account        VARCHAR(160),
    transfer_reference  VARCHAR(120) NOT NULL,
    sender_name         VARCHAR(160),
    notes               TEXT,
    status              VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending|approved|rejected
    reviewed_by         VARCHAR(160),
    reviewed_at         TIMESTAMP,
    reject_reason       VARCHAR(255),
    tenant_id           INTEGER      NOT NULL,
    created_at          TIMESTAMP    DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pro_requests_user   ON pro_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_pro_requests_status ON pro_requests(status);
CREATE INDEX IF NOT EXISTS idx_pro_requests_tenant ON pro_requests(tenant_id);

-- إضافة قيد FK إلى tenants(id) فقط إن لم يكن موجوداً مسبقاً (آمن للتشغيل
-- المتكرر — DO NOTHING بدل فشل الترحيل بخطأ "constraint already exists")
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'fk_pro_requests_tenant'
    ) THEN
        ALTER TABLE pro_requests
            ADD CONSTRAINT fk_pro_requests_tenant
            FOREIGN KEY (tenant_id) REFERENCES tenants(id);
    END IF;
END $$;

-- ── ⚙️ حالة التحكم العامة بالتطبيق (dev_control.php + app_control.php) ──────
-- جدول عام واحد بلا tenant_id (يخص كل النظام لا متجراً بعينه) — صف واحد فقط
-- (id=1) يُقرأ/يُحدَّث دائماً عبر WHERE id = 1.
CREATE TABLE IF NOT EXISTS app_control (
    id                    SERIAL       PRIMARY KEY,
    maintenance_mode      SMALLINT     NOT NULL DEFAULT 0,
    maintenance_message   TEXT,
    force_update          SMALLINT     NOT NULL DEFAULT 0,
    min_supported_build   INTEGER      NOT NULL DEFAULT 1,
    latest_build          INTEGER      NOT NULL DEFAULT 1,
    latest_apk_url        VARCHAR(255),
    announcement_title    VARCHAR(160),
    announcement_body     TEXT,
    announcement_active   SMALLINT     NOT NULL DEFAULT 0,
    updated_at            TIMESTAMP    DEFAULT NOW()
);

-- بذرة الصف الوحيد id=1 بقيم افتراضية آمنة (لا صيانة، لا إجبار تحديث) —
-- لا يُنفَّذ شيء إن كان الصف موجوداً بالفعل في الإنتاج.
INSERT INTO app_control (id, maintenance_mode, force_update, min_supported_build, latest_build, announcement_active)
VALUES (1, 0, 0, 1, 1, 0)
ON CONFLICT (id) DO NOTHING;
-- ضبط تسلسل SERIAL بعد الإدخال اليدوي بمعرّف صريح =1 (تفادياً لتعارض مستقبلي
-- لو أضاف أحد صفاً جديداً بدون تحديد id صراحة)
SELECT setval('app_control_id_seq', (SELECT MAX(id) FROM app_control));
