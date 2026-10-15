ALTER TABLE message_requests ADD COLUMN IF NOT EXISTS is_frozen BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE message_requests ADD COLUMN IF NOT EXISTS frozen_at TIMESTAMPTZ;
ALTER TABLE message_requests ADD COLUMN IF NOT EXISTS frozen_by BIGINT REFERENCES users(id);

CREATE TABLE IF NOT EXISTS announcements (
    id BIGSERIAL PRIMARY KEY,
    author_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    badge_label VARCHAR(100) DEFAULT 'تنبيه أكاديمي',
    priority VARCHAR(50) DEFAULT 'NORMAL',
    cover_style VARCHAR(100) DEFAULT 'gradient_blue',
    cover_image_url TEXT,
    class_subject_id BIGINT REFERENCES class_subjects(id) ON DELETE CASCADE,
    class_id BIGINT REFERENCES classes(id) ON DELETE CASCADE,
    target_role VARCHAR(50) DEFAULT 'ALL',
    is_published BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS announcements_author_idx ON announcements (author_id);
CREATE INDEX IF NOT EXISTS announcements_class_subject_idx ON announcements (class_subject_id);
CREATE INDEX IF NOT EXISTS announcements_created_idx ON announcements (created_at DESC);
