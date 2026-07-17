<?php
/**
 * Profile photo upload — shared by every role's profile.php.
 */
declare(strict_types=1);

const PROFILE_PHOTO_DIR = __DIR__ . '/../uploads/profile_photos/';
const PROFILE_PHOTO_MAX_BYTES = 5 * 1024 * 1024;

// Keyed by the constant getimagesize() reports for a real image of that
// type — never trusts the client-supplied filename or MIME type.
const PROFILE_PHOTO_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

/**
 * Validate and persist an uploaded profile photo from $_FILES['photo'].
 * Only ever trusts what getimagesize() actually detects in the file
 * bytes — the client-supplied filename/extension/MIME type are ignored.
 *
 * @return array{success: bool, filename: string, error: string}
 */
function save_profile_photo(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'filename' => '', 'error' => 'Please choose a photo to upload.'];
    }

    $tmpName = (string) $file['tmp_name'];
    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > PROFILE_PHOTO_MAX_BYTES) {
        return ['success' => false, 'filename' => '', 'error' => 'Image must be 5MB or smaller.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false || !isset(PROFILE_PHOTO_ALLOWED_TYPES[$imageInfo[2]])) {
        return ['success' => false, 'filename' => '', 'error' => 'Please upload a JPG, PNG, GIF, or WEBP image.'];
    }

    if (!is_dir(PROFILE_PHOTO_DIR)) {
        if (!mkdir(PROFILE_PHOTO_DIR, 0755, true) && !is_dir(PROFILE_PHOTO_DIR)) {
            return ['success' => false, 'filename' => '', 'error' => 'Could not save the photo. Please try again.'];
        }
    }

    $extension = PROFILE_PHOTO_ALLOWED_TYPES[$imageInfo[2]];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;

    if (!move_uploaded_file($tmpName, PROFILE_PHOTO_DIR . $filename)) {
        return ['success' => false, 'filename' => '', 'error' => 'Could not save the photo. Please try again.'];
    }

    return ['success' => true, 'filename' => $filename, 'error' => ''];
}

/**
 * Best-effort removal of a previous profile photo once it's been
 * replaced — avoids orphaned files accumulating in the upload directory.
 */
function delete_old_profile_photo(?string $filename): void
{
    if ($filename === null || $filename === '') {
        return;
    }

    $path = PROFILE_PHOTO_DIR . $filename;
    if (is_file($path)) {
        @unlink($path);
    }
}
