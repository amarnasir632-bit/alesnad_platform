<?php
declare(strict_types=1);

function configured_upload_limit(): int
{
    $value = env_value('UPLOAD_MAX_BYTES');
    if ($value === null || !preg_match('/^[1-9][0-9]*$/D', $value) || (string) (int) $value !== $value) {
        throw new ApiException(503, 'upload_not_configured', 'رفع الملفات غير مهيأ في إعدادات الخادم.');
    }
    return (int) $value;
}

function private_storage_root(): string
{
    $configured = env_value('PRIVATE_STORAGE_PATH');
    if ($configured === null || trim($configured) === '') {
        throw new ApiException(503, 'storage_not_configured', 'مساحة الملفات الخاصة غير مهيأة.');
    }
    $path = trim($configured);
    if (!str_starts_with($path, DIRECTORY_SEPARATOR)) $path = PROJECT_ROOT . DIRECTORY_SEPARATOR . $path;
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new ApiException(503, 'storage_unavailable', 'تعذر تجهيز مساحة الملفات الخاصة.');
    }
    $root = realpath($path);
    $project = realpath(PROJECT_ROOT);
    $public = realpath(PROJECT_ROOT . '/public');
    if ($root === false || $project === false || $public === false) {
        throw new ApiException(503, 'storage_unavailable', 'تعذر تجهيز مساحة الملفات الخاصة.');
    }
    if ($root === $public || str_starts_with($root, $public . DIRECTORY_SEPARATOR)) {
        throw new ApiException(503, 'unsafe_storage_path', 'يجب أن تكون الملفات خارج مجلد الويب العام.');
    }
    $expectedPrivate = realpath(PROJECT_ROOT . '/storage/private');
    $isInsideProject = $root === $project || str_starts_with($root, $project . DIRECTORY_SEPARATOR);
    if ($isInsideProject && ($expectedPrivate === false || $root !== $expectedPrivate)) {
        throw new ApiException(503, 'unsafe_storage_path', 'مسار التخزين داخل المشروع يجب أن يكون storage/private.');
    }
    @chmod($root, 0700);
    return $root;
}

function content_mime_allowlist(string $category): array
{
    $documents = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/rtf', 'text/rtf', 'text/plain',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
    ];
    return match ($category) {
        'PDF' => ['application/pdf'],
        'DOCUMENT' => $documents,
        'VIDEO' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime', 'video/x-matroska'],
        'AUDIO' => ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/flac'],
        'IMAGE' => ['image/jpeg', 'image/png', 'image/webp'],
        'EXAM' => $documents,
        default => [],
    };
}

function inspect_upload_file(array $file, string $category): array
{
    if (!isset($file['error']) || !is_int($file['error'])) throw new ApiException(422, 'file_required', 'أرفق ملفًا صالحًا.');
    if ($file['error'] === UPLOAD_ERR_NO_FILE) throw new ApiException(422, 'file_required', 'أرفق ملفًا.');
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw new ApiException(413, 'file_too_large', 'حجم الملف يتجاوز حد الخادم.');
    if ($file['error'] !== UPLOAD_ERR_OK) throw new ApiException(422, 'upload_failed', 'تعذر استقبال الملف. أعد المحاولة.');
    $tmp = $file['tmp_name'] ?? null;
    if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) throw new ApiException(422, 'invalid_upload', 'الملف المرسل غير صالح.');
    $size = filesize($tmp);
    if ($size === false || $size < 1) throw new ApiException(422, 'empty_file', 'لا يمكن رفع ملف فارغ.');
    if ($size > configured_upload_limit()) throw new ApiException(413, 'file_too_large', 'حجم الملف يتجاوز الحد المهيأ.');
    if ($category === 'IMAGE' && $size > 5 * 1024 * 1024) {
        throw new ApiException(413, 'file_too_large', 'يجب ألا يتجاوز حجم الصورة 5 ميغابايت.');
    }
    if (!class_exists('finfo')) throw new ApiException(503, 'fileinfo_unavailable', 'فحص أنواع الملفات غير متاح على الخادم.');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!is_string($mime) || !in_array($mime, content_mime_allowlist($category), true)) {
        throw new ApiException(415, 'unsupported_file_type', 'نوع الملف غير مسموح لهذا المحتوى.');
    }
    if ($category === 'IMAGE') {
        $dimensions = @getimagesize($tmp);
        if (!is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime
            || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1
            || $dimensions[0] > 5000 || $dimensions[1] > 5000
            || $dimensions[0] * $dimensions[1] > 20_000_000) {
            throw new ApiException(415, 'invalid_image', 'الصورة غير صالحة أو أبعادها أكبر من المسموح.');
        }
    }
    $original = $file['name'] ?? '';
    if (!is_string($original) || trim($original) === '') throw new ApiException(422, 'invalid_filename', 'اسم الملف غير صالح.');
    $original = basename(str_replace('\\', '/', $original));
    $original = preg_replace('/[\x00-\x1F\x7F]/u', '_', $original) ?? '';
    if ($original === '' || preg_match('//u', $original) !== 1) throw new ApiException(422, 'invalid_filename', 'اسم الملف غير صالح.');
    if (preg_match_all('/./us', $original) > 255) throw new ApiException(422, 'invalid_filename', 'اسم الملف يتجاوز 255 محرفًا.');
    return ['tmp_name' => $tmp, 'size' => $size, 'mime_type' => $mime, 'original_filename' => $original];
}

