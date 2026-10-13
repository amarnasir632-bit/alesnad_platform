#!/usr/bin/env python3
from pathlib import Path
import re
import subprocess
import sys
from zipfile import ZipFile
from xml.etree import ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
# Pin the phase-7 baseline; CI does not guarantee stacked branch refs exist.
BASELINE = "e4888141e5c415d513d15e4836fdf8fe26895fb8"
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
probe_pattern = re.compile(r"\['(GET|POST|PATCH|PUT|DELETE)',\s*'([^']+)'(\s*\.\s*\$[A-Za-z_]\w*)?")
probes = []
for match in probe_pattern.finditer(endpoint_test):
    path = match.group(2).split("?", 1)[0]
    if match.group(3) and path.endswith("/"):
        path += "1"
    probes.append((match.group(1), path))
route_specs = []
for match in re.finditer(r"\$route\s*===\s*'((?:GET|POST|PUT|PATCH|DELETE) /api/v1/[^']+)'", (ROOT / "app/Http/Router.php").read_text()):
    route_specs.append((match.group(1), False))
for match in re.finditer(r"preg_match\('#\^((?:GET|POST|PUT|PATCH|DELETE)) (.*?)\$#',\s*\$route", (ROOT / "app/Http/Router.php").read_text()):
    route_specs.append((match.group(1) + " " + match.group(2), True))
missing_probes = []
for spec, is_pattern in route_specs:
    method, path = spec.split(" ", 1)
    if spec in ("GET /api/v1/csrf", "POST /api/v1/auth/login", "POST /api/v1/auth/register"):
        continue
    if spec in ("GET /api/v1/auth/me", "POST /api/v1/auth/logout"):
        if spec == "GET /api/v1/auth/me" and "GET /api/v1/auth/me rejects an anonymous request" in endpoint_test:
            continue
        if spec == "POST /api/v1/auth/logout" and "POST /api/v1/auth/logout rejects an anonymous request" in endpoint_test:
            continue
        missing_probes.append(spec)
    elif not any(m == method and (re.fullmatch(path, p) if is_pattern else path == p) for m, p in probes):
        missing_probes.append(spec)
check("every protected router endpoint has an authorization rejection probe", not missing_probes and "receives 403" in endpoint_test and "foreach ($cases as $case)" in endpoint_test)
check("authorized and denied HTTP role probes cover the endpoint manifest", len(probes) >= 65 and "receives 403" in endpoint_test and "reaches endpoint" in endpoint_test)
check("authentication endpoint and health endpoint are exercised over HTTP", "GET /api/v1/auth/me accepts" in endpoint_test and "GET /health returns healthy" in endpoint_test)
check("direct access to private storage is tested over HTTP", "private file cannot be fetched by direct public URL" in endpoint_test)
check("backup and restore integration test invokes both commands and verifies restored state", "database/backup.php" in backup_test and "database/restore.php" in backup_test and "RESTORED" in backup_test)
check("GitHub Actions provisions PostgreSQL 16 and PHP 8.4 with pdo_pgsql", "image: postgres:16" in workflow and "php-version: '8.4'" in workflow and "pdo_pgsql" in workflow)
check("GitHub Actions applies migrations and executes test suites", "php database/migrate.php" in workflow and "phase1_integration.php" in workflow and "phase2_academic_integration.php" in workflow and "protected_endpoints_integration.php" in workflow and "backup_restore_integration.php" in workflow)
check("deferred and unimplemented requirement statuses are explicit", all(any(token in matrix_rows.get(req, [])[3] for token in tokens) for req, tokens in {
    "FR-022": ("غير منفذ",), "FR-025": ("مؤجل", "غير منفذ"), "FR-026": ("مؤجل", "غير منفذ"),
    "FR-027": ("غير منفذ",), "FR-028": ("غير منفذ",), "NFR-004": ("غير قابل للقياس",), "NFR-005": ("غير قابل للقياس",),
}.items()))
check("Arabic RTL layout declaration is covered", 'lang="ar" dir="rtl"' in layout and "NFR-008" in trace)
check("stale claims that backups and restore are not implemented were removed", all(stale not in context for stale in ("لا يوجد بعد رفع ملفات أو تدفق نسخ احتياطي", "النسخ الاحتياطي والاستعادة ما زال بحاجة إلى اعتماد وتنفيذ", "الاستعادة لم تنفذ")))
check("README documents the test command and CI limitations", "GitHub Actions" in readme and "TRACEABILITY.md" in readme and "لا يشمل اختبارًا فعليًا" in readme)
pr_template = ROOT / ".github/pull_request_template.md"
check("pull request template requires implemented work, remaining work, and open decisions", pr_template.is_file() and all(section in pr_template.read_text() for section in ("ما نُفذ", "ما بقي", "القرارات المعلقة")))

tracked = subprocess.run(["git", "ls-files", "-z"], cwd=ROOT, capture_output=True, check=True).stdout.decode().split("\0")
tracked = [path for path in tracked if path]
private_or_secret = []
for path in tracked:
    name = Path(path).name.lower()
    if (path == ".env" or (path.startswith(".env.") and path != ".env.example")
            or (path.startswith(("storage/private/", "storage/backups/")) and name != ".gitkeep")
            or name.endswith((".dump", ".dump.gz", ".sql.gz", ".bkp", ".backup"))
            or name in {"id_rsa", "id_ed25519", "credentials.json", "service-account.json"}):
        private_or_secret.append(path)
check("Git index contains no environment secrets, private files, or database dumps", not private_or_secret)

for migration in sorted((ROOT / "database/migrations").glob("*.sql")):
    result = subprocess.run(["git", "diff", "--quiet", BASELINE, "HEAD", "--", f"database/migrations/{migration.name}"], cwd=ROOT)
    check(f"existing migration unchanged: {migration.name}", result.returncode == 0)
frontend = subprocess.run(["git", "diff", "--quiet", BASELINE, "HEAD", "--", "app/Views", "public/assets"], cwd=ROOT)
check("frontend ownership preserved", frontend.returncode == 0)

failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(bool(failed))
