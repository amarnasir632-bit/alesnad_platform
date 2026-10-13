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
- لا توجد حاليًا نقاط API للمحتوى والمراجعة والتنزيل والاختبارات والحصص والرسائل والاشتراكات. عند إضافتها، يجب فرض الصلاحيات والتحقق من الاستحقاق وتسجيل أحداث المراجعة/النشر/التنزيل في `audit_events`.
- الدفع الإلكتروني والذكاء الاصطناعي والألعاب وبوابة ولي الأمر وتطبيق الجوال خارج النطاق.

## الترحيل والتراجع

`004_auth_security.sql` يضيف `auth_login_attempts` وفهرسًا جزئيًا لتخزين عداد محاولات الدخول وبصمة البريد ووقت القفل وآخر عنوان IP؛ لا يغير 001–003. التراجع قبل استخدام بياناته: إيقاف تطبيق الويب ثم تنفيذ `DROP TABLE auth_login_attempts;` على قاعدة البيئة المتأثرة (يزيل سجل محاولات الدخول فقط). بعد تطبيق الترحيل على بيئة مشتركة، لا تنفذ التراجع دون موافقة مالك البيئة لأن بيانات القفل ستفقد.
