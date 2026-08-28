# spec-zoom-sessions-v1 — الحصّة الحيّة على Zoom

> **الحالة:** مسوّدة للتنفيذ · 28 أغسطس 2026
> **الوحدة:** `Minhaj\Modules\Meetings\`
> **تعتمد على:** `spec-access-v1` (إلزاميّ) · `spec-timetable-v1` (منفَّذ) · `spec-people-v1` (منفَّذ)
> **يعتمد عليها:** `spec-attendance-v1` · `spec-recordings-v1`

---

## 1 · الغرض والنطاق

ربط كل صفّ في `minhaj_sessions` باجتماع Zoom حقيقيّ، وإصدار روابط دخول فرديّة، وإغلاق دورة حياة الجلسة `scheduled → live → completed` من الواقع لا من التقويم.

**ملاحظة على الشيفرة القائمة:** الثوابت `SESSION_STARTED` و`SESSION_COMPLETED` موجودة في `Timetable\Events` **بلا مُطلِق**. هذه المواصفة هي مُطلِقها. الحالتان `live` و`completed` في `SessionStatus` موجودتان بلا كاتب — وهذه المواصفة كاتبهما.

**خارج النطاق:** الحضور (`spec-attendance-v1`)، التسجيلات (`spec-recordings-v1`)، التقارير (المرحلة 3).

## 2 · القرارات الحاكمة

| المصدر | القرار |
|---|---|
| قرار 2 | Zoom، والحساب ملكنا وحدنا، لا حسابات معلّمين، التسجيل السحابيّ مفعَّل |
| قرار 6 | الأب يفتح الحصّة للطفل الصغير — لا اسم دخول ولا كلمة مرور للطفل |
| قرار 7 | الحصّة 60 دقيقة (بيان على المجموعة، لا ثابت) |
| قرار 8 | لا نافذة تدريس ثابتة ⇒ **سقف التزامن يفرضه النظام** ولا يُقدَّر مسبقاً |
| 28 أغسطس | **الدخول برابط موقّع قصير العمر لكل مشارك** — لا Meeting SDK في هذه المرحلة |

**ملاحظة تصميم (لا قرار):** يُعزَل الدخول خلف واجهة `JoinStrategy` بدالّة واحدة `issue( int $session_id, int $user_id, ?int $subject_student_id ): JoinTicket`. التنفيذ الحاليّ `SignedLinkStrategy`. هذا يجعل الانتقال لاحقاً إلى Meeting SDK استبدالَ صنفٍ واحد، لا إعادةَ كتابة الحضور والتسجيلات. الكلفة الآن: واجهة وصنف. الكلفة بدونها لاحقاً: المرحلة 2 كلّها.

## 3 · نموذج البيانات

أربعة جداول جديدة، بالبادئة `{$wpdb->prefix}` ومحرّك InnoDB، اتّساقاً مع القائم: بلا `FOREIGN KEY`، والتفرّد وحده يُفرَض في القاعدة.

### 3.1 `minhaj_zoom_licenses`

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `zoom_user_id` | VARCHAR(64) NOT NULL | **UNIQUE `uq_zoom_user`** |
| `email` | VARCHAR(190) NOT NULL | حساب المضيف المرخَّص |
| `concurrent_capacity` | TINYINT UNSIGNED NOT NULL DEFAULT 2 | Zoom Business: اجتماعان متزامنان لكل مرخَّص |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'active' | `active` / `disabled` — KEY |
| `created_at` / `updated_at` | DATETIME NOT NULL | |

### 3.2 `minhaj_session_meetings`

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `session_id` | BIGINT UNSIGNED NOT NULL | **UNIQUE `uq_session`** — اجتماع واحد لكل جلسة، يفرضه القاعدة |
| `license_id` | BIGINT UNSIGNED NOT NULL | KEY — المضيف المسنَد |
| `zoom_meeting_id` | VARCHAR(32) NOT NULL | KEY |
| `zoom_meeting_uuid` | VARCHAR(64) NULL | يتغيّر لكل انعقاد؛ يُملأ من `meeting.started` |
| `state` | VARCHAR(20) NOT NULL DEFAULT 'pending' | `pending` → `created` → `started` → `ended` / `failed` / `revoked` — KEY |
| `scheduled_start_utc` | DATETIME NOT NULL | نسخة من الجلسة وقت الإنشاء (لكشف الانحراف بعد إعادة جدولة) |
| `duration_minutes` | SMALLINT UNSIGNED NOT NULL | |
| `create_attempts` | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| `last_error` | VARCHAR(255) NULL | |
| `created_at` / `updated_at` | DATETIME NOT NULL | |

> **لا يُخزَّن `start_url` ولا `join_url` ولا كلمة مرور الاجتماع في القاعدة.** كلّها حاملات وصول (bearer). تُجلَب من Zoom لحظة الحاجة وتُستهلك في إعادة توجيه 302، ولا تُطبع في HTML ولا في سجلّ.

### 3.3 `minhaj_session_participants`

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `session_id` | BIGINT UNSIGNED NOT NULL | KEY |
| `actor_user_id` | BIGINT UNSIGNED NOT NULL | من ضغط الزرّ فعلاً (وليّ الأمر أو المعلّم أو الطالب البالغ) |
| `subject_student_id` | BIGINT UNSIGNED NULL | الطالب الذي يمثّله الدخول؛ NULL للمعلّم |
| `role` | VARCHAR(20) NOT NULL | `host` / `participant` |
| `zoom_registrant_id` | VARCHAR(64) NULL | KEY — مفتاح الربط مع أحداث الحضور |
| `zoom_participant_uuid` | VARCHAR(64) NULL | يُملأ من webhook الانضمام |
| `issued_at` / `expires_at` | DATETIME NOT NULL | صلاحيّة التذكرة |
| `consumed_at` | DATETIME NULL | |
| **UNIQUE `uq_session_subject`** | `(session_id, subject_student_id)` | مسجَّل واحد لكل طالب في الجلسة |
| **UNIQUE `uq_session_host`** | `(session_id, active_host_flag)` | انظر أدناه |

**عمود مولَّد** — اتّساقاً مع مبدأ «القواعد في القاعدة»:

```sql
active_host_flag TINYINT UNSIGNED
  GENERATED ALWAYS AS ( IF( role = 'host', 1, NULL ) ) STORED
