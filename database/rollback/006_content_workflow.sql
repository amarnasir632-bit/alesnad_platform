-- Manual rollback for 006_content_workflow.sql.
-- Stop the application first. This deletes provenance, published_at, and content review history added/used by phase 3.
DROP TRIGGER IF EXISTS content_reviews_submission_guard ON content_reviews;
DROP TRIGGER IF EXISTS questions_publication_review_guard ON questions;
DROP TRIGGER IF EXISTS past_exams_publication_review_guard ON past_exams;
DROP TRIGGER IF EXISTS learning_resources_publication_review_guard ON learning_resources;
DROP TRIGGER IF EXISTS questions_status_transition ON questions;
DROP TRIGGER IF EXISTS past_exams_status_transition ON past_exams;
DROP TRIGGER IF EXISTS learning_resources_status_transition ON learning_resources;
DROP FUNCTION IF EXISTS validate_content_review_submission();
DROP FUNCTION IF EXISTS require_content_review_before_publication();
DROP FUNCTION IF EXISTS enforce_content_status_transition();
ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_publication_timestamp_check;
ALTER TABLE past_exams DROP CONSTRAINT IF EXISTS past_exams_publication_timestamp_check;
ALTER TABLE learning_resources DROP CONSTRAINT IF EXISTS learning_resources_publication_timestamp_check;
ALTER TABLE questions DROP CONSTRAINT IF EXISTS questions_review_provenance_check;
ALTER TABLE past_exams DROP CONSTRAINT IF EXISTS past_exams_review_provenance_check;
ALTER TABLE learning_resources DROP CONSTRAINT IF EXISTS learning_resources_review_provenance_check;
ALTER TABLE questions DROP COLUMN IF EXISTS published_at;
ALTER TABLE questions DROP COLUMN IF EXISTS copyright_license;
ALTER TABLE questions DROP COLUMN IF EXISTS source_owner;
ALTER TABLE questions DROP COLUMN IF EXISTS source_reference;
ALTER TABLE past_exams DROP COLUMN IF EXISTS source_reference;
ALTER TABLE learning_resources DROP COLUMN IF EXISTS source_reference;
