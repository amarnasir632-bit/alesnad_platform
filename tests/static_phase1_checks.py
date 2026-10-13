#!/usr/bin/env python3
"""Repository-level invariants for phase 1; these complement PHP/PG integration tests."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
checks: list[tuple[str, bool]] = []

def check(name: str, condition: bool) -> None:
    checks.append((name, condition))
    print(f"{'PASS' if condition else 'FAIL'} {name}")

for migration in ("001_initial_schema.sql", "002_seed_access_control.sql", "003_seed_initial_academic_structure.sql"):
    path = f"database/migrations/{migration}"
    baseline = subprocess.run(["git", "show", f"origin/main:{path}"], cwd=ROOT, capture_output=True, check=False)
    current = (ROOT / path).read_bytes() if (ROOT / path).exists() else b""
    check(f"legacy migration unchanged: {migration}", baseline.returncode == 0 and baseline.stdout == current)

router = (ROOT / "app/Http/Router.php").read_text()
authz = (ROOT / "app/Security/authorization.php").read_text()
accounts = (ROOT / "app/Services/AccountService.php").read_text()
auth = (ROOT / "app/Auth/auth.php").read_text()
check("API routes are dispatched separately from web controller", "handle_web_request();" in router and "/api/v1/admin/users" in router)
check("authorization queries role_permissions", "role_permissions" in authz and "function has_permission" in authz)
check("role guard and permission guard exist", "function require_role" in authz and "function require_permission" in authz)
check("last active administrator is protected", "ensure_not_last_admin" in accounts and "DomainException" in accounts)
check("session user is refreshed from database", "function refresh_authenticated_user" in authz and "is_active" in authz)
check("login, registration, and account events are audited", all(event in auth for event in ("auth.login.failed", "auth.login.succeeded", "auth.registered")) and "audit_log('account.created'" in accounts)
check("password policy caps at 72 bytes", "strlen($password) > 72" in (ROOT / "app/Security/passwords.php").read_text())
policy_prefixes = ("SESSION_IDLE_TIMEOUT_SECONDS=", "LOGIN_MAX_ATTEMPTS=", "LOGIN_WINDOW_SECONDS=", "LOGIN_LOCKOUT_SECONDS=", "AUTH_THROTTLE_KEY=")
policy_lines = [line for line in (ROOT / ".env.example").read_text().splitlines() if line.startswith(policy_prefixes)]
check("all runtime policy settings are blank in sample env", len(policy_lines) == len(policy_prefixes) and all(line.endswith("=") for line in policy_lines))
check("new auth migration creates attempt table", "CREATE TABLE auth_login_attempts" in (ROOT / "database/migrations/004_auth_security.sql").read_text())
check("API contract and traceability are documented", (ROOT / "docs/API_CONTRACT.md").exists() and (ROOT / "docs/TRACEABILITY_PHASE_1.md").exists())
check("frontend views and static assets remain untouched", subprocess.run(["git", "diff", "--quiet", "origin/main", "--", "app/Views", "public/assets"], cwd=ROOT).returncode == 0)
check("application SQL avoids direct PDO query calls", "->query(" not in "\n".join(p.read_text() for p in (ROOT / "app").rglob("*.php")))

failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} static checks, {len(failed)} failures")
sys.exit(1 if failed else 0)
