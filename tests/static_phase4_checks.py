#!/usr/bin/env python3
"""Static invariants for quiz and attempt workflow."""
from pathlib import Path
import subprocess
import sys
ROOT = Path(__file__).resolve().parents[1]
# Pin the baseline commit instead of a remote branch ref. GitHub Actions checks
# out the PR head and does not guarantee that every stacked feature branch ref
# exists in its remote-tracking namespace; this commit is an ancestor of phase 4.
BASE = "f3ed7967c4d1a1b377ded32c836f1d2b507923d9"
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
check("view files and frontend assets untouched", git("diff", "--quiet", BASE, "--", "app/Views", "public/assets").returncode == 0)
svc = (ROOT / "app/Services/QuizService.php").read_text()
content = (ROOT / "app/Services/ContentService.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
migration = (ROOT / "database/migrations/007_quizzes_and_attempts.sql").read_text()
env = (ROOT / ".env.example").read_text()
api = (ROOT / "docs/API_CONTRACT.md").read_text()
trace = (ROOT / "docs/TRACEABILITY_PHASE_4.md").read_text()
check("question option requirements enforced on submit", all(s in content for s in ("optionCount < 2", "optionCount !== 2", "correctCount !== 1")) and "validate_question_options_before_review" in migration)
check("only approved same-context questions join a quiz", "question['status'] !== 'APPROVED'" in svc and "validate_quiz_question_context" in migration)
check("all undecided quiz policies require configuration", all(f"{key}=" in env for key in ("QUIZ_MAX_ATTEMPTS", "QUIZ_DURATION_MINUTES", "QUIZ_RESULT_VISIBILITY", "QUIZ_INCOMPLETE_ATTEMPT_POLICY")) and "quiz_not_configured" in svc)
check("attempt limits and deadlines enforced server-side", "QUIZ_MAX_ATTEMPTS" in svc and "deadline_at" in svc and "quiz_is_expired" in svc)
check("objective grading and manual essay grading exist", "awarded_mark = CASE WHEN qo.is_correct" in svc and "function quiz_grade_essay" in svc)
check("result access enforces student ownership and teacher assignment", "attempt_owner_required" in svc and "quiz_assert_teacher_scope" in svc)
check("quiz and attempt APIs are routed", all(p in router for p in ("/api/v1/quizzes", "/api/v1/attempts/", "/api/v1/teacher/quizzes", "/grade")))
check("API contract covers policies and result errors", all(p in api for p in ("QUIZ_INCOMPLETE_ATTEMPT_POLICY", "AFTER_WINDOW", "attempt_limit_reached", "attempt_owner_required")))
check("SRS mapping includes requested requirements and UC-02", all(p in trace for p in ("FR-009", "FR-014", "FR-015", "FR-016", "UC-02")))
failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(1 if failed else 0)
