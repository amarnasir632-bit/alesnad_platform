-- Manual rollback for 008. Stops the application first.
-- Restores the original NOT NULL recording key; this fails if provider-only rows exist.
DROP TRIGGER IF EXISTS live_session_assignment_guard ON live_sessions;
DROP FUNCTION IF EXISTS validate_live_session_assignment();
DROP INDEX IF EXISTS live_sessions_teacher_schedule_idx;
DROP INDEX IF EXISTS messages_sender_rate_idx;
ALTER TABLE messages DROP COLUMN IF EXISTS receiver_deleted_at;
ALTER TABLE messages DROP COLUMN IF EXISTS sender_deleted_at;
ALTER TABLE session_recordings DROP CONSTRAINT IF EXISTS session_recording_source_check;
ALTER TABLE session_recordings ALTER COLUMN storage_key SET NOT NULL;
