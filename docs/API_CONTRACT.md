# عقد واجهة API

الجذر `/api/v1/`. كل الاستجابات JSON بترميز UTF-8، وتستخدم الأخطاء الشكل `{ "error": { "code": "...", "message": "..." } }`. يتطلب كل `POST` و`PUT` و`PATCH` و`DELETE` جلسة متصفح ورمز CSRF؛ لا تستخدم الواجهة تخزينًا محليًا لرموز الدخول.

## الجلسة وCSRF

### `GET /api/v1/csrf`

لا يتطلب تسجيل الدخول. ينشئ/يعيد رمز CSRF للجلسة.

استجابة `200`:

```json
{"data":{"csrf_token":"<opaque-token>"}}
```

أرسل الرمز في `X-CSRF-Token` مع الطلبات المغيرة. تقبل الخدمة أيضًا `_csrf` في JSON. غياب الرمز أو عدم مطابقته يعيد `419 csrf_failed`.

### `GET /api/v1/auth/me`

يتطلب جلسة مستخدم نشطة. يعيد `401 unauthenticated` إذا انتهت الجلسة أو عُطل الحساب. يعيد `200 {"data":{"user":{"id":1,"full_name":"...","email":"...","is_active":true,"roles":["student"]}}}`. يعاد التحقق من الحساب والأدوار من PostgreSQL في كل طلب.

## المصادقة

### `POST /api/v1/auth/login`

CSRF مطلوب، دون دور مسبق.

المدخلات: `email` نص بريد و`password` نص. الاستجابة `200` تتضمن المستخدم ورمز CSRF المجدد. الأخطاء: `419 csrf_failed`, `422 validation_failed`, `401 invalid_credentials`, `429 login_rate_limited` عند تجاوز الحد المهيأ. لا تكشف الرسالة ما إذا كان البريد مسجلًا.

### `POST /api/v1/auth/register`

CSRF مطلوب، ينشئ طالبًا فقط. المدخلات: `full_name` (2–150 محرفًا)، `email`، `phone` اختياري، `password` (12 محرفًا على الأقل و72 بايت كحد أعلى). الاستجابة `201` تتضمن بيانات المستخدم ورمز CSRF. الأخطاء `409 email_exists`, `422 validation_failed`, `419 csrf_failed`.

### `POST /api/v1/auth/logout`

CSRF مطلوب. ينهي جلسة المستخدم؛ استجابة `200 {"data":{"logged_out":true}}`.

## إدارة الحسابات

تتطلب العمليات صلاحية من قاعدة البيانات في الخادم، وليس اعتمادًا على الدور المخبأ في الجلسة. CSRF مطلوب للعمليات المغيرة.

### `GET /api/v1/admin/users?limit=50&offset=0`

الصلاحية `users.manage`. يعيد `200 {"data":[{"id":1,"full_name":"...","email":"...","phone":null,"is_active":true,"roles":["teacher"],"created_at":"..."}]}`. الحد `limit` بين 1 و100، و`offset` صفر أو أكثر.

### `GET /api/v1/admin/roles`

الصلاحية `roles.manage`. يعيد `200 {"data":{"roles":[{"code":"teacher","display_name":"أستاذ","description":"..."}]}}` لاستخدام رموز الأدوار المعتمدة في إنشاء الحساب وتغيير أدواره.

### `POST /api/v1/admin/users`

يتطلب `users.manage` و`roles.manage`. المدخلات: `full_name`, `email`, `password`, `roles` مصفوفة رموز أدوار معرفة، و`phone` اختياري. يعيد `201 {"data":{"user":{...}}}`. ينشئ ملف `employees` لأدوار `teacher`, `content_reviewer`, `admin` وملف `students` لدور `student` ضمن معاملة واحدة. الأخطاء `403 forbidden`, `409 account_conflict`, `422 validation_failed`, `419 csrf_failed`.

### `PATCH /api/v1/admin/users/{id}`

الصلاحية `users.manage`. يقبل واحدًا أو أكثر من `full_name`, `email`, `phone`, `password`, `is_active`. يجب أن تكون `is_active` قيمة JSON منطقية. تغيير الحساب وسجل التدقيق معاملة واحدة. يعيد `200 {"data":{"user":{...}}}`، أو `403`, `404 not_found`, `409 account_conflict` للبريد المكرر، `409 last_admin`, `422`, `419`.

### `PUT /api/v1/admin/users/{id}/roles`

الصلاحية `roles.manage`. المدخل `{ "roles": ["teacher"] }`. يعيد الحساب بأدواره بعد التحديث الذري. يمنع إزالة/تعطيل آخر مدير نظام نشط (`409 last_admin`). الأخطاء الأخرى `403`, `404`, `422`, `419`.

