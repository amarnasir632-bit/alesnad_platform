<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}
require_once dirname(__DIR__) . '/app/Services/PrivateStorage.php';

$cases = [
    [null, 100, null],
    ['bytes=0-9', 100, [0, 9]],
    ['bytes=10-', 100, [10, 99]],
    ['bytes=-10', 100, [90, 99]],
    ['bytes=-200', 100, [0, 99]],
    ['bytes=99-100', 100, [99, 99]],
];
foreach ($cases as [$header, $size, $expected]) {
    if (parse_byte_range($header, $size) !== $expected) {
        fwrite(STDERR, "FAIL range {$header}\n");
        exit(1);
    }
}
foreach (['bytes=100-', 'bytes=9-2', 'bytes=-0', 'items=0-2', 'bytes=0-1,3-4'] as $header) {
    try {
        parse_byte_range($header, 100);
        fwrite(STDERR, "FAIL invalid range {$header} accepted\n");
        exit(1);
    } catch (ApiException $error) {
        if ($error->status !== 416) throw $error;
    }
}
echo "PASS byte range parsing and invalid range rejection\n";
