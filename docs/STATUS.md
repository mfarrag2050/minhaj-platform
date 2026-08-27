# حالة المشروع — minhaj-platform

> هذا الملفّ يصف ما هو **موجود فعلاً في الشيفرة** حتى تاريخ آخر commit. كلّ ادّعاء هنا مأخوذ من ملفّ في المستودع، لا من ذاكرة جلسة أو نيّة مستقبليّة.

---

## 1 · ما هو minhaj-platform؟

نواة منصّة تعليم إسلاميّ عن بُعد مبنيّة إضافةً واحدة على WordPress اسمها `minhaj-core` (ترويسة الإضافة في `plugins/minhaj-core/minhaj-core.php`). تحمل بيانات الطلاب والحلقات والجدول، وتُشكّل الأساس الذي تُركَّب فوقه بقيّة وحدات العمل. الإضافة تتطلّب `PHP 8.2+` و `WordPress 6.4+`، فضاؤها الاسمي `Minhaj\` (PSR-4)، ومعيار الكود WordPress Coding Standards (فرَض تلقائيّ عبر `phpcs.xml.dist`). النطاق النصّي `minhaj-core` مع ستّ لغات مستهدَفة.

---

## 2 · بنية `plugins/minhaj-core`

```
plugins/minhaj-core/
├── minhaj-core.php                  ← ترويسة الإضافة + تعريف الثوابت وتسجيل التحميل
├── assets/
│   └── js/                          ← ملفّات JavaScript الإداريّة
├── includes/
│   ├── Autoloader.php               ← PSR-4 داخل فضاء Minhaj\
│   ├── Plugin.php                   ← Bootstrap مركزي: register_modules() + Migrator
│   ├── Activator.php                ← register_activation_hook — يشغّل Migrator وينشئ الأدوار والقدرات
│   ├── Migrations/                  ← Migration.php (Base) + Migrator.php (تشغيل + جدول versions)
│   └── Modules/
│       └── Groups/                  ← الوحدة الوحيدة المنفَّذة
│           ├── Module.php           ← تسجيل الوحدة في Plugin
│           ├── Events.php           ← ثوابت أسماء الأحداث (do_action hooks)
│           ├── GroupService.php     ← واجهة الأعمال العامّة
│           ├── Roles.php            ← minhaj_student + minhaj_teacher
│           ├── Domain/              ← GroupStatus, GroupType, GroupCapacity, GroupRules, RuleViolationException
│           ├── Migrations/          ← CreateGroupsTables.php
│           ├── Repository/          ← GroupRepository.php + PersistenceException.php
│           └── Admin/               ← AdminController, GroupsListTable, AjaxSearchController, Assets, ErrorMap, AuditFormatter
└── languages/                       ← ملفّات ترجمة .po/.mo (فارغة الآن)
```

---

## 3 · ما يعمل الآن فعلاً

### 3.1 وحدة المجموعات (`Modules\Groups`)

الوحدة الوحيدة المسجَّلة في `Plugin::register_modules()`. تشمل:

- **آلة حالات كاملة** — `GroupStatus` (`plugins/minhaj-core/includes/Modules/Groups/Domain/GroupStatus.php`):
  `draft → forming → scheduled → active → (completed | suspended → active | cancelled)`. الحالات النهائيّة: `completed`، `cancelled`.
- **قواعد المجال** (`GroupRules`): `assert_seat_available`، `assert_ready_to_schedule`، `assert_capacity_matches_type` (ترمي `RuleViolationException`).
- **`GroupService` — واجهة الأعمال**: `create`، `add_member`، `remove_member`، `transfer_member`، `assign_teacher`، `transition`، `update`، `available_seats`، `can_accept`. كلّ كتابة تجري داخل معاملة (`begin_transaction`/`commit`/`rollback`) عبر `GroupRepository`.
- **واجهة الإدارة** (`Admin\AdminController`) — قائمة مجموعات مع فلترة (الحالة، الدفعة، اللغة، المعلّم) وبحث بالرمز وعمود إجراءات؛ صفحة مجموعة مع أعضاء، معلّم، سجلّ تدقيق بجُمَل مترجَمة (`AuditFormatter`)، وإجراءات إضافة/إزالة/نقل عضو وإسناد معلّم وانتقال حالة.
- **بحث AJAX** (`Admin\AjaxSearchController`) — نقطتان تحت `admin-ajax.php`: `minhaj_groups_search_users` (يفلتر بدور الطالب/المعلّم عبر `WP_User_Query`) و`minhaj_groups_search_groups` (بحث بالرمز). كلاهما محميّ بالقدرة + nonce `minhaj_groups_search`.

### 3.2 الجداول (بعد التشغيل)

يتولّى `Migrator` (`plugins/minhaj-core/includes/Migrations/Migrator.php`) التشغيل والترقية، ويستعمل جدولاً واحداً لتتبّع نسخة المخطّط:

| الجدول | المصدر | الغرض |
|---|---|---|
| `{$wpdb->prefix}minhaj_schema_versions` | `Migrator::VERSIONS_TABLE` | يخزّن أرقام الـmigrations المُطبَّقة |
| `{$wpdb->prefix}minhaj_groups` | `CreateGroupsTables::GROUPS_TABLE` | كيان المجموعة — مفتاح `code` فريد |
| `{$wpdb->prefix}minhaj_group_members` | `CreateGroupsTables::MEMBERS_TABLE` | العضويّات — مع عمودَين مولَّدَين `active_seat_index`/`active_student_id` وقيدَين فريدَين `uq_active_seat` و`uq_active_student` يفرضان تفرّد المقعد ومنع تكرار الطالب داخل المجموعة نفسها |
| `{$wpdb->prefix}minhaj_group_audit` | `CreateGroupsTables::AUDIT_TABLE` | صفّ لكلّ عمليّة كتابة — يُكتَب داخل المعاملة نفسها |

### 3.3 الأدوار والقدرات

- `minhaj_student` — يُنشَأ من `Modules\Groups\Roles::install()` بقدرة `read` فقط، بلا صلاحيّات أخرى.
- `minhaj_teacher` — يُنشَأ بالطريقة نفسها.
- كلا الاسمين قابلا التخصيص عبر الفلترَين `minhaj_groups_student_role` و`minhaj_groups_teacher_role`.
- القدرة `minhaj_manage_groups` — تُمنَح لدور `administrator` من `Activator::grant_admin_capabilities()`. تحرس كلّ قراءة وكتابة في `AdminController` وكلّ نقطة AJAX في `AjaxSearchController`.
- **ملاحظة**: `Activator::activate()` يُنشئ الأدوار عند التفعيل ولا يحذفها عند إلغاء التفعيل (لا يوجد `register_deactivation_hook`) — حماية للمستخدمين الحاليّين.

### 3.4 الأحداث والفلاتر المُطلَقة

الأحداث (`Modules\Groups\Events`)، تُطلَق بـ`do_action` من `GroupService` عند نجاح كل عمليّة:
`minhaj_group_scheduled`, `minhaj_group_activated`, `minhaj_group_suspended`, `minhaj_group_resumed`, `minhaj_group_completed`, `minhaj_group_cancelled`, `minhaj_group_member_added`, `minhaj_group_member_removed`, `minhaj_group_member_transferred`, `minhaj_group_teacher_assigned`, `minhaj_group_teacher_changed`.

الفلاتر المكشوفة:
- `minhaj_core_register_migrations` — تجمع الـmigrations من الوحدات.
- `minhaj_group_can_accept_student` — نقطة اعتراض قبل قبول طالب في مجموعة.
- `minhaj_group_code_format` — تسمح بإعادة تشكيل الرمز عند الإنشاء.
- `minhaj_group_default_capacity` — تسمح بتعديل السعة الافتراضيّة حسب النوع.
- `minhaj_groups_student_role` / `minhaj_groups_teacher_role` — تبدّل اسم الدور المستعمَل في البحث.

### 3.5 نظام Migrations

- `Minhaj\Migrations\Migration` (base abstract): كلّ ترحيل يعرّف `version(): int` و`name(): string` و`up(): void`.
- `Minhaj\Migrations\Migrator` singleton: يجمع الـmigrations عبر الفلتر `minhaj_core_register_migrations`، ثم يُطبّق ما لم يظهر في `minhaj_schema_versions`، ولا يُعيد تطبيق ترحيل سبق تطبيقه. يُشغَّل من `Activator::activate()` (مسار التفعيل) ومن `Plugin::boot()` عبر `maybe_upgrade()`.

---

## 4 · حالة المواصفات في `docs/specs/`

### `spec-groups-v1.md`

| القسم | الحالة | الشاهد في الشيفرة |
|---|---|---|
| §3 — نموذج البيانات | **مطبَّق** | `Modules/Groups/Migrations/CreateGroupsTables.php` (الجداول الثلاثة + قيود التفرّد) |
| §4 — دورة الحياة | **مطبَّق** | `Domain/GroupStatus::TRANSITIONS` + `GroupService::transition()` |
| §5 — القواعد الثابتة | **مطبَّق** | `Domain/GroupRules` + اختبارات وحدة في `tests/Unit/Modules/Groups/Domain/GroupRulesTest.php` |
| §6 — الواجهة العامّة | **مطبَّق** | `GroupService` (9 دوالّ عامّة: `create`، `transition`، `add_member`، `remove_member`، `transfer_member`، `assign_teacher`، `update`، `can_accept`، `available_seats`) + `Events.php` + `Repository/GroupRepository` |
| §7 — الصلاحيّات وتقليل البيانات | **جزئيّ** | القدرة `minhaj_manage_groups` قائمة للإدارة؛ الأدوار `minhaj_student` و`minhaj_teacher` منشأة بلا قدرات؛ **لا** توجد بعد أيّ عرض موجَّه للمعلّم أو لوليّ الأمر (لا `minhaj_view_group` ولا `minhaj_view_own_child_group`) |
| §8 — واجهة الإدارة الدنيا | **مطبَّق** | `Modules/Groups/Admin/` كاملة — قائمة، إنشاء، شاشة مجموعة، أعضاء، سجلّ تدقيق مترجَم، بحث AJAX عن الطلاب/المعلّمين/المجموعات |
| §9 — معايير القبول | **مطبَّق (ما يقبل الاختبار الآليّ)** | `tests/Unit/Modules/Groups/GroupServiceTest.php` + `tests/Integration/groups-admin-ui.sh` + `tests/Integration/groups-concurrency.sh` |
| §10 — الأسئلة المفتوحة | لم يُبَتّ فيها في الشيفرة | (يبقى قرار حجم المجموعة النهائي، قائمة الانتظار، الاستمرار للمستوى التالي، رؤية وليّ الأمر) |

### `spec-growth-instrumentation-v1.md`

مواصفة فقط. لا شيفرة تنفَّذ منها. لا يوجد `Modules/Growth/` في الشجرة.

---

## 5 · تشغيل البيئة محلياً

### 5.1 المتطلّبات
- Docker + Node (لأجل `@wordpress/env`).
- `composer` مثبَّت محلياً.

### 5.2 wp-env

الإعداد في `.wp-env.json`:
- WordPress **7.1** مثبَّت (`WordPress/WordPress#7.1`).
- PHP **8.2**.
- تحميل تلقائيّ للإضافة `./plugins/minhaj-core`.
- `WP_DEBUG`، `WP_DEBUG_LOG`، `SCRIPT_DEBUG` مفعَّلة.
- بيانات مسؤول wp-env الافتراضيّة: المستخدم `admin` كلمة السرّ `password`.

