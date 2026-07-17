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
| **System Administrator** | Whole system | Full technical control: all CRUD, User Management, role appointment, system Settings, Notification thresholds, backups (incl. Settings → Danger Zone factory reset for handover, with an automatic pre-wipe `mysqldump` backup) | — |
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

### Semester / Xiiso Restructuring
- [x] **Replaced the raw-date attendance model with ADMAS's real Semester +
      Xiiso (session) structure**, on branch `feature/semester-xiiso`
      (checkpoint commit `bc5ab1e` on `main` beforehand: "Working system
      before Semester/Xiiso restructuring - all 6 roles complete, tested").
      A semester runs ~3 months and has exactly 12 numbered sessions
      ("Xiiso"): 1-5 and 7-11 are regular teaching sessions, 6 is the
      Midterm, 12 is the Final — matching the lecturer's real paper/Excel
      tracking grid instead of a free-form date picker.
      - **Schema**: new `semesters` table (`academic_year_id` FK, `name`,
        `start_date`, `end_date`, `is_current` — same single-current-flag
        pattern as `academic_years.is_current`) and `sessions` table
        (`semester_id` FK, `session_number` 1-12, `type` ENUM
        `regular`/`midterm`/`final`, `date` nullable until assigned).
        `attendance` gained a nullable `session_id` FK; the old
        `uq_attendance_once_per_day (student_id, course_id, attendance_date)`
        unique key was replaced with
        `uq_attendance_once_per_session (student_id, course_id, session_id)`
        — old date-only rows keep `session_id = NULL` permanently (MySQL
        treats NULL as distinct in a UNIQUE index, so they're never touched
        or invalidated) rather than guessing which Xiiso they belonged to.
        `attendance_date` stays `NOT NULL` and is auto-populated from the
        selected session's `date` at save time (denormalized on purpose, so
        every existing date-range report keeps working unmodified). Shipped
        as both an update to the baseline `admas_attendance_schema.sql` and
        a standalone `migrations/2026_07_semesters_sessions.sql`, applied
        and verified against the live dev DB.
      - **New `includes/semester_helpers.php`**: `get_current_semester()`,
        `generate_sessions_for_semester()` (creates the 12 rows with the
        1-5/6/7-11/12 type mapping above, idempotent via `INSERT IGNORE`
        against the `(semester_id, session_number)` unique key),
        `session_label()` (e.g. "Xiiso 3", "Midterm", "Final"),
        `get_sessions_for_semester()`.
      - **New `semesters.php`** (root-level, shared by `system_admin` and
        `head_academic`, same lives-at-root-not-under-/admin pattern as
        `attendance.php`/`reports.php`): create semesters per academic
        year, one-click "Generate Sessions," inline per-session date
        assignment, "Set as Current" (writes `semesters.is_current` +
        `settings.current_semester_id`, transaction-wrapped like the
        existing Academic Year "set current" flow). Nav entry added to
        `includes/nav_items.php` for both roles.
      - **`attendance.php`**: the old `<input type="date" name="attendance_date">`
        picker was replaced with a read-only "Semester" field (locked to
        whichever semester is current) plus a "Xiiso (Session)" dropdown.
        Roster prefill and the save upsert now key off `session_id` instead
        of `attendance_date`; saving is blocked with a clear error if the
        chosen session has no calendar date assigned yet (can't populate
        the denormalized `attendance_date` otherwise). Pages with no
        current semester, or a current semester with no generated sessions
        yet, show a guidance message instead of the marking form.
      - **`reports.php`**: added a 4th report type, "Xiiso Attendance
        Grid" (`system_admin`/`head_academic`/`dean`/`lecturer`, not
        `registration` — no attendance access per §4) — one row per
        enrolled student, one column per Xiiso 1-12 in the correct
        1-5/Midterm/7-11/Final order, with auto-computed P/A/% trailing
        columns ('1' present, '0' absent, 'L' late, 'E' excused, blank
        unmarked; P/A/% use the same present-and-absent-only formula the
        other 3 report types already use, so late/excused count toward the
        denominator but neither column). Reuses the existing
        PhpSpreadsheet `Spreadsheet`/`Writer\Xlsx` and Dompdf builders
        already in the file rather than introducing a second export
        pipeline. Student roster resolution mirrors `attendance.php`
        exactly, including its `course_enrollments`-empty department
        fallback (needed in practice: this dev DB's `course_enrollments`
        table is currently empty).
      - **Live end-to-end verification** (curl against the running XAMPP
        instance, logged in as real seeded accounts, not just `php -l`):
        created a semester as `admin01`, generated its 12 sessions and
        confirmed the exact type mapping in the DB, assigned dates to
        three sessions, loaded the `attendance.php` roster for a real
        course (fallback path, since `course_enrollments` is empty),
        marked 5 students Present/Absent/Late, confirmed
        `attendance.attendance_date` was correctly derived from the
        session's date, reloaded the roster and confirmed the marks
        persisted (radio `checked` state round-tripped), pulled the Xiiso
        grid on-screen and confirmed cell values/P/A/% matched exactly,
        downloaded both the `.xlsx` (valid "Microsoft Excel 2007+" file)
        and `.pdf` (valid 3-page PDF) exports, and re-ran the pre-existing
        Course Attendance Summary report to confirm its present/absent %
        for the same course matched the marks just entered (60%/20%,
        i.e. 3 present + 1 absent out of 5 marks) — a regression check
        that the old date-range reports still work unmodified against the
        new session-based rows.
      - **Result: no bugs found during verification beyond one fixed while
        building** — `build_xiiso_grid_report()` initially only joined
        through `course_enrollments` and rendered zero rows against this
        dev DB (which has no enrollment rows yet); added the
        `attendance.php`-style department fallback and re-verified all 60
        department students (including the 5 just marked) render with
        correct values.

### Bug found: Grid View attendance invisible on student dashboard
- [x] **Root cause investigation** (before any fix): a student's own
      dashboard/attendance view wasn't showing attendance marked via the
      new interactive Grid View (`attendance_grid.js` +
      `ajax/save_attendance_cell.php`), even though the same records marked
      via the classic single-session form showed up fine. Confirmed via
      direct DB queries: the Grid View's save *was* reaching the database
      correctly — `ajax/save_attendance_cell.php` derives `academic_year_id`
      per-student from `students.academic_year_id` (e.g. students 41/36/31/26
      got academic_year_id 2/3/4/1 respectively, correctly matching their own
      records). The problem was on the read side:
      `student/dashboard.php`/`student/courses.php` filter
      `WHERE a.academic_year_id = ?` using
      `settings.current_academic_year_id` — a single global value that had
      drifted to `4` ("2022/2023") and had nothing to do with the actual
      current semester (`semesters.is_current = 1`, academic_year_id `1`,
      "2025/2026") after the Semester/Xiiso restructuring above. Only the
      one student whose own `academic_year_id` happened to equal 4 ever saw
      their Grid View attendance; everyone else's rows existed but were
      silently filtered out. The classic form doesn't hit this because its
      roster query already filters students by the same `academic_year_id`
      the lecturer explicitly picks, so what it writes and what the
      dashboard (mostly, coincidentally) expects tend to line up — the Grid
      View has no such filter step, it just reads the truth off the
      student's own row.
      **This finding directly motivated the consolidated restructuring
      below** — a narrow "keep `settings.current_academic_year_id` in sync"
      fix was considered and explicitly rejected in favor of removing the
      single global "current" concept entirely, once it became clear
      different faculties need independent semester tracks anyway.

