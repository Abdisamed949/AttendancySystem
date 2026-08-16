<?php
/**
 * Central permission-gated navigation list shared by every role's sidebar.
 * `roles` mirrors the RBAC scope table in CLAUDE.md §4.
 */
declare(strict_types=1);

/**
 * @return array<int, array{label: string, icon: string, file: string, path?: string, roles: array<int, string>}>
 */
function nav_items(): array
{
    return [
        ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'file' => 'dashboard.php', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],
        // Students/Lecturers/Departments/Courses management all live under /admin
        // regardless of caller — registration and dean are both faculty-appropriate
        // callers of the same file (see the require_role()/faculty-scoping in each).
        ['label' => 'Students', 'icon' => 'bi-people', 'file' => 'students.php', 'path' => 'admin/students.php', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean']],
        // Full CRUD Lecturer management (university_rector/dean) lives under /admin.
        ['label' => 'Lecturers', 'icon' => 'bi-person-badge', 'file' => 'lecturers.php', 'path' => 'admin/lecturers.php', 'roles' => ['university_rector', 'dean']],
        // Head of Academic Affairs gets its own, read-only, university-wide
        // Lecturers page (plus the "Register New Lecturer" form CLAUDE.md §4
        // grants it) at head_academic/lecturers.php — a different file from
        // the one above, same split-by-role pattern already used below for
        // Notifications. Same 'file' value is fine: roles are disjoint, so a
        // given login only ever matches one of the two entries.
        ['label' => 'Lecturers', 'icon' => 'bi-person-badge', 'file' => 'lecturers.php', 'roles' => ['head_academic']],
        ['label' => 'Departments', 'icon' => 'bi-diagram-3', 'file' => 'departments.php', 'path' => 'admin/departments.php', 'roles' => ['university_rector', 'head_academic', 'dean']],
        ['label' => 'Faculties', 'icon' => 'bi-bank', 'file' => 'faculties.php', 'path' => 'admin/faculties.php', 'roles' => ['university_rector', 'head_academic']],
        // Previously only reachable via a card inside admin/settings.php —
        // moved to its own CRUD page + sidebar entry so adding a new
        // academic year isn't buried in Settings (see the top-of-file
        // comment on admin/academic_years.php).
        ['label' => 'Academic Years', 'icon' => 'bi-calendar-range', 'file' => 'academic_years.php', 'roles' => ['university_rector']],
        ['label' => 'Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'path' => 'admin/courses.php', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // "My Courses" — Lecturer's own assigned courses and Student's own
        // enrolled courses are two different files under two different role
        
        // folders, but share the 'courses.php' filename and resolve via the
        // default {roleFolder}/{file} convention (no path override needed,
        // same reasoning as the head_academic Lecturers entry above).
        ['label' => 'My Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'roles' => ['lecturer']],
        // Lecturer Check-In / Check-Out — a lecturer's own arrival/departure
        // log (distinct from student Attendance above), self-service only.
        ['label' => 'Lecturer Check-In', 'icon' => 'bi-door-open', 'file' => 'checkin.php', 'roles' => ['lecturer']],
        // Read-only report over the above, shared by the three roles that
        // need to see it — university-wide for university_rector/head_academic,
        // own-faculty-only for Dean (enforced in lecturer_checkins.php itself).
        // Lives at the app root, same shared-file convention as Attendance/
        // Reports/Notifications/Messages above.
        ['label' => 'Lecturer Check-Ins', 'icon' => 'bi-door-open', 'file' => 'lecturer_checkins.php', 'path' => 'lecturer_checkins.php', 'roles' => ['university_rector', 'head_academic', 'dean']],
        ['label' => 'My Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'roles' => ['student']],
        // Per-semester attendance breakdown (all semesters, not just the
        // current academic year like My Courses above) — student only.
        ['label' => 'Attendance History', 'icon' => 'bi-clock-history', 'file' => 'attendance_history.php', 'roles' => ['student']],
        // Attendance lives at the app root (shared by all three roles below), not under any one role folder.
        ['label' => 'Attendance', 'icon' => 'bi-calendar2-check', 'file' => 'attendance.php', 'path' => 'attendance.php', 'roles' => ['university_rector', 'dean', 'lecturer']],
        // Bulk historical-attendance import lives at the app root, same shared-file convention as Attendance above.
        ['label' => 'Import Attendance', 'icon' => 'bi-file-earmark-spreadsheet', 'file' => 'attendance_import.php', 'path' => 'attendance_import.php', 'roles' => ['dean', 'lecturer']],
        // Semester/Xiiso session management lives at the app root (shared across roles), same pattern as Attendance above.
        // Dean sees/manages only their own faculty's semesters (enforced in semesters.php itself).
        ['label' => 'Semesters', 'icon' => 'bi-calendar3-week', 'file' => 'semesters.php', 'path' => 'semesters.php', 'roles' => ['university_rector', 'head_academic', 'dean']],
        ['label' => 'Import Students', 'icon' => 'bi-file-earmark-arrow-up', 'file' => 'students_import.php', 'path' => 'admin/students_import.php', 'roles' => ['registration']],
        // Reports lives at the app root (shared by five roles), not under any one role folder — same pattern as Attendance above.
        ['label' => 'Reports', 'icon' => 'bi-bar-chart', 'file' => 'reports.php', 'path' => 'reports.php', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer']],
        // Notifications lives at the app root for the management roles (shared file, same override pattern as Attendance/Reports).
        ['label' => 'Notifications', 'icon' => 'bi-bell', 'file' => 'notifications.php', 'path' => 'notifications.php', 'roles' => ['university_rector', 'head_academic', 'dean']],
        // A student's own read-only alerts view lives under /student — a different, more restricted page than the one above.
        ['label' => 'Notifications', 'icon' => 'bi-bell', 'file' => 'notifications.php', 'roles' => ['student']],
        // Staff Messages — a WhatsApp-style direct-message chat shared by
        // the five staff roles (never students), lives at the app root
        // like Attendance/Reports/Notifications above. See
        // includes/chat_helpers.php's CHAT_STAFF_ROLES for the single
        // source of truth this role list mirrors.
        ['label' => 'Messages', 'icon' => 'bi-chat-dots', 'file' => 'messages.php', 'path' => 'messages.php', 'roles' => ['university_rector', 'head_academic', 'dean', 'lecturer', 'registration']],
        ['label' => 'User Management', 'icon' => 'bi-person-gear', 'file' => 'users.php', 'roles' => ['university_rector']],
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
        ['label' => 'User Management', 'icon' => 'bi-person-gear', 'file' => 'users.php', 'roles' => ['head_academic']],
        ['label' => 'Settings', 'icon' => 'bi-gear', 'file' => 'settings.php', 'roles' => ['university_rector']],
        // Head of Academic Affairs gets a narrower settings page (Academic
        // Year + minimum attendance threshold only, per CLAUDE.md §4 "Set
        // Academic Year & minimum attendance threshold") — not University
        // Information or the default Faculty/Department scope, which stay
        // university_rector-only on admin/settings.php.
        ['label' => 'Academic Settings', 'icon' => 'bi-calendar-range', 'file' => 'academic_settings.php', 'roles' => ['head_academic']],
        ['label' => 'Profile & Password', 'icon' => 'bi-person-circle', 'file' => 'profile.php', 'roles' => ['university_rector', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],
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
