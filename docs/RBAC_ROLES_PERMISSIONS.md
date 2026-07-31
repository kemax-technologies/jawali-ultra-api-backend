# 🔐 نظام الأدوار والصلاحيات في جوالي ألترا (RBAC)

> **حالة الوثيقة**: نهائية ودقيقة — تعكس الكود الفعلي في `backend/_db.php` (مصدر الحقيقة) و`mobile_app/lib/config/permissions.dart` (المرآة على Flutter) بعد مراجعة شاملة وتصحيح كل تجاوزات/تناقضات الصلاحيات المكتشفة.
>
> **تاريخ آخر تدقيق شامل**: تمت مراجعة كل ملفات API الخادم (29 ملفاً) سطراً بسطر، ومقارنة كل نقطة تحقّق (`require_auth` / `require_admin` / `require_role` / `require_permission`) بمصفوفة الصلاحيات الافتراضية لكل دور، وتصحيح كل تجاوز/تناقض مؤكد.

---

## 1) نظرة عامة على النظام

جوالي ألترا نظام SaaS متعدد المتاجر (Multi-Tenant). كل متجر (`tenant`) له مستخدموه المستقلون تماماً عن متاجر أخرى. لكل مستخدم:

- **دور واحد** من 9 أدوار ثابتة (`APP_ROLES`).
- **صلاحيات فعلية** (`effective_permissions`) = صلاحيات الدور الافتراضية (`role_default_permissions`) **مع إمكانية تخصيص/تجاوز** بعض الصلاحيات لكل مستخدم على حدة (حقل `permissions` JSON في جدول `users`).

⚠️ **تنبيه معماري مهم**: هذا نظام صلاحيات على **مستوى المتجر (Tenant-level)** فقط. هناك طبقة صلاحيات منفصلة تماماً وأعلى مستوى وهي **صلاحيات المنصّة (Platform-level)** الخاصة بمالك تطبيق جوالي ألترا نفسه (عبر لوحة المطوّر `dev_*.php` بمصادقة `dev_require_auth()` المستقلة كليّاً). لا يملك أي دور من الأدوار التسعة أدناه — ولا حتى "مدير" (صاحب المتجر) — أي صلاحية على مستوى المنصّة (مثل الموافقة على طلبات الترقية Pro، أو إدارة تذاكر الدعم، أو حذف/تعليق متاجر أخرى). هذا التصميم مطبَّق ومحمي في `pro.php` (دالة `pro_deny_store_admin()`) وفي `support.php` (دالة `support_is_admin() { return false; }`) — انظر القسم 6.

### مصدر الحقيقة (Source of Truth)

| الطبقة | الملف | الغرض |
|---|---|---|
| **الخادم (PHP)** | `backend/_db.php` | `APP_ROLES`, `APP_PERMISSIONS`, `role_default_permissions()`, `effective_permissions()`, `require_auth()`, `require_admin()`, `require_role()`, `require_permission()`, `ensure_permission()` |
| **العميل (Flutter)** | `mobile_app/lib/config/permissions.dart` | `AppRoles`, `AppPermissions` — مرآة حرفية للمنطق أعلاه، تُستخدم فقط كخط دفاع ثانٍ/احتياطي على العميل (offline، دخول بيومتري، دخول اجتماعي أول مرة) — **المرجع النهائي والحاسم دائماً هو ما يُرجعه الخادم** في `permissions[]` عند تسجيل الدخول (`auth.php?action=login`) وعبر `require_auth()` الذي يُعيد جلب الدور/الصلاحيات من قاعدة البيانات الحيّة **في كل طلب**، لا من التوكن (JWT) المخزَّن. |

> **قاعدة صيانة صارمة**: أي تعديل على الأدوار أو الصلاحيات في `_db.php` **يجب** أن يُقابله تعديل مماثل فوراً في `permissions.dart`، والعكس صحيح. الانحراف بين الملفَين هو أخطر مصدر لثغرات هذا النظام.

---

## 2) الأدوار التسعة (بدون أي تغيير في القائمة أو الأسماء)

