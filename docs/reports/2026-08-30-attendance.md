# تقرير · تنفيذ `spec-attendance-v1`

> **التاريخ:** 30 أغسطس 2026
> **النطاق:** الحضور الآليّ من Zoom + التصحيح البشريّ داخل نافذة + `no_show` مسار الجلسة + بُعد الجهة.
> **يعدّل المواصفة:** §3.1/§3.4 `student_id → minhaj_students.id`، §3.1 عمود `org_id`، §4 توصيل الأحداث عبر فلتر، و**R-14 (جديد)** — اختبارات ذات حمولة webhook واقعيّة الشكل مع تعليق أنّ التحقّق من الشكل الحيّ مؤجَّل حتى «أوّل تماسّ» مع Zoom.

---

## 1 · ما نُفِّذ

### تحديثات المواصفة قبل الكتابة

- **§3.1**: `student_id` يشير إلى `minhaj_students.id` (بعد قرار 18، لا `wp_users.ID`). أُضيف **عمود `org_id`** يُنسخ من الجلسة عند إنشاء الصفّ ويقرأه `AccessPolicy` لتقارير الجهة.
- **§3.4**: `student_id` في جدول التدقيق نفس المعنى الجديد.
- **§4**: حمولات `meeting.participant_joined` / `meeting.participant_left` / `meeting.ended` تصل `minhaj_zoom_events` وتوضَع `ignored` من `MeetingsService`؛ **هذه المواصفة توصِلها** عبر فلتر `minhaj_zoom_event_handled` — مشترك `AttendanceService` يعيد `true` فيتحوّل الحدث إلى `processed`. الربط بالطالب **عبر `zoom_registrant_id` وحده** → `minhaj_session_participants.subject_student_id`. اسم المشارك في Zoom لا يُطابَق نصّاً أبداً (R-1).
- **R-14 (جديد)**: الاختبارات تستعمل حمولة webhook بشكل مرجعيّ Zoom (`payload.object.participant.{participant_uuid, registrant_id, user_name, join_time, leave_time}`)؛ التحقّق من الشكل الحيّ مؤجَّل حتى نجرّبه على تنبيه فعليّ.

### وحدة `Minhaj\Modules\Attendance\`

- **أربعة جداول** (§3):
  - `minhaj_attendance` — صفّ لكل `(session, student)` مع `uq_session_student` (R-10)، ومنسوخ عمود `org_id`.
  - `minhaj_attendance_intervals` — الفترات الخام من Zoom، بفريد `uq_interval(participant_uuid, joined_at_utc)` يُنقذ من إعادة الإرسال (§3.2، AC-3).
  - `minhaj_teacher_presence` — بفريد `uq_session`، وعمود `finalized_at` يُلزم إبعاث `minhaj_attendance_finalized` **مرّةً واحدةً فقط** (AC-4).
  - `minhaj_attendance_audit`.
- **`AttendanceService`** ينفّذ §6 كاملاً: `record_interval`، `close_interval`، `close_open_intervals`، `finalize_session` (متعدّد التنفيذ آمن)، `amend` (نافذة 48 ساعة قابلة للفلترة، R-4/R-5)، `set_note` (R-9)، والقراءات.
- **`EventListener`** — الجسر بين Meetings وAttendance. يشترك في فلتر `minhaj_zoom_event_handled` ويطالب بأنواع الأحداث الثلاثة. **لا يلمس `TimetableService` ولا يستدعيه** (R-6 مبرهنة بـgrep).
- **بُعد الجهة** (R-13): `group_id` و`org_id` منسوخان على صفّ الحضور عند الكتابة الأولى.

### وحدة `Meetings`: تحويل الأحداث

- `MeetingsService::process_pending_events` صار يمرّر كل حدث عبر `apply_filters('minhaj_zoom_event_handled', false, $event_type, $payload)`. إن ادّعى مشترك أنّه عالجه ⇒ يُوسَم `processed`. لا مشترك ⇒ `ignored` كما قبل.

---

## 2 · الملفّات المتغيّرة

**جديدة — Attendance:**
- `plugins/minhaj-core/includes/Modules/Attendance/AttendanceService.php`
- `plugins/minhaj-core/includes/Modules/Attendance/EventListener.php`
- `plugins/minhaj-core/includes/Modules/Attendance/Events.php`
- `plugins/minhaj-core/includes/Modules/Attendance/Module.php`
- `plugins/minhaj-core/includes/Modules/Attendance/Domain/{AttendanceStatus,TeacherPresenceStatus,Source}.php`
- `plugins/minhaj-core/includes/Modules/Attendance/Repository/{AttendanceRepository,PersistenceException}.php`
- `plugins/minhaj-core/includes/Modules/Attendance/Migrations/CreateAttendanceTables.php`

