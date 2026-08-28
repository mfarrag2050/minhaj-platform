# تقرير · تصحيحات ثلاثة على وحدة التقويم

> **التاريخ:** 29 أغسطس 2026 (متابعة لتقرير التقويم الأوّل)
> **النطاق:** ثلاثة أخطاء صامتة اكتُشفت في المراجعة قبل الإطلاق.
> **المرجع:** التقرير الأصليّ في `docs/reports/2026-08-29-calendar.md`.

---

## 1 · ما نُفِّذ

### تصحيح ١ · حذف `CONVERT_TZ` من حارس C-5

الاستعلام الأصليّ في `CalendarRepository::count_held_sessions_on_calendar_date_for_update` كان:

```sql
DATE( CONVERT_TZ( s.scheduled_start_utc, 'UTC', s.anchor_timezone ) ) = %s
```

**العيب**: `CONVERT_TZ` تعيد `NULL` حين لا تكون جداول `mysql.time_zone_name` مبذورة — والافتراض على تنصيبات كثيرة (RDS، صور Docker المصغَّرة، بعض تنصيبات cPanel) أنّها فارغة. `DATE(NULL) = 'x'` تُقيَّم `NULL` (لا `FALSE`)، فتُستبعَد الصفوف من `WHERE` ويعيد `COUNT` صفراً. النتيجة: **الحارس يفشل مفتوحاً**. لا يطابق أيّ صفّ، فيظنّ الخدمة أنّه لا جلسات منعقدة، فيسمح بحذف اليوم فوق جلسة انعقدت فعلاً.

wp-env يبذر 498 اسمَ منطقة — فالاختبار الأصليّ كان يمرّ. لكن الإنتاج ليس wp-env.

**الاستعلام الجديد**:

```sql
DATE( s.local_start_wall ) = %s
```

`local_start_wall` مخزَّن لحظة التوليد كسلسلة يوم-وقت محلّيّة في `anchor_timezone` (T-3 في `spec-timetable-v1`). المطابقة عليه لا تعتمد على أيّ جدول خارجيّ، ولا تُعاد اشتقاقاً من UTC، ولا تتغيّر مع تحديثات tzdata.

**مسح شامل**: `grep -rn "CONVERT_TZ" plugins tests` بعد التصحيح ⇒ لا مطابقات في شيفرة تُنفَّذ، ذكران فقط في تعليق يشرح لماذا لا نستعملها. أُضيف بند إلزاميّ في `CLAUDE.md § الجودة`: **`CONVERT_TZ` ممنوع في المستودع.**

### تصحيح ٢ · سقف صريح على تمديد `weeks_count` في `skip_and_extend`

مسار `TimetableService::generate_for_group` كان يوسّع نافذة التوليد آليّاً كلّما ازداد عدد أيام التقويم المعطَّلة:

```php
$walk_args['weeks_count'] = max( $walk_args['weeks_count'], $needed_weeks );
```

**العيب**: تقويم مُدخَل خطأً بمئة يوم عطلة، أو ملفّ استيراد اجتاحت به لوحة الإدارة صفوفاً فارغة، يجعل `$needed_weeks` كبيراً جداً. المولّد يمشي إلى السنة القادمة صامتاً ويولّد `lesson_no=36` بعد سنة كاملة من موعده. الطالب سيقع في ورطة يوم يقرأ التقرير.

**الفرض**: سقفٌ عند ضعف نافذة المتصل الأصليّة (`max($original * 2, $original + 4)`)، ورفضٌ مفهوم عند تجاوزه:

```
calendar_over_disabled — skip_and_extend would need N weeks (from O, cap M).
Too many disabled dates in the attached calendars — refuse rather than walk
into next year.
```

### تصحيح ٣ · `program_hours` يُعاد حسابه مع `skip_and_compress`

قبل التصحيح: عندما يضغط التوليد `total_sessions` من 36 إلى 32 (مثلاً)، كان يُكتب العدد الجديد إلى العمود، **ولا يُمَسّ `program_hours`**. المجموعة كانت تعلن «36 ساعة» وتسلّم 32 ساعة — ذلك بالضبط الادّعاء الذي تقوم عليه بوّابة الامتثال في `spec-compensation-v1 §2`.

