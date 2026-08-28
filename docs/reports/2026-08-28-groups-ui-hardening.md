# تقرير · تصلّب واجهة المجموعات بعد أوّل استعمال بشريّ

> **التاريخ:** 28 أغسطس 2026
> **النطاق:** الست ثغرات التي كشفها أوّل استعمال بشريّ لواجهة إنشاء المجموعات + قاعدة عامّة أُضيفت إلى `CLAUDE.md`.
> **يعدّل المواصفة:** لا. يُنفّذ ما كانت المواصفة تفترضه أصلاً (رمز مولَّد، لغة من قائمة، دفعات ككيان).

---

## 1 · ما نُفِّذ

### قاعدة عامّة في `CLAUDE.md`

قسم جديد «واجهات الإدخال — قاعدة عامّة»: أيّ حقل مجاله مغلق يُختار، لا يُكتب. أيّ معرِّف يولّده النظام، لا الإنسان. التجاوز إداريّ صريح مسجَّل بسبب. القاعدة تفسّر لماذا الست ثغرات أدناه ثغرات، لا مرونة.

### G1 · رمز المجموعة يُولَّد آلياً

- كيان **دفعة** (`minhaj_batches`) جديد: `id, code (UNIQUE), org_id, market, starts_on, status, created_at/updated_at`. مصنع الرموز يقرأ منه `market` و`code`.
- `GroupCodeFormatter` مشترك افتراضيّ لـ`minhaj_group_code_format` بالصيغة `{MARKET}-{BATCH_CODE}-{LEVEL}-{SEQ}`؛ SEQ = `1 + الموجود في (batch × level) + attempt`. عدّاد `attempt` هو ما يجعل الإعادة تنجو من التصادم.
- `GroupService::create` صار **حلقة إعادة على `PersistenceException::DUPLICATE_CODE`** بدل `code_exists` قبل الكتابة. القيد الفريد `uq_code` هو مصدر الحقيقة — قراءةٌ ثم كتابة تُنشئ سباق TOCTOU لا تحلّه. الرمز يُجمَّد بعد الإنشاء (لا يُعاد توليده).
- إن مرَّر المستدعِي رمزاً صريحاً وجب معه `code_override_reason`، ويسقط بلا إعادة على أوّل تصادم (`code_taken`).

### G2 · كيان الدفعة

- ترحيل `CreateBatchesTable` (`VERSION=20260830300000`).
- `GroupRepository`: `find_batch`, `list_selectable_batches(int limit)`, `count_groups_in_batch_level(batch_id, level)`.
- `BatchStatus` (planned/open/running/closed) في المجال.

### G3 · لغة التدريس من قائمة لا حقل حرّ

- بوّابة **قبل الحفظ** في `GroupService::create` تسأل الفلتر `minhaj_group_teaching_language_coverage($count, $locale)`. عدد < 1 يُرفض بـ`WP_Error('no_assignable_teacher_for_language')` ما لم يمرَّر `language_coverage_override_reason`.
- **`People\Module` مشترك في الفلتر** عبر `PeopleService::language_coverage($locale)['assignable']` — يستدعي الآن `count_assignable_teachers_for_locale` المُعلَّقة بلا استدعاء منذ `spec-people-v1`. الفلتر يحفظ Groups قابلة للتحميل في اختبارات لا تحمّل People (يعيد `null` = لا مشترك).

### G4 · توحيد نوع عمود اللغة

- ترحيل `UnifyLanguageColumnType`: `TRIM(teaching_language)` أوّلاً لإزالة حشوة المسافات التي يخلّفها `CHAR(5)` على بعض المحرّكات، ثمّ `MODIFY teaching_language VARCHAR(10)`. الآن الأعمدة الثلاثة (`teaching_language`, `ui_locale`, `teacher_languages.locale`) متطابقة.
- `CreateGroupsTables` عُدِّل لتنشئ التركيبات الجديدة على `VARCHAR(10)` مباشرة.

### G5 · بوّابة السعة تسبق الحفظ

- كان تحذير `capacity_max > 5` يظهر بعد `INSERT`. الآن `GroupService::create` يفحص السقف قبل توليد الرمز؛ الرفض `capacity_over_promise` يرتدّ قبل أيّ صفّ في القاعدة. التجاوز الإداريّ عبر `capacity_over_promise_reason`.

### G6 · مصالحة `no_show` ⇄ التعويض

- `TimetableRepository::list_no_show_sessions_without_makeup(int limit)` — استعلام `NOT EXISTS` على `makeup_for_id` يكشف كل جلسة `status='no_show'` ولا صفّ تعويض يشير إليها.
- `wp minhaj timetable unscheduled-makeups` صار يعرض **قائمتَين**: طابور الدين الصريح + جلسات `no_show` بلا تعويض، ويخرج بـ`WP_CLI::halt(1)` عند اكتشاف فجوة ليصلح Cron ينبّه.
- `NoShowMakeupListener` مسجَّل في `Timetable\Module` — يشترك في `minhaj_session_no_show` (بعد COMMIT، R-7 حضور) ويكتب صفّ التعويض غير المجدول. اللقّة المتعمَّدة عند الخطأ صامتة لأنّ CLI هو شبكة الأمان.

