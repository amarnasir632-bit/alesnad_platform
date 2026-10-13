-- Phase 4: question option validation, timed quiz attempts, and manual essay grading.
ALTER TABLE quiz_attempts ADD COLUMN deadline_at TIMESTAMPTZ;
UPDATE quiz_attempts qa
SET deadline_at = LEAST(qa.started_at + (q.duration_minutes * INTERVAL '1 minute'), q.ends_at)
FROM quizzes q
WHERE qa.quiz_id = q.id AND qa.status = 'IN_PROGRESS' AND q.duration_minutes IS NOT NULL
  AND (q.ends_at IS NULL OR q.ends_at > qa.started_at);
ALTER TABLE quiz_attempts
    ADD CONSTRAINT quiz_attempt_deadline_check
    CHECK (deadline_at IS NULL OR deadline_at > started_at) NOT VALID;

ALTER TABLE student_answers ADD COLUMN teacher_feedback TEXT;
ALTER TABLE student_answers ADD COLUMN graded_by BIGINT REFERENCES employees(id) ON DELETE RESTRICT;
ALTER TABLE student_answers ADD COLUMN graded_at TIMESTAMPTZ;
ALTER TABLE student_answers
    ADD CONSTRAINT student_answer_manual_grade_check
    CHECK ((graded_by IS NULL AND graded_at IS NULL) OR (graded_by IS NOT NULL AND graded_at IS NOT NULL)) NOT VALID;

CREATE INDEX quiz_attempts_deadline_idx ON quiz_attempts (deadline_at) WHERE status = 'IN_PROGRESS';
CREATE INDEX quiz_attempts_quiz_student_idx ON quiz_attempts (quiz_id, student_id, attempt_number DESC);

CREATE FUNCTION validate_question_options_before_review() RETURNS TRIGGER AS $$
DECLARE
    option_count INTEGER;
    correct_count INTEGER;
BEGIN
    IF NEW.status NOT IN ('PENDING_REVIEW', 'APPROVED') THEN RETURN NEW; END IF;
    SELECT count(*), count(*) FILTER (WHERE is_correct)
      INTO option_count, correct_count
      FROM question_options WHERE question_id = NEW.id;
    IF NEW.question_type IN ('MCQ', 'TRUE_FALSE') THEN
        IF correct_count <> 1 THEN
            RAISE EXCEPTION 'objective questions require exactly one correct option' USING ERRCODE = '23514';
        END IF;
        IF NEW.question_type = 'MCQ' AND option_count < 2 THEN
            RAISE EXCEPTION 'MCQ requires at least two options' USING ERRCODE = '23514';
        END IF;
        IF NEW.question_type = 'TRUE_FALSE' AND option_count <> 2 THEN
            RAISE EXCEPTION 'true/false questions require exactly two options' USING ERRCODE = '23514';
        END IF;
    ELSIF option_count <> 0 THEN
        RAISE EXCEPTION 'essay questions cannot have selectable options' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER questions_options_before_review
    BEFORE UPDATE OF status ON questions
    FOR EACH ROW EXECUTE FUNCTION validate_question_options_before_review();

CREATE FUNCTION protect_reviewed_question_options() RETURNS TRIGGER AS $$
DECLARE
    parent_id BIGINT;
    parent_status VARCHAR(24);
BEGIN
    IF TG_OP = 'DELETE' THEN
        parent_id := OLD.question_id;
        SELECT status INTO parent_status FROM questions WHERE id = parent_id;
        IF parent_status IN ('PENDING_REVIEW', 'APPROVED') THEN
            RAISE EXCEPTION 'options of reviewed questions cannot be changed' USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    ELSIF TG_OP = 'UPDATE' THEN
        SELECT status INTO parent_status FROM questions WHERE id = OLD.question_id;
        IF parent_status IN ('PENDING_REVIEW', 'APPROVED') THEN
            RAISE EXCEPTION 'options of reviewed questions cannot be changed' USING ERRCODE = '23514';
        END IF;
    END IF;
    parent_id := NEW.question_id;
    SELECT status INTO parent_status FROM questions WHERE id = parent_id;
    IF parent_status IN ('PENDING_REVIEW', 'APPROVED') THEN
        RAISE EXCEPTION 'options of reviewed questions cannot be changed' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER question_options_status_guard
    BEFORE INSERT OR UPDATE OR DELETE ON question_options
    FOR EACH ROW EXECUTE FUNCTION protect_reviewed_question_options();