## قواعد عامة

- `Content-Type: application/json` مطلوب لطلبات JSON؛ JSON غير الصحيح يعيد `400 invalid_json` والنوع الخطأ `415 content_type_required`. نقاط رفع الملفات تقبل `multipart/form-data` كما هو موضح أعلاه.
- جميع مدخلات SQL مربوطة عبر PDO prepared statements. رسائل الخادم لا تعرض تفاصيل استثناء قاعدة البيانات.
- أخطاء غير متوقعة تعيد `500 internal_error` مع تسجيل التفاصيل في سجل الخادم.
- الحصص المباشرة والرسائل وبوابة الدفع ما زالت خارج التنفيذ في هذه المرحلة. الذكاء الاصطناعي والألعاب وبوابة ولي الأمر وتطبيق الجوال خارج نطاق الإصدار الحالي.

## الترحيل والتراجع

`004_auth_security.sql` يضيف `auth_login_attempts` وفهرسًا جزئيًا لتخزين عداد محاولات الدخول وبصمة البريد ووقت القفل وآخر عنوان IP؛ لا يغير 001–003. التراجع قبل استخدام بياناته: إيقاف تطبيق الويب ثم تنفيذ `DROP TABLE auth_login_attempts;` على قاعدة البيئة المتأثرة (يزيل سجل محاولات الدخول فقط). بعد تطبيق الترحيل على بيئة مشتركة، لا تنفذ التراجع دون موافقة مالك البيئة لأن بيانات القفل ستفقد.

`005_academic_management.sql` يضيف صلاحية `teaching_assignments.manage` ويربطها بدور `admin` فقط، دون بيانات مراحل أو صفوف. للتراجع على بيئة غير مشتركة، أوقف التطبيق ثم نفذ `DELETE FROM role_permissions WHERE permission_id = (SELECT id FROM permissions WHERE code = 'teaching_assignments.manage');` ثم `DELETE FROM permissions WHERE code = 'teaching_assignments.manage';`. راجع أي إسناد للصلاحية إلى أدوار أخرى قبل التراجع في البيئات المشتركة.

## الهيكل الدراسي والبحث (المرحلة الثانية)

كل طلبات القراءة أدناه تستخدم جلسة المنصة. تتطلب عمليات `POST` و`PATCH` و`DELETE` رمز CSRF عبر `X-CSRF-Token`. لا يقبل الخادم صلاحية قادمة من العميل؛ يتحقق من الصلاحية الحالية في قاعدة البيانات.

### قراءة التصنيفات

- `GET /api/v1/academics/stages` يعيد المراحل النشطة فقط في `data.stages`، بما فيها المرحلة المتوسطة دون إنشاء صفوف لها.
- `GET /api/v1/academics/classes?stage_id={id}` يعيد الصفوف النشطة، اختياريًا لمرحلة محددة، في `data.classes`.
- `GET /api/v1/academics/subjects?class_id={id}` يعيد المواد النشطة وربطها النشط بالصف في `data.subjects`.
- يمكن إضافة `include_inactive=true` لأي من هذه المسارات لحساب يملك `academics.manage` فقط. القيمة الافتراضية `false`.
- `GET /api/v1/academics/lessons?stage_id={id}&class_id={id}&subject_id={id}&class_subject_id={id}` يعيد عناوين الدروس ووصفها وتصنيفها وترتيبها. المستخدم ذو `academics.manage` يرى المسودات؛ المستخدم الآخر يحتاج `content.read` ولا يرى إلا الدروس المنشورة. لا يعيد هذا المسار نص الدرس الكامل.

### إدارة التصنيفات والدروس

تتطلب كل المسارات التالية `academics.manage`:

- `POST /api/v1/admin/academics/stages`: `{ "code": "...", "name": "...", "description": "...", "sort_order": 0 }`.
- `PATCH /api/v1/admin/academics/stages/{id}`: يقبل `name`, `description`, `sort_order`, `is_active`.
- `POST /api/v1/admin/academics/classes`: `{ "stage_id": 1, "code": "...", "name": "...", "sort_order": 0 }`.
- `PATCH /api/v1/admin/academics/classes/{id}`: يقبل `name`, `code`, `sort_order`, `is_active`.
- `POST /api/v1/admin/academics/subjects`: `{ "code": "...", "name": "...", "description": "..." }`، و`code` اختياري.
- `PATCH /api/v1/admin/academics/subjects/{id}`: يقبل `code`, `name`, `description`, `is_active`.
- `POST /api/v1/admin/academics/class-subjects`: `{ "class_id": 1, "subject_id": 1 }` لإنشاء الربط.
- `PATCH /api/v1/admin/academics/class-subjects/{id}`: يقبل `is_active` لتفعيل الربط أو تعطيله دون حذف بيانات مرتبطة.
- `POST /api/v1/admin/academics/lessons`: `{ "class_subject_id": 1, "title": "...", "description": "...", "body": "...", "position": 1 }`. يكون الدرس مسودة افتراضيًا. النشر عند الإنشاء يحتاج `content.publish`.
- `PATCH /api/v1/admin/academics/lessons/{id}`: يقبل `class_subject_id`, `title`, `description`, `body`, `position`, `is_published`. تعديل حالة النشر يحتاج أيضًا `content.publish`.

