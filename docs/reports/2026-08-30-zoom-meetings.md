# تقرير · تنفيذ `spec-zoom-sessions-v1`

> **التاريخ:** 30 أغسطس 2026
> **النطاق:** الاجتماعات + المسجَّلون + webhooks + سقف التزامن + M-22.
> **مؤجَّل:** مسار التسجيل السحابيّ (`recording.completed` وما بعده) — كما طلب المستخدم، لأنّه مرتبط بخطّة الحساب.
> **يعدّل المواصفة:** §7 (اسم من `minhaj_students`)، M-12 (المفتاح على `students.id`)، وقاعدة جديدة M-22 (قفل إعدادات الحماية).

---

## 1 · ما نُفِّذ

### تحديثات المواصفة قبل الكتابة

- **§7 · M-9** يقرأ الاسم من `minhaj_students` (لا `minhaj_student_profiles` — قرار 18 غيّر الجدول).
- **§5.3 M-12** يقول صراحةً: `subject_student_id = minhaj_students.id`، و`uq_session_subject(session_id, subject_student_id)` مفتاحه يشير إلى الجدول الجديد.
- **§5.4 M-22 (جديد)** — إعدادات الحماية مقفَلة على مستوى الحساب: `private_chat=false`، `chat=true` (عامّ)، `screen_sharing.participants=disabled`، `file_transfer=false`، `local_recording=false`، `waiting_room=true`، `allow_participants_to_rename_themselves=false`. **فحصٌ يوميّ** بنداء `GET /accounts/me/settings` عبر ZoomClient يقارن ويطلق `minhaj_zoom_security_drift` لكل انحراف. الضابط الذي لا يُفحَص ينحلّ بصمت.

### وحدة `Minhaj\Modules\Meetings\`

- **خمسة جداول** (§3): `minhaj_zoom_licenses`، `minhaj_session_meetings` (بفريد `uq_session`)، `minhaj_session_participants` (بفريدَين: `uq_session_subject` و`uq_session_host` عبر عمود مولَّد `STORED` باسم `active_host_flag`)، `minhaj_zoom_events` (بفريد `uq_dedup`)، `minhaj_meetings_audit`. **لا عمود** لـ`start_url` أو `join_url` أو كلمة مرور اجتماع في أيّ جدول — تُبرهن هذه القاعدة بـgrep.
- **`ZoomClient`** واجهة، مع تنفيذَين: `HttpZoomClient` (Server-to-Server OAuth، ثوابت من `wp-config.php` وحدها — M-14) و`FakeZoomClient` (للاختبار). الاختيار عبر فلتر `minhaj_zoom_client`.
- **`WebhookVerifier`** — M-15: HMAC-SHA256 على `v0:{ts}:{body}`، `hash_equals`، رفض >5 دقائق. يدعم `endpoint.url_validation` بردّ الـplainToken المشفَّر.
- **`SecuritySettingsChecker`** — M-22: يقارن الإعدادات المطلوبة بما تعيده Zoom، ويعيد قائمة انحرافات (بلا محاولة إصلاح تلقائيّة — البشر يقرّرون).
- **`MeetingsService`** — القالب الكامل مع الطبقات المألوفة:
  - `create_meeting_for_session` (M-1، M-3، M-4، `uq_session`).
  - `revoke_meeting_for_session` (M-2).
  - `issue_join_ticket` (M-9، M-10، M-11، M-12، M-13 عبر العمود المولَّد).
  - `assert_concurrency_within_cap` (M-5، M-6، M-7 · `SELECT ... FOR UPDATE` على نافذة التداخل — نمط `lock_teacher_sessions_between`).
  - `concurrency_at`.
  - `ingest_webhook` (M-15، M-16، M-17): يُنظِّف `join_url` / `start_url` / `password` من الحمولة قبل التخزين.
  - `process_pending_events` (M-18، M-19): `meeting.started` بعد `ended` يُتجاهَل.
- **`Rest\WebhookController`** — نقطة `/wp-json/minhaj/v1/zoom/webhook`. `permission_callback` يُفحَص فيه التوقيع. رفع الحدث إلى `ingest_webhook` وردّ 200 خلال ثوانٍ.
- **`JoinStrategy`** واجهة — التنفيذ الحاليّ عبر Zoom Registrant API. يمكن استبداله بـMeeting SDK لاحقاً بتغيير صنف واحد.

---

