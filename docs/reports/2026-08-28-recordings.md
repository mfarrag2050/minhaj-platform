# تقرير · تنفيذ `spec-recordings-v1` (المرحلة 2 · التسجيلات)

> **التاريخ:** 28 أغسطس 2026
> **النطاق:** كل ما لا يحتاج Zoom حيّاً — بُنِيَ كاملاً، ومسار Zoom بُنِيَ ضدّ عميل مزيَّف يقبل الفحص الثلاثيّ. لا اختبار على حساب Zoom يمتلك السحابة لأنّها ليست مفعَّلة بعد على خطّتنا.
> **تعديلات على المواصفة:** **G-17 جديدة** (سياسة الاحتفاظ عبر النسخ الاحتياطيّ) · **§6.1 جديد** (سطح الإدارة — قرار 20: بلا شاشة، عن قصد).

> ### تذكير · G-1
> **لا حذف من Zoom قبل تحقّق ثلاثيّ كامل** (حجم = المُعلَن، `checksum_sha256` على الملفّ المخزَّن، قراءة اختباريّة من التخزين). **تخلّف واحد ⇒ لا حذف.** فقدان تسجيل حصّة أطفال لا يُعوَّض. المنفَّذ يبرهن هذه القاعدة في AC-3 وG-1-break.

---

## 1 · ما نُفِّذ

### تعديلات على المواصفة (قبل الكتابة)

- **G-17 (جديد) · سياسة الاحتفاظ عبر حدود التخزين.** الحذف من تخزيننا وحده لا يكفي: نسخة احتياطيّة تُبقي فيديو ستّة أشهر بعد `purged_at` تُبطل قرار 12 بصمت. المواصفة توثّق ثلاث فئات مستقلّة — الوسائط، القاعدة، الشيفرة — بسياسات موقَّعة **قبل التشغيل، لا في مصفوفة PHP**.
- **§6.1 (جديد) · سطح الإدارة.** قرار 20 يقول «الوحدة لا تحمل شاشة إدارة»: قناة المشاهدة رابط موقَّع 15 دقيقة يُصدَره الخادم لمشاهد بالاسم، لا لوحة تعرض روابط تسجيلات قاصرين. الأعمال الروتينيّة آليّة، والاستثناءات عبر CLI (`purge-expired`, `download-due`, `quota-report`, `verify`, `orphan-check`). زرّ «Watch» يقع في **صفحة الحصّة** (في وحدة `Timetable/`)، لا هنا.

### وحدة `Minhaj\Modules\Recordings\` (Split A كاملة)

- **ثلاث جداول** (§3):
  - `minhaj_recordings` — `retention_until DATE NOT NULL` (لا صفّ بلا تاريخ حذف)، `uq_zoom_file` على `zoom_file_id` (حاجز الازدواج · G-5)، `storage_region NOT NULL`، مفاتيح على `status/retention_until/purged_at/org_id`.
  - `minhaj_recording_access_log` — بلا IP بلا بصمات (§3.2).
  - `minhaj_recordings_audit` — نمط جداول التدقيق القائمة.
