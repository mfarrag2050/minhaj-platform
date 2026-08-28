# تقرير · تنفيذ `spec-access-v1` و`spec-organizations-v1`

> **التاريخ:** 28 أغسطس 2026
> **الالتزام:** `df5d83c` — `feat(access,orgs): implement spec-access-v1 + spec-organizations-v1`
> **النطاق:** المرحلة 2 · الوحدتان الأوليان + الديون الثلاثة الصغيرة.

---

## 1 · ما نُفِّذ

### وحدة `Minhaj\Access\` — spec-access-v1

- **`Capabilities`** — القدرات الثمانية في §3 مضافةً إلى الأدوار: `MANAGE_GROUPS`، `MANAGE_SESSIONS`، `VIEW_GROUP`، `VIEW_OWN_CHILD_GROUP`، `RECORD_ATTENDANCE`، `VIEW_RECORDING`، `JOIN_SESSION`، `MANAGE_ORG`. اسم `REVIEW_QUALITY` محجوز (§9 Q3) دون منح.
- **`AccessRepository`** — طبقة قراءة فقط، وواحد فقط للكتابة: `record_denial()` يوجّه صفّ `access_denied` إلى جدول تدقيق الوحدة المالكة للموضوع (§5 A-6).
- **`AccessPolicy`** — المحلِّل بحسب §6: `can_view_*`، `join_role`، `visible_*_for`، `org_ids_for`، `is_org_scoped`، `is_active_guardian_of`، `assert()`. تخزين مؤقّت داخل الطلب فقط — لا `transient`، لا `option` (§7 · A-7).
- فلتر `minhaj_access_decision` **يشدّد ولا يخفّف** (§6). محاولة التخفيف تُتجاهَل ويُطلَق `do_action('minhaj_access_decision_loosen_ignored')` كي يُلاحَظ الحدث دون الاصطدام بـ`failOnWarning` في PHPUnit.
- تبعاً لتوجيه المراجعة: **`org_ids_for()` صار `?array`** — `null` نطاق مفتوح (طاقمنا، وليّ الأمر، معلّم مستقل)، وقائمة (حتى فارغة) نطاق صريح (`MANAGE_ORG`). `is_org_scoped()` هي البوّابة الصريحة للقارئ. لا معنى مزدوج لقيمة واحدة.

### وحدة `Minhaj\Modules\Orgs\` — spec-organizations-v1

- **البنية** (§3): `minhaj_orgs`، `minhaj_org_registration_links`، `minhaj_org_members` (مع `active_user_id` كعمود مولَّد `STORED` يفرض `uq_active_member` في القاعدة)، `minhaj_curricula` مبذورة بـ`manhaj-v1`.
- **`AddOrgDimension`** (§3.5): يضيف `org_id` إلى `teacher_profiles/groups/sessions`، و`origin_org_id` + `registration_link_id` إلى `student_profiles`، و`curriculum_id` إلى `groups`.
- **`OrgService`** يطبّق §6 كاملاً. **قفل O-11** حرفيّاً: `type=licensee` أو `data_controller=org` يُرفَض بـ`org_type_unsupported`. **بوّابة DPA** (§5 · O-8): `set_status(active)` و`issue_registration_link` يفشلان دون `dpa_signed_at`. **الرمز الواحد**: `increment_uses_if_available` هو `UPDATE ... WHERE (max_uses IS NULL OR uses_count < max_uses)` — عمليّة ذرّية تفرض §8-3 على مستوى المحرّك.
- **دور `minhaj_org_admin`**، والـ`Activator` يمنحه `MANAGE_ORG` + `VIEW_GROUP` بعد تثبيته.
- **أعمدة `spec-compensation-v1 §2`** أُلحقت مباشرةً بـ`CreateOrgsTables` لأنّ الترحيل لم يُطبَّق على أيّ قاعدة إنتاج بعد — لا ترحيل ثانٍ لجدول وُلد اليوم.

### الديون الثلاثة الصغيرة

1. **`session_duration_minutes` = 0 → 60** بترحيل `DefaultSessionDurationTo60`؛ يعدّل الافتراض في القاعدة ويعبّئ الصفوف الصفريّة، والحارس داخل `TimetableService::generate_for_group` يرفض بـ`invalid_group_duration` إن بقي الحقل صفراً. `CreateGroupsTables` عدِّل ليطابق التركيبات الجديدة.
2. **`minhaj_groups.timezone` احتُفظ به لا حُذف**. أُضيف تعليق SQL: «منطقة عرض المجموعة لوليّ الأمر». السبب: `GroupService::create/update` يقبلانه فعلاً، وبوّابة وليّ الأمر تحتاجه للعرض المحليّ في المرحلة القادمة. المرساة الزمنيّة الحقيقيّة تبقى `minhaj_sessions.anchor_timezone` مجمَّدةً لحظة التوليد.
3. **`SessionStatus::TRANSITIONS`**: تبيَّن أنّها بالفعل تسمح بـ`scheduled → live → completed` و`scheduled → no_show`. لا تعديل.

