-- Phase 6: efficient lookup for expiring subscriptions.
CREATE INDEX subscriptions_expiration_queue_idx
    ON subscriptions (ends_at, id)
    WHERE status = 'ACTIVE';