- **`RecordingsService`** ينفّذ §6: `register_from_webhook`، `download_due` (يمرّر عبر التحقّق الثلاثيّ G-1)، `delete_from_zoom_when_verified` (لا حذف قبل الثلاثة)، `purge_expired` (G-6 + G-7 · شاهد قبر)، `issue_view_url` (G-10 + G-11 + G-12)، `set_legal_hold`، `promote_to_assessment`، `quota_status` (بالأيّام لا الجيجابايت · G-3)، `for_session`.
- **`AccessListener`** يشترك في `minhaj_access_can_view_recording` بقاعدة قرار 11 (إدارة + معلّم صاحب الجلسة فقط) — يقرأ `teacher_id` من `minhaj_sessions` دون الاعتماد على وحدة Timetable.
- **`WebhookListener`** يشترك في `minhaj_zoom_event_handled` من وحدة `Meetings`؛ يستخرج `session_id/group_id/org_id` من `minhaj_meetings ⋈ sessions ⋈ groups` ويسلّم حمولة مُنقّاة من `download_url/play_url/download_token` إلى الخدمة (§7).
- **`PurgeExpiredScanner`** — `wp_schedule_event` يوميّ + مقبض `run` يستدعي `purge_expired`. مهمّة CLI `purge-expired [--dry-run]` هي شبكة الأمان.
- **`StorageClient`** — واجهة رقيقة (`put/get_bytes/delete/exists/region/presign`) بلا منطق عمل. `LocalStorageClient` تنفيذ محلّيّ للاختبار (Presign عبر HMAC-SHA256 مع صلاحيّة، مسار جذر تحت `wp-content/uploads`). المزوّد الأوروبيّ يُختار بفلتر `minhaj_recording_storage`.
- **`RecordingsZoomClient`** — واجهة (`download/delete_recording_file/quota/list_cloud_recordings`). `FakeRecordingsZoomClient` يسجّل كل مكالمة ويسمح بإعادة أحجام مختلفة عن الحقيقيّ لبرهنة G-1.
- **`RecordingAccessCheck`** — منفذ (port) صغير يعزل تبعيّة `AccessPolicy` (النهائيّة، لا يمكن مضاعفتها في الوحدات) عن `RecordingsService`. `AccessPolicyAdapter` يمرّر إلى `AccessPolicy::can_view_recording` في الإنتاج.
- **CLI** الخمسة: `purge-expired [--dry-run] [--limit=N]` · `download-due [--limit=N]` · `quota-report` (بالأيّام) · `verify --recording=` (يعيد الفحص الثلاثيّ بلا حذف) · `orphan-check [--from-days=]` (تسجيلات في Zoom بلا صفّ عندنا؛ خروج غير-صفريّ ⇒ Cron ينبّه).

### Split B — مسار Zoom (مغلَّف بالفاكة)

- `register_from_webhook` مبنيّ ومختبَر — حمولة `recording.completed` مُنقّاة قبل التخزين، صفّ لكلّ ملفّ، `pending` + `retention_until`.
- `download_due` يتطلّب `bearers` (خرائط `zoom_file_id ⇒ [download_url, download_token]`) — التنزيل يفرض الفحص الثلاثيّ: **حجم على القرص = المُعلَن**، `checksum_sha256` محسوب، ثمّ `storage->put()` + قراءة اختباريّة عند `delete_from_zoom_when_verified`.
- `delete_from_zoom_when_verified` **لا يستدعي `zoom->delete_recording_file` إلا بعد نجاح الفحص الثلاثيّ**. الاختبار AC-3 يبرهن أنّ كسر حجم واحد ⇒ صفر مكالمات حذف.
- `quota_status` يقرأ عبر `RecordingsZoomClient::quota()`؛ عند تجاوز 60٪ يُطلق `minhaj_zoom_quota_warning`، والتقرير يعرض **الأيّام المتبقّية** (G-3).
- `orphan-check` يستدعي `zoom->list_cloud_recordings($days)` ويقارن ضدّ `find_by_zoom_file` عندنا — يخرج بـ`halt(1)` عند وجود أيتام.

> **لماذا لا تلمس هذا الرمز Zoom حياً في الاختبار؟** التسجيل السحابيّ **ليس مفعَّلاً** على خطّة الحساب اليوم. تُبنى الشيفرة كاملةً ضدّ العميل المزيَّف الذي يعيد أشكالاً حقيقيّة (بشكل حمولة `recording.completed` الرسميّ من Zoom + بشكل نتيجة API الحقيقيّ للتنزيل/الحذف). عند تفعيل السحابة على الحساب، الاختبار الحيّ يحلّ محلّ الفاكة عبر فلتر `minhaj_recording_zoom_client` دون تعديل الخدمة.

---

## 2 · الملفّات المتغيّرة

