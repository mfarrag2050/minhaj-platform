# spec-access-v1 — الرؤية والصلاحيّات

> **الحالة:** مسوّدة للتنفيذ · 28 أغسطس 2026
> **الوحدة:** `Minhaj\Access\` (في نواة الإضافة، لا في `Modules/` — لأنّها تُستدعى من كل وحدة)
> **تحجب:** `spec-zoom-sessions-v1` · `spec-attendance-v1` · `spec-recordings-v1`. لا تُبنى المرحلة 2 قبلها.

---

## 1 · الغرض

اليوم في الشيفرة قدرة واحدة: `minhaj_manage_groups` للإدارة. القدرتان `minhaj_view_group` و`minhaj_view_own_child_group` المذكورتان في `spec-groups-v1 §7` **لم تُنشأا**. النتيجة العمليّة: **المعلّم لا يرى مجموعاته، ووليّ الأمر لا يرى شيئاً**، وكل شاشة خارج لوحة الإدارة غير قابلة للبناء.

والمرحلة 2 كلّها أسئلة صلاحيّة قبل أن تكون أسئلة تكامل: من يفتح الحصّة، من يدخلها، من يشاهد التسجيل. فتُحسم هنا مرّة واحدة في **محلِّل قرار واحد** بدل أن تتناثر شروط `if` في كل شاشة — وهذا بالضبط ما يمنع IDOR الذي يحرّمه القسم الأمنيّ في `CLAUDE.md`.

## 2 · المبدأ الحاكم

> **القدرة تبوّب الفعل، والمحلِّل يبوّب الصفّ.**

قدرات ووردبريس عامّة بطبيعتها: `current_user_can('minhaj_view_group')` تقول «هذا المستخدم من صنف يرى مجموعات»، ولا تقول «يرى **هذه** المجموعة». فكل نقطة وصول تسأل سؤالين لا سؤالاً واحداً:

1. `current_user_can( $capability )` — بوّابة الصنف.
2. `Access::can_*( $user_id, $subject_id )` — بوّابة الصفّ.

**تخلّف الثانية = IDOR على بيانات قاصرين.** لا استثناء.

## 3 · القدرات

| القدرة | تُمنَح لـ | تعني |
|---|---|---|
| `minhaj_manage_groups` *(قائمة)* | `administrator` | إدارة كاملة للمجموعات والأشخاص |
| `minhaj_manage_sessions` | `administrator` | توليد الجلسات، الإلغاء، إعادة الجدولة، إدارة تراخيص Zoom |
| `minhaj_view_group` | `minhaj_teacher` | رؤية المجموعات المسنَدة إليه **هو** |
| `minhaj_view_own_child_group` | `minhaj_parent` | رؤية مجموعات أبنائه تحت وصاية سارية |
| `minhaj_record_attendance` | `minhaj_teacher` + `administrator` | تسجيل الحضور وتعديله داخل النافذة |
| `minhaj_view_recording` | `minhaj_teacher` + `administrator` | طلب رابط مشاهدة تسجيل (والصفّ يحسمه المحلِّل) |
| `minhaj_join_session` | `minhaj_teacher` + `minhaj_parent` + `minhaj_student` | طلب رابط دخول لجلسة |

تُنشأ من `Access\Capabilities::install()` وتُستدعى من `Activator::activate()` بعد `Roles::install()` القائمة. **لا تُحذف عند إلغاء التفعيل** (اتّساقاً مع سلوك الأدوار القائم).

## 4 · العلاقات التي يقرأها المحلِّل

كلها موجودة في القاعدة اليوم — لا جداول جديدة في هذه المواصفة:

| السؤال | المصدر |
|---|---|
| هل المستخدم معلّم هذه المجموعة؟ | `minhaj_groups.teacher_id` |
| هل المستخدم معلّم هذه الجلسة؟ | `minhaj_sessions.teacher_id` *(مخزَّن على الجلسة، فلا يتغيّر أثرياً بتغيير معلّم المجموعة لاحقاً)* |
| هل هذا الطالب عضو نشط في هذه المجموعة؟ | `minhaj_group_members` حيث `status='active'` |
| هل هذا المستخدم وصيّ على هذا الطالب؟ | `minhaj_guardianship` حيث `ended_at IS NULL` **و** `can_view=1` |
| هل الوصاية أساسيّة؟ | `is_primary=1` — لبعض أفعال الإدارة لا للرؤية |

## 5 · القواعد

- **A-1 — لا استعلام قائمة بلا تقاطع.** أيّ شاشة تعرض قائمة تبني `WHERE` من `Access::visible_group_ids_for( $user_id )` لا من مُدخل المستخدم. الترشيح بعد الجلب ممنوع (يسرّب العدد والصفحات).
- **A-2 — مبدأ المرآة (قرار 6).** ما يراه الطالب يراه وصيّه: `visible( student ) ⊆ visible( guardian )` دائماً. أيّ فعل يُتاح للطالب ولا يُتاح لوصيّه = خرق. تُفرَض باختبار خصائصيّ لا بمراجعة بشريّة.
- **A-3 — الفاعل غير الموضوع.** الطفل الصغير بلا حساب دخول (قرار 6)، فالوصيّ هو `actor_user_id` والطالب هو `subject_student_id`. كل دالّة في المرحلة 2 تأخذ الاثنين صراحةً. **لا `get_current_user_id()` ضمنيّ** — ينكسر في cron والـwebhooks (قاعدة قائمة في المشروع).
- **A-4 — المعلّم لا يرى وليّ الأمر.** يرى الطالب (الاسم الأوّل + حرف العائلة، كما `student_profiles` اليوم) ولا يرى هويّة الوصيّ ولا بريده ولا هاتفه. تقليل بيانات، لا تفضيل واجهة.
- **A-5 — وليّ الأمر لا يرى الطلاب الآخرين.** في مجموعة من 3–5، يرى ابنه فقط: حضوره، تقريره، جلساته. لا أسماء زملاء ولا حضورهم. *(هذه القاعدة هي التي جعلت تسجيل المجموعة محجوباً عنه في `spec-recordings-v1`.)*
- **A-6 — الرفض يُسجَّل.** كل رفض على تسجيل أو جلسة يُكتب صفّاً في تدقيق الوحدة المعنيّة بـ`action='access_denied'` مع `actor_user_id` والموضوع. الرفض الصامت لا يُكتشف عند التحقيق.
- **A-7 — الوصاية المنتهية تقطع فوراً.** `ended_at IS NOT NULL` يُسقط كل رؤية في اللحظة نفسها، بلا مهلة وبلا cache. أيّ تخزين مؤقّت لنتيجة المحلِّل يُبطَل عند `minhaj_guardianship_changed`.
- **A-9 — نطاق الجهة يعلو كل قرار.** بعد `spec-organizations-v1`، كل قرار وكل قائمة تتقاطع أولاً مع `org_ids_for( $user_id )`. طاقمنا (`minhaj_manage_groups`) بلا نطاق ويرى الجهات كلّها؛ **مسؤول الجهة يرى جهته وحدها**. تسرّب جهة إلى جهة تسريبُ بيانات أطفال إلى منافس، وهو أخطر إخفاق ممكن في هذا المحلِّل.
- **A-10 — مسؤول الجهة لا يرى وليّ الأمر.** يرى الطالب بالاسم الأوّل وحرف العائلة كما يراه المعلّم (A-4)، ولا يرى هويّة الوصيّ ولا بريده ولا هاتفه. العلاقة معنا لا معه، وقاعدة العملاء ليست جزءاً من الصفقة.
- **A-8 — الطالب المُخفى هويّته محجوب.** `student_profiles.anonymized_at IS NOT NULL` ⇒ لا رؤية لأحد غير الإدارة، ولا إصدار روابط دخول ولا تسجيلات.

## 6 · الواجهة العامّة

```php
final class Minhaj\Access\AccessPolicy {
    public function __construct( AccessRepository $repo );

