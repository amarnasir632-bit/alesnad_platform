# مصفوفة تتبع SRS ونتائج الاختبار

تغطي هذه المصفوفة كل معرفات المتطلبات الوظيفية وغير الوظيفية في `docs/SRS_Educational_Platform_Arabic.docx`. النتيجة هنا هي نتيجة الاختبارات التي نُفذت محليًا أثناء إعداد المرحلة الثامنة، وليست افتراضًا عن نتيجة GitHub Actions.

**بيئة الفحص المحلي:** PHP 8.5.11 وPostgreSQL 18.6 مع امتداد `pdo_pgsql`. شغّلت تسلسل `backend-ci.yml` محليًا على قاعدة اختبار نظيفة؛ نسخة GitHub Actions تستخدم PostgreSQL 16، لذلك يلزم تشغيلها بعد رفع التغييرات لتأكيد التوافق على إصدار CI نفسه.

## بوابة إنجاز النطاق المعتمد

كل معرفات FR-001–024 وFR-029 وFR-030، وجميع معرفات NFR، موجودة في المصفوفة مع مكوّن واختبار ونتيجة معلنة. آخر تشغيل GitHub Actions فشل في مقارنة baseline للمرحلة الرابعة؛ ثُبّت baseline إلى commit تاريخي في `tests/static_phase4_checks.py`. بعد ذلك نجح تسلسل CI كاملًا محليًا على PostgreSQL 18.6. يجب إعادة تشغيل workflow بعد رفع التغييرات لتأكيد نتيجة GitHub على PostgreSQL 16. لا تُعامل نتيجة GitHub السابقة كنجاح.

المتطلبات التي يتأثر اكتمالها بقرار مالك موضحة في صفوفها وبمعرفات `docs/OPEN_DECISIONS.md`؛ ومنها OD-03/04 للسعة والحصص، OD-11 للاستجابة والتوافر، OD-12 للاحتفاظ، OD-13/14 للمزود والملفات، OD-19 لأسماء الصفوف، OD-20/22 لسياسات الاختبارات، وOD-24 للنسخ والاستعادة. لا تُسقط هذه المتطلبات عند إغلاق المرحلة.

