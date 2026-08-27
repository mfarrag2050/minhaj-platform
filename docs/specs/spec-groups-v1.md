# spec-groups-v1 — وحدة المجموعات (Groups)

| | |
|---|---|
| **Spec ID** | `spec-groups-v1` |
| **Module path** | `plugins/minhaj-core/includes/modules/groups/` |
| **Status** | DRAFT — للمراجعة قبل التنفيذ |
| **Date** | 2026-08-27 |
| **Depends on** | `modules/timetable` (قائم وعامل) · جداول `minhaj-core` القائمة (14) |
| **Blocks** | `modules/sessions` · `modules/attendance` · `modules/reports` · تكامل Zoom · تكامل WooCommerce |
| **Authority** | قرارات النظام 1، 3، 4 · أرقام التشغيل v0.1 |

> **قاعدة SDD (ADR-014):** لا كود قبل هذه المواصفة. أيّ انحراف عنها أثناء التنفيذ يُوثَّق هنا لا في الكود.

---

## 0 · تحقّق مطلوب قبل البدء

هذه المواصفة كُتبت **دون قراءة المستودع** (Claude.ai لا يصل إلى `minhaj-platform`). قبل تسليمها لـ Claude Code، الصق لي:

1. مخطّط الجداول الأربعة عشر (`SHOW CREATE TABLE` أو ملفّ الـ migrations).
2. الواجهة العامّة لوحدة `timetable`: أسماء الدوال، شكل نمط التكرار، وحدة تخزين الوقت (UTC؟).
3. اصطلاح التسمية: البادئة (`wp_minhaj_` أو `{$wpdb->prefix}minhaj_`)، وأسلوب الـ service classes والـ namespaces.

كلّ اسم حقل أو جدول في §3 أدناه **مقترَح** ويُوفَّق مع القائم. المنطق في §4–§7 لا يتغيّر بتغيّر الأسماء.

---

## 1 · المشكلة

النظام **جدول دراسيّ (timetable)** لا نظام حجز ولا نظام محتوى: مجموعة ثابتة + معلّم ثابت + مواعيد أسبوعيّة متكرّرة + مدّة برنامج محدّدة (36 ساعة). **المجموعة هي الوحدة المحوريّة** التي يتعلّق بها كلّ شيء آخر: الجدول، جلسات Zoom، الحضور، التقارير، الفوترة، وقناة التواصل.

قرار النظام 4 يجعل المجموعة أيضًا **وحدة الحماية**: لا تواصل خاصًّا إطلاقًا، وكلّ تواصل داخل مجموعة مسجَّلة. فأيّ خطأ في نمذجة المجموعة هو خطأ في ضابط حماية الطفل، لا خطأ تقنيّ فقط.

## 2 · النطاق

**داخل النطاق:** كيان المجموعة ودورة حياتها · العضويّة (طالب↔مجموعة) وتاريخها · إسناد المعلّم · تجميد خطّة البرنامج عند التفعيل · قواعد السعة والتشكيل · تخصيص المقاعد بأمان تزامنيّ · الأحداث (hooks) التي تبني عليها الوحدات الأخرى · الصلاحيات وسجلّ التدقيق · واجهة إدارة دنيا.

**خارج النطاق (يستهلك أحداث هذه الوحدة):** توليد الجلسات وZoom · الحضور · التقارير · الإشعارات · الدفع والباقات (WooCommerce) · اختبار الانتقال · مطابقة المعلّم بالطالب آليًّا (المرحلة الأولى **إسناد يدويّ من الإدارة**).

---

## 3 · نموذج البيانات (مقترَح)

**جداول مخصّصة لا CPT.** المجموعة كيان علائقيّ بقيود سلامة وقراءات مجدولة كثيفة؛ `wp_posts`/`postmeta` نمط خاطئ هنا. الطلاب وأولياء الأمور والمعلّمون **مستخدمو ووردبريس** بأدوار.

### 3.1 `minhaj_groups`