| # | الدور (بالعربية) | الوصف الرسمي المختصر |
|---|---|---|
| 1 | **مدير** (`admin`) | صاحب المتجر (Tenant Owner) — كل الصلاحيات التشغيلية على متجره فقط |
| 2 | **محاسب** (`accountant`) | القيود المحاسبية والتقارير المالية فقط |
| 3 | **أمين مخزن** (`warehouseKeeper`) | المنتجات والمخزون والجرد والتحويلات، بدون أرباح/حسابات |
| 4 | **كاشير** (`cashier`) | واجهة بيع مبسّطة، بدون أرباح أو تعديل سعر أو حذف فواتير |
| 5 | **موظف مبيعات** (`salesEmployee`) | عروض الأسعار والطلبات والعملاء |
| 6 | **مراقب** (`supervisor`) | مراقبة واعتماد الخصومات/المرتجعات والتقارير |
| 7 | **مدير فرع** (`branchManager`) | صلاحيات كاملة تقريباً، لكنها نطاقاً محدودة تشغيلياً بفرع واحد |
| 8 | **خدمة عملاء** (`customerService`) | العملاء والطلبات والمرتجعات والشكاوى |
| 9 | **موظف** (`employee`) | موظف عام بصلاحيات محدودة (الدور الافتراضي الأقل صلاحية عند عدم التحديد) |

---

## 3) الصلاحيات الـ26 (بدون أي إضافة أو حذف)

| المفتاح (كود) | الاسم بالعربية | ملاحظة |
|---|---|---|
| `sell` | بيع | |
| `purchase` | شراء | |
| `returns` | مرتجعات | |
| `discounts` | خصومات | |
| `editPrice` | تعديل السعر | |
| `deleteInvoice` | حذف فاتورة | حذف نهائي — حساس جداً |
| `cancelInvoice` | إلغاء فاتورة | إلغاء بدون حذف السجل — حساس |
| `openDrawer` | فتح الدرج | |
| `manageInventory` | إدارة المخزون | |
| `printBarcode` | طباعة باركود | |
| `editProducts` | تعديل المنتجات | إنشاء/تعديل/حذف منتجات |
| `reports` | التقارير | التقارير التشغيلية العامة (لوحة تحكم، مبيعات، مخزون، تحليلات) |
| `financialReports` | التقارير المالية | |
| `profits` | الأرباح | تقرير الربحية (إيراد − مصروفات) |
| `settings` | الإعدادات | إعدادات المتجر العامة |
| `manageUsers` | المستخدمون | إنشاء/تعديل/تعطيل مستخدمي المتجر |
| `backup` | النسخ الاحتياطي | |
| `activityLog` | سجل النشاط | سجل التدقيق (audit log) |
| `manageBranches` | إدارة الفروع | |
| `manageTaxes` | إدارة الضرائب | |
| `manageCurrencies` | إدارة العملات | |
| `managePaymentMethods` | إدارة طرق الدفع | |
| `manageOffers` | إدارة العروض | |
| `approveSensitive` | الموافقة على العمليات الحساسة | |
| `deleteSystem` | حذف النظام | 👑 **حصراً لمالك التطبيق (منصّة) — لا دور تشغيلي يملكها، حتى "مدير"** |
| `manageLicense` | إدارة الترخيص | 👑 **حصراً لمالك التطبيق (منصّة) — لا دور تشغيلي يملكها، حتى "مدير"** |

---

## 4) مصفوفة الأدوار × الصلاحيات الافتراضية (9 × 26)

القيم أدناه من `role_default_permissions()` في `_db.php` (والمطابقة حرفياً في `defaultsForRole()` بـ `permissions.dart`). ✅ = ممنوحة افتراضياً، ⬜ = غير ممنوحة افتراضياً (لكن يمكن تفعيلها فردياً كـ *override* لمستخدم معيّن دون تغيير تعريف الدور العام).

