-- Phase 3: provenance, review workflow, and database guards for content publication.
ALTER TABLE learning_resources ADD COLUMN source_reference TEXT;
ALTER TABLE past_exams ADD COLUMN source_reference TEXT;
ALTER TABLE questions ADD COLUMN source_reference TEXT;
ALTER TABLE questions ADD COLUMN source_owner VARCHAR(200);
ALTER TABLE questions ADD COLUMN copyright_license VARCHAR(200);
ALTER TABLE questions ADD COLUMN published_at TIMESTAMPTZ;

-- Existing rows are not revalidated automatically. Every new or updated row must satisfy these rules.
ALTER TABLE learning_resources
    ADD CONSTRAINT learning_resources_review_provenance_check
    CHECK (
        status NOT IN ('PENDING_REVIEW', 'APPROVED')
        OR (
            NULLIF(btrim(source_reference), '') IS NOT NULL
            AND NULLIF(btrim(source_owner), '') IS NOT NULL
            AND NULLIF(btrim(copyright_license), '') IS NOT NULL
        )
    ) NOT VALID;
ALTER TABLE past_exams
    ADD CONSTRAINT past_exams_review_provenance_check
    CHECK (
        status NOT IN ('PENDING_REVIEW', 'APPROVED')
        OR (
            NULLIF(btrim(source_reference), '') IS NOT NULL
            AND NULLIF(btrim(source_owner), '') IS NOT NULL
            AND NULLIF(btrim(copyright_license), '') IS NOT NULL
        )
    ) NOT VALID;
ALTER TABLE questions
    ADD CONSTRAINT questions_review_provenance_check
    CHECK (
        status NOT IN ('PENDING_REVIEW', 'APPROVED')
        OR (
            NULLIF(btrim(source_reference), '') IS NOT NULL
            AND NULLIF(btrim(source_owner), '') IS NOT NULL
            AND NULLIF(btrim(copyright_license), '') IS NOT NULL
        )
    ) NOT VALID;

ALTER TABLE learning_resources
    ADD CONSTRAINT learning_resources_publication_timestamp_check
    CHECK ((status = 'APPROVED' AND published_at IS NOT NULL) OR (status <> 'APPROVED' AND published_at IS NULL)) NOT VALID;
ALTER TABLE past_exams
    ADD CONSTRAINT past_exams_publication_timestamp_check
    CHECK ((status = 'APPROVED' AND published_at IS NOT NULL) OR (status <> 'APPROVED' AND published_at IS NULL)) NOT VALID;
ALTER TABLE questions
    ADD CONSTRAINT questions_publication_timestamp_check
    CHECK ((status = 'APPROVED' AND published_at IS NOT NULL) OR (status <> 'APPROVED' AND published_at IS NULL)) NOT VALID;

CREATE FUNCTION enforce_content_status_transition() RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'DRAFT' OR NEW.published_at IS NOT NULL THEN
            RAISE EXCEPTION 'content must be created as a draft' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.status = OLD.status THEN
        IF NEW.published_at IS DISTINCT FROM OLD.published_at THEN
            RAISE EXCEPTION 'publication timestamp changes only with a status transition' USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF NOT (
        (OLD.status = 'DRAFT' AND NEW.status = 'PENDING_REVIEW')
        OR (OLD.status = 'REJECTED' AND NEW.status = 'DRAFT')
        OR (OLD.status = 'PENDING_REVIEW' AND NEW.status IN ('APPROVED', 'REJECTED'))
    ) THEN
        RAISE EXCEPTION 'invalid content status transition' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION require_content_review_before_publication() RETURNS TRIGGER AS $$
DECLARE
    target_column TEXT;
    has_approved_review BOOLEAN;