**جديدة — Recordings:**
- `plugins/minhaj-core/includes/Modules/Recordings/Module.php`
- `plugins/minhaj-core/includes/Modules/Recordings/RecordingsService.php`
- `plugins/minhaj-core/includes/Modules/Recordings/WebhookListener.php`
- `plugins/minhaj-core/includes/Modules/Recordings/AccessListener.php`
- `plugins/minhaj-core/includes/Modules/Recordings/AccessPolicyAdapter.php`
- `plugins/minhaj-core/includes/Modules/Recordings/RecordingAccessCheck.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Events.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Domain/{RecordingStatus,RecordingKind,FileType,AccessAction}.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Repository/{RecordingsRepository,PersistenceException}.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Storage/{StorageClient,StorageException,LocalStorageClient}.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Zoom/{RecordingsZoomClient,RecordingsZoomException,FakeRecordingsZoomClient}.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Cron/PurgeExpiredScanner.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Cli/{PurgeExpiredCommand,DownloadDueCommand,QuotaReportCommand,VerifyCommand,OrphanCheckCommand}.php`
- `plugins/minhaj-core/includes/Modules/Recordings/Migrations/CreateRecordingsTables.php`

**معدَّلة:**
- `docs/specs/spec-recordings-v1.md` — G-17 جديد + §6.1 (سطح الإدارة) + CLI الإضافيّة (`list`, `hold`, `issue-url`).
- `plugins/minhaj-core/includes/Plugin.php` — تسجيل `RecordingsModule`.

**اختبارات جديدة:**
- `tests/Unit/Modules/Recordings/RecordingsServiceTest.php` — سبع حالات: منح/رفض/شاهد قبر لـ`issue_view_url`، رفض حذف Zoom بلا Object، رفض حذف بلا checksum، اشتراط سبب لـ`legal_hold` و`promote_to_assessment`.
- `tests/Integration/recordings-pipeline.sh` — ثمانية معايير قبول حيّة على wp-env.
- `tests/Integration/recordings-pipeline-break.sh` — كسر واستعادة أربعة حرّاس على شيفرة حيّة.

---

## 3 · معايير القبول والنتائج

### وحدات (`composer test:82`)

```
............................................................... 189 / 192 ( 98%)
...                                                             192 / 192 (100%)

Time: 00:00.245, Memory: 26.00 MB

OK (192 tests, 638 assertions)
```

### الأنبوب الحيّ (`recordings-pipeline.sh`)

```
== Reset recording + audit tables ==

== AC-1/AC-2 · register_from_webhook: retention_until stored; replay is a no-op ==
    FIRST=2 SECOND=0
  ROW status=pending retention_until=2026-09-27
  ROW status=pending retention_until=2026-09-27
  ✓ first webhook created 2 rows; replay created 0 (uq_zoom_file)
  ✓ rows land as pending with retention_until populated

== AC-3 · G-1 · size mismatch ⇒ failed, NO Zoom delete ==
  DELETED=false
  ZOOM_DELETE_CALLS=0
  STATUS=failed ERR=bytes mismatch: expected 999, got 10
  ✓ size mismatch → status=failed, zero Zoom delete calls

== AC-4 · G-1 · verified download ⇒ stored + Zoom delete ==
  AFTER_DOWNLOAD status=stored object_key=sessions/1/2026-08/FID-OK.mp4 checksum=64164443bb63
  DELETED=true
  ZOOM_DELETE_CALLS=1
  AFTER_ZOOM_DELETE status=zoom_deleted deleted_at=2026-08-28 16:23:07
  ✓ verified download → stored; Zoom delete called exactly once

== AC-5/AC-6 · purge tombstones + legal_hold skips purge ==
  PURGED=1
  PLAIN status=purged object_key= checksum=
  HOLD  status=legal_hold object_key=exp/hold
  PLAIN_ON_DISK=no HOLD_ON_DISK=yes
  ✓ plain expired row purged + tombstone kept; legal_hold row untouched

== AC-7 · issue_view_url grants / refuses / logs ==
  GRANT_TYPE=url
  REFUSE_TYPE=err:access_denied
  LOG view=1 denied=1
  ✓ grant issues URL, refusal returns access_denied, both logged (no IPs)

== AC-8 · retention_until is stored, not derived from later filter ==
  FIRST_RETENTION=2026-09-27
  SECOND_RETENTION=2036-08-25 STILL_FIRST=2026-09-27
  ✓ retention_until on existing row unchanged after filter raise (2026-09-27)

RECORDINGS PIPELINE PROOF PASSED
```

### كسر/استعادة (`recordings-pipeline-break.sh`)

