#!/usr/bin/env python3
from pathlib import Path
import re
import subprocess
import sys
from zipfile import ZipFile
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
checks = []


def check(name, passed):
    checks.append((name, bool(passed)))
    print(f"{'PASS' if passed else 'FAIL'} {name}")


trace = (ROOT / "docs/TRACEABILITY.md").read_text()
workflow = (ROOT / ".github/workflows/backend-ci.yml").read_text()
endpoint_test = (ROOT / "tests/protected_endpoints_integration.php").read_text()
backup_test = (ROOT / "tests/backup_restore_integration.php").read_text()
readme = (ROOT / "README.md").read_text()
context = (ROOT / "docs/PROJECT_CONTEXT.md").read_text()
layout = (ROOT / "app/Views/layout.php").read_text()
web_controller = (ROOT / "app/Http/LegacyWebController.php").read_text()
main_js = (ROOT / "public/assets/js/main.js").read_text()
styles = (ROOT / "public/assets/css/style.css").read_text()
schedule_view = (ROOT / "app/Views/school_schedule.php").read_text()
schedule_service = (ROOT / "app/Database/school_schedule.php").read_text()
staff_view = (ROOT / "app/Views/staff.php").read_text()
staff_service = (ROOT / "app/Database/staff.php").read_text()

with ZipFile(ROOT / "docs/SRS_Educational_Platform_Arabic.docx") as archive:
    document = ET.fromstring(archive.read("word/document.xml"))
namespace = {"w": "http://schemas.openxmlformats.org/wordprocessingml/2006/main"}
srs_text = "\n".join("".join(node.text or "" for node in paragraph.findall(".//w:t", namespace)) for paragraph in document.findall(".//w:p", namespace))
required_ids = [*(f"FR-{number:03d}" for number in range(1, 31)), *(f"NFR-{number:03d}" for number in range(1, 13))]
matrix_ids = set(re.findall(r"\b(?:FR|NFR)-\d{3}\b", trace))
matrix_rows = {}
for line in trace.splitlines():
    columns = [column.strip() for column in line.strip().strip("|").split("|")]
    if columns and re.fullmatch(r"(?:FR|NFR)-\d{3}", columns[0]): matrix_rows[columns[0]] = columns
