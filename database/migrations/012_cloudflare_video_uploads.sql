-- Cloudflare Stream direct uploads for protected learning videos.
ALTER TABLE learning_resources
    ADD COLUMN cloudflare_video_uid VARCHAR(255);

ALTER TABLE learning_resources
    DROP CONSTRAINT learning_resources_storage_location_check,
    ADD CONSTRAINT learning_resources_storage_location_check
        CHECK (storage_key IS NOT NULL OR external_url IS NOT NULL OR cloudflare_video_uid IS NOT NULL);

CREATE UNIQUE INDEX learning_resources_cloudflare_video_uid_unique
    ON learning_resources (cloudflare_video_uid)
    WHERE cloudflare_video_uid IS NOT NULL;

CREATE TABLE cloudflare_video_uploads (
    upload_token VARCHAR(64) PRIMARY KEY,
    cloudflare_video_uid VARCHAR(255) NOT NULL UNIQUE,
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
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX cloudflare_video_uploads_user_expiry_idx
    ON cloudflare_video_uploads (user_id, expires_at);
