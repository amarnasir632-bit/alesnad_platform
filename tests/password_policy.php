<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Security/passwords.php';

$cases = [
    'minimum ASCII length' => password_validation_error(str_repeat('a', 12)) === null,
    'minimum Unicode length' => password_validation_error(str_repeat('ع', 12)) === null,
    'too short rejected' => password_validation_error(str_repeat('a', 11)) !== null,
    '72 bytes accepted' => password_validation_error(str_repeat('a', 72)) === null,
    '73 bytes rejected' => password_validation_error(str_repeat('a', 73)) !== null,
    'Unicode byte cap enforced' => password_validation_error(str_repeat('ع', 37)) !== null,
];
$failed = [];
foreach ($cases as $name => $passed) {
    printf("%s %s\n", $passed ? 'PASS' : 'FAIL', $name);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
