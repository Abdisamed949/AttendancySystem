<?php
/**
 * Course Documents — lecturer-uploaded learning materials organized by
 * Chapter (1-7 per course), with a short description, shared by
 * lecturer/course_documents.php (upload/manage) and
 * student/course_documents.php (browse/download) plus
 * download_course_document.php (the one access-checked download route —
 * files are never served directly from uploads/course_documents/, which
 * is also .htaccess-protected against PHP execution, same convention as
 * uploads/profile_photos/).
 */
declare(strict_types=1);

const COURSE_DOCUMENT_DIR = __DIR__ . '/../uploads/course_documents/';
const COURSE_DOCUMENT_MAX_BYTES = 25 * 1024 * 1024; // 25MB
const COURSE_DOCUMENT_CHAPTER_COUNT = 7;
const COURSE_DOCUMENT_COVER_MAX_BYTES = 5 * 1024 * 1024; // 5MB

const COURSE_DOCUMENT_TYPES = [
    'chapter' => 'Chapter',
    'quiz' => 'Quiz',
    'assignment' => 'Assignment',
];

const COURSE_DOCUMENT_TYPE_ICONS = [
    'chapter' => 'bi-journal-text',
    'quiz' => 'bi-question-circle-fill',
    'assignment' => 'bi-clipboard2-check-fill',
];

// Real-content-type-detected (getimagesize), same convention as
// includes/profile_photo.php — never trusts the client filename/MIME.
const COURSE_DOCUMENT_COVER_ALLOWED_TYPES = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG => 'png',
    IMAGETYPE_GIF => 'gif',
    IMAGETYPE_WEBP => 'webp',
];

// Extension -> the real MIME type(s) that extension's content must
// actually detect as (via fileinfo, on the file's bytes) — never trusts
// the client-supplied filename/extension alone. Some legacy browsers/OSes
// report Office Open XML formats as a generic zip, so those also accept
// 'application/zip'.
const COURSE_DOCUMENT_ALLOWED_TYPES = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
];

/**
 * Validate and persist an uploaded course document from
 * $_FILES['document']. Confirms the file's real content type via
 * fileinfo (bytes), not the client-supplied filename/extension/MIME type.
 *
 * @return array{success: bool, stored_filename: string, original_filename: string, extension: string, size: int, error: string}
 */
function save_course_document(array $file): array
{
    $blank = ['success' => false, 'stored_filename' => '', 'original_filename' => '', 'extension' => '', 'size' => 0, 'error' => ''];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $blank + ['error' => 'Please choose a file to upload.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > COURSE_DOCUMENT_MAX_BYTES) {
        return $blank + ['error' => 'File must be 25MB or smaller.'];
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!isset(COURSE_DOCUMENT_ALLOWED_TYPES[$extension])) {
        return $blank + ['error' => 'Allowed file types: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, JPG, PNG, ZIP.'];
    }

    $tmpName = (string) $file['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedType = $finfo !== false ? finfo_file($finfo, $tmpName) : false;
    if ($finfo !== false) {
        finfo_close($finfo);
    }
    if ($detectedType === false || !in_array($detectedType, COURSE_DOCUMENT_ALLOWED_TYPES[$extension], true)) {
        return $blank + ['error' => 'This file\'s actual content does not look like a valid ' . strtoupper($extension) . ' file.'];
    }

    if (!is_dir(COURSE_DOCUMENT_DIR)) {
        if (!mkdir(COURSE_DOCUMENT_DIR, 0755, true) && !is_dir(COURSE_DOCUMENT_DIR)) {
            return $blank + ['error' => 'Could not save the file. Please try again.'];
        }
    }

    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($tmpName, COURSE_DOCUMENT_DIR . $storedFilename)) {
        return $blank + ['error' => 'Could not save the file. Please try again.'];
    }

    return [
        'success' => true,
        'stored_filename' => $storedFilename,
        'original_filename' => $originalName,
        'extension' => $extension,
        'size' => $size,
        'error' => '',
    ];
}

function delete_course_document_file(string $storedFilename): void
{
    $path = COURSE_DOCUMENT_DIR . $storedFilename;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Optional cover/background image for a document or chapter — validated
 * via getimagesize() against the file's real bytes (never the client's
 * filename/MIME), same pattern as includes/profile_photo.php. Returns an
 * empty string (not an error) when no file was actually chosen, since this
 * field is always optional; a genuinely invalid upload returns an error.
 *
 * @return array{success: bool, stored_filename: string, error: string}
 */
