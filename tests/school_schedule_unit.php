<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database/school_schedule.php';

$grid = school_schedule_weekly_grid([
    ['recurrence_type' => 'WEEKLY', 'day_of_week' => 0, 'starts_at' => '08:00:00', 'ends_at' => '08:45:00', 'entry_type' => 'LESSON'],
    ['recurrence_type' => 'WEEKLY', 'day_of_week' => 4, 'starts_at' => '08:00:00', 'ends_at' => '08:45:00', 'entry_type' => 'LESSON'],
    ['recurrence_type' => 'WEEKLY', 'day_of_week' => 2, 'starts_at' => '10:00:00', 'ends_at' => '10:20:00', 'entry_type' => 'BREAK'],
    ['recurrence_type' => 'ONCE', 'day_of_week' => 1, 'starts_at' => '07:00:00', 'ends_at' => '07:30:00', 'entry_type' => 'LESSON'],
    ['recurrence_type' => 'WEEKLY', 'day_of_week' => 8, 'starts_at' => '07:00:00', 'ends_at' => '07:30:00', 'entry_type' => 'LESSON'],
]);
$failedGrid = [];
$gridCases = [
    'weekly grid orders used weekdays right to left' => array_keys($grid['days']) === [4, 2, 0],
    'weekly grid combines equal periods by weekday' => count($grid['slots']) === 2
        && count($grid['slots'][0]['entries_by_day'][4]) === 1
        && count($grid['slots'][0]['entries_by_day'][0]) === 1,
    'weekly grid sorts periods by start time' => $grid['slots'][0]['starts_at'] === '08:00'
        && $grid['slots'][1]['starts_at'] === '10:00',
    'weekly grid excludes one-time and invalid-day entries' => !isset($grid['days'][1]) && count($grid['slots']) === 2,
];
foreach ($gridCases as $name => $passed) {
    printf("%s %s\n", $passed ? 'PASS' : 'FAIL', $name);
    if (!$passed) $failedGrid[] = $name;
}
if (!empty($failedGrid)) {
    exit(1);
}

$cases = [
    [
        'name' => 'same class and weekly day overlap',
        'left' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        'right' => ['class_id' => 4, 'teacher_id' => 9, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:30'],
        'expected' => true,
    ],
    [
        'name' => 'different class and teacher do not overlap',
        'left' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        'right' => ['class_id' => 5, 'teacher_id' => 9, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:30'],
        'expected' => false,
    ],
    [
        'name' => 'same teacher cannot overlap across classes',
        'left' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        'right' => ['class_id' => 5, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:30', 'ends_at' => '09:30'],
        'expected' => true,
    ],
    [
        'name' => 'touching slot boundaries are allowed',
        'left' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        'right' => ['class_id' => 4, 'teacher_id' => 9, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '10:00'],
        'expected' => false,
    ],
    [
        'name' => 'weekly lesson conflicts with a matching single date',
        'left' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 0, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        'right' => ['class_id' => 4, 'teacher_id' => 9, 'recurrence_type' => 'ONCE', 'occurrence_date' => '2026-10-25', 'starts_at' => '08:30', 'ends_at' => '09:30'],
        'expected' => true,
    ],
    [
        'name' => 'weekly lesson does not conflict with a different weekday date',
        'left' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '08:00', 'ends_at' => '09:00'],
        'right' => ['class_id' => 4, 'teacher_id' => 9, 'recurrence_type' => 'ONCE', 'occurrence_date' => '2026-10-25', 'starts_at' => '08:30', 'ends_at' => '09:30'],
        'expected' => false,
    ],
    [
        'name' => 'general break conflicts with a class lesson',
        'left' => ['class_id' => null, 'teacher_id' => null, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '10:00', 'ends_at' => '10:30'],
        'right' => ['class_id' => 4, 'teacher_id' => 8, 'recurrence_type' => 'WEEKLY', 'day_of_week' => 1, 'starts_at' => '10:15', 'ends_at' => '11:00'],
        'expected' => true,
    ],
];

$failed = 0;
foreach ($cases as $case) {
    $actual = school_schedule_entries_conflict($case['left'], $case['right']);
    if ($actual !== $case['expected']) {
        fwrite(STDERR, "FAIL {$case['name']}: expected " . var_export($case['expected'], true) . ', got ' . var_export($actual, true) . PHP_EOL);
        $failed++;
        continue;
    }
    echo "PASS {$case['name']}" . PHP_EOL;
}

if ($failed > 0) {
    exit(1);
}

$validationCases = [
    ['name' => 'reject impossible calendar date', 'callback' => static fn() => school_schedule_validate_date('2026-02-30', 'test')],
    ['name' => 'reject malformed calendar date', 'callback' => static fn() => school_schedule_validate_date("2026-10-19\0", 'test')],
    ['name' => 'reject time outside a day', 'callback' => static fn() => school_schedule_validate_time('24:00', 'test')],
];
foreach ($validationCases as $case) {
    try {
        $case['callback']();
        fwrite(STDERR, "FAIL {$case['name']}: invalid value was accepted" . PHP_EOL);
        $failed++;
    } catch (InvalidArgumentException) {
        echo "PASS {$case['name']}" . PHP_EOL;
    }
}

if ($failed > 0) {
    exit(1);
}

echo (count($cases) + count($validationCases)) . " schedule validation checks, 0 failures" . PHP_EOL;
