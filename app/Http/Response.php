<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $code, string $message)
    {
        parent::__construct($message);
        $this->apiCode = $code;
    }
    public readonly string $apiCode;
}

function json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') throw new ApiException(415, 'content_type_required', 'استخدم Content-Type: application/json.');
    try {
        $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new ApiException(400, 'invalid_json', 'جسم الطلب ليس JSON صالحًا.');
    }
    if (!is_object($body)) throw new ApiException(400, 'invalid_body', 'يجب أن يكون جسم الطلب كائن JSON.');
    return get_object_vars($body);
}

function require_api_csrf(array $body): void
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($body['_csrf'] ?? '');
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        throw new ApiException(419, 'csrf_failed', 'رمز الحماية مفقود أو منتهي.');
    }
}