---

## 2 · الملفّات المتغيّرة

**جديدة — Access:**
- `plugins/minhaj-core/includes/Access/Capabilities.php`
- `plugins/minhaj-core/includes/Access/AccessRepository.php`
- `plugins/minhaj-core/includes/Access/AccessPolicy.php`
- `plugins/minhaj-core/includes/Access/AccessDeniedException.php`

**جديدة — Orgs:**
- `plugins/minhaj-core/includes/Modules/Orgs/Module.php`
- `plugins/minhaj-core/includes/Modules/Orgs/OrgService.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Roles.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Events.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Domain/OrgType.php` (يحوي قفل O-11)
- `plugins/minhaj-core/includes/Modules/Orgs/Domain/OrgStatus.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Domain/MembershipRole.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Repository/OrgRepository.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Repository/PersistenceException.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Migrations/CreateOrgsTables.php`
- `plugins/minhaj-core/includes/Modules/Orgs/Migrations/AddOrgDimension.php`

**جديدة — الديون:**
- `plugins/minhaj-core/includes/Modules/Groups/Migrations/DefaultSessionDurationTo60.php`

**معدَّلة — نواة الإضافة:**
- `plugins/minhaj-core/includes/Plugin.php` — تسجيل Orgs.
- `plugins/minhaj-core/includes/Activator.php` — تثبيت `AccessCapabilities` و`OrgsRoles` ومنح قدرات مسؤول الجهة.
- `plugins/minhaj-core/includes/Modules/Groups/Module.php` — إدراج ترحيل `DefaultSessionDurationTo60`.
- `plugins/minhaj-core/includes/Modules/Groups/Migrations/CreateGroupsTables.php` — الافتراض الجديد + توثيق `timezone`.
- `plugins/minhaj-core/includes/Modules/Timetable/TimetableService.php` — الحارس الجديد.

**جديدة — اختبارات:**
- `tests/Unit/Access/AccessPolicyTest.php`
- `tests/Unit/Access/NoImplicitActorGrepTest.php`
- `tests/Unit/Modules/Orgs/OrgServiceTest.php`
- `tests/Integration/orgs-cross-scope.sh`

**معدَّلة — اختبارات:**
- `tests/Unit/Modules/Timetable/TimetableServiceTest.php` — إضافة `session_duration_minutes=60` إلى تركيبات المجموعة.

---

## 3 · معايير القبول والنتائج

### 3.1 spec-access-v1 §8 — اختبارات وحدة

الأمر:

```
vendor/bin/phpunit --testdox tests/Unit/Access
```

الناتج الحرفيّ:

```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.9
Configuration: /Users/mdervis/Minhaj/minhaj-platform/phpunit.xml.dist

............                                                      12 / 12 (100%)

Time: 00:00.054, Memory: 14.00 MB

Access Policy (Minhaj\Tests\Unit\Access\AccessPolicy)
 ✔ §8-1: teacher sees only groups they are assigned to; a third group yields false + access_denied audit
 ✔ §8-2: parent of two children in two groups sees exactly those two; no visibility for a peer parent
 ✔ §8-3 (A-2 mirror): every join_role the student passes, the guardian passes too
 ✔ §8-4: ending the guardianship (ended_at set) drops visibility on the very next call — no cache
 ✔ §8-5: anonymized_at blocks every non-admin decision and blocks participant join
 ✔ org_ids_for returns null for unbounded users (platform admin) — distinct from empty array
 ✔ org_ids_for returns null for parents/teachers who hold no MANAGE_ORG cap
 ✔ org_ids_for returns an array for MANAGE_ORG holders — empty array is a real scope, not "unbounded"
 ✔ §8-6: minhaj_access_decision returning true on a false decision stays false + minhaj_access_decision_loosen_ignored fires
 ✔ §8-7: missing/deleted user id returns false without throwing
 ✔ assert() throws AccessDeniedException on false decision and records a denial

No Implicit Actor Grep (Minhaj\Tests\Unit\Access\NoImplicitActorGrep)
 ✔ spec-access-v1 §8-8: no file in plugins/minhaj-core/includes/Access calls get_current_user_id()

OK (12 tests, 53 assertions)
```

### 3.2 spec-organizations-v1 §8 — اختبارات وحدة

الأمر:

```
vendor/bin/phpunit --testdox tests/Unit/Modules/Orgs
```

الناتج الحرفيّ:

