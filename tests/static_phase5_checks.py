#!/usr/bin/env python3
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
failures = []
checks = 0


def check(name, condition):
    global checks
    checks += 1
    if condition:
        print(f"PASS {name}")
    else:
        failures.append(name)
        print(f"FAIL {name}")


service = (ROOT / "app/Services/SessionMessagingService.php").read_text()
controller = (ROOT / "app/Http/Controllers/SessionMessagingController.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
migration = (ROOT / "database/migrations/008_sessions_messaging.sql").read_text()
contract = (ROOT / "docs/API_CONTRACT.md").read_text()
open_decisions = (ROOT / "docs/OPEN_DECISIONS.md").read_text()
context = (ROOT / "docs/PROJECT_CONTEXT.md").read_text()
provider = (ROOT / "app/Services/LiveMeetingProvider.php").read_text()
env = (ROOT / ".env.example").read_text()

check("new migration only", all((ROOT / f"database/migrations/{n:03d}_{name}.sql").exists() for n, name in [(1, "initial_schema"), (2, "seed_access_control"), (3, "seed_initial_academic_structure"), (4, "auth_security"), (5, "academic_management"), (6, "content_workflow"), (7, "quizzes_and_attempts"), (8, "sessions_messaging")]))
check("recording source check", "storage_key IS NOT NULL OR provider_recording_ref IS NOT NULL" in migration)
check("teacher assignment enforced in application and database", "teaching_assignments" in service and "live_session_assignment_guard" in migration)
check("session join returns URL only from protected path", "meeting_url' => $joinUrl" in service and "meeting_url" not in service[service.index("function sm_list_sessions"):service.index("function sm_join_session")])
check("student session join checks time and manual content access", "session_not_started" in service and "session_ended" in service and "has_manual_content_access" in service)
check("Cloudflare Stream adapter, signed URLs and recording sync are implemented", "class CloudflareStreamProvider" in provider and "function signedToken" in provider and "listReadyRecordings" in provider and "CLOUDFLARE_API_TOKEN" in provider)
check("recordings wait for end and available_from", "available_from <= CURRENT_TIMESTAMP" in service and "recording_not_available" in service)
check("pair messaging uses active student class and teaching assignment", "students st" in service and "teaching_assignments ta" in service and "message_peer_not_linked" in service)
check("read status and participant-specific soft deletion", "read_at = COALESCE" in service and "sender_deleted_at" in service and "receiver_deleted_at" in service)
check("message rate limiting requires environment values", "MESSAGING_RATE_LIMIT_COUNT" in service and "MESSAGING_RATE_LIMIT_WINDOW_SECONDS" in service and "MESSAGING_RATE_LIMIT_COUNT=" in env and "OD-23" in open_decisions)
check("all phase-5 routes registered", all(route in router for route in ["/api/v1/sessions", "/api/v1/messages", "/api/v1/session-recordings/"]))
check("API contract includes phase-5 routes and policies", "المرحلة الخامسة" in contract and "/api/v1/sessions/{id}/join" in contract and "/api/v1/teacher/sessions/{id}/stream" in contract and "لا توجد مجموعات" in contract)
check("project context describes phase 5", "حالة المرحلة الخامسة" in context and "008_sessions_messaging.sql" in context)

layout = (ROOT / "app/Views/layout.php").read_text()
session_view = (ROOT / "app/Views/sessions.php").read_text()
session_script = (ROOT / "public/assets/js/sessions.js").read_text()
legacy_controller = (ROOT / "app/Http/LegacyWebController.php").read_text()
check(
    "session page is wired to the API",
    "($page ?? '') === 'sessions'" in layout
    and '/assets/js/sessions.js' in layout
    and 'id="session-list"' in session_view
    and "api('/api/v1/teacher/sessions', 'POST', values)" in session_script
    and "handle_web_request" in legacy_controller
    and "sm_list_sessions($user)" in legacy_controller,
)

try:
    baseline = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    for i in range(1, 8):
        name = next((ROOT / "database/migrations").glob(f"{i:03d}_*.sql")).name
        same = subprocess.run(["git", "diff", "--quiet", baseline, "--", f"database/migrations/{name}"], cwd=ROOT).returncode == 0
        check(f"prior migration unchanged: {name}", same)
except (subprocess.CalledProcessError, StopIteration):
    check("phase-4 baseline available", False)

print(f"{checks} checks, {len(failures)} failures")
sys.exit(1 if failures else 0)
