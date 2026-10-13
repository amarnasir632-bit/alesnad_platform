-- Phase 2: allow an explicitly authorized academic administrator to assign teachers.
-- Academic data tables already exist in migration 001; this phase adds no invented grade rows.
INSERT INTO permissions (code, display_name, description)
VALUES ('teaching_assignments.manage', 'إسناد الأساتذة', 'إسناد الأساتذة إلى الصفوف والمواد وإدارة تكليفاتهم.')
ON CONFLICT (code) DO NOTHING;

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'admin' AND p.code = 'teaching_assignments.manage'
ON CONFLICT (role_id, permission_id) DO NOTHING;