    // قرارات مفردة
    public function can_view_group( int $user_id, int $group_id ): bool;
    public function can_view_student( int $user_id, int $student_id ): bool;
    public function can_view_session( int $user_id, int $session_id ): bool;
    public function can_record_attendance( int $user_id, int $session_id ): bool;
    public function can_view_recording( int $user_id, int $recording_id ): bool;

    // الدخول: يعيد الدور داخل الجلسة أو false
    // 'host' للمعلّم · 'participant' للطالب/وصيّه · false للمنع
    public function join_role( int $user_id, int $session_id, ?int $subject_student_id = null ): string|false;

    // قوائم — للاستعلامات، تفادياً لـN+1 ولتسريب العدد
    public function visible_group_ids_for( int $user_id ): array;
    public function visible_student_ids_for( int $user_id ): array;
    public function org_ids_for( int $user_id ): array;   // فارغة = بلا نطاق (طاقمنا)

    // مساعدات
    public function is_active_guardian_of( int $guardian_id, int $student_id ): bool;
    public function assert( bool $decision, string $context, int $user_id, int $subject_id ): void; // يسجّل ويرمي
}
```

`AccessRepository` قراءة فقط — لا `begin_transaction`، لا كتابة إلا صفّ التدقيق في `assert()` عند الرفض.

**فلاتر:**

| الفلتر | الغرض |
|---|---|
| `minhaj_access_decision` | `( bool $decision, string $action, int $user_id, int $subject_id )` — اعتراض واحد لكل القرارات. **يُسمح بالتشديد لا بالتخفيف**: قيمة `true` مردودة على `false` تُتجاهَل ويُسجَّل تحذير. |
| `minhaj_access_capability_map` | تبديل أسماء القدرات (اتّساقاً مع `minhaj_groups_student_role` القائم). |

## 7 · الصلاحيّات وتقليل البيانات

- المحلِّل **لا يُخزَّن قراره** في transient أو option. الحساب رخيص (استعلامان مفهرسان)، والتخزين المؤقّت على بيانات وصاية خطر A-7.
- `visible_group_ids_for` تُنفَّذ استعلاماً واحداً لكل دور، وتُخزَّن داخل الطلب الواحد فقط (`static` per-request).
- الإدارة تتجاوز المحلِّل بـ`minhaj_manage_groups` — لكن **لا تتجاوز التدقيق**: وصول الإدارة إلى تسجيل يُسجَّل كوصول عاديّ.

## 8 · معايير القبول

1. معلّم مسنَد إلى مجموعتين يرى مجموعتيه فقط؛ استدعاء `can_view_group` على مجموعة ثالثة يعيد `false` ويكتب `access_denied`.
2. وليّ أمر لطالبين في مجموعتين مختلفتين يرى المجموعتين؛ ولا يرى أسماء زملاء ابنه في أيٍّ منهما.
3. اختبار خصائصيّ لـA-2: لكل (طالب، فعل) يُتاح للطالب، يُتاح الفعل نفسه لوصيّه النشط. لا استثناء واحد.
4. إنهاء وصاية (`ended_at`) يُسقط رؤية الوصيّ **في الطلب التالي مباشرة** بلا إبطال يدويّ.
5. `anonymized_at` مضبوطاً يمنع كل رؤية غير إداريّة، ويمنع `join_role` من إعادة `'participant'`.
6. `minhaj_access_decision` يعيد `true` على قرار `false` ⇒ يبقى `false`، ويُكتب تحذير في السجلّ.
7. تمرير `user_id` لمستخدم محذوف أو غير موجود يعيد `false` ولا يرمي.
8. لا تستدعي أيّ دالّة في هذه الوحدة `get_current_user_id()` — يُفرَض بفحص `grep` في CI.

## 9 · الأسئلة المفتوحة

1. **الوصيّ الثانوي** (`is_primary=0`, `can_view=1`): يرى ويحضر — هل يفتح الحصّة للطفل؟ الافتراض المقترح: نعم، الفتح رؤية لا إدارة.
2. **المعلّم البديل** في حصّة تعويض: هل يرى تاريخ المجموعة كلّه أم الجلسة التي يغطّيها فقط؟ الافتراض المقترح: الجلسة وحدها + آخر تقريرين، وتُثبَّت في `spec-attendance-v1` عند تنفيذ `reschedule`.
3. **مراجع الجودة** (دور غير موجود بعد): يحتاج رؤية عابرة للمجموعات لأغراض التقييم. يُؤجَّل إلى المرحلة 3، لكن يُحجَز اسم القدرة `minhaj_review_quality` الآن حتى لا يُعاد ترقيم شيء.