**التصحيح**: عند كتابة `total_sessions` المضغوط، يُعاد حساب `program_hours` بالمعادلة:

```
program_hours = ROUND( total_sessions * session_duration_minutes / 60 )
```

الحسابان يقعان في **المعاملة نفسها** — الحقلان لا يتخالفان أبداً.

---

## 2 · الملفّات المتغيّرة

- `plugins/minhaj-core/includes/Modules/Calendar/Repository/CalendarRepository.php` — استعلام C-5 يقرأ `local_start_wall`.
- `plugins/minhaj-core/includes/Modules/Timetable/TimetableService.php` — سقف `weeks_count` + رسالة رفض، إعادة حساب `program_hours` عند الضغط.
- `tests/Unit/Modules/Timetable/TimetableServiceTest.php` — اختبار `test_skip_and_extend_refuses_when_walk_would_exceed_cap`.
- `tests/Integration/calendar-anchor-timezone.sh` — قسم `§7-4` جديد لحارس C-5 حيّاً + قسم لضغط `total_sessions`/`program_hours`.
- `CLAUDE.md` — بند إلزاميّ يمنع `CONVERT_TZ`.

---

## 3 · معايير القبول والنتائج

### 3.1 اختبارات الوحدة

```
composer test:82
```

الناتج الحرفيّ (ذيل):
```
............................................................... 126 / 163 ( 77%)
.....................................                           163 / 163 (100%)

Time: 00:00.192, Memory: 22.00 MB

OK (163 tests, 570 assertions)
```

### 3.2 اختبار التكامل الحيّ

```
bash tests/Integration/calendar-anchor-timezone.sh
```

الناتج الحرفيّ:
```
== Reset relevant tables ==
== Seed: one supplier org + one group in Pacific/Kiritimati + a calendar with 2027-01-05 disabled ==
  ORG=47 GROUP=53 TEACHER=353 CALENDAR=11 DAY=25

== C-2 · generation refuses when no calendar attached and no ack on file ==
  GATE_RESULT=err:no_calendar
  ✓ C-2 gate rejects with err:no_calendar

== Attach the calendar + seed teacher availability at 00:00–02:00 Tuesday local ==
  wired

== Generate 3 sessions · anchor Pacific/Kiritimati · start 00:30 local Tuesday · disable 2027-01-05 ==
  ---
  GEN=ok count=3
  SESSION seq=1 local=2027-01-12 00:30:00 utc=2027-01-11 10:30:00
  SESSION seq=2 local=2027-01-19 00:30:00 utc=2027-01-18 10:30:00
  SESSION seq=3 local=2027-01-26 00:30:00 utc=2027-01-25 10:30:00
  ---
  ✓ generation succeeded with exactly 3 sessions
  ✓ no session on 2027-01-05 local — the disabled anchor day was skipped
  ✓ UTC dates differ from local dates — the boundary the anchor-tz rule guards is live
  ✓ session on 2027-01-12 local present
  ✓ session on 2027-01-19 local present
  ✓ session on 2027-01-26 local present

== C-4 · skip_and_compress rewrites BOTH total_sessions AND program_hours ==
  COMPRESS=ok count=3
GROUP_TOTAL=3 GROUP_HOURS=3
  ✓ compression wrote total_sessions=3 AND program_hours=3 (marketing 6 hours no longer stands)

== §7-4 · C-5 held-session guard uses local_start_wall (anchor-local), not CONVERT_TZ ==
  MARKED_COMPLETED session_id=70 local_wall=2027-01-26 00:30:00 utc_start=2027-01-25 10:30:00
NEW_DAY=28
DELETE_RESULT=err:held_sessions_present
  ✓ delete_day refused — held session on anchor-local 2027-01-26 blocked the delete

CALENDAR ANCHOR-TZ PROOF PASSED
```

قراءة الأسطر الحاسمة:

- **Fix 1**: `local_wall=2027-01-26 00:30:00 utc_start=2027-01-25 10:30:00` — التاريخ المحلّيّ 26، وتاريخ UTC 25. لو استُعمل الأخير للمطابقة (كما كان يفعل `CONVERT_TZ` صامتاً حين تنقص جداول tz)، ما اجتاز الاختبار — وحلاوة أنّ الحارس رفض الحذف تُثبت أنّه استخدم اللقطة المحلّيّة الصحيحة.
- **Fix 3**: `GROUP_TOTAL=3 GROUP_HOURS=3` — الحقلان يتحرّكان معاً. لا مساحة تسمح لادّعاء «36 ساعة» بأن يبقى بعد ضغط.

### 3.3 اختبار الكسر والاستعادة لكلّ تصحيح

| # | الحارس | كيف كُسر | الناتج الحرفيّ | الاستعادة |
|---|---|---|---|---|
| 7 | **Fix 1** · `local_start_wall` في C-5 | استُبدل `s.local_start_wall` بـ`s.scheduled_start_utc` (نمط `CONVERT_TZ` الفاشل مفتوحاً) | `✗ delete_day returned accepted — the C-5 guard did not fire` — رفض الحذف صار قبولاً | ✅ |
| 8 | **Fix 2** · سقف `weeks_count` | `if ( $needed_weeks > $max_walk_weeks )` تحوّل إلى `if ( false && … )` | `- 'calendar_over_disabled' + 'availability_conflict'` — الوالكر انفلت إلى أسبوع 25، أوّل شيء استوقفه هو التوفّر | ✅ |
| 9 | **Fix 3** · إعادة حساب `program_hours` | حُذف عمود `program_hours` من `wpdb->update` | `✗ compression=3 total_sessions=3 program_hours=6 — expected 3/3/3` — الحقلان تخالفا فعلاً | ✅ |

كلّ الاختبارات الثلاثة احمرّت عند الكسر واخضرّت عند الإصلاح. **اختبار لم يفشل قطّ لم يُثبَت أنّه يعمل** — والآن ثبتت الثلاثة.

### 3.4 `phpcs` وحدود PHP 8.2

```
composer phpcs   →  نظيف
composer test:82 →  163 tests, 570 assertions, OK
```

### 3.5 اختبارات لم تنكسر (لا انحدار)

- `tests/Integration/orgs-cross-scope.sh` — الأخضر (لم تُمَسّ آليّة العزل ولا حارس العضويّة).

---

## 4 · ما لم يُنفَّذ ولماذا

- **مسح آليّ لـ`CONVERT_TZ` في CI** — القاعدة الآن مكتوبة في `CLAUDE.md`، لكن لا يوجد فحص PHPCS يمنعها. يُترك حتى نبني قاعدة `phpcs.xml.dist` مخصّصة أو نضيف اختبار `grep`-based في `tests/Unit/` مثل `NoImplicitActorGrepTest`.
- **مسح آليّ لأيّ عمود «ساعات مُعلَنة»** يخالف حسبة `total_sessions × session_duration_minutes` — يُترك حتى نبني تقارير الطلاب.

---

## 5 · الأسئلة المفتوحة

1. **`program_hours` للجمهور**: هل نعرضه لوليّ الأمر مباشرةً أم نعيد اشتقاقه في العرض من `total_sessions`؟ الاشتقاق يُغلق آخر ثغرة درفت. القرار: الاثنان — نُظهر القيمة المخزَّنة، والتقارير الآليّة تتحقّق من التطابق، والاختلاف يرفع تنبيهاً.
2. **`session_duration_minutes` غير الستّين**: إذا تغيّر يوماً إلى مثلاً 45، `program_hours = round(total × 45 / 60)` قد يُنتج نتيجة كسريّة. نستعمل `round()` اليوم — لا مشكلة في العدد الصحيح؛ يُبَتّ في التقريب حين نعتمد مدداً غير الستّين.
3. **حارس CONVERT_TZ في التنصيبات القديمة**: القرارُ التصحيحيّ هذا يحمي `wp_minhaj_sessions` القادمة؛ الجلسات المخزَّنة اليوم على قواعد إنتاج قديمة قد لا تحمل `local_start_wall` صحيحاً إن كانت مولَّدةً بأخطاء تاريخيّة. مراجعة أدواتٍ للتحقّق من الحقل — تُبنى مع cli الجرد.