| الحقل | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT PK | |
| `code` | VARCHAR(32) UNIQUE | معرّف بشريّ، مثل `NL-B2609-A1-03` |
| `type` | ENUM(`individual`,`group`) | **كيان واحد لا كيانان** — الفرديّ مجموعة سعتها 1 |
| `status` | ENUM — انظر §4 | |
| `batch_id` | BIGINT FK | الدفعة الفصليّة |
| `level` | VARCHAR(32) | مستوى المنهج |
| `teacher_id` | BIGINT FK users | المعلّم الأصيل |
| `teaching_language` | CHAR(5) | لغة جسر المعلّم (`nl`,`fr`,…) — **مستقلّة عن لغة واجهة وليّ الأمر** (قرار 3) |
| `timezone` | VARCHAR(64) | منطقة مرجع المجموعة للعرض؛ التخزين UTC |
| `capacity_min` | TINYINT | مجمَّد عند الإنشاء |
| `capacity_max` | TINYINT | مجمَّد عند الإنشاء |
| `session_duration_minutes` | SMALLINT | **مجمَّد** — لا يقرأ الإعداد العامّ بعد التفعيل |
| `total_sessions` | SMALLINT | **مجمَّد** — مشتقّ من ساعات البرنامج ÷ المدّة |
| `sessions_per_week` | TINYINT | افتراض 3 |
| `program_hours` | SMALLINT | 36 |
| `planned_start_date` | DATE | **مُدخَل إداريّ** قبل التوليد — الوعد المقصود لوليّ الأمر. |
| `actual_start_date` | DATE | **يُختم آليّاً** عند انتقال أوّل جلسة إلى `completed`. لا يُعدَّل يدويّاً. |
| `expected_end_date` | DATE NULL | **مشتقّ** من `DATE(MAX(scheduled_start_utc))` لجلسات المجموعة **غير الملغاة وغير المعلّقة** (§5 من spec-timetable-v1). يعاد حسابه عند كلّ تغيير في الجدول. لا جلسات مجدولة بعد ⇒ `NULL` — الاشتقاق لا يُخمِّن. |
| `has_unscheduled_makeup` | TINYINT UNSIGNED | **مشتقّ** — 1 حين تحمل المجموعة تعويضاً معلّقاً واحداً على الأقلّ (§5.2 من spec-timetable-v1)؛ 0 خلاف ذلك. مربوط بـ`expected_end_date` حتى لا يُعرض تاريخ نهاية يبدو نهائياً وهو ليس كذلك. |
| `formation_deadline` | DATE | آخر موعد لاكتمال الحدّ الأدنى — انظر R-6 |
| `created_at` / `updated_at` / `deleted_at` | DATETIME | **حذف ناعم فقط** |

> **قاعدة اشتقاق التواريخ (2026-08-28)**: **الجلسات مصدر الحقيقة.** `expected_end_date` و`has_unscheduled_makeup` يكتبهما مستمع واحد في وحدة Timetable (`SessionDerivedDatesListener`) على أحداث `minhaj_sessions_generated` · `minhaj_session_cancelled` · `minhaj_session_rescheduled` · `minhaj_makeup_unscheduled` · `minhaj_makeup_scheduled` · `minhaj_session_completed`. لا تُحسب هذه الحقول في طبقة العرض ولا تُخمَّن — رقم واحد يراه الجميع. `planned_start_date` مُدخَل إداريّ يحدَّد قبل التوليد، و`actual_start_date` يُختم عند أوّل جلسة تُتمّم فعلاً.

### 3.2 `minhaj_group_members`

| الحقل | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT PK | |
| `group_id` / `student_id` | BIGINT FK | |
| `status` | ENUM(`active`,`withdrawn`,`transferred_out`,`completed`,`no_show`) | |
| `joined_at` / `left_at` | DATETIME | |
| `seat_index` | TINYINT | 1..capacity_max — يجعل قيد السعة قيدًا في قاعدة البيانات لا في PHP |
| `transferred_from_group_id` / `transferred_to_group_id` | BIGINT NULL | لحفظ سلسلة التنقّل |
| `order_id` | BIGINT NULL | ربط بالباقة/الطلب |

**فهرس فريد** `(group_id, seat_index)` حيث `status='active'` — أو جدول مقاعد منفصل إن لم يدعم MySQL الفهارس الجزئيّة في نسختكم. هذا هو ما يمنع الحجز الزائد فعليًّا.

