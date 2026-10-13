<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Support/env.php';
require_once dirname(__DIR__) . '/app/Database/connection.php';
load_environment(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/app/Security/audit.php';
require_once dirname(__DIR__) . '/app/Services/SubscriptionService.php';

try {
    $count = subscription_expire_all();
    fwrite(STDOUT, sprintf("Expired subscriptions: %d\n", $count));
} catch (Throwable $error) {
    fwrite(STDERR, "Subscription expiry processing failed: " . $error->getMessage() . "\n");
    exit(1);
}