```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.9
Configuration: /Users/mdervis/Minhaj/minhaj-platform/phpunit.xml.dist

............                                                      12 / 12 (100%)

Time: 00:00.031, Memory: 14.00 MB

Org Service (Minhaj\Tests\Unit\Modules\Orgs\OrgService)
 ✔ O-11: creating an org with type=licensee is rejected with an explicit "unsupported" error
 ✔ O-11: data_controller=org override is rejected on the same lock as licensee
 ✔ §8-2: revoked / expired / exhausted / suspended-org tokens all return null (no leak)
 ✔ §8-2: a wrong-length token is rejected before ever reaching the DB
 ✔ §8-3: consume_registration_token relies on an atomic UPDATE — a race that loses returns link_exhausted
 ✔ §8-3: consume_registration_token returns true when the atomic UPDATE affects one row
 ✔ §8-4: set_status(active) fails when the org has no dpa_signed_at
 ✔ §8-4: issue_registration_link fails when the org has no dpa_signed_at
 ✔ §8-11: duplicate active membership surfaces as a DB error (uq_active_member), not a silent success
 ✔ §8-1: issue_registration_link writes the row and returns url + token in one transaction
 ✔ §8-8: nothing in the service prevents cross-org teaching (student org != teacher org)
 ✔ org_ids_for_user returns the repository list unchanged

OK (12 tests, 51 assertions)
```

### 3.3 §8-5 (عزل الجهة إلى الجهة) و§8-11 (رفض القاعدة للعضويّة المكرَّرة) — اختبار تكامل حيّ

هذان أخطر معيارين وهما **مبرهنان على MariaDB حيّة** بلا محاكاة، على نمط `tests/Integration/groups-concurrency.sh`.

**اختبار العزل ثنائيّ الاتجاه**: يبذر مسؤولين — `ADMIN_A` للجهة أ و`ADMIN_B` للجهة ب — ثم يستدعي كامل مجموعة القرارات (`visible_group_ids_for`، `visible_student_ids_for`، `org_ids_for`، `is_org_scoped`، `can_view_group`، `can_view_student`) من **كليهما** ضدّ صفوف الجهة الأخرى. الاختبار باتّجاه واحد لا يثبت العزل — التسريب غير المتناظر (استعلام يرشّح جهة الطالب دون جهة المعلّم مثلاً) لا يُكشَف إلا بمرآة.

الأمر:

```
bash tests/Integration/orgs-cross-scope.sh
```

الناتج الحرفيّ (بعد إزالة ألوان ANSI):

```
== Reset test tables ==
== Seed: two orgs + one group + one student per org ==
  ORG_A=7 ORG_B=8 GROUP_A=13 GROUP_B=14 STUDENT_A=233 STUDENT_B=234 TEACHER_A=231 TEACHER_B=232 ADMIN_A=235 ADMIN_B=236

== §8-5 · direction A→B: org-A admin (user=235) queries the AccessPolicy ==
  GROUPS=13 STUDENTS=233 SCOPE=[7] IS_SCOPED=true CAN_VIEW_OTHER_GROUP=no CAN_VIEW_OWN_GROUP=YES CAN_VIEW_OTHER_STUDENT=no CAN_VIEW_OWN_STUDENT=YES

== §8-5 · direction B→A: org-B admin (user=236) queries the AccessPolicy ==
  GROUPS=14 STUDENTS=234 SCOPE=[8] IS_SCOPED=true CAN_VIEW_OTHER_GROUP=no CAN_VIEW_OWN_GROUP=YES CAN_VIEW_OTHER_STUDENT=no CAN_VIEW_OWN_STUDENT=YES

== Assertions ==
  ✓ [A→B] visible_group_ids_for = [13] — other org not present
  ✓ [A→B] visible_student_ids_for = [233] — other org student not leaked
  ✓ [A→B] org_ids_for = [7], is_org_scoped = true
  ✓ [A→B] can_view_group(other) = false
  ✓ [A→B] can_view_group(own) = true
  ✓ [A→B] can_view_student(other) = false
  ✓ [A→B] can_view_student(own) = true
  ✓ [B→A] visible_group_ids_for = [14] — other org not present
  ✓ [B→A] visible_student_ids_for = [234] — other org student not leaked
  ✓ [B→A] org_ids_for = [8], is_org_scoped = true
  ✓ [B→A] can_view_group(other) = false
  ✓ [B→A] can_view_group(own) = true
  ✓ [B→A] can_view_student(other) = false
  ✓ [B→A] can_view_student(own) = true

== §8-11 · duplicate active membership in org A must be rejected by the DB ==
  INSERT=refused ERR=[Duplicate entry '7-235' for key 'uq_active_member']
SERVICE=err:duplicate_active_member
  ✓ raw INSERT refused by the database
  ✓ MySQL error names the uq_active_member key: Duplicate entry '7-235' for key 'uq_active_member'
  ✓ OrgService translates the DB error into WP_Error(duplicate_active_member)

ORGS CROSS-SCOPE PROOF PASSED
```