| الصلاحية | مدير | محاسب | أمين مخزن | كاشير | موظف مبيعات | مراقب | مدير فرع | خدمة عملاء | موظف |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| sell | ✅ | ⬜ | ⬜ | ✅ | ✅ | ⬜ | ✅ | ⬜ | ✅ |
| purchase | ✅ | ⬜ | ✅ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| returns | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ✅ | ✅ | ⬜ |
| discounts | ✅ | ⬜ | ⬜ | ✅ | ✅ | ✅ | ✅ | ⬜ | ⬜ |
| editPrice | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| deleteInvoice | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| cancelInvoice | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| openDrawer | ✅ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| manageInventory | ✅ | ⬜ | ✅ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| printBarcode | ✅ | ⬜ | ✅ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| editProducts | ✅ | ⬜ | ✅ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| reports | ✅ | ✅ | ⬜ | ⬜ | ✅ | ✅ | ✅ | ✅ | ✅ |
| financialReports | ✅ | ✅ | ⬜ | ⬜ | ⬜ | ✅ | ✅ | ⬜ | ⬜ |
| profits | ✅ | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| settings | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| manageUsers | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| backup | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| activityLog | ✅ | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| manageBranches | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| manageTaxes | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| manageCurrencies | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| managePaymentMethods | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| manageOffers | ✅ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ✅ | ⬜ | ⬜ |
| approveSensitive | ✅ | ✅ | ⬜ | ⬜ | ⬜ | ✅ | ✅ | ⬜ | ⬜ |
| deleteSystem | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |
| manageLicense | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ | ⬜ |

**ملاحظات على القاعدة**:
- **مدير** = كل الصلاحيات الـ26 **باستثناء** `deleteSystem` و`manageLicense` (صلاحيتا منصّة حصريتان لمالك التطبيق).
- **مدير فرع** = نفس منطق "مدير" لكن باستثناء 4 صلاحيات إضافية: `deleteSystem`, `manageLicense`, `manageBranches`, `backup` — أي أن مدير الفرع يملك عملياً **كل الصلاحيات التشغيلية** (بما فيها `manageUsers`, `manageTaxes`, `manageCurrencies`, `managePaymentMethods`, `manageOffers`, `settings`, `activityLog`...) لكنه لا يستطيع إدارة الفروع نفسها أو أخذ نسخ احتياطية على مستوى المتجر الكامل.
- **مسموح دائماً بتخصيص فردي (Per-user Override)**: أي مستخدم — بغض النظر عن دوره — يمكن أن تُمنح له/تُسحب منه أي صلاحية فردياً عبر `users.php` (حقل `permissions` JSON) دون تغيير تعريف دوره العام. هذا التخصيص الفردي **لا يغيّر** جدول القيم الافتراضية أعلاه، بل يُطبَّق فوقه كطبقة override.

---

## 5) آليات فرض الصلاحيات في الخادم (PHP)

يحتوي `_db.php` على 4 دوال فرض صلاحيات، لكل منها استخدام مقصود ومختلف:

| الدالة | ماذا تفحص | متى تُستخدم بشكل صحيح |
|---|---|---|
| `require_auth()` | مصادقة أساسية فقط (JWT صالح + حساب نشط + متجر نشط + جلسة غير منتهية) — **بلا أي فحص دور أو صلاحية** | العمليات التشغيلية العامة التي يحتاجها كل الموظفين بغض النظر عن دورهم (مثال: تصفح المنتجات لبيعها، إنشاء فاتورة، تسجيل مرتجع) |
| `require_admin()` | فحص **حرفي** لحقل الدور: `role === 'مدير'` فقط — **يتجاوز نظام الصلاحيات الدقيقة بالكامل** | فقط للعمليات المخصصة فعلاً وعمداً لدور "مدير" حصراً (مثال: حذف نهائي لسجل حساس كتنظيف بيانات، لا يوجد بديل دقيق منطقي له) |
| `require_role(array $roles)` | فحص الدور ضمن قائمة أدوار مسموحة محددة | حالات نادرة تحتاج مجموعة أدوار محددة بالاسم لا صلاحية واحدة |
| `require_permission(string $perm)` | يستدعي `require_auth()` ثم يفحص الصلاحية **الفعلية** (`effective_permissions`) بغض النظر عن اسم الدور | **الآلية الصحيحة والمفضّلة دائماً** — تدعم كل الأدوار التي تملك الصلاحية بشكل تلقائي، وتدعم أيضاً التخصيص الفردي (Per-user Override) |
| `ensure_permission(array $auth, string $perm)` *(جديدة)* | نفس فحص `require_permission()` لكن على `$auth` مُستخرجة مسبقاً (بدون إعادة `require_auth()` واستعلام قاعدة بيانات إضافي) | الملفات التي تحتاج صلاحيات مختلفة لكل نوع/action ضمن الملف نفسه بعد `require_auth()` واحد في الأعلى (مثال: `reports.php`) |

