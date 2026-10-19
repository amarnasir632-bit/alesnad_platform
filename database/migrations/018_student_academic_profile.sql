ALTER TABLE students
    ADD COLUMN academic_semester VARCHAR(12),
    ADD COLUMN admission_year SMALLINT,
    ADD CONSTRAINT students_academic_semester_valid
        CHECK (academic_semester IS NULL OR academic_semester IN ('FIRST', 'SECOND')),
    ADD CONSTRAINT students_admission_year_valid
        CHECK (admission_year IS NULL OR admission_year BETWEEN 1900 AND 2200);