function save_course_document_cover(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || (int) ($file['size'] ?? 0) === 0) {
        return ['success' => true, 'stored_filename' => '', 'error' => ''];
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'stored_filename' => '', 'error' => 'Could not upload the cover image. Please try again.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size > COURSE_DOCUMENT_COVER_MAX_BYTES) {
        return ['success' => false, 'stored_filename' => '', 'error' => 'Cover image must be 5MB or smaller.'];
    }

    $tmpName = (string) $file['tmp_name'];
    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false || !isset(COURSE_DOCUMENT_COVER_ALLOWED_TYPES[$imageInfo[2]])) {
        return ['success' => false, 'stored_filename' => '', 'error' => 'Cover image must be a JPG, PNG, GIF, or WEBP image.'];
    }

    if (!is_dir(COURSE_DOCUMENT_DIR)) {
        if (!mkdir(COURSE_DOCUMENT_DIR, 0755, true) && !is_dir(COURSE_DOCUMENT_DIR)) {
            return ['success' => false, 'stored_filename' => '', 'error' => 'Could not save the cover image. Please try again.'];
        }
    }

    $extension = COURSE_DOCUMENT_COVER_ALLOWED_TYPES[$imageInfo[2]];
    $storedFilename = 'cover_' . bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($tmpName, COURSE_DOCUMENT_DIR . $storedFilename)) {
        return ['success' => false, 'stored_filename' => '', 'error' => 'Could not save the cover image. Please try again.'];
    }

    return ['success' => true, 'stored_filename' => $storedFilename, 'error' => ''];
}

function delete_course_document_cover_file(?string $storedFilename): void
{
    if (empty($storedFilename)) {
        return;
    }
    $path = COURSE_DOCUMENT_DIR . $storedFilename;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * True if this lecturer has ever held (or currently holds) a
 * course_offerings row for this course — any semester, matching the same
 * "any offering" precedent already used for lecturer/teaching_history.php
 * and reports.php's lecturer-scoped Xiiso dropdown. Materials aren't
 * per-semester, so a lecturer keeps the ability to manage a course's
 * documents even after a later semester's offering is reassigned.
 */
function lecturer_can_manage_course_documents(mysqli $conn, int $lecturerId, int $courseId): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM course_offerings WHERE course_id = ? AND lecturer_id = ? LIMIT 1');
    $stmt->bind_param('ii', $courseId, $lecturerId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();

    return $found;
}

/**
 * Every distinct course a lecturer may manage documents for.
 */
function lecturer_documentable_courses(mysqli $conn, int $lecturerId): array
{
    $stmt = $conn->prepare(
        'SELECT DISTINCT c.id, c.code, c.name, d.name AS department_name, f.name AS faculty_name
         FROM course_offerings co
         JOIN courses c ON c.id = co.course_id
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         WHERE co.lecturer_id = ?
         ORDER BY c.code'
    );
    $stmt->bind_param('i', $lecturerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * True if this student can reach this course at all — via an explicit
 * course_enrollments row, their own department matching the course's own
 * catalog department, or a guest offering whose roster_department_id
 * names their department (any semester — documents aren't per-semester).
 * This is the actual download access boundary — see §23 "documents should
 * not be publicly accessible" — never trust a document id alone.
 */
function student_can_access_course_documents(mysqli $conn, int $studentId, int $courseId): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM courses c
         JOIN students s ON s.id = ?
         WHERE c.id = ?
           AND (
             EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.student_id = s.id)
             OR c.department_id = s.department_id
             OR EXISTS (SELECT 1 FROM course_offerings co WHERE co.course_id = c.id AND co.roster_department_id = s.department_id)
           )
         LIMIT 1'
    );
    $stmt->bind_param('ii', $studentId, $courseId);
    $stmt->execute();
    $found = $stmt->get_result()->fetch_assoc() !== null;
    $stmt->close();

    return $found;
}

/**
 * Every distinct course a student can browse/download documents for.
 */
function student_accessible_courses(mysqli $conn, int $studentId): array
{
    $stmt = $conn->prepare(
        'SELECT DISTINCT c.id, c.code, c.name, d.name AS department_name, f.name AS faculty_name
         FROM courses c
         JOIN departments d ON d.id = c.department_id
         JOIN faculties f ON f.id = d.faculty_id
         JOIN students s ON s.id = ?
         WHERE
           EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.course_id = c.id AND ce.student_id = s.id)
           OR c.department_id = s.department_id
           OR EXISTS (SELECT 1 FROM course_offerings co WHERE co.course_id = c.id AND co.roster_department_id = s.department_id)
         ORDER BY c.code'
    );
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

/**
 * Small file-type -> Bootstrap Icon glyph map for the document list UI.
 */
function course_document_icon_class(string $extension): string
{
    $map = [
        'pdf' => 'bi-file-earmark-pdf-fill text-danger',
        'doc' => 'bi-file-earmark-word-fill text-primary',
        'docx' => 'bi-file-earmark-word-fill text-primary',
        'ppt' => 'bi-file-earmark-ppt-fill text-warning',
        'pptx' => 'bi-file-earmark-ppt-fill text-warning',
        'xls' => 'bi-file-earmark-excel-fill text-success',
        'xlsx' => 'bi-file-earmark-excel-fill text-success',
        'jpg' => 'bi-file-earmark-image-fill text-info',
        'jpeg' => 'bi-file-earmark-image-fill text-info',
        'png' => 'bi-file-earmark-image-fill text-info',
        'zip' => 'bi-file-earmark-zip-fill text-secondary',
    ];

    return $map[$extension] ?? 'bi-file-earmark-fill text-muted';
}

/**
 * Human-readable file size (e.g. "2.4 MB").
 */
function format_file_size(int $bytes): string
{
    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' B';
}