### ⚠️ الخطورة الجوهرية لـ `require_admin()`

لأن `require_admin()` يفحص **اسم الدور حرفياً** بدل الصلاحية الفعلية، فهو **يتجاوز** نظام RBAC الدقيق تماماً. أي دور آخر يملك الصلاحية المطلوبة فعلياً حسب `role_default_permissions()` (مثل "مدير فرع" الذي يملك `manageUsers`, `settings`, `activityLog` وغيرها) سيُحظر رغم ذلك، لأن الفحص لا ينظر إلى الصلاحية بل إلى اسم الدور فقط. هذا بالضبط ما تم اكتشافه وتصحيحه في هذه المراجعة (انظر القسم 7).

**قاعدة الاستخدام الصحيحة من الآن فصاعداً**: أي endpoint يفرض قيداً "للمدير فقط"، يجب أولاً التحقق: هل توجد صلاحية دقيقة في `APP_PERMISSIONS` تُعبّر عن هذا القيد بدقة أكبر؟ إن وُجدت → استخدم `require_permission()`/`ensure_permission()`. إن لم توجد صلاحية مناسبة إطلاقاً (عملية إدارية استثنائية بلا مجال منطقي دقيق) → يبقى `require_admin()` مقبولاً كخيار أخير فقط.

---

## 6) الفصل بين صلاحيات المتجر (Tenant) وصلاحيات المنصّة (Platform)

هذا نمط معماري إلزامي في جوالي ألترا: أي عملية تخص **المنصّة ككل** (كل المتاجر، أو تشغيل التطبيق نفسه كمنتج SaaS) — مثل الموافقة على طلبات ترقية Pro، أو إدارة تذاكر الدعم الفني، أو تعليق/حذف متجر بالكامل، أو إدارة كل المطوّرين — **لا يجوز أبداً** أن تكون في متناول أي دور من أدوار المتجر التسعة، ولا حتى "مدير" (صاحب المتجر) رغم أنه يملك أوسع الصلاحيات على مستوى متجره الخاص.

### مرجعان صحيحان مطبَّقان فعلاً في الكود (لا يحتاجان تعديلاً):

- **`pro.php`** — دالة `pro_deny_store_admin()`: تحجب صراحة كل عمليات إدارة طلبات الترقية (list/approve/reject/revoke) عن أي مستخدم متجر، بمن فيهم "مدير"، وتوجّه فعلياً لاستخدام لوحة المطوّر المستقلة (`dev_pro.php`, `dev_stats.php`, `dev_users.php`) بمصادقة `dev_require_auth()` منفصلة كليّاً.
- **`support.php`** — دالة `support_is_admin() { return false; }`: تحجب صراحة كل عمليات إدارة تذاكر الدعم (تحديث حالة/رد رسمي) عن أي مستخدم متجر، وتوجّه لاستخدام لوحة المطوّر المستقلة (`dev_support.php`). العمليات المتاحة للمستخدم العادي (`create`, `list`, `messages`, `reply`) مقيَّدة بأمان على مستوى الاستعلام (`WHERE user_id = auth['sub']`) بحيث لا يرى المستخدم إلا تذاكره الخاصة فقط.

هذا النمط يجب اتباعه في أي ميزة مستقبلية على مستوى المنصّة: **لا تستخدم `require_admin()` لحجب مستخدمي المتجر عن ميزة منصّة — استخدم دالة حجب صريحة وواضحة (`xxx_deny_store_admin()`) بدل ذلك، لتوضيح أن هذا ليس مجرد قيد صلاحية عادي بل فصل معماري كامل بين طبقتين مختلفتين من النظام.**

---

## 7) التصحيحات المطبَّقة في هذه المراجعة (تجاوزات وتناقضات صلاحيات)

