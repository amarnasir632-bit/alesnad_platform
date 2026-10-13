# عقد واجهة API للمرحلة الأولى

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

- `Content-Type: application/json` مطلوب لكل طلب ذي جسم؛ JSON غير الصحيح يعيد `400 invalid_json`، والنوع الخطأ `415 content_type_required`.
- جميع مدخلات SQL مربوطة عبر PDO prepared statements. رسائل الخادم لا تعرض تفاصيل استثناء قاعدة البيانات.
- أخطاء غير متوقعة تعيد `500 internal_error` مع تسجيل التفاصيل في سجل الخادم.
- لا توجد نقاط لرفع الملفات أو تنزيلها أو مراجعة الموارد والامتحانات أو الاختبارات والحصص والرسائل والاشتراكات. البحث الأكاديمي يعرض بيانات وصفية للموارد فقط؛ عند تنفيذ نقاط الوصول للملفات يجب فرض الاستحقاق وتسجيل أحداث المراجعة/التنزيل في `audit_events`.
- الدفع الإلكتروني والذكاء الاصطناعي والألعاب وبوابة ولي الأمر وتطبيق الجوال خارج النطاق.

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

`GET /api/v1/content/search?q={text}&stage_id={id}&class_id={id}&subject_id={id}&lesson_id={id}&limit=25&offset=0` يتطلب جلسة وصلاحية `content.read`. كل المرشحات اختيارية؛ البحث النصي محدود بـ120 محرفًا، والحد `limit` من 1 إلى 100. يعيد موارد المحتوى المعتمدة فقط، مع بيانات المرحلة والصف والمادة والدرس وحقل `requires_subscription`، دون `storage_key` أو رابط تنزيل. يظل الوصول إلى الملف الفعلي خاضعًا لنقطة تنزيل محمية في مرحلة المحتوى، وقواعد أهلية الاشتراك في OD-05. أخطاء المدخلات `422 validation_failed`، وانعدام الصلاحية `403 forbidden`.

تمثل أسماء المراحل والصفوف والمواد بيانات قابلة للإدارة. لا يزرع النظام صفوفًا للمرحلة المتوسطة لأن أسمائها غير معتمدة بعد (OD-19).