---

## 2 · الملفّات المتغيّرة

**جديدة:**
- `plugins/minhaj-core/includes/Modules/Groups/GroupCodeFormatter.php` — مصنع رمز افتراضيّ للفلتر.
- `plugins/minhaj-core/includes/Modules/Groups/Domain/BatchStatus.php`.
- `plugins/minhaj-core/includes/Modules/Groups/Migrations/CreateBatchesTable.php` (VERSION `20260830300000`).
- `plugins/minhaj-core/includes/Modules/Groups/Migrations/UnifyLanguageColumnType.php` (VERSION `20260830300001`).
- `plugins/minhaj-core/includes/Modules/Timetable/NoShowMakeupListener.php`.
- `tests/Integration/groups-ui-fixes.sh` — أنبوب حيّ متكامل لكل حارس.
- `tests/Integration/groups-ui-fixes-break.sh` — برهنة كسر/استعادة لكل حارس.

**معدَّلة:**
- `CLAUDE.md` — قسم «واجهات الإدخال — قاعدة عامّة» جديد.
- `plugins/minhaj-core/includes/Modules/Groups/GroupService.php` — إعادة كتابة `create()` كاملة: بوّابة تغطية اللغة، بوّابة السعة قبل الحفظ، حلقة إعادة على `DUPLICATE_CODE`، فرض `code_override_reason` عند تمرير رمز صريح.
- `plugins/minhaj-core/includes/Modules/Groups/Repository/GroupRepository.php` — `find_batch`, `list_selectable_batches`, `count_groups_in_batch_level`; تصنيف تصادم `uq_code`/`'code'` كـ`DUPLICATE_CODE`.
- `plugins/minhaj-core/includes/Modules/Groups/Repository/PersistenceException.php` — ثابت `DUPLICATE_CODE`.
- `plugins/minhaj-core/includes/Modules/Groups/Migrations/CreateGroupsTables.php` — `teaching_language VARCHAR(10)` للتركيبات الجديدة.
- `plugins/minhaj-core/includes/Modules/Groups/Module.php` — تسجيل `GroupCodeFormatter` غير مشروط بـadmin؛ إدراج المهاجرَين الجديدَين.
- `plugins/minhaj-core/includes/Modules/People/Module.php` — اشتراك في `minhaj_group_teaching_language_coverage`.
- `plugins/minhaj-core/includes/Modules/Timetable/Repository/TimetableRepository.php` — `find_makeup_for`, `list_no_show_sessions_without_makeup`.
- `plugins/minhaj-core/includes/Modules/Timetable/Cli/UnscheduledMakeupsCommand.php` — عرض القائمتَين + `halt(1)` عند الفجوة.
- `plugins/minhaj-core/includes/Modules/Timetable/Module.php` — تسجيل `NoShowMakeupListener`.

---

## 3 · معايير القبول والنتائج

### AC-1 · الرمز يُولَّد آلياً بالتسلسل

```
== AC-1 · create three groups back-to-back and see NL-B2609-A1-{01,02,03} ==
  CREATE_0=NL-B2609-A1-01
CREATE_1=NL-B2609-A1-02
CREATE_2=NL-B2609-A1-03
  ✓ codes generated in sequence: NL-B2609-A1-01, NL-B2609-A1-02, NL-B2609-A1-03
```

### AC-2 · الإعادة على التصادم تتخطّى الخانة المشغولة

الصيغة تُثبَّت لتعيد `NL-B2609-A1-04` على `attempt=0` (تصادم مع صفّ مثبَّت مسبقاً). على `attempt=1` تعود إلى المصنع الافتراضيّ الذي يستعمل `1 + count + attempt = 06`. الرقم **يقفز فوق 05 عمداً** لأنّ ذلك ما يضمن سلامة الإعادة تحت التزامن؛ الكفاءة ليست الهدف، السلامة هي.

```
== AC-2 · concurrent collision retries and lands on next slot ==
  RESULT=NL-B2609-A1-06
  ✓ retry avoided the collision and landed on NL-B2609-A1-06
```

### AC-3 · السعة > السقف مرفوضة قبل الحفظ

```
== AC-3 · capacity_max > 5 refused pre-save without a written reason ==
  WITHOUT=err:capacity_over_promise
WITH=ok
  ✓ capacity>5 without a reason refused with err:capacity_over_promise
  ✓ capacity>5 with a reason accepted
```

### AC-4 · لغة بلا تغطية مرفوضة قبل الحفظ

```
== AC-4 · language with zero coverage refused pre-save ==
  LANG=err:no_assignable_teacher_for_language
OVERRIDE=ok
  ✓ zero-coverage locale refused with err:no_assignable_teacher_for_language
  ✓ zero-coverage locale accepted with an override reason
```

### AC-5 · CLI يكشف جلسة `no_show` بلا تعويض

جلسة تُبذَر مباشرة بحالة `no_show` بدون أن يمرّ الحدث بالمستمع (محاكاة سقوط ما بعد COMMIT). الأمر يرصد الفجوة:

