-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 005: جدول جلسات تسجيل الدخول المعلَّقة بانتظار تحقق 2FA
-- ─────────────────────────────────────────────────────────────────────────────
-- يُستخدم في تدفق تسجيل الدخول ثنائي الخطوة الجديد (auth.php):
--   1) POST action=login (بريد + كلمة مرور) — إذا كان tfa_enabled=1 للمستخدم،
--      لا يُصدر الخادم JWT كامل الصلاحيات فوراً؛ بل يُنشئ سجلاً هنا برمز عشوائي
--      قصير الأجل (5 دقائق) ويُعيده للعميل كـ "tfa_token" مؤقت لا يصلح لأي
--      استدعاء API آخر (ليس JWT، ولا يُقبل في require_auth()).
--   2) POST action=verify_2fa (tfa_token + code) — يتحقق الخادم من الرمز مقابل
--      users.tfa_secret، وعندها فقط يُصدر JWT الحقيقي كامل الصلاحيات.
--
-- هذا يُغلق الثغرة الأمنية التي كانت تسمح لأي عميل يتجاوز واجهة Flutter
-- (باستدعاء API مباشر) بالحصول على وصول كامل بعد كلمة المرور فقط، رغم تفعيل
-- 2FA على حساب المدير.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS tfa_pending_logins (
    id          SERIAL PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(64) NOT NULL UNIQUE,
    ip_address  VARCHAR(64),
    created_at  TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at  TIMESTAMP NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_tfa_pending_token ON tfa_pending_logins (token_hash);
CREATE INDEX IF NOT EXISTS idx_tfa_pending_expires ON tfa_pending_logins (expires_at);
