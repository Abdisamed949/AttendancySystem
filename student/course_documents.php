<?php
/**
 * Course Materials (Student only) — read-only browse/download of every
 * course's documents (Chapter/Quiz/Assignment), one card per course, same
 * layout as lecturer/course_documents.php's manage view. Every course
 * listed and every download is re-verified against
 * student_can_access_course_documents() server-side (never a raw document
 * id trusted alone) — see §23 of the project spec ("documents should not
 * be publicly accessible").
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/course_document_helpers.php';

require_role(['student']);

$conn = db();
$currentUser = current_user();

$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

$stuStmt = $conn->prepare('SELECT id FROM students WHERE user_id = ?');
$stuStmt->bind_param('i', $currentUser['id']);
$stuStmt->execute();
$stuRow = $stuStmt->get_result()->fetch_assoc();
$stuStmt->close();
$studentRecordId = $stuRow ? (int) $stuRow['id'] : 0;

$courses = student_accessible_courses($conn, $studentRecordId);

$search = trim((string) ($_GET['search'] ?? ''));

$documentsByCourseAndType = [];
if (!empty($courses)) {
    $courseIds = array_map(static fn ($c) => (int) $c['id'], $courses);
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    if ($search !== '') {
        $docsStmt = $conn->prepare(
            "SELECT id, course_id, document_type, chapter_number, title, description, original_filename,
                    file_extension, file_size, cover_image_path, created_at
             FROM course_documents WHERE course_id IN ($placeholders) AND (title LIKE ? OR description LIKE ?)
             ORDER BY document_type, chapter_number, created_at DESC"
        );
        $like = '%' . $search . '%';
        $types = str_repeat('i', count($courseIds)) . 'ss';
        $params = array_merge($courseIds, [$like, $like]);
        $docsStmt->bind_param($types, ...$params);
    } else {
        $docsStmt = $conn->prepare(
            "SELECT id, course_id, document_type, chapter_number, title, description, original_filename,
                    file_extension, file_size, cover_image_path, created_at
             FROM course_documents WHERE course_id IN ($placeholders)
             ORDER BY document_type, chapter_number, created_at DESC"
        );
        $docsStmt->bind_param(str_repeat('i', count($courseIds)), ...$courseIds);
    }
    $docsStmt->execute();
    $allDocs = $docsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $docsStmt->close();
    foreach ($allDocs as $d) {
        $documentsByCourseAndType[(int) $d['course_id']][(string) $d['document_type']][] = $d;
    }
}

// ---------------------------------------------------------------------
// Course picker → per-course detail: same restructuring as
// lecturer/course_documents.php — a grid of clickable course cards first,
// picking one opens a bordered detail view scoped to just that course.
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
    <title>Course Materials — ADMAS Attendance System</title>
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
                Access scope: Own personal record only
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Course Materials</h4>
                    <p class="text-muted mb-0">Chapters, quizzes, and assignments your lecturers have shared, across every one of your courses.</p>
                </div>
            </div>

            <?php if (empty($courses)): ?>
                <div class="admas-card p-4 text-center text-muted">No courses found for your record yet.</div>
            <?php elseif ($selectedCourse === null): ?>
                <!-- Course picker — click a course to open its own bordered
                     detail view (Chapter/Quiz/Assignment tabs) below. -->
                <div class="course-pick-grid">
                    <?php foreach ($courses as $c): ?>
                        <?php
                        $cid = (int) $c['id'];
                        $totalCount = array_sum(array_map('count', $documentsByCourseAndType[$cid] ?? []));
                        ?>
                        <a href="<?= htmlspecialchars(BASE_URL) ?>/student/course_documents.php?course_id=<?= $cid ?>" class="course-pick-card admas-card">
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
                <a href="<?= htmlspecialchars(BASE_URL) ?>/student/course_documents.php" class="course-doc-back-link">
                    <i class="bi bi-arrow-left"></i> Back to Courses
                </a>

                <div class="admas-card p-3 mb-3">
                    <form method="get" action="<?= htmlspecialchars(BASE_URL) ?>/student/course_documents.php" class="d-flex flex-wrap gap-2 align-items-end">
                        <input type="hidden" name="course_id" value="<?= (int) $selectedCourse['id'] ?>">
                        <div class="flex-grow-1" style="max-width: 320px;">
                            <label class="form-label small mb-1">Search this course</label>
                            <input type="text" class="form-control form-control-sm" name="search" placeholder="Search title or description" value="<?= htmlspecialchars($search) ?>">
                        </div>
                        <button type="submit" class="btn btn-sm text-white" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <?php if ($search !== ''): ?>
                            <a href="<?= htmlspecialchars(BASE_URL) ?>/student/course_documents.php?course_id=<?= (int) $selectedCourse['id'] ?>" class="small">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

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
                        <span class="badge-pill badge-neutral"><?= $totalCount ?> file<?= $totalCount === 1 ? '' : 's' ?></span>
                    </div>

                    <?php if ($totalCount === 0): ?>
                        <p class="text-muted small mb-0 py-2"><?= $search !== '' ? 'No documents match your search.' : 'No documents have been uploaded for this course yet.' ?></p>
                    <?php else: ?>
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
                                        <p class="text-muted small mb-0 py-3">No <?= htmlspecialchars(mb_strtolower($typeLabel)) ?> documents.</p>
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
                                                            &middot; <?= htmlspecialchars(date('M j, Y', strtotime((string) $doc['created_at']))) ?>
                                                        </div>
                                                    </div>
                                                    <div class="doc-file-actions">
                                                        <a href="<?= htmlspecialchars(BASE_URL) ?>/download_course_document.php?id=<?= (int) $doc['id'] ?>" class="btn-icon-label text-sky" title="Download">
                                                            <i class="bi bi-download"></i> Download
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