**فهرس فريد** `(group_id, student_id)` حيث `status='active'` — لا عضويّة مزدوجة.

> **قرار تنفيذ (2026-08-27 · محدَّث):** MySQL/MariaDB لا يدعمان الفهارس الجزئيّة (`WHERE status='active'`). البديل المُطبَّق هو **عمودا مرآة مولَّدان (STORED generated columns)** يحسبهما محرّك القاعدة من `status`:
>
> ```sql
> active_seat_index  TINYINT UNSIGNED GENERATED ALWAYS AS (IF(status='active', seat_index,  NULL)) STORED
> active_student_id  BIGINT  UNSIGNED GENERATED ALWAYS AS (IF(status='active', student_id, NULL)) STORED
> ```
>
> الفهرسان الفريدان `UNIQUE (group_id, active_seat_index)` و`UNIQUE (group_id, active_student_id)` يقعان على هذين العمودَين. لأنّ MySQL يسمح بتكرار الـ`NULL` في الفهرس الفريد، الصفوف غير النشطة لا تصطدم — والصفّ يعود «نشطاً» فور تحوّل `status` إلى `active` بلا سطر واحد في PHP. **طبقة الخدمة لا تلمس هذين العمودَين ولا يمكنها نسيان مزامنتهما.** تُخرَج مسؤوليّة المزامنة من الكود إلى القيد ذاته، وهو ما تطلبه المواصفة صراحةً في §5 R-1: «يُفرَض في القاعدة لا في PHP».
>
> جرِّب على WordPress core `dbDelta` مع MariaDB 11 LTS: يقبل الصياغة ويولّد الأعمدة كما هي، والفهارس فوقها، بلا تحوير. `wp-env destroy && wp-env start` ثم `SHOW CREATE TABLE wp_minhaj_group_members` أثبت ذلك.

### 3.3 `minhaj_group_audit`
`id` · `group_id` · `actor_user_id` · `action` · `subject_id` · `payload_json` · `created_at`.
**كلّ** تغيير حالة أو عضويّة أو معلّم يُقيَّد هنا. مطلوب لحماية الطفل وللـGDPR (سجلّ من دخل مجموعة فيها قاصرون ومتى)، ولا يُحذف مع الحذف الناعم.

---

## 4 · دورة الحياة

```
draft ──▶ forming ──▶ scheduled ──▶ active ──▶ completed
             │            │            │
             │            │            └──▶ suspended ──▶ active | cancelled
             └────────────┴──────────────────▶ cancelled
```

| الحالة | المعنى | مسموح |
|---|---|---|
| `draft` | تُبنى إداريًّا، غير مرئيّة | تعديل كلّ شيء |
| `forming` | مفتوحة للتسجيل، لم تبلغ الحدّ الأدنى | إضافة/إزالة أعضاء |
| `scheduled` | بلغت الحدّ الأدنى وثُبّت الجدول والمعلّم، لم تبدأ | إضافة حتى `capacity_max` |
| `active` | بدأت فعليًّا (`actual_start_date`) | إضافة متأخّر بموافقة صريحة؛ الانسحاب يبقي المقعد شاغرًا |
| `suspended` | إيقاف مؤقّت (غياب معلّم، عطلة) — **الجلسات المستقبليّة تُعلَّق لا تُحذف** | استئناف أو إلغاء |
| `completed` | استُهلكت `total_sessions` | للقراءة فقط |
| `cancelled` | لم تتشكّل أو أُلغيت | للقراءة فقط |

الانتقال إلى `scheduled` هو **لحظة التجميد**: تُنسخ المدّة وعدد الجلسات والسعة من الإعدادات إلى صفّ المجموعة، ولا تتأثّر بعدها بأيّ تغيير عامّ. هذا ما يجعل **قرار مدّة الحصّة المعلّق لا يحجب بناء هذه الوحدة** — يحجب شراء تراخيص Zoom وحسابات السعة فقط.

---

## 5 · القواعد الثابتة (invariants) — كلّها قابلة للاختبار