الأوامر:
```bash
wp-env start                          # يشغّل البيئة
wp-env run cli wp plugin list         # يؤكّد أنّ minhaj-core نشط
wp-env stop                           # يوقف البيئة
```

الموقع يعمل على `http://localhost:8888` بعد التشغيل.

### 5.3 التحقّقات

```bash
composer install                      # مرّة واحدة
composer phpcs                        # WordPress Coding Standards — يجب أن يعود صفر أخطاء وصفر تحذيرات
vendor/bin/phpunit                    # اختبارات الوحدة (Brain\Monkey)
```

بيئة الاختبار:
- `phpunit.xml.dist` يحمّل `tests/bootstrap.php` الذي يُحمّل composer autoload وشيماً لـ`WP_Error` قبل تشغيل الاختبارات.
- الاختبارات لا تصل قاعدة بيانات، تعتمد على `brain/monkey` لِخداع دوالّ ووردبريس.

### 5.4 الاختبارات التكامليّة اليدويّة

سكربتات Bash تُنفَّذ داخل wp-env بعد تشغيله:

```bash
tests/Integration/groups-admin-ui.sh       # يُنشئ مجموعة، يضيف 5 طلاب، يحاول السادس، يتأكّد من رسالة "The group is full — no free seats."
tests/Integration/groups-concurrency.sh    # يختبر انسداد المقعد الأخير تحت طلبَين متزامنَين
```

