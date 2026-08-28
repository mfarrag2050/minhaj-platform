# spec-attendance-v1 — الحضور

> **الحالة:** مسوّدة للتنفيذ · 28 أغسطس 2026
> **الوحدة:** `Minhaj\Modules\Attendance\`
> **تعتمد على:** `spec-access-v1` · `spec-zoom-sessions-v1` · `spec-timetable-v1` (منفَّذ)
> **يعتمد عليها:** تقرير ما بعد الحصّة (المرحلة 3) · الفوترة (المرحلة 4)

---

## 1 · الغرض

تسجيل من حضر كل جلسة ومتى وكم بقي، آليّاً من أحداث Zoom وبتصحيح بشريّ محدود النافذة، وكتابة الحالة `no_show` على الجلسة حين لا ينعقد الدرس.

**ملاحظة على الشيفرة القائمة:** `SessionStatus::NO_SHOW` موجودة **بلا كاتب**. هذه المواصفة كاتبها.

## 2 · القرار الحاكم — ما الذي يستهلك رصيد الـ36 ساعة

> **الحصّة تُحتسب بانعقادها، لا بحضور الطالب.** *(قرار 28 أغسطس 2026)*

هذا القرار يُبسّط النظام تبسيطاً جوهريّاً، ويجب ألّا يُنقض ضمناً في التنفيذ:

- **الحضور لا يمسّ `lesson_no` ولا `sequence_no` ولا `expected_end_date`.** غياب الطالب حدثٌ يُسجَّل ويُبلَّغ به وليّ أمره، ولا يحرّك الجدول. هذا ما يُبقي وعد الـ12 أسبوعاً قائماً وطاقة المعلّمين محسوبة.
- **التعويض يبقى حصراً لما ألغيناه نحن.** المسار المنفَّذ اليوم (`cancel` → تعويض في آخر البرنامج، وإلّا `unscheduled`) هو **مسارنا نحن** — إلغاء إداريّ، غياب معلّم، `no_show`. غياب الطالب **لا يولّد تعويضاً ولا يستدعي `schedule_makeup`**.
- **حصّة انعقدت بحضور صفر تبقى منعقدة** — تُختم `completed`، وتُحتسب، ويُنبَّه الإدارة. لا تتحوّل إلى إلغاء.

## 3 · نموذج البيانات

### 3.1 `minhaj_attendance`

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `session_id` | BIGINT UNSIGNED NOT NULL | KEY |
| `student_id` | BIGINT UNSIGNED NOT NULL | KEY — **يشير إلى `minhaj_students.id`** بعد قرار 18، لا إلى `wp_users.ID` كما كان في المسوّدة |
| `group_id` | BIGINT UNSIGNED NOT NULL | KEY — منسوخ للاستعلام، لا للاشتقاق |
| `org_id` | BIGINT UNSIGNED NULL | KEY — **جديد**: منسوخ من الجلسة عند الإنشاء، ويقرأه `AccessPolicy` لتحديد نطاق مسؤول الجهة (`spec-organizations-v1` + R-13) |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'absent' | `present` / `late` / `absent` — KEY |
| `auto_status` | VARCHAR(20) NOT NULL DEFAULT 'absent' | ما استنتجه النظام قبل أيّ تعديل بشريّ |
| `source` | VARCHAR(20) NOT NULL DEFAULT 'zoom' | `zoom` / `manual` / `system` |
| `first_join_utc` | DATETIME NULL | |
| `last_leave_utc` | DATETIME NULL | |
| `attended_seconds` | INT UNSIGNED NOT NULL DEFAULT 0 | **مجموع** الفترات، لا الفرق بين الطرفين |
| `late_seconds` | INT UNSIGNED NOT NULL DEFAULT 0 | |
| `amended_by` | BIGINT UNSIGNED NULL | |
| `amended_at` | DATETIME NULL | |
| `amend_reason` | VARCHAR(255) NULL | |
| `note_visible` | TEXT NULL | **مرئيّ لوليّ الأمر بالضرورة** — انظر §5 R-9 |
| `created_at` / `updated_at` | DATETIME NOT NULL | |
| **UNIQUE `uq_session_student`** | `(session_id, student_id)` | صفّ واحد لكل طالب في الجلسة |

### 3.2 `minhaj_attendance_intervals`

الوقائع الخام من Zoom. تبقى منفصلة لأنّ الطالب يدخل ويخرج مرّات:

`id` PK · `session_id` (KEY) · `attendance_id` NULL (KEY) · `zoom_participant_uuid` VARCHAR(64) · `zoom_registrant_id` VARCHAR(64) (KEY) · `joined_at_utc` DATETIME NOT NULL · `left_at_utc` DATETIME NULL · `created_at` DATETIME.
**UNIQUE `uq_interval`** `(zoom_participant_uuid, joined_at_utc)` — يمنع ازدواج الفترة عند إعادة إرسال webhook.

### 3.3 `minhaj_teacher_presence`

حضور المعلّم — مصدر `no_show` وأساس الجودة لاحقاً:

`id` PK · `session_id` **UNIQUE** · `teacher_id` (KEY) · `first_join_utc` DATETIME NULL · `last_leave_utc` DATETIME NULL · `attended_seconds` INT UNSIGNED DEFAULT 0 · `status` VARCHAR(20) DEFAULT 'pending' (`attended` / `late` / `no_show`) · `created_at` / `updated_at`.

### 3.4 `minhaj_attendance_audit`

نمط جداول التدقيق القائمة: `id` · `session_id` NULL (KEY) · `student_id` NULL (KEY — **`minhaj_students.id`** كسائر مراجع المستودع بعد قرار 18) · `actor_user_id` (KEY — مستخدم ووردبريس، وليّ الأمر أو المعلّم أو الإدارة، وليس الطفل) · `action` VARCHAR(64) · `payload_json` LONGTEXT NULL · `created_at` (KEY).

## 4 · الاشتقاق الآليّ

مدخلات: `meeting.participant_joined` / `meeting.participant_left` / `meeting.ended` من `minhaj_zoom_events` — تصل الجدول اليوم و**تُوسَم `ignored` من `MeetingsService::process_pending_events`** لأنّ الوحدة لم تكن مكتوبة. هذه المواصفة **توصِلها** عبر الفلتر الوسيط `minhaj_zoom_event_handled`: مشترك من `AttendanceService` يُعيد `true` لأنواع أحداث المشاركين، فيتحوّل الحدث من `ignored` إلى `processed`.

الربط بالطالب **عبر `zoom_registrant_id` وحده** → `minhaj_session_participants.subject_student_id` (يشير إلى `minhaj_students.id` — قرار 18). **لا يُطابَق اسمُ المشارك في Zoom نصّاً أبداً**: الأسماء تتكرّر وتُنتحَل، والاسم الذي أرسلناه إلى Zoom يعرضه Zoom، فلا معنى لمطابقةٍ عليه لا زيادة ولا تحقّقاً (R-1).

عند `meeting.ended`، تُغلق الفترات المفتوحة على وقت الانتهاء، ثم لكل طالب:

| الشرط | `auto_status` |
|---|---|
| `attended_seconds ≥ present_threshold` **و** `late_seconds ≤ late_threshold` | `present` |
| `attended_seconds ≥ present_threshold` **و** `late_seconds > late_threshold` | `late` |
| غير ذلك (بما فيه صفر ثوانٍ) | `absent` |

**العتبات إعدادات لا ثوابت** (قرار 4: «مدّة ديناميكيّة تحدّدها الإدارة»):

| الإعداد | الافتراض | المعنى |
|---|---|---|
| `minhaj_attendance_present_ratio` | `0.70` | نسبة من `duration_minutes` **للجلسة** لا رقم دقائق ثابت |
| `minhaj_attendance_late_minutes` | `10` | بعدها يُعدّ متأخّراً |
| `minhaj_teacher_no_show_minutes` | `15` | لم يدخل المعلّم بعدها ⇒ `no_show` |

النسبة لا الدقائق: `duration_minutes` بيان على المجموعة (قرار 7)، فربط العتبة برقم مطلق يكسرها لأيّ مجموعة بمدّة مختلفة.

## 5 · القواعد

- **R-1 — لا حضور بلا مشارك مسجَّل.** الربط عبر `zoom_registrant_id` وحده. اسم معروض في Zoom لا يُطابَق نصّاً أبداً — الأسماء تتكرّر وتُنتحَل.
- **R-2 — دخول غير معروف يُعزَل لا يُهمَل.** فترة بـ`registrant_id` لا يقابله صفّ في `minhaj_session_participants` تُكتب بـ`attendance_id = NULL` وتُبلَّغ للإدارة. **دخول مجهول إلى حصّة أطفال حادثة حماية، لا خطأ بيانات.**
- **R-3 — الفترات تُجمع ولا تُطرح.** `attended_seconds` مجموع الفترات المغلقة. الطالب الذي ينقطع ويعود مرّتين حاضرٌ بمجموع دقائقه لا بالفرق بين أوّل دخول وآخر خروج.
- **R-4 — التصحيح البشريّ داخل نافذة.** المعلّم يعدّل الحالة خلال **48 ساعة** من انتهاء الجلسة، بسبب إلزاميّ. بعدها الإدارة وحدها. `auto_status` **لا يُمسّ أبداً** — يبقى شاهداً على ما رآه النظام.
- **R-5 — التصحيح لا يخترع فترات.** تعديل الحالة يدويّاً لا يكتب في `minhaj_attendance_intervals` ولا يغيّر `attended_seconds`. الوقائع من Zoom، والحكم من الإنسان، ولا يتنكّر أحدهما بالآخر.
- **R-6 — الحضور لا يحرّك الجدول.** لا استدعاء لـ`schedule_makeup` ولا لأيّ دالّة في `TimetableService` من هذه الوحدة إلا `no_show` (R-7). يُفرَض بفحص `grep` في CI.
- **R-7 — `no_show` مسار الجلسة لا مسار الطالب.** لم يدخل المعلّم خلال `minhaj_teacher_no_show_minutes` ⇒ الجلسة `no_show`، ويُطلق `minhaj_session_no_show`، ويُعامَل معاملة الإلغاء في الترقيم والتعويض عبر `LessonNumbering` القائم. حضور الطلاب في هذه الحالة يُسجَّل ولا يُحتسب حصّةً.
- **R-8 — حصّة بحضور صفر تبقى `completed`.** تُحتسب من الـ36، ويُطلق `minhaj_session_zero_attendance` للتنبيه. **مجموعة بثلاث حصص متتالية صفريّة الحضور تنبيه إداريّ عاجل** — هذه إشارة انسحاب أو خطأ جدولة، وأرخص وقت لاكتشافها الآن لا عند طلب الاسترداد.
- **R-9 — لا ملاحظة مخفيّة** *(قرار 7 — مبدأ المرآة)*. الحقل الوحيد للنصّ الحرّ اسمه `note_visible` ويظهر لوليّ الأمر. لا حقل ثانٍ، ولا `notes_internal`، ولا استعمال `payload_json` في التدقيق مخبأً للرأي في الطفل. القاعدة للفريق: **لا يُكتب عن طفل ما لا يُقبل أن يقرأه أبوه.**
- **R-10 — الحضور يُكتب مرّة ويُعدّل، لا يُعاد بناؤه.** إعادة معالجة أحداث الجلسة نفسها تُحدّث الصفّ القائم (`uq_session_student`) ولا تُدرج ثانياً، ولا تدهس تعديلاً بشريّاً: صفّ بـ`amended_at IS NOT NULL` يُحدَّث فيه `auto_status` فقط.
- **R-11 — الأحداث بعد COMMIT**، و`actor_user_id` صريح في كل كتابة (`0` للنظام مع `source='system'`).
- **R-12 — الحضور يُجمع عبر انعقادات الجلسة الواحدة** *(قرار Muhammed، 28 أغسطس 2026)*. إن عاد الاجتماع خلال `minhaj_session_rejoin_grace_minutes` (افتراض **10 دقائق، ديناميكيّ تضبطه الإدارة** — `spec-zoom-sessions-v1 §5 M-20`)، فالفترات من الانعقادين **تُجمع في صفّ حضور واحد**، ولا يُستدعى `finalize_session` إلا بعد الانتهاء الأخير. الطالب الذي بقي 25 دقيقة قبل الانقطاع و30 بعده **حاضر بـ55 دقيقة**، لا غائب مرّتين. والدقائق بين الانعقادين **لا تُحتسب غياباً على الطالب** — العطل عندنا لا عنده.
- **R-13 — بُعد الجهة.** `group_id` **و`org_id`** منسوخان على صفّ الحضور عند الإنشاء (لا مشتقّان بجوين لاحقاً). مسؤول الجهة يرى مجاميع حضور مجموعاتها، **لا** صفوف طلاب جهة أخرى — الفحص يُمرَّر عبر `AccessPolicy::org_ids_for( $user_id )` تماماً كما `visible_group_ids_for` (`spec-access-v1 §5 A-9`).

- **R-14 — الاختبارات تعيد تشغيل حمولات webhook واقعيّة الشكل**، لا كائنات مبسَّطة تصنعها الاختبارات نفسها. الحمولة تحمل بنية `payload.object.participant.{user_id, participant_uuid, id, user_name, join_time, leave_time}` كما تنشرها Zoom في وثائقها. لكن — كما في وحدة `Meetings` — **التحقّق من الشكل الحقيقيّ في الإنتاج مؤجَّل حتى «أوّل تماسّ»** مع Zoom الفعليّ؛ الاختبار الحاليّ يستعمل ما يُبينه المرجع، ولا يمكن أن يُثبت أنّ الحمولة الحيّة لا تختلف في مفتاح صغير حتى نراها.

## 6 · الواجهة العامّة

```php
final class Minhaj\Modules\Attendance\AttendanceService {
    public function __construct( AttendanceRepository $repo, AccessPolicy $access );

