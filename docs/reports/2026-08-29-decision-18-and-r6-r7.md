# تقرير · قرار 18 (فصل هويّة الطالب) + حارسا R-6 و R-7

> **التاريخ:** 29 أغسطس 2026
> **النطاق:** فصل هويّة الطالب عن مصادقة ووردبريس · فحصان جديدان في `TimetableRules`.
> **يعدّل:** `spec-people-v1` (§2 · §2.2 · S-11..S-13).

---

## 1 · ما نُفِّذ

### قرار 18 · الطفل ليس مستخدم ووردبريس

- **جدول جديد `minhaj_students`** بمفتاح `id BIGINT AI` وعمود `user_id BIGINT NULL`. الافتراض: `user_id = NULL` — الطفل لا يملك حساب دخول. عمود مولَّد `STORED` (`active_user_link`) بعليه فهرس فريد يمنع ربط مستخدم ووردبريس واحد بأكثر من صفّ طالب (`uq_active_user_link`)، بقاعدة يفرضها المحرّك لا PHP.
- **`minhaj_student_profiles` يُحذَف** ضمن الترحيل — الجدول كان فارغاً في كل بيئة (تُحقَّق قبل الحذف بحارس `COUNT(*) > 0` يرمي).
- **الأعمدة العرَضيّة** (`origin_org_id`، `registration_link_id` من `AddOrgDimension`) تُنقَل إلى الجدول الجديد بالاسم نفسه — الاتّجاه في `AccessRepository` و`OrgRepository` يتغيّر إلى `minhaj_students` دون أن تنتشر معرفة القرار.
- **`PeopleService::create_student` لا يستدعي `wp_insert_user`** بعد الآن. الشيفرة تُدرج مباشرةً في `minhaj_students` بـ`user_id = NULL` وترجع `id` الجديد. الوصاية تُدرج بالمعرِّف الجديد.
- **`AjaxSearchController` (الإدارة)** يقرأ الطلاب من `minhaj_students` (اسم أوّل + حرف عائلة)، والمعلّمين من `wp_users` كما كانا — المعلّم مستخدم ووردبريس، الطالب لا.
- **`AdminController` (شاشة الأعضاء)** لا يستدعي `get_user_by('id', $student_id)` بعد الآن — الاستعلام على `PeopleRepository::find_student`.
- **`AccessPolicy::can_view_student`** كان يقارن `$user_id === $student_id` — سيّئة معنى بعد القرار (المعرِّفات في فضاءين مختلفَين). أُصلحت لتبحث عن `student.user_id` وتقارن به لا بـ`id`.

### spec-people-v1 · بنود S-11 إلى S-13

- **S-11 · لا `wp_users` لطفل**. حالة طبيعيّة لا استثناء. مبرهنة باختبار وحدة صريح + قسم `S-11` من اختبار التكامل.
- **S-12 · تداخل الجلسات على مستوى الطالب** (R-6). حارس جديد في `TimetableRules::assert_no_student_double_book`، يُستدعى داخل معاملة `generate_for_group` بعد `SELECT ... FOR UPDATE` على جلسات الطالب الحاليّة في كل مجموعاته (`TimetableRepository::lock_student_sessions_between`). المقارنة على `scheduled_start_utc` / `scheduled_end_utc` — لحظات UTC. **`local_start_wall` لسؤال آخر** (§3.1 من التقويم: أيّ يوم محلّيّ هذا؟) فلا يُخلَط. تعليق صريح في الشيفرة يُبيّن المقصد، واختبار وحدة يُبرهن الغلط عند الخلط.
- **S-13 · تداخل الأسرة** (R-7). أبناء وليّ أمر واحد في جلستين متداخلتين لا يُمنَع — قد تكون أسرة بشاشتَين وأبوَين — بل يُطلَق `do_action('minhaj_family_overlap_warning', $guardian_id, $group_id, $start_utc, $end_utc, $overlaps)` كي يرى الإدارة الاصطدام قبل أن يشتكي منه وليّ الأمر.

---

## 2 · الملفّات المتغيّرة

**جديد:**
- `plugins/minhaj-core/includes/Modules/People/Migrations/RestructureStudentsForNonWpIdentity.php`
- `tests/Integration/students-double-book.sh`