function store_uploaded_file(array $file, string $category): array
{
    $metadata = inspect_upload_file($file, $category);
    $root = private_storage_root();
    $key = bin2hex(random_bytes(32));
    $target = $root . DIRECTORY_SEPARATOR . $key;
    $reservation = @fopen($target, 'x');
    if ($reservation === false) throw new ApiException(503, 'storage_unavailable', 'تعذر حفظ الملف الآن.');
    fclose($reservation);
    if (!move_uploaded_file($metadata['tmp_name'], $target)) {
        @unlink($target);
        throw new ApiException(503, 'storage_unavailable', 'تعذر حفظ الملف الآن.');
    }
    @chmod($target, 0600);
    return $metadata + ['storage_key' => $key];
}

function discard_private_file(string $key): void
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) return;
    $path = private_storage_root() . DIRECTORY_SEPARATOR . $key;
    if (is_file($path) && !is_link($path)) @unlink($path);
}

function resolve_private_file(string $key): ?string
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) return null;
    $root = private_storage_root();
    $path = $root . DIRECTORY_SEPARATOR . $key;
    if (is_link($path) || !is_file($path) || !is_readable($path)) return null;
    $real = realpath($path);
    if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) return null;
    return $real;
}

function parse_byte_range(?string $header, int $size): ?array
{
    if ($header === null || trim($header) === '') return null;
    if ($size < 1 || str_contains($header, ',')) throw new ApiException(416, 'range_not_satisfiable', 'نطاق البايت المطلوب غير صالح.');
    if (!preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $match) || ($match[1] === '' && $match[2] === '')) {
        throw new ApiException(416, 'range_not_satisfiable', 'نطاق البايت المطلوب غير صالح.');
    }
    if ($match[1] === '') {
        $suffix = (int) $match[2];
        if ($suffix < 1) throw new ApiException(416, 'range_not_satisfiable', 'نطاق البايت المطلوب غير صالح.');
        $start = max(0, $size - $suffix);
        return [$start, $size - 1];
    }
    $start = (int) $match[1];
    $end = $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1);
    if ($start >= $size || $end < $start) throw new ApiException(416, 'range_not_satisfiable', 'نطاق البايت المطلوب غير صالح.');
    return [$start, $end];
}

function stream_private_file(string $key, string $mime, string $filename, bool $inline = false): never
{
    $path = resolve_private_file($key);
    if ($path === null) throw new ApiException(404, 'not_found', 'الملف غير متاح.');
    $size = filesize($path);
    if ($size === false) throw new ApiException(404, 'not_found', 'الملف غير متاح.');
    $range = parse_byte_range($_SERVER['HTTP_RANGE'] ?? null, $size);
    $start = $range[0] ?? 0;
    $end = $range[1] ?? max(0, $size - 1);
    $length = $size === 0 ? 0 : $end - $start + 1;
    $handle = fopen($path, 'rb');
    if ($handle === false || ($length > 0 && fseek($handle, $start) !== 0)) {
        if (is_resource($handle)) fclose($handle);
        throw new ApiException(404, 'not_found', 'الملف غير متاح.');
    }
    $safeName = str_replace('\\', '/', $filename);
    $safeName = basename($safeName);
    $safeName = preg_replace('/[\x00-\x1F\x7F]/u', '_', $safeName) ?? 'download';
    if ($safeName === '' || preg_match('//u', $safeName) !== 1) $safeName = 'download';
    $asciiName = preg_replace('/[^A-Za-z0-9._-]/u', '_', $safeName) ?? 'download';
    $asciiName = trim($asciiName, '._-');
    if ($asciiName === '') $asciiName = 'download';

    if (headers_sent()) { fclose($handle); throw new RuntimeException('Response headers were already sent.'); }
    while (ob_get_level() > 0) ob_end_clean();
    @ini_set('zlib.output_compression', '0');
    http_response_code($range === null ? 200 : 206);
    $safeMime = preg_match('#^[a-z0-9!$&^_.+-]+/[a-z0-9!$&^_.+-]+$#iD', $mime) === 1 ? $mime : 'application/octet-stream';
    header('Content-Type: ' . $safeMime);
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if ($range !== null) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(65536, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}