تعيد عمليات الإنشاء `201 {"data":{"item":{...}}}` والتعديل `200` بالبنية نفسها. الأكواد الممكنة: `403 forbidden`, `404 not_found`, `409 academic_conflict` لتكرار الرمز/الاسم/الربط/ترتيب الدرس، `422 validation_failed` أو `invalid_reference`, و`419 csrf_failed`. التعطيل يحافظ على السجلات المرتبطة؛ لا يوجد حذف صلب للتصنيفات.

### إسناد الأساتذة

- `POST /api/v1/admin/teaching-assignments` يتطلب `teaching_assignments.manage` وCSRF. المدخل: `{ "employee_id": 1, "class_subject_id": 1 }`. يجب أن يكون الموظف نشطًا وله دور `teacher` وأن يكون ربط الصف والمادة نشطًا. يعيد `200 {"data":{"assigned":true}}`؛ التكرار آمن ولا يكرر الإسناد.
- `DELETE /api/v1/admin/teaching-assignments/{employee_id}/{class_subject_id}` يتطلب الصلاحية نفسها وCSRF؛ يعيد `200 {"data":{"unassigned":true}}` أو `404 not_found`.
- `GET /api/v1/academics/my-teaching-assignments` يتطلب دور `teacher` ويعيد إسنادات الأستاذ الحالي فقط في `data.assignments`.
- يسجل النظام الإنشاء والتعديل والإسناد وإلغاءه في `audit_events` ضمن المعاملة ذاتها.

### البحث

`GET /api/v1/content/search?q={text}&stage_id={id}&class_id={id}&class_subject_id={id}&subject_id={id}&lesson_id={id}&limit=25&offset=0` يتطلب جلسة وصلاحية `content.read`. يعيد الموارد والامتحانات السابقة المعتمدة فقط دون مفاتيح التخزين أو روابط التنزيل. البحث بالمرحلة والصف والمادة والدرس يطبق على الموارد؛ الامتحانات السابقة تتبع الصف والمادة. الحد `limit` من 1 إلى 100.

تمثل أسماء المراحل والصفوف والمواد بيانات قابلة للإدارة. لا يزرع النظام صفوفًا للمرحلة المتوسطة لأن أسمائها غير معتمدة بعد (OD-19).

## المحتوى والملفات والأسئلة (المرحلة الثالثة)

طلبات الرفع تستخدم `multipart/form-data` مع حقل ملف اسمه `file` ورمز CSRF في `X-CSRF-Token`. الأنواع تُفحص بمكتبة PHP Fileinfo. الحد الأقصى إلزامي في `UPLOAD_MAX_BYTES`؛ غيابه يعيد `503 upload_not_configured`. الملفات تحفظ خارج `public/` في `PRIVATE_STORAGE_PATH`، ومفتاح التخزين عشوائي ولا يظهر في استجابات API. توافق حد PHP/web server مع الحد المعتمد مطلوب (OD-14).

### رفع وتحرير المحتوى

- `POST /api/v1/content/resources` يتطلب `content.upload` وملفًا، ويقبل `title`, `resource_type` (`PDF|VIDEO|AUDIO|DOCUMENT`), `class_subject_id`, `lesson_id` اختياري، `description`, `source_reference`, `source_owner`, `copyright_license`. يعود 201 بمورد في حالة `DRAFT`.
- `POST /api/v1/content/past-exams` يتطلب `content.upload` وملفًا، ويقبل `title`, `class_subject_id`, `academic_year` اختياري وحقول المصدر والمالك والترخيص. يعود 201 في `DRAFT`.
- `POST /api/v1/content/questions` يتطلب `questions.manage` ويقبل `class_subject_id`, `lesson_id` اختياري، `question_text`, `question_type` (`MCQ|TRUE_FALSE|ESSAY`), `difficulty` اختياري، `explanation`, حقول المصدر، و`options` الاختيارية كمصفوفة `{option_text,is_correct}`. قواعد الإرسال للمراجعة: MCQ بخيارين على الأقل وإجابة صحيحة واحدة، وصح/خطأ بخيارين وإجابة صحيحة واحدة، والمقالي بلا خيارات.
- `PATCH /api/v1/content/{resources|past-exams|questions}/{id}` يحرر مسودة أو محتوى مرفوض يملكه المستخدم أو مدير النظام. تعديل المحتوى المرفوض يعيده إلى `DRAFT`. الصلاحية المطلوبة `content.upload` للملفات و`questions.manage` للسؤال.
- `POST /api/v1/content/{resources|past-exams}/{id}/replace-file` يستبدل ملف المسودة/المرفوض. يحذف الملف القديم بعد نجاح معاملة تحديث قاعدة البيانات.

