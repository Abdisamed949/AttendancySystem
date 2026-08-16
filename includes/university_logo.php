<?php
/**
 * University logo upload (University Rector, Settings page) — same
 * validate-then-random-filename pattern as includes/profile_photo.php,
 * just for the one shared logo used on the sidebar, login page, and PDF
 * report exports instead of a per-user photo.
 */
declare(strict_types=1);

const UNIVERSITY_LOGO_DIR = __DIR__ . '/../uploads/university_logo/';
const UNIVERSITY_LOGO_MAX_BYTES = 5 * 1024 * 1024;
const UNIVERSITY_LOGO_DEFAULT_PATH = 'logo/logo.jpg';

// Keyed by the constant getimagesize() reports for a real image of that
// type — never trusts the client-supplied filename or MIME type.
const UNIVERSITY_LOGO_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/**
 * The app-root-relative path (for both <img src> and filesystem access via
 * __DIR__ . '/../' . path) to the currently configured university logo —
 * the original bundled /logo/logo.jpg until an admin uploads a
 * replacement via Settings.
 */
function get_university_logo_relative_path(array $settings): string
{
    $path = trim((string) ($settings['university_logo'] ?? ''));

    return $path !== '' ? $path : UNIVERSITY_LOGO_DEFAULT_PATH;
}

/**
 * Validate and persist an uploaded university logo from $_FILES['logo'].
 * Only ever trusts what getimagesize() actually detects in the file bytes.
 *
 * @return array{success: bool, path: string, error: string}
 */
function save_university_logo(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'path' => '', 'error' => 'Please choose a logo image to upload.'];
    }

    $tmpName = (string) $file['tmp_name'];
    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > UNIVERSITY_LOGO_MAX_BYTES) {
        return ['success' => false, 'path' => '', 'error' => 'Image must be 5MB or smaller.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false || !isset(UNIVERSITY_LOGO_ALLOWED_TYPES[$imageInfo[2]])) {
        return ['success' => false, 'path' => '', 'error' => 'Please upload a JPG, PNG, GIF, or WEBP image.'];
    }

    if (!is_dir(UNIVERSITY_LOGO_DIR)) {
        if (!mkdir(UNIVERSITY_LOGO_DIR, 0755, true) && !is_dir(UNIVERSITY_LOGO_DIR)) {
            return ['success' => false, 'path' => '', 'error' => 'Could not save the logo. Please try again.'];
        }
    }

    $extension = UNIVERSITY_LOGO_ALLOWED_TYPES[$imageInfo[2]];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file($tmpName, UNIVERSITY_LOGO_DIR . $filename)) {
        return ['success' => false, 'path' => '', 'error' => 'Could not save the logo. Please try again.'];
    }

    return ['success' => true, 'path' => 'uploads/university_logo/' . $filename, 'error' => ''];
}

/**
 * Best-effort removal of a previously uploaded logo once it's been
 * replaced. Never touches the original bundled default — only a path
 * that actually lives under uploads/university_logo/ is ever deleted.
 */
function delete_old_university_logo(string $relativePath): void
{
    if (strpos($relativePath, 'uploads/university_logo/') !== 0) {
        return;
    }

    $path = __DIR__ . '/../' . $relativePath;
    if (is_file($path)) {
        @unlink($path);
    }
}
