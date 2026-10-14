#!/usr/bin/env python3
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
checks = []


def check(name, passed):
    checks.append((name, passed))
    print(f"{'PASS' if passed else 'FAIL'} {name}")


service = (ROOT / "app/Services/SubscriptionService.php").read_text()
router = (ROOT / "app/Http/Router.php").read_text()
controller = (ROOT / "app/Http/Controllers/SubscriptionController.php").read_text()
content = (ROOT / "app/Http/Controllers/ContentController.php").read_text()
quiz = (ROOT / "app/Services/QuizService.php").read_text()
sessions = (ROOT / "app/Services/SessionMessagingService.php").read_text()
migration = (ROOT / "database/migrations/009_subscription_management.sql").read_text()
rollback = (ROOT / "database/rollback/009_subscription_management.sql").read_text()
context = (ROOT / "docs/PROJECT_CONTEXT.md").read_text()
api = (ROOT / "docs/API_CONTRACT.md").read_text()
trace = (ROOT / "docs/TRACEABILITY_PHASE_6.md").read_text()
env = (ROOT / ".env.example").read_text()

check("plan CRUD and manual subscription administration exist", all(x in service for x in ("subscription_create_plan", "subscription_update_plan", "subscription_create_manual", "subscription_update_manual", "subscription_list_admin")))
check("no plan price or currency is invented", "price' => null" not in service and "currency' => '" not in service and "price" in service and "currency" in service)
check("plan duration is explicitly required and used", "duration_days مطلوب" in service and "duration_days" in service and "days'" in service)
check("manual subscription requires explicit amount, currency, status and start", "amount_paid" in service and "currency" in service and "PENDING', 'ACTIVE" in service and "starts_at" in service)
check("single active-subscription function performs expiry reconciliation", "function has_active_subscription" in service and "SET status = 'EXPIRED'" in service and "ends_at <= CURRENT_TIMESTAMP" in service)
check("release content, quiz and session access use manual content grants", "has_manual_content_access" in content and "has_manual_content_access" in quiz and "has_manual_content_access" in sessions and "function has_manual_content_access" in service)
check("expiration can run through API and CLI", "api_admin_expire_subscriptions" in controller and (ROOT / "database/expire_subscriptions.php").exists())
check("subscription endpoints are registered", all(x in router for x in ("/api/v1/subscription-plans", "/api/v1/subscriptions/me", "/api/v1/admin/subscriptions")))
check("admin operations enforce subscriptions.manage", controller.count("require_permission('subscriptions.manage')") >= 5)
check("no payment gateway or payment mutation was added", "provider" not in service.lower() and "INSERT INTO payments" not in service)
check("migration is additive and rollback is documented", "subscriptions_expiration_queue_idx" in migration and "DROP INDEX IF EXISTS subscriptions_expiration_queue_idx" in rollback)
check("project and API docs map phase 6", "حالة المرحلة السادسة" in context and "الاشتراكات (المرحلة السادسة)" in api and "FR-007" in trace)
check("price and entitlement decisions remain documented", "OD-05" in trace and "OD-06" in trace and "لا أسعار" in trace)
check("no subscription policy values added to environment", "SUBSCRIPTION_PRICE=" not in env and "SUBSCRIPTION_CURRENCY=" not in env)

baseline = "HEAD"
for i in range(1, 9):
    file = next((ROOT / "database/migrations").glob(f"{i:03d}_*.sql"), None)
    if file is None:
        check(f"migration {i:03d} exists", False)
        continue
    proc = subprocess.run(["git", "diff", "--quiet", baseline, "--", f"database/migrations/{file.name}"], cwd=ROOT)
    check(f"prior migration unchanged: {file.name}", proc.returncode == 0)
check("session frontend is implemented", (ROOT / "app/Views/sessions.php").exists() and (ROOT / "public/assets/js/sessions.js").exists())

failed = [name for name, passed in checks if not passed]
print(f"{len(checks)} checks, {len(failed)} failures")
sys.exit(bool(failed))
