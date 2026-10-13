# مصفوفة تتبع SRS ونتائج الاختبار

تغطي هذه المصفوفة كل معرفات المتطلبات الوظيفية وغير الوظيفية في `docs/SRS_Educational_Platform_Arabic.docx`. النتيجة هنا هي نتيجة الاختبارات التي نُفذت محليًا أثناء إعداد المرحلة الثامنة، وليست افتراضًا عن نتيجة GitHub Actions.

**بيئة الفحص المحلي:** PHP 8.4 syntax checks واختبارات PHP/Python الساكنة تعمل؛ امتداد `pdo_pgsql` متاح، لكن خدمة PostgreSQL المحلية على `127.0.0.1:5432` غير متاحة، لذلك اختبارات PostgreSQL وHTTP لا يمكن تسجيلها ناجحة محليًا. `backend-ci.yml` ينفذها على PostgreSQL 16 بعد رفع الفرع.

| المعرّف | المكوّن المنفذ / الحالة | الاختبار | النتيجة الفعلية |
| --- | --- | --- | --- |
| FR-001 | إنشاء وإدارة الحسابات `AccountService` و`AccountController` | `tests/phase1_integration.php`; `tests/protected_endpoints_integration.php` | static phase 1: PASS محليًا؛ تكامل DB/HTTP: لم ينفذ محليًا، ينتظر CI |
| FR-002 | تسجيل الدخول والجلسات وتحديد المحاولات | `tests/phase1_integration.php`; `tests/protected_endpoints_integration.php` | static phase 1: PASS محليًا؛ تكامل DB/HTTP: لم ينفذ محليًا، ينتظر CI |
| FR-003 | صلاحيات حسب الدور على الخادم | `tests/protected_endpoints_integration.php` اختبار مسموح ومرفوض لكل مسار محمي | static phase 1 و8: PASS محليًا؛ HTTP: لم ينفذ محليًا، ينتظر CI |
| FR-004 | المراحل والصفوف والمواد والدروس | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | static phase 2: PASS محليًا؛ تكامل PostgreSQL: لم ينفذ محليًا، ينتظر CI |
| FR-005 | صف السادس والثالث الثانوي وإضافة الفئات | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | static phase 2: PASS محليًا؛ تكامل PostgreSQL: لم ينفذ محليًا، ينتظر CI |
| FR-006 | ربط المحتوى بالسياق الدراسي | `tests/static_phase3_checks.py`; `tests/phase2_academic_integration.php` | static phases 2–3: PASS محليًا؛ تكامل PostgreSQL: ينتظر CI |
| FR-007 | المكتبة والتنزيل وفق الصلاحية | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static phase 3: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-008 | البحث والتصفية | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | static phase 2: PASS محليًا؛ تكامل PostgreSQL: ينتظر CI |
| FR-009 | بنك الأسئلة وتصنيفها | `tests/quiz_policy_unit.php`; `tests/static_phase4_checks.py` | PASS محليًا لاختبار الوحدة والفحص الساكن |
| FR-010 | إنشاء الأسئلة وتعديلها وإرسالها للمراجعة | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static phase 3: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-011 | أرشيف الامتحانات السابقة | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static phase 3: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-012 | إدارة الامتحانات وبيانات نشرها | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static phase 3: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-013 | مراجعة المحتوى واعتماد النشر | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static phase 3: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-014 | الاختبارات الإلكترونية | `tests/static_phase4_checks.py`; `tests/protected_endpoints_integration.php` | static phase 4: PASS محليًا؛ تكامل دورة الاختبار على PostgreSQL: ينتظر CI |
| FR-015 | التصحيح التلقائي للأنواع الموضوعية | `tests/quiz_policy_unit.php`; `tests/static_phase4_checks.py` | PASS محليًا للوحدة والفحص الساكن؛ دورة التقييم على DB تنتظر CI |
| FR-016 | عرض النتائج بحسب الدور | `tests/static_phase4_checks.py`; `tests/protected_endpoints_integration.php` | static phase 4: PASS محليًا؛ اختبار خصوصية API على PostgreSQL: ينتظر CI |
| FR-017 | جدولة الحصص للأستاذ المخول | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static phase 5: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-018 | الانضمام للحصة حسب الوقت والصلاحية | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static phase 5: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-019 | التسجيلات بعد انتهاء الحصة | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static phase 5: PASS محليًا؛ HTTP/DB: ينتظر CI؛ مزود البث غير مختار |
| FR-020 | الرسائل الفردية بين الطالب والأستاذ | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static phase 5: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-021 | صلاحيات وضوابط التواصل والقراءة والحذف | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static phase 5: PASS محليًا؛ HTTP/DB: ينتظر CI؛ سياسة الاحتفاظ معلقة |
| FR-022 | لوحة الإدارة الرسومية؛ صفحات الإدارة غير منفذة؛ عمليات الإدارة الحالية API | `tests/static_phase8_checks.py` يتحقق من دقة وصف النطاق | غير منفذ؛ لم يوصف بأنه جاهز |
| FR-023 | حالات نشر المحتوى والوصول إليه | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static phase 3: PASS محليًا؛ HTTP/DB: ينتظر CI |
| FR-024 | التنزيل وRange للاتصال المحدود | `tests/content_range_unit.php`; `tests/static_phase3_checks.py` | PASS محليًا للوحدة والفحص الساكن؛ تنزيل HTTP ينتظر CI |
| FR-025 | توليد الأسئلة بالذكاء الاصطناعي؛ خارج إطلاق المرحلة الحالية حسب قرار المالك | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | مؤجل؛ غير منفذ |
| FR-026 | توضيح المحتوى المولد آليًا والإبلاغ عنه؛ تابع لتوليد الذكاء الاصطناعي المؤجل | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | مؤجل؛ غير منفذ |
| FR-027 | اللعبة التعليمية؛ خارج النطاق المعتمد الحالي | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | غير منفذ؛ لم يوصف بأنه جاهز |
| FR-028 | بوابة ولي الأمر؛ خارج النطاق المعتمد الحالي | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | غير منفذ؛ مشروط بقرار مالك |
| FR-029 | إضافة مراحل وصفوف ومواد دون تغيير المخطط؛ API الإدارة في `AcademicService` | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | static phase 2: PASS محليًا؛ تكامل PostgreSQL: ينتظر CI |
| FR-030 | سجلات التدقيق والمراقبة الأساسية؛ `audit_log`, سجل أخطاء منقح و`/health` | `tests/phase1_integration.php`; `tests/protected_endpoints_integration.php`; `tests/static_phase8_checks.py` | فحص ساكن PASS محليًا؛ DB/HTTP ينتظر CI |
| NFR-001 | حماية الحسابات والبيانات والجلسات؛ CSRF، تجزئة كلمات المرور، سجل منقح | `tests/password_policy.php`; `tests/phase1_integration.php`; `tests/static_phase1_checks.py` | كلمة المرور/static: PASS محليًا؛ تكامل DB ينتظر CI |
| NFR-002 | فرض الصلاحية لكل endpoint محمي؛ متحكمات API وفحص الصلاحيات من DB | `tests/protected_endpoints_integration.php` يختبر دورًا مسموحًا ودورًا مرفوضًا لكل إدخال في manifest | static manifest PASS محليًا؛ اختبار HTTP ينتظر CI |
| NFR-003 | خصوصية الملفات وحقوقها؛ تخزين خارج `public/` وتنزيل محمي | `tests/content_range_unit.php`; `tests/protected_endpoints_integration.php` فحص URL مباشر | Range PASS محليًا؛ اختبار HTTP المباشر ينتظر CI |
| NFR-004 | الأداء وزمن الاستجابة؛ لم تعتمد أرقام حمل أو زمن استجابة | لا اختبار أداء بأرقام غير معتمدة؛ `tests/static_phase8_checks.py` | غير قابل للقياس بعد؛ قرار المالك معلق |
| NFR-005 | السعة والتوسع؛ نمو المستخدمين والحصص يتطلب أرقامًا متفقًا عليها | لا اختبار ضغط قبل تحديد السعة؛ `tests/static_phase8_checks.py` | غير قابل للقياس بعد؛ القرار معلق |
| NFR-006 | الإنترنت المحدود والتنزيل المتدرج؛ بث ملفات وRange | `tests/content_range_unit.php`; `tests/static_phase3_checks.py` | PASS محليًا للوحدة والفحص الساكن |
| NFR-007 | النسخ الاحتياطي والاستعادة؛ `pg_dump`, `pg_restore`, checksum وسجل backup | `tests/backup_restore_integration.php` | لم ينفذ محليًا لعدم توفر خدمة PostgreSQL؛ CI يشغله على PostgreSQL |
| NFR-008 | وضوح الواجهة والعربية والهاتف؛ الهيكل الحالي عربي RTL؛ تدقيق تجربة الهاتف/الوصول النهائي مسؤولية الواجهة | `tests/static_phase8_checks.py` يتحقق من `lang="ar" dir="rtl"` | تحقق HTML ساكن PASS محليًا؛ اختبار قابلية الاستخدام/UAT لم ينفذ |
| NFR-009 | قابلية الصيانة وإدارة الإصدارات؛ PHP، ترحيلات ببصمات، وثائق وCI | `php -l`; `tests/static_phase8_checks.py`; جميع static phase checks | PHP/static PASS محليًا؛ نتيجة CI تنتظر تشغيل GitHub |
| NFR-010 | خصوصية الطلاب والرسائل والنتائج؛ فحوص الدور والملكية على الخادم | `tests/protected_endpoints_integration.php`; `tests/phase1_integration.php` | static PASS محليًا؛ تكامل HTTP/DB ينتظر CI |
| NFR-011 | مراقبة الأخطاء والخدمة؛ `/health` وسجل أحداث لا يحتوي رسالة الاستثناء | `tests/protected_endpoints_integration.php`; `tests/static_phase8_checks.py` | static PASS محليًا؛ HTTP ينتظر CI |
| NFR-012 | التعامل مع تعذر الخدمات الخارجية دون أسرار؛ عقد مزود مجرد؛ لا مزود بث/تخزين محدد أو adapter | `tests/static_phase5_checks.py`; `tests/static_phase8_checks.py` | عدم وجود مزود تنفيذي موثق؛ اختبار تكامل غير منطبق قبل اختيار المزود |

