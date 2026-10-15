DROP TABLE IF EXISTS announcements;
ALTER TABLE message_requests DROP COLUMN IF EXISTS is_frozen;
ALTER TABLE message_requests DROP COLUMN IF EXISTS frozen_at;
ALTER TABLE message_requests DROP COLUMN IF EXISTS frozen_by;
