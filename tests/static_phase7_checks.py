#!/usr/bin/env python3
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
checks = []


def check(name, passed):
    checks.append((name, bool(passed)))
    print(f"{'PASS' if passed else 'FAIL'} {name}")


migrate = (ROOT / "database/migrate.php").read_text()
backup = (ROOT / "database/backup.php").read_text()
restore = (ROOT / "database/restore.php").read_text()
common = (ROOT / "database/backup_common.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
logger = (ROOT / "app/Support/logger.php").read_text()
docs = (ROOT / "docs/OPERATIONS_BACKUP_RESTORE.md").read_text()
trace = (ROOT / "docs/TRACEABILITY_PHASE_7.md").read_text()

check("migration runner obtains PostgreSQL advisory lock", "pg_advisory_lock(735891, 1)" in migrate and "pg_advisory_unlock(735891, 1)" in migrate)
check("applied migration checksums are saved and validated", "checksum_sha256" in migrate and "hash_equals" in migrate and "hash('sha256', $sql)" in migrate)
check("each new migration is applied with a transaction", "beginTransaction()" in migrate and "commit()" in migrate and "rollBack()" in migrate)
check("backup uses pg_dump and private storage", "pg_dump" in backup and "storage/backups" in common and "chmod($directory, 0700)" in common)
check("backup records include checksum, size, and status", all(value in backup for value in ("checksum_sha256", "size_bytes", "VERIFIED", "backup_records")))
check("PostgreSQL password is passed through a protected pgpass file", "PGPASSFILE" in common and "chmod($path, 0600)" in common and "DB_PASSWORD" in common)
check("restore requires verified record and matching checksum", "status'] !== 'VERIFIED'" in restore and "hash_equals" in restore)
check("restore requires explicit database-name confirmation", "confirm-target" in restore and "--single-transaction" in restore and "--clean" in restore and "Restore target database name is invalid." in restore)
check("restore verifies metadata in source and writes result to explicit target", "RESTORE_TARGET_DATABASE" in restore and "backup_target_database_connection($targetDatabase)" in restore)
check("health endpoint checks DB and reveals only a generic state", "GET /health" in docs and "database_connection()->query('SELECT 1')" in router and "health_database_check_failed" in router)
check("error logger does not log exception messages", "getMessage" not in logger and "exception" in logger and "error_log" in logger)
check("backup restore rehearsal is documented without claiming it ran", "تجربة استعادة معزولة" in docs and "لا تعتبر وجود هذا الإجراء دليلًا" in docs)
check("phase 7 traceability covers all requested controls", all(value in trace for value in ("NFR-007", "NFR-009", "NFR-011", "تجربة استعادة")))
check("backup artifacts remain excluded from Git", "/storage/backups/*" in (ROOT / ".gitignore").read_text())

baseline = "HEAD"
prior = sorted((ROOT / "database/migrations").glob("*.sql"))
for migration in prior:
    result = subprocess.run(["git", "diff", "--quiet", baseline, "--", f"database/migrations/{migration.name}"], cwd=ROOT)
    check(f"existing migration unchanged: {migration.name}", result.returncode == 0)
check("session page does not expose Stream credentials in rendered markup", "stream_key" not in (ROOT / "app/Views/sessions.php").read_text() and "stream_key" in (ROOT / "public/assets/js/sessions.js").read_text())

failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(bool(failed))
