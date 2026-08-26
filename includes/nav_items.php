<?php
/**
 * Central permission-gated navigation list shared by every role's sidebar.
 * `roles` mirrors the RBAC scope table in CLAUDE.md §4.
 *
 * Order matters: entries are laid out in `group` order (Overview, Academic
 * Management, Attendance Management, Reports & Analytics, Communication,
 * System Administration, Account) so that filtering this array down to one
 * role's visible items — a stable filter, never a re-sort — always yields a
 * contiguous run per group. sidebar.php relies on this to print each group
 * header exactly once per role, in the same fixed section order for
 * everyone, without needing role-specific logic of its own.
 */
declare(strict_types=1);

/**
 * @return array<int, array{label: string, icon: string, file: string, path?: string, group: string, roles: array<int, string>}>
 */
function nav_items(): array
{
    return [
        // ---- Overview ----
        ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'file' => 'dashboard.php', 'group' => 'Overview', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],

        // ---- Academic Management ----
        // Students/Lecturers/Departments/Courses management all live under /admin
        // regardless of caller — registration and dean are both faculty-appropriate
        // callers of the same file (see the require_role()/faculty-scoping in each).
        ['label' => 'Students', 'icon' => 'bi-people', 'file' => 'students.php', 'path' => 'admin/students.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean']],
        // Full CRUD Lecturer management (university_rector/dean) lives under /admin.
        ['label' => 'Lecturers', 'icon' => 'bi-person-badge', 'file' => 'lecturers.php', 'path' => 'admin/lecturers.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'dean']],
        // Head of Academic Affairs gets its own, read-only, university-wide
        // Lecturers page (plus the "Register New Lecturer" form CLAUDE.md §4
        // grants it) at head_academic/lecturers.php — a different file from
        // the one above, same split-by-role pattern already used below for
        // Notifications. Same 'file' value is fine: roles are disjoint, so a
        // given login only ever matches one of the two entries.
        ['label' => 'Lecturers', 'icon' => 'bi-person-badge', 'file' => 'lecturers.php', 'group' => 'Academic Management', 'roles' => ['head_academic']],
        ['label' => 'Departments', 'icon' => 'bi-diagram-3', 'file' => 'departments.php', 'path' => 'admin/departments.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'head_academic', 'dean']],
        ['label' => 'Faculties', 'icon' => 'bi-bank', 'file' => 'faculties.php', 'path' => 'admin/faculties.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'head_academic']],
        // Previously only reachable via a card inside admin/settings.php —
        // moved to its own CRUD page + sidebar entry so adding a new
        // academic year isn't buried in Settings (see the top-of-file
        // comment on admin/academic_years.php).
        ['label' => 'Academic Years', 'icon' => 'bi-calendar-range', 'file' => 'academic_years.php', 'group' => 'Academic Management', 'roles' => ['university_rector']],
        ['label' => 'Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'path' => 'admin/courses.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // "My Courses" — Lecturer's own assigned courses and Student's own
        // enrolled courses are two different files under two different role
        // folders, but share the 'courses.php' filename and resolve via the
        // default {roleFolder}/{file} convention (no path override needed,
        // same reasoning as the head_academic Lecturers entry above).
        ['label' => 'My Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'group' => 'Academic Management', 'roles' => ['lecturer']],
        ['label' => 'My Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'group' => 'Academic Management', 'roles' => ['student']],
        // Course Documents (lecturer: upload/manage, per Chapter 1-7) and
        // Course Materials (student: browse/download) — two files, same
        // filename-per-role-folder resolution as My Courses above. See
        // includes/course_document_helpers.php for the access boundary.
        ['label' => 'Course Documents', 'icon' => 'bi-folder2-open', 'file' => 'course_documents.php', 'group' => 'Academic Management', 'roles' => ['lecturer']],
        ['label' => 'Course Materials', 'icon' => 'bi-folder2-open', 'file' => 'course_documents.php', 'group' => 'Academic Management', 'roles' => ['student']],
        // Semester/Xiiso session management lives at the app root (shared across roles), same pattern as Attendance below.
        // Dean sees/manages only their own faculty's semesters (enforced in semesters.php itself).
        ['label' => 'Semesters', 'icon' => 'bi-calendar3-week', 'file' => 'semesters.php', 'path' => 'semesters.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // Class Time Table — read-only weekly Day/Time grid across every
        // course_offerings row with a schedule set. Editing happens on
        // admin/course_offerings.php/lecturer_courses.php; this page only
        // visualizes it, including for University Rector, who never edits
        // course_offerings at all.
        ['label' => 'Class Time Table', 'icon' => 'bi-calendar2-week', 'file' => 'class_timetable.php', 'path' => 'class_timetable.php', 'group' => 'Academic Management', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // Student's own version is a distinct file (student/class_timetable.php,
        // own-record-only) sharing the filename with the shared-file page
        // above but resolved via the default per-role-folder convention.
        ['label' => 'Class Time Table', 'icon' => 'bi-calendar2-week', 'file' => 'class_timetable.php', 'group' => 'Academic Management', 'roles' => ['student']],
        // Lecturer's own version — a third, distinct file
        // (lecturer/class_timetable.php, own-current-offerings-only), same
        // filename-sharing/default-convention pattern as the student entry
        // above.
        ['label' => 'Class Time Table', 'icon' => 'bi-calendar2-week', 'file' => 'class_timetable.php', 'group' => 'Academic Management', 'roles' => ['lecturer']],
        ['label' => 'Import Students', 'icon' => 'bi-file-earmark-arrow-up', 'file' => 'students_import.php', 'path' => 'admin/students_import.php', 'group' => 'Academic Management', 'roles' => ['registration']],
        ['label' => 'Download Students', 'icon' => 'bi-cloud-download', 'file' => 'students_download.php', 'path' => 'admin/students_download.php', 'group' => 'Academic Management', 'roles' => ['registration']],

        // ---- Attendance Management ----
        // Lecturer Check-In / Check-Out — a lecturer's own arrival/departure
        // log (distinct from student Attendance below), self-service only.
        ['label' => 'Lecturer Check-In', 'icon' => 'bi-door-open', 'file' => 'checkin.php', 'group' => 'Attendance Management', 'roles' => ['lecturer']],
        // Read-only report over the above, shared by the three roles that
        // need to see it — university-wide for university_rector/head_academic,
        // own-faculty-only for Dean (enforced in lecturer_checkins.php itself).
        // Lives at the app root, same shared-file convention as Attendance/
        // Reports/Notifications/Messages below.
        ['label' => 'Lecturer Check-Ins', 'icon' => 'bi-door-open', 'file' => 'lecturer_checkins.php', 'path' => 'lecturer_checkins.php', 'group' => 'Attendance Management', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // Per-semester attendance breakdown (all semesters, not just the
        // current academic year like My Courses above) — student only.
        ['label' => 'Attendance History', 'icon' => 'bi-clock-history', 'file' => 'attendance_history.php', 'group' => 'Attendance Management', 'roles' => ['student']],
        // Per-semester teaching record (all semesters they've ever held an
        // offering in, not just current) — lecturer's own equivalent of
        // Attendance History above.
        ['label' => 'Teaching History', 'icon' => 'bi-clock-history', 'file' => 'teaching_history.php', 'group' => 'Attendance Management', 'roles' => ['lecturer']],
        // Attendance lives at the app root (shared by all three roles below), not under any one role folder.
        // University Rector and Dean never actually get write access on this
        // page (both are read-only oversight — see
        // user_can_write_course_attendance()), so the sidebar link only ever
        // showed them a disabled grid; the identical data is already
        // reachable read-only via Reports -> "Xiiso Attendance Grid" (both
        // roles already have that report type), so removed here rather than
        // keep a confusing nav link that looks like a write action but isn't.
        ['label' => 'Attendance', 'icon' => 'bi-calendar2-check', 'file' => 'attendance.php', 'path' => 'attendance.php', 'group' => 'Attendance Management', 'roles' => ['lecturer']],
        // Bulk historical-attendance import lives at the app root, same shared-file convention as Attendance above.
        // Dean removed — pure write action (bulk import), no read-only
        // equivalent, and Dean is now a faculty-scoped Viewer only.
        ['label' => 'Import Attendance', 'icon' => 'bi-file-earmark-spreadsheet', 'file' => 'attendance_import.php', 'path' => 'attendance_import.php', 'group' => 'Attendance Management', 'roles' => ['lecturer']],

        // ---- Reports & Analytics ----
        // Reports lives at the app root (shared by five roles), not under any one role folder — same pattern as Attendance above.
        ['label' => 'Reports', 'icon' => 'bi-bar-chart', 'file' => 'reports.php', 'path' => 'reports.php', 'group' => 'Reports & Analytics', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer']],
        // Reports Hub — one landing page gathering every report type (this
        // file's own reports.php entry plus Teaching History/Lecturer
        // Check-Ins) into one front door, role-aware about which cards it
        // shows. Lives at the app root, same shared-file convention.
        ['label' => 'Reports Hub', 'icon' => 'bi-grid-1x2', 'file' => 'reports_hub.php', 'path' => 'reports_hub.php', 'group' => 'Reports & Analytics', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer']],

        // ---- Communication ----
        // Notifications lives at the app root for the management roles (shared file, same override pattern as Attendance/Reports).
        ['label' => 'Notifications', 'icon' => 'bi-bell', 'file' => 'notifications.php', 'path' => 'notifications.php', 'group' => 'Communication', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // A student's own read-only alerts view lives under /student — a different, more restricted page than the one above.
        ['label' => 'Notifications', 'icon' => 'bi-bell', 'file' => 'notifications.php', 'group' => 'Communication', 'roles' => ['student']],
        // Staff Messages — a WhatsApp-style direct-message chat shared by
        // the five staff roles (never students), lives at the app root
        // like Attendance/Reports/Notifications above. See
        // includes/chat_helpers.php's CHAT_STAFF_ROLES for the single
        // source of truth this role list mirrors.
        ['label' => 'Messages', 'icon' => 'bi-chat-dots', 'file' => 'messages.php', 'path' => 'messages.php', 'group' => 'Communication', 'roles' => ['university_rector', 'head_academic', 'dean', 'lecturer', 'registration']],

        // ---- System Administration ----
        ['label' => 'User Management', 'icon' => 'bi-person-gear', 'file' => 'users.php', 'group' => 'System Administration', 'roles' => ['university_rector']],
        // Head of Academic Affairs gets its own User Management at
        // head_academic/users.php — same disjoint-roles-same-filename
        // resolution as the head_academic Lecturers/Notifications entries
        // above. Deliberately NOT the same door as admin/users.php: that
        // page's "System Users" table has no target-role restriction (a
        // university_rector can reset/deactivate another university_rector), and its
        // "Assign Role" panel appoints Dean/Head of Academic Affairs/
        // Registration Office — both are University Rector-only powers
        // per CLAUDE.md §4's "except top management" boundary. This page
        // manages every Dean/Head of Academic Affairs/Registration
        // Office/Lecturer/Student account (full CRUD: reset password,
        // activate/deactivate) but excludes University Rector accounts
        // entirely, server-side as well as in the list.
        ['label' => 'User Management', 'icon' => 'bi-person-gear', 'file' => 'users.php', 'group' => 'System Administration', 'roles' => ['head_academic']],
        ['label' => 'Settings', 'icon' => 'bi-gear', 'file' => 'settings.php', 'group' => 'System Administration', 'roles' => ['university_rector']],
        // Audit Log lives at the app root, not under /admin — same
        // path-override convention as attendance.php/reports.php/etc.
        ['label' => 'Audit Log', 'icon' => 'bi-journal-text', 'file' => 'audit_log.php', 'path' => 'audit_log.php', 'group' => 'System Administration', 'roles' => ['university_rector']],
        // Head of Academic Affairs gets a narrower settings page (Academic
        // Year + minimum attendance threshold only, per CLAUDE.md §4 "Set
        // Academic Year & minimum attendance threshold") — not University
        // Information or the default Faculty/Department scope, which stay
        // university_rector-only on admin/settings.php.
        ['label' => 'Academic Settings', 'icon' => 'bi-calendar-range', 'file' => 'academic_settings.php', 'group' => 'System Administration', 'roles' => ['head_academic']],

        // ---- Account ----
        ['label' => 'Profile & Password', 'icon' => 'bi-person-circle', 'file' => 'profile.php', 'group' => 'Account', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],
        // Logout as its own sidebar nav entry (all 6 roles) — additive, not a
        // replacement for the existing icon-only logout button in
        // includes/topbar.php, which stays exactly as it was. Both point at
        // the same root-level logout.php.
        ['label' => 'Log Out', 'icon' => 'bi-box-arrow-right', 'file' => 'logout.php', 'path' => 'logout.php', 'group' => 'Account', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],
    ];
}

/**
 * Map a role name to its top-level folder under the app root.
 */
function role_folder(string $role): string
{
    $folders = [
        'university_rector' => 'admin',
        'head_academic' => 'head_academic',
        'registration' => 'registration',
        'dean' => 'dean',
        'lecturer' => 'lecturer',
        'student' => 'student',
    ];

    return $folders[$role] ?? '';
}

/**
 * Human-readable label for a role name.
 */
function role_label(string $role): string
{
    $labels = [
        'university_rector' => 'University Rector',
        'head_academic' => 'Head of Academic Affairs',
        'registration' => 'Registration Office',
        'dean' => 'Dean',
        'lecturer' => 'Lecturer',
        'student' => 'Student',
    ];

    return $labels[$role] ?? $role;
}
