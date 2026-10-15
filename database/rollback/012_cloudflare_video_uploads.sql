DROP TABLE IF EXISTS cloudflare_video_uploads;
DROP INDEX IF EXISTS learning_resources_cloudflare_video_uid_unique;
ALTER TABLE learning_resources DROP CONSTRAINT IF EXISTS learning_resources_storage_location_check;
ALTER TABLE learning_resources DROP COLUMN IF EXISTS cloudflare_video_uid;
ALTER TABLE learning_resources
    ADD CONSTRAINT learning_resources_storage_location_check
        CHECK (storage_key IS NOT NULL OR external_url IS NOT NULL);
