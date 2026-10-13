-- Phase 5: private session recording sources and per-participant message deletion.
ALTER TABLE session_recordings ALTER COLUMN storage_key DROP NOT NULL;
ALTER TABLE session_recordings
    ADD CONSTRAINT session_recording_source_check
    CHECK (storage_key IS NOT NULL OR provider_recording_ref IS NOT NULL);

ALTER TABLE messages ADD COLUMN sender_deleted_at TIMESTAMPTZ;
ALTER TABLE messages ADD COLUMN receiver_deleted_at TIMESTAMPTZ;
CREATE INDEX messages_sender_rate_idx ON messages (sender_id, sent_at DESC);
CREATE INDEX live_sessions_teacher_schedule_idx ON live_sessions (teacher_id, scheduled_at DESC);

CREATE FUNCTION validate_live_session_assignment() RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM teaching_assignments ta
        JOIN employees e ON e.id = ta.employee_id AND e.is_active = TRUE
        JOIN users u ON u.id = e.user_id AND u.is_active = TRUE
        JOIN user_roles ur ON ur.user_id = u.id
        JOIN roles r ON r.id = ur.role_id AND r.code = 'teacher'
        JOIN class_subjects cs ON cs.id = ta.class_subject_id AND cs.is_active = TRUE
        JOIN classes c ON c.id = cs.class_id AND c.is_active = TRUE
        JOIN stages st ON st.id = c.stage_id AND st.is_active = TRUE
        JOIN subjects s ON s.id = cs.subject_id AND s.is_active = TRUE
        WHERE ta.employee_id = NEW.teacher_id AND ta.class_subject_id = NEW.class_subject_id
    ) THEN
        RAISE EXCEPTION 'live session teacher must have an active teaching assignment' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER live_session_assignment_guard
    BEFORE INSERT OR UPDATE OF teacher_id, class_subject_id ON live_sessions
    FOR EACH ROW EXECUTE FUNCTION validate_live_session_assignment();