| المعرّف | المكوّن المنفذ / الحالة | الاختبار | النتيجة الفعلية |
| --- | --- | --- | --- |
| FR-001 | إنشاء وإدارة الحسابات `AccountService` و`AccountController` | `tests/phase1_integration.php`; `tests/protected_endpoints_integration.php` | DB: 17/17 assertions PASS؛ HTTP والصلاحيات ضمن 154/154 assertions PASS محليًا |
| FR-002 | تسجيل الدخول والجلسات وتحديد المحاولات | `tests/phase1_integration.php`; `tests/protected_endpoints_integration.php` | DB: 17/17 assertions PASS؛ الدخول وauth/me وتسجيل الخروج اختبرت HTTP بنجاح |
| FR-003 | صلاحيات حسب الدور على الخادم | `tests/protected_endpoints_integration.php` اختبار مسموح ومرفوض لكل مسار محمي | 72/72 إدخال مسار؛ 154/154 HTTP assertions PASS محليًا، مع 403 لكل دور مرفوض |
| FR-004 | المراحل والصفوف والمواد والدروس | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | DB: 12/12 assertions PASS؛ static phase 2 PASS |
| FR-005 | صف السادس والثالث الثانوي وإضافة الفئات | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | DB: 12/12 assertions PASS؛ لم تُخترع صفوف للمرحلة المتوسطة، والأسماء تنتظر OD-19 |
| FR-006 | ربط المحتوى بالسياق الدراسي | `tests/static_phase3_checks.py`; `tests/phase2_academic_integration.php` | static phases 2–3 PASS؛ إنشاء مورد بمراجعة معتمدة والبحث عنه ضمن DB: PASS |
| FR-007 | المكتبة والتنزيل وفق الصلاحية | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ تنزيل ملف فعلي باشتراك غير مغطى باختبار E2E مستقل |
| FR-008 | البحث والتصفية | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | DB: 12/12 assertions PASS، بما فيها البحث بالسياق الدراسي والنص |
| FR-009 | بنك الأسئلة وتصنيفها | `tests/quiz_policy_unit.php`; `tests/static_phase4_checks.py` | PASS محليًا لاختبار الوحدة والفحص الساكن |
| FR-010 | إنشاء الأسئلة وتعديلها وإرسالها للمراجعة | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ دورة المحتوى E2E لا تزال بلا اختبار DB مستقل |
| FR-011 | أرشيف الامتحانات السابقة | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ سيناريو أرشيف فعلي لا يزال بلا اختبار DB مستقل |
| FR-012 | إدارة الامتحانات وبيانات نشرها | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ إدارة الامتحان المنشور لا تزال بلا اختبار DB مستقل |
| FR-013 | مراجعة المحتوى واعتماد النشر | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | DB يختبر موردًا ومراجعة معتمدة ضمن phase 2؛ HTTP صلاحيات PASS؛ بقية أنواع المحتوى بلا E2E كامل |
| FR-014 | الاختبارات الإلكترونية | `tests/static_phase4_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ دورة المحاولة الكاملة لم تختبر على DB بعد؛ الإعدادات تنتظر OD-20/22 |
| FR-015 | التصحيح التلقائي للأنواع الموضوعية | `tests/quiz_policy_unit.php`; `tests/static_phase4_checks.py` | اختبارات quiz policy وstatic PASS؛ دورة التصحيح المحفوظة في PostgreSQL لم تختبر E2E |
| FR-016 | عرض النتائج بحسب الدور | `tests/static_phase4_checks.py`; `tests/protected_endpoints_integration.php` | HTTP صلاحيات PASS؛ خصوصية نتيجة طالب تجاه طالب آخر لا تزال بلا سيناريو E2E DB |
| FR-017 | جدولة الحصص للأستاذ المخول | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ حفظ وتعديل حصة فعلية في DB بلا اختبار مستقل بعد |
| FR-018 | الانضمام للحصة حسب الوقت والصلاحية | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ قواعد الموعد والاشتراك لا يغطيها E2E DB بعد |
| FR-019 | التسجيلات بعد انتهاء الحصة | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ مزود البث ينتظر OD-13 وسياسة الملفات OD-14 |
| FR-020 | الرسائل الفردية بين الطالب والأستاذ | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | HTTP صلاحيات PASS؛ إرسال وقراءة وحذف الرسائل لا تغطيها دورة E2E DB كاملة |
| FR-021 | صلاحيات وضوابط التواصل والقراءة والحذف | `tests/static_phase5_checks.py`; `tests/protected_endpoints_integration.php` | HTTP رفض الدور PASS؛ مدد الاحتفاظ وحدود المعدل تنتظر OD-12 وOD-23 |
| FR-022 | لوحة الإدارة الرسومية؛ صفحات الإدارة غير منفذة؛ عمليات الإدارة الحالية API | `tests/static_phase8_checks.py` يتحقق من دقة وصف النطاق | واجهة لوحة الإدارة غير منفذة في هذا المستودع؛ APIs الإدارة موجودة؛ يتطلب عمل فريق الواجهة واختبار UI |
| FR-023 | حالات نشر المحتوى والوصول إليه | `tests/static_phase3_checks.py`; `tests/protected_endpoints_integration.php` | static وHTTP صلاحيات PASS؛ دورة انتقال المحتوى لكل الأنواع ليست E2E كاملة |
| FR-024 | التنزيل وRange للاتصال المحدود | `tests/content_range_unit.php`; `tests/static_phase3_checks.py` | اختبار Range ورفض URL مباشر PASS؛ تنزيل ملف فعلي واستئنافه عبر HTTP بلا E2E مستقل |
| FR-025 | توليد الأسئلة بالذكاء الاصطناعي؛ خارج إطلاق المرحلة الحالية حسب قرار المالك | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | مؤجل؛ غير منفذ |
| FR-026 | توضيح المحتوى المولد آليًا والإبلاغ عنه؛ تابع لتوليد الذكاء الاصطناعي المؤجل | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | مؤجل؛ غير منفذ |
| FR-027 | اللعبة التعليمية؛ خارج النطاق المعتمد الحالي | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | غير منفذ؛ لم يوصف بأنه جاهز |
| FR-028 | بوابة ولي الأمر؛ خارج النطاق المعتمد الحالي | `tests/static_phase8_checks.py` يتحقق من وصف النطاق | غير منفذ؛ مشروط بقرار مالك |
| FR-029 | إضافة مراحل وصفوف ومواد دون تغيير المخطط؛ API الإدارة في `AcademicService` | `tests/phase2_academic_integration.php`; `tests/static_phase2_checks.py` | DB: 12/12 assertions PASS؛ static phase 2 PASS |
| FR-030 | سجلات التدقيق والمراقبة الأساسية؛ `audit_log`, سجل أخطاء منقح و`/health` | `tests/phase1_integration.php`; `tests/protected_endpoints_integration.php`; `tests/static_phase8_checks.py` | DB audit assertions وHTTP health PASS؛ السجلات المنقحة تحققها فحوص static |
| NFR-001 | حماية الحسابات والبيانات والجلسات؛ CSRF، تجزئة كلمات المرور، سجل منقح | `tests/password_policy.php`; `tests/phase1_integration.php`; `tests/static_phase1_checks.py` | اختبارات الوحدة وDB 17/17 وHTTP المصادقة PASS محليًا |
| NFR-002 | فرض الصلاحية لكل endpoint محمي؛ متحكمات API وفحص الصلاحيات من DB | `tests/protected_endpoints_integration.php` يختبر دورًا مسموحًا ودورًا مرفوضًا لكل إدخال في manifest | كل المسارات المحمية المشمولة: 72/72؛ HTTP assertions: 154/154 PASS محليًا |
| NFR-003 | خصوصية الملفات وحقوقها؛ تخزين خارج `public/` وتنزيل محمي | `tests/content_range_unit.php`; `tests/protected_endpoints_integration.php` فحص URL مباشر | Range unit PASS؛ الوصول المباشر للملف الخاص أعاد 404؛ 154 HTTP assertions PASS |
| NFR-004 | الأداء وزمن الاستجابة؛ لم تعتمد أرقام حمل أو زمن استجابة | لا اختبار أداء بأرقام غير معتمدة؛ `tests/static_phase8_checks.py` | غير قابل للقياس بعد؛ الهدف الرقمي ينتظر OD-11 |
| NFR-005 | السعة والتوسع؛ نمو المستخدمين والحصص يتطلب أرقامًا متفقًا عليها | لا اختبار ضغط قبل تحديد السعة؛ `tests/static_phase8_checks.py` | غير قابل للقياس بعد؛ أحجام الاستخدام تنتظر OD-03 وOD-04 |
| NFR-006 | الإنترنت المحدود والتنزيل المتدرج؛ بث ملفات وRange | `tests/content_range_unit.php`; `tests/static_phase3_checks.py` | اختبار Range وfحص static PASS؛ E2E تنزيل/استئناف ملف حقيقي لم ينفذ |
| NFR-007 | النسخ الاحتياطي والاستعادة؛ `pg_dump`, `pg_restore`, checksum وسجل backup | `tests/backup_restore_integration.php` | نسخ واستعادة PostgreSQL: 5/5 assertions PASS محليًا؛ جدولة واحتفاظ وتشفير تنتظر OD-24 |
| NFR-008 | وضوح الواجهة والعربية والهاتف؛ الهيكل الحالي عربي RTL؛ تدقيق تجربة الهاتف/الوصول النهائي مسؤولية الواجهة | `tests/static_phase8_checks.py` يتحقق من `lang="ar" dir="rtl"` | RTL تحقق static؛ اختبار الهاتف وقابلية الاستخدام/UAT لم ينفذ |
| NFR-009 | قابلية الصيانة وإدارة الإصدارات؛ PHP، ترحيلات ببصمات، وثائق وCI | `php -l`; `tests/static_phase8_checks.py`; جميع static phase checks | 53/53 PHP lint و161/161 static و19/19 unit PASS محليًا؛ GitHub rerun ينتظر الرفع |
| NFR-010 | خصوصية الطلاب والرسائل والنتائج؛ فحوص الدور والملكية على الخادم | `tests/protected_endpoints_integration.php`; `tests/phase1_integration.php` | فحوص صلاحيات HTTP PASS؛ اختبار عزل ملكية النتائج والرسائل E2E غير كامل |
| NFR-011 | مراقبة الأخطاء والخدمة؛ `/health` وسجل أحداث لا يحتوي رسالة الاستثناء | `tests/protected_endpoints_integration.php`; `tests/static_phase8_checks.py` | /health أعاد 200 وسجلات الأخطاء المنقحة اجتازت static checks؛ HTTP PASS محليًا |
| NFR-012 | التعامل مع تعذر الخدمات الخارجية دون أسرار؛ عقد مزود مجرد؛ لا مزود بث/تخزين محدد أو adapter | `tests/static_phase5_checks.py`; `tests/static_phase8_checks.py` | عقد المزود مجرد، ولا تكامل تنفيذي قبل اعتماد OD-13؛ اختبار المزود غير منطبق حاليًا |

## نتائج التشغيل المسجلة لهذه المرحلة

- `php -l`: 53/53 ملف PHP ناجح.
- اختبارات الوحدة: 19/19 ناجحة؛ الفحوصات الساكنة للمراحل 1–8: 161/161 ناجحة.
- تكامل PostgreSQL/HTTP نجح محليًا على PostgreSQL 18.6: phase 1 = 17/17، phase 2 = 12/12، HTTP = 154/154، backup/restore = 5/5 assertions.
- آخر تشغيل workflow فشل في baseline المرحلة الرابعة؛ أصلحنا المقارنة محليًا. نتيجة GitHub الجديدة لم تصدر بعد، ولا تسجل CI كناجح قبل تشغيله فعليًا.
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
