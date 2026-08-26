<?php
/**
 * Course Document download route — the ONLY way a course document's file
 * bytes are ever served (uploads/course_documents/ itself is
 * .htaccess-protected and never linked to directly). Re-verifies the
 * requesting lecturer/student can actually reach the document's course
 * before streaming anything, per §23 of the project spec: Login -> Verify
 * Enrollment -> Verify Course Offering -> Verify Document Permission ->
 * Download.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course_document_helpers.php';

require_role(['lecturer', 'student']);

$conn = db();
$currentUser = current_user();
$role = current_role();

$documentId = (int) ($_GET['id'] ?? 0);

$docStmt = $conn->prepare(
    'SELECT id, course_id, stored_filename, original_filename, file_extension FROM course_documents WHERE id = ?'
);
$docStmt->bind_param('i', $documentId);
$docStmt->execute();
$doc = $docStmt->get_result()->fetch_assoc();
$docStmt->close();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$courseId = (int) $doc['course_id'];
$allowed = false;

if ($role === 'lecturer') {
    $lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
    $lecStmt->bind_param('i', $currentUser['id']);
    $lecStmt->execute();
    $lecRow = $lecStmt->get_result()->fetch_assoc();
    $lecStmt->close();
    $lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;
    $allowed = $lecturerRecordId > 0 && lecturer_can_manage_course_documents($conn, $lecturerRecordId, $courseId);
} elseif ($role === 'student') {
    $stuStmt = $conn->prepare('SELECT id FROM students WHERE user_id = ?');
    $stuStmt->bind_param('i', $currentUser['id']);
    $stuStmt->execute();
    $stuRow = $stuStmt->get_result()->fetch_assoc();
    $stuStmt->close();
    $studentRecordId = $stuRow ? (int) $stuRow['id'] : 0;
    $allowed = $studentRecordId > 0 && student_can_access_course_documents($conn, $studentRecordId, $courseId);
}

if (!$allowed) {
    http_response_code(403);
    exit('You do not have permission to download this document.');
}

$filePath = COURSE_DOCUMENT_DIR . $doc['stored_filename'];
if (!is_file($filePath)) {
    http_response_code(404);
    exit('This file is no longer available.');
}

$updateStmt = $conn->prepare('UPDATE course_documents SET download_count = download_count + 1 WHERE id = ?');
$updateStmt->bind_param('i', $documentId);
$updateStmt->execute();
$updateStmt->close();

$downloadName = preg_replace('/[\r\n"]/', '_', (string) $doc['original_filename']);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