كلاهما يعتمد `admin/password` ويُعيد تعيين جداول المجموعات قبل التشغيل.

---

## 6 · ما لم يُبنَ بعد

بترتيب ما يمنع ماذا:

1. **رؤية المعلّم لمجموعاته**: لا كود بعد. المواصفة تطلب `minhaj_view_group` لكنّه غير معرَّف في `Roles.php`. يمنع: صفحة معلّم للحضور والدرجات.
2. **رؤية وليّ الأمر لمجموعة ابنه**: لا كود بعد. مطلوب `minhaj_view_own_child_group` مع فحص ملكيّة الجلسة (§7 من المواصفة).
3. **وحدة Timetable**: لا `Modules/Timetable/` في الشجرة. لا مواصفة في `docs/specs/`. تمنع: Sessions، Attendance، Reports، تكامل Zoom.
4. **طبقة القياس (Growth)**: مواصفة موجودة، لا شيفرة. تمنع: تسجيل عام مفتوح للسوق (المواصفة تقول «يجب أن تدخل قبل أي واجهة تسجيل أو شراء»).
5. **مسار حذف بيانات GDPR**: لا `Personal_Data_Exporter` ولا `Personal_Data_Eraser` مسجَّلَين. الجداول تحوي بيانات قاصرين — هذا مطلَب قانونيّ لا وظيفيّ.

## 7 · الخطوة التالية المرشَّحة

بناءً على ما هو موجود اليوم:
- **إن كان الهدف إنزال الوحدة الحاليّة إلى الإنتاج**: بناء رؤية المعلّم (§7) لأنّها تكمل قصّة المسؤوليّة على المجموعة.
- **إن كان الهدف الفتح للتسجيل**: كتابة شيفرة `spec-growth-instrumentation-v1` قبل أيّ صفحة تسجيل عامّة (المواصفة تصفها بـ«تُلتقط الآن أو لا تُلتقط أبداً»).
- **إن كان الهدف الجدولة**: كتابة مواصفة `spec-timetable-v1` أوّلاً (قاعدة SDD في CLAUDE.md: «لا كود قبل هذه المواصفة»).