بعد فحص شامل لكل ملفات API الخادم (29 ملفاً)، تم تحديد وتصحيح 5 مواضع تجاوز/تناقض مؤكدة. كل التصحيحات مُطبَّقة الآن في كلا نسختي المشروع (`jawali_backend/jawali_api` و`github_push_workspace/repo/backend`) ومفحوصة بـ `php -l` بلا أخطاء.

| # | الملف | المشكلة قبل التصحيح | نوع المشكلة | التصحيح المطبَّق |
|---|---|---|---|---|
| 1 | `users.php` | كل عمليات المستخدمين (GET/POST/DELETE) محمية بـ `require_admin()` — فحص دور حرفي "مدير" فقط | **تناقض (Too Restrictive)**: "مدير فرع" يملك صلاحية `manageUsers` ضمن صلاحياته الافتراضية لكنه كان يُحظر بلا داعٍ | استُبدل بـ `require_permission('manageUsers')` — يسمح الآن لكل من يملك الصلاحية فعلياً (مدير + مدير فرع) |
| 2 | `products.php` | POST (إنشاء/تعديل منتج) وDELETE (حذف منتج) محميان بـ `require_auth()` فقط — بلا أي فحص صلاحية | **تجاوز (Overreach)**: أي مستخدم مصادَق (حتى كاشير أو خدمة عملاء، اللذين لا يملكان `editProducts` افتراضياً) كان يستطيع إنشاء/تعديل/حذف منتجات | استُبدلا بـ `require_permission('editProducts')` في كليهما |
| 3 | `reports.php` | كل الأنواع (`dashboard`, `sales`, `top_products`, `inventory`, `analytics`) بـ `require_auth()` فقط؛ نوع `profit` أيضاً بـ `require_auth()` فقط؛ نوع `audit` بفحص دور حرفي `role !== 'مدير'` | **تجاوز + تناقض مزدوج**: (أ) أي مستخدم مصادَق كان يرى تقارير مالية/تشغيلية حتى لو لا يملك `reports`/`profits`؛ (ب) "محاسب" و"مدير فرع" يملكان `activityLog` لكنهما كانا يُحظران من نوع `audit` بسبب الفحص الحرفي | أُضيفت `ensure_permission($auth, 'reports')` لـ dashboard/sales/top_products/inventory/analytics؛ `ensure_permission($auth, 'profits')` لـ profit؛ واستُبدل فحص الدور الحرفي في `audit` بـ `ensure_permission($auth, 'activityLog')` |
| 4 | `audit.php` | GET (قراءة سجل التدقيق) محمي بـ `require_admin()` | **تناقض (Too Restrictive)**: نفس مشكلة #3(ب) — "محاسب" و"مدير فرع" يملكان `activityLog` لكنهما محظوران | استُبدل بـ `require_permission('activityLog')` |
| 5 | `settings.php` | POST (تعديل إعدادات المتجر) محمي بـ `require_auth()` فقط | **تجاوز (Overreach)**: أي مستخدم مصادَق كان يستطيع تعديل إعدادات المتجر رغم وجود صلاحية `settings` مخصّصة لهذا الغرض بالضبط | استُبدل بـ `require_permission('settings')` |

### دالة مساعدة جديدة: `ensure_permission()`

أُضيفت إلى `_db.php` (بجانب `require_permission()` الموجودة) لأن `reports.php` يستدعي `require_auth()` **مرة واحدة** في أعلى الملف (خارج أي `switch`)، ثم يحتاج صلاحيات **مختلفة** لكل نوع تقرير على حدة. استدعاء `require_permission()` من جديد لكل نوع كان سيُكرر استعلام قاعدة البيانات بلا فائدة (لأن `require_auth()` نفسه يُستدعى داخلها من جديد). الحل: `ensure_permission(array $auth, string $permission)` تفحص الصلاحية مباشرة من مصفوفة `$auth` المُستخرجة مسبقاً، دون إعادة الاستعلام.

```php
function ensure_permission(array $auth, string $permission): void {
    $perms = $auth['permissions'] ?? [];
    if (!in_array($permission, $perms, true)) {
        json_error('غير مصرح — تحتاج صلاحية "' . $permission . '"', 403);
    }
}
```

---

## 8) قرارات تصميم متعمّدة — لا تُعتبر أخطاء (بعد المراجعة والتحقق)