## 2 · الملفّات المتغيّرة

**جديدة (18 ملفّ):**
- `plugins/minhaj-core/includes/Modules/Meetings/Migrations/CreateMeetingsTables.php`
- `plugins/minhaj-core/includes/Modules/Meetings/Domain/{MeetingState,ParticipantRole,EventStatus,JoinTicket,RuleViolationException}.php`
- `plugins/minhaj-core/includes/Modules/Meetings/Repository/{MeetingsRepository,PersistenceException}.php`
- `plugins/minhaj-core/includes/Modules/Meetings/Zoom/{ZoomClient,HttpZoomClient,FakeZoomClient,ZoomApiException,WebhookVerifier,SecuritySettingsChecker}.php`
- `plugins/minhaj-core/includes/Modules/Meetings/{Events,JoinStrategy,MeetingsService,Module}.php`
- `plugins/minhaj-core/includes/Modules/Meetings/Rest/WebhookController.php`

**معدَّلة:**
- `plugins/minhaj-core/includes/Plugin.php` — تسجيل الوحدة.
- `docs/specs/spec-zoom-sessions-v1.md` — التصحيحات الثلاثة.

**اختبارات جديدة:**
- `tests/Unit/Modules/Meetings/WebhookVerifierTest.php`
- `tests/Unit/Modules/Meetings/SecuritySettingsCheckerTest.php`
- `tests/Integration/meetings-zoom.sh`

---

## 3 · معايير القبول والنتائج

### 3.1 اختبارات الوحدة

```
composer test:82
```

الناتج:
```
OK (175 tests, 593 assertions)
```

### 3.2 اختبار التكامل الحيّ على wp-env

```
bash tests/Integration/meetings-zoom.sh
```

الناتج الحرفيّ (بعد إزالة ألوان ANSI):

```
== Reset meetings tables + seed one active license + one session ==
  SESSION=137

== AC-1 · create meeting for session; second call must not duplicate ==
  M1=1 M2=1
MEETINGS_COUNT=1
  ✓ exactly one meeting row for the session (uq_session enforced)

== AC-2 · a second host row for the same session must be rejected by the DB ==
  FIRST_ERR=[] SECOND=refused SECOND_ERR=[Duplicate entry '137-1' for key 'uq_session_host']
  ✓ second host row refused by uq_session_host (STORED generated column)

== AC-3 · unsigned webhook → 401; signed → 200 + one event row ==
  BAD_ALLOWED=no
GOOD_ALLOWED=yes
STATUS=200
EVENTS_ROWS=1
  ✓ bad signature → permission_callback returned false (would 401)
  ✓ good signature → 200 with event row inserted
  ✓ one event row in the DB

== AC-4 · deliver the same event three more times → still one row (uq_dedup) ==
  MEETING_ENDED_ROWS=1
  ✓ three deliveries → one row (uq_dedup enforced)

== AC-6 · concurrency cap: license capacity 2, four seeded meetings, fifth candidate fails ==
  CAP_RESULT=refused rule=M-6
  ✓ concurrency cap fires on the fifth slot with rule=M-6

== AC-11 · grep the module for start_url / join_url in INSERT / UPDATE queries ==
  ✓ no start_url / join_url in any Meetings INSERT / UPDATE

MEETINGS ZOOM PROOF PASSED
```

قراءة الأسطر الحاسمة:

- **AC-2**: `Duplicate entry '137-1' for key 'uq_session_host'` — الرفض من InnoDB باسم المفتاح الفريد الصريح على العمود المولَّد.
- **AC-3**: `BAD_ALLOWED=no` قبل الإدراج — `permission_callback` يمنع الوصول للـhandler إن كان التوقيع مزيَّفاً؛ فلا يُلامَس جدول الأحداث.
- **AC-4**: بعد ثلاثة تسليمات لنفس الحدث، `MEETING_ENDED_ROWS=1` — القاعدة تفرض عدم التكرار عبر `uq_dedup`.
- **AC-6**: `rule=M-6` — الاستثناء المُلقى يحمل اسم القاعدة صراحةً كما أمر §5.2.
- **AC-11**: `grep -rEn '\$wpdb->insert|\$wpdb->update' ... | grep -Ei 'start_url|join_url'` أرجع صفر أسطر.

### 3.3 اختبار الكسر والاستعادة لكلّ حارس