قراءة السطر الحاسم لـ§8-11: `Duplicate entry '7-235' for key 'uq_active_member'` — الرفض قادم من InnoDB باسم المفتاح الفريد الصريح، لا من `if` في PHP. وللتماثل في §8-5: `CAN_VIEW_OTHER_GROUP=no` و`CAN_VIEW_OTHER_STUDENT=no` من الاتّجاهين — لا مجموعة، لا طالب، لا نطاق يظهر عبر الحدّ.

### 3.4 مجموعة الاختبارات كاملة + phpcs

الأمر:

```
vendor/bin/phpunit && composer phpcs
```

المخرج الأساسي:

```
OK (145 tests, 509 assertions)
```

`phpcs` نظيف — لا أخطاء ولا تحذيرات معلَّقة.

---

## 4 · ما لم يُنفَّذ ولماذا

1. **لوحة إدارة الجهات وأوامر WP-CLI في §6/§9** — خارج نطاق المواصفة الحاليّة صراحةً (§2: «احمل العمود، وافرض النطاق، وأجّل المزايا»). يُبنى فوق البنية الحاليّة بلا ترحيل.
2. **النوع `licensee` وسلوك «مزوّد برمجيّات» بالكامل** — مقفَل بقرار O-11 حتى تُنجَز حِزمة §9.5. العمود موجود ليحمل المستقبل، والحُقن في `OrgService::create_org` يرفض التفعيل صراحةً.
3. **`can_view_recording`** — تُعيد `false` مع فلتر `minhaj_access_can_view_recording` كنقطة تعليق. النقطة الفعليّة تُملأ من `spec-recordings-v1`؛ لا صواب افتراضيّ قبلها.
4. **§8-6 / §8-7 / §8-9 / §8-10 من spec-organizations-v1** — تحتاج إمّا بيانات ولاية بيانات (dpa/scc) أو أدوات إشارة (transfer-check CLI) لم تدخل هذه الجولة. مقيَّدة في §5 من التقرير أدناه.
5. **§8-8 من spec-access-v1** لم يُنفَّذ كاختبار runtime بل كاختبار ثابت (`token_get_all` ثم `grep` على شيفرة غير مُعلَّقة). السبب: القاعدة ذاتها هي «لا `get_current_user_id()`»، وفحص الشيفرة أقوى من فحص السلوك في هذه الحالة.
6. **Zoom والحضور والتسجيلات** — كلّها في المرحلة 2 لكنّها مؤجَّلة كما طُلِب: «قف عند حدّ المواصفتين ولا تبدأ Zoom».

---

## 5 · الأسئلة المفتوحة

1. **حصريّة السوق أو اللغة بين الجهات** (spec-organizations §9-2): قرار تجاريّ يُكتب في العقد قبل الجهة الثانية، لا قيد بيانات.
2. **جهة قطر: واحدة أم فرعيّة؟** (spec-organizations §9-3): يؤثّر على دقّة تقارير الإسناد لا على البنية. يُحسم قبل أوّل تسوية.
3. **الوصيّ الثانوي وحدود «فتح الحصّة»** (spec-access §9-1): الاقتراح المكتوب في المواصفة هو «نعم، الفتح رؤية لا إدارة». حاليّاً `join_role` يعامل الوصيّ الثانوي بشرط `can_view=1` دون التمييز بين أساسيّ وثانوي — يوافق الاقتراح. يُثبَّت في المواصفة عند أول اختبار قبول ماليّ يكشف عن أثره.
4. **المعلّم البديل في حصّة تعويض** (spec-access §9-2): سيُحسم مع `spec-attendance-v1` — لا تأثير على هذه الجولة.
5. **بند تعاقديّ عاجل قبل عقد قطر** (spec-organizations §1): «حقّنا في التعاقد المباشر مع خرّيجي تدريبهم». قرار تجاريّ يخرج عن نطاق الشيفرة تماماً — لكنّه يقرّر ما إذا كان الأصل الأكبر الذي بنيناه ملكاً لنا أم رهناً بشريك.
6. **تشغيل `orgs-cross-scope.sh` تلقائيّاً في CI** — يحتاج بيئة MariaDB مطبَّقاً عليها كل الترحيلات ودور `minhaj_org_admin` مثبَّتاً بقدراته. `wp-env` الحاليّ يكفي محليّاً؛ الوصلة إلى CI مؤجَّلة.
