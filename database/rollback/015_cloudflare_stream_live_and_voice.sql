DROP TABLE IF EXISTS session_voice_requests;
DROP INDEX IF EXISTS live_sessions_lesson_idx;
ALTER TABLE live_sessions DROP COLUMN IF EXISTS lesson_id;
ALTER TABLE learning_resources DROP COLUMN IF EXISTS video_processing_status;
