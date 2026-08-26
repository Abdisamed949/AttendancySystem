<?php
/**
 * Course Documents (Lecturer only) — upload/manage learning materials for
 * every course this lecturer has ever held an offering for (see
 * lecturer_can_manage_course_documents()/lecturer_documentable_courses()
 * in includes/course_document_helpers.php). Materials are one of three
 * types — Chapter (1-7 per course), Quiz, or Assignment — each optionally
 * carrying its own cover/background image. Every write action re-verifies
 * the lecturer's own ownership of both the course and the specific
 * document server-side — never trusted from a hidden form field alone.
 *
 * Card-per-course layout: the whole page shows every course this lecturer
 * manages at once (no "select a course first" step), each as its own card
 * with its documents grouped into Chapter/Quiz/Assignment tabs.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/course_document_helpers.php';

require_role(['lecturer']);

$conn = db();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

$lecStmt = $conn->prepare('SELECT id FROM lecturers WHERE user_id = ?');
$lecStmt->bind_param('i', $currentUser['id']);
$lecStmt->execute();
$lecRow = $lecStmt->get_result()->fetch_assoc();
$lecStmt->close();
$lecturerRecordId = $lecRow ? (int) $lecRow['id'] : 0;

$successMessage = '';
$errorMessage = '';
if (!empty($_SESSION['flash_success'])) {
    $successMessage = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errorMessage = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------
// Handle POST actions — upload / delete. Both re-verify course ownership
// server-side (lecturer_can_manage_course_documents()), never trusting a
// posted course_id alone.
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $courseId = (int) ($_POST['course_id'] ?? 0);

    if (!lecturer_can_manage_course_documents($conn, $lecturerRecordId, $courseId)) {
        $_SESSION['flash_error'] = 'You are not assigned to that course.';
        redirect_to('lecturer/course_documents.php');
    }

    if ($action === 'upload') {
        $documentType = (string) ($_POST['document_type'] ?? '');
        $chapterNumberRaw = (string) ($_POST['chapter_number'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $validationError = '';
        if (!array_key_exists($documentType, COURSE_DOCUMENT_TYPES)) {
            $validationError = 'Please select a valid document type.';
        } elseif ($title === '') {
            $validationError = 'Please give this document a title.';
        }

        $chapterNumber = null;
        if ($validationError === '' && $documentType === 'chapter') {
            $chapterNumber = (int) $chapterNumberRaw;
            if ($chapterNumber < 1 || $chapterNumber > COURSE_DOCUMENT_CHAPTER_COUNT) {
                $validationError = 'Please select a valid chapter (1 to ' . COURSE_DOCUMENT_CHAPTER_COUNT . ').';
            }
        }

        $saved = null;
        if ($validationError === '') {
            $saved = save_course_document($_FILES['document'] ?? []);
            if (!$saved['success']) {
                $validationError = $saved['error'];
            }
        }

        $savedCover = null;
        if ($validationError === '') {
            $savedCover = save_course_document_cover($_FILES['cover_image'] ?? []);
            if (!$savedCover['success']) {
                $validationError = $savedCover['error'];
            }
        }

        if ($validationError === '') {
            $insertStmt = $conn->prepare(
                'INSERT INTO course_documents
                    (course_id, document_type, chapter_number, title, description, stored_filename, original_filename, file_extension, file_size, cover_image_path, uploaded_by_lecturer_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $descriptionParam = $description !== '' ? $description : null;
            $coverPathParam = $savedCover['stored_filename'] !== '' ? $savedCover['stored_filename'] : null;
            $insertStmt->bind_param(
                'isisssssisi',
                $courseId,
                $documentType,
                $chapterNumber,
                $title,
                $descriptionParam,
                $saved['stored_filename'],
                $saved['original_filename'],
                $saved['extension'],
                $saved['size'],
                $coverPathParam,
                $lecturerRecordId
            );
            $insertStmt->execute();
            $insertStmt->close();

    $_SESSION['flash_success'] = 'Document uploaded (' . COURSE_DOCUMENT_TYPES[$documentType] . ($chapterNumber !== null ? ' ' . $chapterNumber : '') . ').';
        } else {
            $_SESSION['flash_error'] = $validationError;
        }
        redirect_to('lecturer/course_documents.php?course_id=' . $courseId);
    } elseif ($action === 'edit') {
        // Corrects a mistake on an already-uploaded document — type,
        // chapter, title, description, and optionally the file/cover
        // itself if a replacement was chosen. Never trusts document_id
        // alone: re-verifies it belongs to THIS lecturer and course before
        // changing anything.
        $documentId = (int) ($_POST['document_id'] ?? 0);
        $documentType = (string) ($_POST['document_type'] ?? '');
        $chapterNumberRaw = (string) ($_POST['chapter_number'] ?? '');
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        $docStmt = $conn->prepare('SELECT id, stored_filename, cover_image_path FROM course_documents WHERE id = ? AND course_id = ? AND uploaded_by_lecturer_id = ?');
        $docStmt->bind_param('iii', $documentId, $courseId, $lecturerRecordId);
        $docStmt->execute();
        $existingDoc = $docStmt->get_result()->fetch_assoc();
        $docStmt->close();

        $validationError = '';
        if (!$existingDoc) {
            $validationError = 'Document not found.';
        } elseif (!array_key_exists($documentType, COURSE_DOCUMENT_TYPES)) {
            $validationError = 'Please select a valid document type.';
        } elseif ($title === '') {
            $validationError = 'Please give this document a title.';
        }

        $chapterNumber = null;
        if ($validationError === '' && $documentType === 'chapter') {
            $chapterNumber = (int) $chapterNumberRaw;
            if ($chapterNumber < 1 || $chapterNumber > COURSE_DOCUMENT_CHAPTER_COUNT) {
                $validationError = 'Please select a valid chapter (1 to ' . COURSE_DOCUMENT_CHAPTER_COUNT . ').';
            }
        }

        // Replacing the file/cover is optional — an empty file input means
        // "keep the one already there", not an error, unlike the upload
        // action where the file is required.
        $newFile = null;
        if ($validationError === '' && ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newFile = save_course_document($_FILES['document']);
            if (!$newFile['success']) {
                $validationError = $newFile['error'];
            }
        }

        $newCover = null;
        if ($validationError === '' && ($_FILES['cover_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newCover = save_course_document_cover($_FILES['cover_image']);
            if (!$newCover['success']) {
                $validationError = $newCover['error'];
            }
        }

        if ($validationError === '') {
            $descriptionParam = $description !== '' ? $description : null;

            if ($newFile !== null) {
                $updateStmt = $conn->prepare(
                    'UPDATE course_documents SET document_type = ?, chapter_number = ?, title = ?, description = ?,
                        stored_filename = ?, original_filename = ?, file_extension = ?, file_size = ? WHERE id = ?'
                );
                $updateStmt->bind_param(
                    'sissssiis',
                    $documentType,
                    $chapterNumber,
                    $title,
                    $descriptionParam,
                    $newFile['stored_filename'],
                    $newFile['original_filename'],
                    $newFile['extension'],
                    $newFile['size'],
                    $documentId
                );
                $updateStmt->execute();
                $updateStmt->close();
                delete_course_document_file((string) $existingDoc['stored_filename']);
            } else {
                $updateStmt = $conn->prepare(
                    'UPDATE course_documents SET document_type = ?, chapter_number = ?, title = ?, description = ? WHERE id = ?'
                );
                $updateStmt->bind_param('sissi', $documentType, $chapterNumber, $title, $descriptionParam, $documentId);
                $updateStmt->execute();
                $updateStmt->close();
            }

            if ($newCover !== null && $newCover['stored_filename'] !== '') {
                $coverUpdStmt = $conn->prepare('UPDATE course_documents SET cover_image_path = ? WHERE id = ?');
                $coverUpdStmt->bind_param('si', $newCover['stored_filename'], $documentId);
                $coverUpdStmt->execute();
                $coverUpdStmt->close();
                delete_course_document_cover_file($existingDoc['cover_image_path']);
            }

            $_SESSION['flash_success'] = 'Document updated.';
        } else {
            $_SESSION['flash_error'] = $validationError;
        }
        redirect_to('lecturer/course_documents.php?course_id=' . $courseId);
    } elseif ($action === 'bulk_upload_chapters') {
        // Upload multiple chapters in one submit instead of repeating the
        // single-document Upload flow 7 times — one file input per chapter
        // number, each optional; a chapter left blank is silently skipped,
        // not an error (a lecturer rarely has all 7 ready at once). Title
        // defaults to "Chapter N" — no per-chapter title/description field,
        // keeping the form to "pick your files and go".
        $uploadedCount = 0;
        $skippedChapters = [];
        for ($chapterNumber = 1; $chapterNumber <= COURSE_DOCUMENT_CHAPTER_COUNT; $chapterNumber++) {
            $fileSlot = $_FILES['chapter_files']['error'][$chapterNumber] ?? UPLOAD_ERR_NO_FILE;
            if ($fileSlot === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $singleFile = [
                'name' => $_FILES['chapter_files']['name'][$chapterNumber] ?? '',
                'type' => $_FILES['chapter_files']['type'][$chapterNumber] ?? '',
                'tmp_name' => $_FILES['chapter_files']['tmp_name'][$chapterNumber] ?? '',
                'error' => $_FILES['chapter_files']['error'][$chapterNumber] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['chapter_files']['size'][$chapterNumber] ?? 0,
            ];

            $saved = save_course_document($singleFile);
            if (!$saved['success']) {
                $skippedChapters[] = 'Chapter ' . $chapterNumber . ' (' . $saved['error'] . ')';
                continue;
            }

            $insertStmt = $conn->prepare(
                'INSERT INTO course_documents
                    (course_id, document_type, chapter_number, title, description, stored_filename, original_filename, file_extension, file_size, cover_image_path, uploaded_by_lecturer_id)
                 VALUES (?, "chapter", ?, ?, NULL, ?, ?, ?, ?, NULL, ?)'
            );
            $chapterTitle = 'Chapter ' . $chapterNumber;
            $insertStmt->bind_param(
                'iisssiii',
                $courseId,
                $chapterNumber,
                $chapterTitle,
                $saved['stored_filename'],
                $saved['original_filename'],
                $saved['extension'],
                $saved['size'],
                $lecturerRecordId
            );
            $insertStmt->execute();
            $insertStmt->close();
            $uploadedCount++;
        }

        if ($uploadedCount === 0 && empty($skippedChapters)) {
            $_SESSION['flash_error'] = 'No chapter files were selected.';
        } else {
            $summary = $uploadedCount . ' chapter' . ($uploadedCount === 1 ? '' : 's') . ' uploaded.';
            if (!empty($skippedChapters)) {
                $summary .= ' Skipped: ' . implode(', ', $skippedChapters) . '.';
            }
            $_SESSION['flash_success'] = $summary;
        }
        redirect_to('lecturer/course_documents.php?course_id=' . $courseId);
    } elseif ($action === 'delete') {
        $documentId = (int) ($_POST['document_id'] ?? 0);

        $docStmt = $conn->prepare('SELECT id, stored_filename, cover_image_path FROM course_documents WHERE id = ? AND course_id = ? AND uploaded_by_lecturer_id = ?');
        $docStmt->bind_param('iii', $documentId, $courseId, $lecturerRecordId);
        $docStmt->execute();
        $docRow = $docStmt->get_result()->fetch_assoc();
        $docStmt->close();

        if (!$docRow) {
            $_SESSION['flash_error'] = 'Document not found.';
        } else {
            $deleteStmt = $conn->prepare('DELETE FROM course_documents WHERE id = ?');
            $deleteStmt->bind_param('i', $documentId);
            $deleteStmt->execute();
            $deleteStmt->close();
            delete_course_document_file((string) $docRow['stored_filename']);
            delete_course_document_cover_file($docRow['cover_image_path']);
            $_SESSION['flash_success'] = 'Document deleted.';
        }
        redirect_to('lecturer/course_documents.php?course_id=' . $courseId);
    }
}

// ---------------------------------------------------------------------
// Data for rendering — every documentable course + every one of its
// documents, grouped by course then by type.
// ---------------------------------------------------------------------
$courses = lecturer_documentable_courses($conn, $lecturerRecordId);

$documentsByCourseAndType = [];
if (!empty($courses)) {
    $courseIds = array_map(static fn ($c) => (int) $c['id'], $courses);
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $docsStmt = $conn->prepare(
        "SELECT id, course_id, document_type, chapter_number, title, description, original_filename,
                file_extension, file_size, cover_image_path, download_count, created_at
         FROM course_documents WHERE course_id IN ($placeholders)
         ORDER BY document_type, chapter_number, created_at DESC"
    );
    $docsStmt->bind_param(str_repeat('i', count($courseIds)), ...$courseIds);
    $docsStmt->execute();
    $allDocs = $docsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $docsStmt->close();
    foreach ($allDocs as $d) {
        $documentsByCourseAndType[(int) $d['course_id']][(string) $d['document_type']][] = $d;
    }
}

// ---------------------------------------------------------------------
// Course picker → per-course detail: the page shows a grid of clickable
// course cards first; picking one opens a bordered detail view scoped to
// just that course (Chapter/Quiz/Assignment tabs), instead of every
// course's full document grid rendered inline at once.
// ---------------------------------------------------------------------
$selectedCourseId = (int) ($_GET['course_id'] ?? 0);
$selectedCourse = null;
foreach ($courses as $c) {
    if ((int) $c['id'] === $selectedCourseId) {
        $selectedCourse = $c;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Course Documents — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/course_documents.css?v=<?= @filemtime(__DIR__ . '/../assets/css/course_documents.css') ?: '1' ?>" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                Access scope: your own assigned courses only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Course Documents</h4>
                    <p class="text-muted mb-0">Chapters, quizzes, and assignments for your courses — each can optionally carry its own cover image.</p>
                </div>
            </div>

            <?php if ($successMessage !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($courses)): ?>
                <div class="admas-card p-4 text-center text-muted">You are not currently assigned to any course.</div>
            <?php elseif ($selectedCourse === null): ?>
                <!-- Course picker — click a course to open its own bordered
                     detail view (Chapter/Quiz/Assignment tabs) below. -->
                <div class="course-pick-grid">
                    <?php foreach ($courses as $c): ?>
                        <?php
                        $cid = (int) $c['id'];
                        $totalCount = array_sum(array_map('count', $documentsByCourseAndType[$cid] ?? []));
                        ?>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/course_documents.php?course_id=<?= $cid ?>" class="course-pick-card admas-card">
                            <div class="course-pick-icon"><i class="bi bi-journal-bookmark-fill"></i></div>
                            <div class="course-doc-title"><?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?></div>
                            <div class="course-doc-sub"><?= htmlspecialchars($c['faculty_name'] . ' · ' . $c['department_name']) ?></div>
                            <div class="course-pick-footer">
                                <span class="badge-pill badge-neutral"><?= $totalCount ?> file<?= $totalCount === 1 ? '' : 's' ?></span>
                                <span class="course-pick-view-btn">View Details <i class="bi bi-arrow-right"></i></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <!-- Single-course detail view. -->
                <a href="<?= htmlspecialchars(BASE_URL) ?>/lecturer/course_documents.php" class="course-doc-back-link">
                    <i class="bi bi-arrow-left"></i> Back to Courses
                </a>
                <?php
                $cid = (int) $selectedCourse['id'];
                $byType = $documentsByCourseAndType[$cid] ?? [];
                $tabId = 'course' . $cid;
                $totalCount = array_sum(array_map('count', $byType));
                ?>
                <div class="course-doc-card admas-card">
                    <div class="course-doc-card-header">
                        <div>
                            <div class="course-doc-title"><?= htmlspecialchars($selectedCourse['code'] . ' — ' . $selectedCourse['name']) ?></div>
                            <div class="course-doc-sub"><?= htmlspecialchars($selectedCourse['faculty_name'] . ' · ' . $selectedCourse['department_name']) ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge-pill badge-neutral"><?= $totalCount ?> file<?= $totalCount === 1 ? '' : 's' ?></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="admasOpenBulkUploadModal(<?= $cid ?>, <?= htmlspecialchars(json_encode($selectedCourse['code'] . ' — ' . $selectedCourse['name'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">
                                <i class="bi bi-cloud-arrow-up-fill"></i> Upload All Chapters
                            </button>
                            <button type="button" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"
                                    onclick="admasOpenUploadModal(<?= $cid ?>, <?= htmlspecialchars(json_encode($selectedCourse['code'] . ' — ' . $selectedCourse['name'], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>)">
                                <i class="bi bi-cloud-upload-fill"></i> Upload
                            </button>
                        </div>
                    </div>

                    <ul class="nav nav-pills course-doc-tabs" role="tablist">
                        <?php foreach (COURSE_DOCUMENT_TYPES as $typeKey => $typeLabel): ?>
                            <?php $typeCount = count($byType[$typeKey] ?? []); ?>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link <?= $typeKey === 'chapter' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#<?= $tabId ?>-<?= $typeKey ?>" type="button" role="tab">
                                    <i class="bi <?= COURSE_DOCUMENT_TYPE_ICONS[$typeKey] ?>"></i> <?= htmlspecialchars($typeLabel) ?> <span class="tab-count"><?= $typeCount ?></span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="tab-content">
                        <?php foreach (COURSE_DOCUMENT_TYPES as $typeKey => $typeLabel): ?>
                            <div class="tab-pane fade <?= $typeKey === 'chapter' ? 'show active' : '' ?>" id="<?= $tabId ?>-<?= $typeKey ?>" role="tabpanel">
                                <?php $typeDocs = $byType[$typeKey] ?? []; ?>
                                <?php if (empty($typeDocs)): ?>
                                    <p class="text-muted small mb-0 py-3">No <?= htmlspecialchars(mb_strtolower($typeLabel)) ?> documents uploaded yet.</p>
                                <?php else: ?>
                                    <div class="doc-file-grid">
                                        <?php foreach ($typeDocs as $doc): ?>
                                            <div class="doc-file-card"<?= $doc['cover_image_path'] ? ' style="background-image: linear-gradient(180deg, rgba(0,0,0,0.05), rgba(0,0,0,0.55)), url(\'' . htmlspecialchars(BASE_URL) . '/uploads/course_documents/' . htmlspecialchars($doc['cover_image_path']) . '\');"' : '' ?>>
                                                <?php if (!$doc['cover_image_path']): ?>
                                                    <div class="doc-file-icon"><i class="bi <?= course_document_icon_class($doc['file_extension']) ?>"></i></div>
                                                <?php endif; ?>
                                                <div class="doc-file-body">
                                                    <?php if ($doc['document_type'] === 'chapter' && $doc['chapter_number']): ?>
                                                        <span class="doc-file-eyebrow">Chapter <?= (int) $doc['chapter_number'] ?></span>
                                                    <?php endif; ?>
                                                    <div class="doc-file-title"><?= htmlspecialchars($doc['title']) ?></div>
                                                    <?php if (!empty($doc['description'])): ?>
                                                        <div class="doc-file-desc"><?= htmlspecialchars($doc['description']) ?></div>
                                                    <?php endif; ?>
                                                    <div class="doc-file-meta">
                                                        <?= strtoupper($doc['file_extension']) ?> &middot; <?= format_file_size((int) $doc['file_size']) ?>
                                                        &middot; <i class="bi bi-download"></i> <?= (int) $doc['download_count'] ?>
                                                    </div>
                                                </div>
                                                <div class="doc-file-actions">
                                                    <a href="<?= htmlspecialchars(BASE_URL) ?>/download_course_document.php?id=<?= (int) $doc['id'] ?>" class="btn-icon-label" title="Download">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                    <button type="button" class="btn-icon-label" title="Edit"
                                                            onclick='admasOpenEditModal(<?= $cid ?>, <?= json_encode([
                                                                "id" => (int) $doc["id"],
                                                                "document_type" => $doc["document_type"],
                                                                "chapter_number" => $doc["chapter_number"] !== null ? (int) $doc["chapter_number"] : 1,
                                                                "title" => $doc["title"],
                                                                "description" => $doc["description"] ?? "",
                                                                "original_filename" => $doc["original_filename"],
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/course_documents.php" onsubmit="return confirm('Delete this document?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="course_id" value="<?= $cid ?>">
                                                        <input type="hidden" name="document_id" value="<?= (int) $doc['id'] ?>">
                                                        <button type="submit" class="btn-icon-label text-danger" title="Delete">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Shared Upload modal, reused for every course card above -->
    <div class="modal fade" id="uploadDocModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/course_documents.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="course_id" id="uploadCourseId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="uploadDocModalCourseName">Upload Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="document_type" id="uploadDocType" required onchange="admasToggleChapterField()">
                                <?php foreach (COURSE_DOCUMENT_TYPES as $typeKey => $typeLabel): ?>
                                    <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="uploadChapterFieldWrap">
                            <label class="form-label">Chapter</label>
                            <select class="form-select" name="chapter_number" id="uploadChapterNumber">
                                <?php for ($chapter = 1; $chapter <= COURSE_DOCUMENT_CHAPTER_COUNT; $chapter++): ?>
                                    <option value="<?= $chapter ?>">Chapter <?= $chapter ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" maxlength="150" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-muted small">(optional)</span></label>
                            <textarea class="form-control" name="description" maxlength="500" rows="2"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">File</label>
                            <input type="file" class="form-control" name="document" required>
                            <div class="form-text">PDF, DOC(X), PPT(X), XLS(X), JPG, PNG, ZIP — up to 25MB.</div>
                        </div>

                        <div class="mb-1">
                            <label class="form-label">Cover Image <span class="text-muted small">(optional)</span></label>
                            <input type="file" class="form-control" name="cover_image" accept="image/*">
                            <div class="form-text">Shown as this document's background/thumbnail. JPG, PNG, GIF, or WEBP — up to 5MB.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-upload"></i> Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit modal, reused for every document across every course card -->
    <div class="modal fade" id="editDocModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/course_documents.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="course_id" id="editCourseId">
                    <input type="hidden" name="document_id" id="editDocumentId">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Document</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="document_type" id="editDocType" required onchange="admasToggleEditChapterField()">
                                <?php foreach (COURSE_DOCUMENT_TYPES as $typeKey => $typeLabel): ?>
                                    <option value="<?= htmlspecialchars($typeKey) ?>"><?= htmlspecialchars($typeLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3" id="editChapterFieldWrap">
                            <label class="form-label">Chapter</label>
                            <select class="form-select" name="chapter_number" id="editChapterNumber">
                                <?php for ($chapter = 1; $chapter <= COURSE_DOCUMENT_CHAPTER_COUNT; $chapter++): ?>
                                    <option value="<?= $chapter ?>">Chapter <?= $chapter ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Title</label>
                            <input type="text" class="form-control" name="title" id="editDocTitle" maxlength="150" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-muted small">(optional)</span></label>
                            <textarea class="form-control" name="description" id="editDocDescription" maxlength="500" rows="2"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Replace File <span class="text-muted small">(optional)</span></label>
                            <input type="file" class="form-control" name="document">
                            <div class="form-text" id="editDocCurrentFile"></div>
                        </div>

                        <div class="mb-1">
                            <label class="form-label">Replace Cover Image <span class="text-muted small">(optional)</span></label>
                            <input type="file" class="form-control" name="cover_image" accept="image/*">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-check2-circle"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bulk Upload All Chapters modal, reused for every course card -->
    <div class="modal fade" id="bulkUploadModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/lecturer/course_documents.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="bulk_upload_chapters">
                    <input type="hidden" name="course_id" id="bulkUploadCourseId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="bulkUploadModalCourseName">Upload All Chapters</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">Pick a file for whichever chapters you have ready — leave the rest blank and upload them later. Each is titled "Chapter N" automatically.</p>
                        <?php for ($chapter = 1; $chapter <= COURSE_DOCUMENT_CHAPTER_COUNT; $chapter++): ?>
                            <div class="mb-2">
                                <label class="form-label small mb-1">Chapter <?= $chapter ?></label>
                                <input type="file" class="form-control form-control-sm" name="chapter_files[<?= $chapter ?>]">
                            </div>
                        <?php endfor; ?>
                        <div class="form-text">PDF, DOC(X), PPT(X), XLS(X), JPG, PNG, ZIP — up to 25MB each.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-cloud-arrow-up-fill"></i> Upload All
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function admasToggleChapterField() {
            const type = document.getElementById('uploadDocType').value;
            const wrap = document.getElementById('uploadChapterFieldWrap');
            wrap.classList.toggle('d-none', type !== 'chapter');
        }

        function admasOpenUploadModal(courseId, courseLabel) {
            document.getElementById('uploadCourseId').value = courseId;
            document.getElementById('uploadDocModalCourseName').textContent = 'Upload to ' + courseLabel;
            admasToggleChapterField();
            new bootstrap.Modal(document.getElementById('uploadDocModal')).show();
        }

        function admasToggleEditChapterField() {
            const type = document.getElementById('editDocType').value;
            const wrap = document.getElementById('editChapterFieldWrap');
            wrap.classList.toggle('d-none', type !== 'chapter');
        }

        function admasOpenEditModal(courseId, doc) {
            document.getElementById('editCourseId').value = courseId;
            document.getElementById('editDocumentId').value = doc.id;
            document.getElementById('editDocType').value = doc.document_type;
            document.getElementById('editChapterNumber').value = doc.chapter_number;
            document.getElementById('editDocTitle').value = doc.title;
            document.getElementById('editDocDescription').value = doc.description;
            document.getElementById('editDocCurrentFile').textContent = 'Current file: ' + doc.original_filename + ' — choose a new one only to replace it.';
            admasToggleEditChapterField();
            new bootstrap.Modal(document.getElementById('editDocModal')).show();
        }

        function admasOpenBulkUploadModal(courseId, courseLabel) {
            document.getElementById('bulkUploadCourseId').value = courseId;
            document.getElementById('bulkUploadModalCourseName').textContent = 'Upload All Chapters — ' + courseLabel;
            new bootstrap.Modal(document.getElementById('bulkUploadModal')).show();
        }
    </script>
</body>
</html>