BEGIN
    IF NEW.status = 'APPROVED' THEN
        IF NEW.published_at IS NULL THEN
            RAISE EXCEPTION 'approved content requires published_at' USING ERRCODE = '23514';
        END IF;
        target_column := CASE
            WHEN TG_TABLE_NAME = 'learning_resources' THEN 'resource_id'
            WHEN TG_TABLE_NAME = 'past_exams' THEN 'past_exam_id'
            ELSE 'question_id'
        END;
        EXECUTE format('SELECT EXISTS (SELECT 1 FROM content_reviews WHERE %I = $1 AND status = $2)', target_column)
            INTO has_approved_review USING NEW.id, 'APPROVED';
        IF NOT has_approved_review THEN
            RAISE EXCEPTION 'content approval requires an approved review record' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.published_at IS NOT NULL THEN
        RAISE EXCEPTION 'unapproved content cannot have published_at' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE FUNCTION validate_content_review_submission() RETURNS TRIGGER AS $$
DECLARE
    uploader_id BIGINT;
    content_status VARCHAR(24);
    reviewer_active BOOLEAN;
BEGIN
    IF NEW.resource_id IS NULL AND NEW.past_exam_id IS NULL AND NEW.question_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.status NOT IN ('APPROVED', 'REJECTED') THEN
        RAISE EXCEPTION 'content review outcome must be approved or rejected' USING ERRCODE = '23514';
    END IF;

    SELECT is_active INTO reviewer_active FROM employees WHERE id = NEW.reviewer_id;
    IF reviewer_active IS DISTINCT FROM TRUE THEN
        RAISE EXCEPTION 'reviewer employee must be active' USING ERRCODE = '23514';
    END IF;

    IF NEW.resource_id IS NOT NULL THEN
        SELECT uploaded_by, status INTO uploader_id, content_status FROM learning_resources WHERE id = NEW.resource_id FOR UPDATE;
    ELSIF NEW.past_exam_id IS NOT NULL THEN
        SELECT uploaded_by, status INTO uploader_id, content_status FROM past_exams WHERE id = NEW.past_exam_id FOR UPDATE;
    ELSE
        SELECT created_by, status INTO uploader_id, content_status FROM questions WHERE id = NEW.question_id FOR UPDATE;
    END IF;
    IF content_status IS DISTINCT FROM 'PENDING_REVIEW' THEN
        RAISE EXCEPTION 'only pending content can be reviewed' USING ERRCODE = '23514';
    END IF;
    IF uploader_id = NEW.reviewer_id THEN
        RAISE EXCEPTION 'reviewers cannot review their own content' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER learning_resources_status_transition
    BEFORE INSERT OR UPDATE OF status, published_at ON learning_resources
    FOR EACH ROW EXECUTE FUNCTION enforce_content_status_transition();
CREATE TRIGGER past_exams_status_transition
    BEFORE INSERT OR UPDATE OF status, published_at ON past_exams
    FOR EACH ROW EXECUTE FUNCTION enforce_content_status_transition();
CREATE TRIGGER questions_status_transition
    BEFORE INSERT OR UPDATE OF status, published_at ON questions
    FOR EACH ROW EXECUTE FUNCTION enforce_content_status_transition();

CREATE TRIGGER learning_resources_publication_review_guard
    BEFORE INSERT OR UPDATE OF status, published_at ON learning_resources
    FOR EACH ROW EXECUTE FUNCTION require_content_review_before_publication();
CREATE TRIGGER past_exams_publication_review_guard
    BEFORE INSERT OR UPDATE OF status, published_at ON past_exams
    FOR EACH ROW EXECUTE FUNCTION require_content_review_before_publication();
CREATE TRIGGER questions_publication_review_guard
    BEFORE INSERT OR UPDATE OF status, published_at ON questions
    FOR EACH ROW EXECUTE FUNCTION require_content_review_before_publication();

CREATE TRIGGER content_reviews_submission_guard
    BEFORE INSERT ON content_reviews
    FOR EACH ROW EXECUTE FUNCTION validate_content_review_submission();