CREATE FUNCTION validate_quiz_question_context() RETURNS TRIGGER AS $$
DECLARE
    quiz_context BIGINT;
    question_context BIGINT;
    question_status VARCHAR(24);
    question_type_value VARCHAR(20);
    option_count INTEGER;
    correct_count INTEGER;
BEGIN
    SELECT class_subject_id INTO quiz_context FROM quizzes WHERE id = NEW.quiz_id FOR SHARE;
    SELECT class_subject_id, status, question_type INTO question_context, question_status, question_type_value FROM questions WHERE id = NEW.question_id FOR SHARE;
    IF quiz_context IS NULL OR question_context IS NULL OR quiz_context <> question_context THEN
        RAISE EXCEPTION 'quiz question must have the same academic context' USING ERRCODE = '23514';
    END IF;
    IF question_status <> 'APPROVED' THEN
        RAISE EXCEPTION 'only approved questions can be included in a quiz' USING ERRCODE = '23514';
    END IF;
    SELECT count(*), count(*) FILTER (WHERE is_correct)
      INTO option_count, correct_count FROM question_options WHERE question_id = NEW.question_id;
    IF question_type_value IN ('MCQ', 'TRUE_FALSE') AND correct_count <> 1 THEN
        RAISE EXCEPTION 'objective quiz question requires exactly one correct option' USING ERRCODE = '23514';
    END IF;
    IF (question_type_value = 'MCQ' AND option_count < 2)
       OR (question_type_value = 'TRUE_FALSE' AND option_count <> 2)
       OR (question_type_value = 'ESSAY' AND option_count <> 0) THEN
        RAISE EXCEPTION 'quiz question options do not match the question type' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER quiz_question_context_guard
    BEFORE INSERT OR UPDATE ON quiz_questions
    FOR EACH ROW EXECUTE FUNCTION validate_quiz_question_context();

CREATE FUNCTION validate_student_answer_context() RETURNS TRIGGER AS $$
DECLARE
    quiz_id_value BIGINT;
    attempt_status_value VARCHAR(20);
    question_type_value VARCHAR(20);
BEGIN
    SELECT qa.quiz_id, qa.status INTO quiz_id_value, attempt_status_value
      FROM quiz_attempts qa WHERE qa.id = NEW.attempt_id FOR SHARE;
    SELECT q.question_type INTO question_type_value
      FROM quiz_questions qq JOIN questions q ON q.id = qq.question_id
      WHERE qq.quiz_id = quiz_id_value AND qq.question_id = NEW.question_id;
    IF question_type_value IS NULL OR attempt_status_value <> 'IN_PROGRESS' THEN
        RAISE EXCEPTION 'answer must belong to an in-progress quiz attempt' USING ERRCODE = '23514';
    END IF;
    IF NEW.selected_option_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM question_options qo WHERE qo.id = NEW.selected_option_id AND qo.question_id = NEW.question_id
    ) THEN
        RAISE EXCEPTION 'selected option does not belong to the question' USING ERRCODE = '23514';
    END IF;
    IF (question_type_value = 'ESSAY' AND (NEW.selected_option_id IS NOT NULL OR NEW.essay_answer IS NULL))
       OR (question_type_value IN ('MCQ', 'TRUE_FALSE') AND (NEW.selected_option_id IS NULL OR NEW.essay_answer IS NOT NULL)) THEN
        RAISE EXCEPTION 'answer type does not match the question' USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER student_answer_context_guard
    BEFORE INSERT OR UPDATE OF attempt_id, question_id, selected_option_id, essay_answer ON student_answers
    FOR EACH ROW EXECUTE FUNCTION validate_student_answer_context();