| # | الحارس | كيف كُسر | ناتج الاختبار الحرفيّ | الاستعادة |
|---|---|---|---|---|
| 14 | **M-15 · تحقّق التوقيع** | `hash_equals` رُبِط بـ`if (false && ...)` — التحقّق يُتجاهَل | `Expected 'invalid' Actual 'valid'` — جسم مُلاعَب اجتاز التحقّق | ✅ |
| 15 | **M-17 · dedup key** | مفتاح التكرار تحوّل إلى `hash(... . microtime(true) . wp_generate_uuid4())` — يتغيّر مع كل نداء | `MEETING_ENDED_ROWS=3 — expected 1` | ✅ |
| 16 | **M-6 · سقف التزامن** | `if ($peak > $cap)` تحوّل إلى `if (false && ...)` | `CAP_RESULT=accepted` — التوليد قَبِل الجلسة الخامسة رغم بلوغ السقف | ✅ |
| 17 | **M-22 · فحص الإعدادات** | `!==` تحوّل إلى `!=`، ومنطق التقرير عُكس إلى `continue` بدل الإضافة | `Failed asserting that actual size 0 matches expected size 1` — انحراف `private_chat=true` مرّ صامتاً | ✅ |

الأربعة احمرّت عند الكسر، اخضرّت عند الإصلاح.

### 3.4 `phpcs` وحدود PHP 8.2

```
composer phpcs   → نظيف
composer test:82 → 175 tests, 593 assertions, OK
```

---

## 4 · ما لم يُنفَّذ ولماذا

- **`recording.completed` وما بعده** — مؤجَّل صراحةً كما طُلِب. الحدث سيدخل جدول `minhaj_zoom_events` ويُوسَم `ignored` (لا يفشل التسليم). سيُطبَّق ضمن `spec-recordings-v1`.
- **`create-due` cron + WP-CLI** — الخدمة تحمل `list_pending_due` جاهزاً؛ الأمر السطريّ نفسه (`wp minhaj meetings create-due`) لم يُلحَق بعد، وسيُشحَن في المرحلة القادمة.
- **`process-events` cron** — الخدمة تحمل `process_pending_events` جاهزةً؛ ربطها بـ`wp_schedule_event` سيُشحَن مع أمر السطر.
- **`M-2 reschedule` الآليّ** — القدرة موجودة في `revoke_meeting_for_session` + `create_meeting_for_session`. الربط عبر `minhaj_session_rescheduled` مؤجَّل حتى ينضج `recalculate_from` (خارج نطاق هذه المهمّة).
- **`M-20 rejoin grace window`** — البنية جاهزة (`MeetingState::STARTED` تسمح بإعادة الدخول)، لكن عدّاد الانعقادات (`zoom_meeting_uuid[]`) لم يُطبَّق بعد. عند إعادة الجدولة يُلحَق.
- **الحدّ الأدنى للمعدَّل** *(§9 السؤال 3)* — يُبَتّ عند بذر تراخيص فعليّة، لا الآن.

---

## 5 · الأسئلة المفتوحة

1. **مفتاح `MINHAJ_ZOOM_WEBHOOK_SECRET`** يُقرأ من ثابت. تدوير المفتاح يعني نشرَ ثابت جديد على الخادم وإعادة تحميل — يحتاج توثيقاً في `docs/runbooks/` عند إطلاق الإنتاج.
2. **معالج `endpoint.url_validation`** يعمل عبر `permission_callback`، لكنّ Zoom يتوقّع أحياناً استجابة 200 بردّ HMAC حتى قبل التوقيع. عند تسجيل النقطة أوّل مرّة يجب اختبار المسار من لوحة Zoom.
3. **بريد المسجَّل عند مضيف الاجتماع**: نستعمل `user_email` من `wp_users`. المعلّم دون بريد صحيح يُنشئ Zoom خطأ. يُبَتّ حين نضيف تحقّق البريد إلى `PeopleService::transition_teacher`.
4. **جهة التنبيه على `minhaj_zoom_security_drift`**: من يستقبل الحدث؟ مؤقّتاً يبقى `do_action` مفتوحاً؛ عند بناء لوحة الإدارة نضع مستمعاً يكتب إشعاراً.
5. **قسم `recordings` عندما يُنشَط**: هل تتحوّل `state='ended'` إلى `awaiting_recording` حتى تُنزَّل السحابة؟ يُبَتّ في `spec-recordings-v1`.