**معدَّل — قرار 18:**
- `plugins/minhaj-core/includes/Modules/People/PeopleService.php` — لا `wp_insert_user` في `create_student`؛ `find_student` بدل `find_student_profile` في `anonymize_student`.
- `plugins/minhaj-core/includes/Modules/People/Repository/PeopleRepository.php` — `insert_student` / `find_student` / `update_student` / `search_students_by_first_name`.
- `plugins/minhaj-core/includes/Modules/People/Module.php` — تسجيل الترحيل.
- `plugins/minhaj-core/includes/Access/AccessRepository.php` — `find_student_profile` يقرأ من `minhaj_students`، بـ`id` مفتاح.
- `plugins/minhaj-core/includes/Access/AccessPolicy.php` — الفرع «الذاتيّ» يقارن `student.user_id` لا `student.id`، و`compute_visible_student_ids` يقرأ `id` من `minhaj_students`.
- `plugins/minhaj-core/includes/Modules/Orgs/Repository/OrgRepository.php` — `attribution_rows` يقرأ من `minhaj_students`.
- `plugins/minhaj-core/includes/Modules/Groups/Admin/AjaxSearchController.php` — بحث الطلاب على `minhaj_students`.
- `plugins/minhaj-core/includes/Modules/Groups/Admin/AdminController.php` — عرض الطالب من `PeopleRepository::find_student`.
- `tests/Unit/Modules/People/PeopleServiceTest.php` — تحديث الأسماء وإدخال تأكيد أنّ `wp_insert_user` لم يُستدعَ.
- `docs/specs/spec-people-v1.md` — §2 و§2.2 وبنود S-11..S-13.

**معدَّل — R-6 و R-7:**
- `plugins/minhaj-core/includes/Modules/Timetable/Domain/TimetableRules.php` — `assert_no_student_double_book` و`detect_family_overlaps`.
- `plugins/minhaj-core/includes/Modules/Timetable/Repository/TimetableRepository.php` — `lock_student_sessions_between`، `list_family_sessions_between`، `list_active_roster_with_primary_guardian`.
- `plugins/minhaj-core/includes/Modules/Timetable/TimetableService.php` — الفحصان داخل حلقة `foreach ( $sessions as $s )`، مع تعليق يوضّح **صرامة UTC**.
- `tests/Unit/Modules/Timetable/Domain/TimetableRulesTest.php` — ثلاثة اختبارات جديدة، أهمّها `test_student_double_book_is_utc_not_local`.

---

## 3 · معايير القبول والنتائج

### 3.1 اختبارات الوحدة

```
composer test:82
```

الناتج:
```
OK (166 tests, 577 assertions)
```

### 3.2 اختبار التكامل — S-11 و S-12 و S-13 على قاعدة حيّة

```
bash tests/Integration/students-double-book.sh
```

الناتج الحرفيّ (بعد إزالة ألوان ANSI):
```
== Reset relevant tables ==

== S-11 · create_student does NOT create a wp_users row for the child ==
  WP_USERS_BEFORE=184 WP_USERS_AFTER_GUARDIAN=185 WP_USERS_AFTER_STUDENT=185 STUDENT_ID=19 STUDENT_USER_ID=null STUDENT_NAME=Sara GUARDIAN_ID=380
  ✓ guardian inserted +1 in wp_users
  ✓ create_student did NOT create a wp_users row (delta=0) — decision 18
  ✓ students.user_id IS NULL for the new child

== Seed a second student (Bilal), a teacher, two groups, calendars ==
  TEACHER_A=381 TEACHER_B=382 GROUP_A=72 GROUP_B=73 STUDENT_B=20

== Generate group A on Mondays 10:00 UTC ==
  GEN_A=ok count=3
  ✓ group A generated 3 sessions on Mondays

== S-12 · move student A into group B and try to generate overlapping sessions ==
  DBOOK=err:student_double_book
  ✓ generation refused with err:student_double_book — R-6 blocked the overlap

== S-13 · remove student A from group B, keep student B (family case), overlap Tuesday ==
  FAMILY_GEN=ok count=3 WARNINGS=3
  ✓ family case does NOT block — group B still generated 3 sessions
  ✓ minhaj_family_overlap_warning fired 3 time(s) — admin sees the collision

STUDENTS DOUBLE-BOOK PROOF PASSED
```

