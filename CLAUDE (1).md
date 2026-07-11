# CLAUDE.md — ADMAS Attendance Management System

This file gives Claude Code full context on this project. Read it before writing any code.

## 1. Project Overview

A university attendance management system for **ADMAS University** (Garoowe Campus,
Puntland, Somalia), built as a thesis-level system design project. It replaces manual
attendance tracking with a role-based web application covering student, lecturer,
department, faculty and course management, attendance marking, reporting, and
low-attendance alerts.

**Project root:** `C:\xampp\htdocs\AttendancySystem`
**Branding assets (logo, official colors):** `C:\xampp\htdocs\AttendancySystem\logo`

**Important for Claude Code:** Before generating any header, sidebar, login page, or
any other branded UI, check the `logo` folder for the actual ADMAS University logo
file(s) and any existing brand color reference. Use the real logo image (not a text
placeholder like "AD") and match the real official colors if they differ from the
sky-blue/navy palette described in §3 below. If the `logo` folder only contains the
logo image and no documented color palette, keep using the sky-blue (`#0ea5e9`) /
navy (`#0b1f3a`) palette from this file, but always insert the real logo image in
place of the placeholder mark.

Reference document: `ADMAS_Chapter_Four_System_Design_Updated.docx` (Chapter Four —
System Design) contains the full UI mockups and screen-by-screen specification this
build should follow.

## 2. Tech Stack (match existing ADMAS Library System conventions)

- **Backend:** PHP, procedural style (no frameworks), `mysqli` with **prepared
  statements** for every query — never concatenate user input into SQL.
- **Database:** MySQL.
- **Frontend:** Bootstrap 5, vanilla JavaScript, Chart.js for the dashboard charts.
- **Excel import:** PhpSpreadsheet (or a lightweight CSV fallback if PhpSpreadsheet
  is unavailable in the hosting environment).
- **Auth:** PHP sessions. Store `user_id`, `role`, and (for scoped roles) `faculty_id`
  in `$_SESSION` after login. Every protected page must check the session and the
  role before rendering.
- **No JS frameworks** (no React/Vue) — this is a traditional server-rendered PHP app,
  consistent with the Library Management System.

## 3. Design System

- **Colors:** Sky-blue `#0ea5e9` (primary/accent), Navy `#0b1f3a` → `#122a4d`
  (sidebar gradient), light background `#f3f6fb`.
- **Layout:** Fixed dark navy sidebar with nav icons (Bootstrap Icons) + a two-part
  top area: a thin **sky-blue header strip** (university location, email, phone —
  no footer anywhere in the app) above a white **topbar** (search, notification bell,
  user avatar/name/role).
- **Components:** Rounded white cards (`border-radius: 14px`), KPI stat cards with
  colored icon chips, pill-shaped status badges (Active/Inactive, Present/Absent/
  Late/Excused), Bootstrap 5 tables with uppercase muted headers.
- **Login page:** Split-screen card — left panel is a navy/sky gradient brand panel,
  right panel is the form. **A single "Role" dropdown** (not separate tabs) lists all
  six roles; after submit, redirect based on the selected + verified role.

## 4. User Roles & Permissions (RBAC)

Six roles, ranked by scope (widest to narrowest):

| Role | Scope | Can do | Cannot do |
|---|---|---|---|
| **System Administrator** | Whole system | Full technical control: all CRUD, User Management, role appointment, system Settings, Notification thresholds, backups | — |
| **Head of Academic Affairs** | All faculties | Set Academic Year & minimum attendance threshold; view cross-faculty reports; **register new Lecturer accounts** | Cannot manage students, delete accounts, or edit system Settings |
| **Registration Office** | All faculties | Add/edit students, bulk Excel import of students, enrollment reports | No access to Attendance or Settings |
| **Dean** | **Own faculty only** | Full CRUD on Departments, Courses, Lecturers, Students, Attendance *within their faculty*; faculty-scoped reports | Cannot view/edit other faculties, no system Settings, no User Management |
| **Lecturer** | Own assigned courses only | Take attendance, view "My Courses" (filtered by Academic Year + Faculty + Department to disambiguate duplicate course codes across faculties), class reports | Cannot see other lecturers' courses or student management screens |
| **Student** | Own record only | View own attendance %, enrolled courses, profile | Read-only, no management screens |

Every role also has a **"Profile & Password"** screen to edit their own details and
change their password.

The **System Administrator** appoints users into the Dean / Head of Academic Affairs /
Registration Office roles from **User Management**; when appointing a Dean, the
Admin must also select which single Faculty that Dean will oversee.