**معدَّلة:**
- `plugins/minhaj-core/includes/Modules/Meetings/MeetingsService.php` — فلتر `minhaj_zoom_event_handled` بين الحالة الافتراضيّة والدفع إلى `IGNORED`.
- `plugins/minhaj-core/includes/Plugin.php` — تسجيل الوحدة.
- `docs/specs/spec-attendance-v1.md` — التحديثات الأربعة.

**اختبارات جديدة:**
- `tests/Unit/Modules/Attendance/AttendanceStatusTest.php` — AC-1 (present / late / absent + الحدود).
- `tests/Unit/Modules/Attendance/NoTimetableServiceCallInAttendanceGrepTest.php` — R-6 · فحص ثابت.
- `tests/Unit/Modules/Attendance/NoInternalNotesGrepTest.php` — R-9 / AC-12.
- `tests/Integration/attendance-pipeline.sh` — أنبوب حيّ متكامل بحمولات واقعيّة.

---

## 3 · معايير القبول والنتائج

### 3.1 اختبارات الوحدة

```
composer test:82
```

الناتج:
```
OK (182 tests, 602 assertions)
```

### 3.2 اختبار التكامل الحيّ

```
bash tests/Integration/attendance-pipeline.sh
```

الناتج الحرفيّ (بعد إزالة ألوان ANSI):

```
== Reset attendance + upstream tables ==
  SESSION=142 STUDENT_A=23 STUDENT_B=24 GROUP=75 START=2026-08-28 12:25:04 END=2026-08-28 13:25:04

== Replay realistic-shaped participant_joined / participant_left / meeting.ended ==
  ---
  FINALIZED_COUNT=1 UNKNOWN_COUNT=2
  STUDENT student_id=23 auto_status=present attended_seconds=3300
  STUDENT student_id=24 auto_status=late attended_seconds=3000
  INTERVALS_A=1
  ---
  ✓ AC-1a · student A → present with 55 min (3300s)
  ✓ AC-2 · student B interval sum = 3000s (three encounters merged, R-3)
  ✓ AC-3 · uq_interval deduplicated A's resent participant_joined
  ✓ AC-4 · two finalize_session calls → one minhaj_attendance_finalized event
  ✓ AC-9 · unknown registrant emitted minhaj_unknown_participant_detected

== R-12 · rejoin merge — a second meeting.ended after grace-window intervals folds into the same row ==
  STUDENT_A_AFTER_REJOIN=3600
STUDENT_A_ROWS=1
  ✓ R-12 · rejoin summed into the same row (3300 + 300 = 3600s)
  ✓ R-12 · still exactly one attendance row for student A (uq_session_student)

ATTENDANCE PIPELINE PROOF PASSED
```

قراءة الأسطر الحاسمة:

- **AC-1a**: `attended_seconds=3300` مع `auto_status=present` — نسبة ≥ 0.70 من ساعة كاملة.
- **AC-2 (R-3)**: طالب دخل ثلاث مرّات (20+20+10) — النتيجة `3000` ثانية = 50 دقيقة، بحساب المجموع لا الفرق بين طرفَي الفترات.
- **AC-3**: `INTERVALS_A=1` رغم بذر `participant_joined` مرّتين على `pu-A-1`.
- **AC-4**: `FINALIZED_COUNT=1` رغم بذر `meeting.ended` مرّتين.
- **AC-9**: `UNKNOWN_COUNT=2` — مسجَّل مجهول أثار الجرس مرّتين (`participant_joined` ولاحقاً في finalize).
- **R-12**: بعد إعادة انضمام بـ`pu-A-rejoin`، المجموع `3600` ثانية = 60 دقيقة، وصفّ الحضور واحد بلا ازدواج.

### 3.3 اختبار الكسر والاستعادة لكلّ حارس

