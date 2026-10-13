-- Phase 1: persistent login throttling. Values and reset policy are configured by the application.
CREATE TABLE auth_login_attempts (
    email_key CHAR(64) PRIMARY KEY,
    failed_attempts INTEGER NOT NULL CHECK (failed_attempts >= 0),
    window_started_at TIMESTAMPTZ NOT NULL,
    locked_until TIMESTAMPTZ,
    last_attempt_at TIMESTAMPTZ NOT NULL,
    last_ip INET,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX auth_login_attempts_locked_idx ON auth_login_attempts (locked_until)
    WHERE locked_until IS NOT NULL;
