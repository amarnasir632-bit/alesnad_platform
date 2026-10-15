<?php
declare(strict_types=1);

final class ApiException extends RuntimeException
{
    public function __construct(public int $status, public string $apiCode, string $message)
    {
        parent::__construct($message);
    }
}

function env_value(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

function app_log_error(string $event, Throwable $error): void
{
}

require dirname(__DIR__) . '/app/Services/LiveMeetingProvider.php';
require dirname(__DIR__) . '/app/Services/VideoUploadProvider.php';

$assert = static function (bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
};

$keyPair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($keyPair === false) { throw new RuntimeException('Could not create test signing key.'); }
openssl_pkey_export($keyPair, $privateKey);
$keyDetails = openssl_pkey_get_details($keyPair);
if (!is_string($privateKey) || !is_array($keyDetails) || !is_string($keyDetails['key'] ?? null)) {
    throw new RuntimeException('Could not export test signing key.');
}

putenv('MUX_TOKEN_ID=test-token-id');
putenv('MUX_TOKEN_SECRET=test-token-secret');
putenv('MUX_SIGNING_KEY_ID=test-signing-key-id');
putenv('MUX_SIGNING_KEY_PRIVATE=' . base64_encode($privateKey));
putenv('VIDEO_UPLOAD_PROVIDER=mux');
$provider = video_upload_provider();
$assert($provider instanceof MuxVideoUploadProvider && $provider->code() === 'mux', 'mux upload provider can be selected independently');

$url = $provider->playbackUrl('playback-id-test', 7200);
$parts = parse_url($url);
parse_str((string) ($parts['query'] ?? ''), $query);
$token = $query['token'] ?? '';
$segments = explode('.', $token);
$base64UrlDecode = static fn (string $value): string|false => base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
$header = count($segments) === 3 ? json_decode((string) $base64UrlDecode($segments[0]), true) : null;
$claims = count($segments) === 3 ? json_decode((string) $base64UrlDecode($segments[1]), true) : null;
$signature = count($segments) === 3 ? $base64UrlDecode($segments[2]) : false;
$verified = is_string($signature) && openssl_verify($segments[0] . '.' . $segments[1], $signature, $keyDetails['key'], OPENSSL_ALGO_SHA256) === 1;
$assert(($parts['host'] ?? null) === 'player.mux.com' && ($parts['path'] ?? null) === '/playback-id-test', 'playback URL uses Mux Player embed');
$assert(is_array($header) && ($header['alg'] ?? null) === 'RS256', 'playback token declares RS256');
$assert(is_array($claims) && ($claims['sub'] ?? null) === 'playback-id-test' && ($claims['aud'] ?? null) === 'v'
    && ($claims['kid'] ?? null) === 'test-signing-key-id' && ($claims['exp'] ?? 0) > time() + 7200, 'playback token contains signed video claims and sufficient lifetime');
$assert($verified, 'playback token signature verifies with matching public key');