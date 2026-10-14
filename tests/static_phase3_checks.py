#!/usr/bin/env python3
"""Static invariants for private content workflow and stage-3 API."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
BASE = "origin/main"
checks: list[tuple[str, bool]] = []
def check(name: str, ok: bool) -> None:
    checks.append((name, ok)); print(f"{'PASS' if ok else 'FAIL'} {name}")

def git(*args: str) -> bytes:
    return subprocess.run(["git", *args], cwd=ROOT, capture_output=True, check=False).stdout

for migration in ("001_initial_schema.sql", "002_seed_access_control.sql", "003_seed_initial_academic_structure.sql", "004_auth_security.sql", "005_academic_management.sql"):
    path = f"database/migrations/{migration}"
    baseline = subprocess.run(["git", "show", f"{BASE}:{path}"], cwd=ROOT, capture_output=True, check=False)
    current = (ROOT / path).read_bytes()
    check(f"legacy migration unchanged: {migration}", baseline.returncode == 0 and baseline.stdout == current)
check("session frontend exists and shares the current application layout", (ROOT / "app/Views/sessions.php").exists() and (ROOT / "public/assets/js/sessions.js").exists() and "sessions.js" in (ROOT / "app/Views/layout.php").read_text())
svc = (ROOT / "app/Services/ContentService.php").read_text()
storage = (ROOT / "app/Services/PrivateStorage.php").read_text()
ctrl = (ROOT / "app/Http/Controllers/ContentController.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
mig = (ROOT / "database/migrations/006_content_workflow.sql").read_text()
contract = (ROOT / "docs/API_CONTRACT.md").read_text()
trace = (ROOT / "docs/TRACEABILITY_PHASE_3.md").read_text()
check("private storage path and random keys", "storage/private" in storage and "bin2hex(random_bytes(32))" in storage and "public" in storage)
check("upload size is mandatory and no default is invented", "UPLOAD_MAX_BYTES" in storage and "upload_not_configured" in storage)
check("server-side MIME inspection", "finfo(FILEINFO_MIME_TYPE)" in storage)
check("range support and partial response", "function parse_byte_range" in storage and "206" in storage and "Content-Range" in storage)
check("teacher upload scope checks assignment", "teaching_assignments" in svc and "content_assert_upload_scope" in svc)
check("provenance required before submission", "provenance_required" in svc and "source_reference" in svc)
check("review and publish are transactional", "beginTransaction()" in svc and "INSERT INTO content_reviews" in svc and "published_at = CASE" in svc)
check("self-review is rejected", "self_review_forbidden" in svc and "reviewers cannot review their own content" in mig)
check("approval requires approved review record", "require_content_review_before_publication" in mig and "has_approved_review" in mig)
check("manual access grants and downloadable variants are enforced", "has_manual_content_access" in ctrl and "is_downloadable = TRUE" in svc)
check("private keys are excluded from public records", "unset($record['storage_key']" in svc)
check("content and exam routes are dispatched", all(p in router for p in ("/api/v1/content/resources", "/api/v1/content/past-exams", "/api/v1/content/questions", "/download")))
check("API contract covers upload, review, manual access, and Range", all(p in contract for p in ("POST /api/v1/content/resources", "PENDING_REVIEW", "content_access_required", "Range: bytes")))
check("SRS requirements mapped to verification", all(p in trace for p in ("FR-007", "FR-010", "FR-011", "FR-012", "FR-013", "FR-023", "FR-024", "NFR-003")))
failed = [name for name, ok in checks if not ok]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(bool(failed))
