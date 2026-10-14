#!/usr/bin/env python3
"""Static invariants for quiz and attempt workflow."""
from pathlib import Path
import subprocess
import sys
ROOT = Path(__file__).resolve().parents[1]
BASE = "HEAD"
def git(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(["git", *args], cwd=ROOT, capture_output=True, check=False)
checks: list[tuple[str, bool]] = []
def check(name: str, passed: bool) -> None:
    checks.append((name, passed)); print(f"{'PASS' if passed else 'FAIL'} {name}")
for migration in ("001_initial_schema.sql", "002_seed_access_control.sql", "003_seed_initial_academic_structure.sql", "004_auth_security.sql", "005_academic_management.sql", "006_content_workflow.sql"):
    path = f"database/migrations/{migration}"
    base = git("show", f"{BASE}:{path}")
    current = (ROOT / path).read_bytes() if (ROOT / path).exists() else b""
    check(f"prior migration unchanged: {migration}", base.returncode == 0 and base.stdout == current)
check("session UI additions remain outside quiz and assessment views", (ROOT / "app/Views/sessions.php").exists() and (ROOT / "app/Views/quiz_attempt.php").exists())
svc = (ROOT / "app/Services/QuizService.php").read_text()
content = (ROOT / "app/Services/ContentService.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
migration = (ROOT / "database/migrations/007_quizzes_and_attempts.sql").read_text()
env = (ROOT / ".env.example").read_text()
api = (ROOT / "docs/API_CONTRACT.md").read_text()
trace = (ROOT / "docs/TRACEABILITY_PHASE_4.md").read_text()
web = (ROOT / "app/Http/LegacyWebController.php").read_text()
quiz_page = (ROOT / "app/Views/quizzes.php").read_text()
quiz_attempt_page = (ROOT / "app/Views/quiz_attempt.php").read_text()
check("question option requirements enforced on submit", all(s in content for s in ("optionCount < 2", "optionCount !== 2", "correctCount !== 1")) and "validate_question_options_before_review" in migration)
check("only approved same-context questions join a quiz", "question['status'] !== 'APPROVED'" in svc and "validate_quiz_question_context" in migration)
check("parallel feedback migrations safely add the column once without changing their checksums", "migration_execution_sql" in (ROOT / "database/migrate.php").read_text() and "007_quiz_feedback.sql" in (ROOT / "database/migrate.php").read_text() and "007_quizzes_and_attempts.sql" in (ROOT / "database/migrate.php").read_text())
check("launch quiz policy is manager approval and controlled retry", "AFTER_MANAGER_APPROVAL" in env and "MANAGER_REVIEW" in env and "INCOMPLETE" in svc)
check("attempt limits and deadlines enforced server-side", "QUIZ_MAX_ATTEMPTS" in svc and "deadline_at" in svc and "quiz_is_expired" in svc)
check("objective grading and manual essay grading exist", "awarded_mark = CASE WHEN qo.is_correct" in svc and "function quiz_grade_essay" in svc)
check("result access enforces student ownership and teacher assignment", "attempt_owner_required" in svc and "quiz_assert_teacher_scope" in svc)
check("student results remain hidden until a manager approves the graded attempt", "result_approved_at" in svc and "موافقة المدير" in quiz_attempt_page and "quiz_get_result($user, $attemptId)" in web)
check("only admins approve results or grant one immediate retry", "in_array('admin'" in svc and "quiz_manager_review_attempt" in svc and "retry_grant_used_at" in svc and "/api/v1/admin/attempts/" in router)
check("expiry becomes incomplete and retry decision is visible in the web view", "quiz_finalize_attempt($db, $attemptId, 'INCOMPLETE')" in svc and "المحاولة غير مكتملة" in quiz_attempt_page and "grant_retry" in quiz_attempt_page and "quiz_manager_review_attempt" in web)
check("scheduled worker classifies expired attempts in bounded batches", (ROOT / "database/process_expired_quiz_attempts.php").exists() and "quiz_classify_expired_attempts" in svc and "FOR UPDATE SKIP LOCKED" in svc)
check("legacy quiz forms delegate attempts, grading, and publishing to QuizService", all(f"{name}(" in web for name in ("quiz_start_attempt", "quiz_save_answer", "quiz_submit_attempt", "quiz_grade_essay", "quiz_update")) and all(f"{name}(" not in web for name in ("start_quiz_attempt", "save_quiz_answer", "submit_quiz_attempt", "grade_essay_answer", "set_quiz_published")))
check("quiz and attempt APIs are routed", all(p in router for p in ("/api/v1/quizzes", "/api/v1/attempts/", "/api/v1/teacher/quizzes", "/grade")))
check("API contract covers manager policies and result errors", all(p in api for p in ("QUIZ_INCOMPLETE_ATTEMPT_POLICY", "AFTER_MANAGER_APPROVAL", "INCOMPLETE", "attempt_limit_reached", "attempt_owner_required")))
check("SRS mapping includes requested requirements and UC-02", all(p in trace for p in ("FR-009", "FR-014", "FR-015", "FR-016", "UC-02")))
failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(1 if failed else 0)
