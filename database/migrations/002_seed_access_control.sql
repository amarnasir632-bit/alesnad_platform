INSERT INTO roles (code, display_name, description) VALUES
    ('student', 'طالب', 'الوصول إلى المحتوى التعليمي والاختبارات والرسائل المسموح بها.'),
    ('teacher', 'أستاذ', 'إدارة المحتوى والحصص والاختبارات المسندة إلى الأستاذ.'),
    ('content_reviewer', 'مراجع محتوى', 'مراجعة المحتوى والأسئلة والامتحانات قبل النشر.'),
    ('admin', 'مدير النظام', 'إدارة حسابات النظام وأدواره وإعداداته.')
ON CONFLICT (code) DO NOTHING;

INSERT INTO permissions (code, display_name, description) VALUES
    ('users.manage', 'إدارة المستخدمين', 'إنشاء حسابات المستخدمين وتعديلها وتعطيلها.'),
    ('roles.manage', 'إدارة الأدوار', 'إسناد الأدوار والصلاحيات.'),
    ('academics.manage', 'إدارة الهيكل الدراسي', 'إدارة المراحل والصفوف والمواد.'),
    ('content.upload', 'رفع المحتوى', 'رفع الكتب والموارد والامتحانات.'),
    ('content.read', 'قراءة المحتوى', 'عرض المحتوى المصرح به وفق الصلاحيات والاشتراك.'),
    ('content.review', 'مراجعة المحتوى', 'قبول المحتوى أو رفضه أو طلب تعديله.'),
    ('content.publish', 'نشر المحتوى', 'إتاحة المحتوى المعتمد للمستخدمين.'),
    ('exams.read', 'عرض الامتحانات', 'عرض الامتحانات السابقة المصرح بها.'),
    ('questions.manage', 'إدارة الأسئلة', 'إنشاء الأسئلة وتعديلها.'),
    ('quizzes.manage', 'إدارة الاختبارات', 'إنشاء الاختبارات ومراجعة نتائجها.'),
    ('quizzes.attempt', 'أداء الاختبارات', 'بدء الاختبارات المسموح بها وإرسال الإجابات.'),
    ('sessions.manage', 'إدارة الحصص', 'جدولة الحصص والتسجيلات.'),
    ('sessions.join', 'حضور الحصص', 'الانضمام إلى الحصص ومشاهدة التسجيلات المصرح بها.'),
    ('messages.use', 'استخدام الرسائل', 'إرسال واستقبال الرسائل الفردية المصرح بها.'),
    ('subscriptions.manage', 'إدارة الاشتراكات', 'إدارة خطط الاشتراك والاشتراكات.'),
    ('subscriptions.read_own', 'عرض الاشتراك الشخصي', 'عرض حالة اشتراك المستخدم الحالي.'),
    ('payments.review', 'مراجعة المدفوعات', 'متابعة حالات المدفوعات دون حفظ بيانات البطاقات.'),
    ('audit.read', 'عرض سجل التدقيق', 'قراءة سجلات الأحداث الإدارية.')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE (r.code = 'student' AND p.code IN ('content.read', 'exams.read', 'quizzes.attempt', 'sessions.join', 'messages.use', 'subscriptions.read_own'))
   OR (r.code = 'teacher' AND p.code IN (
       'content.upload', 'content.read', 'exams.read', 'questions.manage',
       'quizzes.manage', 'quizzes.attempt', 'sessions.manage', 'sessions.join', 'messages.use'
   ))
   OR (r.code = 'content_reviewer' AND p.code IN ('content.review', 'content.publish', 'questions.manage'))
   OR (r.code = 'admin' AND p.code IN (
       'users.manage', 'roles.manage', 'academics.manage', 'content.upload',
       'content.read', 'content.review', 'content.publish', 'exams.read',
       'questions.manage', 'quizzes.manage', 'quizzes.attempt', 'sessions.manage',
       'sessions.join', 'messages.use', 'subscriptions.manage', 'subscriptions.read_own',
       'payments.review', 'audit.read'
   ))
ON CONFLICT (role_id, permission_id) DO NOTHING;