الملفات/الحالات التالية فُحصت بعناية أثناء المراجعة الشاملة، وتقرر **الإبقاء عليها كما هي دون تعديل**، للأسباب المذكورة تحت كل حالة. هذا القرار متعمّد وليس إغفالاً، ويُوثَّق هنا حرصاً على الدقة الكاملة المطلوبة:

### 8.1 عمليات مالية/تشغيلية حساسة بلا صلاحية دقيقة مخصّصة لها

لا توجد في `APP_PERMISSIONS` (26 صلاحية) صلاحية تُعبّر بدقة عن مفاهيم مثل "إدارة الصناديق النقدية" أو "صرف الرواتب" أو "تحويل الأموال بين الأفراد" — وبناءً على توجيه صريح بالحفاظ على القائمة الحالية للأدوار والصلاحيات دون توسيعها، أُبقيت هذه العمليات على نمطها الحالي المتّسق مع بقية النظام: `require_auth()` للعمليات التشغيلية اليومية (لأنها ضرورية لعمل الكاشير/الموظف العادي)، و`require_admin()` فقط لعمليات الحذف النهائي:

| الملف | العملية | الحماية الحالية | السبب |
|---|---|---|---|
| `cashboxes.php` | إنشاء حساب / تحويل / إيداع وسحب | `require_auth()` | لا صلاحية دقيقة "إدارة الصناديق" — `openDrawer` أضيق مفهوماً (فتح الدرج وقت البيع فقط) |
| `employees.php` | `action=pay` (صرف راتب) | `require_auth()` | لا صلاحية دقيقة "صرف رواتب"؛ أقرب صلاحيتين مفهوماً (`financialReports`, `approveSensitive`) لا تُطابقان المعنى تماماً |
| `quotations.php` | `action=convert` (تحويل عرض سعر لفاتورة فعلية) | `require_auth()` | يُعامَل معاملة "إنشاء فاتورة" العادية (`require_auth()` في `invoices.php` نفسه) |
| `returns.php` | POST (تسجيل مرتجع بيع + إعادة مخزون) | `require_auth()` | يُعامَل بنفس منطق تسجيل عملية بيع/فاتورة عادية |
| `transfers.php` | إنشاء/صرف/إلغاء حوالة مالية | `require_auth()` | لا صلاحية دقيقة "تحويلات مالية بين أفراد" — نشاط مستقل عن `sell`/`purchase` |
| `warehouses.php` | POST (إنشاء مخزن / تحويل مخزون بين مخازن) | `require_auth()` | أقرب مفهوماً لـ `manageInventory`، لكنها ليست مطابقة حرفياً؛ النمط الحالي متّسق مع `products.php` قبل الإصلاح لكنه هنا مقصود لأن نقل مخزون داخلي بين فروع نفس المتجر أقل حساسية من تعديل تعريف منتج |

**جميعها** تحمي عمليات الحذف النهائي بـ `require_admin()` بشكل صحيح ومتّسق (`cashboxes.php`, `employees.php`, `quotations.php`, `returns.php`, `transfers.php`, `warehouses.php`, `purchase_returns.php` — كلها DELETE محمية بـ `require_admin()`).

> **توصية مستقبلية (خارج نطاق هذه المراجعة)**: إذا احتاج المستخدم مستقبلاً تحكماً أدق في هذه العمليات، يمكن النظر في إضافة صلاحيات جديدة مخصّصة (مثل `manageCashboxes`, `payPayroll`, `manageTransfers`) — لكن هذا يتعارض مع التوجيه الحالي الصريح بالحفاظ على القائمة كما هي.

### 8.2 نقاط نهاية بلا مصادقة JWT — آمنة بتصميم بديل (False Positives)