## نتائج التشغيل المسجلة لهذه المرحلة

- `php -l`: 53/53 ملف PHP ناجح.
- اختبارات الوحدة: 16/16 ناجحة؛ الفحوصات الساكنة للمراحل 1–8: 158/158 ناجحة.
- لم تعمل اختبارات PostgreSQL/HTTP محليًا لأن خادم PostgreSQL على `127.0.0.1:5432` لا يستجيب. امتداد `pdo_pgsql` متاح، لكن خدمة DB غير متاحة.
- Workflow مضاف ولم ينفذ على GitHub بعد. لا تسجل نتيجة CI كناجحة حتى يكتمل فعليًا.
- اختبارات الأداء وUAT وتكامل البث/التخزين تنتظر قرارات وأرقام وبيئة من المالك؛ لم تُسجل كاختبارات ناجحة.

## تشغيل مجموعة الاختبارات

```bash
php tests/password_policy.php
php tests/content_range_unit.php
php tests/quiz_policy_unit.php
for check in tests/static_phase{1..8}_checks.py; do python3 "$check"; done
php tests/phase1_integration.php
php tests/phase2_academic_integration.php
```

للتكامل HTTP والنسخ والاستعادة، استخدم GitHub Actions أو بيئة اختبار مع PostgreSQL 16 وعميل `pg_dump`/`pg_restore`. كل اختبارات التكامل ترفض أي قاعدة لا يبدأ اسمها بـ`alesnad_test_`.
