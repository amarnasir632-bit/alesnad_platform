<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Security/passwords.php';

$cases = [
    'minimum ASCII length accepted' => password_validation_error(str_repeat('a', 8)) === null,
    'maximum ASCII length accepted' => password_validation_error(str_repeat('a', 12)) === null,
    'too short rejected' => password_validation_error(str_repeat('a', 7)) !== null,
    'over maximum rejected' => password_validation_error(str_repeat('a', 13)) !== null,
    'ASCII symbols accepted' => password_validation_error('A1!bcdef') === null,
    'Arabic characters rejected' => password_validation_error('كلمةسر123') !== null,
    'Unicode emoji rejected' => password_validation_error('abcde😀12') !== null,
    'spaces rejected' => password_validation_error('abcd 123') !== null,
];
$failed = [];
foreach ($cases as $name => $passed) {
    printf("%s %s\n", $passed ? 'PASS' : 'FAIL', $name);
    if (!$passed) $failed[] = $name;
}
exit($failed ? 1 : 0);