**Sidebar navigation must be generated per-role from a permission list** — do not
show a nav item for a module the current role cannot access. Every restricted page
should also render a short scope banner (e.g. "Access scope: Engineering & IT
Faculty only") reminding the user of their boundary.

## 5. Core Modules (build in roughly this order)

1. **Database schema** (see §6) + seed data (faculties, departments, roles, one
   admin user).
2. **Login** — single Role selector, session-based auth, redirect per role.
3. **Faculty Management** — CRUD (Admin only).
4. **Department Management** — CRUD + Excel import; each department belongs to one
   Faculty.
5. **Course Management** — CRUD + Excel import; each course belongs to one
   Department and is assigned to one Lecturer.
6. **Lecturer Management** — CRUD + Excel import; auto-create a Lecturer login
   account when a lecturer record is created.
7. **Student Management** — CRUD + Excel import; each student has Academic Year,
   Faculty, Department, Level, and **Shift** (see below); auto-create a Student
   login account.
8. **Import Students from Excel** — select Academic Year, Semester, Faculty,
   Department, Level, Shift → upload → preview with per-row validation (flag
   missing/invalid fields) → confirm import.
9. **Attendance** — Lecturer selects Academic Year, Faculty, Course, **Shift**, Date
   → loads the student roster for that exact combination → marks each student
   Present / Absent / Late / Excused → saves.
10. **Reports** — filter by course/department/date range, export to PDF and Excel.
11. **Notifications / Alerts** — in-app only (no email/SMS in this version).
    Recalculate each student's attendance % per course; if it drops below the
    configurable threshold (default 75%, editable in Settings by Admin or Head of
    Academic Affairs), surface it in: the Notifications screen, an "Attendance
    Alerts" widget on the Dashboard, and (if enabled) the student's own dashboard.
12. **User Management** — list/reset-password/activate-deactivate all users; Role
    Assignment panel to appoint Dean (+ Faculty)/Head of Academic Affairs/
    Registration Office.
13. **Settings** — university info (name, campus, contact), current Academic Year,
    default Faculty/Department scope, minimum attendance threshold.
14. **Role dashboards** — a distinct, permission-filtered dashboard view per role
    (see §4), each with its own KPI cards relevant to that role's scope.

### Important naming detail: "Shift", not "Section"
This system uses **Shift** (`Morning Shift`, `Afternoon Shift`, `Weekend`) — not
"Section A/B/C" — as the class-grouping field for students, courses and attendance.
Use an ENUM or a small lookup table with exactly these three values everywhere a
shift is stored or selected.

## 6. Suggested Database Schema (core tables)

```
users            (id, username, password_hash, full_name, email, role, status, last_login)
roles            (id, name)  -- system_admin, head_academic, registration, dean, lecturer, student
faculties        (id, name, dean_user_id NULL)
departments      (id, name, code, faculty_id)
academic_years   (id, label, is_current)
courses          (id, code, name, department_id, lecturer_id, credit_hours)
students         (id, student_no, full_name, user_id, academic_year_id, faculty_id,
                   department_id, level, shift, status)
lecturers        (id, staff_no, full_name, user_id, department_id)
attendance       (id, student_id, course_id, academic_year_id, shift, date, status,
                   recorded_by_user_id)
                   -- status: present | absent | late | excused
notifications    (id, student_id, course_id, attendance_pct, threshold_at_time,
                   created_at, is_read)
settings         (id, key, value)  -- university_name, campus, contact_email, contact_phone,
                                       current_academic_year_id, min_attendance_pct
role_assignments (id, user_id, role, faculty_id NULL) -- faculty_id only used for 'dean'
```

Adjust field names to match whatever conventions the Library Management System
already uses in this project, for consistency across the two systems.

## 7. Coding Conventions

- One PHP file per screen/action, procedural, under a clear folder structure
  (e.g. `/admin`, `/dean`, `/lecturer`, `/student`, `/includes`, `/config`).
- Central `includes/db.php` for the `mysqli` connection; central
  `includes/auth.php` for session/role checks, included at the top of every
  protected page.
- All SQL through prepared statements (`mysqli_prepare` / `bind_param`).
- Escape all output (`htmlspecialchars`) to prevent XSS.
- Reuse the shared sidebar/topbar/header include across all pages, parameterized by
  the current user's role so the nav list and scope banner render correctly.

## 8. Explicitly Out of Scope (documented in Chapter Five as future work)

- Course Scheduling / Timetable module (day/time/room booking).
- Email or SMS delivery for notifications (in-app alerts only for now).

## 9. Next Steps for This Chat / Claude Code Session

1. Confirm/adjust the database schema in §6 against the existing Library System's
   conventions.
2. Scaffold the folder structure and `includes/db.php`, `includes/auth.php`.
3. Build Login + session handling with the single Role dropdown.
4. Build Faculty → Department → Course → Lecturer → Student CRUD, in that order,
   since each depends on the one before it.
5. Build Attendance marking, then Reports, then Notifications.
6. Build User Management + Role Assignment, then Settings.
7. Build the five restricted role dashboards last, once all underlying data and
   permissions logic exist.

## 10. Progress Log

**Read this section first in every new session to know exactly where the
project stands. Update it (or ask Claude Code to update it) every time a
module is finished, before ending a session.**

**All 14 core modules listed in §9 are now complete**, including Profile &
Password and real (non-placeholder) dashboards for every one of the 6
roles. There is no "← START HERE" pointer below anymore because there is
no next planned module — remaining work is polish/hardening items listed
under "Known Gaps / Things to Revisit" at the end of this section.

### Done
- [x] Database schema created (`admas_attendance_schema.sql`) and imported into
      MySQL as database `admas_attendance`.
- [x] `includes/db.php` — mysqli connection.
- [x] `includes/auth.php` — session/role checks, `BASE_URL` fixed to
      `/AttendancySystem`, `require_login()`, `require_role()`, `current_user()`,
      `current_role()`, `can_access_faculty()`, `redirect_to()`.
- [x] `login.php` — single Role dropdown (all 6 roles), verifies username +
      password + selected role all match, stores `role` (name, not id) in
      session. Tested and working for all 6 roles.
- [x] 6 placeholder dashboards created (one per role) so login redirects don't
      404: `admin/dashboard.php`, `dean/dashboard.php`, `head_academic/dashboard.php`,
      `registration/dashboard.php`, `lecturer/dashboard.php`, `student/dashboard.php`.
- [x] `admin/dashboard.php` — full real UI built: navy sidebar with real ADMAS
      logo from `/logo`, sky-blue top contact strip pulling from `settings`
      table, white topbar with search/bell/avatar from `current_user()`, 4 KPI
      cards with real DB counts, Chart.js weekly attendance trend, Attendance
      Alerts widget. This is the shared layout pattern all other pages reuse.
- [x] `admin/faculties.php` — full CRUD (add/edit/delete), Dean dropdown,
      delete blocked if departments are linked. Tested: add, edit, delete all
      confirmed working.
- [x] `admin/departments.php` — full CRUD, Faculty dropdown, unique code per
      faculty enforced, delete blocked if students/lecturers/courses linked.
      Tested and working.
- [x] `admin/departments_import.php` — Excel import for departments
      (PhpSpreadsheet), preview + validation before confirm.
- [x] Manually fixed a data gap via phpMyAdmin: `dean01` had no faculty
      assigned because User Management (role appointment) had not been built
      yet when the seed script ran. Ran
      `UPDATE users SET role_id = 4, faculty_id = 1 WHERE username = 'dean01';`
      as a temporary fix so Faculty Management's Dean dropdown would populate.
      **This manual fix should be superseded once admin/users.php (Assign Role)
      is built — re-verify dean01 still looks correct through the real UI once
      that page exists.**
- [x] Test accounts exist in the database (from `seed_test_users.php`, run once):
      admin01, head01, reg01, dean01, lec01, std01 — all password `Test@12345`.
      **Reminder: delete `seed_test_users.php` before the system goes live.**

### Done (continued)
- [x] `admin/courses.php` — full CRUD (add/edit/delete), Department dropdown
      grouped by Faculty via `<optgroup>`, Lecturer dropdown filtered
      client-side (JS) to only lecturers in the selected Department, unique
      code enforced per Department (same code allowed to repeat across other
      Departments/Faculties), delete blocked if attendance records or student
      enrollments are linked. `admin/courses_import.php` — Excel import with
      preview/validation (unknown department, duplicate code+department combo,
      unknown lecturer name, invalid credit hours), reusing the
      departments_import.php PhpSpreadsheet pattern. Tested end-to-end via
      HTTP requests against the live app: create, same-code-different-department
      allowed, same-code-same-department blocked, edit, delete blocked by a
      linked enrollment, delete succeeded once unblocked, and Excel import
      (2 valid rows imported, 3 invalid rows correctly flagged and skipped).
      All test data created during verification was cleaned up afterward.

### Done (continued 2)
- [x] `admin/lecturers.php` — full CRUD (add/edit/delete), Department dropdown
      grouped by Faculty via `<optgroup>`, # Courses Assigned column (COUNT
      from `courses`), Status pill from the linked user account. Add Lecturer
      auto-generates a username (first initial + last name, numeric suffix on
      collision) and a random temporary password, inserting into `users` +
      `lecturers` in one transaction (rolls back both on failure) and showing
      the credentials once in a success alert. Edit updates name/email/
      department only (staff no, username, password untouched). Delete is
      blocked with a clear message if the lecturer has courses assigned or
      attendance records they recorded (checked via `recorded_by_user_id` on
      their linked user, not the lecturer id); once unblocked, delete removes
      the `lecturers` row and deactivates (not deletes) the linked `users`
      row. Reset Password regenerates and shows a new temporary password
      once. `admin/lecturers_import.php` — Excel import (Staff No, Full Name,
      Email, Department), preview flags unknown department, duplicate/
      existing staff no, duplicate/existing email, and invalid email format;
      confirm applies the same per-row account-creation transaction as the
      manual form and renders a one-time results table of generated
      usernames/passwords (deliberately not a redirect, since those
      credentials can't be shown again). Shared username/password generation
      logic lives in `includes/lecturer_accounts.php`, used by both files.
      Tested end-to-end via HTTP requests against the live app: create
      (including username collision producing a numeric suffix), duplicate
      staff no rejected, edit, reset password (verified the shown plaintext
      against the stored hash with `password_verify`), delete blocked by an
      assigned course, delete blocked by an attendance record, delete
      succeeding once unblocked (and correctly deactivating rather than
      deleting the user), and Excel import (2 valid rows imported with
      correct generated credentials, 3 invalid rows correctly flagged and
      skipped). All test data created during verification was cleaned up
      afterward. (Also hit an unrelated MariaDB crash — an InnoDB OS-level
      read error — mid-session; restarted the service via `mysql_start.bat`
      and confirmed no data loss before continuing.)

### Done (continued 3)
- [x] `admin/students.php` — full CRUD (add/edit/delete), table shows Student
      No/Full Name/Academic Year/Faculty/Department/Level/Shift (friendly
      label, e.g. "Morning Shift")/Status, with a filter bar (Academic Year,
      Faculty, Department — Department filterable independently via JS, not
      locked to a chosen Faculty) plus a name/student-no search box, all
      applied as real SQL `WHERE` conditions via a dynamically built prepared
      statement (not client-side filtering). Add Student form: Full Name,
      Email, Academic Year, Faculty, Department (JS-filtered by selected
      Faculty), Level (1-5), Shift; validates the department actually
      belongs to the selected faculty. On create: auto-generates a
      student_no (`ADM-{year}-{sequence}`), a username (first name lowercase
      + "123", numeric suffix on collision), and a temp password, inserting
      into `users` + `students` in one transaction (rolled back together on
      failure), shown once in the success alert. Edit updates
      name/email/academic year/faculty/department/level/shift (student_no,
      username, password untouched). Delete blocked if attendance records or
      course enrollments exist; once unblocked, deletes the `students` row
      and deactivates (not deletes) the linked `users` row. Reset Password
      follows the same one-time-reveal pattern as lecturers.php.
      `admin/students_import.php` — Excel import (Full Name, Email, Academic
      Year, Faculty, Department, Level, Shift accepting either the enum
      value or the friendly label), preview flags unknown academic
      year/faculty/department, department-not-in-faculty, invalid level,
      invalid shift, and bad/duplicate email; confirm applies the same
      per-row account-creation transaction as the manual form, then
      redirects back to `admin/students.php` with a success + skipped-count
      flash message (same redirect pattern as courses_import.php — generated
      credentials are not re-displayed after a bulk import; admins use
      per-student Reset Password if credentials need reissuing). Added
      `generate_student_username()` and `generate_student_no()` to the
      shared `includes/lecturer_accounts.php` helper file (also updated its
      header comment — it's now shared by both lecturer and student account
      creation). Tested end-to-end via HTTP requests against the live app:
      create (including username collision producing a numeric suffix),
      faculty/department mismatch rejected, edit, reset password, faculty
      filter, name search, delete blocked by a course enrollment, delete
      succeeding once unblocked (with correct user deactivation), and Excel
      import (2 valid rows imported, 5 invalid rows correctly flagged and
      skipped for every validation rule). All test data created during
      verification was cleaned up afterward.

### Done (continued 4)
- [x] `attendance.php` — Attendance marking screen, built at the app root
      (not under `/admin`) since it's shared by three roles rather than
      owned by one; `includes/sidebar.php` now supports an optional
      per-nav-item `path` override (used only by this item) so the shared
      sidebar can link to a root-level page instead of the usual
      `{roleFolder}/{file}` convention. Wrapped with
      `require_role(['system_admin', 'dean', 'lecturer'])`. Course dropdown
      is scoped per role via the SQL `WHERE` clause itself (not just hidden
      in the UI): system_admin sees all courses grouped by Faculty —
      Department `<optgroup>`s (with a Faculty filter that rebuilds the
      Course `<select>` client-side, same JS-rebuild pattern as
      `admin/courses.php`'s department→lecturer filter); dean sees only
      courses in their own faculty (`$_SESSION['faculty_id']`, department-
      grouped, Faculty control locked/disabled) — the dean's faculty is
      always read from the session server-side and never trusted from
      request input; lecturer sees only courses where `lecturer_id` matches
      their own `lecturers.id` (looked up via `current_user()['id']` →
      `lecturers.user_id`), flat list, no Faculty control shown at all.
      Filters: Academic Year, Faculty (role-dependent as above), Course,
      Shift, Date (defaults to today) — Load Students is a GET request;
      roster query tries `course_enrollments` first and falls back to
      students in the course's department/academic year/shift if no
      enrollments exist yet. Roster table: Student No, Full Name, four
      required radio buttons per row (Present/Absent/Late/Excused) — each
      row's radios share a per-student `required` group so native HTML5
      validation blocks submission of any row left unset; re-verified
      server-side too. Save uses one `INSERT ... ON DUPLICATE KEY UPDATE`
      per student inside a transaction, keyed off the existing
      `uq_attendance_once_per_day` unique constraint, so re-marking the same
      course+date loads and edits the existing values instead of erroring.
      After saving, redirects back to the same filters (+ `load=1`) so the
      roster reloads showing what was just saved, with a
      `flash_success` count message and a colored badge-pill summary row
      (Present/Absent/Late/Excused counts) above the table. Tested
      end-to-end via HTTP requests against the live app with three
      temporary test accounts (one per role: system_admin, dean, lecturer —
      created via direct SQL since `seed_test_users.php` had already been
      deleted and its accounts' current passwords are unknown): admin
      loaded the roster and saved Present for a student; dean (locked to
      Engineering & IT) saw only that faculty's courses, loaded the same
      course+date and saw the admin's Present pre-filled, changed it to
      Late and saved (confirmed the same `attendance` row was updated, not
      duplicated, and `recorded_by_user_id` changed to the dean's user id);
      lecturer (assigned to the same course for the test) saw only their
      own course with no Faculty control, confirmed a crafted request for a
      course_id belonging to a different faculty was rejected server-side
      ("Please select a valid course."), confirmed submitting a save with a
      student missing a status was rejected with the roster re-shown
      (not lost) and an error message, then saved Excused successfully
      (confirmed the row updated again with the lecturer's user id). All
      temporary users/lecturer/course-assignment/attendance rows created
      for this test were deleted afterward.

### Done (continued 5)
- [x] `reports.php` — role-aware Reports screen, built at the app root
      (not under any one role folder) since it's shared by five roles;
      `includes/nav_items.php`'s Reports entry now has a `path` override
      (it was pointing at the wrong `{roleFolder}/reports.php` URL before
      this — fixed alongside this build, same override style already used
      by Attendance). Wrapped with `require_role(['system_admin',
      'head_academic', 'dean', 'registration', 'lecturer'])`. Three report
      types — Course Attendance Summary, Department Summary, Faculty
      Summary — each built by its own function returning a generic
      `[$columns, $rows]` shape so the HTML table, Excel export, and PDF
      export all share one render loop instead of three hand-written
      tables. Report Type options are filtered per role: system_admin/
      head_academic see all three; dean sees Course Attendance + Department
      Summary only (no Faculty Summary, since their scope is already a
      single faculty); registration sees Department + Faculty Summary only
      (no Attendance access per the RBAC table, so those two report types
      show a "Total Enrollments" column from `course_enrollments` instead
      of Avg Present/Absent %); lecturer sees Course Attendance Summary
      only. Scoping is enforced server-side the same way as
      attendance.php: system_admin/head_academic/registration get a real
      Faculty dropdown (All Faculties + list) with a Department dropdown
      cascaded via the same JS rebuild pattern as students.php; dean's
      Faculty is locked/disabled and read only from
      `$_SESSION['faculty_id']`, with the Department dropdown pre-scoped
      to just their faculty's departments; lecturer has no Faculty/
      Department controls at all — scoped directly by their
      `lecturers.id` (resolved via `current_user()['id']` →
      `lecturers.user_id`, same lookup as attendance.php). Course
      Attendance Summary computes Total Sessions
      (`COUNT(DISTINCT attendance_date)`), Avg Present % and Avg Absent %
      (`SUM(status = 'present'|'absent') / COUNT(*)`) grouped by course
      via a `LEFT JOIN` to `attendance` filtered by the selected date
      range (defaults to the current month); Department/Faculty Summary
      roll up course/student counts plus the same attendance percentages
      (or enrollment counts for registration) via separate grouped queries
      merged in PHP, avoiding join fan-out. Export Excel
      (PhpSpreadsheet, reusing the same library as the import features)
      and Export PDF (dompdf, newly added via `composer require
      dompdf/dompdf` — see `composer.json`) both reuse the exact same
      filtered `$reportColumns`/`$reportRows` as the on-screen table; the
      PDF header embeds the real ADMAS logo (base64 data URI, so no remote
      fetching needed) plus the university name/campus/contact line above
      the report title, rendered A4 landscape. Tested end-to-end via HTTP
      requests against the live app with temporary test accounts (one per
      role: system_admin, head_academic, dean, registration, lecturer, plus
      a student account to confirm the block) and temporary attendance/
      enrollment rows: verified all three report types' figures against
      hand-computed expected percentages for system_admin; verified
      head_academic sees the same three types as system_admin;
      registration correctly restricted to Department/Faculty Summary with
      the Total Enrollments column (verified counts) instead of attendance
      %; dean correctly restricted to two report types and locked to
      Engineering & IT (crafted requests for a different `faculty_id` and
      for `report_type=faculty_summary` were both silently ignored/
      clamped server-side, confirmed by the response still showing only
      the dean's own faculty and only the two allowed types); lecturer
      correctly saw exactly their one assigned course with no Faculty/
      Department controls; the student role was correctly blocked
      (redirected, not rendered); the empty-state "No data for the
      selected filters" row was confirmed by filtering to a
      non-existent department; Export Excel was downloaded and re-read
      with PhpSpreadsheet to confirm the title/period header rows and
      data rows matched the on-screen table; Export PDF was downloaded and
      its decompressed content stream inspected to confirm the ADMAS logo
      image, university name/contact line, report title, and table
      headers/rows all render (including the em dash in course names,
      correctly encoded as WinAnsi `0x97`, not corrupted). All temporary
      users, lecturer record, attendance rows, and enrollment rows created
      for this test were deleted afterward by their exact recorded IDs
      (verified real concurrently-created data from live app usage during
      the test session was left untouched).

### Done (continued 6)
- [x] **Notifications/Alerts** — `notifications.php` built at the app root
      (shared by three roles, same pattern as attendance.php/reports.php),
      wrapped with `require_role(['system_admin', 'head_academic', 'dean'])`.
      Below-threshold list is computed live from `attendance` for
      `settings.current_academic_year_id`, grouped by student+course
      (`ROUND(100 * SUM(status='present') / COUNT(*), 2)`), filtered
      against `settings.min_attendance_pct` via `HAVING` — the exact query
      shape already sketched at the bottom of
      `admas_attendance_schema.sql`. Scoped the same way as the other
      shared pages: system_admin/head_academic see every faculty, dean is
      locked to `$_SESSION['faculty_id']` (never trusted from request
      input). 3 KPI cards: Students Below Threshold (distinct student
      count from the live list), Unread Alerts (`notifications` table,
      `is_read = 0`, scoped by role — dean's count is joined through
      `students.faculty_id`), and Alert Threshold (read-only display of
      `settings.min_attendance_pct`, editable only once Settings exists).
      Color-coded `Avg Present %`-style badges via a new shared
      `attendance_badge_class()` helper in `includes/attendance_helpers.php`
      (red `< threshold - 10`, yellow `< threshold`, green `>= threshold`)
      — reused by student/notifications.php too. The "Notify" button posts
      `student_id`/`course_id` only; the server re-derives the attendance
      % and the student's faculty itself (never trusts the posted
      percentage or scope), rejects if the student is no longer below
      threshold or is outside a dean's faculty, and otherwise inserts into
      `notifications` unless a row already exists for that student+course
      today (checked via `DATE(created_at) = CURDATE()`), in which case it
      just flashes "already notified" — matching rows show a disabled
      "Notified" state instead of a live button, computed via one lookup
      query (not N+1). Added `student/notifications.php`, a strictly
      read-only view scoped by `WHERE student_id = <own students.id>`
      (resolved from `current_user()['id']` → `students.user_id`, never
      from request input) so a student can only ever see notifications
      already raised about themself — lists rows from the `notifications`
      table (not a live recomputation, since a below-threshold combo isn't
      "official" to the student until a management role clicks Notify),
      with a "Mark as Read" action scoped by `student_id = ?` in the same
      `UPDATE` so it can't touch another student's row. The shared
      `includes/topbar.php` bell now computes its own role-scoped unread
      count and links to the right destination instead of being a static
      decoration fed by whatever a page happened to set: system_admin/
      head_academic/dean → `notifications.php`, student →
      `student/notifications.php`, any other role (registration, lecturer)
      renders as a plain non-navigating button since they have no
      notifications feature. Removed the now-dead
      `$notifBadgeCount = count($alerts);` line from `admin/dashboard.php`
      now that topbar.php computes it itself. Also fixed a bug in
      `includes/nav_items.php`: the Notifications entry had no `path`
      override (same missing-override bug the Reports entry had before
      being fixed), so it was resolving to the wrong
      `{roleFolder}/notifications.php` URL for every role including ones
      it shouldn't reach at all; it's now split into two entries — one
      with `path => 'notifications.php'` for
      system_admin/head_academic/dean, and a separate student-only entry
      that correctly falls through to the default
      `student/notifications.php` convention. Tested end-to-end via HTTP
      requests against the live app with temporary accounts (system_admin,
      head_academic, dean scoped to Engineering & IT, plus two temporary
      students — one at ~25% attendance in the dean's own faculty, one at
      ~66.7% in a different faculty) and temporary attendance rows:
      verified the KPI counts and both badge colors (red/yellow) against
      hand-computed percentages; verified head_academic sees the same
      full list as system_admin; verified dean sees only their own
      faculty's student and that a crafted Notify POST for the
      out-of-faculty student was rejected server-side with no row
      inserted (confirmed against the `notifications` table directly);
      verified re-submitting Notify for an already-notified student/course
      today correctly no-ops with an "already notified" message instead of
      inserting a duplicate; verified the row then renders as
      disabled/"Notified Today" and the KPI/topbar unread counts increment
      accordingly; verified the notified student sees exactly that one
      alert on their own page (and nothing when a different temp student
      with no raised notification visited theirs); verified a crafted
      "mark as read" request from one student against another student's
      notification id was rejected (row stayed unread), then verified the
      owning student's own "Mark as Read" succeeded and the unread
      KPI/topbar dot both dropped to 0 immediately after, both on
      notifications.php-adjacent pages (e.g. admin/dashboard.php) and not
      just notifications.php itself; verified the registration role (no
      notifications access) is blocked from `notifications.php` and gets
      no bell link. All temporary users, students, attendance rows, and
      the one notifications row created for this test were deleted
      afterward by their exact recorded IDs (pre-existing real attendance
      data from concurrent live-app usage was left untouched, verified by
      row count before/after).

### Done (continued 7)
- [x] **User Management (`admin/users.php`)**, wrapped with
      `require_role(['system_admin'])`. "System Roles & Responsibilities"
      info panel at the top is a static render of the exact role/scope/
      can-do/cannot-do table from CLAUDE.md §4 (driven by a small
      `ROLE_INFO` const array, not hand-written HTML rows). "Assign Role"
      panel: Select User dropdown lists every user whose role is NOT
      already system_admin/head_academic/registration/dean (i.e.
      lecturers and students — the only two roles left), plus a
      "+ Create New User" option that reveals an inline Full Name/Email
      sub-form via plain JS (visibility purely driven by the select's
      current value, so a failed submit re-shows the right state for
      free once PHP re-selects the posted option — no separate reopen-modal
      JS needed, unlike faculties.php's pattern); Appoint As offers Dean/
      Head of Academic Affairs/Registration Office; Faculty is
      required-and-shown only for Dean (same toggle approach). Reused
      `role_label()` from `includes/nav_items.php` for every role label
      instead of hardcoding them again, and added a new
      `generate_admin_username()` to `includes/lecturer_accounts.php`
      (thin wrapper around the existing `generate_lecturer_username()`
      first-initial+last-name scheme, kept semantically distinct at call
      sites since it's now used for Dean/Head/Registration accounts too,
      not just lecturers) alongside `generate_temp_password()` for the
      "+ Create New User" flow. On Save: re-verifies server-side (never
      trusts the dropdown) that the target user isn't already elevated;
      appointing a Dean first releases whichever user previously held
      that faculty's Dean seat (`UPDATE users SET faculty_id = NULL WHERE
      faculty_id = ? AND role_id = deanRoleId`, the same release-before-
      reassign step `admin/faculties.php` already does) then updates both
      `users.role_id`/`faculty_id` and `faculties.dean_user_id` so the two
      columns stay in sync the way `admin/faculties.php` expects; every
      successful appointment inserts a `role_assignments` audit row
      (`assigned_by = current_user()['id']`). Full "System Users" table:
      Username, Full Name, Role (via `role_label()`, not the raw id),
      Faculty (blank em-dash if none), Last Login (`Never` if
      `last_login_at` is null), Status pill, and per-row Reset Password
      (generates + shows a new temporary password once, same pattern as
      lecturers.php) and Activate/Deactivate toggle — no delete action, to
      avoid orphaning lecturer/student records owned elsewhere. The
      toggle/reset actions apply to any user regardless of role, and the
      toggle button is hidden entirely for the currently logged-in admin's
      own row, with a server-side re-check as well ("You cannot activate
      or deactivate your own account.") since the button-hide alone isn't
      a security boundary. Tested end-to-end via HTTP requests against the
      live app with a temporary system_admin account plus temporary
      lecturer/student accounts and a throwaway faculty (kept separate
      from the real Engineering & IT / dean01 data): appointed a student
      as Head of Academic Affairs; appointed a lecturer as Dean of the
      temp faculty, then appointed a second lecturer as Dean of that same
      temp faculty and confirmed the first was automatically released
      (`faculty_id` back to `NULL`, role left as `dean`) while
      `faculties.dean_user_id` correctly pointed at the new one; used
      "+ Create New User" to create-and-appoint a brand-new Registration
      Office account in one step, confirming the generated
      username/password matched what was hashed in the database; sent a
      crafted re-appointment POST for dean01 (already elevated) and
      confirmed it was rejected server-side with no changes to dean01 or
      to `role_assignments`; used Reset Password and confirmed the shown
      plaintext matched the stored hash; used the Activate/Deactivate
      toggle; attempted to deactivate the logged-in test admin's own
      account and confirmed it was blocked. All temporary users, the
      temporary faculty, and their `role_assignments` rows were deleted
      afterward by exact recorded ID, and dean01/faculty 1 (Engineering &
      IT) were re-verified untouched throughout.

### Done (continued 8)
- [x] **Settings (`admin/settings.php`)**, wrapped with
      `require_role(['system_admin'])`. "University Information" card
      (University Name, Campus, Contact Email, Contact Phone) reads/writes
      the `settings` table via a small shared `save_setting()` upsert
      helper (`INSERT ... ON DUPLICATE KEY UPDATE`, since `settings.key` is
      the primary key — works whether the row already existed from the
      schema seed or not). Confirmed these same four values already fed
      the sky-blue top strip everywhere (`includes/topbar.php` was already
      settings-driven), but found and fixed two places that still
      hardcoded "ADMAS University" as literal text instead of reading
      `settings.university_name`: `includes/sidebar.php`'s brand title
      (now `$settings['university_name'] ?? 'ADMAS University'` — every
      page already loads `$settings` before including sidebar.php, so no
      extra query needed) and `login.php`'s brand panel heading (this page
      has no session yet, so it now runs its own small settings query
      rather than relying on the shared topbar/sidebar includes). "Academic
      Year Settings" card: table of existing `academic_years` with a
      "Set as Current" button per non-current row that, in one
      transaction, zeroes `is_current` on every row, sets it on the chosen
      one, and upserts `settings.current_academic_year_id` to match; a
      small "Add New Academic Year" form (Label only, checked against the
      table's own `UNIQUE` constraint with a friendly pre-check rather
      than letting the DB throw); a Default Faculty/Department dropdown
      pair (Department cascaded by Faculty via the same JS-rebuild pattern
      already used on Reports/Students) saved as new `default_faculty_id` /
      `default_department_id` settings rows — validated so a chosen
      Department must actually belong to the chosen Faculty when both are
      set; and a Minimum Attendance % field (`settings.min_attendance_pct`,
      0–100 range validated) — the exact value Notifications already
      reads. Wired the new `default_faculty_id`/`default_department_id`
      settings into `reports.php`: its Faculty/Department filters now fall
      back to these defaults only when the request omitted that filter
      key entirely (`isset($_GET[...])`), so an explicit `?faculty_id=0`
      ("All Faculties") is still honored as a deliberate choice rather than
      being silently overridden by the default. Tested end-to-end via HTTP
      requests against the live app with a temporary system_admin account:
      saved University Information (and confirmed a bad contact email was
      rejected), then confirmed the new name appeared on the sidebar brand,
      the sky-blue top strip, *and* the pre-login page in the same request
      cycle; added a new Academic Year (duplicate label correctly
      rejected), set it as Current and confirmed both `academic_years` rows
      flipped correctly and `settings.current_academic_year_id` matched;
      saved a default Faculty/Department pair (a mismatched
      Faculty/Department combo was correctly rejected first) and threshold
      (an out-of-range 150% was correctly rejected); confirmed a bare
      `reports.php` request pre-selected the saved default Faculty and
      returned only that faculty's courses, while `?faculty_id=0&department_id=0`
      still returned every course, proving the override logic works
      per-field. All settings values and the test Academic Year were
      restored to their pre-test snapshot afterward, and the temporary
      admin account was deleted.

### Done (continued 9)
- [x] **Profile & Password — all 6 roles**: `admin/profile.php` (built
      first, in isolation, and confirmed working before being extended to
      the other 5 roles) plus `dean/profile.php`,
      `head_academic/profile.php`, `registration/profile.php`,
      `lecturer/profile.php`, `student/profile.php` — each
      `require_role()`-gated to exactly one role, otherwise an identical
      copy of the same three independent forms/actions: Profile
      Information (Full Name/Email via `current_user()`, duplicate-email
      checked), Change Username (format + uniqueness checked), and Change
      Password (`password_verify()` against the current hash before
      accepting a new one, 8-character minimum, `password_hash()` before
      saving) — each form re-populates only its own fields on a failed
      submit. `lecturer/profile.php` and `student/profile.php` additionally
      sync the denormalized `lecturers.full_name` / `students.full_name`
      columns when the name changes, since other pages (e.g.
      `admin/courses.php`'s lecturer dropdown) read the name from there,
      not from `users`. Every role's existing "Profile & Password" sidebar
      item already pointed at `profile.php` via the default
      `{roleFolder}/{file}` convention, so no `nav_items.php` change was
      needed.
- [x] **Role Dashboards — all 5 remaining roles** (§9 module #7 / §5
      module 14), replacing the bare placeholders. All scoped server-side
      the same way as the other shared pages (dean/lecturer/student never
      trust `$_SESSION`/request input beyond the session's own
      `faculty_id`, and always resolve their own `lecturers.id` /
      `students.id` via a `user_id` lookup, never from a request
      parameter):
      - `dean/dashboard.php` — KPIs (Students/Lecturers/Departments in
        Faculty, Avg Attendance Today) and a Departments-in-My-Faculty
        table all scoped to `$_SESSION['faculty_id']`; a "Low Attendance —
        My Faculty" widget reusing the exact live-query shape from
        `notifications.php`/`admin/dashboard.php`, scoped the same way.
      - `head_academic/dashboard.php` — university-wide KPIs and an
        Attendance-by-Faculty table (current-academic-year averages); also
        a self-contained "Register New Lecturer" quick-add form (own
        transaction, same shape as `admin/lecturers.php`'s create branch,
        reusing `generate_lecturer_username()`/`generate_temp_password()`),
        since CLAUDE.md §4 grants Head of Academic Affairs this ability but
        `admin/lecturers.php` itself is still system_admin-only.
      - `registration/dashboard.php` — KPIs limited to what's honestly
        computable from real columns (Total Registered Students,
        Faculties, Departments, Added This Month via `students.created_at`
        — no import-log KPI, since no such tracking exists in the schema)
        and a Recent Registrations table with working "Add Student"/
        "Import from Excel" quick links. Building those required a small,
        deliberate RBAC fix: `admin/students.php` and
        `admin/students_import.php` were `system_admin`-only despite
        CLAUDE.md §4 explicitly granting Registration Office "Add/edit
        students, bulk Excel import of students" and `nav_items.php`
        already (incorrectly) listing `registration` among their allowed
        roles — added `'registration'` to both files' `require_role()`
        (zero query-scoping changes needed, since both pages are already
        unscoped/university-wide, matching Registration's own "All
        faculties" scope exactly), made both pages' scope banners
        role-aware instead of always claiming "Full system", and added
        `path => 'admin/...'` overrides to `nav_items.php`'s Students/
        Import Students entries so registration's own sidebar links
        resolve to the real file instead of a nonexistent
        `registration/students.php` (a pre-existing 404 that also silently
        affected `dean`, whose own access to that page remains unbuilt —
        left as-is, out of scope for this session, noted below).
      - `lecturer/dashboard.php` — KPIs (My Courses, Total Students via
        `course_enrollments`, Sessions Recorded as `COUNT(DISTINCT
        attendance_date)` where `recorded_by_user_id` is them) and a My
        Assigned Courses table (current academic year label, most recent
        shift and session date per course via correlated subqueries,
        enrollment count, and a "Take Attendance" button linking to
        `attendance.php?course_id=X` to pre-select that course).
      - `student/dashboard.php` — KPIs (My Attendance %, Enrolled Courses
        — a `UNION` of `course_enrollments` and any course with an actual
        attendance record, so it stays meaningful even where formal
        enrollment rows don't exist yet, Courses Below Threshold) and a My
        Course Attendance table (Present/Absent/Late counts + a
        color-coded Attendance % badge via the shared
        `attendance_badge_class()` helper).
      All 5 show the scope banner pattern specified: dean's is
      `"Access scope: {Faculty} Faculty only"`, student's is exactly
      `"Access scope: Own personal record only"` as specified, and the
      other three reuse the same phrasing already established on their
      other pages (reports.php/notifications.php). Tested end-to-end via
      HTTP requests against the live app with one temporary account per
      role plus a temporary lecturer/student/course-enrollment/attendance
      fixture set (course 7 temporarily reassigned to the temp lecturer,
      restored afterward): verified every KPI and table figure by hand
      against the fixture data (e.g. dean's Computer Science department
      showing exactly 50.0% avg attendance and a red badge, matching
      2-present/1-absent/1-late); verified the Register New Lecturer form
      end-to-end including a duplicate-staff-no rejection, and confirmed
      the created row's `role_id` was really `lecturer`; verified
      registration's dashboard quick links now actually reach
      `admin/students.php`/`admin/students_import.php` instead of 403ing;
      verified the lecturer dashboard's "Take Attendance" link carried the
      correct `course_id`; verified full round-trips on two of the six
      Profile & Password pages (student and, in the prior session, admin)
      including logging in fresh with a changed username *and* changed
      password afterward — the other four roles' profile pages are
      byte-for-byte the same logic with only the role/URL swapped, so
      individually re-verifying all fifteen forms was not repeated. All
      temporary users, lecturer/student records, attendance rows, the
      enrollment row, and the extra lecturer created via the quick-add
      form were deleted afterward by exact recorded ID, and course 7's
      original (empty) `lecturer_id` was restored.

### Known Gaps / Things to Revisit
- ~~Dean does not yet have a working "Students" (or Courses/Departments/
  Lecturers) management page of their own~~ — **resolved in the Dean Role
  Audit above**: `admin/students.php`, `admin/courses.php`,
  `admin/lecturers.php`, and `admin/departments.php` all now accept the
  `dean` role with real faculty-scoped queries (list, create, edit, delete,
  reset-password), not just a `require_role()` tweak.
- Have not yet verified `require_role()` correctly blocks a logged-in user
  from reaching a dashboard outside their role by typing the URL directly
  for the other 5 roles — the Dean Role Audit above confirmed this
  specifically for Dean (including the discovery/fix that
  `unauthorized.php` didn't exist at all), but a full cross-role check for
  system_admin/head_academic/registration/lecturer/student is still worth
  doing in a future session.
- ~~Head of Academic Affairs' sidebar still links to `admin/lecturers.php`
  but that page's `require_role()` does not include `head_academic`~~ —
  **resolved in the Student/Lecturer/Head-of-Academic-Affairs audit below**:
  removed `head_academic` from the "Lecturers" nav item in
  `includes/nav_items.php` instead of adding it to `admin/lecturers.php`'s
  `require_role()`, since CLAUDE.md §4 only grants that role "register new
  Lecturer accounts" (already its own form on `head_academic/dashboard.php`),
  not full CRUD over every lecturer — granting nav access to the full
  management page would have been over-scoped.
- ~~Dean role assignment was done manually via SQL, not through the app
  UI~~ — **confirmed resolved**: `admin/users.php` (User Management) now
  exists, and dean01 was re-verified through that real UI (not phpMyAdmin)
  to correctly show Role = Dean and Faculty = Engineering & IT.
- ~~Deferred decision: where should Academic Year management (add/edit
  years, mark one Current) live?~~ — **resolved**: it lives inside
  `admin/settings.php`'s "Academic Year Settings" card, not as its own
  sidebar item — `includes/nav_items.php` was not changed for this, since
  Settings already had its own nav entry pointing at `admin/settings.php`.
- The "Import Students" sidebar link bug from the previous session (nav item
  pointed to `import_students.php` instead of the real `students_import.php`)
  is confirmed still fixed — `includes/nav_items.php`'s entry has the
  correct filename and nothing further is needed there. That same session
  also added the `path` override now used by the Attendance nav item (see
  above) so this is the second nav item that no longer relies purely on the
  default `{roleFolder}/{file}` convention.
- `seed_test_users.php` has already been deleted and admin01/dean01's actual
  current passwords are unknown (not `Test@12345` as this log previously
  assumed) — only admin01 and dean01 exist as real accounts; there is no
  `lec01`/lecturer test account at all right now. Verifying any page as a
  real logged-in user will need either a password reset through the app or
  new temporary accounts (as this session did for attendance.php, cleaned up
  afterward) rather than assuming the old seeded credentials still work.

### Dean Role Audit
- [x] **Full audit of every page a Dean can reach**, against CLAUDE.md §4/§6,
      prompted by the "Known Gaps" note that Dean had no working Students/
      Courses/Departments/Lecturers management despite §4 granting "Full
      CRUD ... within their faculty". Confirmed `dean/dashboard.php`,
      `dean/profile.php`, `attendance.php`, `reports.php`, and
      `notifications.php` were already correctly faculty-scoped (session
      `faculty_id` only, real scope banners, no changes needed). Found and
      fixed the actual gap:
      - `includes/nav_items.php` listed Dean among the allowed roles for
        Students/Lecturers/Departments/Courses, but only Students had a
        `path` override to the real `admin/students.php` file — Lecturers/
        Departments/Courses had none, so they resolved to nonexistent
        `dean/lecturers.php`/`dean/departments.php`/`dean/courses.php`
        (404s). Added the missing `path` overrides, same convention as
        Students/Attendance/Reports.
      - `admin/departments.php`, `admin/courses.php`, `admin/lecturers.php`
        were `system_admin`-only; `admin/students.php` allowed
        `registration` but not `dean`. Added `'dean'` to all four
        `require_role()` calls and added real faculty-scoping to each,
        following the same "never trust request input, always read
        `$_SESSION['faculty_id']`" pattern already used by
        attendance.php/reports.php/notifications.php:
        - List queries filtered to the Dean's own faculty (departments,
          courses, lecturers, students all scoped via `faculty_id` or a
          join to it).
        - Create forms: Faculty is forced server-side to the Dean's own
          `faculty_id` (ignoring any posted value) on departments.php and
          students.php; on courses.php/lecturers.php the Department
          dropdown itself is pre-scoped to the Dean's faculty, so no
          separate Faculty field exists to lock.
        - Edit/Delete/Reset-Password: every action re-verifies the target
          record (department/course/lecturer/student) currently belongs to
          the Dean's own faculty before acting, blocking a crafted
          id belonging to another faculty from being edited, deleted, or
          "adopted" into the Dean's faculty.
        - Faculty selectors shown to a Dean (departments.php/students.php)
          are rendered as a disabled, single-option select showing only
          their own faculty name.
        - Scope banners changed from the hardcoded "Full system" text to
          `"Access scope: {Faculty} Faculty only"` for the Dean role on all
          four pages.
        - The "Import from Excel" button is hidden for Dean on all four
          pages (those importer scripts remain `system_admin`-only; adding
          Dean to bulk import was not part of this audit's scope).
      - Found and fixed a second, unrelated bug blocking verification of
        the audit's own requirement that unauthorized access "should
        redirect to unauthorized.php": that file did not exist anywhere in
        the project, so `require_role()`'s redirect for *every* role (not
        just Dean) landed on a real Apache 404, not an access-denied page.
        Created `unauthorized.php` (login-required only, no specific role)
        using the same sidebar/topbar shell as the rest of the app, with a
        "Back to My Dashboard" link resolved via `role_folder(current_role())`.
      - Deliberately left untouched (out of scope / other roles' pages):
        Head of Academic Affairs' own access to `admin/lecturers.php` is
        still broken (`nav_items.php` lists it as allowed, but
        `require_role()` never included it, before or after this session) —
        noted in a comment at the top of `admin/lecturers.php` rather than
        fixed, since the task was scoped to the Dean role only. (Resolved in
        the Student/Lecturer/Head-of-Academic-Affairs audit below, in the
        opposite direction than a naive fix would suggest — see there.)
      - Verified end-to-end against the live app with `dean01` (Engineering
        & IT) plus a temporary second faculty/department/course/lecturer/
        student fixture set: confirmed all four pages now load (200) with
        the correct scope banner and sidebar links (no more 404s); confirmed
        the other faculty's department/course/lecturer/student never
        appeared in any of Dean's list views; confirmed crafted
        edit/update/delete/reset-password requests against the other
        faculty's IDs were all rejected server-side ("not found" / "Invalid
        ... selected for editing") with zero rows changed; confirmed a
        department created with a tampered `faculty_id` in the POST body was
        still forced into the Dean's own faculty; confirmed a legitimate
        create/edit/delete of a Dean-owned department succeeded; confirmed
        `admin/users.php`, `admin/settings.php`, `admin/faculties.php`,
        `admin/dashboard.php`, and all four `*_import.php` pages redirect a
        Dean to the new `unauthorized.php` (200, real access-denied page)
        rather than a 404; confirmed `system_admin` and `registration`
        access to the same four pages was unaffected (regression check).
        All temporary users/faculty/department/course/lecturer/student rows
        were deleted afterward and the real Engineering & IT / CS / dean01
        data was re-verified untouched throughout.

### Student / Lecturer / Head of Academic Affairs Audit
- [x] Reviewed every page these three roles can reach —
      `head_academic/dashboard.php`, `head_academic/profile.php`,
      `lecturer/dashboard.php`, `lecturer/profile.php`,
      `student/dashboard.php`, `student/notifications.php`,
      `student/profile.php` — against CLAUDE.md §4/§6. All `require_role()`
      calls, session-derived scoping (`current_user()['id']` →
      `lecturers.user_id`/`students.user_id`, never from request input), and
      scope banners were already correct; no changes needed to any of those
      seven files.
- [x] Found the one real bug: `includes/nav_items.php`'s "Lecturers" nav
      entry listed `head_academic` among the allowed roles (pointing at
      `admin/lecturers.php`), but that page's `require_role()` has only ever
      allowed `system_admin`/`dean` — a dead link in Head of Academic
      Affairs' own sidebar (302 to `unauthorized.php`). This is the same bug
      already noted (and deliberately left) in the Dean Role Audit above.
      Fixed it by removing `head_academic` from that nav entry rather than
      adding it to `admin/lecturers.php`'s `require_role()`, since CLAUDE.md
      §4 only grants Head of Academic Affairs "register new Lecturer
      accounts" — a capability it already has via its own quick-add form on
      `head_academic/dashboard.php` — not full CRUD over every lecturer
      university-wide. Granting nav access to the full management page would
      have over-scoped the role beyond what CLAUDE.md documents.
      Verified live with `headacad01`: the sidebar no longer shows a
      "Lecturers" link, and a direct request to `admin/lecturers.php` still
      redirects (302, unchanged — it was already correctly blocked, only the
      dangling nav link was new).

### Done (continued 10)
- [x] **Logout + password-visibility toggle — system-wide, all 6 roles**:
      added root-level `logout.php` (destroys the session, redirects to
      `login.php`) and a Logout link in `includes/topbar.php` next to the
      user avatar/name, so it's automatically present on every dashboard and
      every Profile & Password page for all 6 roles without touching each
      page individually. Added a shared `assets/js/password-toggle.js`
      (plain JS, per-input, no new library) plus a Bootstrap `input-group`
      eye-icon button pattern applied to `login.php`'s password field and
      all three password fields (Current/New/Confirm) on all 6
      `*/profile.php` pages. Tested end-to-end via HTTP requests against the
      live app with a temporary system_admin account: confirmed the logout
      button renders on the dashboard and on profile.php, confirmed
      `logout.php` destroys the session and redirects to `login.php`, and
      confirmed a subsequent request to a protected dashboard correctly
      bounces back to `login.php` afterward. Temporary account deleted
      afterward.

### Navigation Audit (Head of Academic Affairs / Lecturer / Student)
- [x] Compared the sidebar actually rendered for these three roles against
      CLAUDE.md §4's nav spec and found three missing items (all now fixed
      in `includes/nav_items.php` and reflected on every affected role's
      pages, not just one):
      - **Head of Academic Affairs — "Lecturers"**: the previous session
        (Student/Lecturer/Head-of-Academic-Affairs Audit above) had removed
        this nav item entirely rather than grant the role full CRUD on
        `admin/lecturers.php`. This session restores it the correct way —
        a new, purpose-built `head_academic/lecturers.php`: a read-only,
        university-wide lecturer directory (Staff No/Full Name/Department/
        Faculty/# Courses/Status, no edit/delete/reset-password actions)
        plus the "Register New Lecturer" quick-add form, moved here from
        `head_academic/dashboard.php` (removed from the dashboard so it's
        no longer duplicated in two places — the dashboard now shows only
        its KPIs and the Attendance-by-Faculty table). `nav_items.php` now
        has two separate "Lecturers" entries with disjoint `roles` arrays
        (`['system_admin', 'dean']` → `admin/lecturers.php`, `['head_academic']`
        → the new file via the default `{roleFolder}/{file}` convention) —
        same same-label/different-file split pattern already used for
        Notifications.
      - **Head of Academic Affairs — "Academic Settings"**: new
        `head_academic/academic_settings.php`, `require_role(['head_academic'])`.
        Reuses the exact Academic Year (add / set-current) and Minimum
        Attendance % logic from `admin/settings.php`'s "Academic Year
        Settings" card (same `save_setting()` upsert helper, redefined
        locally since this file doesn't share admin/settings.php), but
        deliberately omits University Information and the default Faculty/
        Department scope fields — CLAUDE.md §4 grants this role "Set
        Academic Year & minimum attendance threshold" only, not the rest of
        Settings, which stays `system_admin`-only.
      - **Lecturer — "My Courses"**: `lecturer/courses.php` did not exist
        (not lost — never built; the dashboard's "My Assigned Courses"
        table was the closest equivalent but had no dedicated page or nav
        item). Created it: lists courses scoped to `c.lecturer_id` resolved
        from `lecturers.user_id` (never request input), filterable by
        Academic Year (scopes the session-count/last-session stats shown,
        since `courses` has no `academic_year_id` column of its own),
        Faculty and Department (both option lists built only from this
        lecturer's own courses, never the whole university's), plus a
        course code/name search box — each row has a "Take Attendance"
        button linking to `attendance.php?course_id=X`.
      - **Student — "My Courses"**: `student/courses.php` did not exist
        either. Created it: resolves the student's own `students.id` from
        `students.user_id`, tries `course_enrollments` first, and — clearly
        flagged in a code comment — falls back to every course in the
        student's own department if they have zero enrollment rows yet
        (the same enrolled-or-department-fallback assumption
        `attendance.php`'s roster query already makes in the opposite
        direction, course → students). Shows Course Code, Name, Lecturer
        (or "Unassigned"), and this student's own attendance % in that
        course for the current academic year, color-coded via the shared
        `attendance_badge_class()` helper.
      - `includes/nav_items.php` updated with all three: the two "Lecturers"
        entries described above, plus "My Courses" (`roles: ['lecturer']`)
        and "My Courses" (`roles: ['student']`) — both named `courses.php`
        and resolved via the default per-role-folder convention, distinct
        from the `system_admin`/`dean` "Courses" entry (which keeps its
        `admin/courses.php` path override). Roles are disjoint across all
        of these same-filename entries, so no role ever matches more than
        one.
      Tested end-to-end via HTTP requests against the live app with the
      real `headacad01`/`lecturer01`/`student01` accounts plus one temporary
      course (assigned to `lecturer01`'s department) and a temporary
      registered lecturer: confirmed `head_academic`'s sidebar now lists
      both "Lecturers" and "Academic Settings" and both return 200;
      confirmed the Register New Lecturer form works end-to-end from its
      new location (`head_academic/lecturers.php`) and the new lecturer
      appears in the read-only list; confirmed `lecturer`'s sidebar "My
      Courses" link returns 200, shows the temporary course, and its Take
      Attendance button carries the correct `course_id`; confirmed
      `student`'s sidebar "My Courses" link returns 200 and shows the same
      course via the department-fallback path (no enrollment row existed
      yet), then re-confirmed it still shows correctly once a real
      `course_enrollments` row was added (primary path); confirmed
      `system_admin`/`dean` access to `admin/lecturers.php` and
      `admin/courses.php` was unaffected (regression check). All temporary
      course/lecturer/enrollment rows were deleted afterward, restoring the
      database to exactly its pre-test state (verified by row count).

### Fixed: Excel Import Always Failed with "Could not read the uploaded file"
- [x] All four Excel import pages (`admin/departments_import.php`,
      `admin/courses_import.php`, `admin/lecturers_import.php`,
      `admin/students_import.php`) rejected every `.xlsx` upload —
      including a minimal, correctly-headed file — with the generic
      message "Could not read the uploaded file. Please make sure it is a
      valid Excel or CSV file." PhpSpreadsheet itself was installed and
      autoloading correctly (`composer.lock` confirms 2.1.17,
      `vendor/autoload.php` present); the form's `enctype`/field name and
      `admin/departments_import.php`'s `$_FILES['import_file']` read were
      also both correct; `upload_max_filesize`/`post_max_size` were 40M
      each (not 0) under both CLI and Apache's php.ini. **Root cause**:
      `C:\xampp\php\php.ini` shipped with `;extension=zip` commented out —
      **on this fresh XAMPP install, the PHP `zip` extension was disabled
      by default**. Since `.xlsx` is a ZIP container, PhpSpreadsheet's
      `Xlsx` reader needs the `ZipArchive` class to open it; without it,
      `$reader->load()` throws `Error: Class "ZipArchive" not found`,
      which the broad `catch (\Throwable $e)` block was silently
      swallowing into the generic, misleading user-facing message (the
      *writer* — e.g. the "Download starter template" link — has a
      fallback path and kept working, which is why only imports looked
      broken). Confirmed via temporary `error_log()` logging added to
      `admin/departments_import.php`'s catch block, reproduced through a
      real HTTP upload, then removed once confirmed fixed.
      **Fix applied**: uncommented `extension=zip` in
      `C:\xampp\php\php.ini` and restarted Apache. Re-verified
      `extension_loaded('zip')` / `class_exists('ZipArchive')` both return
      `true` under Apache (not just CLI) after the restart. Re-tested all
      four import pages end-to-end with generated `.xlsx` files: each now
      reaches the Preview step instead of erroring, correctly flags
      already-existing rows (e.g. a duplicate "CS" department code) as
      errors rather than failing to read the file at all, and a genuinely
      new row (a temporary "MKTG" department) imported successfully on
      Confirm and was then deleted. This was a fresh-XAMPP-install
      environment issue, not a regression in this codebase — no
      application code needed to change.
      **For future sessions / other machines**: if Excel import ever fails
      again with "Could not read the uploaded file" on a new machine,
      check `php.ini`'s `extension=zip` line *first* before assuming the
      uploaded file or the import code is at fault — fresh XAMPP installs
      may ship with `ext-zip` disabled by default.

### Login Redesign, Forgot Password (email), and Force Password Change
- [x] **Note on this session's brief**: it referenced an existing "Force
      Password Change" entry under a "Deferred Decisions" section — no such
      section or entry exists anywhere in this file (searched for both
      "deferred" and "force password"; the only actual deferred-decision
      note on record is the already-resolved Academic Year one under
      Known Gaps). Treated as a new feature rather than resolving a
      pre-existing note, since there wasn't one to resolve.
- [x] **Login page redesign (`login.php`)**: sky-blue gradient
      (`#0ea5e9` → `#38bdf8` → `#7dd3fc`) now fills the whole page behind a
      centered, rounded white card; the two panels are a true 50/50 CSS
      grid (`grid-template-columns: 1fr 1fr`, collapsing to one column
      under 860px) instead of the old uneven split. The real logo
      (`/logo/logo.jpg`) sits inside a circular frame (`border-radius:
      50%`, a translucent white ring border) centered at the top of the
      brand panel, followed by the university name in an elegant bold
      serif (Google Fonts "Playfair Display") — line 1 is
      `settings.university_name`, line 2 is `settings.campus` with a
      trailing "Campus" word stripped via regex (data-driven, not
      hardcoded, per this file's own branding rule), and "Attendance
      System" as a smaller subtitle underneath both.
- [x] **Forgot Password (email-based)**: new `password_resets` table
      (`user_id`, `code`, `expires_at`, `used`) added to
      `admas_attendance_schema.sql` and the live database. New
      `forgot_password.php` (email input -> generates a random 6-digit
      code via `random_int(0, 999999)` zero-padded, stores it with a
      10-minute expiry, emails it through PHPMailer/Gmail SMTP) always
      shows the same generic "if that email matches an account..."
      message regardless of whether the email existed or the send
      succeeded, to avoid user enumeration — a real send failure (e.g. bad
      credentials) is only ever `error_log()`'d, never shown to the
      visitor. New `reset_password.php` (email + code + new password +
      confirmation -> verifies the code belongs to that email, is unused,
      and hasn't expired, then hashes and saves the new password, marks
      that code used, and invalidates any other outstanding codes for the
      same user) — asks for email alongside the code (not code alone,
      contrary to how the task was phrased) because `password_resets.code`
      is only unique per-user, not globally, so the pair is what
      unambiguously identifies the request being redeemed. Resetting a
      password this way also clears `must_change_password` (see below),
      since the user has now set one of their own choosing either way.
      PHPMailer installed via
      `php composer.phar require phpmailer/phpmailer` (v7.1.1) — composer
      itself wasn't present anywhere on this machine or bundled with
      XAMPP, so a local `composer.phar` was downloaded straight from
      getcomposer.org into the project root and used instead of a global
      `composer` command; also had to enable `extension=gd` in
      `C:\xampp\php\php.ini` first (same "fresh XAMPP disables extensions
      by default" pattern as the `ext-zip` issue above — phpspreadsheet
      requires it and composer's platform-check blocked the install
      without it). New `includes/mail_config.php` holds the SMTP
      constants; **the real Gmail address goes in `SMTP_USERNAME` and a
      16-character Gmail *App Password* (not the normal account password —
      Google requires 2-Step Verification to be on to generate one at all)
      goes in `SMTP_PASSWORD`, both directly in that file** — currently
      still placeholder values, so outgoing mail will fail
      (`SMTP Error: Could not authenticate`, confirmed via a live test)
      until real credentials are filled in.
- [x] **Force Password Change on first login**: added
      `must_change_password TINYINT(1) NOT NULL DEFAULT 1` to `users` in
      both the schema file and the live database (existing rows —
      including the ~300+ real accounts already in use — were immediately
      set back to `0` in the same migration, so only genuinely new
      accounts from this point on are affected; the column's `DEFAULT 1`
      means every existing account-creation `INSERT` already covers this
      with zero code changes, since none of them explicitly list that
      column). `login.php` now selects it and, on success, stores it in
      `$_SESSION['must_change_password']` and redirects to the role's own
      `profile.php` instead of its dashboard when set. Enforcement lives
      in one place — `includes/auth.php`'s `require_role()` — rather than
      being copy-pasted into every protected page: after the existing
      role check, if the flag is set and the current script's basename
      isn't `profile.php`, it redirects there; the basename check is what
      lets Profile & Password itself load instead of infinitely
      redirecting to itself. (`auth.php` now `require_once`s
      `nav_items.php` internally so `role_folder()` is always available
      inside `require_role()`, regardless of each page's own include
      order.) All six `*/profile.php` files show the one-time banner
      ("For security, please set your own password before continuing.")
      when the session flag is set, and a new shared
      `clear_must_change_password(mysqli $conn, int $userId)` helper in
      `auth.php` (updates the DB column and the session key together) is
      called from each file's `change_password` success branch instead of
      duplicating the raw SQL six times.
      Tested end-to-end against the live app with a temporary account
      seeded with `must_change_password = 1`: confirmed login redirected
      straight to `student/profile.php` (not the dashboard); confirmed the
      banner rendered; confirmed both `student/dashboard.php` and the new
      `student/courses.php` redirected back to `profile.php` instead of
      loading; changed the password through the real form and confirmed
      the DB column flipped to `0`, the banner disappeared, and
      `student/dashboard.php` then returned `200` directly; separately
      exercised the full Forgot Password round-trip (submitted the email,
      confirmed the generated code/expiry in `password_resets`, confirmed
      the expected Gmail auth failure was logged and never shown to the
      visitor, confirmed a wrong code was rejected, confirmed the real
      code succeeded and reused rejected as already-used, and confirmed
      the resulting new password logged in successfully); confirmed
      `admin01` (an untouched pre-existing account) still logs straight
      into its dashboard with no forced redirect (regression check). The
      temporary account and its `password_resets` rows were deleted
      afterward.

### Registration Office Role Audit
- [x] **Full audit of every page a Registration Office user can reach**
      (`registration/dashboard.php`, and the real targets of the
      "Students"/"Import Students"/"Reports" sidebar links), re-verifying
      the RBAC fix already recorded above under "Done (continued 9)"
      rather than assuming that log entry still matches the live code.
      Checked live HTTP behavior first (not just the source) with a
      temporary `registration`-role account: all four pages returned
      `200` with no PHP errors/warnings and the correct
      "All faculties — enrollment-focused" scope banner — no blank pages,
      404s, or `unauthorized.php` redirects on any of them.
      - **Dashboard** (`registration/dashboard.php`): loads cleanly;
        "Recent Student Registrations" rendered 10 real rows from the
        `students` table (not static markup); both quick links resolved
        to `admin/students.php` and `admin/students_import.php` and both
        returned `200`.
      - **Students**: `includes/nav_items.php`'s "Students" entry already
        carries the `path => 'admin/students.php'` override and lists
        `registration`; `admin/students.php`'s `require_role()` already
        includes `'registration'`. Confirmed live: the Faculty filter
        renders as a real, enabled dropdown (5 faculties, not
        locked/disabled the way Dean's is), and a full create → edit →
        delete round-trip against a temporary student succeeded — created
        under Faculty 4/Department 6, immediately visible in the
        all-faculty list (`faculty_id=0`), edited (name/level/shift all
        changed and persisted), then deleted (row removed from `students`,
        linked `users` row correctly deactivated rather than deleted,
        matching the existing Dean-audit-verified behavior).
      - **Import Students**: nav entry already overrides to
        `admin/students_import.php` and lists `registration`; that file's
        `require_role()` already includes `'registration'`. Confirmed live:
        `200`, a real file-upload form (not a placeholder), no errors.
      - **Reports**: nav entry already overrides to `reports.php` and
        lists `registration`; `reports.php`'s `require_role()` already
        includes `'registration'`. Confirmed live: the Report Type control
        offers exactly `department_summary`/`faculty_summary` (no Course
        Attendance option, matching the "No access to Attendance" rule),
        and Department Summary rendered a "Total Enrollments" column with
        no "Avg Present %" column — enrollment-focused, not
        attendance-focused, as specified.
      - **Result: no bugs found, no code changes were necessary.** The
        RBAC/nav fix recorded under "Done (continued 9)" was verified
        still correct and complete for all four surfaces; this audit's
        only contribution is confirming that against live behavior instead
        of the code alone.
      Temporary registration-role account and the temporary student (plus
      its linked user account) created for the CRUD test were deleted
      afterward; the two real Registration Office accounts already in the
      database (`registrar01`, `ximtixanaadka`) were left untouched and
      re-confirmed present throughout.