الأستاذ الذي يرفع أو يرسل للمراجعة يجب أن يملك إسنادًا نشطًا للصف والمادة. أخطاء الرفع تشمل `413 file_too_large`, `415 unsupported_file_type`, `422 invalid_reference|provenance_required|file_required`, و`503 storage_not_configured|upload_not_configured`.

### المراجعة والنشر

- `POST /api/v1/content/{resources|past-exams|questions}/{id}/submit` يتطلب صلاحية الرفع المناسبة، ملكية المحتوى أو دور مدير النظام، وحالة `DRAFT` وبيانات مصدر/مالك/ترخيص مكتملة.
- `POST /api/v1/admin/content/{resources|past-exams|questions}/{id}/review` يتطلب `content.review` ويقبل `{ "status": "APPROVED"|"REJECTED", "feedback": "..." }`. الموافقة تتطلب أيضًا `content.publish`. ينشأ سجل `content_reviews` وتتغير الحالة و`published_at` في معاملة واحدة. المراجع لا يمكنه مراجعة محتواه، وقاعدة البيانات تمنع حالة `APPROVED` بلا سجل موافقة.
- المسار: `DRAFT → PENDING_REVIEW → APPROVED|REJECTED`؛ المحتوى المرفوض يمكن تحريره وإعادته لمسودة.
- أخطاء الحالات: `403 self_review_forbidden|forbidden`, `404 not_found`, `409 content_state_conflict`, `422 provenance_required|file_unavailable`, و`419 csrf_failed`.

### تنزيل الملفات الخاصة

- `GET /api/v1/content/resources/{id}/download`, `GET /api/v1/content/resources/{id}/variants/{variant_id}/download`, `GET /api/v1/content/past-exams/{id}/download` تتطلب جلسة وصلاحية `content.read` أو `exams.read` على الترتيب.
- يشترط أن يكون المحتوى `APPROVED` وله `published_at` وسجل مراجعة موافق. الطالب يحتاج اشتراكًا نشطًا ساريًا إذا كان المورد يتطلب اشتراكًا. تنزيل النسخ البديلة يتطلب `resource_variants.is_downloadable=true`.
- يدعم `Range: bytes=start-end` وطلبات suffix، ويعيد `206` مع `Content-Range` و`Accept-Ranges`. النطاق غير الصحيح يعيد `416` و`Content-Range: bytes */size`. النطاقات المتعددة غير مدعومة. يسجل التنزيل `content.download.started` في `audit_events`.
- لا يعاد مفتاح التخزين ولا ينشأ رابط عام. أخطاء الوصول: `401 unauthenticated`, `403 forbidden|active_subscription_required`, `404 not_found`, `416 range_not_satisfiable`.

## الأسئلة والاختبارات (المرحلة الرابعة)

إعدادات التشغيل المطلوبة بلا قيم افتراضية:

- `QUIZ_MAX_ATTEMPTS`: عدد المحاولات المحتسبة المسموح به، عدد صحيح موجب.
- `QUIZ_DURATION_MINUTES`: مدة افتراضية بالدقائق عند عدم تحديد `duration_minutes` للاختبار.
- `QUIZ_INCOMPLETE_ATTEMPT_POLICY`: `AUTO_SUBMIT` يصحح المحاولة المنتهية بإجاباتها المحفوظة؛ `CANCEL_COUNTED` يغلقها ويلتزم احتسابها؛ `CANCEL_FREE` يغلقها ولا يحتسبها من الحد. قبل انتهاء المهلة، يعيد بدء الاختبار المحاولة نفسها وإجاباتها المحفوظة.
- `QUIZ_RESULT_VISIBILITY`: `IMMEDIATE` يعرض نتيجة المرسل مباشرة؛ `AFTER_WINDOW` يعرضها بعد `ends_at` ويتطلب تاريخ إغلاق؛ `AFTER_MANUAL_GRADING` ينتظر اكتمال تصحيح الإجابات المقالية.