```

فيمنع القاعدةُ مضيفَين لجلسة واحدة، بلا صيانة من طبقة الخدمة.

### 3.4 `minhaj_zoom_events`

جدول الاستقبال — **الحاجز الوحيد بين شبكة Zoom وحالتنا**:

| العمود | النوع | ملاحظة |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `dedup_key` | VARCHAR(191) NOT NULL | **UNIQUE `uq_dedup`** — `event_type` + `payload.object.uuid` + `event_ts` |
| `event_type` | VARCHAR(64) NOT NULL | KEY |
| `payload_json` | LONGTEXT NOT NULL | |
| `received_at` | DATETIME NOT NULL | KEY |
| `processed_at` | DATETIME NULL | |
| `status` | VARCHAR(20) NOT NULL DEFAULT 'received' | `received` / `processed` / `ignored` / `failed` — KEY |
| `attempts` | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| `last_error` | VARCHAR(255) NULL | |

### 3.5 `minhaj_meetings_audit`

`id` PK · `session_id` NULL (KEY) · `actor_user_id` (KEY) · `action` VARCHAR(64) · `subject_id` NULL · `payload_json` LONGTEXT NULL · `created_at` DATETIME (KEY). نمط جداول التدقيق القائمة حرفيّاً.

## 4 · دورة حياة الاجتماع

```
pending ──(create_meetings cron, T-24h)──▶ created
created ──(webhook meeting.started)──────▶ started ──(webhook meeting.ended)──▶ ended
created ──(الجلسة أُلغيت)────────────────▶ revoked   (يُحذف الاجتماع من Zoom)
pending/created ──(فشل متكرّر)───────────▶ failed    (تنبيه إداريّ)
```

وبالتوازي، حالة **الجلسة** في `minhaj_sessions`:

```
scheduled ──(meeting.started)──▶ live ──(meeting.ended)──▶ completed
scheduled ──(لم يدخل المعلّم خلال العتبة)──▶ no_show   ← يكتبها spec-attendance-v1
```

`SessionStatus::TRANSITIONS` القائمة يجب أن تسمح بـ`scheduled → live → completed` و`scheduled → no_show`. **يُتحقَّق من ذلك قبل البناء**؛ إن لم تسمح فترحيل تعديل واحد على `Domain/SessionStatus`.

## 5 · القواعد

### 5.1 التوقيت والإنشاء

- **M-1 — الاجتماع يُنشأ متأخّراً لا مبكّراً.** cron كل ساعة ينشئ اجتماعات الجلسات التي تبدأ خلال **24 ساعة** (قابل للضبط). توليد 12 أسبوعاً مقدّماً يعني ~4,900 اجتماعاً معلّقاً في Zoom تتقادم مع كل إعادة جدولة — ضجيج وهشاشة بلا مقابل.
- **M-2 — إعادة الجدولة تُبطل الاجتماع.** عند `minhaj_session_rescheduled` أو `minhaj_session_cancelled`: يُحذف اجتماع Zoom وتصير الحالة `revoked`، ويُنشأ جديد إن عاد الموعد داخل نافذة الـ24 ساعة. مقارنة `scheduled_start_utc` المخزَّنة على الاجتماع بالجلسة تكشف أيّ انحراف صامت — فحص ليليّ يُبلّغ عنه.
- **M-3 — الاجتماع يُنشأ بـ`auto_recording: "cloud"`** (قرار 2) و`waiting_room: true` و`join_before_host: false` و`approval_type: 1` (تسجيل مسبق مطلوب) و`meeting_authentication: false` (الأطفال بلا حسابات Zoom). التسجيل ليس خياراً للمعلّم.
- **M-4 — لا اجتماع متكرّر (recurring).** كل جلسة اجتماع مستقلّ. الاجتماع المتكرّر يربط الحضور والتسجيلات بسلسلة واحدة ويخلط انعقادات مختلفة — ونحن نلغي ونعوّض ونعيد الجدولة باستمرار.

### 5.2 سقف التزامن *(قرار 8)*

- **M-5 — سقف مضبوط إدارياً.** إعداد `minhaj_max_concurrent_sessions`، قيمته الافتراضيّة = مجموع `concurrent_capacity` للتراخيص النشطة. **لا يُقدَّر مسبقاً — يُقاس ويُرفَع.**
- **M-6 — التوليد يرفض تجاوز السقف.** `TimetableService::generate_for_group` تستدعي `MeetingsService::assert_concurrency_within_cap( $candidate_slots )` قبل الإدراج. التنفيذ **يعيد استعمال النمط القائم حرفيّاً**: معاملة + `SELECT ... FOR UPDATE` على نافذة التداخل (كما `lock_teacher_sessions_between`) ثم عدّ الجلسات المتداخلة. لا قيد قاعدة هنا — تداخل الفترات لا يُفرَض بـUNIQUE.
- **M-7 — تحذير قبل السقف.** تجاوز **80٪** من السقف في أيّ نافذة يكتب تنبيهاً إداريّاً ولا يمنع. الوصول للسقف يمنع برسالة مفهومة عبر `ErrorMap` (لا رمز خام) تقترح النافذة المتاحة الأقرب.
- **M-8 — إسناد المضيف عند الإنشاء لا عند التوليد.** الترخيص يُختار وقت M-1 من التراخيص النشطة الأقلّ حملاً في تلك النافذة. ترخيص عُطِّل ⇒ اجتماعاته المعلّقة تُعاد إلى `pending` وتُسنَد من جديد.

### 5.3 الدخول

- **M-9 — لا رابط عامّ إطلاقاً.** كل داخل مسجَّل عبر Zoom Registrant API باسم يوضع من عندنا: `«الاسم الأوّل + حرف العائلة»` **من `minhaj_students`** (بعد قرار 18 صار جدول الطلاب هذا لا `minhaj_student_profiles`). تقليل بيانات — لا اسم كامل في نظام طرف ثالث.
- **M-10 — التذكرة قصيرة العمر ومرّة واحدة.** تُصدَر عند الضغط، صلاحيّتها **15 دقيقة**، وتُستهلك بإعادة توجيه 302 إلى Zoom. `join_url` لا يظهر في DOM ولا في `href` ولا في سجلّ الخادم.
- **M-11 — نافذة الظهور.** زرّ «ادخل الحصّة» يُفعَّل من **15 دقيقة قبل** الموعد حتى **نهاية الحصّة + 15 دقيقة**. خارجها يُرفض الإصدار.
- **M-12 — الفاعل والموضوع.** إصدار التذكرة يمرّ بـ`AccessPolicy::join_role( $user_id, $session_id, $subject_student_id )` (spec-access §6). وليّ الأمر الذي يفتح لطفله: `actor_user_id` = الوصيّ (WP user)، `subject_student_id` = **`minhaj_students.id`** بعد قرار 18 (لا `wp_users.ID` كما كان)، `role` = `participant`. **`uq_session_subject(session_id, subject_student_id)`** يفرض في القاعدة أنّ الطالب لا يُسجَّل مرّتين للجلسة نفسها، وهذا المفتاح الأجنبيّ (المنطقيّ) يشير إلى جدول الطلاب لا إلى `wp_users`. هذا هو قرار 6 مطبَّقاً في صفّ قاعدة بيانات.
- **M-13 — مضيف واحد.** المعلّم المسنَد للجلسة فقط يأخذ `role='host'` (يفرضه العمود المولَّد §3.3). الإدارة تدخل بـ`participant` ما لم تُسنَد صراحةً بديلاً.

### 5.4 الأمن والتكامل

- **M-14 — Server-to-Server OAuth.** `MINHAJ_ZOOM_ACCOUNT_ID` و`_CLIENT_ID` و`_CLIENT_SECRET` و`_WEBHOOK_SECRET` **ثوابت في `wp-config.php` فقط** — لا في القاعدة، لا في المستودع، لا في `option`. الرمز المؤقّت في transient بانتهاء أقصر من انتهائه الحقيقيّ بخمس دقائق.
- **M-15 — كل webhook موقَّع أو مرفوض.** التحقّق من `x-zm-signature` بـHMAC-SHA256 على `v0:{timestamp}:{body}` بمقارنة `hash_equals`، ورفض ما تجاوز طابعه الزمنيّ **5 دقائق**. دعم تحدّي `endpoint.url_validation`. الرفض يعيد 401 ولا يكتب صفّاً.
- **M-16 — الاستقبال يفصل عن المعالجة.** نقطة الاستقبال تتحقّق، تُدرج صفّاً في `minhaj_zoom_events`، وتعيد **200 خلال 3 ثوانٍ**. المعالجة في cron/`wp-cron` مستقلّ. Zoom يعطّل نقاط النهاية البطيئة أو الراجعة بخطأ.
- **M-17 — التكرار غير مؤذٍ (idempotent).** القيد `uq_dedup` يمنع معالجة الحدث مرّتين على مستوى القاعدة. Zoom يعيد الإرسال حتى ثلاث مرّات — والمعالجة المزدوجة تعني حضوراً مضاعفاً وتقريرين لوليّ الأمر.
- **M-18 — الحدث المتأخّر لا يُرجِع الحالة.** `meeting.started` واصلاً بعد `meeting.ended` يُهمَل (`status='ignored'`) ولا ينقل الجلسة من `completed` إلى `live`. الترتيب يُحسم بـ`event_ts` لا بوقت الوصول.
- **M-19 — الأحداث بعد COMMIT.** كل `do_action` بعد `commit()` وخارج `try` — القاعدة القائمة في المشروع، بلا استثناء لهذه الوحدة.
- **M-20 — نافذة تسامح إعادة الانضمام** *(قرار Muhammed، 28 أغسطس 2026)*. انقطاع المعلّم يجعل Zoom يطلق `meeting.ended` ثم `meeting.started` بـ`uuid` جديد. الانعقاد الثاني الذي يبدأ خلال **`minhaj_session_rejoin_grace_minutes`** (افتراض **10 دقائق**، **ديناميكيّ تضبطه الإدارة** رفعاً أو خفضاً) **يُعامَل امتداداً للجلسة نفسها لا جلسةً جديدة**: الجلسة تعود إلى `live`، ولا يُطلق `minhaj_session_completed` إلا عند الانتهاء الأخير، و`zoom_meeting_uuid` يُلحَق بقائمة انعقادات لا يُستبدَل. تجاوز النافذة ⇒ الجلسة `completed` نهائيّاً وما بعده انعقاد غير مرتبط يُبلَّغ للإدارة. **العتبة إعداد لا ثابت** — شبكات المعلّمين تختلف بين الأسواق.
- **M-21 — بُعد الجهة.** `minhaj_sessions.org_id` (`spec-organizations-v1 §3.5`) يُنسخ على صفّ الاجتماع، وسقف التزامن يُقاس **إجمالاً وبحسب الجهة معاً**: جهة واحدة لا تبتلع كل التراخيص. الإعداد `minhaj_max_concurrent_sessions_per_org` افتراضه = السقف العامّ (أي بلا تقييد) حتى تُضبَط قيمة أدنى.

- **M-22 — إعدادات الحماية مقفَلة على مستوى الحساب** *(قرار 17)*. Zoom يُتيح مسؤولَ الحساب أن يفتح ما نغلقه على مستوى الاجتماع في أيّ لحظة — والضابط الذي يقول «لا دردشة خاصّة» لا يعمل إلا إذا كان الحساب نفسه لا يسمح بها. **الإعدادات الملزَمة**:

    | الإعداد | القيمة المطلوبة | لماذا |
    |---|---|---|
    | `in_meeting.private_chat` | `false` | **الأخطر**: بالغ يكتب لطفل خارج نظر الأب والمعلّم |
    | `in_meeting.chat` | `true` (عامّ) | الدردشة العامّة تبقى — تعليم وسؤال |
    | `in_meeting.screen_sharing` (participants) | `disabled` | مشاركة الشاشة من المشارك = صورة قد تخرج |
    | `in_meeting.file_transfer` | `false` | نقل ملفّات = خرق تحكّم بيانات |
    | `recording.local_recording` | `false` | التسجيل السحابيّ فقط (قرار 2) — محلّيّ = نسخة خارج حكمنا |
    | `in_meeting.allow_participants_to_rename_themselves` | `false` | الاسم الذي أرسلناه هو الاسم — لا انتحال |
    | `in_meeting.waiting_room` | `true` | لا دخول بلا سماح المضيف |

    **يُفحَص دوريّاً**: أمر `wp minhaj meetings security-settings-check` يعمل يوميّاً عبر `wp-cron`، يجلب `account settings` من Zoom API، يقارن بالقيم الملزَمة، ويطلق `minhaj_zoom_security_drift` على كل انحراف — مع كتابة صفّ تدقيق في `minhaj_meetings_audit`. **الضابط الذي لا يُفحَص ينحلّ بصمت** — مسؤول Zoom قد يقلّب مفتاحاً بلا أن يعرف أنّه يمسّ سلامة أطفال.

## 6 · الواجهة العامّة

```php
final class Minhaj\Modules\Meetings\MeetingsService {
    public function __construct( MeetingsRepository $repo, ZoomClient $zoom, AccessPolicy $access );

