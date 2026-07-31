-- ─────────────────────────────────────────────────────────────────────────────
-- Task 8 — نظام النسخ الاحتياطي والاستعادة المركزي والتلقائي (Server-Side)
-- ─────────────────────────────────────────────────────────────────────────────
-- يختلف هذا النظام جذرياً عن النسخ الاحتياطي الحالي في تطبيق الجوّال (JSON
-- محلي / مشاركة / Google Drive الشخصي للمستخدم) من جهتين:
--   1) "مركزي": يُخزَّن على خادم/قاعدة بيانات المنصّة نفسها (لا يعتمد على
--      حساب Google Drive شخصي لأي مستخدم قد لا يكون متصلاً بها إطلاقاً).
--   2) "تلقائي": يُنشأ من نفسه بشكل دوري بدون أي تدخل من المستخدم (عبر
--      maybe_auto_backup() المُستدعاة بفرصة كل تسجيل دخول ناجح + دعم اختياري
--      لـ cron حقيقي عبر backup_cron.php لمن يملك صلاحية جدولة على الخادم).
--
-- كل نسخة احتياطية = لقطة (snapshot) كاملة لكل الجداول التشغيلية الخاصة
-- بمتجر (tenant) واحد، مخزَّنة كـ JSONB ضمن صف واحد، قابلة للاستعادة الكاملة
-- (Transaction واحدة تحذف الحالي وتُدرِج من اللقطة) من داخل التطبيق مباشرة
-- من قبل مدير المتجر (require_admin) مع تأكيد صريح.

CREATE TABLE IF NOT EXISTS tenant_backups (
    id           BIGSERIAL PRIMARY KEY,
    tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    backup_data  JSONB   NOT NULL,
    size_bytes   INTEGER NOT NULL DEFAULT 0,
    is_automatic BOOLEAN NOT NULL DEFAULT TRUE,
    triggered_by VARCHAR(255),
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- استعلام القائمة الأكثر شيوعاً: كل نسخ متجر معيّن، الأحدث أولاً
CREATE INDEX IF NOT EXISTS idx_tenant_backups_tenant_created
    ON tenant_backups (tenant_id, created_at DESC);

-- استعلام التنظيف الدوري (retention): آخر النسخ التلقائية لكل متجر
CREATE INDEX IF NOT EXISTS idx_tenant_backups_auto
    ON tenant_backups (tenant_id, is_automatic, created_at DESC);