أي إعداد مطلوب مفقود أو غير صالح يعيد `503 quiz_not_configured`. يجب اعتماد قيم البيئة والسياسات قبل إتاحة الاختبارات للطلاب (OD-20 وOD-22).

### إدارة الأسئلة والاختبارات

- الأسئلة في `GET /api/v1/teacher/questions?stage_id=&class_id=&subject_id=&lesson_id=&question_type=&difficulty=&class_subject_id=` تتطلب `quizzes.manage`؛ الأستاذ يرى المواد المسندة إليه فقط. تعاد الأسئلة المعتمدة وخياراتها مع الإجابة الصحيحة للأستاذ المخول.
- قبل إرسال السؤال للمراجعة: سؤال `MCQ` يحتاج خيارين على الأقل وإجابة صحيحة واحدة؛ `TRUE_FALSE` يحتاج خيارين بالضبط وإجابة صحيحة واحدة؛ السؤال المقالي لا يقبل خيارات. خيار إجابة واحدة هو المتوافق مع نموذج الإجابة الحالي (`selected_option_id`). يفرض التطبيق وقاعدة البيانات هذه القواعد.
- `POST /api/v1/teacher/quizzes` يتطلب `quizzes.manage` وإسناد المادة، ويقبل `class_subject_id`, `title`, `description` اختياري، `duration_minutes` اختياري، `passing_marks` اختياري، `starts_at`, `ends_at` اختياريان، `requires_subscription` اختياري، و`questions` مصفوفة `{question_id,question_mark}`. لا يقبل إلا أسئلة `APPROVED` من المادة نفسها. ينشأ الاختبار غير منشور.
- `PATCH /api/v1/teacher/quizzes/{id}` يعدل البيانات و`is_published`. النشر يحتاج سؤالًا معتمدًا واحدًا على الأقل. لا يمكن تعديل البيانات بعد بدء محاولة. الأستاذ لا يعدل إلا اختبار مادة مسندة إليه؛ المدير يستطيع إدارة كل المواد.
- `GET /api/v1/quizzes` و`GET /api/v1/quizzes/{id}` للطالب ذي `quizzes.attempt`، ويعرضان الاختبارات المنشورة لصفه وخيارات الأسئلة دون مفاتيح الإجابة. يتطلب الاختبار اشتراكًا فعالًا إذا كان `requires_subscription=true`.

### المحاولات والتصحيح والنتائج

- `POST /api/v1/quizzes/{id}/attempts` يبدأ محاولة أو يعيد المحاولة غير المنتهية وإجاباتها المحفوظة. يرفض الخادم الاختبار غير المنشور أو خارج `starts_at`/`ends_at` أو عند تجاوز العدد. يعيد `attempt.deadline_at` المحسوب من مدة الاختبار ونهاية نافذته.
- `PUT /api/v1/attempts/{attempt_id}/answers/{question_id}` يحفظ `{ "selected_option_id": 12 }` للسؤال الموضوعي أو `{ "essay_answer": "..." }` للمقالي. الإجابة تتبع سؤال الاختبار والمحاولة، ولا يمكن تعديلها بعد الإرسال أو انتهاء الوقت. احفظ الإجابات أثناء الاختبار لتمكين الاستئناف.
- `POST /api/v1/attempts/{attempt_id}/submit` يرسل المحاولة. يحسب الخادم درجات MCQ وصح/خطأ؛ تبقى المحاولة بانتظار التصحيح اليدوي إذا وُجدت إجابات مقالية مجابة.
- `POST /api/v1/teacher/attempts/{attempt_id}/questions/{question_id}/grade` يقبل `{ "awarded_mark": 4, "teacher_feedback": "..." }`، ويتطلب `quizzes.manage` وإسناد مادة الاختبار. الدرجة لا تتجاوز علامة السؤال.
- `GET /api/v1/attempts/{attempt_id}/result` يسمح للطالب بقراءة محاولته فقط بعد تحقق سياسة إظهار النتيجة. `GET /api/v1/teacher/quizzes/{id}/results` يعرض محاولات ودرجات مادة الأستاذ المسندة فقط.
- حالات الأخطاء تشمل `403 attempt_owner_required|quiz_class_forbidden|active_subscription_required|result_not_available`, `409 quiz_not_open|quiz_closed|attempt_limit_reached|attempt_time_expired|attempt_not_active`, `422 question_not_approved|question_not_in_quiz|question_options_invalid`, و`503 quiz_not_configured`.