    public function create_meeting_for_session( int $actor_user_id, int $session_id ): int;
    public function revoke_meeting_for_session( int $actor_user_id, int $session_id, string $reason ): void;
    public function issue_join_ticket( int $actor_user_id, int $session_id, ?int $subject_student_id = null ): JoinTicket;
    public function assert_concurrency_within_cap( array $candidate_slots ): void; // يرمي RuleViolationException
    public function concurrency_at( string $from_utc, string $to_utc ): int;
    public function assign_license( int $session_id, string $start_utc, string $end_utc ): int;
    public function ingest_webhook( string $raw_body, array $headers ): int;   // يعيد event id
    public function process_pending_events( int $limit = 100 ): int;           // يعيد عدد المعالَج
}
```

`ZoomClient` غلاف رقيق حول `wp_remote_*`: `create_meeting`, `delete_meeting`, `add_registrant`, `get_meeting`, `list_recordings`, `delete_recording`. **بلا منطق عمل** — ليُختبر ما فوقه بمضاعف اختبار.

**الأحداث (بعد COMMIT):**

| الحدث | متى |
|---|---|
| `minhaj_session_started` *(ثابت قائم — أوّل مُطلِق له)* | `meeting.started` |
| `minhaj_session_completed` *(ثابت قائم — أوّل مُطلِق له)* | `meeting.ended` |
| `minhaj_meeting_created` | نجاح الإنشاء |
| `minhaj_meeting_failed` | فشل بعد آخر محاولة |
| `minhaj_meeting_revoked` | حذف من Zoom |
| `minhaj_join_ticket_issued` | إصدار تذكرة |
| `minhaj_concurrency_threshold_reached` | تجاوز 80٪ من السقف |

**الفلاتر:** `minhaj_meeting_settings` (تعديل حمولة الإنشاء) · `minhaj_meeting_lead_hours` (افتراض 24) · `minhaj_join_window_minutes` (افتراض 15) · `minhaj_max_concurrent_sessions`.

**WP-CLI:** `wp minhaj meetings create-due` · `wp minhaj meetings process-events` · `wp minhaj meetings concurrency-report --from= --to=` · `wp minhaj meetings drift-check`.

## 7 · الصلاحيّات وتقليل البيانات

- الإنشاء والإلغاء وإدارة التراخيص: `minhaj_manage_sessions`.
- إصدار تذكرة: `minhaj_join_session` **+** `AccessPolicy::join_role` ≠ `false`. القدرة وحدها لا تكفي (spec-access §2).
- ما يصل Zoom عن الطفل: الاسم الأوّل وحرف العائلة فقط. **لا بريد الطفل، لا تاريخ ميلاد، لا اسم عائلة كامل.** بريد التسجيل هو بريد **وليّ الأمر** حين يلزم.
- `payload_json` في `minhaj_zoom_events` يُنقّى قبل التخزين: تُحذف حقول `join_url`, `start_url`, `password`, `h323_password`. الحمولة الخام قد تحوي حاملات وصول.

## 8 · معايير القبول

1. جلسة تبدأ بعد 20 ساعة ⇒ `create-due` ينشئ اجتماعاً واحداً بـ`auto_recording=cloud`. تشغيله ثانيةً لا ينشئ ثانياً (`uq_session`).
2. محاولة إدراج مشارك ثانٍ بـ`role='host'` لنفس الجلسة ⇒ خطأ قاعدة، لا نجاح صامت.
3. webhook بتوقيع خاطئ ⇒ 401 وصفر صفوف. بتوقيع صحيح ⇒ 200 خلال 3 ثوانٍ وصفّ واحد في `minhaj_zoom_events`.
4. إرسال الحدث نفسه ثلاث مرّات ⇒ صفّ واحد، معالجة واحدة، `do_action` واحد.
5. `meeting.started` بعد `meeting.ended` ⇒ الجلسة تبقى `completed`، والحدث `ignored`.
6. السقف 4 وأربع جلسات متداخلة قائمة ⇒ توليد خامسة متداخلة يفشل بـ`RuleViolationException` برسالة مترجَمة تقترح نافذة بديلة.
7. عشرون طلب توليد متزامناً على السقف نفسه ⇒ لا يتجاوز العدد النهائيّ السقف (اختبار تزامن بنمط `groups-concurrency.sh` القائم).
8. وليّ أمر يطلب تذكرة لطفل ليس تحت وصايته ⇒ رفض + صفّ `access_denied`، ولا استدعاء لـZoom.
9. طلب تذكرة قبل الموعد بساعتين ⇒ رفض. قبله بعشر دقائق ⇒ نجاح، والاستجابة 302، و`join_url` غير موجود في جسم الاستجابة.
10. إلغاء جلسة لها اجتماع ⇒ حذف من Zoom والحالة `revoked` خلال دورة cron واحدة.
11. `grep` على المستودع لا يجد `start_url` ولا `join_url` في أيّ استعلام إدراج أو تحديث.

## 9 · الأسئلة المفتوحة

1. **المعلّم في بلد يُحجب فيه Zoom.** ستّون معلّماً موزّعون، والتغطية عالميّة الآن. يحتاج فحصاً قبل إسناد أوّل مجموعة.
2. **انقطاع المعلّم أثناء الحصّة** ⇒ Zoom يطلق `meeting.ended` ثم `meeting.started` بـ`uuid` جديد. الافتراض المقترح: نافذة تسامح 10 دقائق يُعامَل فيها الانعقاد الثاني امتداداً لا جلسة جديدة. **يحتاج قراراً قبل التنفيذ لأنّه يمسّ الحضور والتسجيل معاً.**
3. **الحدّ الأقصى لطلبات Zoom API.** ~4,900 اجتماعاً و~2,000 مسجَّل شهرياً ضمن الحدود المعلنة، لكن ذروة `create-due` تتكدّس في ساعة واحدة. يُقترح توزيع الإنشاء على دفعات وحدّ معدّل داخليّ.
4. **`minhaj_groups.timezone` حقل ميّت** — لا يقرؤه التوليد (يقرأ `anchor_timezone` من النمط). إمّا يُعرَّف صراحةً «منطقة عرض المجموعة لوليّ الأمر» أو يُحذف. تركه بلا تعريف يستدعي استعمالاً خاطئاً.
5. **`session_duration_minutes` افتراضه 0** في `minhaj_groups`. بعد قرار 7 ينبغي أن يكون 60 — مجموعة تُنشأ بـ0 تولّد جلسات صفريّة المدّة. ترحيل صغير + تحقّق `> 0` قبل التوليد.
