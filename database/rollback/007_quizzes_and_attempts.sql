-- Manual rollback for 007_quizzes_and_attempts.sql.
-- Stop the application and back up the database first. This deletes deadlines and manual essay grades/feedback.
DROP TRIGGER IF EXISTS student_answer_context_guard ON student_answers;
DROP TRIGGER IF EXISTS quiz_question_context_guard ON quiz_questions;
DROP TRIGGER IF EXISTS question_options_status_guard ON question_options;
DROP TRIGGER IF EXISTS questions_options_before_review ON questions;
DROP FUNCTION IF EXISTS validate_student_answer_context();
DROP FUNCTION IF EXISTS validate_quiz_question_context();
DROP FUNCTION IF EXISTS protect_reviewed_question_options();
DROP FUNCTION IF EXISTS validate_question_options_before_review();
DROP INDEX IF EXISTS quiz_attempts_deadline_idx;
DROP INDEX IF EXISTS quiz_attempts_quiz_student_idx;
ALTER TABLE student_answers DROP CONSTRAINT IF EXISTS student_answer_manual_grade_check;
ALTER TABLE quiz_attempts DROP CONSTRAINT IF EXISTS quiz_attempt_deadline_check;
ALTER TABLE student_answers DROP COLUMN IF EXISTS graded_at;
ALTER TABLE student_answers DROP COLUMN IF EXISTS graded_by;
ALTER TABLE student_answers DROP COLUMN IF EXISTS teacher_feedback;
ALTER TABLE quiz_attempts DROP COLUMN IF EXISTS deadline_at;