```
== AC-5 · unscheduled-makeups CLI catches a no_show session that has no make-up row ==
  ORPHAN_SESSION=166
  --- CLI output ---
  
  == no_show sessions with NO make-up row (reconciliation gap) ==
  id	group_id	sequence_no	lesson_no	teacher_id	anchor_timezone	scheduled_start_utc
  166	42	1		42	UTC	2027-06-01 09:00:00
  ---
  ✓ CLI reported the orphaned no_show session (166)

GROUPS UI HARDENING PROOF PASSED
```

الأمر يخرج بـ`exit 1` عند اكتشاف الفجوة (`WP_CLI::halt(1)`) ليصلح Cron يستطيع التنبيه.

### وحدات وPHPCS

```
$ composer test:82
OK (182 tests, 602 assertions)

$ composer phpcs
............................................................  60 / 117 (51%)
.........................................................    117 / 117 (100%)
Time: 6.72 secs; Memory: 38MB
```

---

## 4 · سجلّ كسر/استعادة (Break-and-restore)

كل حارس جديد كُسِر عمداً — إمّا بتقليل `max_attempts` إلى 1، أو بتحويل شرط البوّابة إلى `false && …`، أو بتعطيل استعلام الفجوة في CLI — وتُحقِّق أنّ سيناريو القبول انهار (احمرّ)، ثمّ استُعيدت الشيفرة الأصليّة من نسخة احتياطيّة بايت-لِبايت وتُحقِّق أنّه اخضرَّ مرّةً أخرى. الاستعادة تمرّ عبر ملفّ محلّي مؤقّت لا عبر `git checkout` — لأنّ العمل لم يُثبَّت بعد وثقتنا فقط بالنسخة قبل الكسر.

```
== G1 · auto-generated code retry-on-collision ==
  ✓ G1 retry went red after breaking the guard
  ✓ G1 retry restored went green again after restoring the guard

== G2 · capacity_over_promise pre-save gate ==
  ✓ G2 capacity gate went red after breaking the guard
  ✓ G2 capacity gate restored went green again after restoring the guard

== G3 · language coverage pre-save gate ==
  ✓ G3 language gate went red after breaking the guard
  ✓ G3 language gate restored went green again after restoring the guard

== G4 · no_show reconciliation CLI ==
  ✓ G4 no_show reconciliation went red after breaking the guard
  ✓ G4 no_show reconciliation restored went green again after restoring the guard

BREAK-AND-RESTORE PROOF PASSED
```

الكسور اختيرت لتصيب سطر البوّابة نفسه لا سطراً محايداً حوله؛ كسر السطر المحايد سيمرّ حتى مع بوّابة معطَّلة ولن يبرهن شيئاً.

---

## 5 · ما أُجِّل

- **واجهة الإدارة** لم تُعدَّل في هذا التقرير. الإصلاحات تعيش في `GroupService` (الطبقة الوحيدة التي لا يمكن الالتفاف عليها من admin/CLI/REST). تحديث `AdminController` ليعرض قائمة الدفعات وقائمة اللغات مع تحذير التغطية بدل حقل حرّ هو خطوة ثانية بعد قبول هذا التصلّب.
- **تنبيه Cron** على مخرج `wp minhaj timetable unscheduled-makeups`. المسألة الآن: `exit 1` عند الفجوة، والنقلة إلى إعلام (Slack/بريد) للحضور الأولّي مؤجَّلة لسبيك عمليّات لاحق.
- **حدّ إعادة أذكى**: الحالي `1 + count + attempt` قد يترك خانات فارغة عند التصادم. الأمر مقبول (السلامة > الكفاءة) لكن يمكن لاحقاً `SELECT MAX(seq)` داخل معاملة لملء الثقوب. اختياريّ.
- **`tests/Integration/orgs-cross-scope.sh`**: عند التشغيل يفشل عند `DELETE FROM wp_minhaj_student_profiles` — الجدول أُعيدت تسميته إلى `wp_minhaj_students` في مهجرة `RestructureStudentsForNonWpIdentity`. لم أعدّله لأنّ هذا التغيير لا يمسّ `Modules\Orgs` ولا `Access` (الشرط الوحيد الذي يفرض تشغيله)، لكنّه دَين تقنيّ عالٍ يستحقّ إصلاحاً منفصلاً.

---

## 6 · أسئلة مفتوحة

- **من يقرّر لغات الإطلاق؟** حالياً لا مصدر رسميّ. `PeopleService::language_coverage` يعمل على أيّ سلسلة locale؛ الواجهة الإداريّة (قيد الإنجاز) ستحتاج قائمة ثابتة. أقترح ثابت `Modules\Groups\Domain\LaunchLanguages` مبدئيّاً + فلتر للتوسّع.
- **حدّ إعادة موحَّد؟** الآن 5 محاولات ثابت في `GroupService`. عند التعمير (10k+ مجموعة) قد يصير عنق زجاجة. مفتاح للنقاش: `apply_filters('minhaj_group_code_max_attempts', 5, $args)` مقابل ثابت.
