INSERT INTO stages (code, name, sort_order) VALUES
    ('PRIMARY', 'المرحلة الابتدائية', 1),
    ('MIDDLE', 'المرحلة المتوسطة', 2),
    ('SECONDARY', 'المرحلة الثانوية', 3)
ON CONFLICT (code) DO NOTHING;

INSERT INTO classes (stage_id, code, name, sort_order)
SELECT id, 'GRADE_6', 'الصف السادس', 6 FROM stages WHERE code = 'PRIMARY'
ON CONFLICT (stage_id, code) DO NOTHING;

INSERT INTO classes (stage_id, code, name, sort_order)
SELECT id, 'GRADE_3_SECONDARY', 'الصف الثالث الثانوي', 3 FROM stages WHERE code = 'SECONDARY'
ON CONFLICT (stage_id, code) DO NOTHING;
