-- ═══════════════════════════════════════════════════════════════════════════
-- Task 7 — فرض عدم قابلية تعديل سجل التدقيق (audit_log) على مستوى قاعدة البيانات
-- ═══════════════════════════════════════════════════════════════════════════
-- الهدف: منع أي UPDATE أو DELETE على صفوف audit_log بشكل غير قابل للتجاوز من
-- كود PHP (حتى لو تم اختراق التطبيق أو استُخدم خطأ برمجي)، مع الاستمرار في
-- السماح بحالة استثنائية واحدة ومقصودة فقط: حذف سجلات متجر (tenant) بالكامل
-- عند حذف ذلك المتجر نهائياً من لوحة المطوّر (dev_tenants.php) — وهو إجراء
-- "الحق في المحو" الكامل لحساب، لا تلاعب بسجل فردي.
--
-- لماذا Trigger لا REVOKE؟
--   التطبيق يتصل بقاعدة البيانات بدور واحد قوي (postgres) يُستخدم لكل شيء
--   (INSERT/SELECT/UPDATE/DELETE على كل الجداول) — REVOKE على هذا الدور غير
--   عملي (يكسر عمليات أخرى مشروعة ويمكن التراجع عنه ببساطة GRANT مرة أخرى من
--   نفس الاتصال). المُشغِّل (Trigger) يُطبَّق على مستوى الصف نفسه بمعزل عن
--   صلاحيات الدور — لا يمكن لأي استعلام SQL عادي تجاوزه، فقط تعديل بنية
--   قاعدة البيانات نفسها (DDL) يمكنه إزالته، وهو إجراء مختلف تماماً (ونادر
--   ويتطلب صلاحيات DDL كاملة، ويُسجَّل في سجلات قاعدة البيانات لو فُعِّل).
--
-- آلية الاستثناء الوحيد المسموح به (حذف متجر بالكامل):
--   يضبط dev_tenants.php المتغيّر المحلي للمعاملة (transaction-scoped GUC)
--   عبر: SET LOCAL jawali.allow_audit_purge = 'on';
--   قبل حذف صفوف tenant_id المحدَّد من audit_log مباشرة. هذا المتغيّر يُعاد
--   تلقائياً لقيمته الافتراضية ('off') عند COMMIT/ROLLBACK لتلك المعاملة —
--   لا يُمكن "تفعيله بشكل دائم" بالخطأ أو النية السيئة.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE OR REPLACE FUNCTION audit_log_immutable_guard()
RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        -- ✅ لا يُسمح بتعديل أي سجل تدقيق مطلقاً — بلا أي استثناء. تعديل
        -- محتوى سجل تدقيق موجود هو بالتعريف تلاعب/إخفاء دليل.
        RAISE EXCEPTION 'audit_log records are immutable and cannot be updated (row id=%)', OLD.id
            USING ERRCODE = '23000';
    ELSIF TG_OP = 'DELETE' THEN
        -- ✅ الاستثناء الوحيد: حذف متجر بالكامل عبر لوحة المطوّر، الذي يضبط
        -- هذا المتغيّر المحلي للمعاملة عمداً قبل الحذف الجماعي.
        IF current_setting('jawali.allow_audit_purge', true) IS DISTINCT FROM 'on' THEN
            RAISE EXCEPTION 'audit_log records are immutable and cannot be deleted (row id=%) — use tenant purge flow if intentional', OLD.id
                USING ERRCODE = '23000';
        END IF;
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_audit_log_immutable ON audit_log;
CREATE TRIGGER trg_audit_log_immutable
    BEFORE UPDATE OR DELETE ON audit_log
    FOR EACH ROW
    EXECUTE FUNCTION audit_log_immutable_guard();

-- ملاحظة: INSERT غير متأثر بهذا المُشغِّل مطلقاً — audit() في _db.php يستمر
-- بالعمل بلا أي تغيير.