| # | الحارس | كيف كُسر | ناتج الاختبار الحرفيّ | الاستعادة |
|---|---|---|---|---|
| 18 | **R-1 · لا مطابقة اسم** | حين لا يوجد صفّ مشارك، أُسند فترة المجهول لأوّل طالب في المجموعة بلا إبعاث `unknown_participant_detected` | `✗ UNKNOWN_COUNT=0 (expected ≥ 1)` — التسريب مرّ صامتاً | ✅ |
| 19 | **R-6 · لا استدعاء لـ`TimetableService`** | أُضيف مرجع `\Minhaj\Modules\Timetable\TimetableService::class` في `AttendanceService` (حتى ولو مُلغى) | `Failed asserting that two arrays are identical.\n-Array []\n+Array [0 => '/app/plugins/minhaj-core/includes/Modules/Attendance/AttendanceService.php']` | ✅ |
| 20 | **R-10 · إبعاث `finalized` مرّة واحدة** | حُذفت حماية `was_already_finalized` — إبعاث كل مرّة | `✗ FINALIZED_COUNT=2 (expected 1)` — الحدث انبعث لكلّ نداء | ✅ |
| 21 | **R-12 · جمع الفترات لا الفرق** | `attended` صار `last - first` بدلاً من مجموع الفترات | `✗ STUDENT_A_AFTER_REJOIN=3300 (expected 3600)` — إعادة الانضمام لم تُجمع | ✅ |

الأربعة احمرّت عند الكسر، اخضرّت عند الإصلاح.

### 3.4 `phpcs` وPHP 8.2

```
composer phpcs   → نظيف
composer test:82 → 182 tests, 602 assertions, OK
```

---

## 4 · ما لم يُنفَّذ ولماذا

- **`amend` بعد 48 ساعة بيد الإدارة**: فحص `apply_filters('minhaj_attendance_amend_window_hours')` يتيح تجاوز الوقت من قبل الفلترة، ولكن **قدرة الإدارة الصريحة** لتجاوز النافذة لم تُلحَق (تحتاج قدرةً جديدة `minhaj_admin_override_amend_window` وواجهةَ إدخال سبب). يُشحَن مع شاشة الحضور.
- **`reconcile` (`wp minhaj attendance reconcile`)** — يحتاج نداءَ Zoom Reports API وحقّ الاحتفاظ. مؤجَّل مع «أوّل تماسّ».
- **`zero-report` (`wp minhaj attendance zero-report --weeks=`)** — التقرير موجود منطقيّاً في `summary_for_group`؛ الأمر السطريّ لم يُلحَق (يُشحَن مع أوامر WP-CLI).
- **الحدّ الفعليّ لنافذة تسامح إعادة الانضمام** (M-20 / R-12) — الاعتماد اليوم على أنّ `finalize_session` idempotent وتحويل الفترات كلّها إلى الصفّ نفسه؛ عدّاد الانعقادات (`zoom_meeting_uuid` مصفوفة) وقفزة تصعيد الحالة لم يُلحَقا بالكامل.
- **حذف الفترات مع `anonymize_student`** (§7)  — يبقى مؤجَّلاً حتى نشحن مسار الإخفاء (يُبنى فوق الـPeopleService القائم).

---

## 5 · الأسئلة المفتوحة

1. **الطالبان على جهاز واحد** (`§9.1`) — لا زلنا بلا قرار. القرار ذو صلة مباشرة بحقل `subject_student_id`: هل نضيف `secondary_subject_student_id` أم نُنشئ صفّ حضور آلي مساوٍ لكلا الأخوَين؟
2. **`endpoint.url_validation` من Zoom** — عولج في `Meetings`، وسيصل `Attendance` أيضاً حين نبدأ نداءات `participant.*` — يُبَتّ عند «أوّل تماسّ».
3. **دقّة `late_seconds`** حين تكون فترات كثيرة صغيرة — الحساب اليوم يعتبر «فقط الفترة الأولى» تأخيراً. سؤال جودة: هل المتأخّر الذي يعود بعد ذلك يتأخّر مرّة أخرى؟ يُبَتّ مع شاشة الجودة.
4. **صياغة `viewer_scope`** للقراءات: اليوم فلتر `minhaj_attendance_viewer_wards` يعتمد على مشتركين خارجيّين. عند بناء بوّابة وليّ الأمر ندمج `AccessPolicy::visible_student_ids_for` رسميّاً.
5. **زمن مسار `no_show`** — الحساب اليوم يعتمد على `now_ts >= scheduled_end`. الأنسب: يعتمد على `now_ts >= scheduled_start + no_show_threshold`. يُبَتّ عند تنفيذ حارس `no_show` cron (خارج نطاق هذه المهمّة).