- **R-1** لا تتجاوز العضويّات النشطة `capacity_max` أبدًا. يُفرَض بقيد فريد في قاعدة البيانات + معاملة، لا بفحص في PHP.
- **R-2** لا تنتقل مجموعة إلى `scheduled` بأقلّ من `capacity_min`.
- **R-3** `type='individual'` ⇒ `capacity_min = capacity_max = 1`.
- **R-4** `type='group'` ⇒ الافتراض `min=3, max=5` (أرقام التشغيل v0.1، وهي **تعديل معلن** لقرار 1 الذي قال 3 حدًّا أقصى). القيمتان قابلتان للضبط في الإعدادات بسقف صلب 6؛ تجاوزه يحتاج تعديل كود متعمَّدًا.
- **R-5** لكلّ مجموعة `active`/`scheduled` معلّم واحد غير فارغ. البديل يُسجَّل **على الجلسة** لا على المجموعة.
- **R-6** مجموعة `forming` تجاوزت `formation_deadline` دون بلوغ الحدّ الأدنى ⇒ تُرفع للإدارة بقرار صريح: دمج · تمديد · تحويل إلى فرديّ بسعر مختلف · إلغاء واسترداد. **لا يُتَّخذ القرار آليًّا** (له أثر ماليّ وتعاقديّ).
- **R-7** الطالب في مجموعة `active` واحدة لكلّ مستوى في الوقت نفسه.
- **R-8** لا حذف صلب لمجموعة ولا لعضويّة. الانسحاب حالة، والحذف ناعم.
- **R-9** تغيير `teaching_language` أو `teacher_id` بعد `active` يستلزم سجلّ تدقيق وإشعارًا لأولياء الأمور.
- **R-10** كلّ الأوقات UTC في التخزين؛ العرض بمنطقة المجموعة أو المستخدم.

---

## 6 · الواجهة العامّة

`Minhaj\Groups\GroupService` (استعلامات قراءة في `GroupRepository`؛ لا SQL في طبقة العرض):

```
create( array $args ): int|WP_Error
update( int $group_id, array $args ): true|WP_Error
transition( int $group_id, string $to_status, string $reason ): true|WP_Error

add_member( int $group_id, int $student_id, array $ctx = [] ): int|WP_Error   // معامَلة + قفل صفّ
remove_member( int $membership_id, string $reason ): true|WP_Error
transfer_member( int $membership_id, int $to_group_id, string $reason ): int|WP_Error

assign_teacher( int $group_id, int $teacher_id, string $reason ): true|WP_Error
available_seats( int $group_id ): int
can_accept( int $group_id, int $student_id ): true|WP_Error   // فحص جاف بلا آثار جانبيّة
```

**التزامن:** `add_member` تعمل داخل `START TRANSACTION` مع `SELECT ... FOR UPDATE` على صفّ المجموعة، وتعتمد على قيد `(group_id, seat_index)` كخطّ الدفاع الأخير. سيناريو الفشل الحقيقيّ: طلبان في WooCommerce يُكملان الدفع في اللحظة نفسها على آخر مقعد. **يجب أن يفشل الثاني فشلًا نظيفًا** ويُوجَّه لقائمة الانتظار، لا أن يُنشئ مجموعة من ستّة.

**الأحداث** (هي عقد التكامل — الوحدات الأخرى لا تستدعي هذه الوحدة مباشرة):

| الحدث | المستهلك |
|---|---|
| `minhaj_group_scheduled` | timetable → توليد المواقيت · sessions → توليد جلسات Zoom |
| `minhaj_group_activated` | notifications · reports |
| `minhaj_group_member_added` / `_removed` / `_transferred` | attendance · notifications · billing |
| `minhaj_group_teacher_assigned` / `_changed` | zoom (المضيف البديل) · notifications |
| `minhaj_group_suspended` / `_resumed` | sessions → تعليق/استئناف المواقيت المستقبليّة |
| `minhaj_group_completed` | تقييم الانتقال · شهادات · retention |

**Filters:** `minhaj_group_default_capacity` · `minhaj_group_code_format` · `minhaj_group_can_accept_student`.

**التكامل مع Timetable:** المجموعة تملك **نمط التكرار** (أيام + أوقات + عدد الأسابيع). عند `scheduled` تُسلَّم للـtimetable التي تولّد المواقيت الفعليّة. المجموعة **لا تعرف Zoom إطلاقًا** — تلك مسؤوليّة `sessions`.