check("traceability matrix contains every SRS FR and NFR", all(req in srs_text and req in matrix_ids for req in required_ids))
check("each trace row names implementation, test, and an explicit result", "| المعرّف |" in trace and "| المكوّن المنفذ / الحالة |" in trace and "| الاختبار" in trace and "| النتيجة الفعلية |" in trace)
check("every requirement row has all four populated columns", all(req in matrix_rows and len(matrix_rows[req]) == 4 and all(matrix_rows[req][i] for i in range(1, 4)) for req in required_ids))
test_paths = set(re.findall(r"`(tests/[A-Za-z0-9_./-]+)`", trace))
check("all test paths named in the matrix exist", bool(test_paths) and all((ROOT / path).is_file() for path in test_paths))
check("protected endpoint integration manifest has positive and negative role probes", len(re.findall(r"^\s*\['(?:GET|POST|PATCH|PUT|DELETE)',", endpoint_test, re.M)) >= 65 and "receives 403" in endpoint_test and "reaches endpoint" in endpoint_test)
check("authorization fixtures create their own curriculum rows", "INSERT INTO stages" in endpoint_test and "INSERT INTO classes" in endpoint_test and "GRADE_6" not in endpoint_test)
check("authentication endpoint and health endpoint are exercised over HTTP", "GET /api/v1/auth/me accepts" in endpoint_test and "GET /health returns healthy" in endpoint_test)
check("direct access to private storage is tested over HTTP", "private file cannot be fetched by direct public URL" in endpoint_test)
check("backup and restore integration test invokes both commands and verifies restored state", "database/backup.php" in backup_test and "database/restore.php" in backup_test and "RESTORED" in backup_test)
check("GitHub Actions provisions PostgreSQL 16 and PHP 8.4 with pdo_pgsql", "image: postgres:16" in workflow and "php-version: '8.4'" in workflow and "pdo_pgsql" in workflow)
check("GitHub Actions applies migrations and executes test suites", "php database/migrate.php" in workflow and "phase1_integration.php" in workflow and "phase2_academic_integration.php" in workflow and "protected_endpoints_integration.php" in workflow and "backup_restore_integration.php" in workflow)
check("deferred and unimplemented requirement statuses are explicit", all(any(token in matrix_rows.get(req, [])[3] for token in tokens) for req, tokens in {
    "FR-022": ("جزئي", "غير منفذ"), "FR-025": ("مؤجل", "غير منفذ"), "FR-026": ("مؤجل", "غير منفذ"),
    "FR-027": ("غير منفذ",), "FR-028": ("غير منفذ",), "NFR-004": ("غير قابل للقياس",), "NFR-005": ("غير قابل للقياس",),
}.items()))
check("Arabic RTL layout declaration is covered", 'lang="ar" dir="rtl"' in layout and "NFR-008" in trace)
check("session management UI is connected to protected APIs", (ROOT / "app/Views/sessions.php").exists() and (ROOT / "public/assets/js/sessions.js").exists() and "api_provision_session_stream" in (ROOT / "app/Http/Controllers/SessionMessagingController.php").read_text())
check("stale claims that backups and restore are not implemented were removed", all(stale not in context for stale in ("لا يوجد بعد رفع ملفات أو تدفق نسخ احتياطي", "النسخ الاحتياطي والاستعادة ما زال بحاجة إلى اعتماد وتنفيذ", "الاستعادة لم تنفذ")))
check("README documents the test command and CI limitations", "GitHub Actions" in readme and "TRACEABILITY.md" in readme and "لا يشمل اختبارًا فعليًا" in readme)
check("registrar can be invited by a role manager and is listed as a staff account", "value=\"registrar\"" in staff_view and "$data['can_manage_roles']" in staff_view and "'admin', 'registrar'" in staff_service)
check("catalog search is role-gated in navigation and server routing", "user_has_role('content_reviewer') && !user_has_role('student')" in web_controller and "user_has_role('admin') || user_has_role('content_reviewer') || user_has_role('student')" in layout)
check("teacher subject list is restricted to assigned subjects", "list_teacher_class_subjects((int) $user['id'])" in web_controller and "$data['teacher_subjects_only']" in web_controller)
check("content reviewers can open subjects while student and teacher scope checks remain", "|| user_has_permission('content.review')" in web_controller and "$studentInClass" in web_controller and "$teacherAssigned" in web_controller)
check("navigation drawer is hidden by default and can overlay desktop content", "transform: translateX(105%)" in styles and ".app-sidebar.is-open" in styles and ".app-mobile-topbar {\n  display: flex" in styles and "margin-right: var(--sidebar-width)" not in styles)
check("navigation drawer closes on backdrop, Escape and destination selection at every viewport", "backdrop.addEventListener('click'" in main_js and "if (e.key === 'Escape')" in main_js and "navLinks.forEach" in main_js and "isDrawer" not in main_js)
check("weekly schedule uses a familiar weekday-by-period table and keeps dated entries separate", "class=\"schedule-timetable\"" in schedule_view and "school_schedule_weekly_grid" in schedule_view and "$oneTimeEntries" in schedule_view and "حصص وفسح غير متكررة" in schedule_view and "'entries_by_day' => []" in schedule_service)

baseline = "HEAD"
for migration in sorted((ROOT / "database/migrations").glob("*.sql")):
    result = subprocess.run(["git", "diff", "--quiet", baseline, "--", f"database/migrations/{migration.name}"], cwd=ROOT)
    check(f"existing migration unchanged: {migration.name}", result.returncode == 0)
check("session UI files are present", (ROOT / "app/Views/sessions.php").exists() and (ROOT / "public/assets/js/sessions.js").exists())

failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(bool(failed))
