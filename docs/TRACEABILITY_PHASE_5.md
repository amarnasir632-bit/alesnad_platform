# تتبع المرحلة الخامسة: الحصص والرسائل

| المتطلب | التنفيذ | التحقق |
| --- | --- | --- |
| FR-017 إدارة الحصص للأستاذ المسند | `sm_create_session`, `sm_update_session`, `sm_list_sessions`; تحقق التطبيق ومحفز الترحيل من `teaching_assignments` | `tests/static_phase5_checks.py`؛ تحقق PostgreSQL متكامل غير متاح محليًا |
| FR-018 الانضمام الآمن | `sm_join_session` يتحقق من الوصول والموعد والمدة ومنحة الطالب اليدوية؛ `meeting_url` لا يعاد في القوائم | `tests/static_phase5_checks.py` |
| مزود البث | `LiveMeetingProvider` يهيئ Cloudflare Live Inputs مع التسجيل الآلي ويصدر روابط تشغيل موقعة | `tests/static_phase5_checks.py`؛ يتطلب بثًا تجريبيًا بحساب المالك للتحقق التشغيلي |
| التسجيلات الخاصة | مرجع Cloudflare Stream، الحالة `READY`، انتهاء الحصة و`available_from`، تحقق المنحة اليدوية، وتنزيل/تحويل محمي | `tests/static_phase5_checks.py`؛ تحقق PostgreSQL وتدفق HTTP يتطلبان بيئة تشغيل |
| FR-019/020/021 الرسائل الفردية | تحقق علاقة الطالب/الأستاذ عبر الصف والإسناد، قراءة `read_at`، إخفاء منفصل لكل طرف، rate limit بقفل قاعدة البيانات | `tests/static_phase5_checks.py` |
| لا مجموعات | كل الرسائل بين زوج محدد؛ لا جدول أو endpoint مجموعات | مراجعة عقد API والاختبار الساكن |

## أثر المخطط والتراجع

الترحيل `008_sessions_messaging.sql` يزيل `NOT NULL` عن `session_recordings.storage_key` ويشترط وجود `storage_key` أو `provider_recording_ref`. يضيف أعمدة الحذف المنفصل وفهارسًا، ومحفزًا يمنع إنشاء حصة دون إسناد أستاذ فعال. لا يعدل الترحيلات 001–007. التراجع اليدوي `database/rollback/008_sessions_messaging.sql` يعيد `storage_key NOT NULL`؛ سيفشل إذا وجدت تسجيلات تعتمد على مرجع مزود فقط، كما يحذف بيانات إخفاء الرسائل المنفصل.

## حدود التحقق

تغطي الاختبارات الساكنة مسارات الصلاحية والوقت والمنحة اليدوية والعقد ومراجع الترحيل. يجب تشغيل اختبارات API وقاعدة البيانات على PostgreSQL مهيأة قبل الدمج؛ اختبار كتابة الرسائل يحتاج تعيين قيم OD-23 المعتمدة دون تخمينها.