| الملف | آلية الحماية الفعلية | لماذا هي آمنة |
|---|---|---|
| `app_control.php` | بلا مصادقة | إعدادات عامة غير حساسة (تحكم بإصدار التطبيق) — مصمَّم للوصول العام عمداً |
| `scanner_poll.php` | جلسة مؤقتة (`session_id`) مرتبطة بـ `tenant_id` عند إنشائها | الجهاز المساعد (كاميرا الباركود) لا يملك تسجيل دخول مستخدم، ويُصادَق بجلسة قصيرة العمر محصورة بمتجر واحد فقط |
| `scanner_scan.php` | توقيع HMAC (مُشتق من `JWT_SECRET` + `session_id`) + بصمة جهاز (`device_fingerprint`) + تحديد معدل (Rate Limiting) | نفس منطق `scanner_poll.php` مع طبقات حماية إضافية ضد التلاعب/إعادة الاستخدام |

### 8.3 مسارات مصادقة عامة (تسجيل دخول/تسجيل/استعادة كلمة مرور) — بلا `require_auth()` بتصميم صحيح

`auth.php` (login/register)، `password_recovery.php`، `social_auth.php` — هذه المسارات **تسبق** وجود جلسة مصادَقة أصلاً (المستخدم يحاول تسجيل الدخول لأول مرة)، لذا لا تُطبَّق عليها `require_auth()` بشكل منطقي؛ حمايتها الفعلية عبر: تحديد معدل الطلبات (Rate Limiting بـ `rl_check()`)، تأخير موحّد لمنع Timing Attack، واستجابات موحّدة لمنع Account Enumeration. حالة `confirm_admin` في `auth.php` مصادَقة بـ `require_auth()` بشكل صحيح لأنها تتطلب جلسة مستخدم حالية + تحقق من مدير من **نفس المتجر فقط**.

### 8.4 `sync.php` — مزامنة بيانات أوفلاين

يستخدم `require_auth()` فقط لكل من GET (سحب البيانات) وPOST (رفع فواتير أوفلاين) بتصميم متعمّد يخدم آلية المزامنة الشاملة بلا تمييز أدوار، لأن كل الأدوار تحتاج مزامنة بياناتها الأساسية.

---

## 9) خريطة الصلاحيات الكاملة على مستوى Endpoints (بعد التصحيح)

| الملف | GET | POST (الافتراضي) | Actions خاصة | DELETE |
|---|---|---|---|---|
| `products.php` | `require_auth()` | `require_permission('editProducts')` | — | `require_permission('editProducts')` |
| `invoices.php` | `require_auth()` | `require_auth()` | `action=cancel` → `require_permission('cancelInvoice')` | `require_permission('deleteInvoice')` |
| `users.php` | `require_permission('manageUsers')` | `require_permission('manageUsers')` | — | `require_permission('manageUsers')` |
| `settings.php` | `require_auth()` | `require_permission('settings')` | — | — |
| `reports.php` | — | — | `type=dashboard/sales/top_products/inventory/analytics` → `reports`؛ `type=profit` → `profits`؛ `type=audit` → `activityLog` (جميعها عبر `ensure_permission()` بعد `require_auth()` واحد في الأعلى) | — |
| `audit.php` | `require_permission('activityLog')` | `require_auth()` (تسجيل حدث تدقيق — مسموح لأي مستخدم مصادَق) | — | — |
| `customers.php` | `require_auth()` | `require_auth()` | — | — |
| `suppliers.php` | `require_auth()` | `require_auth()` | — | — |
| `expenses.php` | `require_auth()` | `require_auth()` | — | — |
| `credits.php` | `require_auth()` | `require_auth()` | — | `require_admin()` |
| `credit_payments.php` | `require_auth()` | `require_auth()` | — | `require_admin()` |
| `accounting.php` | `require_auth()` | `require_auth()` | — | `require_admin()` |
| `assets.php` | `require_auth()` | `require_auth()` | — | — |
| `purchases.php` | `require_auth()` | `require_auth()` | — | (لا يوجد) |
| `purchase_returns.php` | `require_auth()` | `require_auth()` | — | `require_admin()` |
| `quotations.php` | `require_auth()` | `require_auth()` | `action=status`, `action=convert` → `require_auth()` | `require_admin()` |
| `returns.php` | `require_auth()` | `require_auth()` | — | `require_admin()` |
| `transfers.php` | `require_auth()` | `require_auth()` | `action=complete`, `action=cancel` → `require_auth()` | `require_admin()` |
| `vouchers.php` | `require_auth()` | `require_auth()` | — | `require_admin()` |
| `warehouses.php` | `require_auth()` | `require_auth()` | `action=transfer` → `require_auth()` | `require_admin()` |
| `cashboxes.php` | `require_auth()` | `require_auth()` | `action=transfer`, `action=deposit` → `require_auth()` | `require_admin()` |
| `employees.php` | `require_auth()` | `require_auth()` | `action=pay` → `require_auth()` | `require_admin()` (سجلات + تعطيل موظف) |
| `sync.php` | `require_auth()` | `require_auth()` | — | — |
| `scanner_session.php` | `require_auth()` | `require_auth()` | — | `require_auth()` |
| `app_control.php` | بلا مصادقة (بتصميم متعمّد) | — | — | — |
| `scanner_poll.php` | جلسة مؤقتة + `tenant_id` مضمَّن | — | — | — |
| `scanner_scan.php` | HMAC + بصمة جهاز + Rate Limiting | — | — | — |
| `auth.php` | — | `login`/`register` بلا مصادقة (بتصميم)؛ `confirm_admin` → `require_auth()` | `me` → `require_auth()` | — |
| `password_recovery.php` | — | بلا مصادقة (بتصميم — استعادة كلمة مرور) | — | — |
| `social_auth.php` | — | بلا مصادقة (بتصميم — تسجيل دخول اجتماعي) | — | — |
| `pro.php` | `status` → `require_auth()`؛ `list` → محجوب لأي دور متجر | `request` → `require_auth()`؛ `approve`/`reject`/`revoke` → محجوبة لأي دور متجر (منصّة فقط) | — | — |
| `support.php` | `require_auth()` (مقيّد بـ `user_id` الخاص فقط) | `require_auth()` (مقيّد بـ `user_id` الخاص فقط) | `action=update` → محجوب لأي دور متجر (منصّة فقط) | — |

