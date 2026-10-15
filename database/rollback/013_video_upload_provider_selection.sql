DROP INDEX IF EXISTS video_upload_sessions_user_expiry_idx;
DROP TABLE IF EXISTS video_upload_sessions;
DROP INDEX IF EXISTS learning_resources_provider_asset_unique;

ALTER TABLE learning_resources
    DROP CONSTRAINT IF EXISTS learning_resources_video_reference_check,
    DROP CONSTRAINT IF EXISTS learning_resources_video_provider_check,
    DROP CONSTRAINT IF EXISTS learning_resources_storage_location_check;

ALTER TABLE learning_resources
    ADD CONSTRAINT learning_resources_storage_location_check
        CHECK (storage_key IS NOT NULL OR external_url IS NOT NULL OR cloudflare_video_uid IS NOT NULL),
    DROP COLUMN IF EXISTS video_duration_seconds,
    DROP COLUMN IF EXISTS video_playback_id,
    DROP COLUMN IF EXISTS video_asset_id,
    DROP COLUMN IF EXISTS video_provider;