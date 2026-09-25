<?php
/**
 * Shared evidence-file validation and safe filename helpers.
 *
 * The browser's filename and MIME value are untrusted metadata. The upload
 * endpoint uses the file contents and the server-side Fileinfo extension to
 * decide whether a file is one of the document types supported by the app.
 */

const EVIDENCE_MAX_BYTES = 10 * 1024 * 1024;

const EVIDENCE_ALLOWED_EXTENSIONS = [
    'pdf',
    'jpg',
    'jpeg',
    'png',
    'doc',
    'docx',
    'xls',
    'xlsx',
];

const EVIDENCE_CANONICAL_MIME_TYPES = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

/**
 * Return the canonical MIME type for a validated stored-file extension.
 */
function evidenceCanonicalMimeType(string $extension): ?string
{
    return EVIDENCE_CANONICAL_MIME_TYPES[$extension] ?? null;
}

/**
 * Validate the actual bytes of an uploaded file and return its canonical MIME.
 *
 * Legacy Word/Excel files use the same OLE container format, while modern
 * docx/xlsx files use ZIP containers. The package/stream markers distinguish
 * those supported document formats from arbitrary ZIP or OLE files.
 */
function evidenceValidateFile(string $path, string $extension): string
{
    if (!in_array($extension, EVIDENCE_ALLOWED_EXTENSIONS, true)) {
        throw new InvalidArgumentException('Unsupported file extension.');
    }

    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('The uploaded file could not be read for validation.');
    }

    if (!class_exists('finfo')) {
        throw new RuntimeException('Server-side file type detection is unavailable.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo->file($path);
    if (!is_string($detectedMime) || $detectedMime === '') {
        throw new InvalidArgumentException('The uploaded file type could not be determined.');
    }

    if (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        $imageInfo = @getimagesize($path);
        $expectedImageType = $extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
        if ($imageInfo === false || ($imageInfo[2] ?? null) !== $expectedImageType) {
            throw new InvalidArgumentException('The uploaded file does not match its image type.');
        }

        $expectedMime = evidenceCanonicalMimeType($extension);
        if ($detectedMime !== $expectedMime) {
            throw new InvalidArgumentException('The uploaded file does not match its image type.');
        }

        return $expectedMime;
    }

    if ($extension === 'pdf') {
        $signature = @file_get_contents($path, false, null, 0, 5);
        if ($signature !== '%PDF-') {
            throw new InvalidArgumentException('The uploaded file is not a valid PDF.');
        }

        if ($detectedMime !== 'application/pdf') {
            throw new InvalidArgumentException('The uploaded file does not match its PDF type.');
        }

        return 'application/pdf';
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('The uploaded document could not be read for validation.');
    }

    if (in_array($extension, ['docx', 'xlsx'], true)) {
        $packageRoot = $extension === 'docx' ? 'word/' : 'xl/';
        if (!str_starts_with($contents, "PK\x03\x04")
            || !str_contains($contents, '[Content_Types].xml')
            || !str_contains($contents, $packageRoot)
        ) {
            throw new InvalidArgumentException('The uploaded file is not a valid Office document.');
        }

        return evidenceCanonicalMimeType($extension);
    }

    if (!str_starts_with($contents, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        throw new InvalidArgumentException('The uploaded file is not a valid legacy Office document.');
    }

    $isWord = evidenceContainsUtf16Le($contents, 'WordDocument');
    $isExcel = evidenceContainsUtf16Le($contents, 'Workbook')
        || evidenceContainsUtf16Le($contents, 'Book');

    if (($extension === 'doc' && !$isWord) || ($extension === 'xls' && !$isExcel)) {
        throw new InvalidArgumentException('The uploaded file does not match its Office document type.');
    }

    return evidenceCanonicalMimeType($extension);
}

/** Detect UTF-16LE stream names used inside legacy OLE Office documents. */
function evidenceContainsUtf16Le(string $contents, string $needle): bool
{
    $encoded = '';
    for ($index = 0, $length = strlen($needle); $index < $length; $index++) {
        $encoded .= $needle[$index] . "\x00";
    }

    return str_contains($contents, $encoded);
}

/**
 * Keep the original name for display while preventing path/header injection
 * and keeping it within the database column's 255-character limit.
 */
function evidenceSanitizeOriginalFilename(mixed $value, string $extension): string
{
    $name = is_string($value) ? $value : '';
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
    $name = str_replace('"', '', $name);
    $name = trim($name, " .\t\n\r\0\x0B");

    if ($name === '') {
        return 'document.' . $extension;
    }

    if (strlen($name) > 255) {
        if (function_exists('mb_strcut')) {
            $name = mb_strcut($name, 0, 255, 'UTF-8');
        } else {
            $name = substr($name, 0, 255);
            while ($name !== '' && @preg_match('//u', $name) !== 1) {
                $name = substr($name, 0, -1);
            }
        }
    }

    return $name !== '' ? $name : 'document.' . $extension;
}

/** Stored names are generated by this application, never accepted from a client. */
function evidenceStoredFilenameIsSafe(mixed $value): bool
{
    return is_string($value)
        && preg_match('/^[a-f0-9]{32}\.(?:pdf|jpg|jpeg|png|doc|docx|xls|xlsx)$/D', $value) === 1;
}

/** Use only known server-approved MIME values when streaming an attachment. */
function evidenceDownloadMimeType(mixed $storedMime, string $storedFilename): string
{
    $extension = strtolower(pathinfo($storedFilename, PATHINFO_EXTENSION));
    $canonicalMime = evidenceCanonicalMimeType($extension);
    if ($canonicalMime !== null) {
        return $canonicalMime;
    }

    $mime = is_string($storedMime) ? trim($storedMime) : '';
    return $mime !== '' ? $mime : 'application/octet-stream';
}

/** Make a stored original name safe for both ASCII and UTF-8 download headers. */
function evidenceDownloadFilename(mixed $value, string $fallback): string
{
    $name = is_string($value) ? $value : '';
    $name = str_replace(["\r", "\n", '"', '\\'], '', $name);
    $name = trim($name, " .\t");
    return $name !== '' ? $name : $fallback;
}