---

## 10) نموذج عملي: كيف تعمل الصلاحيات الفعلية (Effective Permissions)

```
effective_permissions(role, overrides) =
    (defaults_for(role) ∪ overrides_enabled) − overrides_disabled
```

مثال: مستخدم دوره "كاشير" (افتراضياً: `sell`, `openDrawer`, `discounts`)، لكن مدير المتجر منحه فردياً صلاحية `returns` إضافية عبر `users.php` (`permissions: {"returns": true}`) — عندها ستكون صلاحياته الفعلية: `sell`, `openDrawer`, `discounts`, `returns`. هذا التخصيص لا يغيّر تعريف دور "كاشير" العام لأي مستخدم آخر بنفس الدور.

---

## 11) خلاصة تنفيذية (Executive Summary)

- ✅ **الأدوار التسعة محفوظة بالضبط كما كانت** — بلا أي إضافة أو حذف أو تغيير اسم.
- ✅ **الصلاحيات الـ26 محفوظة بالضبط كما كانت** — بلا أي إضافة أو حذف.
- ✅ **5 تصحيحات دقة صلاحيات مطبَّقة** في: `users.php`, `products.php`, `reports.php` (6 مواضع داخله)، `audit.php`, `settings.php` — إما لإصلاح تجاوز أمني حقيقي (مستخدم يقوم بعملية دوره لا يخوّلها) أو لإصلاح تناقض دقة (دور يملك الصلاحية توثيقياً لكنه محجوب فعلياً بفحص دور حرفي).
- ✅ **6 حالات فُحصت وتقرر تركها كما هي بتوثيق واضح للسبب** (صناديق نقدية، رواتب، تحويلات مالية، تحويل عروض أسعار، مرتجعات بيع، تحويل مخزون بين مخازن) — لعدم وجود صلاحية دقيقة مطابقة ضمن القائمة الحالية المطلوب الحفاظ عليها.
- ✅ **الفصل بين صلاحيات المتجر والمنصّة محفوظ ومحمي بشكل صريح** في `pro.php` و`support.php` — لا دور متجر (بما فيه "مدير") يتجاوز هذا الفصل.
- ✅ **كل الملفات المعدَّلة (`_db.php`, `users.php`, `products.php`, `reports.php`, `audit.php`, `settings.php`) مفحوصة بـ `php -l` بلا أخطاء صياغية في كلا نسختي المشروع.**
