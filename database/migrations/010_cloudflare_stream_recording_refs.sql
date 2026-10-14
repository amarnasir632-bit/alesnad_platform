-- Keep Cloudflare Stream inputs and replays idempotent in the application database.
CREATE UNIQUE INDEX live_sessions_provider_session_ref_unique
    ON live_sessions (provider_code, provider_session_ref)
    WHERE provider_session_ref IS NOT NULL;

CREATE UNIQUE INDEX session_recordings_provider_ref_unique
    ON session_recordings (session_id, provider_recording_ref)
    WHERE provider_recording_ref IS NOT NULL;