قراءة الأسطر الحاسمة:

- **S-11**: `WP_USERS_BEFORE=184 → WP_USERS_AFTER_GUARDIAN=185 → WP_USERS_AFTER_STUDENT=185`. وليّ الأمر +1، الطالب صفر. `STUDENT_USER_ID=null` — الطفل بلا حساب.
- **S-12**: `DBOOK=err:student_double_book`. R-6 قطع التوليد بدلاً من R-5 (الذي كان سيقطعه لو كان المعلّمان واحداً — هنا معلّمان مختلفان، فالفرق ينكشف).
- **S-13**: `FAMILY_GEN=ok count=3 WARNINGS=3`. لا منع، بل ثلاثة تحذيرات (جلسة واحدة لكل من ثلاث جلسات في الأسبوع تصطدم مع شقيقة).

### 3.3 اختبار الكسر والاستعادة لكلّ حارس

| # | الحارس | كيف كُسر | الناتج الحرفيّ | الاستعادة |
|---|---|---|---|---|
| 10 | **S-11** · لا `wp_users` لطفل | أُدرج `wp_insert_user()` صريح وأُلحق مُعرِّف المستخدم | `WP_USERS_AFTER_STUDENT=173` (+1)، `STUDENT_USER_ID=368` — الطفل صار حساب دخول | ✅ |
| 11 | **S-12 · R-6** · حصار التداخل | `foreach ( $roster_student_ids …)` تحوّل إلى `foreach ( array() …)` | `DBOOK=ok` — التوليد نجح رغم التداخل | ✅ |
| 12 | **S-13 · R-7** · تحذير الأسرة | `foreach ( $family_sessions_by_guardian …)` تحوّل إلى `foreach ( array() …)` | `FAMILY_GEN=ok count=3 WARNINGS=0` — لا تحذير | ✅ |
| 13 | **صرامة UTC في R-6** · قراءة `local_start_wall` بدل UTC | `$existing['local_start_wall'] ?? …` بدل `$existing['scheduled_start_utc']` في `assert_no_student_double_book` | استثناء `RuleViolationException: student 42 already booked 2027-01-04 07:00:00..2027-01-04 08:00:00 …` — إيجاب زائف على نوافذ لا تتداخل في UTC | ✅ |

كلّها احمرّت عند الكسر، اخضرّت عند الإصلاح.

### 3.4 `phpcs`

نظيف — لا أخطاء ولا تحذيرات معلّقة.

---

## 4 · ما لم يُنفَّذ ولماذا

- **ترقية طالب إلى مستخدم دخول عند 16**: التصميم يحمل `user_id NULL` وعمود مولَّد فريد؛ آليّة الترقية نفسها لم تُبنَ. تُبنى مع بوّابة الطالب اليافع في مرحلة قادمة.
- **إعادة توليد بعد التقاويم أو التداخل** (`recalculate_from`): لا تزال محجوبة بـZoom كما في تقرير التقويم.

## 5 · الأسئلة المفتوحة

1. **`AjaxSearchController` كان يشترط ثلاثة أحرف** للبحث؛ الآن `first_name` غالباً قصير (Sara, Ali). خفّضت الحدّ ضمنيّاً إلى حرفين للطلاب — يُراجَع إن ازدحمت البيانات.
2. **العرض في شاشة الأعضاء** يظهر `#id first_name family_initial.` — قصير. حين نبني شاشة تفاصيل الطالب، يصير الرابط منها.
3. **الأسرة بأبوين وشاشتَين**: R-7 يعتمد على `is_primary=1` فقط في اختيار الوصيّ للاختبار. أب ثانوي `can_view=1` قد يُحضر أحد الطفلَين بشاشة ثانية. تعالَج حين نضيف `can_manage` في الاستحقاق.
4. **الأعمدة `active_user_link`**: عمود مولَّد يفرض أنّ المستخدم لا يُربَط بأكثر من طالب. لكن ماذا لو انتقل الطفل إلى مؤسّسة أخرى وأخذنا صفَّه معنا؟ يُبَتّ حين نبني «نقل الطالب».
