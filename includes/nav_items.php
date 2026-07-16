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
        ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'file' => 'dashboard.php', 'roles' => ['system_admin', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],
        // Students/Lecturers/Departments/Courses management all live under /admin
        // regardless of caller — registration and dean are both faculty-appropriate
        // callers of the same file (see the require_role()/faculty-scoping in each).
        ['label' => 'Students', 'icon' => 'bi-people', 'file' => 'students.php', 'path' => 'admin/students.php', 'roles' => ['system_admin', 'registration', 'dean']],
        // Full CRUD Lecturer management (system_admin/dean) lives under /admin.
        ['label' => 'Lecturers', 'icon' => 'bi-person-badge', 'file' => 'lecturers.php', 'path' => 'admin/lecturers.php', 'roles' => ['system_admin', 'dean']],
        // Head of Academic Affairs gets its own, read-only, university-wide
        // Lecturers page (plus the "Register New Lecturer" form CLAUDE.md §4
        // grants it) at head_academic/lecturers.php — a different file from
        // the one above, same split-by-role pattern already used below for
        // Notifications. Same 'file' value is fine: roles are disjoint, so a
        // given login only ever matches one of the two entries.
        ['label' => 'Lecturers', 'icon' => 'bi-person-badge', 'file' => 'lecturers.php', 'roles' => ['head_academic']],
        ['label' => 'Departments', 'icon' => 'bi-diagram-3', 'file' => 'departments.php', 'path' => 'admin/departments.php', 'roles' => ['system_admin', 'dean']],
        ['label' => 'Faculties', 'icon' => 'bi-bank', 'file' => 'faculties.php', 'roles' => ['system_admin']],
        ['label' => 'Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'path' => 'admin/courses.php', 'roles' => ['system_admin', 'dean']],
        // "My Courses" — Lecturer's own assigned courses and Student's own
        // enrolled courses are two different files under two different role
        
        // folders, but share the 'courses.php' filename and resolve via the
        // default {roleFolder}/{file} convention (no path override needed,
        // same reasoning as the head_academic Lecturers entry above).
        ['label' => 'My Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'roles' => ['lecturer']],
        ['label' => 'My Courses', 'icon' => 'bi-journal-bookmark', 'file' => 'courses.php', 'roles' => ['student']],
        // Attendance lives at the app root (shared by all three roles below), not under any one role folder.
        ['label' => 'Attendance', 'icon' => 'bi-calendar2-check', 'file' => 'attendance.php', 'path' => 'attendance.php', 'roles' => ['system_admin', 'dean', 'lecturer']],
        // Semester/Xiiso session management lives at the app root (shared by both roles), same pattern as Attendance above.
        ['label' => 'Semesters', 'icon' => 'bi-calendar3-week', 'file' => 'semesters.php', 'path' => 'semesters.php', 'roles' => ['system_admin', 'head_academic']],
        ['label' => 'Import Students', 'icon' => 'bi-file-earmark-arrow-up', 'file' => 'students_import.php', 'path' => 'admin/students_import.php', 'roles' => ['system_admin', 'registration']],
        // Reports lives at the app root (shared by five roles), not under any one role folder — same pattern as Attendance above.
        ['label' => 'Reports', 'icon' => 'bi-bar-chart', 'file' => 'reports.php', 'path' => 'reports.php', 'roles' => ['system_admin', 'head_academic', 'registration', 'dean', 'lecturer']],
        // Notifications lives at the app root for the management roles (shared file, same override pattern as Attendance/Reports).
        ['label' => 'Notifications', 'icon' => 'bi-bell', 'file' => 'notifications.php', 'path' => 'notifications.php', 'roles' => ['system_admin', 'head_academic', 'dean']],
        // A student's own read-only alerts view lives under /student — a different, more restricted page than the one above.
        ['label' => 'Notifications', 'icon' => 'bi-bell', 'file' => 'notifications.php', 'roles' => ['student']],
        ['label' => 'User Management', 'icon' => 'bi-person-gear', 'file' => 'users.php', 'roles' => ['system_admin']],
        ['label' => 'Settings', 'icon' => 'bi-gear', 'file' => 'settings.php', 'roles' => ['system_admin']],
        // Head of Academic Affairs gets a narrower settings page (Academic
        // Year + minimum attendance threshold only, per CLAUDE.md §4 "Set
        // Academic Year & minimum attendance threshold") — not University
        // Information or the default Faculty/Department scope, which stay
        // system_admin-only on admin/settings.php.
        ['label' => 'Academic Settings', 'icon' => 'bi-calendar-range', 'file' => 'academic_settings.php', 'roles' => ['head_academic']],
        ['label' => 'Profile & Password', 'icon' => 'bi-person-circle', 'file' => 'profile.php', 'roles' => ['system_admin', 'head_academic', 'registration', 'dean', 'lecturer', 'student']],
    ];
}

/**
 * Map a role name to its top-level folder under the app root.
 */
function role_folder(string $role): string
{
    $folders = [
        'system_admin' => 'admin',
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
        'system_admin' => 'System Administrator',
        'head_academic' => 'Head of Academic Affairs',
        'registration' => 'Registration Office',
        'dean' => 'Dean',
        'lecturer' => 'Lecturer',
        'student' => 'Student',
    ];

    return $labels[$role] ?? $role;
}
