ALTER TABLE learning_resources
    ADD COLUMN video_provider VARCHAR(32),
    ADD COLUMN video_asset_id VARCHAR(255),
    ADD COLUMN video_playback_id VARCHAR(255),
    ADD COLUMN video_duration_seconds NUMERIC(12,3);

UPDATE learning_resources
SET video_provider = 'cloudflare_stream',
    video_asset_id = cloudflare_video_uid,
    video_playback_id = cloudflare_video_uid
WHERE cloudflare_video_uid IS NOT NULL;

ALTER TABLE learning_resources
    DROP CONSTRAINT learning_resources_storage_location_check,
    ADD CONSTRAINT learning_resources_storage_location_check
        CHECK (storage_key IS NOT NULL OR external_url IS NOT NULL OR cloudflare_video_uid IS NOT NULL OR video_asset_id IS NOT NULL),
    ADD CONSTRAINT learning_resources_video_provider_check
        CHECK (video_provider IS NULL OR video_provider IN ('cloudflare_stream', 'mux')),
    ADD CONSTRAINT learning_resources_video_reference_check
        CHECK ((video_provider IS NULL AND video_asset_id IS NULL AND video_playback_id IS NULL)
            OR (video_provider IS NOT NULL AND video_asset_id IS NOT NULL AND video_playback_id IS NOT NULL));

CREATE UNIQUE INDEX learning_resources_provider_asset_unique
    ON learning_resources (video_provider, video_asset_id)
    WHERE video_asset_id IS NOT NULL;

CREATE TABLE video_upload_sessions (
    upload_token VARCHAR(48) PRIMARY KEY CHECK (upload_token ~ '^[a-f0-9]{48}$'),
    provider_code VARCHAR(32) NOT NULL CHECK (provider_code IN ('cloudflare_stream', 'mux')),
    provider_upload_id VARCHAR(255) NOT NULL,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    employee_id BIGINT NOT NULL REFERENCES employees(id) ON DELETE RESTRICT,
    class_subject_id BIGINT NOT NULL REFERENCES class_subjects(id) ON DELETE RESTRICT,
    lesson_id BIGINT REFERENCES lessons(id) ON DELETE SET NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    source_reference TEXT,
    source_owner VARCHAR(200),
    copyright_license VARCHAR(200),
    expires_at TIMESTAMPTZ NOT NULL,
    resource_id BIGINT REFERENCES learning_resources(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (provider_code, provider_upload_id)
);

CREATE INDEX video_upload_sessions_user_expiry_idx
    ON video_upload_sessions (user_id, expires_at);

INSERT INTO video_upload_sessions
    (upload_token, provider_code, provider_upload_id, user_id, employee_id, class_subject_id, lesson_id, title,
     description, source_reference, source_owner, copyright_license, expires_at, resource_id, created_at)
SELECT upload_token, 'cloudflare_stream', cloudflare_video_uid, user_id, employee_id, class_subject_id, lesson_id, title,
       description, source_reference, source_owner, copyright_license, expires_at, resource_id, created_at
FROM cloudflare_video_uploads;