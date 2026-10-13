<?php
declare(strict_types=1);

/** Adapter contract for a future conferencing provider; intentionally has no provider implementation. */
interface LiveMeetingProvider
{
    /** @return array{provider_code:string, provider_session_ref:string, meeting_url:string} */
    public function createMeeting(array $session): array;

    public function joinUrl(array $session, array $participant): string;

    /** @return array{stream_url?:string, storage_key?:string, mime_type?:string, filename?:string} */
    public function recordingSource(array $recording, array $participant): array;
}

function live_meeting_provider(): LiveMeetingProvider
{
    throw new ApiException(503, 'broadcast_provider_not_configured', 'لم يتم ربط مزود بث بعد.');
}
