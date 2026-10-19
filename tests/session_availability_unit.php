<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Services/SessionMessagingService.php';

$now = new DateTimeImmutable('2026-01-15 12:00:00 Africa/Khartoum');
$cases = [
    'future scheduled session' => [
        ['status' => 'SCHEDULED', 'scheduled_at' => '2026-01-15 13:00:00 Africa/Khartoum', 'duration_minutes' => 60],
        'upcoming',
        false,
    ],
    'active scheduled session' => [
        ['status' => 'SCHEDULED', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => 60],
        'joinable',
        true,
    ],
    'active live session' => [
        ['status' => 'LIVE', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => 60],
        'live',
        true,
    ],
    'session at end boundary' => [
        ['status' => 'SCHEDULED', 'scheduled_at' => '2026-01-15 11:00:00 Africa/Khartoum', 'duration_minutes' => 60],
        'joinable',
        true,
    ],
    'expired session' => [
        ['status' => 'SCHEDULED', 'scheduled_at' => '2026-01-15 10:59:00 Africa/Khartoum', 'duration_minutes' => 60],
        'ended',
        false,
    ],
    'cancelled session' => [
        ['status' => 'CANCELLED', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => 60],
        'cancelled',
        false,
    ],
    'completed session' => [
        ['status' => 'COMPLETED', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => 60],
        'completed',
        false,
    ],
    'session without a valid duration' => [
        ['status' => 'SCHEDULED', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => null],
        'unavailable',
        false,
    ],
];

$failures = [];
foreach ($cases as $name => [$session, $expectedState, $expectedCanJoin]) {
    $result = sm_session_availability($session, $now);
    if ($result['state'] !== $expectedState || $result['can_join'] !== $expectedCanJoin) {
        $failures[] = $name . ': got ' . json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}

$recordingCases = [
    'completed session recordings are available' => [
        ['status' => 'COMPLETED', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => 60],
        true,
    ],
    'cancelled session recordings wait for the scheduled end' => [
        ['status' => 'CANCELLED', 'scheduled_at' => '2026-01-15 11:30:00 Africa/Khartoum', 'duration_minutes' => 60],
        false,
    ],
    'cancelled session recordings are available after the scheduled end' => [
        ['status' => 'CANCELLED', 'scheduled_at' => '2026-01-15 10:00:00 Africa/Khartoum', 'duration_minutes' => 60],
        true,
    ],
];
foreach ($recordingCases as $name => [$session, $expected]) {
    if (sm_session_recordings_available($session, $now) !== $expected) {
        $failures[] = $name;
    }
}

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

printf("PASS %d session availability and recording cases\n", count($cases) + count($recordingCases));