```
== G-1 · triple verification blocks Zoom delete ==
  ✓ G-1 triple verify went red after breaking the guard
  ✓ G-1 triple verify restored went green again after restoring the guard

== G-6 · daily purge deletes storage AND writes tombstone ==
  ✓ G-6 purge went red after breaking the guard
  ✓ G-6 purge restored went green again after restoring the guard

== G-8 · legal_hold is excluded from purge candidates ==
  ✓ G-8 legal_hold went red after breaking the guard
  ✓ G-8 legal_hold restored went green again after restoring the guard

== G-11 · view URL only after AccessCheck says YES ==
  ✓ G-11 access check went red after breaking the guard
  ✓ G-11 access check restored went green again after restoring the guard

RECORDINGS BREAK-AND-RESTORE PASSED
```

الكسور تصيب سطر الحارس نفسه (شرط قلب إلى `false && …`، أو دالّة تُختصر لتعيد `true`/`0`)، لا سطراً محايداً حوله. الاستعادة عبر نسخ احتياطيّة بايت-لبايت لا `git checkout`، لأنّ الشيفرة لم تُثبَّت بعد وقتَ التشغيل.

**ملاحظة على «retention stored»:** ليس حارساً في سطر واحد، بل **ثابت في نموذج البيانات** (`retention_until NOT NULL` + لا تُحدَّث بعد الإدراج إلّا في `promote_to_assessment`). AC-8 هو من يبرهنها بتغيير الفلتر بعد الإدراج والتحقّق أنّ الصفّ لا يتحرّك.

---

## 4 · ما لم يُنفَّذ ولماذا

- **العميل الحقيقيّ لـZoom (`HttpRecordingsZoomClient`).** خطّة الحساب لا تسمح اليوم بالتسجيل السحابيّ (تحتاج ترقية Business/Pro مع Cloud Recording add-on)؛ الشيفرة مبنيّة ضدّ الواجهة `RecordingsZoomClient` وسيُوصَل التنفيذ الحقيقيّ عبر فلتر `minhaj_recording_zoom_client` بلا تعديل الخدمة.
- **`HttpStorageClient` أوروبيّ.** المزوّد لم يُختَر (§9.4) — استضافة أوروبيّة منفصلة قرار مؤجَّل. الواجهة جاهزة، ومفتاح الاختيار فلتر واحد.
- **زرّ «Watch recording» في صفحة الحصّة.** قرار 20 وضع سطح الإدارة في وحدة Timetable (المرحلة 3). CLI الخمسة تكفي للإدارة اليوم؛ الزرّ يُضاف حين تصل صفحة الحصّة.
- **تنبيه فوريّ عند فشل ليلة واحدة** (G-3). الحدث `minhaj_zoom_quota_warning` يُطلق، والتوصيل إلى Slack/بريد مؤجَّل إلى سبيك عمليّات لاحق (كما هو حال بقيّة تنبيهات النظام).
- **`recording.completed` من webhook حقيقيّ.** لن يصل حتى يفعَّل التسجيل السحابيّ؛ الاختبار يحرّك المسار عبر `register_from_webhook` مباشرةً بحمولات بشكل Zoom الرسميّ.

---

## 5 · الأسئلة المفتوحة (منقولة أو مُضافة)

مواصفة §9 تحمل الأسئلة الأصليّة (فيديو التقييم كمشهد جماعيّ، الأساس القانونيّ، حقّ المحو، مزوّد التخزين، التجربة التسويقيّة). المضاف من هذا التنفيذ:

- **من يوقّع سياسة النسخة الاحتياطيّة لكل فئة (G-17)؟** الفئات الثلاث — الوسائط، القاعدة، الشيفرة — تحتاج قراراً موثَّقاً قبل أوّل تسجيل حقيقيّ. اقترح: مسؤول أمن + مسؤول قانونيّ يوقّعان على SLA المزوّد + سياسة النسخ الداخليّة.
- **متى نرقّي حساب Zoom إلى Business + Cloud Recording؟** بدون ترقية، الأنبوب كلّه ينطلق ضدّ فاكة. اقترح: قبل الأسبوع الذي يسبق أوّل حصّة إنتاج.
- **`HttpStorageClient` وأيّ مزوّد؟** يرتبط بقرار الاستضافة الأوروبيّة. القرار الفنّيّ سهل بعد اختيار المزوّد (~200 سطر PHP)؛ اختياره ليس تقنيّاً.