---

## 7 · الصلاحيات وتقليل البيانات

| القدرة | من |
|---|---|
| `minhaj_manage_groups` | الإدارة |
| `minhaj_view_group` | المعلّم — مجموعاته فقط |
| `minhaj_view_own_child_group` | وليّ الأمر |

**قرار تصميم يحتاج تثبيتك:** ماذا يرى وليّ الأمر من قائمة المجموعة؟ **التوصية: الاسم الأوّل فقط لبقيّة الطلاب، ولا صور ولا بيانات تواصل.** قائمة كاملة بأسماء أطفال آخرين معروضة على أولياء أمور غرباء هي مشكلة GDPR وحماية طفل معًا، وهي بالضبط ما تسأل عنه الجهة الهولنديّة في مسار الاعتماد. المعلّم والإدارة يريان الكامل.

كلّ استدعاء يمرّ بفحص القدرة + `check_admin_referer`/nonce، ولا يُعتمد على إخفاء الواجهة. صفّ التدقيق يُكتب **قبل** الاستجابة لا بعدها.

---

## 8 · واجهة الإدارة الدنيا (المرحلة الأولى)

جدول مجموعات (فلترة بالحالة/الدفعة/اللغة/المعلّم) · إنشاء مجموعة · شاشة مجموعة (أعضاء، مقاعد شاغرة، معلّم، نمط الجدول، سجلّ التدقيق) · إضافة/إزالة/نقل عضو · إسناد معلّم · أزرار الانتقال بين الحالات مع حقل سبب إلزاميّ.
كلّ النصوص عبر `__()` بنطاق `minhaj-core` — لا نصّ مكتوب في الكود (ستّ لغات).

## 9 · معايير القبول

1. إنشاء مجموعة `group`، إضافة 3 طلاب ⇒ الانتقال إلى `scheduled` ينجح ويطلق `minhaj_group_scheduled`.
2. إضافة طالب سادس ⇒ `WP_Error` بلا تغيير في قاعدة البيانات.
3. طلبا `add_member` متزامنان على آخر مقعد ⇒ ينجح واحد ويفشل الآخر فشلًا نظيفًا؛ العضويّات النشطة = `capacity_max` بالضبط.
4. مجموعة بطالبين تجاوزت `formation_deadline` ⇒ لا انتقال آليّ؛ تظهر في طابور قرار الإدارة.
5. تغيير مدّة الحصّة الافتراضيّة في الإعدادات ⇒ **لا يتغيّر** `session_duration_minutes` لمجموعة `active`.
6. نقل طالب بين مجموعتين ⇒ يبقى صفّ `transferred_out` وسلسلة النقل قابلة للتتبّع من الطرفين.
7. `remove_member` ⇒ لا حذف صلب؛ الحضور التاريخيّ سليم.
8. وليّ أمر يطلب مجموعة ليست لابنه ⇒ 403.
9. كلّ انتقال حالة وكلّ تغيير عضويّة له صفّ في `minhaj_group_audit` باسم الفاعل.
10. `PHPCS` بمعيار WordPress نظيف، وتغطية اختبارات وحدة لكلّ قاعدة من R-1..R-10.

## 10 · أسئلة مفتوحة تحجب اكتمال هذه الوحدة

1. **حجم المجموعة النهائيّ**: 3–5 (أرقام التشغيل) أم 3 حدًّا أقصى (قرار 1). تناقض قائم في وثيقتين — **احسمه**. المواصفة تفترض 3–5.
2. **قائمة الانتظار**: كيان مستقلّ أم مجرّد مجموعة `forming`؟ التوصية: `forming` كافية للمرحلة الأولى.
3. **الاستمرار لمستوى تالٍ**: مجموعة جديدة أم تمديد القائمة؟ يؤثّر في `completed` وفي كلّ حسابات السعة.
4. **رؤية وليّ الأمر لقائمة المجموعة** (§7).
5. مدّة الحصّة — **لا تحجب هذه الوحدة** بفضل التجميد في §4، لكنها تحجب Zoom والسعة.
