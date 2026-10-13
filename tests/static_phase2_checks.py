#!/usr/bin/env python3
"""Static invariants for the academic structure/search phase."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
BASE = "codex/phase-1-foundation-security"
checks: list[tuple[str, bool]] = []

def check(name: str, ok: bool) -> None:
    checks.append((name, ok))
    print(f"{'PASS' if ok else 'FAIL'} {name}")

for migration in ("001_initial_schema.sql", "002_seed_access_control.sql", "003_seed_initial_academic_structure.sql", "004_auth_security.sql"):
    path = f"database/migrations/{migration}"
    baseline = subprocess.run(["git", "show", f"{BASE}:{path}"], cwd=ROOT, capture_output=True, check=False)
    current = (ROOT / path).read_bytes() if (ROOT / path).exists() else b""
    check(f"migration unchanged: {migration}", baseline.returncode == 0 and baseline.stdout == current)

m005 = (ROOT / "database/migrations/005_academic_management.sql").read_text()
service = (ROOT / "app/Services/AcademicService.php").read_text()
controller = (ROOT / "app/Http/Controllers/AcademicController.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
api = (ROOT / "docs/API_CONTRACT.md").read_text()
trace = (ROOT / "docs/TRACEABILITY_PHASE_2.md").read_text()
check("phase 2 adds no assumed middle school grades", "INSERT INTO classes" not in m005 and "OD-19" in api)
check("assignment permission is granted only to admin", "r.code = 'admin' AND p.code = 'teaching_assignments.manage'" in m005)
check("academic APIs are routed", all(route in router for route in ("/api/v1/academics/stages", "/api/v1/academics/classes", "/api/v1/academics/subjects", "/api/v1/academics/lessons", "/api/v1/content/search")))
check("mutation endpoints enforce academic and assignment permissions", "require_permission('academics.manage')" in controller and "require_permission('teaching_assignments.manage')" in controller)
check("teacher role and active employee are checked on assignment", "r.code = 'teacher'" in service and "e.is_active = TRUE" in service)
check("search filters stage, class, subject and lesson", all(key in service for key in ("'stage_id' => 'st.id'", "'class_id' => 'c.id'", "'subject_id' => 's.id'", "'lesson_id' => 'l.id'")))
check("search results do not select private storage keys", "storage_key" not in service[service.index("function academic_search_resources"):service.index("function academic_list_lessons")])
check("mutations use prepared statements and transactions", "->prepare(" in service and "beginTransaction()" in service and "->query(" not in service)
check("audit log is in academic transactions", "audit_log(" in service)
check("no frontend files were modified", subprocess.run(["git", "diff", "--quiet", BASE, "--", "app/Views", "public/assets"], cwd=ROOT).returncode == 0)
check("SRS requirements linked to tests", all(req in trace for req in ("FR-004", "FR-005", "FR-006", "FR-008", "FR-029")) and "phase2_academic_integration.php" in trace)

failed = [name for name, ok in checks if not ok]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(1 if failed else 0)