### Consolidated Restructuring: Per-Faculty Semesters, Course Offerings, Student Semester Tracking
- [ ] **Phase 0 — Schema (additive only)** — done, on branch
      `feature/semester-xiiso`. Added:
      - `semesters.faculty_id` (nullable FK -> `faculties`) — "current"
        becomes a per-faculty concept instead of one global flag. Existing
        3 semester rows keep `faculty_id = NULL` until assigned via an
        updated `semesters.php` (Phase 1) — a NULL-faculty semester can
        never be marked current going forward. Unique key changed from
        `uq_semester_name_per_year (academic_year_id, name)` to
        `uq_semester_name_per_faculty_year (faculty_id, academic_year_id,
        name)`; hit and resolved a MySQL 1553 ("index needed in a foreign
        key constraint") gotcha — `academic_year_id`'s FK needed its own
        supporting `idx_semesters_academic_year` index added *before* the
        old unique key could be dropped, since the new one leads with
        `faculty_id` instead.
      - New `course_offerings` table (`course_id`, `semester_id`,
        `lecturer_id` nullable, unique per `(course_id, semester_id)`) —
        replaces `courses.lecturer_id` as a permanent assignment.
        `courses.lecturer_id` itself is untouched in this phase (still read
        by not-yet-migrated code); it gets backfilled into
        `course_offerings` and dropped only in later phases, once verified
        unused.
      - `students.semester_id` (nullable FK -> `semesters`) — replaces
        `students.level` going forward. `level` stays in the schema,
        unused after Phase 3, since there's no reliable formula mapping an
        existing 1-5 value to a real semester row (confirmed: live `level`
        values 1-4 don't correspond to any actual semester).
      Shipped as `migrations/2026_07_faculty_scoped_semesters_and_offerings.sql`
      (applied and verified against the live dev DB — final working
      statement order captured in the file for future fresh installs) and
      mirrored into `admas_attendance_schema.sql`. Verified via
      `SHOW CREATE TABLE` on all three affected tables plus unchanged row
      counts (`semesters` 3, `students` 301, `courses` 24) after the
      migration.
      Full plan (all phases, decisions D1-D7, file-by-file breakdown) is
      recorded at the plan-mode approval stage for this session; Phases
      2-4 (`course_offerings` backfill + write-authorization + course
      management UI, the Level -> Semester student field, and final
      UI-clarity/cleanup) are tracked as in-progress below, updated as
      each phase completes and is verified.

- [x] **Phase 1 — Per-faculty current semester, done and verified.**
      `get_current_semester(mysqli $conn, int $facultyId)` in
      `includes/semester_helpers.php` now requires a faculty and returns
      null for `$facultyId <= 0`; every caller updated to resolve faculty
      from the specific course/student/dean in scope, never from a
      lecturer's own home department (rejected explicitly — a lecturer can
      hold offerings across faculties once Phase 2 lands).
      - `includes/attendance_helpers.php`: added `get_course_faculty_id()`.
      - `semesters.php`: Faculty selector on Add Semester (required for new
        rows), Faculty column + inline "Assign Faculty" action for the
        legacy `faculty_id IS NULL` rows, `set_current_semester` now clears
        `is_current` only `WHERE faculty_id = ?` (was unscoped/global)
        and rejects setting a NULL-faculty semester current. Dropped the
        dead `current_semester_id` settings write and the now-unused
        `save_setting()` helper from this file.
      - `ajax/save_attendance_cell.php`: resolves the course's faculty via
        `get_course_faculty_id()` before calling `get_current_semester()`.
      - `attendance.php`: the biggest structural change — semester/session
        resolution used to run once, globally, before any course was even
        selected; moved into a new `resolve_current_semester_for_course()`
        helper called from both the POST and GET handlers right after
        `course_id` is known. This surfaced a real chicken-and-egg bug
        while building: the classic form's entire filter form (including
        the Course dropdown itself) and the Grid View's course picker were
        both gated behind `$currentSemester !== null`, which can now never
        be true before a course is picked — fixed by ungating the picker
        forms (only `empty($courses)` blocks them now) and moving the
        Semester/Xiiso-session fields into their own conditional block
        that only appears once a course is selected, plus a new
        `admasReloadWithCourse()` JS helper so picking a course reloads
        the page (mirroring the Grid View picker's existing reload
        pattern) to re-resolve sessions for that course's faculty.
      - `reports.php`: Xiiso course dropdown query gained `faculty_id`;
        `$defaultXiisoSemesterId` now resolves from the selected course's
        faculty (was a bare global call, would have fatal-errored);
        added a guard that silently un-selects the semester if a
        submitted course+semester pair belong to different faculties
        (verified via a crafted mismatched request — degrades to no
        semester selected, no error).
      - Dashboards swapped from `settings.current_academic_year_id` to
        per-faculty resolution: `dean/dashboard.php` (trivial, already had
        `$deanFacultyId`), `student/dashboard.php` + `student/courses.php`
        (resolve the viewing student's own `faculty_id`), `lecturer/
        courses.php` (the "Last Session" stats default is now resolved
        **per course row**, one query per distinct faculty represented in
        that lecturer's own course list — not a single lecturer-home
        default), `head_academic/dashboard.php`'s Attendance-by-Faculty
        (genuinely cross-faculty — one small query per faculty, merged),
        `notifications.php` (dean branch trivial; system_admin/
        head_academic branch + the Notify POST handler's server-side
        re-verify both do the same per-faculty loop-and-merge), `admin/
        dashboard.php`'s fallback alerts query (same loop-and-merge
        pattern, capped back to top 8 after merging).
      - Retired the global "Set as Current Year" control entirely from
        `admin/settings.php` and `head_academic/academic_settings.php`
        (byte-for-byte duplicated logic in both) since nothing reads
        `settings.current_academic_year_id` anymore — replaced with a note
        pointing to the per-faculty control on `semesters.php`; kept "Add
        New Academic Year" (still needed to populate the label dropdown
        used when creating semesters).
      - Confirmed via repo-wide grep that `current_academic_year_id` is
        now read in exactly one place on purpose:
        `attendance.php`'s `$defaultAcademicYearId`, a cosmetic
        pre-selected dropdown default for the (unrelated, unchanged)
        student-cohort Academic Year filter — left alone as documented
        low-priority/cosmetic, not a scoping-correctness issue.
      - **Verified end-to-end via real HTTP requests** against the live
        XAMPP app (not just `php -l`, though every touched file was also
        lint-checked clean): created a temporary `system_admin` test
        account (explicit user approval obtained first, since the harness
        blocked account creation as a sensitive action by default), logged
        in via curl, and confirmed `admin/dashboard.php`, `notifications.php`,
        `attendance.php`, `reports.php`, and `admin/settings.php` all
        return 200 with zero PHP warnings/notices/fatals. Assigned the two
        pre-existing legacy semesters to two different faculties (Semester
        3 -> Engineering & IT, semester 1 -> Social Sciences) and set both
        current *simultaneously* via the real `semesters.php` UI — confirmed
        in the DB that both stayed `is_current = 1` at once (the actual
        point of this phase: setting one faculty's current semester no
        longer clears another's). Loaded `attendance.php?course_id=10`
        (a Social Sciences course) and confirmed the Xiiso session dropdown
        and read-only Semester field correctly showed *that faculty's* own
        current semester ("semester 1", all 12 Xiiso sessions); loaded a
        course in a faculty with no current semester set (`course_id=3`,
        informatics) and confirmed a clean "No current semester is set for
        informatics." message with no error, course/shift pickers still
        usable. Verified the `reports.php` mismatch guard directly: a
        matched course+semester query string showed "Semester: semester 1",
        a deliberately mismatched one showed a blank semester with no
        fatal error.
        **Closed the original bug end-to-end**: created a temporary student
        account in the Social Sciences faculty, marked one Xiiso session
        Present via the real Grid View AJAX endpoint
        (`ajax/save_attendance_cell.php`) as the admin, then logged in *as
        that student* (separate session/cookie jar) and confirmed both
        `student/dashboard.php` ("My Attendance %": 100.0%, course row
        present) and `student/courses.php` (green 100.0% badge on that
        course) now show it — the exact symptom from the original bug
        report, now fixed as a consequence of the per-faculty restructuring
        rather than a narrow patch. All temporary accounts and the one
        temporary attendance row were deleted afterward (confirmed via row
        counts back to the pre-test baseline: 301 students, 149 attendance
        rows); the faculty-assignment/current-semester configuration and
        the session dates/sessions generated for Social Sciences' semester
        were deliberately left in place (real configuration needed for the
        feature to work, not test pollution) — Engineering & IT, informatics,
        and Business Administration still have no current semester set and
        will show the "no current semester" guidance message everywhere
        until an admin sets one.

- [x] **Phase 2 — Course offerings, done and verified.** Replaced
      `courses.lecturer_id` (a permanent, university-history-spanning
      assignment) with per-semester `course_offerings` rows as the source
      of "who teaches this course, when" — `courses.lecturer_id` itself is
      left in the schema, unused after this phase, dropped only in a later
      cleanup migration (Phase 4) once verified nothing reads it.
      - **Backfill** (`migrations/2026_07_course_offerings_backfill.sql`):
        a two-tier rule — courses whose attendance history maps to exactly
        one semester *and that semester already belongs to the course's
        own faculty* get that semester's offering; everything else falls
        back to the course's own faculty's current semester (if set).
        Running it against live data surfaced a real correctness trap the
        tier-1-without-the-faculty-check version of this rule would have
        hit silently: 3 courses' attendance history pointed at a semester
        that Phase 1's own test setup had since assigned to a *different*
        faculty than those courses actually belong to (an artifact of the
        old single-global-semester model, where every course shared one
        semester regardless of faculty) — the added faculty-match check
        correctly skips tier 1 for those and falls through to tier 2
        instead of creating a cross-faculty offering. Only the 4 courses
        in a faculty that already had a current semester set (Social
        Sciences) got auto-backfilled; the other 20 (informatics,
        Business Administration — neither had a current semester at
        backfill time) got no row and need manual assignment via the new
        UI, exactly as designed — confirmed via the migration's own
        verification `SELECT`.
      - **Write-authorization**: `user_can_write_course_attendance()` in
        `includes/attendance_helpers.php` now takes a `$semesterId` and,
        for the `lecturer` role, checks `course_offerings` (course +
        *current* semester + lecturer) instead of the permanent column —
        a lecturer unassigned from a course for the next semester loses
        write access automatically instead of retaining it forever. Same
        current-offering scoping applied to `attendance.php`'s course
        list (the lecturer branch's actual security boundary),
        `lecturer/courses.php` (list + Faculty/Department filter option
        queries), and `lecturer/dashboard.php`'s KPI counts + "My
        Assigned Courses" table (each row's Academic Year now comes from
        its own current offering's semester, not one shared global
        label, since a lecturer's courses can span faculties on
        different years at once — D1, never derived from the lecturer's
        own home department).
      - **Any-offering (historical) scoping**, per D2's other branch,
        applied to `reports.php`'s lecturer report filter and Xiiso course
        dropdown (a lecturer can still report on courses they no longer
        currently teach), and `admin/lecturers.php` /
        `head_academic/lecturers.php`'s course-count display badge
        (lifetime distinct-course count via `course_offerings`).
        `admin/lecturers.php`'s delete-blocker switched to
        current-offering-only (a lecturer with only past assignments no
        longer blocks deletion). `student/courses.php`'s displayed
        lecturer name now resolves through the student's own course's
        current offering (current-only, since it's a live "who teaches
        this now" display).
      - **`admin/courses.php` redesign**: Add/Edit form dropped the
        "Assigned Lecturer" field and its department cross-check
        validation entirely (course creation is now catalog-only: code,
        name, department, credit hours). The course list's old
        "Assigned Lecturer" column became "Current Offering" (lecturer +
        semester + academic year, resolved per row via that row's own
        faculty's current semester — "No current semester" vs
        "Unassigned" are rendered as distinct states). Added a "Manage
        Offerings" icon-link per row.
      - **New `admin/course_offerings.php`** (`system_admin`/`dean`,
        dean scoped to own-faculty courses same as admin/courses.php) —
        reachable only via that per-row link, deliberately no standalone
        sidebar entry (confirmed with the user before building: it's a
        per-course contextual page, meaningless without a course already
        picked). Lists all of one course's offerings across every
        semester; the add/update form's Semester dropdown is restricted
        to that course's own faculty's semesters (D1), Lecturer dropdown
        restricted to that course's own department (the validation moved
        here from the old admin/courses.php field), Academic Year shown
        read-only and derived from the selected semester via a small JS
        `data-academic-year` attribute lookup — never a separate input.
        Save is an upsert (`ON DUPLICATE KEY UPDATE`) keyed on the
        `(course_id, semester_id)` unique constraint, so re-saving an
        already-offered semester updates the lecturer instead of
        erroring as a duplicate.
      - **`admin/courses_import.php`**: dropped the "Lecturer" column
        entirely from the bulk-import template, header-matching, and
        INSERT — lecturer assignment now happens exclusively through
        "Manage Offerings" after import, per the same UX simplification
        applied to the manual form.
      - **Verified end-to-end via real HTTP requests**: created a fresh
        temporary `system_admin` account (previous session's temp
        accounts had already been deleted), confirmed
        `admin/courses.php`, `admin/course_offerings.php` (both a course
        with an offering and one in a faculty with no current semester —
        "has no semesters yet" guidance, no error), `admin/lecturers.php`,
        `head_academic/lecturers.php`, `reports.php`, and `attendance.php`
        all return 200 with zero PHP warnings/notices/fatals. Exercised
        the real Manage Offerings save flow (switched course 10's
        semester-2 offering to a different in-department lecturer,
        confirmed via DB; then confirmed a lecturer *outside* that
        course's department was correctly rejected with the offering left
        unchanged) and restored the original lecturer afterward.
        **Directly verified the stale-access fix this phase exists for**:
        created a temporary lecturer account, pointed course 10's current
        offering at them, confirmed via a *separate lecturer login* that
        `lecturer/courses.php`/`lecturer/dashboard.php` showed the course
        and a real Grid View attendance save succeeded; then reassigned
        that same offering back to the original lecturer (simulating a
        semester-to-semester reassignment) and confirmed, still logged in
        as the same temporary lecturer with no new login, that the course
        immediately disappeared from their course list *and* a further
        attendance-save attempt was rejected with `403` — under the old
        permanent `courses.lecturer_id` model this access would have
        persisted indefinitely. All temporary accounts and the one
        temporary attendance row were deleted afterward (confirmed via
        row counts back to baseline: 301 students, 330 users, 19
        lecturers, 149 attendance rows); course 10's `course_offerings`
        row was confirmed restored to its original lecturer (id 11).

- [x] **Phase 3 — Level -> Semester on student registration, done and
      verified.** `students.level` (a university-wide 1-5 scale,
      incoherent once faculties run independent-length semester tracks)
      replaced by `students.semester_id` on every write/read path — the
      column itself stays in the schema, unused, since there's no
      reliable formula to auto-backfill it from the old integer (D6);
      existing students show "Not set" until an admin edits them.
      - `admin/students.php`: the hardcoded 1-5 "Level" `<select>` became
        a "Semester" `<select>`, cascaded from the Faculty selection via a
        new `semestersByFacultyId` JS map (same rebuild-on-faculty-change
        pattern already used for the Department cascade) — a student's
        semester is a position within their own faculty's track, so the
        options always come from `semesters WHERE faculty_id = ?`, never
        a flat university-wide list. Added a server-side check that the
        selected semester actually belongs to the selected faculty
        (mirrors the existing department-belongs-to-faculty check).
        List column "Level" -> "Semester" (`LEFT JOIN semesters`, "Not
        set" for the NULL case).
      - `admin/students_import.php`: template header + required column
        "Level" -> "Semester"; validation switched from a 1-5 numeric
        bound check to resolving the semester **name** against that row's
        already-resolved Faculty (`faculty_id|lowercase(name)` lookup map,
        same shape as the existing Department resolution in this file) —
        an unrecognized semester name for that faculty is now a per-row
        validation error ("Unknown semester...") instead of a numeric
        range check.
      - `notifications.php`: the alerts table's "Level" column became
        "Semester" (`LEFT JOIN semesters` on the student's own
        `semester_id`, "Not set" for NULL, both the SELECT and GROUP BY
        updated).
      - Confirmed via repo-wide grep that no other file reads
        `students.level` — these three were the only readers, matching
        the original audit.
      - **Verified end-to-end via real HTTP requests**: fresh temporary
        `system_admin` account, confirmed `admin/students.php` renders
        the Semester select and "Not set" for existing (pre-migration)
        students with no PHP warnings/errors; created a real student with
        an explicit `semester_id` and confirmed it landed correctly in
        the database; confirmed the faculty/semester mismatch guard
        rejects a crafted request pairing a student's faculty with a
        semester belonging to a *different* faculty (no row created);
        confirmed `notifications.php` renders its new "Semester" column
        cleanly; downloaded the real Excel import template and inspected
        its actual XML contents (not just the HTTP response) to confirm
        the header row says "Semester" with a "Semester 1" sample value.
        Cleaned up afterward — including the `users` row auto-created
        alongside the test student, which `DELETE FROM students` does
        *not* cascade-delete (the FK cascades the other direction, user
        -> student) and had to be removed explicitly — confirmed row
        counts back to exact baseline (301 students, 330 users).

- [x] **Phase 4 — UI clarity, done; one destructive step intentionally
      NOT done.** Consolidated restructuring's final phase.
      - **New shared breadcrumb helper** `render_scope_breadcrumb()` in
        `includes/attendance_helpers.php` — renders "Course › Department ›
        Faculty › Semester › Academic Year" (skipping any segment passed
        as null/empty, so callers can omit whichever don't apply), so it's
        never ambiguous which faculty's semester a given view is scoped
        to. Wired into `attendance.php` (both the classic roster header
        and the Grid View header), `reports.php`'s Xiiso grid report
        header (kept separate from the plain-text `$reportMetaLine` used
        by the Excel/PDF exports, which can't render HTML), and
        `admin/course_offerings.php`'s page header (Course › Department ›
        Faculty only there, since offerings span multiple semesters at
        once — Academic Year is shown per-row in that page's table
        instead of once at the top).
      - **`semesters.php`'s Faculty column + grouping** — already done as
        part of Phase 1's per-faculty rework (the listing query's
        `ORDER BY (faculty_id IS NULL), faculty name, academic year desc,
        start date desc` and the Faculty column were added then, not a
        separate step here); re-confirmed still correct, no changes
        needed in this phase.
      - **Destructive `courses.lecturer_id` column drop — deliberately
        NOT performed.** Re-ran the backfill migration's verification
        query: 20 of the 24 courses (every course in the informatics and
        Business Administration faculties) still have no
        `course_offerings` row at all, because neither of those two
        faculties has ever had a current semester set (confirmed
        unchanged since Phase 2 — nobody has used `semesters.php` for
        them yet). Dropping `courses.lecturer_id` now would permanently
        lose those 20 courses' only remaining lecturer-assignment
        history with no way to recover it, since D5's plan explicitly
        makes this drop conditional on the verification query coming back
        empty. **This is an outstanding manual step for the university
        admin, not a bug**: once Engineering & IT (has no courses at all,
        so not blocking), informatics, and Business Administration each
        have a current semester set via `semesters.php`, either re-run
        `migrations/2026_07_course_offerings_backfill.sql` (tier 2 will
        then backfill the remaining courses automatically) or assign them
        individually via each course's "Manage Offerings" screen — then
        the drop migration below can be written and run safely.
      - **Settings cleanup — deliberately skipped, not just deferred.**
        `settings.current_academic_year_id` is still read once on
        purpose (`attendance.php`'s cosmetic dropdown default, a Phase 1
        decision to leave alone as low-priority/non-scoping) — deleting
        the row would silently blank that one default. Left both
        `current_academic_year_id` and the already-fully-dead
        `current_semester_id` settings rows in place; harmless either way.
      - **Verified end-to-end via real HTTP requests**: fresh temporary
        `system_admin` account, confirmed the breadcrumb renders correctly
        (with the exact expected Course/Semester/Academic Year text, not
        just "no PHP error") on a loaded attendance roster, the Grid View,
        the Xiiso grid report, and Manage Offerings — all 200, zero PHP
        warnings/notices/fatals. Cleaned up the temporary account
        afterward, confirmed row counts unchanged (301 students, 330
        users) since this phase made no data-mutating requests.

**Consolidated restructuring status: Phases 0-4 all complete.** The one
remaining action item — assigning informatics and Business Administration
each a current semester, then re-running the offerings backfill (or using
Manage Offerings per course) before dropping `courses.lecturer_id` — is a
data/configuration task for the university admin, not further code work.

### Bulk Multi-Select Delete ("Delete Selected")
- [x] Added checkbox-based bulk delete (`select-all` header checkbox + a
      "Delete Selected (N)" button, disabled/hidden until at least one row
      is checked, with a `confirm()` dialog listing the count and a
      5-name sample before submitting) to six pages, scoped per-role
      exactly as requested — no role's existing page access was widened:
      - `admin/students.php` — system_admin, registration, dean (each
        already-existing scope).
      - `admin/lecturers.php` — system_admin, dean.
      - `head_academic/lecturers.php` — **new capability**: this role
        previously had view + "Register New Lecturer" only (CLAUDE.md §4);
        bulk delete is now added, scoped to this role's existing
        university-wide read scope (no faculty restriction, since the
        role has none). No single-row delete button was added — bulk
        delete is the only delete path on this page.
      - `admin/courses.php` — system_admin, dean only. Confirmed
        `head_academic` still has zero access to this page at all
        (`require_role(['system_admin', 'dean'])` unchanged; a live
        request as `head_academic` still redirects to
        `unauthorized.php`).
      - `admin/departments.php` — **system_admin only**. Dean's existing
        single-row Delete button/action was left completely untouched;
        the bulk checkboxes/button/hidden form are only rendered when
        `$role === 'system_admin'`, and the `bulk_delete` POST handler
        itself also rejects the action server-side for any other role
        (defense in depth, not just hiding the UI) — verified a Dean
        POSTing `action=bulk_delete` directly against a department with
        children was rejected and nothing was deleted.
      - `semesters.php`'s Xiiso sessions list — system_admin,
        head_academic (this page's existing `require_role`). There was no
        pre-existing single-row delete for sessions at all (sessions are
        normally a fixed auto-generated set of 12 via "Generate
        Sessions"), so bulk delete is the first and only delete path for
        this entity — implemented as a new `delete_session_row()`
        following the same shape as every other entity's blocker check
        (blocked by any `attendance` row referencing that `session_id`).
      - Shared JS: new `assets/js/bulk_delete.js` —
        `admasInitBulkDelete(opts)` wires select-all/row checkboxes to a
        "Delete Selected (N)" button, builds the confirm-dialog sample
        text from each checkbox's `data-label`, and on confirm copies the
        checked values into a separate hidden form (kept separate from
        each row's own tiny Edit/Reset/Delete `<form>` — nesting one
        `<form>` around the whole table would be invalid HTML) before
        submitting it. One reusable file, instantiated per-page with
        different selectors/labels — avoids duplicating the same ~50
        lines of wiring six times.
      - Backend: **every bulk_delete handler reuses the exact same
        blocker/validation logic as the page's existing single-row
        delete — no parallel delete logic was written.** Concretely, each
        page's inline single-delete POST branch was refactored into a
        named function (`delete_student_row()`, `delete_lecturer_row()`,
        `delete_course_row()`, `delete_department_row()`,
        `delete_session_row()`, plus a `head_academic`-specific
        `delete_lecturer_row_head_academic()` since that page has no
        `$role`/`$deanFacultyId` to thread through) that both the
        single-row action and the new `bulk_delete` action call — for
        the five pages that already had single-row delete, this was a
        refactor, not new logic; the function's behavior is byte-for-byte
        identical to what the inline code did before. Each bulk action
        loops the submitted ids, deletes what passes its checks, skips
        (does not abort the batch for) rows that fail, and flashes a
        summary: "N of M selected \<entity\> deleted. Skipped: \<reason
        per skipped row\>."
      - **Verified end-to-end via real HTTP requests** with four
        temporary accounts (system_admin, dean scoped to Engineering &
        IT, registration, head_academic) and disposable test rows
        (students/lecturers/courses/departments, plus attendance and
        course_offerings rows used specifically to trigger blockers):
        - Confirmed successful bulk deletion for each role on each page
          listed above (login → bulk_delete POST → row gone + linked
          `users` row deactivated for students/lecturers, same as the
          existing single-delete behavior).
        - Confirmed skip-not-abort: a batch containing one clean row and
          one blocked row (attendance-blocked student/course/session,
          course_offerings-blocked lecturer, lecturer-blocked department)
          always deleted the clean row and left the blocked row
          untouched, with the skip reason in the flash message.
        - Confirmed a Dean's bulk request including a real student ID
          from a different faculty skipped that row ("not found", the
          same `WHERE faculty_id = ?` scoping the single-delete path
          already had) — the foreign-faculty student was unaffected.
        - Confirmed `head_academic` gets redirected to
          `unauthorized.php` on `admin/courses.php` (still no access at
          all), and that its `admin/departments.php` equivalent isn't
          reachable either (role not in that page's `require_role`).
        - **Test-methodology note for future sessions**: the Xiiso
          session bulk-delete test was initially run against a real,
          currently-in-use semester's sessions 1-3 without first checking
          whether they already had attendance recorded — they did (this
          is exactly what should block deletion, and correctly did), so
          the "successful deletion" half of that test was re-run against
          sessions 5-7 of the same semester instead (genuinely 0
          attendance rows). Two of those three sessions (5, 6) were
          deleted by the system_admin test call and one (7) by the
          head_academic test call to prove both roles' access — all
          three were **immediately restored** afterward via direct SQL
          (`INSERT INTO sessions (semester_id, session_number, type)`)
          once the mistake was noticed. Row existence and `type` are
          exact; the `date` column could not be recovered and was
          restored as `NULL` — consistent with sessions 4 and 8-12 on
          that same semester, which were also still unscheduled, so this
          is very likely a no-op in practice, but flagging it here in
          case anyone notices Xiiso 5/6/7 dates looking unset on
          Engineering & IT's "Semester 3". **Lesson: always check a
          target row's real dependent/related data before using it as a
          "should succeed" fixture, even for an ostensibly-safe
          read-like row such as a session — prefer a dedicated disposable
          semester for this kind of test in the future.**
      - All temporary accounts and disposable rows removed after
        verification; `users` count confirmed back to the 330 baseline.

### Dean Access to semesters.php
- [x] Extended `semesters.php` (Semester + Xiiso session management) —
      previously `system_admin`/`head_academic` only — to also allow
      `dean`, strictly scoped to their own faculty (per CLAUDE.md §4
      "Dean: Own faculty only... Cannot view/edit other faculties").
      - **Navigation**: added `'dean'` to the `roles` array on the
        `Semesters` entry in `includes/nav_items.php` — no separate dean
        page needed since `semesters.php` already lives at the app root
        with a `path` override, same as `attendance.php`/`reports.php`.
      - **Scoping, following the exact pattern already used on
        `admin/students.php`/`admin/departments.php`/etc.**: added
        `$role = current_role()` + a `$deanFacultyId`/`$deanFacultyName`
        block reading `$_SESSION['faculty_id']` (never trusted from
        request input).
        - **Add New Semester form**: Faculty is a disabled `<select>`
          showing only the Dean's own faculty name (no `name` attribute,
          so nothing is even submitted for it) — the backend forces
          `$facultyId = $deanFacultyId` for a Dean regardless of what's
          posted, exactly like `admin/departments.php`'s Faculty lock.
        - **All Semesters list**: for a Dean, a separate prepared-statement
          query (`WHERE s.faculty_id = ?`) replaces the unscoped one —
          other faculties' semesters never appear in the list or become
          reachable as `$selectedSemester` via `?semester_id=`, since that
          selection is resolved by scanning this already-scoped array.
        - **Every write action re-checks ownership server-side**, not just
          via the scoped list/GET side: a new `dean_owns_semester()`
          helper (`SELECT id FROM semesters WHERE id = ? AND faculty_id =
          ?`) guards `generate_sessions`, `set_current_semester`,
          `save_session_dates`, and `bulk_delete_sessions` — each rejects
          a crafted `semester_id` belonging to another faculty with the
          generic "Selected semester does not exist." (same
          not-found-vs-forbidden ambiguity convention used elsewhere in
          this app, e.g. `admin/students.php`'s Dean edit-check) before
          touching the database. `assign_faculty` (backfilling a legacy
          faculty-less semester) is blocked outright for Dean with "Not
          permitted." — a Dean's own semesters always already have
          `faculty_id` set at creation, so this action has no legitimate
          use for that role.
      - **Academic Year dropdown**: unchanged as a plain `<select>` from
        existing `academic_years` rows (no "Add New Academic Year" option
        was ever present) — added a "No academic years exist yet..."
        message plus a disabled Create-Semester button/select when the
        table is empty, mirroring `admin/students.php`'s existing
        empty-state pattern. Applied for all three roles on this page,
        not just Dean, since the same broken-empty-dropdown case existed
        for `system_admin`/`head_academic` too.
      - **Scope banner**: reuses the exact same
        `<div class="scope-banner">` markup/wording pattern as
        `dean/dashboard.php`/`admin/lecturers.php` ("Access scope: {name}
        Faculty only").
      - **Verified end-to-end via a temporary Dean account** (Engineering
        & IT faculty): confirmed the scope banner, faculty-filtered list
        (only that faculty's semester shown), and disabled Faculty select
        all render correctly; created a semester while POSTing a foreign
        `faculty_id` in the request and confirmed the server ignored it
        and forced the Dean's own faculty; confirmed all four write
        actions succeed against the Dean's own newly-created semester
        (generate 12 sessions, set as current — correctly clearing the
        real pre-existing "Semester 3" per the per-faculty exclusivity
        rule, bulk-delete 2 of its sessions); then, from the same
        session, attempted `set_current_semester`, `save_session_dates`,
        `bulk_delete_sessions`, and `generate_sessions` directly against
        a real semester belonging to a different faculty (Social
        Sciences) and confirmed every one was rejected with no DB change
        whatsoever (session dates, session count, and `is_current` flags
        all verified byte-identical before/after), plus confirmed
        `assign_faculty` is flatly blocked for Dean. Cleaned up afterward:
        removed the temp account and test semester, restored the real
        "Semester 3" to `is_current = 1`; `users` count confirmed back to
        the 330 baseline.

### course_offerings Teaching-Period Dates
- [x] Extended `course_offerings` with `start_date DATE NULL` and
      `end_date DATE NULL` — a specific course's actual teaching period
      within its semester, independent of the semester's own (usually
      wider) date range. Both nullable: an offering can exist before its
      exact dates are known, same spirit as `sessions.date`.
      - **Schema**: `migrations/2026_08_course_offerings_dates.sql`
        (additive `ALTER TABLE`, applied and verified against the live
        dev DB via `SHOW CREATE TABLE`), mirrored into
        `admas_attendance_schema.sql`.
      - **Dean access to `admin/course_offerings.php` — already in
        place**, confirmed by reading the file rather than assumed: it's
        been `require_role(['system_admin', 'dean'])` with own-faculty
        course scoping since Phase 2 of the consolidated restructuring;
        no role/access change was needed here.
      - **"Guided flow" (Department → Course → Semester → Lecturer →
        Dates), reusing what already existed rather than building a new
        page**: Department/Course selection already happens by browsing
        `admin/courses.php`'s own-faculty-scoped, department-grouped
        list and clicking a specific course's "Manage Offerings" link;
        Semester (own-faculty-scoped) and Lecturer (own-department-scoped)
        were already the two fields on `admin/course_offerings.php`'s
        existing Add/Update Offering form. Start Date and End Date were
        added as two more fields on that *same* form/submit — no new
        page, no new table, one upsert saves semester + lecturer + both
        dates together, exactly as asked.
      - **Validation**: end date before start date is a hard block
        ("End date must be on or after start date.", existing row left
        untouched on failure). Dates falling outside the selected
        semester's own `start_date`/`end_date` range are a **soft**
        warning only — appended to the success flash message, the save
        still goes through — since a lecturer's real teaching period can
        legitimately run short of (or rarely past) the semester's
        nominal boundaries.
      - **Display, wherever an offering was already shown**:
        `admin/courses.php`'s "Current Offering" column now shows the
        date range under the lecturer name (or under "Unassigned", since
        an offering row — and its dates — can exist independent of
        whether a lecturer is assigned yet). New
        `get_offering_summary()` / `render_offering_summary()` in
        `includes/attendance_helpers.php` resolve and render "Lecturer ·
        start to end" as a small line directly under the existing
        `render_scope_breadcrumb()` output — used at both
        `attendance.php` breadcrumb call sites (roster + Xiiso Grid) and
        `reports.php`'s Xiiso grid report breadcrumb. Kept as a sibling
        helper rather than folded into `render_scope_breadcrumb()` itself,
        since that function's Course/Department/Faculty/Semester/Academic
        Year contract is used elsewhere (e.g. `admin/course_offerings.php`'s
        own header) where there's no single offering to summarize.
      - **Verified end-to-end via temporary `system_admin` and `dean`
        (Engineering & IT) accounts** against a disposable test course:
        saved a valid in-range offering and confirmed it in the DB and on
        the list table; confirmed end-before-start was hard-rejected with
        the existing row unchanged; confirmed an out-of-semester-range
        save both succeeded *and* showed the warning text; confirmed the
        date range renders on `admin/courses.php`, and the new "Lecturer
        · dates" line renders directly under the breadcrumb on both
        `attendance.php` call sites and `reports.php`'s Xiiso grid;
        confirmed the Dean could update the same offering's dates for
        their own faculty, and that a crafted cross-faculty `semester_id`
        was rejected with zero DB change (same pre-existing
        faculty-ownership check, unaffected by the new fields). Cleaned
        up afterward: removed the temp accounts, the test course, and its
        offering row; `users` (330), `courses` (24), and the 4
        pre-existing `course_offerings` rows all confirmed back to
        baseline.

### Add Course + Optional First Offering
- [x] Extended `admin/courses.php`'s Add Course form to optionally create
      the course's first `course_offerings` row in the same submit,
      instead of requiring a separate "Manage Offerings" trip immediately
      after — Semester (own-faculty-scoped) → Academic Year (read-only,
      derived) → Shift → Lecturer (own-department-scoped), left blank by
      default so course creation works exactly as before if untouched.
      **Create-mode only** — Edit mode is unchanged, still just points at
      "Manage Offerings" as before, since this is specifically about a
      *new* course's *first* offering, not editing an existing one.
      - **Investigated before implementing, per explicit instruction —
        recommended and confirmed with the user: Option (b), Shift is
        informational only.** `course_offerings`' unique key
        (`course_id`, `semester_id`) is unchanged; a nullable `shift`
        column was added purely for display. Rejected Option (a)
        (`shift` in the unique key, allowing a different lecturer per
        shift) after grepping every `course_offerings` consumer:
        the lecturer write-authorization check
        (`user_can_write_course_attendance()` in
        `includes/attendance_helpers.php`), the lecturer's own course
        list (`attendance.php`, `lecturer/courses.php`), lecturer
        dashboard KPIs (`lecturer/dashboard.php`), and the student's
        displayed lecturer (`student/courses.php`) all key off
        `(course_id, semester_id, lecturer_id)` with zero concept of
        shift — making shift part of the identity without auditing and
        updating every one of those would silently fail to separate
        write access per shift (a lecturer assigned to only the Morning
        offering would still pass every one of those checks for
        Afternoon/Weekend rosters too), reopening the exact "stale
        access" class of bug Phase 2 exists to close. Documented in
        `migrations/2026_08_course_offerings_shift.sql`'s header for
        whoever revisits this if true multi-lecturer-per-shift support
        is ever needed.
      - **Schema**: `migrations/2026_08_course_offerings_shift.sql`
        (`ALTER TABLE course_offerings ADD COLUMN shift ENUM('morning',
        'afternoon','weekend') NULL`), mirrored into
        `admas_attendance_schema.sql`, applied and verified against the
        live dev DB via `SHOW CREATE TABLE`.
      - **`admin/courses.php`**: reused the exact existing patterns
        rather than inventing new ones — `semestersByFacultyId` mirrors
        `admin/students.php`'s Faculty→Semester cascade shape (extended
        with `academic_year_label`/`is_current`); the Lecturer dropdown
        reuses `admin/course_offerings.php`'s own
        `department_id = ? AND status = 'active'` scoping; the read-only
        Academic Year display reuses `admin/course_offerings.php`'s
        `data-academic-year` attribute technique verbatim. New
        `admasUpdateOfferingFieldsForDepartment()` /
        `admasUpdateOfferingSemesterChange()` JS cascade: Department
        change rebuilds both the Semester and Lecturer option lists;
        Semester change reveals the Shift/Lecturer/Academic-Year block
        (hidden via Bootstrap's `d-none` until a semester is picked, so
        it's correctly excluded from HTML5 required-field validation
        while hidden) and fills in the Academic Year. On the backend, the
        whole offering sub-section only validates at all when
        `offering_semester_id > 0` (the opt-in gate) — semester must
        belong to the selected department's faculty, shift must be one
        of the three valid values (hard error if missing once a semester
        is chosen — a stored `shift` with no value would defeat the
        point of adding it), lecturer is optional and defaults to
        Unassigned exactly like `admin/course_offerings.php`. Course
        INSERT + offering INSERT are wrapped in one transaction
        (mirroring `admin/students.php`'s create-with-related-row
        pattern) so a mid-way failure can't leave an orphaned course with
        no offering or vice versa. The Courses list's "Current Offering"
        column now shows the shift label next to the lecturer name (or
        next to "Unassigned"), reusing the same `SHIFT_LABELS` constant
        shape already used on `admin/students.php`/`attendance.php`.
      - **Untouched, exactly as instructed**: `admin/course_offerings.php`
        ("Manage Offerings") — no shift field was added there, no
        behavior changed; it remains the only way to add a course to
        additional semesters/shifts later or reassign a lecturer without
        recreating the course.
      - **Verified end-to-end via temporary `system_admin` and `dean`
        (Engineering & IT) accounts**: confirmed the new fields render;
        confirmed creating a course with the offering section left blank
        behaves identically to before (course only, zero
        `course_offerings` rows, unchanged success message); confirmed
        creating with Semester+Shift+Lecturer filled in correctly created
        both rows in one request with the shift persisted, and the
        Courses list showed it; confirmed a semester chosen with Shift
        left blank was hard-rejected with **no course created at all**
        (not even a partial one — the transaction never started because
        validation runs first); confirmed a crafted cross-faculty
        `semester_id` was rejected the same way; confirmed the Dean could
        do the same in their own faculty, and that
        `admin/course_offerings.php` still loads and functions normally
        for a course created this way. Cleaned up afterward: removed the
        temp accounts and every test course/offering; `users` (331 —
        this session's real baseline, one genuine new student
        registration happened between sessions and is not test data),
        `courses` (24), and `course_offerings` (4) all confirmed back to
        baseline.

### Phase 1: Department Context on semesters.php (display-only)
- [x] Added an optional "Department" field to `semesters.php`'s Add New
      Semester form — **purely a display/reference note, not scoping**.
      A semester still applies to its entire `faculty_id` exactly as
      before; every department in that faculty still shares the same
      current semester.
      - **Schema**: new
        `migrations/2026_08_semesters_context_department.sql` —
        `semesters.context_department_id INT UNSIGNED NULL`, FK ->
        `departments(id) ON DELETE SET NULL` (so deleting the noted
        department later can't take the semester down with it).
        Deliberately named `context_department_id`, not `department_id`,
        so it can never be mistaken for a real scoping/ownership column
        the way `faculty_id` is. Mirrored into
        `admas_attendance_schema.sql`, applied and verified against the
        live dev DB.
      - **`semesters.php`**: Department `<select>` (cascades from
        Faculty, same `departmentsByFacultyId` JS pattern already used
        by `admin/students.php`'s own Faculty->Department field), always
        optional, labeled "(optional, for your own reference only)".
        Backend: if the posted `context_department_id` doesn't actually
        belong to the resolved `faculty_id` (stale JS state or direct
        tampering), it's **silently dropped to NULL rather than
        blocking semester creation** — this field is informational only,
        so a bad value shouldn't stop a real semester from being
        created. Displayed next to the Faculty name (as a small
        sub-line) everywhere semesters are already listed: the "All
        Semesters" table and the selected-semester detail panel.
      - **Confirmed untouched, by inspection, not just by not having
        edited the file**: `includes/semester_helpers.php`'s
        `get_current_semester()` and every per-faculty scoping check
        added across Phases 0-4 of the consolidated restructuring —
        grepped the whole codebase for `context_department` and found
        it nowhere outside `semesters.php` itself and the two schema
        files above.
      - **Verified end-to-end via temporary `system_admin` and `dean`
        accounts**: confirmed the field renders and cascades correctly;
        created a semester with a valid context department and confirmed
        it saved and displays correctly in both the list and detail
        panel; confirmed a crafted cross-faculty department id was
        silently dropped (`context_department_id` saved as `NULL`, the
        semester itself still created successfully — not rejected);
        confirmed Dean's own-faculty flow works the same way. Cleaned up
        afterward; `users` (331) and `semesters` (5) confirmed back to
        baseline.

### Phase 2: Department Filter on attendance.php (UI convenience only)
- [x] Added an optional "Department" dropdown above the Course selector
      on `attendance.php`, in **both** course pickers on the page (the
      classic roster form and the Xiiso Grid View form) — makes it
      faster to find the right course, especially for a Dean managing
      several departments within one faculty. **Zero new queries, zero
      new access checks**: purely a client-side show/hide filter over
      the Course `<select>`'s already-loaded, already-permission-scoped
      `<option>` elements.
      - Department dropdown options are scoped per role exactly as
        specified: `system_admin` sees every department system-wide
        (via a plain new query — not derived from `$courses`, so
        departments with zero courses still appear, a legitimate empty
        state if selected); Dean sees every department in their own
        faculty (same reasoning); Lecturer's list is derived directly
        from their own already-scoped `$courses` (distinct
        `department_id`s already present in their own current-offering
        course list) — exactly "departments containing courses they're
        assigned to," with no separate query needed since that's already
        what `$courses` represents for that role.
      - Implementation: every course `<option>` (both server-rendered
        and, for `system_admin`, the ones `rebuildCourseSelect()` builds
        dynamically for its existing Faculty->Course cascade) now
        carries `data-department-id`. New `admasFilterCourseByDepartment()`
        toggles each option's `.hidden` based on the selected
        department, clearing the Course selection if the currently-picked
        option becomes hidden. Wired into both pickers' new Department
        `<select>`, and layered on top of `system_admin`'s existing
        Faculty filter (selecting Faculty re-applies whatever Department
        filter is currently set, so the two combine correctly instead of
        one clobbering the other).
      - **Confirmed by inspection**: the three role-scoped `$courses`
        queries (the actual security boundary — unchanged, byte-for-byte
        same shape as before) and
        `user_can_write_course_attendance()` in
        `includes/attendance_helpers.php` were not touched at all in
        this phase — nothing about which courses a user can select or
        mark attendance for changed; only how easy they are to find in
        the dropdown did.
      - **Verified end-to-end via temporary `system_admin`, `dean`, and
        `lecturer` accounts** (a disposable test course + offering was
        needed since the Dean's own faculty currently has zero real
        courses, an unrelated pre-existing state): confirmed each role's
        Department dropdown shows exactly the expected scope (all 6
        departments with faculty prefixes for `system_admin`; only the
        Dean's own faculty's department, no prefix; only the Lecturer's
        own assigned department); confirmed `data-department-id` is
        present and correct on the course options; confirmed the classic
        roster "Load Students" flow still works end-to-end afterward
        with zero PHP warnings/notices/fatals — no regression to the
        real marking flow. Cleaned up afterward (test course, test
        offering, test lecturer, all three temp accounts); `users` (331)
        confirmed back to baseline. (Real `courses` count differs from
        an earlier session's checkpoint — confirmed via a clean-state
        check that none of this session's own test rows were left
        behind; the difference reflects legitimate application usage
        between sessions, e.g. the bulk-delete-courses feature, not
        anything from this phase.)

### Danger Zone: Factory Reset (handover feature)
- [x] Built a permanent, self-service, `system_admin`-only "Danger Zone"
      on `admin/settings.php` that wipes all institution-specific and test
      data down to an empty shell for handover to a real university —
      keeping only System Administrator login access, the `roles` lookup
      table, and the schema itself. Not a one-off script: stays in the app
      for reuse with future universities.
      - **New `includes/factory_reset.php`**:
        `factory_reset_run_backup()` runs a full `mysqldump` (via
        `proc_open`, password passed through the `MYSQL_PWD` env var
        rather than a `-p` CLI arg so it never appears in a process
        listing — this app's first process-exec code) to a timestamped
        file under `C:\xampp\backups\admas_attendance\` (created
        recursively if missing, outside `htdocs`, never web-servable);
        aborts and deletes any partial file if the dump fails or comes
        back empty. `factory_reset_execute()` deletes rows in FK-safe
        order inside one transaction (rollback on any failure — no
        partial wipe): `course_offerings` → `course_enrollments` →
        `attendance` → `notifications` → `password_resets` →
        `role_assignments` → `sessions` → `courses` → `students` →
        `semesters` → `lecturers` → `users` (all except
        `role_id` = system_admin) → `departments` → `academic_years` →
        `faculties`. `roles` is never touched. Also resets the `settings`
        keys that would otherwise dangle-reference deleted rows —
        `university_name`/`campus`/`contact_email`/`contact_phone` to
        blank, `default_faculty_id`/`default_department_id` to `'0'`,
        `current_academic_year_id`/`current_semester_id` to `''` —
        leaving `min_attendance_pct` untouched as a policy default.
      - **`admin/settings.php`**: new red-bordered "Danger Zone" card
        after Academic Year Settings. Requires typing the exact phrase
        `FACTORY RESET` (submit button starts `disabled`, JS unlocks it
        only on an exact match) **and** re-entering the acting admin's
        own current password, verified server-side with
        `password_verify()` against a freshly-fetched hash (same pattern
        as `admin/profile.php`'s change-password flow) — both re-checked
        server-side regardless of the JS gate, since a raw POST can skip
        it. On success, runs the backup first and refuses to wipe
        anything if it fails; on a full success, renders a report
        directly on the page (backup path/size, per-table row-deleted
        counts, remaining `system_admin` username(s)) instead of
        redirecting, since there's a one-time report to show.
      - **Verified end-to-end against the real dev DB** (captured a
        manual safety-net `mysqldump` snapshot first): confirmed the
        submit button stays disabled until the phrase is typed exactly;
        confirmed a wrong password and, separately, a wrong phrase are
        both rejected server-side with zero rows changed (row counts
        checked before/after both attempts); ran a real reset and
        confirmed every table in the delete order was emptied except
        `roles` (6) and the surviving `admin01` row, `settings` values
        blanked/zeroed as designed with `min_attendance_pct` unchanged,
        the on-page report's counts matched the actual pre-reset counts
        exactly, the backup file existed and was non-empty, and — the
        deeper check — restoring that backup into a scratch database
        reproduced the exact pre-reset row counts (331 users, 149
        attendance, 44 students, 4 faculties); confirmed the acting
        admin's own session stayed valid and could still load
        `admin/settings.php`/`admin/dashboard.php` after the wipe.
        Cleaned up afterward: dropped the scratch restore-test database,
        deleted the test run's backup file and the manual safety-net
        snapshot, and restored the real dev DB from that safety-net
        snapshot — confirmed every table's row count back to the exact
        pre-test baseline.
      - No schema migration needed — pure delete/update logic, no new
        columns or tables.

### Semester Delete
- [x] Added a Delete action to `semesters.php`'s "All Semesters" list, for
      `system_admin` (any semester) and `dean` (own faculty only, same
      scoping already enforced elsewhere on this page) — `head_academic`
      does not get the button, and a crafted POST from that role is
      rejected server-side with "Not permitted."
      - New `delete_semester_row()` follows the exact same
        "block if dependents exist" shape as
        `delete_department_row()`/`delete_course_row()`/
        `delete_lecturer_row()`: scoped `SELECT` first (dean-owned or not
        found), then blocker counts, then `DELETE`.
      - **Blockers, exactly as requested**: any `course_offerings` row for
        the semester, or any `attendance` row reachable through its
        `sessions`. Sessions with zero attendance are left alone to
        cascade-delete automatically with the semester
        (`fk_sessions_semester`/`fk_offerings_semester` are both
        `ON DELETE CASCADE`) — no separate bulk-delete-sessions step
        needed first.
      - **Two additional blockers found during investigation and added
        beyond the literal request** (flagged to and confirmed by the
        user before building): `students.semester_id` has no
        `ON DELETE` clause (RESTRICT) — without an app-level check, a
        semester with any student still assigned would fail with a raw
        SQL constraint error instead of a friendly message, so a
        `students` count blocker was added. Deleting a faculty's
        `is_current = 1` semester would silently break
        `get_current_semester()` for every attendance/report page
        depending on it, so that's now blocked outright with "set a
        different semester as current before deleting it."
      - Confirmation is a plain `onsubmit="return confirm(...)"` dialog
        (same severity/pattern as Faculty/Department/Course/Lecturer
        delete elsewhere) — not the Danger-Zone typed-phrase pattern,
        which is reserved for the full factory reset.
      - **Verified end-to-end** via a temporary `system_admin` account (and
        a temporary `dean` + temporary second faculty for the cross-faculty
        check): built five disposable test semesters exercising each path —
        course_offerings blocker, students blocker, attendance-via-sessions
        blocker, is_current blocker (all four correctly rejected with the
        exact expected message and zero DB change), and one dependency-free
        semester that deleted cleanly with its 12 generated-but-unused
        sessions cascading away automatically; confirmed `head_academic`
        never sees the button and a crafted POST is rejected; confirmed a
        temporary dean scoped to a different faculty can't see or delete a
        semester belonging to faculty "informatic" (crafted POST rejected
        with "Semester not found", zero DB change). Cleaned up afterward:
        all temp semesters/course/student/accounts/faculty removed;
        confirmed the real `semester1` (faculty "informatic") and its
        `is_current = 1` flag were untouched throughout, and `users`/
        `semesters`/`faculties` counts back to the pre-test baseline (2/1/1).
      - No schema migration needed.

### Profile Photo Upload
- [x] Implemented exactly as previously scoped in Deferred Decisions:
      `users.photo_path VARCHAR(255) NULL` (filename only, not a full
      path), upload control on each role's own Profile & Password page,
      replacing the initials-circle avatar in both the topbar
      (`includes/topbar.php`) and on the profile page itself once a photo
      exists. Admin list tables remain text-only — no thumbnails added
      anywhere else.
      - **Schema**: `migrations/2026_08_users_photo_path.sql`, mirrored
        into `admas_attendance_schema.sql`; `includes/auth.php`'s
        `current_user()` SELECT extended to include it.
      - **New `includes/profile_photo.php`**: `save_profile_photo()`
        validates via `getimagesize()` — real image-content detection,
        never the client-supplied filename or MIME type — against an
        allowed-type map (JPEG/PNG/GIF/WEBP), enforces a 5MB cap, and
        saves under `uploads/profile_photos/` with a
        `bin2hex(random_bytes(16))` filename (client filename discarded
        entirely). `delete_old_profile_photo()` best-effort removes the
        previous file whenever a new one is saved, so replaced photos
        don't accumulate on disk forever (not explicitly requested, added
        for correctness).
      - **New `uploads/profile_photos/.htaccess`**: denies execution of
        `.php`/`.php\d`/`.phtml`/`.pht` — created directly (not
        runtime-`mkdir`'d) so the directory is never live without it.
        Confirmed effective: `AllowOverride All` is set on `C:/xampp/htdocs`
        in `httpd.conf`, and a live test PHP file placed in the directory
        returned 403 and never executed, both before and after all upload
        testing. This is the first `.htaccess` file anywhere in this
        project — no prior convention existed to reuse.
      - **All six `*/profile.php`** (admin, dean, head_academic,
        registration, lecturer, student) got the identical change: a new
        `upload_photo` POST action, and a new small photo block at the top
        of the existing "Profile Information" card — current photo (or
        initials fallback, reusing the exact `$initials` variable already
        computed by `includes/topbar.php`, since `include` shares scope) —
        with a camera-icon label opening the file picker. New shared
        `assets/js/profile_photo.js` (same convention as the existing
        `bulk_delete.js`/`password-toggle.js`) handles the client-side
        live preview (`FileReader`) and enables the "Change Photo" submit
        button only once a file is chosen; server-side re-validates
        everything regardless.
      - **Verified end-to-end** via temporary `system_admin` and `student`
        accounts: confirmed all four allowed formats (JPG/PNG/GIF/WEBP)
        upload successfully and immediately replace the initials avatar on
        both the profile page and the topbar; confirmed a text file
        renamed to `.jpg` is rejected server-side with "Please upload a
        JPG, PNG, GIF, or WEBP image." despite passing the client-side
        extension filter; confirmed a 6MB file is rejected with "Image
        must be 5MB or smaller."; confirmed each successful re-upload
        deletes the previous file from disk (checked the actual directory
        listing between uploads); confirmed the `.htaccess` protection
        survived all of the above. Cleaned up afterward: removed all
        uploaded test photos, the temp accounts, and the temp student row;
        `uploads/profile_photos/` confirmed empty except `.htaccess`, and
        `users`/`students` counts back to the pre-test baseline (2/0).

### Profile Photo Circle/Border Follow-up
- [x] Fixed two visual bugs reported after the Profile Photo Upload
      feature above went live: the topbar avatar rendered as a stretched
      rectangle instead of a cropped circle (root cause: the user was
      viewing a browser-cached copy of `app.css` from before the
      `.avatar-photo` rule existed — confirmed via direct curl fetches of
      the live server response, bypassing any browser cache, that the
      served HTML/CSS were already correct; a hard refresh resolved it).
      Hardened anyway rather than relying on "just don't cache": added
      explicit `width`/`height` HTML attributes (not just CSS) to every
      place the photo renders — `includes/topbar.php` (42px) and all six
      `*/profile.php` preview images plus `assets/js/profile_photo.js`'s
      dynamically-created live-preview `<img>` (72px) — and a `display:
      block` on `.avatar-photo`. Also added a 2px `var(--admas-sky)`
      border ring around the circular photo in all of the same places
      (topbar, profile-page preview, and the JS live-preview element), per
      a follow-up request, for a consistent branded look wherever the
      photo appears. Verified live via temporary accounts + a
      deliberately non-square (400×100, later 50×50) test image at each
      step, confirming the rendered HTML always carries the explicit
      `width`/`height` attributes and the border/circle CSS, cleaning up
      test accounts/files afterward each time.

### Dark / Night Mode
- [x] Added an app-wide dark/night mode toggle, persisted per-browser via
      `localStorage` (no schema change — no existing per-user preference
      mechanism existed to extend, and none was needed).
      - **`assets/css/app.css`**: expanded `:root` from 4 to 10 custom
        properties (`--admas-surface`, `--admas-text`, `--admas-text-
        muted`, `--admas-text-faint`, `--admas-border`, `--admas-shadow`,
        `--admas-tint-opacity`, `--admas-scope-bg`, `--admas-scope-text`,
        alongside the existing `--admas-sky`/`--admas-navy-*`/`--admas-
        bg`), plus a `[data-theme="dark"]` block redefining all of them.
        Every rule in the file that previously hardcoded a surface/text/
        border color now references the matching variable instead —
        ~51 raw-hex declarations rewritten. Status/badge/KPI-icon tint
        backgrounds (`.badge-present`, `.kpi-icon.bg-*`, `.grid-cell[data-
        status=...]`, etc.) switched from fixed `rgba(...)` to the modern
        `rgb(r g b / var(--admas-tint-opacity))` syntax, with the opacity
        itself bumped from 0.12 to 0.22 in dark mode — a flat opacity
        percentage that read fine as a light wash on white goes nearly
        invisible on a dark surface, so this was necessary, not just
        mechanical substitution (flagged as a judgment call needing visual
        confirmation, same as everything below). The 8 duplicated
        `.btn-icon` `<style>` blocks that used to live separately in
        `admin/settings.php`, `admin/departments.php`, `admin/lecturers.php`,
        `semesters.php`, `admin/students.php`, `admin/courses.php`,
        `admin/users.php`, and `admin/faculties.php` were extracted into
        one shared rule here and removed from all 8 source files
        (`admin/faculties.php`'s and `admin/users.php`'s own *extra* rules
        in those same blocks — `.faculty-summary-*`, `.role-info-table` —
        were kept, just with their colors also switched to variables).
      - **Toggle mechanism**: `includes/topbar.php` gained a moon/sun
        icon button (`#themeToggleBtn`) in `.topbar-right`, plus a
        `<script src=".../assets/js/theme_toggle.js">` at the end of the
        same partial — since every one of the 34 in-app pages already
        includes `topbar.php` identically, this reached every page with
        zero per-page edits. New `assets/js/theme_toggle.js` (same
        self-contained-IIFE convention as `profile_photo.js`/`password-
        toggle.js`) reads/writes `localStorage['admas-theme']` and
        toggles `data-theme="dark"` on `<html>`. `includes/sidebar.php`
        (the earliest shared include point, right after `<body>` opens)
        gained a tiny synchronous inline script that applies the saved
        theme immediately, to minimize (not fully eliminate) a flash of
        the wrong theme on first paint — full elimination would need
        editing all 37 pages' individual `<head>` blocks, since no shared
        `<head>` partial exists in this app; accepted as a deliberate
        trade-off.
      - **The ~194 inline `style="..."` hardcoded colors across 34 files**
        (194 minus the ~13 that lived inside the now-removed `.btn-icon`
        blocks) were swept with a scripted find-replace of the two exact
        dominant literal strings — `style="color: #0b1f3a;"` (115
        matches) → `style="color: var(--admas-text);"`, and `style=
        "background-color: #0ea5e9; border-color: #0ea5e9;"` (63
        matches) → the same pair with `var(--admas-sky)` — verified by
        grep count before/after to confirm the exact expected number of
        replacements with nothing missed or over-matched. The one
        remaining miscellaneous instance (`reports.php`'s "No data for
        the selected filters" row) was fixed by hand.
      - **Explicitly excluded, on purpose**: `login.php`,
        `forgot_password.php`, `reset_password.php` (no `sidebar.php`/
        `topbar.php` include, so no toggle is reachable there — they keep
        their fixed sky gradient always) and `reports.php`'s
        `render_report_pdf_html()` print/PDF export stylesheet (printed
        output should stay light/print-appropriate regardless of the live
        UI theme). `admin/dashboard.php`'s Chart.js line-color config
        (`#0ea5e9` in the Weekly Attendance Trend chart) was also left
        untouched — a known, minor, non-blocking gap noted here for
        anyone revisiting: the color still reads fine on a dark
        background, it just isn't dynamically theme-aware.
      - **Verified programmatically** (no browser/screenshot tool
        available): `php -l` on all 36 touched files; grep sweeps
        confirming zero remaining occurrences of either target literal
        string anywhere in the app, and confirming the `[data-theme=
        "dark"]` block plus all 10 variables exist in the served
        `app.css`; curl smoke tests across 10 pages spanning `system_admin`
        and `student` roles confirming 200 responses, `themeToggleBtn`
        present in the rendered HTML, and no PHP warnings/fatals in the
        output. **Final visual confirmation (contrast, the tint-opacity
        judgment call, overall polish in both themes) still needs the
        user's own screenshots** — flagged explicitly as an open item
        pending their review, same limitation as the earlier avatar-photo
        rendering bug in this session.
      - No schema migration needed.

### Deferred Decisions
- **Student ID as username/password scheme**: scoped but paused before
  implementation — the user's request assumed a "Student ID" field
  already exists as an admin-typed form input (e.g. "1472/23"), but
  investigation found `students.student_no` is actually auto-generated
  today (`generate_student_no()` in `includes/lecturer_accounts.php`,
  format `ADM-2026-0001`) with no such field anywhere in
  `admin/students.php` or `admin/students_import.php`. Option identified
  and recommended: repurpose `student_no` into a free-text, admin-typed
  required field instead of auto-generating it, since it's already the
  one identifier displayed/searched everywhere (rosters, reports,
  notifications) — revisit when requested. Separately confirmed
  `must_change_password` **already exists and works end-to-end** in this
  app today (`users.must_change_password` defaults to 1 in the schema;
  `login.php` and `require_role()` in `includes/auth.php` both force a
  redirect to the role's own `profile.php` while it's set; every role's
  `profile.php` plus `reset_password.php` clears it on a successful
  password change) — so when this is revisited, that prerequisite is
  already satisfied and doesn't need to be rebuilt.