    public function record_interval( int $session_id, string $registrant_id, string $joined_utc, ?string $left_utc ): int;
    public function close_open_intervals( int $session_id, string $ended_at_utc ): int;
    public function finalize_session( int $session_id ): array;          // يشتقّ الحالات ويطلق الأحداث
    public function amend( int $actor_user_id, int $attendance_id, string $status, string $reason ): void;
    public function set_note( int $actor_user_id, int $attendance_id, string $note_visible ): void;
    public function for_session( int $session_id ): array;
    public function for_student( int $student_id, ?int $group_id = null ): array;
    public function summary_for_group( int $group_id ): array;           // حاضر/متأخّر/غائب لكل طالب
}
```

**الأحداث (بعد COMMIT):** `minhaj_attendance_recorded` · `minhaj_attendance_amended` · `minhaj_session_no_show` · `minhaj_session_zero_attendance` · `minhaj_unknown_participant_detected` · `minhaj_attendance_finalized` *(مرساة تقرير ما بعد الحصّة في المرحلة 3)*.

**الفلاتر:** `minhaj_attendance_present_ratio` · `minhaj_attendance_late_minutes` · `minhaj_teacher_no_show_minutes` · `minhaj_attendance_amend_window_hours` (افتراض 48) · `minhaj_attendance_auto_status` (اعتراض الاشتقاق قبل الكتابة).

**WP-CLI:** `wp minhaj attendance finalize --session=` · `wp minhaj attendance reconcile --from= --to=` (مطابقة مع تقرير مشاركي Zoom لجلسات ضاع فيها webhook) · `wp minhaj attendance zero-report --weeks=`.

## 7 · الصلاحيّات وتقليل البيانات

- القراءة: `minhaj_view_group` أو `minhaj_view_own_child_group` **+** المحلِّل. وليّ الأمر يرى **صفّ ابنه وحده**، لا صفوف زملائه ولا مجاميعهم (spec-access A-5).
- التعديل: `minhaj_record_attendance` + `AccessPolicy::can_record_attendance` + النافذة.
- `zoom_participant_uuid` و`registrant_id` معرّفات تشغيليّة: **لا تُعرض في أيّ واجهة**، وتُحذف مع صفوف الفترات عند إخفاء هويّة الطالب (`anonymize_student`).
- `anonymize_student` تُبقي صفّ الحضور (رقم لا هويّة) وتفرّغ `note_visible` وتحذف الفترات. استمرار العدّاد ضرورة محاسبيّة، واستمرار النصّ ليس كذلك.

## 8 · معايير القبول

1. طالب دخل بعد 3 دقائق وبقي 55 من 60 ⇒ `present`. دخل بعد 12 وبقي 45 ⇒ `late`. دخل بعد 40 وبقي 15 ⇒ `absent`.
2. طالب دخل وخرج ثلاث مرّات بمجموع 50 دقيقة ⇒ `present`، و`attended_seconds = 3000`، وثلاثة صفوف فترات.
3. إعادة إرسال `participant_joined` نفسه ⇒ لا فترة ثانية (`uq_interval`)، ولا تغيّر في `attended_seconds`.
4. `finalize_session` مرّتين ⇒ نتيجة واحدة، لا صفوف مكرّرة، و`minhaj_attendance_finalized` مرّة واحدة.
5. تعديل المعلّم بعد 47 ساعة ينجح؛ بعد 49 يُرفض برسالة مفهومة. `auto_status` بعد التعديل = قيمته الأصليّة.
6. إعادة معالجة أحداث جلسة عُدِّلت يدويّاً ⇒ `status` كما عدّله الإنسان، و`auto_status` محدَّث.
7. المعلّم لم يدخل خلال 15 دقيقة ⇒ الجلسة `no_show`، و`minhaj_session_no_show` مُطلق، وترقيم `lesson_no` أُزيح كما في الإلغاء.
8. لا طالب دخل والمعلّم دخل ⇒ الجلسة `completed`، وتُحتسب، و`minhaj_session_zero_attendance` مُطلق.
9. فترة بـ`registrant_id` مجهول ⇒ صفّ بـ`attendance_id = NULL` + `minhaj_unknown_participant_detected`، ولا يُحسب لأحد.
10. وليّ أمر يستدعي `for_session` لجلسة ابنه ⇒ صفّ واحد. صفوف الزملاء غير موجودة في الاستجابة (لا محجوبة في الواجهة).
11. `grep` لا يجد استدعاءً لـ`TimetableService` من هذه الوحدة إلا في مسار `no_show`.
12. لا حقل ولا عمود اسمه `notes_internal` أو ما يعادله في هذه الوحدة.

## 9 · الأسئلة المفتوحة

1. **الطالبان على جهاز واحد** (أخوان في مجموعة واحدة، مسجَّل واحد). يحتاج قراراً: هل يُمنع، أم يُسجَّل حضوراً مشتركاً بعلامة؟ يمسّ الفوترة.
2. **انقطاع الجلسة وعودتها بـ`uuid` جديد** — نفس السؤال المفتوح في `spec-zoom-sessions-v1 §9.2`. الحضور يُجمع عبر الانعقادين إن اعتُمدت نافذة التسامح. **قرار واحد يحسم المواصفتين.**
3. **جلسة ضاع فيها webhook كلّياً** (انقطاع شبكة عندنا): `reconcile` يجلب تقرير المشاركين من Zoom Reports API. يحتاج تثبيت مدّة الاحتفاظ بتقارير Zoom قبل الاعتماد عليه.
4. **مدّة نافذة التعديل 48 ساعة** افتراض مني لا قرار من Muhammed. إن كان تقرير ما بعد الحصّة يُرسل خلال ساعة (قرار 4)، فالتعديل بعد الإرسال يعني تقريراً ثانياً مصحَّحاً — يُحسم مع مواصفة التقارير.
