-- Manager approval for individual quiz results and one controlled retry.
ALTER TABLE quiz_attempts DROP CONSTRAINT quiz_attempts_status_check;
ALTER TABLE quiz_attempts
    ADD CONSTRAINT quiz_attempts_status_check
        CHECK (status IN ('IN_PROGRESS', 'SUBMITTED', 'GRADED', 'INCOMPLETE', 'CANCELLED')),
    ADD COLUMN result_approved_at TIMESTAMPTZ,
    ADD COLUMN result_approved_by BIGINT REFERENCES employees(id) ON DELETE RESTRICT,
    ADD COLUMN retry_granted_at TIMESTAMPTZ,
    ADD COLUMN retry_granted_by BIGINT REFERENCES employees(id) ON DELETE RESTRICT,
    ADD COLUMN retry_grant_used_at TIMESTAMPTZ,
    ADD CONSTRAINT quiz_attempt_result_approval_pair_check
        CHECK ((result_approved_at IS NULL) = (result_approved_by IS NULL)),
    ADD CONSTRAINT quiz_attempt_retry_grant_pair_check
        CHECK ((retry_granted_at IS NULL) = (retry_granted_by IS NULL)),
    ADD CONSTRAINT quiz_attempt_retry_used_requires_grant_check
        CHECK (retry_grant_used_at IS NULL OR retry_granted_at IS NOT NULL);

CREATE INDEX quiz_attempts_pending_approval_idx ON quiz_attempts (quiz_id, submitted_at)
    WHERE status = 'GRADED' AND result_approved_at IS NULL;
CREATE INDEX quiz_attempts_retry_grant_idx ON quiz_attempts (quiz_id, student_id)
    WHERE retry_granted_at IS NOT NULL AND retry_grant_used_at IS NULL;
