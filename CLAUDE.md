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
| **University Rector** | Whole system, supervisory/oversight | Full **read-only VIEW** access everywhere (Students, Lecturers, Courses/Offerings/Enrollments, Departments, Faculties, Attendance grids, Semesters/Xiiso, Academic Years — including per-student and per-lecturer detail drill-down pages); plus full **CRUD** on User Management and Settings only (role appointment, Notification thresholds, backups, incl. Settings → Danger Zone factory reset for handover, with an automatic pre-wipe `mysqldump` backup) | **Cannot create/edit/delete/import/bulk-act on** any day-to-day academic-data page (Students, Lecturers, Courses, Course Offerings, Course Enrollments, Departments, Faculties, Attendance marking, Semesters/Xiiso sessions, Academic Years) — enforced server-side on every write action, not just hidden buttons; retains full CRUD only on User Management and Settings |
| **Head of Academic Affairs** | All faculties | Set Academic Year & minimum attendance threshold; **set Semesters per Year per faculty**; view cross-faculty reports; **register new Lecturer accounts**; **cross-faculty Course Management, full scope — Add/Edit/Delete Courses, Manage Offerings, cross-list via Course Search, Enroll/Remove Students on a course roster (`admin/course_enrollments.php`), all faculties, no bulk Excel import**; **User Management (reset password, activate/deactivate) over every Dean/Head of Academic Affairs/Registration Office/Lecturer/Student account, university-wide**; **Assign Role — appoint an existing Lecturer/Student (or create a new user) as Dean or Registration Office only**; **View Students information — the same read-only, university-wide student directory + per-student drill-down (`admin/student_view.php`) granted to University Rector, view-only, no create/edit/delete** | Cannot manage student **profile** records (`admin/students.php` — creating/editing a student's own record stays Registration Office/Dean only; course-roster enrollment is a distinct action, granted above), **cannot manage University Rector accounts** ("except top management" — enforced server-side on every action, not just hidden from the list), **cannot appoint University Rector or (more) Head of Academic Affairs accounts** — University Rector remains the project's one overall root authority and the only role that can appoint into Head of Academic Affairs itself, no bulk Course Excel import, delete accounts, or edit system Settings |
| **Registration Office** | All faculties | Add/edit students, bulk Excel import of students, enrollment reports | No access to Attendance or Settings |
| **Dean** | **Own faculty only** | Full **CRUD** on Courses (create/edit/delete, including a course's first offering and cross-faculty cross-listing search), Lecturers (create/edit/delete/reset password), and Semesters/Xiiso (create semesters, generate sessions, set dates, Start/Current/End/Waiting status, "Save All Semesters"), plus Assign Courses (lecturer-first course_offerings management) — all restored to full CRUD within their own faculty only (an earlier session's temporary read-only-Viewer conversion for these three areas was explicitly reversed per later request); full **read-only VIEW** access within their own faculty for everything else (Departments, Students, Attendance grids, Course Enrollments, Lecturer Check-Ins — including per-student and per-lecturer detail drill-down pages); faculty-scoped Reports; Notify (raise a below-threshold alert) and Staff Messages stay available | **Cannot create/edit/delete/import/bulk-act on** Students, Departments, Course Enrollments (roster enrollment — real student data, distinct from course/offering schedule metadata), or Attendance marking, within their own faculty — enforced server-side on every write action, not just hidden buttons; still cannot view/edit other faculties' data (Course/Lecturer/Semester writes remain own-faculty-only even though Courses full CRUD includes browsing every faculty's catalog to cross-list), no system Settings, no User Management |
| **Lecturer** | Own assigned courses only | Take attendance, view "My Courses" (filtered by Academic Year + Faculty + Department to disambiguate duplicate course codes across faculties), class reports | Cannot see other lecturers' courses or student management screens |
| **Student** | Own record only | View own attendance %, enrolled courses, profile | Read-only, no management screens |

Every role also has a **"Profile & Password"** screen to edit their own details and
change their password.

The **University Rector** appoints users into the Dean / Head of Academic Affairs /
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
roles            (id, name)  -- university_rector, head_academic, registration, dean, lecturer, student
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
- ~~Not yet done — cross-faculty lecturer assignment (planned)~~ — **resolved**.
  The real university this system models has lecturers who teach in more
  than one faculty at once, and "common" courses that multiple faculties
  share via one lecturer. `attendance.php`, `lecturer/courses.php`,
  `lecturer/dashboard.php`, and `reports.php` already resolved a lecturer's
  courses/current-semester **per course's own faculty**, not the lecturer's
  home faculty, so nothing changed there. The actual blockers were the
  `department_id = ?` lecturer-ownership checks in `admin/courses.php`
  (Add Course + first offering) and `admin/course_offerings.php` (Manage
  Offerings — the real per-semester assignment page) — both relaxed to
  `status = 'active'` (any lecturer system-wide), for **both**
  `system_admin` and `dean` (a Dean assigning an outside lecturer only
  touches a course_offering inside their own faculty — it doesn't grant
  visibility into the lecturer's home faculty's data, so this doesn't
  violate Dean's "own faculty only" scope). Both lecturer dropdowns now
  label an outside lecturer with their home faculty (e.g. "Jane Doe
  (Engineering)") — `admin/course_offerings.php`'s flat list sorts own-
  department first; `admin/courses.php`'s JS cascade lists the picked
  department's own lecturers first, then every other active lecturer under
  an "── Other faculties ──" separator. Verified live: created a temporary
  test course under Nursing and assigned it to an Engineering-department
  lecturer via `admin/course_offerings.php` — saved and displayed
  correctly, no errors; cleaned up afterward.
- **Lecturer-first "Assign Courses" page** (`lecturer_courses.php`, app
  root, shared by `system_admin`/`dean`/`head_academic`) — the course-first
  flow above (`admin/course_offerings.php`: open a course, pick a
  lecturer) had no inverse: a lecturer teaching "common" courses across
  faculties had their assignments scattered one course-page at a time,
  with no single view of everything they teach. This page starts from the
  lecturer instead: an "Assign Courses" link on their row in
  `admin/lecturers.php` and `head_academic/lecturers.php` opens a page
  showing every course_offerings row for that lecturer, any faculty
  (Course/Semester/Faculty/Academic Year/Teaching Period), plus a
  Faculty→Department→Course→Semester cascading form to add a new one.
  Writes the exact same `course_offerings` table as the course-first page
  — two doors, one room. Scoping: `system_admin`/`head_academic` may
  add/remove anywhere; `dean` sees a lecturer's full cross-faculty teaching
  list read-only (schedule metadata only, not student/attendance data) but
  can only add/remove an assignment inside their own faculty — rows
  outside it show "Other faculty" instead of a Remove button, and the
  server independently re-derives/re-checks the course's faculty on every
  POST rather than trusting anything posted from the form. Verified live
  as temp system_admin, dean (own-faculty allow + cross-faculty POST
  correctly rejected), and head_academic accounts; cleaned up afterward.
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

### Student Name Split (First/Father/Grandfather), Attendance Excel Import, Xiiso Grid Borders
- [x] Three related features requested together after the user shared a
      screenshot of the real paper/Excel attendance tracker ADMAS staff
      already use, which splits a student's name into three columns
      (FIRST NAMES / FATHER'S / G.FATHER'S — the standard Somali given +
      father's + grandfather's naming) and lays out attendance as one
      column per calendar day grouped into colored month bands. Planned in
      plan mode (3 parallel Explore agents traced every `full_name`
      consumer, the existing Xiiso grid rendering, and the existing import
      page-flow pattern first), then confirmed with the user via
      AskUserQuestion on three open design forks before building: Excel
      day-columns map to the 12 Xiiso slots **automatically, in
      chronological order** (not a manual per-column picker); the 301
      (at the time; actually 77 in the live dev DB) existing students'
      `full_name` gets **auto-split** on migration (word 1/word
      2/remainder), correctable afterward via the edit form; the 3-column
      split applies to **students only**, not lecturers/admins (the
      screenshot's own Lecturer line is one free-text string).
      - **Schema** (`migrations/2026_08_students_name_parts.sql`, mirrored
        into `admas_attendance_schema.sql`): added `students.first_name`/
        `father_name` (required) and `grandfather_name` (nullable — not
        every real name has 3 parts), backfilled from the existing
        `full_name` via `SUBSTRING_INDEX` (confirmed first, via a live
        query, that every one of the 77 existing students' names is
        cleanly 3 words — so no separate PHP backfill script was needed,
        simplifying the originally-planned approach), then converted
        `full_name` itself into a **MariaDB `GENERATED ALWAYS AS
        (TRIM(CONCAT_WS(' ', first_name, father_name,
        grandfather_name))) STORED`** column. This was the key design
        decision from planning: of ~25 files that read `students.full_name`
        across the app (dashboards, reports, notifications, attendance
        rosters, `admin/courses.php`'s lecturer dropdown, etc.), only
        `admin/students.php` and `admin/students_import.php` ever *wrote*
        it — making it generated means those ~23 read-only files needed
        **zero code changes**, they keep resolving `full_name` exactly as
        before. Applied and verified against the live dev DB via `SHOW
        CREATE TABLE` and a full-table diff of all 77 rows' computed
        `full_name` against their original value (byte-identical). A
        `mysqldump` backup was taken immediately before running this
        migration, per the security audit's own recommendation earlier in
        this file.
      - **`admin/students.php`**: the single "Full Name" input became
        three (First Name/Father's Name required, Grandfather's Name
        optional); the create/update handlers now insert/update the three
        physical columns instead of `full_name` (which MySQL/MariaDB
        rejects explicit values for on a generated column); the edit-GET
        SELECT and search filter (`WHERE first_name LIKE ? OR father_name
        LIKE ? OR grandfather_name LIKE ? OR full_name LIKE ?`) were
        updated to match; the list table's Full Name column needed **no
        change** at all, it still just reads `full_name`.
        `generate_student_username()` (in `includes/lecturer_accounts.php`,
        untouched) is now called with `$firstName` directly instead of the
        old concatenated full name — its existing "first word of whatever
        string it's given" logic already does exactly the right thing
        without modification.
      - **`admin/students_import.php`**: the single Full Name header
        match became three (`find_import_column()` synonym lists for
        "First Names"/"Father's"/"G.Father's", matching the real
        tracker's exact wording plus Somali synonyms), the downloadable
        template regenerated with the new 3-column header, and the
        confirm-insert writes the three columns the same way as the
        manual form.
      - **New `attendance_import.php`** (app root,
        `require_role(['system_admin', 'dean', 'lecturer'])`, same
        shared-file/role-scoping convention as `attendance.php`) — bulk
        historical-attendance import matching the real tracker's exact
        layout. Course list reuses `attendance.php`'s exact
        system_admin/dean query shapes (so "what can I import into" can
        never drift from "what can I mark attendance for"); the lecturer
        branch deliberately differs (any course they've *ever* held an
        offering for, not just their current one, since this page exists
        specifically for historical backfill) — actual write permission
        for the picked course+semester is independently re-verified via
        the existing `user_can_write_course_attendance()` at both Preview
        and Confirm, so a permissive course list is never the real
        security boundary. Upload → preview → confirm flow mirrors
        `admin/students_import.php`'s proven shape (parsed rows stashed in
        `$_SESSION['attendance_import_preview']`, confirm reads them back
        rather than re-parsing). Column detection: scans for the "REG/NO"
        header cell to skip the ADMAS UNIVERSITY/Faculty/Course
        Name/Lecturer banner rows above it; since a real tracker may
        vertically merge REG/NO/names/P/A/% across two header rows (band
        labels sharing the REG/NO row, bare day-numbers on the row below)
        rather than putting everything on one row, the day-number row is
        auto-detected by checking which of the REG/NO row or the row below
        it has more cells that look like bare 1-31 day-numbers, and the
        month-band labels are read from whichever of those two rows isn't
        the day-number row (forward-filled leftward per column, since
        PhpSpreadsheet's `toArray()` only populates the top-left cell of a
        merged band). Detected dates are sorted chronologically and the
        first 12 mapped straight to Xiiso 1–12 (generating the semester's
        session rows first via the existing `generate_sessions_for_semester()`
        if needed); anything past 12 is dropped with a visible warning
        listing the skipped dates. REG/NO is matched against
        `students.student_no` directly (case-insensitive/trim, same
        convention as the students importer) — unmatched rows are flagged
        as skippable errors, not a hard failure. On Confirm: any mapped
        Xiiso session whose `date` is still `NULL` gets it filled in from
        the file; a session that already has a *different* date keeps its
        existing date (flagged as a warning on the preview page, never
        silently overwritten) — every actual attendance write reuses the
        existing shared `save_attendance_record()` primitive (the same one
        `attendance.php`'s classic save and `ajax/save_attendance_cell.php`
        already use), so this import can never diverge from how a manual
        mark is recorded. Redirects to the course's own Xiiso Grid report
        on success. Added to `includes/nav_items.php` as "Import
        Attendance" for the same three roles.
      - **Xiiso grid sky-blue borders** — new `build_xiiso_chunks()` in
        `includes/attendance_helpers.php` (sibling to the existing
        `build_month_groups()`) groups a semester's sessions into fixed
        bands of 4 by position (Xiiso 1–4/5–8/9–12, independent of actual
        calendar dates) for a colspan band row, reusing the already-
        existing `.grid-month-band`/`.col-group-end` CSS classes (no new
        CSS needed for the table-based views) plus one small new
        `.badge-divider` rule for `student/xiiso_grid.php`'s Present/
        Absent/Average badge-pill row (which isn't a table there). Applied
        to all three Xiiso-grid render sites: `reports.php`'s
        `build_xiiso_grid_report()` (every-4-session divider plus
        individual dividers after the P and A columns, and a new colspan
        band header row above the existing one); `attendance.php`'s Grid
        View (layered on top of its pre-existing calendar-month band row —
        the two groupings are independent and don't conflict — plus
        adding the previously-missing `col-group-end` to its P/A/%
        columns); `student/xiiso_grid.php` (had zero grouping
        infrastructure before this — added the same two-row band+session
        header and wrapped its summary badges in `.badge-divider`).
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary `system_admin` account: created a student via
        the new 3-field form and confirmed the generated `full_name`,
        derived username, and search-by-father's-name all worked; edited
        the same student's 3 name fields and confirmed the change
        propagated to both `students.full_name` (generated) and
        `users.full_name` (computed concatenation); downloaded the
        regenerated students-import template and confirmed its 3
        name-header columns; ran a students import with one fully-valid,
        one valid-without-grandfather, and one invalid (blank name) row
        and confirmed exactly 2 imported with the invalid row correctly
        skipped. For the attendance importer: built a test `.xlsx`
        replicating the real tracker's two-row-header/merged-band
        structure (REG/NO + 3 names + P/A/% on one row, bare day-numbers
        on the row below, spanning Feb/Mar month bands) against a real
        course with 36 real enrollments and a semester that (as it turned
        out) already had its 12 session dates assigned from earlier
        testing — confirmed the preview correctly detected and
        chronologically mapped all 6 test dates to Xiiso 1–6, and
        correctly flagged+preserved the semester's existing (different)
        session dates rather than overwriting them; confirmed on Confirm
        that both test students' present/absent marks landed in the
        `attendance` table exactly matching the source file, correctly
        keyed to the *existing* session dates (proving the
        keep-existing-date safety behavior actually works, not just that
        it's coded); confirmed the Xiiso Grid report, `attendance.php`'s
        Grid View, and (logged in as the enrolled test student directly,
        after a forced password change) `student/xiiso_grid.php` all
        render the new sky-blue band/border/divider classes with zero PHP
        warnings/notices/fatals, and that the student's own grid correctly
        displayed the imported Present/Absent marks. All temporary
        accounts, students, the course enrollment, and the imported
        attendance rows were deleted afterward; `students` count confirmed
        back to the exact pre-session baseline (77) — the pre-existing
        session dates and the 36 real enrollments on the test course were
        left untouched throughout.

### admin/students_import.php: Skip Arbitrary Title Banners Too
- [x] Follow-up to the Student Name Split/Attendance Import session above.
      `admin/students_import.php` already had a mechanism to skip a leading
      "Field:", "value" block (e.g. "Faculty:" / "Informatic" — used when a
      whole sheet shares one Faculty/Department/Academic Year/Semester/
      Shift for every student), but that peeling loop stops at the first
      row that doesn't match a known field label — so a plain decorative
      title banner above the table (university name, "YEAR: 1", a sheet
      title — the same kind of banner `attendance_import.php` already
      skips) would have been wrongly treated as the header row itself,
      breaking the import. Added a second skip-loop after the existing
      one: keeps advancing past any row that doesn't actually contain a
      Student-No/REG-No-like or First-Name-like cell (reusing the same
      `find_import_column()` candidate lists used for real column
      matching) until it finds the real header row. The existing "Field:",
      "value" batch-default behavior is untouched — this only fires once
      that loop has already stopped, so both mechanisms compose instead of
      conflicting.
      **Verified end-to-end via real HTTP requests** with a temporary
      `system_admin` account: a file with a 4-row decorative banner (no
      "Field:" pattern at all) above the real header now previews
      correctly (regression-tested: was previously going to fail); a file
      using the pre-existing "Field:", "value" batch-default pattern still
      previews correctly with the same row-number/defaults behavior as
      before (no regression). Neither test file was actually confirmed/
      inserted (preview-only, by design, to isolate this from the
      DB-writing path), so no cleanup of `students`/`users` rows was
      needed; the temporary admin account was deleted afterward.

### attendance.php: Removed Single-Session Form, Grid View Is Now the Only View
- [x] Discussed with the user before writing any code (they explicitly asked
      to "wait" on this one first): removed the older "classic"
      single-session marking form from `attendance.php` entirely — the
      interactive Xiiso Grid (click a cell to mark Present/Absent) is now
      the only way to mark attendance on this page, shown directly on
      load, no more List View/Grid View toggle.
      - **Real gap found and fixed before removing anything**: the classic
        form's roster query filtered by `students.shift`, but the Grid's
        own roster query (`get_xiiso_grid_data()`) didn't filter by shift
        at all — removing the classic form outright would have silently
        merged Morning/Afternoon/Weekend students into one roster for any
        department running multiple shifts through the same course. Added
        an optional `?string $shift = null` parameter to
        `get_xiiso_grid_data()` (`includes/attendance_helpers.php`) that
        adds `AND s.shift = ?` to both the enrollment and department-
        fallback roster queries only when set — `reports.php`'s own call
        site is untouched (still passes no shift, unaffected).
      - **Semester also became user-selectable** (previously the Grid
        always silently used `get_current_semester()`, with no way to view
        or correct a past semester's grid): `attendance.php` now resolves
        whichever `semester_id` is in the querystring (validated to
        belong to the selected course's own faculty) and only falls back
        to that faculty's current semester when none is specified.
      - **Found and fixed a real correctness bug this surfaced**:
        `ajax/save_attendance_cell.php` (the endpoint every grid-cell
        click actually saves through) always independently re-resolved
        `get_current_semester()` itself, completely ignoring whatever
        semester the on-screen grid was displaying — so if Semester
        selection had shipped without this fix, clicking any cell on a
        non-current semester's grid would have failed every time
        ("Invalid Xiiso session"), since the submitted `session_id` would
        never match the *current* semester's own session list. Fixed by
        having the endpoint accept and validate an explicit `semester_id`
        from the client (checked against the course's faculty) instead of
        ever silently substituting "whichever semester happens to be
        current right now" — `assets/js/attendance_grid.js` now reads
        `data-semester-id` off the grid `<table>` (added alongside the
        pre-existing `data-course-id`) and includes it in every save
        request.
      - **Read-only rendering for semesters/courses outside write scope**:
        `attendance.php` now computes
        `user_can_write_course_attendance($conn, $role, $currentUser,
        $courseId, $semesterId)` once per page load (the exact same
        function the AJAX endpoint already used to reject unauthorized
        saves) and disables every grid cell plus shows a "Read-only" badge
        and banner when false — e.g. a lecturer can still view a semester
        they no longer (or don't yet) hold a `course_offerings` row for,
        matching how `reports.php` already lets roles view data outside
        their write scope, but can no longer be misled into thinking a
        click will save when the server would reject it anyway.
      - **New picker**: Faculty (unchanged) → Department filter (unchanged,
        client-side only) → Course (unchanged) → **Semester** (new — every
        semester belonging to the selected course's faculty, not just
        current, populated client-side via a `courseFacultyIdMap` /
        `semestersByFacultyId` JS cascade with no page reload, the same
        pattern `attendance_import.php` already established) → **Shift**
        (new — optional, blank = every shift) → one "Load Grid" submit.
        Changing Course no longer immediately reloads the page (the old
        Grid View's behavior) — it now just repopulates the Semester
        dropdown's options client-side (pre-selecting that faculty's
        current semester as the default), so Course/Semester/Shift can all
        be set before a single reload, avoiding a double round-trip.
      - **Fixed a stale deep-link**: `lecturer/courses.php`'s "Take
        Attendance" quick-link (from the pending-Xiiso-sessions widget) was
        building a URL with `load=1&session_id=X&academic_year_id=Y` —
        parameters that belonged only to the now-deleted classic form.
        Updated it to link with `course_id` + `semester_id` + `shift`
        instead (the Grid View shows the whole semester at once, so a
        specific pending session id is no longer a meaningful target).
        Grepped the rest of the codebase for other `attendance.php?...`
        links (`lecturer/dashboard.php`) and confirmed those only ever
        used the still-valid `course_id` param, nothing else to fix.
      - **Verified end-to-end via real HTTP requests** against the live
        app: confirmed `attendance.php` loads cleanly with no course
        selected, and with a course selected (defaults to that faculty's
        current semester, 432 grid cells for a real 36-student/12-session
        course); confirmed the Shift filter correctly returns 0 cells for
        a shift no enrolled student has and the full roster for the shift
        they're actually in; confirmed selecting an explicit non-current
        semester via `?semester_id=` renders that semester's own sessions;
        saved a real cell via AJAX with the new `semester_id` field and
        confirmed the DB row; confirmed a crafted AJAX request pairing a
        real session with the *wrong* semester_id was rejected ("Invalid
        Xiiso session") with zero DB change; built a temporary lecturer +
        temporary course + two `course_offerings` rows (one for a semester
        they hold, one they don't) and confirmed the grid renders fully
        writable (no Read-only badge, real AJAX save succeeds) for the
        held semester and fully disabled (Read-only badge + banner, AJAX
        save rejected with HTTP 403) for the one they don't; confirmed a
        temporary dean scoped to a real faculty sees the correct scope
        banner and full write access within their own faculty. All
        temporary users/lecturer/course/offerings/attendance rows were
        deleted afterward, and the one real `course_offerings` row
        (course 23 / semester 9) that was temporarily reassigned during
        the writable-lecturer test was explicitly restored to its original
        lecturer — confirmed via a direct row check, not just "delete and
        hope."
      - Deliberately left untouched: `STATUS_LABELS`/`GRID_STATUS_LABELS`
        constants in `includes/attendance_helpers.php` (still used
        elsewhere — `GRID_STATUS_LABELS` by the AJAX endpoint itself); the
        `.view-toggle-btn` CSS rule in `assets/css/app.css` (now unused by
        this page specifically, left in place as harmless dead CSS rather
        than risk touching a shared stylesheet rule for a purely cosmetic
        cleanup).

### Xiiso Grid Report Export: Borders, Meta Line, Logo Fix
- [x] User asked (after I explained what was/wasn't currently possible,
      without writing code first, per their request) for two of three
      export gaps to be closed on `reports.php`'s Xiiso Attendance Grid
      report — the third (auto-computing "this is faculty X's Nth
      semester/Year Y" since `semesters.name` is free text with no ordinal
      column) was left as a future decision, not built.
      - **Sky-blue column-group borders + P/A/% accent in PDF/Excel
        exports**: the on-screen grid already computes `group_end`/
        `summary`/`header_accent` flags per column (`build_xiiso_grid_report()`
        via `build_xiiso_chunks()`), but both export builders were
        rendering every column identically, ignoring those flags entirely.
        Fixed in `render_report_pdf_html()` (new `.col-group-end`/
        `.col-summary` CSS rules in the PDF's own embedded `<style>` block,
        applied via the same flags on each `<th>`/`<td>`) and in the Excel
        export branch (a new per-column loop after the row-writing loop
        applies a sky-blue header fill for summary/header_accent columns,
        a light sky-blue tint fill down the P/A/% data columns, and a
        medium sky-blue right border at every `group_end` column — using
        the newly-imported `PhpOffice\PhpSpreadsheet\Style\Border`).
        Both are additive: only `build_xiiso_grid_report()`'s columns ever
        set these flags, so the other 3 report types are unaffected (empty
        checks default false) — confirmed via a live regression export of
        all 3 other report types after the change.
      - **Faculty/Department/Academic Year/Lecturer added to the export
        meta line**: this data was already being computed and shown
        on-screen (`render_scope_breadcrumb()` + `render_offering_summary()`/
        `get_offering_summary()`) but `$reportMetaLine` — the only piece of
        that context actually passed into the PDF/Excel builders — only
        ever said "Course: ... | Semester: ...". Extended it to also pull
        `department_name`/`faculty_name` from `$xiisoCourseById`,
        `academic_year_label` from `$xiisoSemesterById`, and the assigned
        lecturer's name via a new `get_offering_summary()` call (falls back
        to "Unassigned" when the course_offerings row has no lecturer_id
        set yet) — one shared variable, so both exports and the on-screen
        title line all picked it up automatically.
      - **PDF logo half cut off, fixed**: root cause was the `<img>` tag
        only setting its size via CSS (`.header img { width: 56px; height:
        56px; }`) with no matching HTML `width`/`height` attributes —
        confirmed by decompressing a real exported PDF's content stream:
        without the fix, dompdf doesn't reliably constrain an embedded
        base64 image to a CSS-only size, so the real 474×474 logo file
        (confirmed via `getimagesize()`) was being placed at closer to
        native size and clipped by the header's `overflow: hidden`. Added
        `width="56" height="56"` directly on the `<img>` tag; re-verified
        by decompressing the regenerated PDF's content stream and
        confirming the image's placement matrix now scales it to exactly
        42×42pt (56px × dompdf's 0.75 px→pt factor) — small enough to sit
        fully inside the header, no clipping.
      **Verified end-to-end**, not just by reading the code: created a
      temporary system_admin account, exported both PDF and Excel for a
      real course/semester/lecturer combination (LA — linear Algebra /
      Semester 8 / lecturer "suldaan naaji"), and inspected the actual
      generated files rather than trusting the code alone — decompressed
      the PDF's content streams to confirm the sky-blue color operator is
      actually painted (3 streams) and the logo's placement matrix is
      42×42pt as expected; re-opened the Excel file with PhpSpreadsheet's
      own reader and printed back every header cell's fill color and
      right-border style (confirmed sky-blue 0EA5E9 fill + medium
      sky-blue border on exactly the expected columns: Student No/Full
      Name/P/A/%, and a border at every 4th Xiiso session) and the A1–A3
      meta rows (confirmed Department/Faculty/Semester/Academic
      Year/Lecturer all present and correct). Re-exported all 3 other
      report types (course_attendance/department_summary/faculty_summary,
      both PDF and Excel) afterward as a regression check — all 6 still
      200 and valid files, confirming the shared export code path wasn't
      broken for the report types that don't set these new column flags.
      Both temporary accounts were deleted afterward; `users` count
      confirmed back to the 85 baseline.

### Course Import: Optional Per-Row Semester Offering (Academic Year/Semester/Shift/Lecturer) + Banner Skip
- [x] `admin/courses_import.php` extended with four new **optional** columns —
      Academic Year, Semester, Shift, Lecturer — mirroring two existing
      patterns rather than inventing a new UI: `admin/courses.php`'s manual
      "Add Course + Optional First Offering" opt-in rule (a Semester left
      blank = catalog-only course, exactly as before), and
      `admin/students_import.php`'s per-row column pattern (no separate
      pre-upload dropdowns — a course belonging to Semester 2 and one
      belonging to Semester 9 can sit in the same file, each row resolving
      its own). Explicitly chosen over a separate "Bulk Assign Courses to a
      Semester" page after the user pushed back that a new page with its
      own Faculty/Department/Semester dropdowns would be "another window" —
      the simpler answer was to extend the one import flow that already
      exists.
      - **Resolution rule**: the whole offering group is validated only when
        a row's Semester cell is non-empty (opt-in, per-row). When it is:
        Academic Year and Shift become required for that row (clear
        per-row error if either is missing), Lecturer stays optional
        (defaults to Unassigned). Semester is resolved via a new
        `(faculty_id, academic_year_id, name)` lookup —
        **includes Academic Year in the key**, unlike
        `students_import.php`'s simpler `(faculty_id, name)`-only lookup,
        specifically because the same Semester name can legitimately repeat
        across different academic years for one faculty and the user asked
        for Academic Year to disambiguate that. Faculty itself is never a
        separate column — it's resolved from the row's already-resolved
        Department. Shift accepts either the raw enum value or the
        friendly label (`normalize_shift_input_for_course()`, a local
        duplicate of `students_import.php`'s own `normalize_shift_input()`,
        matching that file's existing local-helper convention rather than
        extracting a new shared one). Lecturer matches by full name against
        any active lecturer system-wide (not department-restricted — same
        "common courses across faculties" reasoning already used by
        `admin/courses.php`'s own offering-lecturer field).
      - **Banner skip**: added the same `$looksLikeHeaderRow`-style loop
        used in `admin/students_import.php` (checks for a Code-like or
        Name-like cell before treating a row as the real header), so
        decorative title rows above the real table (university name, a
        department note, etc.) no longer break the import.
      - **Confirm step**: the existing course-insert transaction now also
        inserts one `course_offerings` row per valid row that resolved a
        Semester (plain `INSERT`, not upsert — these are always brand-new
        `course_id`s within the same import, so no existing offering could
        conflict). Flash message now reports courses imported *and*
        offerings created separately.
      - **Preview table**: replaced the removed single "Lecturer" column
        (dropped in an earlier session) with one combined "Offering" column
        showing "Semester · Year · Shift · Lecturer" or an em dash for a
        catalog-only row — kept as one column rather than four separate
        ones to avoid widening an already 6-column table, matching the
        user's explicit priority that the UI stay simple.
      - **Template** regenerated with the 4 new example columns (one row
        with a full offering filled in, one without, mirroring the two
        real usage patterns).
      **Verified end-to-end via live HTTP requests** with a temporary
      system_admin account and a 7-row test file (including 3 decorative
      banner rows above the real header, to prove banner-skip): confirmed
      the template downloads as a valid 8-column `.xlsx`; confirmed preview
      correctly flagged, with the exact expected message, every error case
      tested — missing Academic Year when Semester was given, unknown
      semester name, invalid shift, unknown lecturer, unknown department —
      while the two valid rows (one catalog-only, one with a full real
      offering against real "Semester 9"/"2023/2024"/"Abdirahman Mohamed"
      data) both showed "Ready to import"; confirmed the Offering column
      rendered "Semester 9 (2023/2024) · Afternoon Shift · Abdirahman
      Mohamed" correctly; confirmed Confirm imported exactly 2 courses,
      created exactly 1 course_offerings row, and skipped exactly 5 —
      matching the flash message — then verified directly in the database
      that the offering row's `semester_id`/`lecturer_id`/`shift` were all
      exactly correct. Cleaned up afterward: the 2 test courses, the 1 test
      offering, and the temp account were deleted; `users` count confirmed
      back to the 85 baseline.

### Dark Mode Follow-up: Form Label Contrast
- [x] User reported that on `admin/profile.php` (and, by the same shared
      markup, every other role's `profile.php`) in dark mode, field labels
      like "Current Password"/"New Password"/"Username" were essentially
      invisible. Root cause: Bootstrap's `.form-label`/`.form-text` classes
      have no explicit color rule of their own in `app.css` — they were
      inheriting the browser/Bootstrap default near-black text color, which
      the original Dark Mode pass never touched (that pass only rewrote
      *our own* hardcoded inline `style="color: #0b1f3a"` occurrences, not
      Bootstrap's own unstyled defaults) — near-black text on the dark navy
      page background reads as blank.
      Fixed with two new rules scoped to `[data-theme="dark"]` in
      `assets/css/app.css` (light mode is untouched — this was reported and
      confirmed as a dark-mode-only issue): `.form-label` becomes a
      sky-blue pill with bold white text (matching the user's explicit
      request), and `.form-text` (the small helper captions like "At least
      8 characters.") now reads `var(--admas-text-muted)` instead of
      Bootstrap's default. Pure CSS, zero PHP changes — confirmed via grep
      that `class="form-label"` is used consistently (98 occurrences across
      23 files: every `*/profile.php`, every `admin/*.php` CRUD form,
      `login.php`, `forgot_password.php`, `reset_password.php`,
      `attendance_import.php`, `semesters.php`, etc.), so this one CSS
      change fixes the contrast issue app-wide instead of needing a
      per-file edit.
      **Also clarified, no code change needed**: the user separately asked
      that dark/night mode not be something the System Administrator can
      force on for every user — confirmed by re-reading
      `includes/topbar.php`/`assets/js/theme_toggle.js` and grepping
      `admin/settings.php` for any theme-related setting that this was
      never built as an admin-wide control to begin with. The toggle has
      always been a purely individual `localStorage` preference (see the
      original "Dark / Night Mode" entry above) — each user's own choice,
      with no admin override path existing anywhere in the codebase.

### Dark Mode Follow-up #2: Table Row Backgrounds (Grid View Student Names Invisible)
- [x] User reported (with a screenshot of `attendance.php`'s Xiiso Grid View
      in dark mode) that student full names were washed out/unreadable,
      while the Student No column right next to them looked fine — a subtle
      giveaway that pointed away from a simple "wrong text color" bug.
      Root cause, found by reading `table.admas-table`'s markup: the
      **Full Name** `<td>` has always had an explicit
      `style="color: var(--admas-text);"` (correct, light-gray-on-dark in
      dark mode) — but the **Student No** `<td>` right next to it has no
      color styling at all, and still looked fine. That only makes sense if
      the table's actual background was still white, not the dark card
      surface behind it: Bootstrap 5.3's `.table` class sets its own
      `background-color: var(--bs-table-bg)` directly on the `<table>`
      element (defaulting to white), and this app never redefines that
      Bootstrap variable — so the table painted a solid white rectangle
      over the dark `.admas-card` behind it. Student No's default dark
      Bootstrap text then read fine by accident (dark-on-white), while Full
      Name's intentionally light dark-mode color (meant for a dark
      background) became invisible on that same accidental white.
      **This bug wasn't unique to this one page** — every table anywhere in
      the app using the shared `admas-table` class (student/lecturer/course
      lists, reports, notifications, this grid, etc.) had the identical
      white-table-on-dark-card problem; it simply wasn't as noticeable
      elsewhere because most other tables' body-text columns don't set an
      explicit color and were "accidentally" reading fine against that
      unintended white background — a "correct-looking" table for the
      wrong reason.
      Fixed by pointing Bootstrap's own table variables at our theme
      variables directly on `table.admas-table` in `assets/css/app.css`
      (`--bs-table-bg`, `--bs-table-color`, `--bs-table-border-color`, plus
      an explicit `background-color`/`color` for browsers/paths that don't
      resolve the CSS-variable indirection), **not** scoped to
      `[data-theme="dark"]` this time — in light mode `--admas-surface` is
      already white and `--admas-text` already dark navy, so this is a
      no-op there and only changes dark-mode behavior. One shared CSS rule,
      zero PHP changes, fixes every `admas-table` instance across the whole
      app at once (striped/hover row tints and cell borders also inherit
      correctly since Bootstrap derives those from the same `--bs-table-*`
      variables).

### Clickable, Modernized KPI Dashboard Cards
- [x] Every role's dashboard KPI cards (`admin/dashboard.php`,
      `dean/dashboard.php`, `head_academic/dashboard.php`,
      `registration/dashboard.php`, `lecturer/dashboard.php`,
      `student/dashboard.php`) are now clickable links to the most relevant
      management/report page for that stat, and the shared `.kpi-card`/
      `.kpi-icon`/`.admas-card` CSS in `assets/css/app.css` was restyled for
      a more modern look — a colored left accent bar per card (matching its
      icon's color family via new `.accent-sky`/`.accent-navy`/
      `.accent-green`/`.accent-amber` classes), a bolder/tighter `.kpi-value`,
      a hover lift (`translateY(-4px)` + deeper shadow + sky-blue border) with
      an icon micro-rotation and a fade-in `.kpi-arrow` chevron, applied only
      to `<a class="kpi-card">` elements (not `<div>`-based ones, so
      non-clickable KPI cards like `notifications.php`'s three still render
      cleanly with no dead hover affordance). `.admas-card` itself also
      gained a subtle border + refined shadow/transition, and `.alert-row`
      (the Attendance Alerts / Low Attendance widgets) got a rounded
      sky-tinted hover state — both improvements apply everywhere those
      classes are already used (dashboards, `reports.php`'s panels,
      `notifications.php`), no per-page changes needed for those two.
      Link targets, chosen per-role based on what page/filter is actually
      reachable for that role (verified against `includes/nav_items.php` and
      each target page's own `require_role()`, not assumed):
      - **admin/dashboard.php**: Total Students → `admin/students.php`,
        Total Lecturers → `admin/lecturers.php`, Active Courses →
        `admin/courses.php`, Avg Attendance Today → `reports.php`.
      - **dean/dashboard.php**: Students/Lecturers in Faculty →
        `admin/students.php`/`admin/lecturers.php` (both already
        dean-faculty-scoped), Departments → `admin/departments.php`, Avg
        Attendance Today → `reports.php`.
      - **head_academic/dashboard.php**: this role has no Students/
        Faculties/Departments management page of its own, so Faculties/
        Departments/Students link into `reports.php` pre-filtered via
        `?report_type=faculty_summary` / `department_summary` (confirmed
        `reports.php` reads `report_type` from `$_GET` directly, so the
        query-string pre-selects the right report on load); University Avg
        Attendance Today → plain `reports.php`.
      - **registration/dashboard.php**: Total Registered Students and Added
        This Month both → `admin/students.php` (registration's own
        university-wide student management page); Faculties/Departments →
        `reports.php?report_type=faculty_summary`/`department_summary`
        (registration has Reports access limited to exactly these two
        types, matching its no-Attendance-access scope).
      - **lecturer/dashboard.php**: My Courses and Total Students both →
        `lecturer/courses.php`; Sessions Recorded → `reports.php`.
      - **student/dashboard.php**: My Attendance % →
        `student/attendance_history.php`; Enrolled Courses and Courses
        Below Threshold both → `student/courses.php`.
      **Verified end-to-end via real HTTP requests**, not just by reading
      the code: created one temporary account per role, logged each in via
      curl with a per-role cookie jar, confirmed every dashboard returns
      `200` with zero PHP warnings/notices/fatals, extracted every rendered
      `kpi-card` `href` from the actual HTML response (not the source code)
      to confirm they matched what was intended, then followed each of
      those 17 links (with `-L` redirect-following) for its own role's
      cookie jar and confirmed the *final* URL after any redirect was still
      the intended target page — not a bounce to `unauthorized.php` or
      `login.php` — for every single link across all 6 roles. All 6
      temporary accounts were deleted afterward; `users` count confirmed
      back to the 85 baseline.

### lecturer/courses.php: Explicit Faculty/Academic Year/Shift Columns
- [x] The "My Courses" table already *queried* `faculty_name` and
      `academic_year_label` (used as small sub-text under Course/Semester)
      and `co.shift AS offering_shift` (queried but not displayed at all).
      The user asked for these to be their own visible columns, motivated
      by a real scenario this app already supports at the data level but
      wasn't surfacing clearly: a lecturer can hold `course_offerings`
      rows for the same course across two different faculties at once
      (each faculty running its own concurrent current semester), and each
      needs to be told apart at a glance.
      - Split the table into `Course | Semester | Faculty | Department |
        Academic Year | Shift | Students | Sessions | Pending Xiiso`
        instead of nesting Faculty under Course and Academic Year under
        Semester. Added the same local `SHIFT_LABELS` constant already
        used by `admin/courses.php`/`admin/students.php`/`attendance.php`/
        `lecturer/dashboard.php` (friendly label, em dash if the offering
        has no shift set yet) — no shared query changes were needed, since
        every value was already selected by the existing SQL
        (`lecturer/courses.php`'s own query joins `course_offerings` +
        `semesters` + `academic_years` + `faculties` per row already).
      - **Noted, not changed**: a *same-semester* multi-shift assignment
        (one lecturer teaching the same course's Morning and Afternoon
        shift within one semester) is not representable today —
        `course_offerings`' unique key is `(course_id, semester_id)`,
        `shift` is informational-only, a deliberate decision from the
        earlier "Add Course + Optional First Offering" session (making
        shift part of the identity would have silently broken every
        write-authorization check keyed off `(course_id, semester_id,
        lecturer_id)`). The cross-faculty case this session targeted
        *is* fully representable and now correctly displayed; the
        same-semester-different-shift case remains a known, previously
        documented architectural boundary, not something this display
        change could or should paper over.
      - **Verified end-to-end via real HTTP requests**: created a
        temporary second faculty/department/semester (needed real
        `start_date`/`end_date` bracketing today — discovered along the
        way that `refresh_semester_current_flags()`, called from
        `includes/auth.php` on every request, recomputes every semester's
        `is_current` from `CURDATE() BETWEEN start_date AND end_date` on
        every page load, silently resetting a manually-set `is_current`
        flag back to 0 for any semester with `NULL` dates — not a bug,
        existing intended behavior, just something the test fixture had
        to account for) plus a temporary lecturer holding two
        `course_offerings` rows — one in Informatics/Information
        Technology (Semester 9, Morning) and one in the temporary faculty
        (Afternoon) — and confirmed both rows rendered with the correct,
        independent Faculty/Semester/Academic Year/Shift values, with zero
        PHP warnings/notices/fatals. All temporary rows (course_offerings,
        lecturer, user, course, semester, department, faculty) were
        deleted afterward; `users` count confirmed back to the 85
        baseline.

### lecturer/dashboard.php: Same Column Expansion + Reordered Above Pending Xiiso
- [x] Follow-up to the `lecturer/courses.php` column-expansion above, applied
      to the "My Assigned Courses" table on `lecturer/dashboard.php` too —
      same `Course | Semester | Faculty | Department | Academic Year |
      Shift | Students | Last Session` columns. The dashboard's own query
      was missing `faculty_name`/`department_name`/`semester_name`/
      `offering_shift` entirely (it only ever selected
      `academic_year_label` + `semester_id`, plus a separate
      last-marked-attendance subquery for Shift) — added a
      `JOIN faculties f ON f.id = d.faculty_id` and the four missing
      columns to the `SELECT`/`GROUP BY`, and switched the Shift column
      from "whichever shift the most recent attendance row happened to be
      marked under" to `co.shift` (the offering's own assigned shift,
      matching `lecturer/courses.php`'s definition — more correct anyway,
      since an offering's shift shouldn't depend on whether attendance has
      been marked yet).
      - Also reordered the two dashboard cards per the user's explicit
        request: "My Assigned Courses" now renders **above** "Pending
        Xiiso Sessions" (previously the reverse) — the assigned-courses
        list is what visually proves to a supervisor/evaluator that the
        system is in real use, so it should be the first thing seen, not
        buried under a pending-work warning card.
      - **Verified end-to-end via a real HTTP request**: created a
        temporary lecturer with one `course_offerings` row (course 24 /
        Semester 9 / Morning), confirmed the dashboard renders "My
        Assigned Courses" before "Pending Xiiso Sessions" in the actual
        HTML order, and confirmed the course row shows all 8 columns
        correctly (Semester 9 / Informatics / Information Technology /
        2023/2024 / Morning Shift / 36 students / Never) with zero real
        PHP warnings/notices/fatals (the only "warning" text in the raw
        HTML was the pre-existing `text-warning`/`badge-warning` CSS
        classes on the Pending Xiiso card, not an error). Temporary
        lecturer/user/offering rows deleted afterward; `users` count
        confirmed back to the 85 baseline.

### semesters.php: Manual Semester Creation + Manual Start/End/Waiting Status
- [x] Replaced the automatic "Generate Next Semester" flow (auto-numbered
      from the faculty's last semester, auto-chained Start Date, dates
      driving `is_current` via a `CURDATE() BETWEEN start_date AND
      end_date` recompute on every request) with fully manual control, per
      explicit request: creation should be by hand, not automatic, and the
      semester's status should be a deliberate choice — three buttons,
      Start / End / Waiting — not something calendar dates decide.
      - **Schema** (`migrations/2026_08_semesters_manual_status.sql`,
        mirrored into `admas_attendance_schema.sql`): `semesters.start_date`
        /`end_date` changed from `NOT NULL` to `NULL` (now optional
        reference-only fields, no longer load-bearing); new
        `status ENUM('waiting', 'current', 'ended') NOT NULL DEFAULT
        'waiting'` column. `is_current` (`TINYINT`) is kept as a physical
        column in sync with `status` on every write (`is_current = 1` iff
        `status = 'current'`) specifically so every *other* file that reads
        `WHERE is_current = 1` (dashboards, `attendance.php`, `reports.php`,
        `notifications.php`, `ajax/save_attendance_cell.php`, etc.) needed
        **zero changes** — confirmed by grep that `is_current` is written in
        exactly one place now (`semesters.php`'s new `set_status` action)
        and read everywhere else unchanged. Backfilled existing rows'
        `status` from their prior computed state
        (`is_current` → `current`, past `end_date` → `ended`, else
        `waiting`) so nothing visually flipped the moment the migration ran
        — took a `mysqldump` safety backup first, per this file's own
        established convention for schema changes.
      - **`includes/semester_helpers.php`**: removed
        `refresh_semester_current_flags()` (the automatic per-request
        recompute — now dead code once nothing calls it),
        `next_semester_number_for_faculty()`, `next_semester_start_date_for_faculty()`,
        and `semester_end_date_from_start()` (all three only ever served the
        removed auto-chaining flow). `get_current_semester()` now queries
        `WHERE s.status = 'current'` instead of `is_current = 1` (same
        result today since the two are kept in sync, but `status` is now
        the actual source of truth) and tie-breaks concurrent-current
        semesters by `ORDER BY s.id DESC` instead of `start_date DESC`,
        since start dates are no longer guaranteed to exist.
      - **`includes/auth.php`**: removed the
        `refresh_semester_current_flags(db())` call that used to run on
        every single request before any page's own queries — status
        changes now happen only when a user explicitly clicks one of the
        three buttons, never silently in the background.
      - **`semesters.php`**: "Generate Next Semester" replaced by "Create
        Semester" — the form is now exactly the three fields requested:
        **Faculty** (Dean locked to their own), **Semester** (a plain
        required text input, e.g. "Semester 1" — typed by hand, no
        auto-suggested/pre-filled value, matching "gacan lagu qoraa"), and
        **Academic Year**. No Start Date field at all. On submit
        (`create_semester` action): validates Faculty/Academic Year exist,
        Semester name is non-empty, and pre-checks the
        `(faculty_id, academic_year_id, name)` uniqueness constraint with a
        friendly message before the DB would reject it; inserts with
        `start_date`/`end_date` left `NULL` and `status = 'waiting'`
        (the column default), then still calls
        `generate_sessions_for_semester()` to create the 12 Xiiso rows —
        that function was already null-safe when a semester has no dates
        (leaves the 12 rows' own `date` columns `NULL`, filled in later one
        by one via the pre-existing "Save Dates" table), so no change was
        needed there.
      - **Old `start_now`/`end_now` actions replaced by one `set_status`
        action** taking an explicit `status` value (`waiting`/`current`/
        `ended`), validated against the enum, dean-ownership-checked the
        same way every other write action on this page already is. Setting
        one semester's status never touches any other semester's row — a
        faculty can still have more than one concurrently "current"
        semester if the admin/dean chooses that (unchanged intent from the
        old date-driven system, just explicit now instead of incidental).
      - **UI**: the "All Semesters" list's Current column is now a plain
        status badge (Current/Ended/Waiting) with no inline action
        buttons — the three actual buttons (**Start**, **End**, **Waiting**,
        in that order) live in the detail panel on the right, always all
        three shown together (not contextually swapped in/out like the old
        Start Current/End Semester pair), with whichever one matches the
        semester's current status rendered solid-colored and `disabled` so
        it's clear at a glance which state is active. `delete_semester_row()`
        blocks deletion using `status === 'current'` instead of the old
        `is_current` int check (same rule, now reading the real source of
        truth). The detail header's dates line
        (`start_date to end_date`) is now null-safe — hidden entirely if
        both are still unset, shown with `—` for whichever one is missing
        otherwise.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary `system_admin` account: confirmed the Create
        Semester form renders with exactly the three requested fields and
        no Start Date field; created "Semester TEST-QA" and confirmed in
        the database it landed with `status = 'waiting'`, `start_date`/
        `end_date` both `NULL`, and 12 real `sessions` rows (all with
        `date IS NULL`, ready for manual entry); clicked all three status
        transitions in sequence (Start → Current, End → Ended, Waiting →
        back to Waiting) and confirmed each one persisted correctly with
        `is_current` staying in sync; set it Current again alongside the
        real pre-existing "Semester 9" (also Current) and confirmed
        **both** stayed Current simultaneously — no auto-clearing — proving
        the multi-concurrent-current behavior survived the move to manual
        control; confirmed creating a second semester with the exact same
        Faculty+Name+Academic Year was rejected with a friendly duplicate
        message; confirmed deletion was correctly blocked while status was
        Current with the new message text, then succeeded once set back to
        Waiting; loaded the semester's own detail page and confirmed all
        three buttons render with the currently-active one shown
        solid/disabled. **Regression-checked every other consumer of
        `get_current_semester()`** (now reading `status` instead of
        `is_current`) by loading `admin/dashboard.php`, `attendance.php`
        (with a real course), `reports.php`, and `notifications.php` as the
        same temporary admin — all 200 with zero PHP warnings/notices/
        fatals, confirming the `is_current`-stays-in-sync design actually
        avoided breaking any of these unmodified files. All temporary
        accounts and the test semester (+ its 12 sessions) were deleted
        afterward; `users` count confirmed back to the 85 baseline, and the
        real "Semester 9" was confirmed still `status = 'current'`
        throughout and afterward, untouched by any of this session's
        actions.

### semesters.php: Edit Semester
- [x] Added an Edit button, follow-up to the manual Create Semester feature
      above — a semester's Faculty/Semester name/Academic Year could
      previously only be set once, at creation, with no way to fix a typo
      or a wrong pick afterward short of deleting and recreating it (which
      would also lose its 12 Xiiso sessions and any dates already entered).
      - The "Create Semester" card now doubles as "Edit Semester", same
        toggle-by-GET-param convention already used by
        `admin/departments.php` (`?edit=1` on top of the existing
        `?semester_id=X` selection — no new id-carrying query param
        needed, since the page already has a role-scoped "selected
        semester" concept to pre-fill from). An "Edit" pencil-icon link
        was added to the detail panel's action bar, next to
        Start/End/Waiting/Generate Sessions.
      - New `update_semester` POST action, validated the same way as
        `create_semester` (valid Faculty/Academic Year, non-empty name,
        duplicate `(faculty_id, academic_year_id, name)` pre-checked with
        a friendly message) plus dean-ownership-checked like every other
        write action on this page.
      - **Deliberately restricted, not fully open**: changing Faculty or
        Academic Year on a semester that already has `course_offerings` or
        `students` pointing at it is blocked with a clear message (e.g.
        "Cannot change Faculty or Academic Year: this semester still has 1
        course offering. You can still rename it.") — both of those
        tables' own faculty-scoping is computed *elsewhere* from the
        semester's `faculty_id` at read time (a course's own department's
        faculty, a student's own faculty), so silently changing it out
        from under existing offerings/students would orphan them logically
        without anything visibly breaking until someone went looking.
        Renaming (keeping the same Faculty + Academic Year) is always
        allowed regardless of dependents — same "block the risky part,
        allow the safe part" shape as `delete_semester_row()`'s own
        blockers just above it in this file.
      - **Verified end-to-end via real HTTP requests** with a temporary
        `system_admin` account: confirmed `?semester_id=X&edit=1` renders
        the card pre-filled with that semester's real Faculty/Name/
        Academic Year and the "Update Semester"/"Cancel" buttons;
        renamed a test semester and confirmed the DB row updated;
        attempted to change its Academic Year after giving it a real
        `course_offerings` row and confirmed it was rejected with the
        exact blocker message and zero DB change, then confirmed a
        rename-only edit on that same still-has-a-dependent semester
        succeeded (proving the block is specific to Faculty/Year, not a
        blanket lock); confirmed renaming to collide with another existing
        semester's `(faculty, year, name)` was rejected and the form
        re-rendered in Edit mode with the attempted (rejected) name still
        showing, not silently reset. Also tested cross-role security with
        a temporary Dean account (scoped to Informatics) and a temporary
        second faculty: confirmed the Dean could edit their own faculty's
        semester (a crafted `faculty_id=999` in the POST was correctly
        ignored/forced back to their own faculty, matching `create_semester`'s
        existing lock), and confirmed a crafted edit against a semester
        belonging to the temporary other faculty was rejected outright with
        zero DB change (redirected to the plain semester list, not the
        target semester). All temporary semesters, the temporary faculty,
        the temporary course offering, and both temporary accounts were
        deleted afterward; `users` (85) and `faculties` (1) counts
        confirmed back to baseline, and the three real semesters
        (9/10/12) confirmed untouched throughout.
      - **Follow-up**: the Edit link had originally only been placed in the
        detail panel on the right (next to Start/End/Waiting/Generate
        Sessions), requiring a click into a semester before reaching it.
        Added a matching pencil-icon `<a>` link to each row of the "All
        Semesters" list on the left too, right next to the existing
        Delete trash-icon button (same `?semester_id=X&edit=1` link, same
        `.btn-icon` class already used for icon-links elsewhere in this
        app, e.g. `admin/course_offerings.php`'s "Manage Offerings" link) —
        so editing no longer requires opening the detail panel first.
        Start/End/Waiting were deliberately left out of the list rows and
        kept detail-panel-only: they sit naturally next to the Xiiso
        session list they affect, and cluttering every list row with three
        more buttons alongside Edit+Delete would work against the
        UI-simplicity concern already raised earlier in this project.
        Verified live with a temporary `system_admin` account: confirmed
        the Edit icon renders on all 3 real semester rows and correctly
        opens that exact semester in Edit mode pre-filled. Temporary
        account deleted afterward; `users` count confirmed back to 85.

### semesters.php: Manual Start Date Auto-Fills the 12 Xiiso Dates
- [x] Follow-up correction to the manual-status rewrite above: the user
      clarified that removing the Start Date field entirely had gone
      further than intended — they still want a hand-typed Start Date
      (not auto-suggested/chained from a previous semester, that part
      stays removed), used purely as a convenience to auto-fill the End
      Date and all 12 Xiiso dates at once, instead of typing all 12 one by
      one via the "Save Dates" table every time.
      - Restored `semester_end_date_from_start()` in
        `includes/semester_helpers.php` (deleted in the manual-status
        rewrite; `compute_session_dates()`/`generate_sessions_for_semester()`
        were never touched, so no other changes were needed there).
      - Added a "Start Date" field back to the Create/Edit Semester card,
        with a helper line explaining exactly what it does: "End Date and
        all 12 Xiiso dates are filled in automatically (3 months from this
        date) — you can still edit individual Xiiso dates afterward."
        **Required, not optional** — the user tried it as optional first,
        then explicitly asked for it to be made compulsory instead; the
        HTML `required` attribute and the server-side validation (`Please
        provide a start date.` when blank) were both updated together on
        both `create_semester` and `update_semester`, so a semester can no
        longer be created (or have its Faculty/Academic Year/Name edited)
        without one.
      - `create_semester`: when a Start Date is given, computes End Date
        and stores both on the new row before calling
        `generate_sessions_for_semester()` (which then auto-fills all 12
        Xiiso dates from them, exactly like the old auto-chained flow
        used to, just from a hand-typed date instead of a computed one).
      - `update_semester`: the same field, reused for **filling in gaps
        later**, not overwriting anything — submitting a Start Date on an
        already-existing semester recomputes End Date and calls
        `generate_sessions_for_semester()` again, which (per its own
        existing, unchanged `date IS NULL` guard) only fills whichever of
        the 12 Xiiso sessions are still empty; any session already dated
        — whether from an earlier auto-fill or typed in by hand — is never
        touched. Leaving the field blank on an edit leaves all existing
        dates alone entirely (blank does not mean "clear").
      - **Verified end-to-end via real HTTP requests** with a temporary
        `system_admin` account: created a semester with Start Date
        `2026-09-01` and confirmed End Date (`2026-11-30`) and all 12
        Xiiso dates were computed and stored correctly, evenly spaced
        across the 3 months exactly as `compute_session_dates()` always
        produced; created a second semester with no Start Date, manually
        set just Xiiso 3's own date by hand, then edited the semester
        adding Start Date `2026-01-01` and confirmed the other 11 sessions
        were auto-filled from it while Xiiso 3's hand-set date was left
        completely untouched — proving the "never overwrite an existing
        date" guarantee holds through the Edit path too, not just at
        creation. Both temporary semesters (and their sessions) and the
        temporary account were deleted afterward; `users` count confirmed
        back to the 85 baseline.
      - **Follow-up**: Start Date was built optional first (per the
        original phrasing of the request); the user then explicitly asked
        for it to be compulsory instead. Made it `required` on both the
        HTML input and both POST handlers (`create_semester` rejects with
        "Please provide a start date." when blank; `update_semester` the
        same). Verified live with a temporary `system_admin` account: a
        create request with no `start_date` was rejected with zero DB
        change, and the identical request with a valid `start_date`
        succeeded exactly as before. Temporary semester and account
        deleted afterward; `users` count confirmed back to 85.

### Faculty Total Semesters + Semester Dropdown on semesters.php
- [x] The "Semester" field on `semesters.php`'s Create/Edit card was a free
      text input ("e.g. Semester 1") — the user asked instead for a
      dropdown, populated per-faculty from how many semesters that
      faculty's whole program actually has, cascading the same way the
      Faculty→Department pickers already do elsewhere in the app.
      Investigated first via `AskUserQuestion` (three options: cap at the
      faculty's existing "Semesters Per Year" setting; a new field
      multiplied by an assumed program length; or a flat 1–12 for every
      faculty) — the user's real answer clarified the actual need: a *new*
      per-faculty field, set when registering/editing a Faculty, for "how
      many semesters this faculty will have in total" — distinct from the
      existing `semesters_per_year` (which only feeds the "Year N" display
      calculation and was never a total).
      - **Schema** (`migrations/2026_08_faculties_total_semesters.sql`,
        mirrored into `admas_attendance_schema.sql`): new
        `faculties.total_semesters TINYINT UNSIGNED NOT NULL DEFAULT 8`.
        A safety backfill raises it above the default for any faculty that
        already has a semester numbered higher than 8 — live data had
        Informatics at `semesters_per_year = 3` but an existing
        "Semester 9", so a flat default-8 rollout would have made that
        real semester's own name fall outside its own faculty's future
        dropdown range; the backfill (`MAX(digits extracted from that
        faculty's semester names)`, via `REGEXP_REPLACE`) correctly raised
        Informatics to `9`, confirmed against live data before proceeding.
        Took a `mysqldump` safety backup first, per this file's own
        established convention.
      - **`admin/faculties.php`**: added a required "Total Semesters"
        number input (1–30) to the Add/Edit Faculty modal, alongside the
        existing "Semesters per Year" field, plus a new "Total Semesters"
        column on the All Faculties table. **Shrink guard**: lowering an
        existing faculty's Total Semesters below the highest semester
        number it already has is blocked server-side ("Total Semesters
        can't be less than 9 — this faculty already has a semester
        numbered that high.") — same live `MAX(...)` check as the
        migration's own backfill, re-run on every update rather than only
        once at migration time, so this can never regress later either.
      - **`semesters.php`**: new `semester_name_options_for_faculty(int
        $totalSemesters): array` generates `["Semester 1", ...,
        "Semester {N}"]` fresh on every render (not stored), so raising a
        faculty's Total Semesters immediately unlocks more dropdown
        options with no further migration needed. The Semester field is
        now `<select name="name">`, cascaded from Faculty exactly like the
        existing Faculty→Department pattern elsewhere
        (`admin/students.php` etc.): disabled with a "Select faculty
        first" placeholder until a Faculty is chosen (system_admin/
        head_academic), or immediately populated for the Dean's own
        (locked) faculty. A new
        `semesterOptionsByFacultyId`/`admasUpdateSemesterNameOptions()` JS
        pair rebuilds the options client-side on Faculty change — no page
        reload, same convention as every other Faculty-cascaded dropdown
        in this app. **Server-side re-validates the submitted name is one
        of that faculty's actual valid options** on both `create_semester`
        and `update_semester` ("Please select which semester this is from
        the dropdown." if not) — the dropdown narrows the UI, but a
        crafted out-of-range value is still rejected regardless of what
        the client sent, same defense-in-depth convention used everywhere
        else in this file.
      - **Verified end-to-end via real HTTP requests** with temporary
        `system_admin` and `dean` (Informatics) accounts: confirmed
        `admin/faculties.php` renders the new field/column with no PHP
        warnings; confirmed `semesters.php`'s Semester select starts
        disabled with 0 options until a Faculty is picked, and the
        rendered `semesterOptionsByFacultyId` JS map correctly listed
        exactly 9 options for Informatics; created a real semester via
        "Semester 4" and confirmed it saved correctly; confirmed a crafted
        `name=Semester 999` POST was rejected with the exact expected
        message and zero DB row created; confirmed lowering Informatics'
        Total Semesters to 5 was blocked (value stayed 9 in the DB) while
        raising it to 12 succeeded; confirmed the Dean's own Semester
        dropdown rendered enabled (not locked/disabled, since their
        Faculty never changes) with the correct 9-option list, and that a
        crafted `faculty_id=999` in their create POST was still correctly
        forced back to their own faculty (existing lock, unaffected by
        this change) with the semester actually created under Informatics,
        not the crafted id. All temporary semesters/accounts were deleted
        afterward, Informatics' `total_semesters` was restored to `9`, and
        `users` count confirmed back to the 85 baseline.

### Data Fix: Semester 8 Lecturer Assignments + Placeholder Attendance (not a code change)
- [x] The user reported "Unassigned" lecturers on `student/courses.php` for
      most Information Technology courses. Investigation (direct SQL, no
      code read needed since the bug was in the data, not the logic)
      found the real cause: 5 courses' `course_offerings` rows (IT803,
      IT804, LA, SB, SD) were correctly assigned to real, active
      lecturers, but tied to `semester_id = 10` ("Semester 8", `status =
      'ended'`) — while `student/courses.php` defaults to whichever
      semester is `is_current` ("Semester 9"), so the lecturer lookup
      (scoped per-semester by design, on purpose — see the Course
      Offerings work earlier in this log) found nothing for Semester 9 and
      showed "Unassigned". 3 further courses (IS, IT801, IT802) had no
      lecturer recorded even under Semester 8 — confirmed via the user as
      intentional (deferred to a future semester's assignment), not a bug.
      **First attempt was a misread of the user's intent** — added 5 new
      `course_offerings` rows under Semester 9 (same lecturers, carried
      forward), which the user then clarified was *not* what they wanted:
      Semester 8 was always the intended/correct semester for these 5
      courses, and the real requirement was for students to be able to
      see Semester 8 (a semester they've already completed) in their own
      history — which those 5 courses already technically supported at
      the `course_offerings` level. The 5 mistaken Semester-9 rows were
      deleted immediately once this was clarified (`course_offerings`
      table backed up via `mysqldump` both before adding and before
      removing them).
      - **Real root cause of "students can't see Semester 8 at all"**:
        `student/courses.php`'s Semester dropdown is built from `attendance
        -> sessions -> semesters WHERE student_id = ?` — a semester only
        appears in a student's own history if they have actual attendance
        rows in it. Semester 8 had **zero** attendance records at all
        (confirmed via direct count), regardless of the correct
        `course_offerings` already existing — so no student could ever see
        it, independent of the lecturer-assignment question above.
      - **Resolution, per the user's explicit choice (they don't have the
        real historical attendance data on hand)**: generated
        **placeholder attendance** for Semester 8's 12 Xiiso sessions
        across the 5 courses, via a one-off script
        (`generate_semester8_placeholder_attendance.php`, scratchpad only
        — not part of the app/repo). Roster per course: `course_enrollments`
        first (IT803/IT804: 36 each; LA/SB: 77 each), department fallback
        to Information Technology's 77 active students when a course had
        zero enrollment rows (SD) — same discovery order
        `attendance.php`'s own roster resolution already uses. Each
        student got a real row per session (~88% present / 12% absent, an
        arbitrary but plausible-looking split — the schema only has these
        two statuses, confirmed live via `SHOW CREATE TABLE attendance`,
        not the four originally planned in this file's own early spec),
        `recorded_by_user_id` set to that course's actual Semester-8
        lecturer's own user account (not a generic admin id), `shift`
        taken from each student's own `students.shift`. 3,636 rows
        inserted total (432 + 432 + 924 + 924 + 924), `mysqldump` backup
        of the `attendance` table taken immediately before running it.
      - **This is explicitly placeholder/simulated data, not a real
        historical record** — flagged here so a future session doesn't
        mistake it for genuine attendance if this ever needs auditing or
        reconciling against a real paper record later.
      - **Verified via direct SQL replicating `student/courses.php`'s own
        query logic exactly** (not a full HTTP login, to avoid resetting a
        real student's password): confirmed a real enrolled student (id
        158, IT803) now has both "Semester 9" and "Semester 8" in their
        semester-history query, and that Semester 8 correctly resolves
        lecturer "abdukadir ali" with a real present/absent split
        (10/12 present) instead of "Unassigned" / no records.

### student/courses.php: Semester Box Picker (Faculty Total Semesters)
- [x] Replaced the plain Semester `<select>` dropdown on `student/courses.php`
      with a row of clickable "Semester N" boxes — one per semester number
      from 1 through the student's own faculty's `total_semesters` (the
      field added earlier this session), not just the semesters the
      student happened to already have attendance rows in. A semester
      number with no real `semesters` row yet for that faculty renders as
      a **disabled, greyed-out box** ("Not created yet" tooltip) instead
      of being silently omitted — the student can see the shape of their
      whole program (e.g. all 9 boxes for Informatics) even before every
      semester has been entered into the system.
      - Moved `semester_name_options_for_faculty()` out of `semesters.php`
        into the shared `includes/semester_helpers.php` (it's now used by
        two pages, not one) — `semesters.php` itself needed no other
        change since it already `require_once`s that file.
      - New logic in `student/courses.php`: resolves the student's own
        `faculties.total_semesters`, builds the full "Semester
        1".."Semester {N}" list via the shared helper, and matches each
        name against that faculty's real `semesters` rows (keyed by name)
        to find a real `semester_id` where one exists. Default selection
        prefers whichever created semester has `status = 'current'`;
        falls back to the highest-numbered *created* semester if none is
        current yet (e.g. a faculty that hasn't started its next semester)
        — replacing the old "most recent semester with attendance history"
        default, since a semester with real course_offerings but zero
        attendance yet (like Semester 8's original state, before the
        placeholder-data fix above) should still be reachable and
        selectable, not invisible.
      - Clicking a box navigates via a plain `?semester_id=X` link (no JS
        needed) — active box shown in solid sky-blue, others as outline
        buttons, unavailable ones as a disabled `<span>` at reduced
        opacity. The course list/lecturer/attendance-% logic underneath
        was not touched at all — it already keyed everything off
        `$filterSemesterId`, which this change only affects how the value
        is chosen from.
      - **Verified end-to-end via a real HTTP request** with a temporary
        student account in Informatics (department scoped, one course
        enrollment): confirmed all 9 boxes rendered for the faculty's
        `total_semesters = 9`, with Semesters 1–5 and 7 correctly shown as
        disabled ("not created yet" — no real semester row exists for
        those numbers in this faculty yet), Semester 6 enabled (a real,
        if unused, semester row), Semester 8 and Semester 9 both enabled,
        and Semester 9 pre-selected/highlighted by default (its own
        `status = 'current'`); clicked into Semester 8 via the box link
        and confirmed the course list correctly switched to show
        "abdukadir ali" as lecturer with "No records yet" (this temporary
        student had no attendance rows, unlike the real students covered
        by the earlier placeholder-attendance fix) — proving the
        semester_id resolution and course-fetch logic still work correctly
        end-to-end through the new picker. Zero PHP warnings/notices/
        fatals throughout. Temporary student, its user account, and its
        course enrollment were deleted afterward; no stray `temp_*`
        accounts remained (confirmed via a direct username search) — the
        `users` count settling at 86 instead of the prior 85 reflects a
        genuine new real registration between sessions, not test-data
        leakage.

### Data Fix + Real Bug: Courses Bleeding Across Semesters on student/courses.php
- [x] Follow-up correction: moved IS/IT801/IT802's `course_offerings` rows
      from Semester 8 to Semester 9 per the user's explicit clarification
      that all four courses shown in their screenshot (CL, IS, IT801,
      IT802) belong to Semester 9, not Semester 8 (`mysqldump` backup of
      `course_offerings` taken first). CL was already correct (had both a
      Semester 9 and a separate, genuine Semester 8 offering) and was left
      untouched.
      - This surfaced a **real bug**, not just a data question: after the
        move, those three courses still showed up under the *Semester 8*
        box with "Unassigned" / "No records yet" — because
        `course_enrollments` (what `student/courses.php`'s course-discovery
        step is keyed on) has **no `semester_id` column at all** — it only
        means "this student takes this course, ever," not "in this
        specific semester." Every semester box was therefore showing the
        student's *entire* enrolled course list, regardless of which
        semester was selected, with per-semester lecturer/attendance just
        layered on top — so a course with no real connection to the
        selected semester still showed up as a bare, empty-looking row.
      - **Fix**: added `AND (co.id IS NOT NULL OR a.id IS NOT NULL)` to the
        course query's `WHERE` clause — a course now only appears under a
        given semester if there's real evidence it belongs there: either a
        `course_offerings` row for that exact `(course, semester)` pair, or
        an actual `attendance` record. `$courseIds` (from
        `course_enrollments`/department fallback) is still the *outer*
        safety net deciding which courses are even candidates, but this
        inner condition is what decides whether a candidate actually
        belongs to the semester currently being viewed. Updated the
        empty-state message from "You are not enrolled in any courses
        yet." to "No courses recorded for this semester yet." — now a
        genuinely reachable state (a semester box with zero real course
        data for this student) rather than only meaning "never enrolled in
        anything."
      - **Verified end-to-end via real HTTP requests** with a temporary
        student (enrolled in CL, IS, IT801, IT802, IT803 via
        `course_enrollments`, same faculty/department as the real data):
        confirmed the Semester 8 box now correctly shows only CL and
        IT803 (the two with real Semester-8 offerings) — IS/IT801/IT802
        correctly gone; confirmed the Semester 9 box shows CL, IS, IT801,
        IT802 (their new home) but correctly does *not* show IT803 (which
        has no Semester-9 offering) — proving the fix is symmetric, not a
        one-off patch for Semester 8 specifically. Zero PHP warnings/
        notices/fatals. Cross-checked against the real student (id 158)
        via direct SQL replicating the exact query: Semester 8 now
        correctly returns only CL/IT803/IT804/LA/SB/SD, matching what the
        user's own screenshot should now show. Temporary student, user,
        and enrollment rows deleted afterward; `users` count confirmed
        back to the 86 baseline.

### student/xiiso_grid.php: Single-Row Layout (Name + 12 Xiiso + P/A/%)
- [x] Redesigned the student's own "My Xiiso Grid" page — previously the
      student's name/Present-Absent-% summary sat as separate badges in a
      card above a bare, name-less 12-column table. The user asked for one
      unified row: Full Name, all 12 Xiiso cells, and trailing P/A/%
      columns together — matching the exact visual language already used
      by the admin/lecturer-facing Xiiso grids (`reports.php`'s Xiiso
      Attendance Grid report, `attendance.php`'s Grid View), just scoped
      down to this one student's single row instead of a whole roster.
      - Reused the same `col-group-end`/`col-summary` CSS classes and the
        same column shape those other two views already established (sky-
        blue header fill + right border after Full Name, matching sky-tint
        fill + individual right borders after P and A, plain sky-tint on
        %) — no new CSS needed, this page just hadn't been brought in line
        with that pattern when it was originally built.
      - Header band row gained a leading blank `<th>` (for the Name column)
        and a trailing `colspan="3"` blank (for P/A/%), same shape as
        `reports.php`'s own Xiiso grid band row.
      - Removed the now-redundant Present/Absent/% badge row that used to
        sit in a separate card above the table — that data lives in the
        row itself now, so showing it twice would be clutter, not clarity.
      - **Verified end-to-end via a real HTTP request** with a temporary
        student (real course enrollment + 12 real attendance marks, 2
        deliberately set to Absent): confirmed the single body row
        rendered exactly 16 cells (Full Name + Student No sub-line, 12
        Xiiso Present/Absent badges with the 2 Absent ones landing on the
        correct sessions, then P=10/A=2/%=83.3% — matching the seeded data
        exactly) and the header rendered the matching band row + "Full
        Name"/12 sessions/"P"/"A"/"%" column row. Zero PHP warnings/
        notices/fatals. The PDF/Excel export code path (a separate,
        untouched `$exportColumns`/`$exportRows` block earlier in the
        file) was not touched by this change. Temporary student, user,
        enrollment, and attendance rows deleted afterward; `users` count
        confirmed back to the 86 baseline.

### Real Bug: student/dashboard.php Mixed Past-Semester Attendance Into "Current"
- [x] The user reported the student dashboard's "My Course Attendance"
      table showing Semester 8 courses (IT803/IT804/LA/SB/SD) instead of
      Semester 9 (the actual current semester). Root cause: the query
      filtered by `a.academic_year_id = ?` (this student's current
      semester's academic year) instead of by the semester itself — and
      **Semester 8 and Semester 9 share the same `academic_year_id`**
      (both "2023/2024" for Informatics), a direct, foreseeable
      consequence of the per-faculty status model from earlier this
      session (a faculty's semesters no longer auto-increment academic
      years the way the old date-driven engine implied). So "current
      academic year" was never a valid stand-in for "current semester" —
      it was accidentally correct before only because this specific data
      situation (two same-year semesters both having real attendance) had
      never come up until the Semester 8 placeholder-attendance fix
      earlier in this session created it.
      - **Fix**: query now joins through `sessions` and filters by
        `sess.semester_id = ?` (this student's own faculty's current
        semester id, from `get_current_semester()`) instead of
        `academic_year_id`. Renamed `$currentAcademicYearId` to
        `$currentSemesterId` throughout — confirmed via grep it was used
        in exactly this one query, no other reader to update. Old
        date-only attendance rows with `session_id IS NULL` (pre-dating
        the whole Semester/Xiiso system) are now correctly excluded from
        this "current semester" view via the inner join — expected, not a
        regression, since those rows can't be attributed to any specific
        semester at all.
      - **Verified end-to-end via a real HTTP request** with a temporary
        student seeded with attendance in *both* semesters at once
        (2 Absent marks under Semester 8's IT803, 3 Present marks under
        Semester 9's CL — deliberately reproducing the exact same-
        academic-year collision): confirmed the dashboard now shows only
        "CL — calculus, 3 present, 0 absent, 100.0%" — Semester 8's IT803
        row is correctly gone entirely, proving the fix. Zero PHP
        warnings/notices/fatals. Temporary student, user, and attendance
        rows deleted afterward; `users` count confirmed back to the 86
        baseline.

### Data Fix + Edit Semester Guard Narrowed to Faculty-Only
- [x] The user was on `admin/course_offerings.php` ("Manage Offerings") for
      IT802, trying to record it as a course taken back in "Semester 6" by
      students now in Semester 9 — and found the Academic Year field
      auto-showing **2024/2025** the moment Semester 6 was selected, which
      didn't match their own institutional knowledge (Semester 6 should be
      2023/2024, same as Semester 8/9). Confirmed via direct SQL this
      wasn't a display bug — `semesters.id = 12` ("Semester 6") really was
      stored with `academic_year_id` pointing at 2024/2025, while
      Semester 8 and 9 (both real, in-use) are 2023/2024. Root cause
      unknown (likely picked wrong at creation time, before this session's
      "Add Total Semesters" work existed to make the dropdown clearer) —
      not something worth root-causing further, since it's a one-row data
      mistake, not a systemic bug.
      - **Attempting the obvious fix (Edit Semester → change Academic
        Year to 2023/2024) hit a real self-inflicted blocker**: the Edit
        Semester guard added earlier this session (`semesters.php`'s
        `update_semester` handler) blocked *any* Faculty-or-Academic-Year
        change once a semester had dependents, and Semester 6 already has
        **41 real students** on `students.semester_id = 12`. Re-examined
        the original justification: the risk that motivated the guard
        (course_offerings' faculty match via courses' own department,
        students' own `faculty_id` — both computed elsewhere and blind to
        a semester's own `faculty_id` changing underneath them) is
        specific to the **Faculty** column. Academic Year is pure
        labeling — `semester_year_number()`'s "Year N" display and report
        filters read it, but nothing scopes `course_offerings`/
        `attendance`/`students` by it, and a student's own `faculty_id`
        lives on a completely different column than the semester's
        `academic_year_id`. **Narrowed the guard to only fire on a Faculty
        change** (`$facultyChanged` instead of `$facultyOrYearChanged`),
        so correcting an Academic Year on a semester that already has real
        course_offerings/students attached is now allowed — updated the
        rejection message to say "Cannot change Faculty" instead of
        "Cannot change Faculty or Academic Year" to match.
      - **Corrected the actual data**: used the real Edit Semester form
        (not raw SQL) to change Semester 6's Academic Year from 2024/2025
        to 2023/2024 — doubled as the live verification that the guard
        narrowing actually works. `mysqldump` backup of `semesters` taken
        first, per this file's established convention for schema/data
        changes.
      - **Verified end-to-end via a real HTTP request** with a temporary
        `system_admin` account: submitted the exact same update_semester
        request that would have been rejected before this fix (Semester 6,
        Faculty unchanged, Academic Year 2024/2025 → 2023/2024) and
        confirmed it now succeeds, the semester's `academic_year_id`
        correctly reads 2023/2024 afterward, and all 41 students' own
        `semester_id = 12` links are completely untouched (row count
        confirmed unchanged before/after). Temporary account deleted
        afterward; `users` count confirmed back to the 86 baseline. The
        user can now open Manage Offerings for IT802, pick Semester 6, and
        see the correct 2023/2024 Academic Year before recording the
        historical lecturer assignment.

### Multi-Shift Course Offerings (One Course, One Semester, Multiple Shifts/Lecturers)
- [x] The user confirmed a real, load-bearing requirement for the actual
      university: a course frequently runs on multiple shifts within one
      semester (e.g. Morning taught by Lecturer A, Afternoon by Lecturer B)
      — `course_offerings`' old `UNIQUE KEY (course_id, semester_id)`
      couldn't represent this; assigning a second lecturer to the same
      course+semester on a different shift silently overwrote the first
      via the existing upserts. This had been deliberately deferred in an
      earlier session (`migrations/2026_08_course_offerings_shift.sql`'s
      header comment explicitly named this as future work). Planned via
      **Plan Mode** (3 Explore-agent-equivalent research passes plus a
      dedicated Plan-agent validation pass auditing all 21 files
      referencing `course_offerings`) before writing any code, given the
      security-sensitive blast radius.
      - **Schema** (`migrations/2026_08_course_offerings_multi_shift.sql`,
        mirrored into `admas_attendance_schema.sql`): `shift` gained a 4th
        ENUM value `'any'` (meaning "applies to every shift") and became
        `NOT NULL DEFAULT 'any'` — every previously-`NULL` row backfilled
        to `'any'`. Unique key replaced:
        `uq_course_semester_shift (course_id, semester_id, shift)`.
        Chose a real sentinel value over keeping `shift` nullable
        specifically because MySQL/MariaDB never treat two `NULL`s as
        equal in a unique index — a nullable "wildcard" would need an
        app-level pre-check with a real TOCTOU race two concurrent saves
        could both pass; `'any'` closes this for free via the DB's own
        `ON DUPLICATE KEY UPDATE`. `mysqldump` backup taken first.
      - **`includes/attendance_helpers.php`**: new shared
        `OFFERING_SHIFT_LABELS` (4 values, including "Any/All Shifts").
        `get_offering_summary()` now takes an optional `?string $shift`
        and returns an **array** of offerings, not a single nullable row
        (a course+semester can now genuinely have several); when `$shift`
        is given, resolves to the single best match via
        `ORDER BY (co.shift = ?) DESC LIMIT 1` — **preferring an exact
        shift match over a coexisting `'any'` row**, a real edge case
        found during live testing (a leftover unassigned `'any'` offering
        from before a specific shift was added later). `render_offering_summary()`
        now renders one line per offering. `user_can_write_course_attendance()`
        gained a 5th `?string $shift` param — the lecturer branch's query
        becomes `... AND (shift = ? OR shift = 'any')` when a shift is
        given; `null` keeps the old unfiltered check (used only where no
        shift context exists yet, e.g. attendance.php's page-level
        UX-only `$canWriteAttendance` flag when "All Shifts" is selected).
      - **Security-critical fix — `ajax/save_attendance_cell.php`**: this
        was the actual enforcement gap — a lecturer assigned to only
        Morning could previously write Afternoon students' attendance too,
        since the write-check never considered shift at all. Reordered the
        student lookup (resolving the student's own real `shift`) to
        *before* the permission check, then passed that student's shift
        into `user_can_write_course_attendance()` — never the viewing
        lecturer's own filter selection, since the boundary must be "is
        this lecturer authorized for *this specific student's* shift."
      - **Writers** — `admin/course_offerings.php` ("Manage Offerings")
        and `lecturer_courses.php` ("Assign Courses"), the two pages whose
        upserts previously silently overwrote/stole another shift's
        lecturer: both gained a required Shift field (4 options, no blank)
        threaded into their `INSERT ... ON DUPLICATE KEY UPDATE` statements
        (trivial once the `'any'` sentinel design was in place — no new
        pre-check logic needed anywhere, the DB's own unique key handles
        it), a Shift column on their offerings tables, and (on
        `admin/course_offerings.php`) a JS-driven "already offered —
        editing lecturer" annotation keyed on the exact (semester, shift)
        pair instead of semester alone. `admin/courses.php`'s existing
        local 3-value `SHIFT_LABELS` const was removed entirely and
        replaced with the shared `OFFERING_SHIFT_LABELS` (adding the
        `attendance_helpers.php` require) so its "Add Course + Optional
        First Offering" shift field and `admin/courses_import.php`'s
        `IMPORT_SHIFT_LABELS` both automatically accept `'any'` as a 4th
        valid value with no other structural change — both already wrote
        brand-new `course_id` rows that could never collide anyway.
      - **`admin/courses.php`'s "Current Offering" column** — restructured
        from one flat `LEFT JOIN` into 3 separate queries (course list;
        every faculty's current semester(s); every matching
        `course_offerings` row), because the pre-existing `cur_se`
        resolution already had a **separate, pre-existing** fan-out risk
        (a faculty can have multiple concurrently-current semesters, a
        capability from an earlier session) that the new shift fan-out
        would have compounded — a naive 2-query fix would only have solved
        half of it. Renders one line per real offering under each current
        semester now (e.g. "Morning Shift: John Doe" / "Afternoon Shift:
        Jane Smith" / "Unassigned"), instead of a single fixed-column value.
      - **`student/courses.php`** — two real bugs fixed by the same
        change: (a) a student previously saw *every* shift's lecturer for
        a course, not just their own; (b) because `attendance` was joined
        independently of `course_offerings` with `GROUP BY` including
        lecturer name, N offering rows produced N result rows each
        carrying the student's *full* unscoped attendance count — genuine
        double-counting the moment a second shift-offering existed. Fixed
        by adding `shift` to the student's own `SELECT` and replacing the
        flat `co` JOIN with the same "resolve to one best match, prefer
        exact shift over `'any'`" correlated subquery used in
        `get_offering_summary()` above — discovered live during testing
        that a plain `(co.shift = ? OR co.shift = 'any')` JOIN condition
        (without the subquery) still fanned out into duplicate rows
        whenever a specific-shift offering coexisted with a leftover
        `'any'` row for the same course+semester, reproducing the exact
        bug being fixed; the subquery closes that gap completely.
      - **Verified end-to-end via real HTTP requests**, in two passes:
        **(1) Regression** — confirmed every touched page (`admin/dashboard.php`,
        `admin/courses.php`, `admin/course_offerings.php`, `attendance.php`,
        `reports.php`'s Xiiso grid, `lecturer_courses.php`) still renders
        real existing single-offering data correctly with zero PHP
        warnings/notices/fatals, and specifically confirmed "CL — calculus"
        (a real course with a genuine single Afternoon-shift offering)
        renders as exactly one row with the correct shift+lecturer, and a
        Morning-shift vs Afternoon-shift temporary student pair correctly
        saw/didn't-see that course under Semester 9 as expected.
        **(2) The real multi-shift scenario**, built via the actual UI (not
        raw SQL) with temporary accounts: created Morning (Lecturer A) and
        Afternoon (Lecturer B) offerings for one course+semester via
        `admin/course_offerings.php`'s real form, confirming both coexisted
        alongside a genuine leftover `'any'`-shift row from earlier in this
        session (a real, not contrived, edge case) with zero overwrites;
        confirmed via direct POST to `ajax/save_attendance_cell.php` that
        Lecturer A could mark a Morning student Present (200) but was
        rejected (403) marking an Afternoon student, and the exact reverse
        for Lecturer B; confirmed a Morning-shift student's `student/courses.php`
        showed exactly one row with Lecturer A's name and 100% (not
        doubled), an Afternoon-shift student showed exactly one row with
        Lecturer B; confirmed `admin/courses.php`'s Current Offering column
        showed all three offerings (Morning/Afternoon/Unassigned) under one
        course row, not three duplicate rows; confirmed `reports.php`'s
        Xiiso grid meta line listed all three lecturers comma-joined;
        confirmed `attendance.php`'s offering-summary line correctly showed
        only the relevant shift's lecturer when a Shift filter was applied.
        All temporary accounts/lecturers/students/offerings/attendance rows
        were deleted afterward, the pre-existing `'any'`-shift row on the
        test course was confirmed restored to its original state, and
        `users` count confirmed back to the 86 baseline.
      - **Noted for later (separate follow-up plan, not in this scope)**:
        multi-*faculty* course offerings (one catalog course taught under
        two different faculties' own semester tracks at once) still isn't
        representable — a course's offerings are still constrained to its
        own home department's faculty (`admin/course_offerings.php`'s
        semester lookup, `lecturer_courses.php`'s `role_may_edit_faculty()`,
        `ajax/save_attendance_cell.php`'s course-faculty-must-match-semester-
        faculty check). Multi-*semester* and multi-*academic-year*
        assignment for one course already worked before this session (a
        course can hold many `course_offerings` rows across any number of
        semesters/years) and needed no change. **Resolved below, see
        "Multi-Faculty Course Offerings."**

### Multi-Faculty Course Offerings
- [x] Resolved the "Noted for later" gap above: one catalog `courses` row
      can now be cross-listed into a DIFFERENT faculty's own semester
      track at once (e.g. a "common" course cataloged under Faculty A also
      taught, with its own lecturer/shift/dates/roster, inside Faculty B's
      current semester) — planned via Plan Mode (3 parallel Explore
      agents traced every place the single-faculty assumption was
      enforced, plus AskUserQuestion to confirm two design forks with the
      user before building) then a Plan agent-equivalent synthesis before
      any code was written.
      - **Schema** (`migrations/2026_08_course_offerings_roster_department.sql`,
        mirrored into `admas_attendance_schema.sql`): the single-faculty
        rule turned out to be 100% application logic — neither `courses`
        nor `course_offerings` has ever had a `faculty_id` column, so the
        only schema change needed was one new nullable
        `course_offerings.roster_department_id` (FK -> `departments`,
        `ON DELETE SET NULL`, same convention as
        `semesters.context_department_id`). NULL (every pre-existing
        offering) means "fall back to the course's own catalog
        department" exactly as before — zero behavior change for any
        existing offering. `mysqldump` backup taken first.
      - **Cross-listing entry point**: new
        `admin/course_offerings_search.php` (reachable via a new "+ Add
        Existing Course" button on `admin/courses.php`'s toolbar, visible
        to `system_admin` and `dean`) — read-only, university-wide course
        search (Code/Name/Home Department/Home Faculty/Credit Hours),
        with an "Add Offering" link per row into
        `admin/course_offerings.php?course_id=X`. Confirmed via
        `AskUserQuestion` with the user: **a Dean may browse every
        faculty's catalog** (read-only) specifically to cross-list a
        course into their own faculty — not restricted to System
        Administrator only, since a Dean otherwise has no query path
        anywhere in the app to even discover a course whose catalog home
        is a different faculty.
      - **`admin/course_offerings.php` widened**: the course lookup at
        the top no longer restricts a Dean to their own faculty's courses
        for *viewing* (write actions are what enforce the real boundary,
        below). System Admin's Semester dropdown now lists every
        faculty's semesters grouped by `<optgroup>`; a Dean's stayed
        exactly as it already was (`WHERE s.faculty_id = ?` bound to
        their own faculty) — which, once the course-lookup restriction
        was lifted, is precisely what makes cross-listing possible with
        zero query change needed there. New "Roster Department" field on
        the Add/Update form, JS-cascaded to whichever faculty the
        selected semester belongs to (`departmentsByFacultyId` map, same
        pattern as `admin/students.php`'s Faculty→Department cascade) —
        required whenever that faculty differs from the course's own
        catalog faculty (a guest offering), optional/defaults to NULL
        otherwise. The existing-offerings table now shows every faculty's
        offerings for the course (Faculty column + a "Guest" badge) —
        schedule-metadata-only, the same "cross-faculty visibility is
        fine, cross-faculty student/attendance data is not" precedent
        already established by `lecturer_courses.php`. Save/Delete now
        check "does the target semester belong to a faculty I'm allowed
        to write into" (System Admin: any; Dean: own faculty only, a
        strict generalization of the old rule) instead of "does the
        course's catalog department match my faculty" — Delete is also
        blocked server-side (not just hidden in the UI) for a Dean
        against another faculty's offering.
      - **Write-authorization** (`includes/attendance_helpers.php`):
        `user_can_write_course_attendance()`'s Dean branch now checks
        `course_offerings JOIN semesters WHERE ... se.faculty_id = ?`
        (does a real offering exist for this course in MY faculty)
        instead of the course's catalog department's faculty — correct
        for both home and guest offerings, and now consistent with the
        lecturer branch, which already worked this way. New
        `course_offering_exists()` helper (does a real offering exist for
        this course+semester, any shift) replaces every old
        `get_course_faculty_id()`-vs-`semesters.faculty_id` scalar
        mismatch guard across `ajax/save_attendance_cell.php`,
        `attendance.php`, and `reports.php` — those guards used to assume
        "the course's one faculty," which is no longer true.
        `get_course_faculty_id()` itself is unchanged and still correctly
        answers "what is this course's catalog home faculty" (used for
        CRUD ownership elsewhere). New `resolve_roster_department_id()`
        resolves a specific course+semester(+shift) offering's roster
        department (preferring an exact shift match over a coexisting
        `'any'`-shift row, same precedence as `get_offering_summary()`).
      - **Roster resolution** (`get_xiiso_grid_data()`): the
        department-fallback (used whenever `course_enrollments` has no
        rows) now resolves the specific offering's `roster_department_id`
        first via the new helper, falling back to the course's own
        catalog department only when that's NULL — unchanged for every
        pre-existing offering.
      - **Course pickers widened** — `attendance.php` (course->[faculty
        ids] JS map instead of one scalar, so the Semester dropdown
        cascade merges every faculty a course is actually offered in;
        Dean/Lecturer course lists gained an `EXISTS`/`JOIN` branch for
        offerings cross-listed in, not just catalog-owned), `reports.php`
        (same Dean widening on the Xiiso course list; the
        course+semester mismatch guard now checks
        `course_offering_exists()` instead of a faculty scalar compare),
        `lecturer/courses.php` + `lecturer/dashboard.php` (dropped the
        `se.faculty_id = d.faculty_id` constraint that was silently
        hiding a lecturer's own guest-faculty offerings from their own
        "My Courses"/dashboard; Faculty/Department columns now show the
        *offering's* own faculty and roster department, not the course's
        catalog home), `student/courses.php` (course discovery gained a
        third, additive source: any course with a `course_offerings` row
        whose `roster_department_id` matches the student's own
        department, regardless of the course's catalog home).
        `student/dashboard.php` needed no change — its "My Course
        Attendance" table is already keyed off real `attendance` rows for
        the student's own current semester, which was already
        faculty-agnostic.
      - **Display polish** (`admin/courses.php`): the course list's
        "Current Offering" column now resolves every faculty a course
        actually has a current offering in (not just its catalog home),
        with a "Guest: {faculty name}" badge on any cross-listed row.
      - **Deliberately out of scope** (confirmed via `AskUserQuestion`):
        building a manual `course_enrollments` management UI — the
        `roster_department_id` approach was chosen instead, since a
        guest offering's roster is realistically "this whole department's
        students," not a hand-picked list, and no `course_enrollments`
        UI has ever existed in this app to begin with (confirmed via
        grep: nothing writes to that table outside test fixtures). Also
        out of scope: extending this to `head_academic` — access stays
        `system_admin` + `dean`, matching this page's existing role set.
      - **Verified end-to-end via a background verification pass**
        against the live app (curl + direct SQL, this project's
        established convention) with temporary `system_admin`/`dean`/
        `lecturer`/student accounts and a disposable cross-listed test
        course spanning two temporary faculties (only one real faculty,
        Informatics, exists in this dev DB, so two throwaway faculties
        were created for this test alongside it): confirmed the search
        page finds any course system-wide; confirmed a save with no
        Roster Department is rejected for a genuine guest offering with
        zero DB change, and succeeds once one is supplied, for two
        different target faculties simultaneously; confirmed the Dean's
        Semester dropdown stays locked to their own faculty even for a
        foreign-catalog course, and that both a crafted cross-faculty
        save and delete are rejected server-side with zero DB change;
        confirmed `attendance.php`'s roster for the cross-listed course
        showed *only* the correct roster-department student (not the
        course's own catalog-department student, not the other faculty's
        roster student) and that a real AJAX save via
        `ajax/save_attendance_cell.php` succeeded and landed correctly;
        confirmed the roster-department student sees the course on their
        own `student/courses.php` with correct lecturer/attendance, and a
        student outside any roster department does not see it at all;
        confirmed a real pre-existing ordinary (non-cross-listed) course
        still renders correctly with zero PHP warnings/notices/fatals on
        `attendance.php`, `reports.php`'s Xiiso grid, and
        `admin/courses.php` (regression check). No bugs found. All
        temporary rows were deleted afterward by exact recorded ID; every
        table's row count confirmed back to the exact pre-test baseline
        (faculties 1, departments 1, semesters 3, courses 9,
        course_offerings 10, users 86, students 77, lecturers 7,
        attendance 3652, course_enrollments 370), and the real semesters'
        statuses were confirmed untouched throughout.

### Enroll Students (manual course_enrollments management UI)
- [x] While investigating a real user-reported issue ("why doesn't course OOB
      show up under student Abdifatah's — IT-1499/24 — 'Semester 6' view"),
      root-caused it via direct SQL, not assumption: `course_enrollments`
      (the table deciding "does this student take this course at all") had
      **no management UI anywhere in this app** — every existing page only
      reads it (counts, delete-blockers, roster/course discovery in
      `get_xiiso_grid_data()`/`student/courses.php`). Abdifatah already had a
      real, explicit 8-course enrollment list that simply didn't include
      OOB — not a bug, just no way to add the missing enrollment. The
      user's real need, once clarified: their whole real cohort
      ("Information Technology, Academic Year 2023/2024" — 36 real
      students, all Afternoon shift) took OOB back in a now-`ended`
      semester, and needed a **UI page** (not a one-off SQL script) to find
      that cohort and bulk-enroll them.
      - New `admin/course_enrollments.php?course_id=X`, entered the same
        way as `admin/course_offerings.php` ("Manage Offerings") — a new
        "Enroll Students" icon-link on `admin/courses.php`'s course rows,
        plus a cross-link on `admin/course_offerings.php`'s own header and
        on `admin/course_offerings_search.php`'s results (so a Dean
        cross-listing a foreign course into their own faculty can
        immediately enroll their own students into it too). Same role
        scope as the widened Manage Offerings page: `system_admin` (any
        course, any student) and `dean` (may reach *any* course, but may
        only browse/enroll/remove students from their **own faculty** —
        unlike Manage Offerings' schedule-metadata cross-faculty
        visibility, this is real student data, so a Dean never even *sees*
        another faculty's enrolled students here, let alone writes them).
      - **Student filter bar**: `admin/students.php`'s exact filter
        shape/query-building pattern reused verbatim — Academic Year,
        Faculty, Department (cascaded), Semester (cascaded), Shift, plus a
        name/student-no search box, all real SQL `WHERE` conditions via
        the same dynamic `$conditions`/`$params`/`$types` prepared-statement
        builder already proven there. Explicit on-page note: a student's
        **Semester** filter matches their *current* `students.semester_id`
        — since students progress over time, this is often the *wrong*
        tool for finding who took a course in a past semester (Abdifatah
        is now in Semester 9, not Semester 6, even though he studied under
        Semester 6 before); Faculty + Department + Academic Year (+ Shift)
        with Semester left blank is what actually finds a historical
        cohort — this exact confusion is what prompted the feature, so it's
        called out in the UI, not just this log.
      - **Results table**: matching students with a checkbox per row; a
        student already enrolled in this course shows an "Already
        Enrolled" badge with the checkbox disabled instead of re-offering
        it (via a `LEFT JOIN course_enrollments ce ON ce.student_id =
        s.id AND ce.course_id = ?` added to the students query).
      - **Bulk enroll**: new `assets/js/bulk_enroll.js` — same checkbox
        -> select-all -> hidden-form -> submit mechanic as the existing
        `assets/js/bulk_delete.js` (used across `admin/students.php` etc.),
        but not a literal reuse of that file since its click handler
        hardcodes delete-specific button/confirm wording; this is a
        parallel file with "Enroll Selected (N)" wording and a
        non-destructive confirm message. Server-side `bulk_enroll`
        handler re-verifies every submitted `student_id` actually belongs
        to the Dean's own faculty (defense in depth, never trusting the
        filter alone — same convention as every other write action in
        this app); inserts via `INSERT IGNORE INTO course_enrollments
        (student_id, course_id) VALUES (?, ?)` per id, relying on the
        existing `uq_student_course` unique key to silently skip
        already-enrolled duplicates with zero risk of a duplicate row;
        flashes a summary ("N enrolled. M already enrolled, skipped. K
        outside your faculty, skipped.").
      - **Currently Enrolled panel**: a compact table above the filter bar
        listing everyone currently enrolled (Student No/Full Name/
        Faculty/Department) with a per-row "Remove" button (`DELETE FROM
        course_enrollments WHERE student_id = ? AND course_id = ?`, same
        Dean-ownership re-check as the bulk action) — gives a way to
        review/undo, mirroring `admin/course_offerings.php`'s
        existing-list-plus-add-form shape.
      - **Explicitly not built**: no semester/offering linkage on the
        enrollment itself — `course_enrollments` has no semester column
        and this feature doesn't add one; whether an enrolled student
        actually *sees* the course under a given semester still depends
        on that semester having a real `course_offerings` row or
        `attendance` records, exactly as already documented in
        `student/courses.php`'s own header comment (both files now
        cross-reference this). No attendance-record entry here either —
        the user explicitly said not to fabricate attendance data; this
        page only manages the enrollment fact.
      - **Verified end-to-end against the live app and real data** (not a
        disposable fixture, since the actual motivating case was real):
        logged in as a temporary `system_admin`, filtered
        Faculty=Informatics/Department=Information Technology/Academic
        Year=2023/2024/Shift=Afternoon (Semester left blank) on the real
        `admin/course_enrollments.php?course_id=38` (OOB), confirmed
        exactly 36 real students matched (including Abdifatah, IT-1499/24)
        — extracted their ids from the actual rendered HTML rather than
        assuming, then submitted a real bulk-enroll POST and confirmed via
        direct SQL that `course_enrollments` now holds exactly those 36
        student ids for course 38 (byte-for-byte diffed against the
        extracted candidate list) with zero effect on any other course;
        confirmed re-submitting the same students correctly no-ops ("0
        enrolled, 3 already enrolled, skipped") with the count still
        exactly 36, no duplicates; confirmed the per-row Remove button
        removes (36 -> 35) and a subsequent re-enroll restores it (35 ->
        36); confirmed a temporary Dean (scoped to the real Informatics
        faculty) sees the identical 36-student Currently Enrolled list;
        confirmed a crafted `bulk_enroll` POST naming a student from a
        temporary foreign faculty was silently rejected (zero row
        created); confirmed a crafted `remove_enrollment` POST against a
        real (SQL-seeded) foreign-faculty enrollment row was rejected with
        the row left completely intact. All temporary accounts, the
        temporary foreign faculty/department/student, and the one
        SQL-seeded foreign enrollment row used purely to test the removal
        boundary were deleted afterward — the real 36-row OOB enrollment
        (the actual intended outcome, not test data) was deliberately left
        in place. `users`/`faculties`/`departments`/`students` counts
        confirmed back to their real pre-session baseline (87/1/1/77 — the
        87 vs. an earlier session's 86 baseline is a genuine real lecturer
        account, "Eng Ali Shiekh ahmed"/`eng8`, created earlier this same
        session as part of setting up OOB's real Semester 6 offering, not
        leftover test data).

### student/dashboard.php: My Course Attendance Now Shows Unmarked Current-Semester Courses
- [x] The user reported (via a real screenshot of a real student, Abdibasid
      Duale/HS-13049/23, Nursing/Health Science) that "My Course
      Attendance" showed nothing at all, despite the student having real
      current-semester courses. Investigated live, not assumed: confirmed
      this dashboard table's query only ever read `attendance` rows
      directly (`FROM attendance a JOIN courses c ... JOIN sessions sess
      ... WHERE a.student_id = ? AND sess.semester_id = ?`) — a course the
      student was enrolled in, or which had a cross-listed offering
      targeting their department (via `roster_department_id`, see the
      Multi-Faculty Course Offerings work), stayed completely invisible on
      this page until someone had actually marked at least one session's
      attendance. Not a bug — the query was internally correct — but a
      confusing empty dashboard for a real, current course load with
      nothing marked yet. User explicitly asked for the fix: show every
      current-semester course regardless of whether attendance exists yet.
      - Replaced the attendance-only query with the same three-source
        course-discovery logic `student/courses.php` already uses
        (`course_enrollments` first, department fallback second, a third
        additive source for a guest-faculty offering whose
        `roster_department_id` names this student's own department) —
        deliberately kept in sync with that file rather than reinvented,
        down to the identical shift-preference correlated subquery and
        "real evidence" `WHERE (co.id IS NOT NULL OR a.id IS NOT NULL)`
        condition, so the two pages can never drift on what counts as
        "this student's course, this semester."
      - A course can now render with zero marks ("No records yet", muted
        text — same convention already used on `student/courses.php`)
        instead of either being invisible or (the bug this required
        fixing alongside) counting as "below threshold": the "Courses
        Below Threshold" KPI loop now only judges a course against
        `min_attendance_pct` once it actually has `total_marks > 0` — with
        the old unconditional `$pct = ... : 0` fallback, every unmarked
        course would have silently and wrongly counted as 0% attendance
        the moment this query started returning them.
      - Empty-state message text updated from "No attendance records
        exist for you yet." to "No courses recorded for this semester
        yet." to match the new meaning (empty now means "no course
        connection to this semester at all," not "nothing marked yet").
      - **Verified end-to-end** without touching any real student's
        credentials: built a temporary student mirroring Abdibasid's exact
        real scenario (same Nursing department, Health Science faculty,
        Semester 8, Afternoon shift; enrolled in only the real LA course,
        matching his real enrollment) and confirmed the dashboard now
        shows both real Health Science offerings targeting Nursing — LA
        (enrolled) and MD/"Medical L surgical" (reachable only via the
        guest-offering roster-department path, no explicit enrollment) —
        both "No records yet," "My Attendance %" correctly "—" (null, not
        0%), "Courses Below Threshold" correctly 0. Regression-checked
        against a real student (id 184) with genuine existing attendance
        (CL: 1 present/1 absent) via a direct query simulation: confirmed
        that course's real present/absent counts are unchanged, while
        three more of his real current-semester courses with a live
        offering but zero marks yet (IS, IT801, IT802) now correctly
        appear as "No records yet" instead of being invisible. Temporary
        student/user rows deleted afterward; confirmed the 50 real Health
        Science/Nursing student rows the user had added between sessions
        (a genuine bulk import, not test data) were left untouched
        throughout.

### Attendance Scoring Overhaul: 0-100% Ratio -> Out-of-10 Present Count
- [x] Replaced the ratio-based attendance percentage
      (`ROUND(100 * SUM(present)/COUNT(*), 2)`, 0-100%) used everywhere in
      the app with the university's real grading rule: attendance is a 10%
      component of a course's grade, and each of the 10 *regular* Xiiso
      sessions (1-5, 7-11) is worth exactly 1 point when Present — so a
      student Present in 9 of 10 regular sessions shows **"9%"**, not 90%,
      and the score reflects real progress through the semester rather than
      a ratio (a student Present in all 3 sessions held so far shows "3%",
      not 100%). Midterm (Xiiso 6) and Final (Xiiso 12) are exams, not
      attendance sessions, and are fully excluded from scoring. Planned via
      Plan Mode (3 parallel Explore-agent research passes auditing every
      percentage calculation, every Xiiso-grid rendering site, and every
      `min_attendance_pct` consumer) plus `AskUserQuestion` to confirm four
      design forks before writing any code: exclude Midterm/Final entirely
      (not just cap them) with their grid boxes rendered grey/disabled;
      Late/Excused irrelevant since only `present`/`absent` exist in the
      schema; **full replace** of the old 0-100% display everywhere, not a
      side-by-side addition; and the three date-range summary reports
      (Course/Department/Faculty Summary) also convert to the new scheme,
      switching from a Date-From/Date-To filter to a Semester picker.
      - **Core formula** (`includes/attendance_helpers.php`): new
        `ATTENDANCE_MAX_SCORE = 10` constant.
        `attendance_badge_class()`'s yellow-band buffer scaled from 10 to 1
        point (same 10%-of-max proportion). The transformation, repeated at
        every site rather than centralized in one giant query helper (each
        site groups by a different dimension): join `attendance` to
        `sessions` filtered `type = 'regular'`, replace
        `ROUND(100*SUM(present)/COUNT(*),2)` with
        `LEAST(10, SUM(status='present'))` — a raw capped count, no more
        `/COUNT(*)` denominator. Legacy pre-Xiiso rows (`session_id IS
        NULL`) are excluded automatically by the join.
      - **Threshold**: new migration
        `migrations/2026_08_min_attendance_pct_scale.sql`
        (`UPDATE settings SET value = ROUND(value/10, 2) WHERE key =
        'min_attendance_pct'` — 75 -> 7.5), applied to the live dev DB after
        a `mysqldump` safety backup. `admin/settings.php` and
        `head_academic/academic_settings.php`'s validation range changed
        0-100 -> 0-10 (`step="0.1"`), with a help caption explaining the new
        scale.
      - **Midterm/Final become disabled, greyed-out boxes**: new
        `.grid-cell-exam`/`.col-exam` CSS (theme-aware, `assets/css/app.css`)
        applied on `attendance.php`'s Grid View, `reports.php`'s Xiiso Grid
        report, and `student/xiiso_grid.php` — cells render grey with a "—"
        glyph, `disabled`, and a tooltip; `assets/js/attendance_grid.js`'s
        local P/A/% recompute excludes `.grid-cell-exam` buttons.
        **Server-side enforcement** (the real boundary):
        `ajax/save_attendance_cell.php` rejects any save where the target
        session's `type !== 'regular'` with "Cannot mark attendance for
        Midterm/Final sessions" — verified live via a direct POST against
        both a Midterm and a Final session id, both rejected with zero DB
        change. `attendance_import.php` still assigns a missing date to a
        Midterm/Final slot if detected (informational) but never writes an
        attendance row for it, flagging skipped exam columns in the preview
        ("Xiiso 6 (Midterm) — not imported (exam session)").
      - **`reports.php`'s Course/Department/Faculty Summary**: converted
        from Date-From/Date-To to a role-scoped **Semester** picker (dean
        locked to their own faculty's semesters, same convention as every
        other Faculty-locked dropdown in this app), reusing the generic
        `<select>` auto-submit already provided by `assets/js/live_filter.js`.
        New shared `attendance_score_subquery()` derived-table pattern:
        per-(student, course) capped score, then `AVG()` rolled up to
        course/department/faculty — "average of capped scores," not a
        pooled ratio, matching the semantics used everywhere else this
        session. "Total Sessions" column became "Sessions Recorded (of
        10)". The dead `report_export_filename()` function and the
        now-unused Date From/To UI/parsing were removed entirely.
        `registration`'s enrollment-count variant of these two report types
        (no attendance access at all) is untouched and doesn't require a
        semester selection.
      - **Per-student/per-course/per-semester sites converted** (SQL
        aggregate sites gained the `sessions`-join + `type='regular'`
        filter + `LEAST(10, SUM(...))` formula; PHP-loop sites skip
        non-regular sessions and use `min(ATTENDANCE_MAX_SCORE,
        $presentCount)` instead of a division): `student/dashboard.php`
        (`myAttendancePct` redefined as the **average of each scored
        course's own out-of-10 score**, not a pooled ratio — a student
        taking several courses could otherwise exceed 10; same averaging
        semantics added to `student/attendance_history.php`'s new
        `semester_average_score()` helper for its per-semester "overall"
        figure), `student/courses.php`, `student/xiiso_grid.php`,
        `lecturer/dashboard.php`'s per-course chart (`lecturer/courses.php`
        was found to no longer display any percentage at all — a prior
        session's refactor already removed it — so it needed no change).
        Chart.js y-axis maxes changed from 100 to 10 everywhere a per-
        course/per-semester score is plotted (student and lecturer
        dashboards, dean's Attendance-by-Semester bar chart) — the day-
        level "Avg Attendance Today"/"Weekly Attendance Trend" widgets on
        admin/dean/head_academic dashboards were **deliberately left as
        plain 0-100% same-day present-rates**, a different metric with no
        "out of 10" interpretation.
      - **`notifications.php`**: converted alongside a **real scoping bug
        fix** discovered while touching this file — both the single-student
        re-verify query and the alerts list still filtered by
        `academic_year_id` (the exact bug already found and fixed in
        `student/dashboard.php` earlier this project, since two of a
        faculty's semesters can share one academic year); switched
        `$academicYearIdByFaculty` to `$semesterIdByFaculty` and both
        queries to join `sessions`/filter `semester_id` + `type='regular'`.
        Same scoping fix + new formula applied to `admin/dashboard.php`'s
        live-alerts fallback and Attendance-by-Department pie chart,
        `dean/dashboard.php`'s Departments table/Low-Attendance
        widget/Attendance-by-Semester chart, and
        `head_academic/dashboard.php`'s per-faculty loop (faculty avg,
        department chart, alerts) — all four now use the same
        `attendance_score_subquery()`-style derived table and
        `sess.semester_id` scoping instead of `academic_year_id`.
      - **Verified end-to-end via real HTTP requests** against the live app
        (temporary `system_admin`, `student`, `dean`, `head_academic`
        accounts, all deleted afterward) with a temporary student enrolled
        in a real current-semester course (36-student real roster):
        marked 9 of the 10 regular sessions Present and 1 Absent one cell
        at a time via the real AJAX endpoint, confirming the returned score
        climbed 1/2/3.../9 (never a ratio) and landed on exactly **9**, not
        90; confirmed the Grid View, `student/dashboard.php` ("9.0%"),
        `student/courses.php` ("9%", green badge), and `reports.php`'s
        Xiiso Grid report (P=9/A=1/%=9, last exam column blank/greyed) all
        agreed; confirmed two direct POSTs against the Midterm and Final
        session ids were both rejected with the exact expected message and
        zero DB rows created; confirmed `reports.php`'s Course Attendance
        Summary (now semester-scoped) rendered sensible blended real+test
        figures with no errors; downloaded and validated both a real
        `.xlsx` export (Course Attendance Summary) and a real `.pdf` export
        (Xiiso Grid) — both valid files; confirmed
        `admin/dashboard.php`, `admin/settings.php`, `notifications.php`,
        `attendance.php`, `reports.php` (all 4 report types),
        `attendance_import.php`, `dean/dashboard.php`,
        `head_academic/dashboard.php`, and
        `head_academic/academic_settings.php` all return 200 with zero PHP
        warnings/notices/fatals; confirmed `admin/settings.php` rejects a
        posted threshold of 15 (out of the new 0-10 range) with zero DB
        change. All temporary accounts, the temporary student, its
        enrollment, and its 10 attendance rows were deleted afterward;
        confirmed the real, pre-existing student (id 158) used to find the
        test course/semester — whose own genuine attendance included a
        real historical Midterm mark — was completely untouched throughout
        (its 7 attendance rows, including that Midterm row, byte-identical
        before and after).

### Forgot Password: Real Gmail SMTP Credentials Configured
- [x] The Forgot Password / Reset Password feature (built in an earlier
      session — "Login Redesign, Forgot Password (email), and Force
      Password Change") had been fully coded from day one but never
      actually delivered mail: `includes/mail_config.php` still held
      placeholder `SMTP_USERNAME`/`SMTP_PASSWORD` values, so every send
      attempt failed with `SMTP Error: Could not authenticate.` (confirmed
      via `error.log` — this exact failure had already been silently
      recurring on at least two earlier real-world attempts, 2026-07-09 and
      2026-08-03, before this session).
      - User walked through generating a real Gmail App Password (Google
        Account → Security → 2-Step Verification → App Passwords) for a
        real Gmail account (`maticn033@gmail.com`) and provided both values.
      - Updated `includes/mail_config.php` with the real
        `SMTP_USERNAME`/`SMTP_PASSWORD` (App Password, not the account's
        normal login password — Gmail rejects SMTP auth with the regular
        password once 2-Step Verification is on).
      - **Verified via a real, live email round-trip** (not just "no
        exception thrown"): created a temporary user
        (`temp_mailtest`, id 471) with `email = 'maticn033@gmail.com'`,
        submitted a real POST to `forgot_password.php`, confirmed
        `error.log` recorded **no** new `Mail send failed` entry for this
        attempt (every prior attempt, going back months, had logged one
        immediately), confirmed the generated code landed in
        `password_resets` (`323138`), and had the user directly confirm
        via their own Gmail inbox that the received email's code
        (`323138`) matched exactly. This is the strongest possible
        confirmation available without direct inbox access: a code that
        can only have come from this exact send now sitting in the user's
        real inbox.
      - Cleaned up afterward: deleted the temporary user's
        `password_resets` row and the `temp_mailtest` user itself
        (`users` id 471); confirmed zero rows remain for that username.
      - **Note for future sessions**: `includes/mail_config.php` now holds
        a real, working Gmail App Password directly in the file (per its
        own header comment's existing convention) — this is a real
        secret, not a placeholder; do not commit it to any public/shared
        repository, and do not overwrite it with placeholder values again
        without the user's explicit request.

### Bug Fix: Xiiso Attendance Grid Report Leaked Other Faculties' Semesters to Dean
- [x] User reported (with a screenshot of `reports.php`'s Xiiso Attendance
      Grid report as a real Dean scoped to Informatics) that the Semester
      dropdown listed semesters belonging to other faculties too, not just
      their own — despite the page's own "Access scope: Informatics Faculty
      only" banner.
      - **Root cause**: `$xiisoSemesters` (feeding that dropdown) was built
        as a deliberately unscoped, every-faculty query — correct in intent
        for `system_admin`/`head_academic`/`lecturer` (documented in its own
        comment: the Xiiso grid is a historical reporting surface, so those
        roles can pull a past semester's grid for any course), but the
        `dean`-only filter that the *adjacent* `$reportSemesters` variable
        (feeding the other 3 report types' Semester dropdown, just above
        this one in the file) already had was never applied here — a plain
        oversight, not an intentional cross-faculty allowance for Dean.
      - **Fix**: added the same `if ($role === 'dean') { array_filter(...) }`
        restriction already used on `$reportSemesters`, applied to
        `$xiisoSemesters` too. Confirmed this can't hide a legitimate
        cross-faculty-cross-listed course's own offering for a Dean: a
        Dean's write access into a cross-listed course is always through an
        offering under their *own* faculty's semester (per the Multi-Faculty
        Course Offerings work), so a Dean's real offerings always belong to
        a semester already inside this filtered list.
      - **Verified live** with a temporary Dean account scoped to
        Informatics (faculty id 3): confirmed the Semester dropdown on
        `reports.php?report_type=xiiso_grid` now lists exactly Informatics's
        5 semesters and nothing from any other faculty. Temporary account
        deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### reports.php: Shift Filter Added to Xiiso Attendance Grid Report
- [x] While reviewing the fix above, the user pointed out (with a screenshot
      of a real multi-shift course, "CL — calculus", showing "Afternoon
      Shift: Abdirahman Mohamed" in the breadcrumb) that the Xiiso Attendance
      Grid report's filter bar had no Shift control at all — confirmed via
      `git log`/`git show` this was never present in any prior commit either
      (not something removed this session), but a real, genuine gap once the
      Multi-Shift Course Offerings feature made it possible for one course to
      have different rosters/lecturers per shift within the same semester.
      `attendance.php`'s own Grid View already had this exact control; the
      Xiiso Grid *report* on `reports.php` never got the equivalent, even
      though `get_xiiso_grid_data()` (the shared helper both pages call) has
      accepted an optional `?string $shift` parameter since that feature
      shipped — `reports.php`'s own call site just never passed it.
      - Added the same `SHIFT_LABELS` (morning/afternoon/weekend) local
        constant and optional `xiiso_shift` GET param/dropdown already used
        by `attendance.php`, wired through
        `build_xiiso_grid_report($conn, $courseId, $semesterId, ?$shift)`
        (new 4th param, passed straight to `get_xiiso_grid_data()`) and into
        `get_offering_summary($conn, $courseId, $semesterId, ?$shift)` so the
        breadcrumb's "Lecturer" line also resolves to the shift-specific
        offering instead of always showing the "best match" summary line.
        Added "Shift: {label}" to `$reportMetaLine` (on-screen title +
        PDF/Excel exports) when a shift is selected, and `xiiso_shift` to
        `$currentQuery` so it round-trips through Export Excel/PDF links.
        JS `toggleReportFilters()` shows/hides the new dropdown the same way
        as the existing Xiiso-only fields.
      - **Verified live** with a temporary Dean account (Informatics): with
        no shift selected, the real "CL — calculus" course (36 enrolled
        students, all Afternoon shift) rendered all 36 rows and "Lecturer:
        Afternoon Shift: Abdirahman Mohamed"; selecting **Afternoon**
        rendered the identical 36 rows and same lecturer line; selecting
        **Morning** correctly rendered zero rows ("No data for the selected
        filters") and "Lecturer: Unassigned" (no Morning offering exists for
        this course) — confirming the shift filter, roster query, and
        offering-summary resolution all agree with each other. Temporary
        account deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### QR Code Login / Device Pairing (all 6 roles)
- [x] New WhatsApp-Web-style feature: from an already-logged-in Profile &
      Password page, a QR code lets a user "pair" their phone (scan + tap
      Confirm, no password re-entry — the fact that the phone can see a QR
      shown on an already-authenticated screen is itself the proof).
      Afterwards, from the Login page on any browser/device, a QR code is
      shown; scanning it with the already-paired phone logs that browser
      in automatically, with no username/password typed at all. Planned
      via Plan Mode (2 parallel Explore agents covering auth/session
      internals and profile-page/network config, then a Plan agent
      validating the design against real conventions) plus
      `AskUserQuestion` to confirm 4 design forks with the user before
      building: real-phone testing (not just curl simulation), a "Linked
      Devices" management/revoke UI, server-side PHP QR generation via a
      new composer package, and no re-login required on the phone during
      pairing (scan + tap is sufficient trust).
      - **Schema** (`migrations/2026_08_qr_device_pairing.sql`, mirrored
        into `admas_attendance_schema.sql` right after `password_resets`,
        `mysqldump` backup taken first): two new tables. `paired_devices`
        — long-lived record of a phone paired to a user account;
        `device_token_hash` is a **sha256 hash** of the raw 256-bit
        cookie secret (not plain — it's a 90-day bearer credential
        functionally equivalent to a password; a fast hash is correct
        here since the token itself is already unbrute-forceable, unlike
        a password needing bcrypt's slowness), `revoked_at` soft-deletes
        for audit. `qr_login_challenges` — short-lived (3-minute), single-
        use tokens for both the pairing and login flows; `challenge_token`
        stays **plain** (same convention as `password_resets.code`,
        since it's already visible on-screen in the QR image and is
        single-use); a 5-state `status` enum
        (`pending`/`confirmed`/`completed`/`expired`/`cancelled`) — the
        extra `completed` state beyond `confirmed` exists specifically so
        the *login* flow's desktop-side session-establishment step can
        never run twice on the same token (see replay-safety below).
      - **QR generation**: added `chillerlan/php-qrcode` (v6, GD-based,
        `ext-gd` already enabled from the PhpSpreadsheet work) as this
        project's 4th composer dependency, installed via the machine's
        newly-available global `composer` command (previous sessions had
        used a local `composer.phar`, which no longer exists on this
        machine — confirmed a real global `composer.exe` install exists
        now instead, used that). New `includes/qr_helpers.php`:
        `qr_render_png()` wraps the library's v6 API (`outputInterface =>
        QRGdImagePNG::class`, `outputBase64 => false` for raw bytes — the
        v6 API uses class-string output types, not the old v3-5 string
        constants my own initial plan assumed; corrected against the
        installed library's actual source once `composer require` landed
        it, exactly as flagged as a risk in the plan). New root-level
        `qr_image.php` streams the PNG for a `?token=...` — validated as
        a live, unexpired challenge row first (404 + a 1x1 blank PNG
        otherwise, no existence leaked); the QR *payload* is derived from
        `qr_absolute_url()` (new helper — `BASE_URL` in `includes/auth.php`
        is host-**relative**, useless inside a QR since the phone has no
        "current host" of its own; the absolute URL is built from the
        *current* request's own `$_SERVER['HTTP_HOST']`, so it always
        matches whichever host the desktop browser is actually using).
      - **Pairing flow**: new `ajax/qr_pair_start.php` (`require_login()`,
        any role) creates a `purpose='pair'` challenge tied to the
        current session's `user_id`; the Profile & Password page polls
        new `ajax/qr_pair_status.php` (GET, scoped to `token AND
        user_id = $_SESSION['user_id']` — prevents one logged-in user
        from polling another's pairing challenge) every 2s via a new
        `assets/js/qr_pair.js` (this app's **first** `setInterval` +
        reusable `fetch()`-polling file — confirmed via the research
        agents that no polling pattern existed anywhere in the codebase
        before this). The phone scans and lands on new root-level
        `qr_pair.php` (public, no session) — GET shows "Link this phone
        to `<name>`'s account?"; POST creates the `paired_devices` row
        (device label auto-summarized from the User-Agent via new
        `device_label_from_user_agent()` in `includes/device_helpers.php`,
        e.g. "iPhone · Safari"), issues a long-lived `admas_device_token`
        cookie on the **phone's** browser (`includes/device_helpers.php`'s
        `issue_device_token_cookie()` — 90 days, `httponly`, `samesite=Lax`,
        **`secure=false`** deliberately, since this app is plain HTTP/LAN-
        only and `secure=true` would silently break the cookie entirely,
        same exposure this app's own PHP session cookie already has), and
        atomically flips the challenge to `confirmed` inside a
        transaction with `SELECT ... FOR UPDATE` locking the row first.
      - **Login flow**: `login.php` gained a new "QR Code Scan" Bootstrap
        tab (the existing password form stays untouched in its own tab
        pane) — also needed adding the Bootstrap **JS** bundle to
        `login.php` for the first time (it previously only loaded
        Bootstrap's CSS; `data-bs-toggle="tab"` needs the JS bundle,
        confirmed via grep this was genuinely never loaded there before).
        New `ajax/qr_login_start.php` (**unauthenticated** — nobody is
        logged in on this browser yet) creates a `purpose='login'`
        challenge with `user_id=NULL`; `assets/js/qr_login.js` starts it
        lazily on first tab activation (not page load, so an ordinary
        password login never creates an unused row) and polls new
        `ajax/qr_login_status.php`. The phone (carrying its paired-device
        cookie) scans to new root-level `qr_login_confirm.php` (public) —
        resolves the phone's own paired device via
        `paired_device_from_cookie()` first (no cookie/unpaired ->
        "pair this phone first from Profile & Password", no fallback
        login form, exactly as specified); if paired, shows "Log in as
        `<owner name>` on this device?"; POST only flips the challenge to
        `confirmed` — **this page never touches `$_SESSION` itself**,
        since it's running in the *phone's* browser, not the desktop's.
      - **The actual session establishment is `ajax/qr_login_status.php`'s
        job**, and only for the poll request that wins an atomic
        `UPDATE ... SET status='completed' WHERE status='confirmed'` (an
        `affected_rows === 1` check) — re-fetches the user+role row fresh
        (same query shape `login.php` itself uses) and sets the exact
        same 5 `$_SESSION[...]` keys `login.php` sets, mirroring its own
        `$roleToDashboard`/`must_change_password` redirect logic exactly.
        Every other poll (a second tab, a refresh, a re-poll of an
        already-completed token) sees a non-`confirmed` status and gets
        back a generic "expired" response — no session write, no user
        data ever leaked before the `completed` transition specifically.
      - **Real bug found and fixed during verification, not caught by
        planning**: three files (`qr_image.php`,
        `ajax/qr_pair_status.php`, `ajax/qr_login_status.php`, and — found
        in a second pass after the first three were already fixed —
        `qr_login_confirm.php`'s own GET branch) originally compared
        expiry via PHP's `strtotime($row['expires_at']) < time()`. This
        looked correct in isolation but broke immediately in practice:
        this machine's PHP `date.timezone` is `Europe/Berlin` while
        MySQL's `NOW()` resolves through `SYSTEM` timezone to the OS's
        real Pacific time — so every freshly-created, genuinely-unexpired
        challenge row was misread as already-expired the instant it was
        created (confirmed live: `qr_image.php` 404'd immediately after a
        successful `qr_pair_start.php` call). Fixed by moving the expiry
        check into SQL itself (`(expires_at < NOW()) AS is_expired`, or a
        WHERE-clause `expires_at > NOW()` matching the pattern already
        used elsewhere in this file) everywhere a challenge's freshness is
        checked — MySQL comparing its own column against its own `NOW()`
        can never have a timezone mismatch, unlike a PHP-vs-MySQL
        comparison. `qr_pair.php`'s POST path and both `*_confirm`/`*_pair`
        pages' write paths were never affected, since their expiry checks
        were already SQL-side (`... AND expires_at > NOW()` in the
        `UPDATE`/`SELECT ... FOR UPDATE` itself) from the start — only the
        four PHP-side comparison sites had the bug.
      - **Verified end-to-end via real HTTP requests against the LAN IP**
        (`http://192.168.0.101/AttendancySystem/...`, not `localhost` —
        deliberately, to genuinely exercise `qr_absolute_url()`'s host
        derivation) with two temporary student accounts and separate curl
        cookie jars standing in for "desktop" vs "phone": full pairing
        round-trip (QR image is a real PNG, confirm page shows the correct
        account name, POST confirm creates the `paired_devices` row and
        issues the cookie, the owning session's poll immediately reflects
        `confirmed`); replay protection on both the pairing confirm POST
        (second attempt correctly rejected, zero double-row) and the login
        status poll (a second poll against an already-`completed` token
        gets a generic "expired" response, confirmed via direct DB read
        that no second session-establishing write occurred); cross-user
        scoping (`qrtest_student2` cannot poll `qrtest_student1`'s pairing
        token — "Invalid pairing code", not the real status); full login
        round-trip (phone confirms -> desktop's poll returns
        `status:"completed"` + the correct role redirect -> **directly
        confirmed the desktop cookie jar could then load
        `student/dashboard.php` with a 200 and the account's own name
        rendered, not a bounce to `login.php`** — i.e. a real session was
        genuinely established, not just a plausible-looking JSON
        response); device revoke (a different user's revoke attempt on
        someone else's device correctly rejected with zero DB change; the
        real owner's revoke succeeds; a second revoke attempt on an
        already-revoked device fails gracefully; and, the strongest
        check, the just-revoked phone's cookie was immediately tried
        against a brand-new login challenge and correctly rejected with
        "Phone Not Linked" — revocation takes effect immediately, not just
        cosmetically in the list); expiry (a force-expired challenge is
        correctly rejected across `qr_image.php`, `qr_pair.php` GET/POST,
        and the status-poll endpoint, all four checked individually after
        the timezone fix). All temporary accounts and every
        `paired_devices`/`qr_login_challenges` row created during testing
        were deleted afterward; both tables confirmed empty post-cleanup.
      - **Real-phone-on-WiFi verification — pending the user**, per their
        own explicit request to test with an actual phone rather than
        relying on curl simulation alone. Programmatic verification above
        already covers the exact same request sequence a phone's browser
        would make; the remaining check is purely "does the physical
        camera-scan-and-tap experience work," which needs a real device.
      - **Known operational note, not a bug**: the QR only encodes a URL
        the phone can reach if the desktop is browsing via its **LAN IP**
        (e.g. `192.168.0.101`), not `localhost` — `qr_absolute_url()`
        derives the host from the current request, so `localhost` in the
        browser produces a QR pointing at `localhost`, which the phone
        obviously can't resolve to this machine. This is inherent to
        running on a LAN-only dev server with no real domain, not
        something fixable in code — documented directly in the plan file
        and repeated here for future sessions.
      - **Also unverified in this session, flagged for whoever revisits**:
        whether Windows Firewall allows inbound TCP 80 from other devices
        on the LAN (a prerequisite for a phone to reach this machine at
        all) — the research phase confirmed Apache itself binds to all
        interfaces (`Listen 80` unqualified), but firewall rules are a
        separate, unverified layer.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

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

### Head of Academic Affairs: Course Management + User Management (§4 revision)
- [x] The user requested Course Management and User Management for Head of
      Academic Affairs, describing this role as the university's "second
      authority" managing everyone except top management. This directly
      contradicted the role's §4 definition as it stood (no Course
      Management at all — that was Dean/System Administrator only; no User
      Management — System Administrator only) and many Progress
      Log entries of this exact boundary being actively *protected* (e.g.
      the `head_academic/lecturers.php` split above, which deliberately
      kept this role to view+register only, not full CRUD). Flagged the
      conflict and got explicit confirmation before writing any code:
      Course Management = cross-faculty full CRUD (same view System
      Administrator gets, not Dean's faculty-scoped view); User Management
      = full, "like System Administrator." The user's own framing —
      "except top management" — was a live tension against the second
      answer (which literally listed admin accounts as in-scope when
      asked); resolved by excluding System Administrator accounts
      specifically rather than asking a third round of questions, since
      that's the more conservative, security-sound reading and matches
      what "except top management" was clearly gesturing at. Flagging this
      interpretation here rather than presenting it as directly
      user-confirmed.
      - **Course Management**: added `head_academic` to `require_role()` on
        `admin/courses.php`, `admin/course_offerings.php`, and
        `admin/course_offerings_search.php`. No other code changes needed —
        all three files already branch on `$role === 'dean'` for the
        faculty-scoped case with everything else (previously just
        `system_admin`) falling through to the unrestricted branch, so
        `head_academic` inherits the exact same cross-faculty view/write
        access as `system_admin` for free. Their scope-banners already use
        the same ternary, so no banner text was hardcoded to "System
        Administrator" anywhere in these three files (verified by grep
        before relying on it, not assumed). Deliberately did **not** touch
        `admin/courses_import.php` (bulk Excel import stays
        `system_admin`-only, matching that Dean doesn't get it either
        despite having full CRUD within their faculty — internal
        consistency: neither faculty-scoped nor cross-faculty "full CRUD"
        implies bulk import in this app) or `admin/course_enrollments.php`
        (its own header comment confirms this page manages real student
        enrollment records, not course/schedule metadata — extending it
        would functionally violate §4's *still-standing* "Cannot manage
        students" restriction, which the user's request never asked to
        change). Verified live as a real `head_academic` test account
        (`caaqil12345678`): `admin/courses.php` renders "Access scope: Full
        system — all faculties, departments, and courses" (the
        `system_admin`-equivalent banner, not Dean's faculty-scoped one);
        `admin/course_offerings_search.php` returns 200;
        `admin/course_enrollments.php` still redirects (302) — confirming
        the student-enrollment boundary is intentionally untouched.
      - **User Management**: new `head_academic/users.php` (disjoint-role,
        same-filename resolution as the existing
        `head_academic/lecturers.php` pattern — see `includes/nav_items.php`)
        rather than sharing `admin/users.php` outright, because that page's
        "System Users" table has no target-role restriction at all (any
        `system_admin` can reset/deactivate *another* `system_admin`) and
        its "Assign Role" panel appoints Dean/Head of Academic
        Affairs/Registration Office — both are System Administrator-only
        powers this role isn't granted. The new page: lists every
        Dean/Head of Academic Affairs/Registration Office/Lecturer/Student
        account university-wide (`WHERE r.name != 'system_admin'`) with
        Reset Password + Activate/Deactivate actions (self-protection
        intact, same as `admin/users.php`); no Assign Role panel at all.
        **Defense in depth**: a new `load_manageable_user()` helper
        re-checks every POST action's target server-side and rejects
        `system_admin` targets outright — not just hidden from the
        dropdown/list, since a user_id can always be forged in a raw
        request. No new account-creation path here; Lecturer/Student
        accounts are still created through their own respective modules,
        keeping account-creation logic in one place per account type.
        Verified live: `head_academic/users.php` renders "Access scope:
        All faculties — every account except System Administrator" and the
        rendered table contains zero System Administrator rows (confirmed
        the one string match on the page was the banner's own text, not a
        table row); a **direct POST with the real System Administrator's
        `user_id`** (bypassing the UI entirely) was rejected with "System
        Administrator accounts cannot be managed from here" and the
        account's `password_hash` was confirmed unchanged in the database;
        a legitimate `reset_password` and `toggle_status` against a real
        lecturer account both worked end-to-end (hash changed; status
        flipped inactive→active) and were reverted to their original
        values afterward; unauthenticated access redirects (302).
      - Updated §4 above and the in-app `ROLE_INFO` reference table on
        `admin/users.php` (a second, user-facing mirror of the same role
        descriptions, checked and found stale — would otherwise have kept
        telling System Administrators the old, narrower scope) to match.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Forgot/Reset Password: Branding + Three-Field Reset Screen
- [x] Two requests together: (1) confirm the Forgot Password flow actually
      delivers to Gmail, and (2) simplify `reset_password.php` down to
      exactly three fields (code, new password, confirm) with the
      university logo/name at the top of both pages — previously neither
      page had any branding, and `reset_password.php` asked for the
      account email a second time (already typed once on
      `forgot_password.php`).
      - **Branding**: both pages now require `includes/university_logo.php`
        (the same helper `login.php` already uses) and render a small
        centered circular logo + `settings.university_name` above their
        heading — a lighter-weight version of `login.php`'s brand panel,
        sized for these pages' simple single-card layout rather than the
        full split-screen login design.
      - **Three-field reset screen**: `forgot_password.php` now stores the
        submitted email in `$_SESSION['password_reset_email']` the moment
        step 1 is submitted (regardless of whether it matched a real
        account — same enumeration-safety reasoning as the identical
        success message). `reset_password.php` reads that session value
        instead of asking for the email again; the field is gone from the
        form entirely (not just hidden — the server already has it via
        session, so no hidden `<input>` was needed either). The original
        code comment's reasoning for needing the email (`password_resets
        .code` is only unique per-user, not globally, so the lookup needs
        both to identify which request is being redeemed) still holds —
        it's just no longer something the user types. Session value is
        cleared on a successful reset; landing on `reset_password.php`
        without ever going through step 1 (no session value) now shows
        "Please request a reset code first" instead of a broken form.
      - **Does it actually reach Gmail? Yes — with one honest caveat.**
        `includes/mail_config.php` already had real-looking Gmail SMTP
        credentials in place (not the placeholder text the file's own
        comment describes), so this wasn't a from-scratch build — it was
        verify-and-fix. First live test through the actual website
        failed: Apache's error log showed `SMTP Error: Could not connect
        to SMTP host.` A raw `fsockopen()` to `smtp.gmail.com` on both 587
        and 465 succeeded immediately, ruling out a network/firewall block
        at the OS level. A standalone PHPMailer script run via `php-cli`
        with `SMTPDebug = 3` immediately after **fully succeeded** — the
        complete SMTP transcript shows STARTTLS negotiated, AUTH LOGIN
        accepted (`235 2.7.0 Accepted`), and the message accepted for
        delivery (`250 2.0.0 OK`) — confirming the credentials and
        SMTP_HOST/PORT config are genuinely correct. Retried through the
        real website a second time immediately after: **succeeded**, no
        error logged. Conclusion: the send is **intermittent, not broken**
        — most likely an occasional connection hiccup between this
        specific machine and Gmail's SMTP servers (could be Windows
        Firewall treating `httpd.exe` differently from `php.exe` on a
        given attempt, antivirus real-time scanning momentarily
        intercepting the connection, or plain transient network flakiness)
        rather than anything wrong in this app's code. This is the same
        general class of "sometimes my local server acts up" the user
        described earlier this session about their laptop. Not something
        fixable from inside this codebase; worth watching for whether it
        recurs, and if so checking Windows Firewall's outbound rule for
        `httpd.exe` (Apache) specifically, or temporarily disabling
        antivirus real-time protection to test.
      - **Verified live end-to-end**, real Gmail address
        (`abdisamedmadoobe@gmail.com`, the real email on file for the
        `caaqil12345678` test account used throughout this session):
        submitted `forgot_password.php`, confirmed a real row landed in
        `password_resets`, completed `reset_password.php` with that code
        and the same known test password (`testpass123`, so the account's
        credentials stay exactly what they already were for this session's
        other tests) — got "Your password has been reset successfully.",
        then confirmed login with `testpass123` actually works afterward.
        Also confirmed the no-session guard on `reset_password.php` and
        that the rendered form has exactly the three requested fields (no
        Account Email field left).
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Forgot Password: Merged Into One Page, Identified by Role + Username (not typed email)
- [x] Immediate follow-up to the branding/three-field work above — the user
      looked at the result and asked for two more changes: (1) one single
      page instead of two, and (2) the email used to send the code should
      come from looking up the selected **Role + Username/Email** (the same
      identification pattern as `login.php`), not from the user typing
      their exact registered email into a box.
      - **Single page**: `forgot_password.php` now renders one of three
        states itself — phase `identify` (Role + Username/Email, the
        default), phase `code` (6-digit code + new password + confirm,
        entered once `$_SESSION['password_reset_pending']` is set), or the
        final success message — instead of being two separate files reached
        by navigating between them. `reset_password.php` is not deleted
        (a stale bookmark/browser-history hit shouldn't 404) but is now
        just a one-line `redirect_to('forgot_password.php')` — confirmed
        via `grep` that nothing else in the codebase links to it, so this
        was safe.
      - **Role + Username/Email instead of typed email**: phase `identify`
        now has the exact same two fields as `login.php` (`role` dropdown,
        identical 6 options/values/order; `username_or_email` text input,
        matching `WHERE u.username = ? OR u.email = ?`) instead of an
        "Account Email" box. On a match — role dropdown value equals the
        account's actual role AND the account has a non-empty email on
        file — that email is used automatically to send the code; the user
        never sees or types it anywhere in this flow.
      - **Enumeration-safety carried through the redesign, not just kept as
        a comment**: previously this was easy (always show the same success
        text regardless of match). A single page with a real phase
        transition is a sharper test of this property, so it was verified
        deliberately, not assumed: phase `identify` always advances to
        phase `code` — real match, wrong role for a real username, or a
        completely made-up username all take the exact same path and render
        identically. Only `$_SESSION['password_reset_user_id']` secretly
        distinguishes them (`null` for the two non-match cases), and phase
        `code`'s lookup treats a `null` session user id as an automatic
        "that code is invalid or has expired" — the *same* message a real
        account with a wrong/expired code would get. **Verified live**: a
        real account (`caaqil12345678` / `head_academic`) produced a real
        row in `password_resets` and a working end-to-end reset; a
        completely fake username and a real-username-wrong-role attempt
        both rendered the identical phase-`code` screen, produced **zero**
        new `password_resets` rows (confirmed by id — no gap), and both
        failed any code entry with the identical generic message.
      - Added a "Didn't get a code? Start over" link on phase `code`
        (`?restart=1`, clears the two session keys and redirects back to
        phase `identify`) — not explicitly requested, but a needed escape
        hatch given the *previous* entry in this log documented that the
        Gmail send is occasionally intermittent; without this, hitting that
        intermittency mid-flow would have been a dead end with no way back
        to phase `identify` short of clearing cookies.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Forgot Password Email: Embedded University Logo
- [x] User asked for two things: change the reset-code email's sender
      address, and put the university logo *inside the email itself* (not
      just on the two web pages, which was already done). Implemented the
      logo half now; the sender-address half is blocked on a real
      credential only the user has — see the question left open at the end
      of this entry.
      - Moved the `$settings`/`$universityName`/`$logoRelativePath`
        lookup that already existed (for the page's own branding) from
        after the POST-handling block to the top of the file, since the
        mail-sending code now needs it too and previously ran before that
        block populated it.
      - **Embedded, not a remote `<img src>`, and this isn't a style
        preference — a remote URL genuinely could not have worked**: the
        logo lives on this local XAMPP server, which Gmail's mail servers
        cannot reach over the internet to fetch a remote image from at
        all. `$mail->addEmbeddedImage($logoFilesystemPath, 'universitylogo')`
        attaches the actual image bytes to the email (`cid:` reference in
        the HTML body), which works regardless of where the recipient
        opens the email. Wrapped in `is_file()` so a missing/not-yet
        -uploaded logo degrades to no image rather than a PHPMailer
        exception. Email subject and body also switched from a hardcoded
        "ADMAS Attendance System" string to `$settings['university_name']`
        (matching what the two web pages already display), so a renamed
        institution stays consistent across the whole flow, not just the
        pages.
      - **Verified live, twice**: the real `forgot_password.php` flow
        (role=head_academic, `caaqil12345678`) produced a fresh
        `password_resets` row with no new Apache error logged (consistent
        with this session's established SMTP intermittency — this attempt
        landed on a "working" moment). To rule out the embedded image
        itself being a new failure mode independent of that intermittency,
        also ran a standalone `SMTPDebug`-enabled PHPMailer script with the
        same embedded-image code — full transcript shows the base64
        image data actually transmitted in the MIME body and a clean
        `250 2.0.0 OK` from Gmail.
      - **Left open, cannot proceed without the user's input**: changing
        the sender to `abdisamedmadoobe@gmail.com` needs that Gmail
        account's own **App Password** (Google Account → Security →
        2-Step Verification → App Passwords) — Gmail's SMTP rejects
        authenticating as one address using a different address's app
        password, so `SMTP_USERNAME` can't just be swapped in
        `includes/mail_config.php` without also swapping `SMTP_PASSWORD`
        to a real, matching one, or the currently-working send would
        break. Asked the user for it rather than guessing/fabricating a
        credential.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Head of Academic Affairs: Course Enrollments Added (full course-action scope)
- [x] Follow-up, same session: the user explicitly asked for the Courses
      section to be *full* CRUD — naming Offerings and Enrolled Students by
      name, "waa inuu dhammaan awoodo dhaqdhaqaaqa courses-ka" (must have
      every course-action capability in the project). This reopens the one
      piece deliberately left out of the original Course Management grant
      above: `admin/course_enrollments.php` was excluded at the time
      because its own header comment frames it as managing real student
      data, which reads as being in tension with §4's "Cannot manage
      students." Now explicitly requested by name, so implemented — but
      still reconciled against, not just overridden past, that restriction:
      **course-roster enrollment** (does student X appear on course Y's
      list) is a distinct action from **student profile management**
      (creating/editing a student's own record on `admin/students.php`,
      which stays untouched — this role still has no access there). §4
      updated to phrase the "cannot" line around student *profile* records
      specifically, not course actions generally.
      - Added `head_academic` to `admin/course_enrollments.php`'s
        `require_role()`. Same pattern as the three Course Management files
        already extended: the file branches on `$role === 'dean'` for the
        faculty-scoped read/write case, everything else (previously just
        `system_admin`) falls through unrestricted — `head_academic`
        inherits that for free, and its scope-banner's Dean-only caveat
        text is similarly ternary-gated, so no hardcoded "System
        Administrator" text needed changing. Confirmed the "Enroll
        Students" entry points on `admin/courses.php` (row icon-link) and
        `admin/course_offerings.php` (header button + inline link) are
        *not* role-gated in their own markup — both already render for any
        role that can reach the page at all, so no changes needed there
        either.
      - **Verified live** as `caaqil12345678`: `admin/course_enrollments.php
        ?course_id=23` (the real "CL — calculus" course from earlier in
        this log) returns 200 and renders normally; confirmed 19 "Enroll
        Students" links render on `admin/courses.php`'s course list. Did a
        full round-trip write test rather than just checking access: picked
        a real student not already on the calculus roster (id 194),
        `bulk_enroll`'d them (roster count 36 → 37, confirmed via a direct
        `course_enrollments` row check), then `remove_enrollment`'d them
        straight back out (37 → 36) — restoring the exact original roster,
        same "leave real data as found" discipline as the Assign Role test
        above.
      - Updated §4 above accordingly (see the reworded "cannot" clause).
        `admin/users.php`'s `ROLE_INFO` panel already says "cross-faculty
        Course Management (full CRUD...)" generically enough that it didn't
        need a further edit for this specific follow-up.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Head of Academic Affairs: Assign Role (Dean + Registration Office only)
- [x] Follow-up to the Course Management/User Management grant above — the
      user asked for Head of Academic Affairs to also be able to **appoint
      (magacaabid)** Registration Office and Dean, while explicitly
      reaffirming System Administrator as the project's one overall root
      authority ("Main-ka guud ee projectigeena, waa cida ugu awooda
      badan"). Read as: extend Assign Role to this role, but scoped to
      exactly those two target roles — not System Administrator, and not
      (more) Head of Academic Affairs accounts, since neither was named and
      the user's own framing keeps System Administrator singular at the
      top.
      - Added a new `HEAD_ACADEMIC_APPOINTABLE_ROLES = ['dean',
        'registration']` constant and a full `assign_role` POST handler to
        `head_academic/users.php`, copied from `admin/users.php`'s
        `assign_role` action and adapted: same validation order, same
        transaction (release any existing Dean on the target faculty →
        insert-or-update the user → sync `faculties.dean_user_id` →
        `role_assignments` audit row insert with `assigned_by` = the
        acting Head of Academic Affairs user), same "+ Create New User"
        path via `generate_admin_username()`/`generate_temp_password()`
        (both already available via the `lecturer_accounts.php` require
        this file already had). The existing System Administrator
        exclusion (`load_manageable_user()`, `$systemAdminRoleId` check)
        now also guards the "Select User" re-appoint path in
        `assign_role`, not just `reset_password`/`toggle_status` — a
        System Administrator account can't be re-appointed away from this
        page either.
      - Deliberately did **not** just widen `admin/users.php`'s own
        `APPOINTABLE_ROLES` constant and share that page: it appoints from
        a dropdown of *all* non-elevated users but its "Select User" and
        "System Users" table both have no target-role restriction at all,
        so sharing the door would have handed this role the same
        System-Administrator-reach that the whole point of this change was
        to keep excluded. Kept the two-forms-one-page layout ("Assign
        Role" card above "System Users" card) matching `admin/users.php`'s
        own layout, rather than the col-lg-8/col-lg-4 split used on
        `head_academic/lecturers.php` — this page is a closer structural
        match to `admin/users.php` than to the Lecturers page.
      - **Verified live** as the real `head_academic` test account
        (`caaqil12345678`): before touching anything, checked
        `faculties.dean_user_id` for every faculty and found Informatics
        already had a real Dean (`madoobe jama abduulaahi`) — used
        **Business Administration** (no Dean assigned) for the live test
        instead, to avoid displacing real data. Appointed an existing
        lecturer (`eng maax`, id 211) as Dean of Business Administration —
        confirmed `role_id`/`faculty_id` updated and
        `faculties.dean_user_id` synced; created a brand-new Registration
        Office account via "+ Create New User" — confirmed the generated
        username/temp-password flash message and the new row in `users`.
        **Bypass attempts**: POSTing `appoint_as=head_academic` and
        `appoint_as=system_admin` directly (bypassing the dropdown, which
        only ever renders Dean/Registration Office options) were both
        rejected with "Please choose a valid role to appoint." and
        confirmed no account was created for either. **Cleanup**: reverted
        `eng maax` back to `role_id` = lecturer with `faculty_id` = NULL,
        cleared `faculties.dean_user_id` for Business Administration back
        to NULL, and deleted the test Registration Office user row plus
        both `role_assignments` rows created during this test — same
        "temporary account deleted afterward" convention already
        established earlier in this log, so the real dataset (197
        students, existing real Dean assignments) was left exactly as
        found.
      - Updated §4 above and `admin/users.php`'s `ROLE_INFO` reference
        table again to match.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Role Rename: System Administrator → University Rector, + University-Wide Dashboard Oversight Charts
- [x] **Full rename of the `system_admin` role to `university_rector`** — both
      the underlying DB/code identifier and every user-facing "System
      Administrator" label, requested so the role reads as "Madaxweynaha
      Jaamacadda" (University Rector) throughout the app. This was a full
      rename, not a display-label overlay: `roles.name` itself changed, and
      every `require_role([...])`/`$role === 'system_admin'` comparison in
      code changed with it, since `current_role()`/`$_SESSION['role']` is a
      plain string pulled straight from `roles.name` — a partial rename
      would have locked the role out immediately.
      - **DB migration**: `mysqldump` safety backup taken first (to
        `C:\xampp1\backups\admas_attendance\`, same convention as every
        prior schema/data change in this log). Then a single statement:
        `UPDATE roles SET name = 'university_rector' WHERE name =
        'system_admin';` — confirmed exactly 1 row affected, confirmed via
        `SELECT * FROM roles` that all 6 rows are intact with no
        duplicate name. `users.role_id`/`role_assignments.role_id` are
        plain numeric FKs into this lookup table (not an ENUM), so no
        further data migration was needed anywhere else — only the PHP
        code doing string comparisons against the role's *name* needed
        updating.
      - **Files changed** (literal `system_admin` → `university_rector` in
        every functional context — `require_role()` arrays, `===`/`!==`
        comparisons, array keys, `<option value="...">`, JS role strings —
        plus "System Administrator" → "University Rector" in every
        user-facing string and doc-comment): `login.php`,
        `includes/topbar.php`, `includes/nav_items.php` (both the
        `nav_items()` role arrays and the `role_folder()`/`role_label()`
        mapping tables — the folder mapping's *value* stayed `'admin'`,
        only the *key* changed, so the physical `admin/` directory was
        never touched), `includes/factory_reset.php`, `includes/auth.php`,
        `includes/attendance_helpers.php`, `includes/university_logo.php`,
        `head_academic/users.php`, `head_academic/lecturers.php`,
        `head_academic/academic_settings.php`, `ajax/save_attendance_cell.php`,
        `ajax/qr_login_status.php`, `admin/users.php` (including the
        `ROLE_INFO` reference panel's own role/description text),
        `admin/students_import.php`, `admin/students.php`,
        `admin/settings.php`, `admin/profile.php`,
        `admin/lecturers_import.php`, `admin/lecturers.php`,
        `admin/faculties.php`, `admin/departments_import.php`,
        `admin/departments.php`, `admin/dashboard.php`,
        `admin/course_offerings_search.php`, `admin/course_offerings.php`,
        `admin/course_enrollments.php`, `admin/courses_import.php`,
        `admin/courses.php`, `admin/academic_years.php`, `semesters.php`,
        `reports.php`, `notifications.php`, `lecturer_courses.php`,
        `forgot_password.php`, `attendance_import.php`, `attendance.php`,
        `student/notifications.php`, `admas_attendance_schema.sql` (the
        role seed row, the default-admin seed `INSERT`'s subselect, and
        two SQL comments). Grepped `migrations/*.sql` and every
        `assets/js/*.js` file (including `qr_pair.js`/`qr_login.js`) —
        neither contained the literal string, so neither needed changes.
      - **CLAUDE.md itself**: updated §4's live RBAC table (first row's
        role name + every "System Administrator" reference inside the
        Head of Academic Affairs row's "cannot do" column + the paragraph
        right below the table describing who appoints Dean/Head of
        Academic Affairs/Registration Office) and §6's schema sketch
        comment listing the six role names — both are current-state
        specification text, not historical narrative. Every entry inside
        this Progress Log (§10) describing *past* sessions was
        deliberately left exactly as written, including every
        "system_admin"/"System Administrator" occurrence in that
        historical text — those are a dated record of what was true at
        the time each session ran, not a living reference.
      - **Verified via repo-wide grep after all edits**: zero `.php`/`.sql`
        files anywhere in the project (this Progress Log's own historical
        text in CLAUDE.md excepted) still contain the literal string
        `system_admin` or the phrase "System Administrator".
      - **Verified end-to-end via real HTTP requests** against the live
        app: created one temporary `university_rector`-role test account
        directly via SQL (`temp_rector_qa`, password hashed with PHP's own
        `password_hash()`); confirmed `login.php`'s Role dropdown now
        renders `<option value="university_rector">University Rector</option>`;
        logged in via curl with that role selected and confirmed a `302`
        redirect straight to `admin/dashboard.php` (first attempt with a
        hand-typed bcrypt hash actually failed — PowerShell's `-e "..."`
        argument was silently mangling the hash's own `$`-prefixed
        segments as shell variable interpolation; fixed by writing the
        `UPDATE` as a `.sql` file and piping it into `mysql` instead of
        passing the hash inline on the command line); confirmed
        `admin/dashboard.php` returns `200` with zero PHP
        warnings/notices/fatals in the raw HTML, and that "University
        Rector" (not "System Administrator") renders in the topbar role
        display; regression-checked `admin/students.php`,
        `admin/settings.php`, `reports.php`, `admin/faculties.php`, and
        `admin/users.php` all still return `200` for this account (not a
        bounce to `unauthorized.php`); confirmed a University Rector is
        still correctly blocked from a Dean-only page
        (`dean/dashboard.php` → `302` to `unauthorized.php`, unchanged
        behavior, not a regression from the rename). Deleted the
        temporary account afterward and confirmed `users` back to its
        exact pre-test baseline (64) and `roles` still showing exactly 6
        rows with `university_rector` in place of the old
        `system_admin` row.
- [x] **University-wide oversight charts added to `admin/dashboard.php`**,
      per the Rector's own description of the role as "kormeeye"
      (overseer/supervisor) wanting a comprehensive visual picture of the
      whole university, not new CRUD pages (this role already has full
      read/write access to every faculty's data via the existing pages —
      this was specifically about enriching the dashboard's own visual
      overview). Reused the page's existing Chart.js include and
      `.admas-card` styling rather than introducing any new visual
      language, and read chart colors from the same CSS custom properties
      (`--admas-sky`, `--admas-text-muted`, `--admas-border`,
      `--admas-surface`) the page's two pre-existing charts (Weekly
      Attendance, Attendance by Department) already used, so all 6 charts
      stay theme-aware in both light and dark mode with no new
      light-mode-only hex values introduced.
      - **Attendance by Faculty** (bar, 0–10 scale) — one score per
        faculty, each resolved against that faculty's own current
        semester via the existing `get_current_semester()` helper (never
        a single shared "current" value) — same per-faculty loop-and-merge
        pattern already used by this same file's pre-existing Attendance
        by Department chart and by `head_academic/dashboard.php`'s own
        Attendance-by-Faculty section, reused rather than reinvented.
        Average of each (student, course) pair's own capped out-of-10
        score, counting only *regular* Xiiso sessions (Midterm/Final
        excluded), matching the exact scoring semantics established
        earlier in this log under "Attendance Scoring Overhaul."
      - **Students per Faculty** (doughnut) — a plain `COUNT` of active
        students grouped by faculty via a `LEFT JOIN` (so a faculty with
        zero students still renders as a real 0 slice rather than being
        silently dropped).
      - **Lecturer Workload (Current Semester)** (horizontal bar, top 8) —
        `COUNT(*)` of `course_offerings` per lecturer, joined to
        `semesters` filtered to `status = 'current'` only, so it reflects
        who is actually teaching the most *right now*, not a lifetime
        historical count.
      - **Student Registrations (Last 6 Months)** (line) — `COUNT(*)` of
        `students` grouped by `DATE_FORMAT(created_at, '%Y-%m')`, the last
        6 calendar months always rendered (including zero-count months)
        so the trend line's shape is honest rather than only showing
        months that happen to have data.
      - Confirmed no new DB schema/migration was needed — all four charts
        query only existing tables/columns.
      - **Verified end-to-end via the same live HTTP session used for the
        role-rename verification above**: confirmed all 6 `<canvas>`
        elements (2 pre-existing + 4 new) render in the fetched HTML with
        zero PHP warnings/notices/fatals; confirmed each new chart's
        PHP-computed `labels`/`data` JS arrays were genuinely non-empty
        against this dev DB's real data (e.g. Attendance by Faculty showed
        3 real faculties with real scores, Lecturer Workload showed 2 real
        lecturers with real current-offering counts, the registration
        trend showed 6 real month labels from `Mar 2026` through
        `Aug 2026` with real counts) rather than only checking that the
        markup was present. The temporary test account used for this
        verification was the same one created and cleaned up for the
        role-rename task above — no separate account was needed.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### University Rector: Full CRUD Converted to View-Only (Supervisory/Oversight Role)
- [x] Following the earlier `system_admin` -> `university_rector` rename,
      the user decided this role should become supervisory/oversight only:
      full VIEW access everywhere, but no create/edit/delete/import/bulk
      actions on day-to-day academic-data pages — **except** User Management
      (`admin/users.php`) and Settings (`admin/settings.php`), which stay
      full CRUD, since University Rector remains the project's top
      account/system-administration authority, just not for editing
      academic data itself. `reports.php`, `notifications.php`, and
      `admin/dashboard.php` were also explicitly left untouched (already
      read-only/reporting surfaces for this role, nothing to convert).
      - **Pattern applied per page**: a `$isReadOnly = ($role ===
        'university_rector');` flag (or, on the two rector-only pages —
        `admin/faculties.php`/`admin/academic_years.php` — the equivalent
        `current_role() === 'university_rector'` check, since no other role
        reaches those files at all) gates every write-UI element (Add/
        Edit/Delete/Reset Password buttons, bulk-select checkboxes +
        "Delete Selected" bars, Import from Excel links, the whole
        Add/Edit side-panel form) without touching the underlying data
        list/table — the list/table itself, and any other role's own
        branch (`dean`, `head_academic`, `registration`), were read but
        never edited beyond this one flag's insertion. Server-side, a
        single guard was added at the very top of each file's POST
        dispatch (`if ($isReadOnly) { flash error; redirect_to(...); }`,
        before the `switch`/`if-elseif` chain reads `$action`) rather than
        repeating the check in every branch — a raw crafted POST is
        rejected identically to a UI-hidden one. Scope banners changed to
        "Access scope: Full system — view only (oversight)" (or, on
        `admin/students.php`/`admin/course_enrollments.php`, an
        `elseif ($isReadOnly)` branch alongside the pre-existing
        `dean`/`registration` branches) for this role only.
      - **`admin/students.php`** (+ `admin/students_import.php`): Add
        Student panel, Import from Excel, bulk-delete bar/checkboxes, and
        per-row Edit/Reset Password/Delete all hidden; a new "View"
        eye-icon link (`admin/student_view.php?student_id=X`) replaces
        them on this role's rows only — other roles' rows unchanged.
        `admin/students_import.php`'s `require_role()` dropped
        `university_rector` entirely (was `['university_rector',
        'registration']`, now `['registration']` — a pure bulk-write page
        has no meaningful view mode).
      - **`admin/lecturers.php`** (+ `admin/lecturers_import.php`): Add
        Lecturer panel, Import from Excel, bulk-delete, and per-row
        Assign Courses/Edit/Reset Password/Delete all hidden; a "View"
        eye-icon link (`admin/lecturer_view.php?lecturer_id=X`) added for
        this role's rows. `admin/lecturers_import.php`'s `require_role()`
        changed from `['university_rector']` to `[]` (this page never had
        any other role granted access — removing the only allowed role
        denies everyone, which is the intended "no import access at all
        anymore for this role, and no one else ever had it" outcome;
        `require_role([])` cleanly 403s/redirects via the existing
        `in_array($currentRole, $allowedRoles, true)` check with no special
        casing needed).
      - **`admin/courses.php`** (+ `admin/courses_import.php`,
        `admin/course_offerings.php`, `admin/course_offerings_search.php`,
        `admin/course_enrollments.php`): Add Course panel, Import from
        Excel, "Add Existing Course" (cross-listing entry point), bulk-
        delete, and per-row Edit/Delete hidden on `admin/courses.php`;
        "Manage Offerings"/"Enroll" row links kept but relabeled ("View
        Offerings"/"Enrolled") since their target pages are now
        themselves read-only for this role, not full write pages anymore.
        `admin/course_offerings.php`: the whole "Add / Update Offering"
        form and every row's Remove button hidden (replaced with "View
        only"); the Offerings list itself, unaffected. `admin/
        course_offerings_search.php` (already read-only/search-only, no
        POST handler at all) just relabels its "Add Offering"/"Enroll
        Students" row buttons to "View Offerings"/"View Enrollment" for
        this role — no access-boundary change was needed here since its
        two target pages already enforce the real boundary.
        `admin/course_enrollments.php`: bulk-enroll button/checkboxes and
        every row's Remove button hidden (checkboxes render `disabled`
        with a "View only" tooltip instead of vanishing outright, so the
        table shape stays consistent); `render_enroll_candidate_rows()`
        gained an `$isReadOnly` parameter, threaded through both its
        full-page and AJAX-partial call sites so the live-filtered partial
        can never drift from the full page load.
        `admin/courses_import.php`'s `require_role()` changed the same way
        as the lecturer/department importers (`['university_rector']` ->
        `[]`).
      - **`admin/departments.php`** (+ `admin/departments_import.php`):
        Add Department panel, Import from Excel, and per-row Edit/Delete
        hidden for this role (the bulk-delete feature on this specific
        page was already `university_rector`-only per an earlier session,
        so it's now simply gone for everyone — no other role ever had it,
        matching the same "sole permitted role removed" pattern as the
        three importers above). `admin/departments_import.php`'s
        `require_role()` changed from `['university_rector']` to `[]`.
      - **`admin/faculties.php`** (rector-only page — no other role has
        ever reached it): Add Faculty button, the entire Add/Edit modal,
        and per-row Edit/Delete all hidden; the "All Faculties" table and
        the per-faculty summary cards render exactly as before.
      - **`admin/academic_years.php`** (also rector-only): same treatment
        — Add Academic Year button, the Add/Edit modal, and per-row
        Edit/Delete all hidden; the table of existing academic years is
        unaffected.
      - **`semesters.php`** (shared by `university_rector`/`head_academic`/
        `dean`): the "Create Semester"/"Edit Semester" card, the
        Start/End/Waiting/Generate Sessions/Edit/Hide-from-Students button
        row, the "Assign Faculty" prompt, and the Save Dates form (with its
        own bulk-delete-sessions bar) are all hidden for this role — a new
        plain read-only sessions table (Xiiso #/label/type/date, no
        checkboxes or date inputs) renders in their place when a semester
        is selected. The "All Semesters" list's Edit/Delete icon column,
        previously shown for `$role === 'university_rector' || $role ===
        'dean'`, is now `!$isReadOnly && $role === 'dean'` (two call sites,
        `replace_all`) — `head_academic` was already excluded from this
        column before this change and still is; only `university_rector`'s
        own access changed.
      - **`attendance.php`** (Xiiso Grid attendance-marking page) — no new
        UI built, per the task's explicit instruction: reused the page's
        own pre-existing `$canWriteAttendance` mechanism (already used to
        render a disabled grid + "Read-only" badge/banner for a
        lecturer/dean viewing a semester outside their normal write scope).
        Added `$role !== 'university_rector' &&` to the condition that
        computes `$canWriteAttendance`, forcing it permanently false for
        this role regardless of course/semester/offering — the existing
        disabled-cell/badge/banner rendering then applies automatically.
        The scope banner's `match ($role)` arm for `university_rector`
        changed to the "view only (oversight)" wording.
      - **`ajax/save_attendance_cell.php` + the shared
        `user_can_write_course_attendance()` helper** (`includes/
        attendance_helpers.php`) — this was the real enforcement point,
        not `attendance.php` itself (which only controls what renders
        clickable): `user_can_write_course_attendance()` previously had
        `if ($role === 'university_rector') { return true; }` as its very
        first check, meaning this role always passed authorization
        regardless of the `attendance.php`-side flag — changed to `return
        false;` with an explanatory comment. Every other caller of this
        shared function (`attendance.php`'s own `$canWriteAttendance`,
        `reports.php`, though that file's role logic itself was left
        untouched per the task) automatically inherited the new
        deny-by-default behavior for this one role with no further
        per-call-site changes needed. The AJAX endpoint's existing
        `http_response_code(403)` + generic denial-message path (already
        built for the "lecturer with no current offering" case) fires
        exactly the same way for this role now — no new response shape.
      - **`includes/nav_items.php`**: two direct sidebar entries would
        otherwise have 404'd/unauthorized'd for this role after removing
        it from their target files' `require_role()` — "Import Attendance"
        (`roles: ['university_rector', 'dean', 'lecturer']` ->
        `['dean', 'lecturer']`) and "Import Students" (`roles:
        ['university_rector', 'registration']` -> `['registration']`).
        The other three import pages
        (`lecturers_import.php`/`departments_import.php`/
        `courses_import.php`) have no standalone sidebar entries at all —
        they're reachable only via the "Import from Excel" button on
        their parent page, already hidden for this role in step one above
        — so no further `nav_items.php` change was needed for those three.
      - **Two new read-only pages**, both `require_role(['university_rector'])`
        only (no other role granted access — every other role already has
        its own scoped way to see this data):
        - `admin/student_view.php?student_id=X` — profile fields (Student
          No/Full Name/Email/Status/Academic Year/Faculty/Department/
          Semester/Shift) plus the same Semester-box-picker +
          course-discovery-and-scoring logic as `student/courses.php`
          (three-source discovery: `course_enrollments` first, department
          fallback second, guest-offering `roster_department_id` third,
          the `co.id IS NOT NULL OR a.id IS NOT NULL` "real evidence"
          filter, and the capped out-of-10 `LEAST(10, SUM(status=
          'present'))` scoring) — adapted to take `student_id` directly
          from the querystring instead of resolving it from
          `current_user()`, since this page looks at someone else's
          record. A new "View" eye-icon link on `admin/students.php`'s
          Actions column (this role only) opens it.
        - `admin/lecturer_view.php?lecturer_id=X` — profile fields (Staff
          No/Full Name/Email/Status/Home Department/Home Faculty) plus the
          same full-teaching-history query shape as `lecturer/courses.php`
          (one row per (course, offering) pair, current + waiting + ended
          semesters alike, roster size and marked-session stats via
          `get_course_roster_count()`/per-session `attendance` counts) —
          adapted to take `lecturer_id` directly rather than resolving the
          viewer's own lecturer record. No "Take Attendance" link (a write
          action, correctly out of scope for a view-only page). A new
          "View" eye-icon link on `admin/lecturers.php`'s Actions column
          (this role only) opens it.
        Both pages reuse `.admas-card`/`admas-table` styling and the
        shared `attendance_badge_class()` helper for score coloring —
        no hardcoded colors, dark-mode-aware via the existing CSS
        variables like every other page in the app.
      - Updated CLAUDE.md §4's University Rector row to describe the new
        view-only-except-User-Management-and-Settings scope, and the
        `ROLE_INFO` mirror table on `admin/users.php` to match (text only,
        no logic change on that file, per the task's explicit
        "do not touch" instruction).
      - **Known gap, flagged rather than silently left**: `lecturer_courses.php`
        ("Assign Courses", reached from `admin/lecturers.php`'s own
        per-row link — itself now hidden for this role — but still
        directly URL-reachable) was **not** in the task's list of 8
        pages/page-groups to convert and was deliberately left untouched;
        its own `require_role(['university_rector', 'dean',
        'head_academic'])` still grants this role full write access to
        `course_offerings` via that specific page if navigated to
        directly. Casual discovery is blocked (the link that used to
        surface it is gone), but this is a real remaining write path for
        `university_rector`, not yet closed — worth revisiting in a future
        session if full defense-in-depth for this role is wanted.
      - **Verified end-to-end via real HTTP requests** against the live
        app (not just `php -l`, though all 22 touched/created files were
        also lint-checked clean) with two temporary accounts created via
        direct SQL insert (`temp_rector_qa` / `university_rector`,
        `temp_dean_qa` / `dean`, scoped to a real faculty — Informatics):
        logged in as both via curl with separate cookie jars; confirmed
        all 8 target pages (plus the 3 courses.php sub-pages) return `200`
        for the rector account with the full data list/table still
        rendering (111 student-row-related elements found on
        `admin/students.php` alone) and every "Add"/"Import"/bulk-delete
        UI element absent from the rendered HTML (confirmed by exact
        string search for the literal button/link text, not just `$role`
        branches — a few initial false-positive greps against JS
        code/comments containing the same substrings, e.g.
        `bulkDeleteStudentsBtn` inside a `document.getElementById()` null
        -guard, were individually re-checked and confirmed not to be
        real rendered buttons); confirmed `attendance.php`'s Xiiso Grid for
        a real course+semester with a real offering rendered every cell
        `disabled` with the "Read-only — you do not have write access..."
        tooltip and a "Read-only" badge; sent 9 crafted direct POSTs
        (students/lecturers/courses/departments/faculties/academic_years
        create, semesters create_semester, course_offerings save_offering,
        course_enrollments bulk_enroll) as the rector account and
        confirmed every one of `users`/`students`/`lecturers`/`courses`/
        `departments`/`faculties`/`semesters`/`course_offerings`/
        `course_enrollments`/`academic_years` row counts were byte-
        identical before and after all nine attempts, with the exact
        expected flash message ("Access scope: View only — this role
        cannot modify records.") rendered back; confirmed a direct AJAX
        POST to `ajax/save_attendance_cell.php` for a real course/
        semester/session/student combination returned HTTP `403` with
        `{"ok":false,"message":"You do not have permission to edit
        attendance for this course."}` and confirmed via direct DB read
        that the targeted attendance row's `recorded_by_user_id` stayed
        the original value (not the rector's own user id) — the request
        never reached the write; confirmed all 5 pages meant to deny this
        role outright (`admin/students_import.php`,
        `admin/lecturers_import.php`, `admin/departments_import.php`,
        `admin/courses_import.php`, `attendance_import.php`) redirect
        (`302`) to `unauthorized.php`; confirmed both new
        `admin/student_view.php`/`admin/lecturer_view.php` return `200`
        with real profile/course/attendance data for a real existing
        student (id 2) and lecturer (id 2); confirmed both eye-icon "View"
        links render with the correct `student_id`/`lecturer_id` on the
        rector's own rendering of `admin/students.php`/
        `admin/lecturers.php`. Confirmed `admin/users.php` and
        `admin/settings.php` still render their full write UI ("Assign
        Role", "Danger Zone") for this role and did one small reversible
        write — toggled the temporary dean account's active/inactive
        status twice via a real POST, confirming both the flip and the
        revert in the database. Confirmed the temporary dean account's
        own access was completely unaffected on 3 of the 8 converted
        pages: `admin/students.php` and `admin/departments.php` both
        still rendered their real "Add Student"/"Add Department" buttons
        and per-row Delete icons, and a real `admin/departments.php`
        create (a temporary "QA Test Dept" department under the dean's
        own Informatics faculty) succeeded and was confirmed in the
        database; `semesters.php` still rendered its "Create Semester"
        card for the dean. All temporary accounts and the temporary
        department were deleted afterward; every table's row count
        confirmed back to the exact pre-session baseline (`users` 64,
        `students` 55, `lecturers` 2, `courses` 4, `departments` 3,
        `faculties` 3, `semesters` 3, `course_offerings` 5,
        `course_enrollments` 80, `academic_years` 2, `sessions` 36).
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### admin/courses.php: Add Course's First Offering Is Now Required, With Cross-Faculty Support
- [x] The user asked that when Head of Academic Affairs or Dean create a
      course, the first-offering section (previously opt-in — a course
      could be saved catalog-only, with "Manage Offerings" used later) must
      be **required**, and that the course should be able to belong both to
      the faculty its own department is registered under AND, separately,
      to a different faculty as an offering (cross-listing at creation
      time, not only via the separate "Manage Offerings"/"Add Existing
      Course" flow built in an earlier session's Multi-Faculty Course
      Offerings work).
      - **Semester (and therefore Shift) is now mandatory on create** for
        both roles — `admin/courses.php`'s create handler now rejects
        `offering_semester_id <= 0` with "Please select a semester for this
        course's first offering." (previously this whole block only ran
        when a semester was actually chosen; a course could be saved with
        zero `course_offerings` rows). The "(optional)" labels on Academic
        Year/Semester were removed and `required` added to both selects
        (Shift already had server-side validation and now also has the
        HTML attribute — safe to require even though it lives inside the
        `offeringDetailsBlock` `d-none` wrapper, since a `display:none`
        required field is exempt from HTML5 constraint validation, the
        same reasoning already documented for this exact pattern in an
        earlier session's "attendance.php: Removed Single-Session Form"
        entry).
      - **Cross-faculty offering at creation, Head of Academic Affairs
        only** — Dean stays exactly as strictly own-faculty-only as every
        other write path in this app (their Semester query is still
        `WHERE id = ? AND faculty_id = ?` bound to `$deanFacultyId`, so
        `$isGuestOffering` can never be true for a Dean by construction,
        same reasoning `admin/course_offerings.php` already established for
        its own dean-vs-not branch). For Head of Academic Affairs (the only
        other role that ever reaches this form — `university_rector` is
        view-only on this page per the prior session's work), a new
        **"Offering Faculty"** dropdown (all faculties, defaults to the
        picked Department's own faculty via a new
        `admasOnCourseDepartmentChange()` wrapper on the Department
        `<select>`'s `onchange`, kept deliberately separate from
        `admasUpdateOfferingFieldsForDepartment()` itself because that
        function is also called on page-load re-render after a failed
        cross-faculty submission and must NOT reset the faculty back to
        the department's own in that case) drives the Semester cascade
        instead of always the Department's faculty. When the chosen
        semester's faculty differs from the course's own department's
        faculty, a **Roster Department** field (department options from
        the *offering* faculty, via a new `$departmentsByFacultyId` id-keyed
        map alongside the existing name-keyed `$departmentsByFaculty`)
        appears and is required — server-side validated the same way
        `admin/course_offerings.php`'s existing guest-offering rule already
        works: "Roster Department must belong to the selected semester's
        own faculty." if given but wrong, "This course's department is in
        a different faculty than the selected semester — please select a
        Roster Department..." if a guest offering is being created with
        none given. `course_offerings.roster_department_id` (already in
        the schema since the earlier Multi-Faculty Course Offerings
        session — no migration needed here) is now written by this form's
        INSERT for the first time.
      - No changes to `admin/course_offerings.php`, `admin/courses_import.php`,
        or edit-mode course saves (edit mode has no offering section at
        all, unchanged) — this session touched only the create-mode
        first-offering section of `admin/courses.php`.
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary `head_academic` and `dean` (Health faculty) test
        accounts: confirmed a create POST with no semester is rejected for
        both roles with zero `courses` row created; confirmed Head of
        Academic Affairs creating a course under Informatics'
        Information Technology department, with Offering Faculty/Semester
        switched to Health's own current semester and no Roster Department,
        is rejected; confirmed the same request WITH a valid Health-faculty
        Roster Department (Nursing) succeeds and the resulting
        `course_offerings` row has the course's own `department_id` still
        Information Technology while `semester_id`/`roster_department_id`
        correctly point at Health/Nursing; confirmed Dean creating a course
        with no semester is rejected, a crafted foreign-faculty
        `offering_semester_id` is rejected with "Please select a valid
        semester from your own faculty." and zero DB change, and a real
        own-faculty semester succeeds with `roster_department_id` correctly
        `NULL` (Dean never sees or can submit that field). All temporary
        test courses/offerings and both temporary accounts were deleted
        afterward; `courses`/`users` row counts confirmed back to baseline.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### semesters.php: Bulk Semester Rollover ("End All Current Semesters")
- [x] The user asked for a way for Head of Academic Affairs, Dean, and
      University Rector to end every currently-current semester in one
      click, instead of clicking End on each one individually — framed
      around a real workflow: at the end of a real academic term, an admin
      wants to close out every in-progress semester at once rather than
      hunting through the list one row at a time. Clarified two open
      questions with the user before building, since the literal request
      ("all three roles can do this") directly conflicted with this
      session's own earlier University-Rector-to-view-only conversion:
      confirmed Rector stays **view-only** here too (sees the panel and the
      live count, button disabled) — Head of Academic Affairs and Dean are
      the only roles that can actually execute it; and confirmed Dean's
      click only ends semesters in **their own faculty**, never
      university-wide, matching every other write action already on this
      page.
      - New **"Bulk Semester Rollover"** card (the name requested — placed
        right below Semesters' own page header, above the existing
        Create/Edit Semester and All Semesters cards) showing a live count
        — `$currentSemesterCount`, computed from the same role-scoped
        `$semesters` array the rest of the page already builds (Dean's own
        faculty only; Head of Academic Affairs/University Rector see every
        faculty) — and one button, "End All Current Semesters".
      - New `end_all_current` POST action: a single `UPDATE semesters SET
        status = 'ended', is_current = 0 WHERE status = 'current'`, with an
        `AND faculty_id = ?` added only for Dean (re-derived server-side
        from `$_SESSION['faculty_id']`, never trusted from the request) —
        same scoping pattern every other write action on this page already
        follows (`dean_owns_semester()` etc.), just applied to a bulk
        `WHERE` instead of a single id. `$isReadOnly` (already `true` for
        University Rector on this whole page) blocks this action the same
        way it blocks every other one, so no separate guard was needed —
        the button is additionally hidden/disabled client-side for that
        role for the same reason every other write UI on this page already
        is.
      - Confirmation dialog states the exact count and (for Dean) the
        faculty name being affected before submitting, and warns that a new
        semester needs to be set Current afterward for attendance marking
        to keep working — ending every current semester is a real,
        deliberate rollover action, not reversible from the UI.
      - Deliberately did **not** build the "students automatically see new
        semesters on their dashboard" half of the request as new code —
        traced it and confirmed this is already exactly how
        `student/courses.php`/`student/dashboard.php`/
        `admin/student_view.php`'s course-discovery + Semester Box Picker
        already work (a semester box is only ever disabled/"not created
        yet" when no real `semesters` row exists for that name+faculty; the
        moment an admin creates a new semester and a course gets a real
        offering or attendance under it, it appears automatically) — ending
        old semesters doesn't hide anything further, and creating new ones
        already surfaces them with zero additional code.
      - **Verified end-to-end via real HTTP requests against the live app,
        using an entirely disposable test faculty for the actual
        DB-mutating step** — deliberately did NOT run the real
        `end_all_current` action as Head of Academic Affairs against
        production data, since that role's own query has no faculty
        filter at all and would have force-ended all 4 of the real,
        currently-in-use current semesters system-wide, breaking live
        attendance marking for real students/lecturers; that role's code
        path was instead verified by direct inspection (identical
        `$role === 'dean'`-gated WHERE-clause pattern already proven safe
        via the Dean test) plus a live, read-only UI check (panel renders
        "across every faculty" / "4 semesters are marked Current (all
        faculties combined)" — matching the real live count exactly — with
        the button present and enabled, no submit sent). For Dean:
        created a temporary faculty with one `current` and one `waiting`
        semester, a temporary Dean scoped to it, confirmed the panel
        showed "Currently 1 semester is marked Current", submitted the
        real action, and confirmed via direct DB read that only that one
        temporary semester flipped to `ended` (`waiting` one untouched)
        while all 4 real semesters across other faculties stayed byte-
        identical before/after. For University Rector: confirmed the
        button renders `disabled` with an explanatory tooltip, and sent a
        crafted direct POST of `action=end_all_current` anyway — confirmed
        via direct DB read that all 4 real current semesters remained
        untouched (the existing page-level `$isReadOnly` guard rejected it
        before reaching the new action's own code). All temporary
        faculty/semesters/sessions/accounts were deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.
      - **Follow-up**: renamed the card title and button from "Bulk
        Semester Rollover" / "End All Current Semesters" to **"Save All
        Semesters"** per explicit request ("magacaas ayaa dadka dhan
        fahmayaan" — that name is what everyone will understand) — text
        only, the underlying `end_all_current` POST action name and all
        scoping/logic are unchanged. `php -l` clean; not re-verified live
        beyond that, since no behavior changed.

### University Rector UI Polish: Profile Hero, Export Card, View Profile Buttons
- [x] Requested cosmetic/UX pass over University Rector's read-only pages —
      real ADMAS branding, nicer cards, the record's own photo visible on
      the detail pages, and a way to export data — plus two access/RBAC
      additions bundled into the same session.
      - **`admin/student_view.php` / `admin/lecturer_view.php`**: new
        `.profile-hero` navy/sky gradient header (new CSS in
        `assets/css/app.css`) showing the student's/lecturer's own uploaded
        photo (`users.photo_path`, same convention as the topbar avatar) or
        an initials circle, plus name/ID/Faculty·Department and a role
        badge. The old bare label/value "Profile" grid was rebuilt as a row
        of `.info-tile` cards with a `.section-heading` icon chip above
        each section, reusing the theme CSS variables so both stay
        dark-mode-correct with zero new hardcoded colors.
      - **`admin/students.php` / `admin/lecturers.php`**: the icon-only
        "View" eye link (Rector's row action) now reads **"View Profile"**
        (icon + text) via the existing shared `.btn-icon-label` class.
      - **New sky-blue `.export-card`** (Excel + PDF buttons) added to
        `admin/students.php`, `admin/lecturers.php`, and `semesters.php`
        (University Rector only), backed by a new `admin/export.php`
        (`require_role(['university_rector'])`) that generates a
        university-wide Students / Lecturers / Semesters list, reusing the
        same PhpSpreadsheet/Dompdf branded-export pattern already
        established in `reports.php`.
      - **Head of Academic Affairs granted the same read-only "View
        Students information" access as University Rector**: added
        `'head_academic'` to `admin/students.php`'s `require_role()` and
        folded it into the existing `$isReadOnly` flag (`in_array($role,
        ['university_rector', 'head_academic'], true)`), and to
        `admin/student_view.php`'s `require_role()` — no other logic
        needed changing, since every write branch on that page already
        gates on `$isReadOnly`, not the specific role name. Added the nav
        entry (`includes/nav_items.php`) and updated CLAUDE.md §4's live
        table accordingly.
      - Verified end-to-end via temporary `university_rector`/
        `head_academic` accounts: "View Profile" renders and links
        correctly on both list pages; both detail pages render the photo/
        initials hero and info-tile cards with zero PHP warnings; the
        Export card's Excel/PDF links on all three pages return real,
        valid files (200, correct `Content-Type`); Head of Academic Affairs
        gets the identical read-only `admin/students.php`/
        `admin/student_view.php` experience (no Add/Edit/Delete/Import UI,
        a crafted `action=delete` POST rejected server-side with zero DB
        change). Temporary accounts deleted afterward.

### Staff Messages (internal chat between staff roles)
- [x] A WhatsApp-style two-pane direct-message chat so University Rector /
      Head of Academic Affairs / Dean / Lecturer / Registration Office can
      message each other directly (e.g. to ask about an issue) without
      leaving the app — students are deliberately not part of this channel.
      - New `messages` table (`migrations/2026_08_staff_messages.sql`,
        `sender_id`/`receiver_id`/`body`/`is_read`/`created_at`, indexed for
        both "one conversation" and "my unread count" lookups) — applied
        and mirrored into `admas_attendance_schema.sql`, `mysqldump` backup
        taken first per this file's own convention.
      - New `includes/chat_helpers.php`: `CHAT_STAFF_ROLES` (the one
        source of truth for who may use this feature) and
        `chat_is_valid_contact()` (an active user whose role is in that
        set) — shared by `messages.php` and both ajax endpoints so the
        "who can message whom" rule can never drift between them.
      - New `messages.php` (app root, shared-file convention like
        `attendance.php`/`reports.php`): a contact list (every other active
        staff user, most-recently-messaged first, with unread badges and a
        last-message preview) on the left, the open conversation on the
        right. A plain `<form>` POST to the same file is the no-JS
        fallback; `assets/js/staff_chat.js` upgrades it to `fetch()`-based
        sending plus a 3-second poll (`ajax/chat_poll.php`) that appends
        new messages without a full reload and marks them read the moment
        they're actually seen.
      - New `ajax/chat_send.php` (inserts one message, rejects an invalid/
        non-staff `receiver_id` or an empty body) and `ajax/chat_poll.php`
        (GET, returns messages newer than `?after_id` in either direction
        between the caller and `?with`, and flips unread rows addressed to
        the caller to read).
      - `includes/nav_items.php` gained a "Messages" entry for the five
        staff roles; `includes/sidebar.php` computes and shows a small
        unread-count badge next to it (one extra `COUNT(*)` query per page
        load for those roles only, same pattern as `topbar.php`'s existing
        notification bell).
      - Verified end-to-end via temporary Rector/Head of Academic Affairs/
        Dean accounts: a real send/receive/read-receipt round-trip (DB
        `is_read` flipped the moment the recipient opened the
        conversation), a poll correctly returning only genuinely-new
        messages, a Dean correctly unable to send to a non-staff/forged
        user id (`400`, "Invalid recipient.", zero row inserted), and cross-
        conversation isolation (polling a conversation with no messages
        between two real staff members correctly returns empty, not
        another pair's messages). All temporary accounts and every test
        message row deleted afterward; `messages` table confirmed empty.

### Lecturer Check-In / Check-Out
- [x] A distinct feature from student Attendance: a lecturer's own
      arrival/departure log, recorded per **course + Xiiso session** they
      actually teach (confirmed with the user via two rounds of
      `AskUserQuestion` before writing any code — name: "Lecturer Check-In
      / Check-Out"; granularity: per session, not one flat daily clock;
      "left early" flagging: explicitly **not** automated — Head of
      Academic Affairs/Dean/University Rector see the raw timestamps and
      judge for themselves, no reference "expected end time" config was
      added anywhere).
      - New `lecturer_checkins` table
        (`migrations/2026_08_lecturer_checkins.sql`, mirrored into
        `admas_attendance_schema.sql`): `lecturer_id`/`course_id`/
        `session_id`/`check_in_at`/`check_out_at` (nullable until checked
        out), unique per `(lecturer_id, course_id, session_id)` so a
        lecturer can never double-check-in to the same class session.
      - New `lecturer/checkin.php` (lecturer-only, self-service): lists
        this lecturer's own **current-semester** offerings' sessions whose
        date has already arrived, most recent first, each row a Check In/
        Check Out button. The real security boundary is
        `lecturer_owns_current_session()` — a `(course_id, session_id)`
        pair is only ever a legitimate target if it belongs to one of this
        lecturer's own current `course_offerings` rows, re-checked
        server-side on every POST, not just filtered into the display list.
      - New `lecturer_checkins.php` (app root, read-only report shared by
        University Rector/Head of Academic Affairs — university-wide — and
        Dean — own-faculty lecturers only, enforced via the same
        `d.faculty_id = ?` join pattern already used elsewhere in this
        app), with a Lecturer/date-range filter bar.
      - `admin/lecturer_view.php` (University Rector's per-lecturer detail
        page) gained a new "Check-In/Out History" card — raw timestamps,
        a total-check-ins and a not-checked-out-count badge, no automatic
        judgement.
      - `includes/nav_items.php`: "Lecturer Check-In" (lecturer only) and
        "Lecturer Check-Ins" (the three viewing roles).
      - Verified end-to-end via a temporary lecturer holding a real
        current-semester `course_offerings` row: a full Check-In → Check-
        Out cycle via real HTTP POSTs, correctly reflected on both
        `lecturer_checkins.php` and `admin/lecturer_view.php`; confirmed a
        Dean scoped to a different faculty could not see that lecturer's
        record (correct empty-state, not an error). Temporary
        account/offering/checkin rows deleted afterward.
      - **Populated with real check-in history for the university's actual
        3 lecturers** (Mr Abdirahman, abdinakani qaanuushi, Eng Maax)
        against their own real current course offerings and real Xiiso
        session dates, at the user's explicit request, so the feature has
        real data to look at — left in place permanently, not test data.
        While seeding this, discovered the real lecturer had already been
        live-testing the feature themselves (3 real check-in rows already
        present for "Mr Abdirahman" at real-time timestamps) — those were
        left completely untouched; the seed script was adjusted around
        them rather than overwritten.

### Bug Fix: Multi-Cohort Semester Picker Defaulted to the Wrong Semester
- [x] Reported directly by the user with a screenshot: a student really
      enrolled in "Semester 9 (2023/2024)" was shown "Semester 3
      (2025/2026)" as the default/active semester on their own "My
      Courses" page, and that same course/data confusion carried through to
      the student's own dashboard ("no courses for the current semester")
      whenever their faculty had more than one semester marked `current` at
      once (an intentional, existing capability from an earlier session —
      independent concurrent cohorts/tracks per faculty).
      - **Root cause**: the Semester Box Picker's default-selection
        fallback (`student/courses.php`, `admin/student_view.php`) picked
        "whichever box is marked `current`, in ascending Semester-number
        order" — never actually checking which semester the specific
        student is assigned to (`students.semester_id`). With two
        concurrently-current semesters, the lower-numbered one always won,
        regardless of which cohort the student in question actually
        belongs to. Separately, `student/dashboard.php`'s own "current
        semester" resolution (`get_current_semester($conn, $facultyId)`)
        has an analogous, documented limitation — it returns the
        most-recently-created current semester for the whole faculty, not
        the one for this student's own academic-year cohort.
      - **Fix**: both picker pages now resolve the student's own
        `semester_id` first (added to their existing student-row `SELECT`)
        and use it as the default whenever it's among the created options,
        only falling back to "whichever is current" if the student has no
        semester assigned yet. `student/dashboard.php` now first looks for
        a `current` semester matching the student's own `(faculty_id,
        academic_year_id)` specifically, falling back to the generic
        per-faculty `get_current_semester()` only if none exists for that
        exact cohort yet.
      - **Follow-up bug, same root cause, reported separately**: with the
        above fixed, the *correct* box was now being selected, but a
        second, different-numbered semester that also happened to be
        `current` still showed its own "(current)" text label at the same
        time — two boxes both claiming "(current)" on screen, confusing on
        its own even once selection itself was correct. Fixed by tying the
        "(current)" label to a single `$myCurrentSemesterId` (the student's
        own semester if set, else whichever is actually selected) instead
        of any box whose raw DB `status` happens to be `current` — at most
        one box can ever say "(current)" on either page now.
      - Verified live against the real data that reproduced the original
        report (Informatics: Semester 9 and Semester 3 both genuinely
        `current`): a temporary student assigned to Semester 9 now
        correctly defaults to the Semester 9 box (not Semester 3) on both
        `student/courses.php` and `admin/student_view.php`, with exactly
        one "(current)" label shown on each page; a second temporary
        student in the same real cohort confirmed `student/dashboard.php`
        now shows all 4 of that cohort's real courses instead of "No
        courses recorded for this semester yet." All temporary accounts
        deleted afterward.

### attendance.php: Roster Search Filter
- [x] Added a client-side Student No/Name search box above the Xiiso Grid
      roster table (new `assets/js/roster_search.js`) so a lecturer/Dean/
      University Rector can quickly find a specific student in a large
      class — pure `input`-event row filtering over the already-rendered
      `<tr data-student-row>` rows, no extra query. Verified live: renders
      correctly above a real 25-student roster with zero PHP
      warnings/notices/fatals.

### admin/courses.php: "Current Offering" Enrolled Count Now Reflects the Real Roster, Not Just Explicit Enrollment
- [x] Found via a live screenshot the user shared while exploring a demo
      setup built earlier this session: a real course ("Inclusive", code
      `IC`, Information Technology/Informatics) showed **"0 enrolled /
      Incomplete"** on `admin/courses.php` despite having a real lecturer
      assigned and a genuinely working roster — students could already see
      it on their own dashboard and a lecturer could already mark real
      attendance against it. Root cause: the "X enrolled" count and the
      Complete/Incomplete badge were computed **only** from explicit
      `course_enrollments` rows (`$enrolledCountByCourseId`, a course-wide
      `COUNT(*) FROM course_enrollments`) — completely blind to the
      department-fallback roster resolution (`course_enrollments` first,
      else every active student in the offering's own department/
      `roster_department_id`) that every other real roster-facing page in
      this app already uses (`attendance.php`'s Grid View,
      `lecturer/courses.php`, `admin/lecturer_view.php`,
      `student/courses.php`). A course could be fully real and actively
      in use — real students, real marked attendance — and still show as
      "Incomplete" here for no reason other than nobody had separately run
      the "Enroll Students" action, which this app has never required for
      a course to actually function.
      - Per the user's explicit request ("waa in automatic dropka lagu
        diwaan geliyay ardayda ku jirta tiradooda ay qaadata" — it should
        automatically take the count of whoever is really in it), replaced
        the course-wide `$enrolledCountByCourseId` lookup with a direct,
        per-offering call to the existing shared
        `get_course_roster_count($conn, $courseId, $semesterId, $shift)`
        helper (`includes/attendance_helpers.php`, already the single
        source of truth this exact resolution logic elsewhere in the app
        reuses) — one call per rendered offering row, using that specific
        offering's own `semester_id` and `shift` (`'any'` normalized to
        `null`), so a course with three offerings across three
        semesters/shifts now shows each one's own real, independently
        correct roster size rather than one shared course-wide number.
      - Deleted the now-fully-dead `$enrolledCountByCourseId` query block
        (its only reader was removed) rather than leaving it unused.
      - **Verified end-to-end against real, live production data — not a
        disposable fixture, since the bug report itself was about a real
        course**: confirmed directly in the database that course `IC`
        genuinely has zero `course_enrollments` rows; logged in as a
        temporary Head of Academic Affairs account and confirmed
        `admin/courses.php` now renders `IC`'s offering as **"25 enrolled"
        / Complete** — the real Information Technology department
        headcount — with zero PHP warnings/notices/fatals. Also
        re-confirmed against the disposable multi-faculty demo course
        built earlier this same session (see "University Rector UI
        Polish"-adjacent demo work above) that enrolling students still
        correctly raises the count further where real explicit enrollment
        exists (`course_enrollments` is still checked *first* inside
        `get_course_roster_count()` — this change only widens what counts
        as "real," it doesn't stop counting explicit enrollment). No
        schema change. Temporary verification account deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.
      - **Follow-up, found by the user comparing the two pages side by
        side**: `admin/course_offerings.php` ("Manage Offerings") had the
        exact same course-wide, `course_enrollments`-only bug — a single
        flat `$enrolledCount` shared by every offering row on the page,
        completely blind to the department-fallback roster. This is what
        caused the two pages to visibly disagree on the same real course
        ("AC — Accounting": `admin/courses.php` correctly showed "15
        enrolled / Complete" per the fix above, while "Manage Offerings"
        for the identical course still showed "0 / Incomplete" on both its
        rows). Applied the identical fix here: deleted the course-wide
        `$enrolledCount` query, replaced the per-row completeness check
        with a direct `get_course_roster_count($conn, $courseId, (int)
        $o['semester_id'], $o['shift'] !== 'any' ? $o['shift'] : null)`
        call per offering row — the same helper, same call shape, as
        `admin/courses.php` now uses, so the two pages can no longer
        drift apart on the same course. Verified live against the real
        "AC — Accounting" course (id 22, both its Health-guest and
        Business-Administration-home offerings) with a temporary Head of
        Academic Affairs account: both rows now show "15 enrolled /
        Complete", matching `admin/courses.php` exactly, zero PHP
        warnings/notices/fatals. Temporary account deleted afterward.

### admin/students.php: "Export Students" for Head of Academic Affairs (Select All / Individually)
- [x] Head of Academic Affairs is read-only on Student Management (per
      CLAUDE.md §4's "View Students information") — no Add/Edit/Delete/
      Import UI. Added a sky-blue "Export Students" button for this role
      specifically, with select-all/individual checkboxes (the same
      `.row-check-student`/`#selectAllStudents` markup every write-capable
      role already gets for bulk delete, now also shown for this
      read-only role — export isn't destructive, so it's safe to expose
      here even though CRUD stays hidden) — exporting either everyone
      currently listed (nothing checked) or just the checked subset.
      - New `assets/js/bulk_export.js` — `admasInitBulkExport()`, a
        sibling to `bulk_delete.js`'s `admasInitBulkDelete()` but with no
        confirm dialog (export isn't destructive) and a button label that
        toggles between "Export All Students" and "Export Selected (N)"
        instead of hiding at zero selected.
      - `admin/export.php` (previously University-Rector-only, whole-list
        Excel/PDF for Students/Lecturers/Semesters): widened
        `require_role()` to include `head_academic`, but only for
        `type=students` — a direct request for `type=lecturers` or
        `type=semesters` from this role is rejected (403), matching that
        this role's own Export button only exists on the Students page.
        Accepts an optional POST `ids[]` (validated as plain positive
        integers) that narrows the query to `WHERE s.id IN (...)` when
        present; a plain GET with no `ids[]` (University Rector's existing
        links, or this role's own button with nothing checked) still
        exports every student, unfiltered, exactly as before. The
        title/meta line on both the Excel sheet and the PDF header now
        reads "N selected students" instead of "University-wide export"
        whenever a subset was requested, so the file itself is unambiguous
        about its own scope.
      - **Verified end-to-end via real HTTP requests** with a temporary
        Head of Academic Affairs account: confirmed the button and
        checkboxes render (previously absent for this role, since they
        were gated behind the same `!$isReadOnly` check as bulk delete —
        introduced a separate `$showSelectCheckboxes` flag so this one
        read-only role gets checkboxes without also getting delete);
        exported with nothing selected and confirmed a real, valid
        `.xlsx` re-opened with PhpSpreadsheet showing exactly 58 data rows
        (the real total student count at the time) titled "University-wide
        export"; exported with 2 specific students checked and confirmed
        exactly 2 data rows, the correct 2 real students, titled "2
        selected students". Temporary account deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Demo/QA Data Cleanup
- [x] Deleted every remaining fixture from this session's earlier
      cross-faculty/multi-shift demonstration work (built, then
      deliberately left in place at the user's request so they could log
      in and verify it themselves — see the "Save All Semesters"-adjacent
      demo sessions above) once the user confirmed they were done: the
      temporary "QA Demo Faculty D" faculty, its department, its semester
      and 12 generated sessions, the "XIS101 — Xisaabta Guud" demo course
      and all 6 of its `course_offerings` rows, the 2 real attendance marks
      created while proving cross-semester write-isolation, the 3 demo
      lecturer accounts (Alpha/Beta/Gamma, later renamed to Somali names),
      the 3 demo student accounts, and the temporary Head of Academic
      Affairs account used to create the offerings through the real UI.
      Confirmed afterward via direct query: zero rows remain matching any
      of these fixtures' names/codes, and the real "AC — Accounting" /
      "IC — Inclusive" courses and the real student count (55) were
      unaffected throughout.

### Bug Fix: student/xiiso_grid.php's "View Grid" Showed "Not Available" for Real Courses
- [x] Reported by the user with a specific, correct diagnostic pattern
      ("the score shows fine, but View Grid shows nothing" — see the
      "How to garner future bugs" discussion that immediately preceded
      this fix): clicking "View Grid" from `student/courses.php` failed for
      real courses (Calculus, Inclusive, Taxation) a real Informatics
      Semester 9 student genuinely takes, even though that same page's own
      attendance-score column for those exact courses displayed correctly.
      Traced to `student/xiiso_grid.php` never having been updated when
      three earlier fixes landed on `student/courses.php`/
      `student/dashboard.php` — it had silently drifted out of sync with
      the "same data, correct version" pages, exactly the failure pattern
      just discussed with the user. Three separate, compounding bugs:
      1. **Department-fallback course discovery was gated on
         `course_enrollments` being empty**, not additive — a student with
         even one explicit `course_enrollments` row (common: only one
         course out of several actually has a row) lost every other
         course their own department offers "for free" via the fallback.
         Same real incident already fixed elsewhere; this file just never
         got the fix.
      2. **The "guest offering" cross-listed-course discovery source was
         entirely missing** — a course cataloged under a different
         faculty but offered into this student's department via
         `course_offerings.roster_department_id` (e.g. Taxation, cataloged
         under Business Administration, offered into Informatics) was
         never even a candidate `course_id` here, though
         `student/courses.php` already discovers it correctly.
      3. **The semester-fallback used the generic, faculty-wide
         `get_current_semester()`** (documented to return the
         most-recently-created current semester when several are
         concurrently current) instead of resolving to this specific
         student's own `(faculty_id, academic_year_id)` cohort first —
         same root cause as the earlier `student/dashboard.php` fix,
         never applied here.
      4. **Found while fixing #2**: the course/semester faculty-match
         guard (`courseRow['faculty_id'] !== semesterRow['faculty_id']`)
         unconditionally rejected a legitimate cross-listed course, since
         its own catalog department's faculty never equals the semester's
         faculty by design. Fixed by allowing the pairing through when a
         real `course_offerings` row exists for that exact
         `(course_id, semester_id)` — reusing the existing
         `course_offering_exists()` helper rather than writing a new
         check, so this can't drift from the definition used elsewhere.
      - All three/four fixes make this file's course/semester resolution
        logic match `student/courses.php`'s already-correct version
        line-for-line in intent, closing the drift rather than patching
        around it.
      - **Verified end-to-end via real HTTP requests**, first reproducing
        the exact reported failure with a temporary student mirroring the
        real student's shape (Informatics/Information Technology,
        Semester 9, one explicit `course_enrollments` row) — confirmed
        Calculus/Inclusive/Taxation all failed with "not available" before
        the fix, exactly as reported (and, unexpectedly, Discrete Math —
        the one course this temp student WAS explicitly enrolled in —
        also failed, which is what surfaced bug #3: a temp student with no
        attendance history yet has nothing to anchor `semesterOptionIds`
        to besides the buggy semester fallback). After all fixes: all four
        courses render correctly with zero PHP warnings/notices/fatals.
        Re-verified with a second, independent temporary student
        mirroring the same real shape to rule out any state leakage
        between test runs. All temporary accounts/enrollments deleted
        afterward; real student/course counts unaffected.

### Sidebar: Grouped Nav Sections + Logout Link (real implementation, task 1 of 2)
- [x] After several rounds of no-code mockup iteration (Artifacts, not
      committed here), the user approved the grouped-sidebar direction and
      asked for it to actually be built — framed as "task 1"; a second,
      separate task (reworking the sidebar's own profile-photo/Role-name
      block, per the most recent mockup) is coming next once this is
      tested live.
      - **`includes/nav_items.php`**: every entry gained a `group` key —
        `Overview` / `Academic Management` / `Attendance Management` /
        `Reports & Analytics` / `Communication` / `System Administration` /
        `Account`. The master array itself was reordered (entries grouped
        together, group order fixed) rather than left in its old order with
        grouping bolted on — a plain per-role filter of an
        already-group-ordered array is guaranteed to stay contiguous per
        group with zero role-specific sorting logic in `sidebar.php`.
        Verified this mattered in practice: Dean's own visible items
        include Semesters (Academic Management) after Attendance/Import
        Attendance (Attendance Management) in the *old* array order — left
        unfixed, Dean would have seen two separate "Academic Management"
        headers. Also added a new **Log Out** nav entry (`logout.php`, all
        6 roles, `Account` group) — additive only, the existing icon-only
        logout button in `includes/topbar.php` is untouched, so logging out
        now works from either place.
      - **`includes/sidebar.php`**: tracks `$sidebarLastGroup` while
        iterating `nav_items()`, printing a new `.sidebar-group-title`
        `<div>` only when the group actually changes for the items *this
        role* can see — no per-role branching, relies entirely on the
        array's own group-ordering guarantee above. The Logout link gets an
        extra `sidebar-link-logout` class for its own red-tinted styling.
      - **`assets/css/app.css`**: added `.sidebar-group-title` (small
        uppercase muted label, matching the mockup's own group-header
        styling scaled to the real 260px sidebar) and
        `.sidebar-link.sidebar-link-logout` (red-tinted text/hover,
        distinguishing it from the ordinary nav links above it).
      - **Verified end-to-end via real HTTP requests** against the live app
        with four temporary accounts (`university_rector`,
        `dean`/Engineering & IT, `lecturer`, `student`): confirmed each
        role's dashboard returns 200 with zero PHP warnings/notices/fatals;
        confirmed the exact expected group set and order per role
        (Rector: all 7 groups; Dean: 6, no System Administration, Academic
        Management rendered exactly once — confirming the Semesters
        interleave risk above is actually fixed, not just reasoned about;
        Lecturer: 6, no Academic Years/Faculties-type entries; Student: 5,
        no Attendance/System Administration write items); confirmed the new
        sidebar Log Out link actually destroys the session (redirects to
        `login.php`, and a subsequent request to the role's own dashboard
        correctly bounces back to `login.php` too, not just a cosmetic
        link). All four temporary accounts were deleted afterward; `users`
        count confirmed back to baseline (0 `temp_navqa*` rows remaining).
      - **Task 2 (done, see next entry)**: the sidebar's top identity block
        (profile photo + Role name, replacing the university-logo/brand
        block) — implemented below once the user approved a mockup and
        asked for it to be built.

### Sidebar: Profile Photo + Role Name Identity Block (task 2 of 2)
- [x] Same no-code-mockup-first process as task 1 above (several rounds of
      Artifact iteration, this one landing on: Role label on top, profile
      photo (or a fallback person icon when none is uploaded) in the
      middle, full name at the bottom — the university's own logo/name
      removed from the sidebar entirely, since that identity now lives only
      in the sky-blue top strip via `includes/topbar.php`, which this task
      deliberately left untouched). Once the user approved it, asked for it
      to be built for real.
      - **`includes/sidebar.php`**: replaced the old `.sidebar-brand` block
        (university logo image + name/subtitle, sourced from
        `includes/university_logo.php`) with a new `.sidebar-profile` block
        reading `role_label(current_role())` for the Role line and the
        already-available `$currentUser` (every page that includes
        `sidebar.php` already sets this before the include — confirmed by
        grep across every page type, not assumed, since `topbar.php` has
        silently depended on the same precondition since the Profile Photo
        Upload session) for the photo/name. Dropped the now-unused
        `require_once .../university_logo.php` from this file — every
        other real consumer of that helper (`login.php`, `reports.php`,
        `admin/settings.php`, `admin/export.php`, etc.) already requires it
        independently, confirmed via grep before removing it here. Reuses
        the exact same photo-or-fallback branch shape and
        `uploads/profile_photos/` path `topbar.php`'s avatar already uses,
        just with a larger 64px size and a Bootstrap `bi-person-fill`
        fallback icon instead of initials (an icon reads better at this
        size/isolation than a two-letter initials circle would).
      - **`assets/css/app.css`**: added `.sidebar-profile` and its four
        children (`-role`, `-photo`, `-photo-fallback`, `-name`) right
        after the now-unused (but left in place, since `login.php` has its
        own same-named classes in a fully separate, non-app.css `<style>`
        block and was confirmed unaffected either way) `.sidebar-brand`/
        `.brand-title`/`.brand-subtitle` rules. Role label and name
        deliberately share one `font-family` declaration (a rounded/
        friendly system stack — "Segoe UI Rounded"/"SF Pro Rounded"/Nunito/
        "Segoe UI"/Roboto — chosen to read as one designed unit, per the
        user's explicit request that both use "the same font"), distinct
        from the sidebar's own default sans stack used by the nav links
        below it. No new dark-mode block was needed: the sidebar has
        always used a fixed navy gradient with hardcoded light text
        regardless of `[data-theme]` (confirmed by reading `.sidebar-link`'s
        existing rules before assuming), so the new block follows the same
        fixed-color convention as everything else already in this
        container.
      - **Verified end-to-end via a real HTTP request** against the live
        app with a temporary `university_rector` account (no photo
        uploaded): confirmed `admin/dashboard.php` returns 200 with zero
        PHP warnings/notices/fatals, and confirmed via the raw HTML that
        the Role label ("University Rector"), the fallback person-icon
        (`sidebar-profile-photo-fallback`, correctly rendered since this
        account has no `photo_path`), and the full name ("Nav QA Rector
        Two") all render in the new block. The photo-present branch (an
        `<img>` from `uploads/profile_photos/...`) was not separately
        exercised via a live login — confirmed instead that `admin01`
        already has a real `photo_path` in the database and that this
        branch's code is structurally identical to `topbar.php`'s
        already-proven avatar-rendering pattern (same conditional shape,
        same path convention), rather than resetting a real account's
        password just to log in as it. Temporary account deleted
        afterward; `users` count confirmed back to baseline (0
        `temp_navqa2` rows remaining).

**Both sidebar-redesign tasks are now complete and live in the real app**,
not just as mockups: grouped nav sections + sidebar Logout link (task 1),
and the Role/photo/name identity block replacing the university brand
block (task 2).

### Sidebar Profile Follow-up: Role/Name Emphasis Swap, Topbar Identity Removed, Role Label Uppercased
- [x] Three quick, related refinements to the task-2 work above, all
      requested with a screenshot of the live result:
      - **Role vs. name emphasis swapped** in `assets/css/app.css`'s
        `.sidebar-profile-role`/`.sidebar-profile-name` — the Role label
        (e.g. "University Rector") grew from a small muted line to the
        large, bold, white one (`1rem`/700), and the person's name shrank
        to the small muted line underneath it (`0.76rem`/500) — the
        opposite of task 2's original sizing.
      - **Removed the now-redundant identity block from the topbar**
        (`includes/topbar.php`'s `.topbar-user` — avatar photo/initials +
        name + role text, next to the bell/theme-toggle/logout icons) since
        the same photo/role/name now lives in the sidebar and showing it
        twice was pure duplication once task 2 landed. The theme toggle,
        notification bell, and logout icon button are untouched — only the
        name/photo cluster between the bell and the logout button was
        removed. Also deleted the `$initials`/`$roleLabel` PHP variables in
        `topbar.php` (dead code the moment their only consumer was
        removed) and the now-fully-unused `.topbar-user`/`.avatar-photo`/
        `.avatar-initials`/`.user-meta`/`.user-name`/`.user-role` CSS rules
        from `app.css` — confirmed via a codebase-wide grep that nothing
        else (no `*/profile.php` photo preview, no other partial) referenced
        any of these class names before deleting them. `.logout-btn` itself
        was kept, since the logout icon button is still rendered.
      - **Sidebar Role label is now uppercase** — added
        `text-transform: uppercase` (plus a touch more letter-spacing) to
        `.sidebar-profile-role`, so "University Rector" now renders as
        "UNIVERSITY RECTOR" for every role, sourced from the same
        `role_label()` call as before (no PHP string changes needed — pure
        CSS).
      - **Verified end-to-end via a real HTTP request** against the live
        app with a temporary `university_rector` account: confirmed
        `admin/dashboard.php` returns 200 with zero PHP warnings/notices/
        fatals; confirmed via the raw HTML that `topbar-user`/`avatar-photo`/
        `avatar-initials`/`user-name`/`user-role` no longer appear anywhere
        in the response while `logout-btn` still does (the icon button
        survived, only the identity cluster was removed); confirmed the
        sidebar's `sidebar-profile-role` element still renders "University
        Rector" as text (the uppercase transform is a CSS rendering effect,
        not a markup/string change, so this is the correct thing to see in
        the raw HTML). Temporary account deleted afterward; `users` count
        confirmed back to baseline (0 `temp_navqa4` rows remaining).

### Sidebar Role Label: "<Role Name> Role" Suffix
- [x] `includes/sidebar.php`'s `$sidebarRoleLabel` now appends `' Role'` to
      `role_label($activeRole)` — "Student Role", "Lecturer Role", "Dean
      Role", "University Rector Role", etc. Deliberately applied only at
      this one call site, not inside `role_label()` itself, since that
      function is also read by `admin/users.php`'s user table, scope
      banners, and other places where "University Rector Role" would read
      oddly next to a real account row — the sidebar is the one place this
      suffix was actually requested.
      - **Verified end-to-end via real HTTP requests** with three temporary
        accounts (`university_rector`, `dean`, `student`): confirmed the
        sidebar now renders "University Rector Role", "Dean Role", and
        "Student Role" respectively (displayed in capitals via the
        existing `text-transform: uppercase` CSS rule, unchanged), with
        zero PHP warnings/notices/fatals on each role's own dashboard. All
        three temporary accounts deleted afterward; `users` count confirmed
        back to baseline.

### Bug Fix: Profile & Password Broken for All 6 Roles (regression from the topbar cleanup)
- [x] User reported Dean and Registration Office's "Profile & Password"
      pages not working, and asked all 6 roles be re-checked. Root cause:
      the "Sidebar Profile Follow-up" session above deleted `$initials`
      from `includes/topbar.php` as apparently-dead code — it *was* dead
      for `topbar.php`'s own markup (the identity cluster that used it was
      removed in that same session), but every one of the 6
      `*/profile.php` pages (`admin`, `dean`, `head_academic`,
      `registration`, `lecturer`, `student`) independently reuses that
      exact variable for their own photo-fallback circle, via PHP's shared
      include scope (`topbar.php` is included before the point each
      `profile.php` reads `$initials`) — a dependency the original grep
      for `$initials`'s only-consumer check should have caught but didn't,
      since it was checked against `topbar.php`'s own file in isolation
      rather than a codebase-wide grep the way `$roleLabel` (the other
      variable removed in the same session) correctly was.
      - **Fix**: restored the `$initials` computation in `topbar.php` —
        not the markup that used to display it there, just the
        computation itself, with a comment explaining it's now consumed
        downstream, not by this file. `$roleLabel` was re-checked via a
        proper codebase-wide grep this time and confirmed to have no real
        remaining consumers (the earlier grep hit was a false-positive
        substring match against the unrelated `$roleLabelText` in
        `admin/users.php`), so it was correctly left removed.
      - **Verified end-to-end via real HTTP requests** with one temporary
        account per role (all 6): confirmed every `*/profile.php` page
        returns 200 with zero PHP warnings/notices/fatals; did a full
        password-change round-trip on Dean and Registration Office
        specifically (the two the user named) — submitted the real
        `change_password` POST, confirmed the "Password changed
        successfully." flash rendered, and independently verified via
        `password_verify()` against the stored hash in the database that
        the password had actually changed, not just that the page said so.
        All 6 temporary accounts deleted afterward; `users` count
        confirmed back to baseline (0 `temp_pw6_*` rows remaining).

### Dean: Full CRUD Converted to a Faculty-Scoped Viewer (University Rector Left Untouched)
- [x] The user asked for Dean's Courses/Students/Lecturers/Semesters/
      Departments/Import Attendance/Attendance nav items to be stripped
      down, then — after being walked through what a literal "just remove
      the sidebar items" reading would actually do (Dean would lose all
      real CRUD but the pages would stay reachable by direct URL, a
      half-measure) — confirmed the real intent: **Dean becomes a
      read-only Viewer, faculty-scoped exactly as before, everywhere**;
      University Rector's own access was explicitly confirmed **untouched**
      throughout. This is the exact same `$isReadOnly` conversion pattern
      University Rector already went through in an earlier session, applied
      to Dean now — with Dean's existing own-faculty scoping (`$role ===
      'dean'` blocks, `$_SESSION['faculty_id']`) left completely intact, so
      a Dean still never sees another faculty's data, just can no longer
      write within their own.
      - **`admin/students.php`, `admin/lecturers.php`,
        `admin/departments.php`, `admin/courses.php`,
        `admin/course_offerings.php`, `admin/course_offerings_search.php`,
        `admin/course_enrollments.php`, `semesters.php`**: added `'dean'`
        to each file's existing `$isReadOnly` flag (previously
        `university_rector`-only on most of these) — every one of these
        files already had every write UI element and the POST dispatch
        guard gated on this single flag from the earlier Rector
        conversion, so this was a one-line change per file plus a scope-
        banner wording tweak ("— view only") on each. No separate
        per-action gating needed since the existing guard is centralized.
      - **`admin/student_view.php` / `admin/lecturer_view.php`**: added
        `'dean'` to `require_role()` plus a new server-side check
        rejecting a `student_id`/`lecturer_id` outside the Dean's own
        `$_SESSION['faculty_id']` (the same "View Profile" pages
        University Rector/Head of Academic Affairs already use) — a Dean
        now gets the identical photo-hero/info-tile detail page for their
        own faculty's people, not just the flat list.
      - **`includes/attendance_helpers.php`'s
        `user_can_write_course_attendance()`**: Dean's branch (previously
        a real `course_offerings`/faculty query granting write access)
        replaced with an unconditional `return false;`, matching
        University Rector's own branch immediately above it — this is the
        actual security boundary both `attendance.php`'s grid-disabling
        and `ajax/save_attendance_cell.php`'s real save-time check both
        depend on, so fixing it here closes the write path everywhere at
        once rather than needing a per-page patch.
      - **`attendance_import.php`**: Dean removed from `require_role()`
        entirely (was `['dean', 'lecturer']`, now `['lecturer']`) — this
        page is a pure write action (bulk historical-attendance import)
        with no read-only equivalent, same treatment University Rector
        already got when *it* was converted (removed from every bulk-
        import page rather than shown a non-functional read-only version).
        `includes/nav_items.php`'s "Import Attendance" entry updated to
        match (`roles` narrowed to `['lecturer']`).
      - **`lecturer_courses.php`("Assign Courses")**: this page was a
        known, previously-documented gap from the original Rector
        conversion — its own `role_may_edit_faculty()` helper still
        granted write access to *both* University Rector and Dean within
        their respective allowed faculties, since it was out of scope for
        that earlier session. Fixed for Dean only (per this session's
        explicit "don't touch Rector" instruction): Dean's branch now
        unconditionally `return false;` instead of `$facultyId ===
        $deanFacultyId`, and the entire "Assign to a New Course" form
        column is now hidden for Dean (`$role !== 'dean'`), with the
        teaching-list table widened to `col-lg-12` in its place. University
        Rector's own `return true;` fallthrough in that same function is
        untouched — confirmed by inspection, not just by not editing that
        line, since the fix was scoped to only add a Dean-specific branch.
      - **Deliberately left unchanged, by design, matching existing
        precedent**: `notifications.php`'s "Notify" action and
        `messages.php` (Staff Messages) — both were explicitly kept
        available to University Rector during its own read-only conversion
        (treated as escalation/communication actions, not academic-data
        editing, per that session's own reasoning), so the same reasoning
        was applied to Dean here without re-litigating it. `reports.php`
        and `lecturer_checkins.php` needed no changes — both were already
        pure read-only reporting surfaces for every role that reaches them,
        Dean included.
      - **Verified end-to-end via real HTTP requests** against the live app
        with a temporary Dean account (Informatics faculty) and a separate
        temporary University Rector account (regression check): confirmed
        every converted page (Students/Lecturers/Courses/Departments/
        Semesters) renders 200 with zero PHP warnings/notices/fatals and no
        Add/Edit/Delete/Create UI visible for Dean (two apparent "write
        button" text matches on Courses/Semesters were verified by
        inspecting the actual surrounding HTML and found to be a harmless
        JS comment and a static page-subtitle sentence, not real buttons —
        the actual "Save All Semesters" button was independently confirmed
        `disabled`); confirmed 6 different crafted write POSTs (delete
        student, delete lecturer, create course, create semester, assign
        lecturer to a course, and a raw `ajax/save_attendance_cell.php`
        attendance save using a real course/session/semester combination)
        were all rejected with zero DB change — the attendance one
        specifically re-verified by reading the target row back from the
        database and confirming its `recorded_by_user_id`/timestamp
        matched a pre-existing real value, not the crafted request's;
        confirmed `admin/student_view.php`/`admin/lecturer_view.php` serve
        the Dean's own faculty's people normally (200) while a crafted
        request for a real student/lecturer in a *different* faculty
        (Health/Business Administration) is rejected with a redirect, not
        the record's data; confirmed `admin/course_offerings.php`'s
        `save_offering` and `admin/course_enrollments.php`'s `bulk_enroll`
        both reject a crafted POST the same way. **Regression-checked
        every University Rector page this session touched or could have
        touched** (Dashboard, Students, Lecturers, Courses, Departments,
        Faculties, Academic Years, Semesters, Attendance, User Management,
        Settings, Reports, Notifications, Lecturer Check-Ins, Messages) —
        all 15 returned 200 with zero PHP issues, `admin/settings.php`'s
        Danger Zone (a University-Rector-only full-CRUD area) was confirmed
        still present, and the Students page's scope-banner text was
        byte-compared to confirm it still reads exactly "Access scope:
        Full system — view only (oversight)" with no wording drift from
        the Dean-specific banner changes made alongside it. Both temporary
        accounts were deleted afterward; `users` count confirmed back to
        baseline.

### Academic Year Labels: Bare Year Instead of "YYYY/YYYY"
- [x] Per explicit request, `academic_years.label` moved from a "2023/2024"
      range format to a plain single year ("2023") everywhere in the
      project. Investigated first (grepped for any code parsing/splitting
      the label on "/") — confirmed nothing does; the label is read
      everywhere as an opaque display string (dropdown text, breadcrumbs,
      `assets/js/semester_label.js`), so this was a safe, purely
      cosmetic/input-format change, not a structural one.
      - `mysqldump` safety backup taken first, per this file's own
        convention. The 3 real existing rows updated in place
        (`2025/2026`→`2025`, `2023/2024`→`2023`, `2024/2025`→`2024`, taking
        the start year) — confirmed no collision, confirmed
        `ORDER BY label DESC` still sorts correctly since the new bare-year
        strings compare the same way the old range strings did.
      - Updated the "e.g. 2026/2027" placeholder text on both Add Academic
        Year forms (`admin/academic_years.php`, the University-Rector-only
        CRUD page; `head_academic/academic_settings.php`'s own copy) to
        "e.g. 2026" — no format validation exists on either form (free text,
        20-char max), so this is a hint-text change only, not a new
        constraint.
      - Updated the sample data row in both Excel import template
        generators (`admin/students_import.php`, `admin/courses_import.php`)
        from "2025/2026" to "2025" for consistency — the import logic itself
        already matches Academic Year by label text lookup regardless of
        format, so this needed no logic change, only the example text
        shown to whoever downloads the template.
      - Left untouched, deliberately: historical/narrative comments
        elsewhere in this file and in a few `.php`/`.sql` files that mention
        "2023/2024" while describing a past session's real data at the time
        it was written — dated record, not live logic.

### Registration Office: Enrollment Template Fields (Add Student + Import Students)
- [x] Extended both of Registration Office's student-creation surfaces —
      the manual "Add Student" form and "Import Students" bulk Excel
      import on `admin/students.php`/`admin/students_import.php` — to
      capture the university's real paper/Excel "Enrollment" form's full
      field set (the user's own `Downloads/Enrollment (2).xlsx`), not just
      the identity/scope fields (name/faculty/department/academic year)
      these two pages captured before. Investigated the real file directly
      (read via PhpSpreadsheet, since the Read tool can't open binary
      `.xlsx`) rather than guessing at its columns: Student ID Number,
      Student Name, Mother Name, Sex, Birth Date, Street Addres [sic],
      Student Phone, Student Email, Emergency Contact Name/Phone,
      Nationality, Enrollment Date, Certificate Type, School Roll Number,
      Degree, Faculty, Department, Program, Academic Year, Class Year — 20
      columns, header row only, no sample data in the real file.
      - **Schema** (`migrations/2026_08_students_enrollment_fields.sql`,
        mirrored into `admas_attendance_schema.sql`, `mysqldump` backup
        taken first): 14 new nullable `students` columns — `mother_name`,
        `sex` (`ENUM('male','female')`), `birth_date`, `street_address`,
        `phone`, `emergency_contact_name`, `emergency_contact_phone`,
        `nationality`, `enrollment_date`, `certificate_type`,
        `school_roll_number`, `degree`, `program`, `class_year`.
        Deliberately **not** added: "Student ID Number" (already
        `students.student_no` — confirmed with the user this stays the
        existing manually-typed, admin/Registration-entered required
        field, not auto-generated — matching this page's actual current
        behavior, not the older auto-generated scheme described in this
        file's own stale "Deferred Decisions" note), "Student Email"
        (reuses the existing `users.email`, no new column), "Faculty"/
        "Department"/"Academic Year" (already `faculty_id`/`department_id`/
        `academic_year_id`), "Shift" (already `students.shift` — not part
        of the real Enrollment form at all, added at the app layer only,
        per explicit instruction, since a course roster needs it).
      - **`admin/students.php`**: confirmed via `git diff` that Dean was
        already converted to read-only on this page in an earlier,
        uncommitted session (not something this session touched) —
        `registration` is the only write-capable role reaching any of this
        work. Per explicit instruction ("Student Name waa hal column"),
        the three separate First Name/Father's Name/Grandfather's Name
        inputs were replaced with **one** "Student Name" field, matching
        the Excel template's own single combined-name column — split
        server-side via a new shared `split_student_full_name()` helper
        (`includes/lecturer_accounts.php`, reusing the same word1/word2/
        remainder rule the original legacy-data name-split migration used)
        into the same 3 physical columns the schema already stores. All 14
        new fields added to the Add/Edit form as optional inputs (Sex as a
        select, Birth Date/Enrollment Date as date inputs, everything else
        free text) — positioned near the template's own field order. Both
        the `create` and `update` POST branches, and the `?edit=ID` GET
        pre-fill branch, extended to read/validate/persist all 14 new
        columns (Sex validated against the enum, Birth/Enrollment Date
        validated as `YYYY-MM-DD`, both loosely — everything else accepted
        as-is since it's free text). The stale "A student number, username,
        and temporary password will be generated automatically..." helper
        text (inaccurate even before this session — student_no has not
        been auto-generated on this page for a while) was corrected to
        "A username and temporary password will be generated automatically
        from the Student No...".
      - **`admin/students_import.php`**: header detection now tries a
        single "Student Name" column **first** (English/Somali synonyms),
        falling back to the older separate First Names/Father's Name/
        Grandfather's Name columns if no combined column is found — old
        import files keep working unmodified, new ones matching the real
        Enrollment template work too. The 14 new fields are **all optional
        on import** (nullable in the DB, matching the manual form), each
        with its own header-synonym detection; Sex is validated against
        Male/Female (also accepts M/F), Birth Date/Enrollment Date through
        a new tolerant `parse_import_date()` helper (handles both an
        already-formatted date string and a raw Excel date serial via
        `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()`),
        Student Email through the same format+uniqueness check the manual
        form uses. Semester and Shift remain required exactly as they
        already were before this session (Shift was **already** a required
        column here — the "add Shift yourself, it's not in the Enrollment
        template" instruction was already satisfied by this file's
        pre-existing design, confirmed by reading the code rather than
        assumed). The downloadable template regenerated to the full
        21-column set (20 Enrollment fields, using "Student No" instead of
        "Student ID Number" as the header text for consistency with the
        form, + Shift) with a realistic sample row. The Preview table was
        **deliberately narrowed**, not widened to 21 columns (Row/Student
        No/Student Name/Academic Year/Faculty/Department/Semester/Shift/
        Status only) — a 21-column preview table would be unreadable; a
        caption below the table clarifies the other 14 fields are still
        validated and imported, just not shown in this table. The `users`
        INSERT on confirm gained an `email` column (previously never set
        at all by this importer, even though the manual form always
        supported it).
      - **Verified end-to-end via real HTTP requests** against the live app
        with a temporary Registration Office account: full create → edit →
        update round-trip via `admin/students.php` with all 14 new fields
        populated, confirmed every value landed correctly in the database
        (including the name split: "Amina Hassan Ali" → first/father/
        grandfather correctly separated) and the update path correctly
        changed them; built a real `.xlsx` file matching the actual
        Enrollment template's exact header text (including its "Street
        Addres" typo, to confirm the synonym list tolerates it) plus a
        Semester and Shift column, with one fully-populated row and one
        row with only the required fields (everything optional left
        blank) — confirmed both previewed as "Ready to import", confirmed
        both imported successfully, confirmed the sparse row's optional
        columns landed as real `NULL`s (not empty strings) rather than
        blocking the import; downloaded and re-read the regenerated
        template with PhpSpreadsheet to confirm all 21 headers and the
        sample row. All temporary students, their linked `users` rows, and
        the temporary Registration account were deleted afterward;
        confirmed zero stray rows remained by an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Student Self-Registration ("Claim Your Account") — register.php
- [x] New public, no-session-required `register.php` — a student whose
      profile record Registration Office already entered (via the
      Enrollment-fields work above, Add Student or Import Students) can
      "claim" their account by proving it's really them, then choose their
      own username/password for the first time, instead of relying on the
      auto-generated placeholder credentials Registration Office never
      hands out. Planned via a back-and-forth clarification round (not
      Plan Mode — small enough in scope) before writing code, since the
      first-drafted version of this feature (a brand-new student signing
      themselves up from scratch) turned out to be wrong: the real design
      is account-claiming against an *existing* Registration-Office-created
      record, not fresh self-service creation.
      - **No name field, deliberately** (explicit instruction) — the 5
        lookup fields (Student No, Faculty, Department, Shift, Academic
        Year) together already uniquely identify the real record; the name
        is already on file and isn't needed to find it.
      - **Schema**: new nullable `students.self_registered_at TIMESTAMP`
        (`migrations/2026_08_students_self_registered_at.sql`, mirrored
        into `admas_attendance_schema.sql`, `mysqldump` backup taken
        first) — `NULL` means "Registration Office created this record but
        the student hasn't claimed it yet"; set once, the first (and only)
        time claiming succeeds.
      - **Lookup**: `SELECT id, user_id, self_registered_at FROM students
        WHERE UPPER(student_no) = ? AND faculty_id = ? AND department_id =
        ? AND shift = ? AND academic_year_id = ?` — all 5 fields must match
        the student's own real row exactly. Three outcomes, each its own
        plain result page (same visual shell as `forgot_password.php`'s
        card, reused for consistency): **no match** → "Account Not
        Registered" (generic — doesn't reveal which of the 5 fields was
        wrong, same enumeration-safety spirit as the Forgot Password flow);
        **match found but `self_registered_at` already set** → "Already
        Registered", directs to the real login page; **match found,
        unclaimed** → the claim succeeds.
      - **On successful claim**: new username validated the same way
        `*/profile.php`'s existing "Change Username" action already does
        (`/^[a-zA-Z0-9._-]+$/`, uniqueness against `users`) — reusing the
        exact regex rather than inventing a new rule. New password: same
        8-character minimum + confirmation-match rule used everywhere else
        in this app. On success, in one transaction: `UPDATE users SET
        username = ?, password_hash = ?, must_change_password = 0 WHERE id
        = ?` (overwriting the placeholder Registration Office never
        shared) and `UPDATE students SET self_registered_at = NOW() WHERE
        id = ?` — then the student is logged straight in (the exact same
        5 `$_SESSION[...]` keys `login.php` itself sets) and redirected to
        `student/dashboard.php`, no separate "now go log in" detour.
      - **`login.php`**: added a plain "New student? Register" link under
        the Sign In button, pointing at `register.php`.
      - **Verified end-to-end via real HTTP requests** against the live
        app: created a temporary Registration Office account and, through
        the real `admin/students.php` Add Student form, a real student
        record (Student No `QA-SELFREG-001`, with its auto-generated
        placeholder username/password never used further); confirmed a
        crafted register attempt with one field deliberately wrong
        (department) rendered "Account Not Registered" with zero DB
        change; confirmed the correct 5-field combo plus a
        self-chosen username/password succeeded — landed directly on
        `student/dashboard.php` showing "Welcome back, Deeqa Farah Yusuf",
        confirmed in the database that `self_registered_at` was set,
        `must_change_password` was `0`, and the new password hash actually
        verifies against the chosen password; **specifically tested the
        one-time/hijack-resistance guarantee**: replayed the exact same
        correct 5-field combo with a *different* chosen username/password
        (`hijack_attempt`) and confirmed it was rejected with "Already
        Registered", the real account's username stayed `deeqa_new`
        (unchanged), and zero `hijack_attempt` row was ever created;
        finally confirmed a real login with the self-chosen username/
        password succeeded and reached the dashboard. All temporary
        accounts/students were deleted afterward; confirmed zero stray
        rows remained by an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Auto-Generated Student Credentials: "DepartmentCode-StudentNo" for Both Username and Password
- [x] Replaced the auto-generated credential scheme used when Registration
      Office creates a student account (`admin/students.php`'s Add Student
      form and `admin/students_import.php`'s bulk import) — previously a
      name-based username (e.g. "amina01") with the bare Student No as the
      password. Per explicit request, both the username *and* the password
      are now the exact same single value: `"{DepartmentCode}-{StudentNo}"`
      (e.g. `"IT-1472/23"`, where `IT` is the student's own department's
      existing `departments.code` — confirmed against real data that this
      column already existed and already held short codes like `IT`/`NS`/
      `BF`, so no schema change was needed here at all).
      - **New shared `student_credential_value(string $departmentCode,
        string $studentNo): string`** in `includes/lecturer_accounts.php` —
        just concatenates `"{code}-{studentNo}"`. No collision-retry logic
        needed (unlike the old `generate_student_username()`, which this
        replaces at every call site): `students.student_no` is already
        enforced globally unique, so prefixing it with a department code
        can never collide either — documented directly in the function's
        own docblock so a future reader doesn't wonder why the retry loop
        is missing.
      - **`admin/students.php`**: the existing department-belongs-to-
        faculty validation query was widened to also `SELECT code` (no
        extra query needed) and used for the create branch's
        username/password generation. The **Reset Password** action was
        also updated to regenerate the same `"DepartmentCode-StudentNo"`
        value (previously just the bare student_no) — its own SELECT
        gained a `JOIN departments d ON d.id = s.department_id` to fetch
        the code. Both the Student No field's inline help text and the
        "will be generated automatically" note near the submit button were
        reworded to describe the new scheme.
      - **`admin/students_import.php`**: `$existingDepartments`'s query
        widened to `SELECT code` too, feeding a new `$departmentCodeById`
        lookup map; each preview row now carries its own `department_code`
        (resolved from the row's already-matched `department_id`), used by
        the confirm/import branch the same way. The upload-instructions
        text updated to match.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary Registration Office account: created a real
        student (Student No `1472/23`, Informatics/Information Technology)
        through the actual Add Student form and confirmed the success
        message showed exactly `Username: IT-1472/23 — Temporary Password:
        IT-1472/23`; confirmed a real login with those exact credentials
        succeeded (redirected to the forced first-login password-change
        page, as every newly admin-created account already does — this
        session didn't touch that unrelated behavior); imported a second
        student (`1473/23`) via a real `.xlsx` file through the actual
        bulk-import flow and confirmed in the database that both the
        stored username and the password hash matched `IT-1473/23` exactly
        (`password_verify()` against the real hash, not just "a row
        exists"); used the real "Reset Password" button against the first
        student and confirmed the flash message and the regenerated
        password both read `IT-1472/23` again. All temporary
        students/accounts were deleted afterward; confirmed zero stray
        rows remained by an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### User Management: Student ID Column + Deterministic Reset Password
- [x] Follow-up to the credential-scheme change above, on both User
      Management pages (`admin/users.php`, University Rector; and its
      near-identical twin `head_academic/users.php`) — the "System Users"
      table had no way to see a student's Student No at all, and
      "Reset Password" on either page generated a random password (via
      `generate_temp_password()`) for *every* role including students,
      inconsistent with `admin/students.php`'s own Reset Password (already
      switched to the deterministic `"DepartmentCode-StudentNo"` scheme in
      the entry above).
      - **New "Student ID" column** on both tables' `$allUsers` query
        (`LEFT JOIN students s ON s.user_id = u.id`, selecting
        `s.student_no`) — renders the value for student rows, an em dash
        for every other role.
      - **Reset Password now matches** for a student row on either page:
        `admin/users.php`'s handler widened its lookup query
        (`LEFT JOIN students`/`departments`) to also fetch `role_name`/
        `student_no`/`department_code`; `head_academic/users.php`'s shared
        `load_manageable_user()` helper (used by both its `reset_password`
        and `toggle_status` branches) widened the same way, returning the
        three new fields alongside its existing `username`/`status`. Both
        now call the same `student_credential_value()` helper (from the
        credential-scheme session above) when the target is a student with
        a linked `students` row, falling back to the existing random
        `generate_temp_password()` for every other role — unchanged
        behavior for Dean/Head of Academic Affairs/Registration
        Office/Lecturer resets.
      - **Verified end-to-end via real HTTP requests** against the live
        app: created a temporary Registration Office account and, through
        the real Add Student form, a real student (`UID-TEST-01`,
        Informatics/Information Technology → username `IT-UID-TEST-01`);
        confirmed both `admin/users.php` (as a temporary University
        Rector) and `head_academic/users.php` (as a temporary Head of
        Academic Affairs) render the new "Student ID" column showing
        `UID-TEST-01` on that row, zero PHP warnings/notices/fatals on
        either page; used the real Reset Password button on
        `admin/users.php` and confirmed the flash message read exactly
        `IT-UID-TEST-01` — matching the visible username and Student ID
        columns exactly, not a random string. All temporary
        accounts/students were deleted afterward; confirmed zero stray
        rows remained by an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Reset Password Now Also Normalizes a Student's Username (not just password)
- [x] Real bug the user caught live: tried logging in as a real, pre-existing
      student using the new `"DepartmentCode-StudentNo"` format
      (`BF-BUS25-015`) and got "Invalid username, password, or role".
      Root-caused via direct DB lookup (not guessed): that student's real
      row (`student_no = "BUS25-015"`, department `BF`) was created
      *before* this session's credential-scheme change, so its `users.username`
      was still the old name-based value (`abdinasir15`) — the new format
      only ever applied going forward, to accounts created *after* the
      change. This also exposed a **second, related bug** in the Reset
      Password action itself: all three Reset Password call sites
      (`admin/students.php`, `admin/users.php`, `head_academic/users.php`)
      already regenerated a student's *password* to the new
      `"DepartmentCode-StudentNo"` value, but never touched the
      *username* — so resetting an old-style account would have produced a
      mismatched pair (old username, new-format password), unusable with
      either the old or the new credentials.
      - **Fix, all three files**: Reset Password for a student now updates
        **both** `username` and `password_hash` to the same
        `student_credential_value()` result in one `UPDATE`, converging any
        pre-existing account onto the new scheme the moment it's reset —
        exactly the tool a real admin would use to fix this for a real
        student. The flash message wording changed from "New temporary
        password: ..." to "New username and temporary password: ..." on
        student resets specifically, so it's clear both changed; every
        other role's reset (still a password-only, randomly-generated
        value) keeps its original wording unchanged.
      - **Verified end-to-end via real HTTP requests**, using a disposable
        test student built to reproduce the exact reported scenario
        (a `users` row seeded directly with an old-style username
        `oldstyle_username_01`, linked to a real `students` row with
        `student_no = "LEGACY-99"` in the `IT` department) rather than
        touching the real reported account — confirmed the real Reset
        Password button on `admin/users.php` produced the flash message
        "New username and temporary password: IT-LEGACY-99"; confirmed a
        login attempt with the *old* username/password was now correctly
        rejected ("Invalid username, password, or role"); confirmed a
        login with `IT-LEGACY-99` used as *both* the username and password
        succeeded and reached `student/profile.php` showing the correct
        name. Temporary student/account deleted afterward; confirmed zero
        stray rows remained.
      - **Left for the user to action, not touched myself**: the real
        student account this bug report was about (`abdinasir15`,
        Student No `BUS25-015`, Business and Finance) still has its old
        username — deliberately not reset from here, since that's a real
        production account and Reset Password is a real, user-visible
        action (shows a new credential meant to be handed to the actual
        student) I shouldn't trigger unprompted. Using the real "Reset
        Password" button on either `admin/students.php` or
        `admin/users.php` for that specific student will convert it to
        `BF-BUS25-015` for both username and password.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Enrollment Fields Made Required + Beautified Viewer UI for the 14 New Fields
- [x] Two follow-ups to the Enrollment Template feature, both requested
      together: (1) the 14 fields added in that earlier session (Mother's
      Name, Sex, Birth Date, Street Address, Phone, Emergency Contact
      Name/Phone, Nationality, Enrollment Date, Certificate Type, School
      Roll Number, Degree, Program, Class Year) were left optional at the
      time — the user clarified this is real registration data every
      student must have on file, not optional extra detail, so both
      `admin/students.php`'s Add/Edit Student form and
      `admin/students_import.php`'s bulk import now require all 14 (plus
      the pre-existing Student Email field, also part of the real
      Enrollment form). (2) University Rector, Head of Academic Affairs,
      and Dean (each already able to reach a student's "View Profile"
      page, per prior sessions' read-only-viewer conversions) previously
      couldn't see any of these 14 fields at all — `admin/student_view.php`
      only ever showed the original 9 basic profile fields. Both changes
      only affect Registration Office's own write surfaces and the three
      roles' shared read-only detail page — no other page reads or writes
      these columns.
      - **`admin/students.php`**: removed every "(optional)" label suffix
        and added `required` to all 14 fields' HTML inputs; server-side
        validation extended with 14 new `elseif` branches (one per field,
        each rejecting a blank value with its own specific message,
        e.g. "Mother's Name is required.") plus widened the pre-existing
        email check from "valid if given" to "required and valid" — a
        blank submission for any of these now re-renders the form with a
        specific error instead of silently saving with a `NULL`.
      - **`admin/students_import.php`**: 14 new `elseif` branches added to
        the per-row validation chain (inserted right after the existing
        Shift check, before Academic Year/Faculty/etc. get resolved),
        each flagging "Missing {Field}" on the preview row's status badge
        for a blank cell — a file missing one of these columns entirely
        now has every row correctly flagged rather than silently importing
        with blanks. The upload-instructions text and the preview table's
        explanatory caption were reworded from "optional, imported when
        present" to describe every column as required. The downloadable
        template itself needed no changes — it already had all 21 columns
        with a fully-populated sample row from when it was first built.
      - **`admin/student_view.php`**: SELECT widened to fetch all 14
        columns; three new `.admas-card` sections added right after the
        existing Profile Information card — **Personal Details** (Mother's
        Name, Sex, Birth Date, Nationality), **Contact Details** (Street
        Address, Phone, Emergency Contact Name/Phone), and **Enrollment
        Details** (Enrollment Date, Certificate Type, School Roll Number,
        Degree, Program, Class Year) — each using the same `.info-tile`
        card-grid pattern the existing Profile Information section already
        uses, so the new sections match the page's established visual
        language rather than introducing a new one. For the "qurxi" (make
        it nicer) half of the request, added three new color-variant CSS
        rules to `assets/css/app.css` (`.section-heading.accent-navy`,
        `.accent-green`, `.accent-amber`, each tinting that section's own
        icon chip) so the three new stacked sections read as visually
        distinct groups at a glance instead of three identical repeated
        sky-blue icons — reusing the same `rgb(... / var(--admas-tint-
        opacity))` tinting technique already established by the KPI card
        accent colors elsewhere in the app, so both light and dark mode
        stay correct with no new hardcoded colors. Blank/legacy values
        (pre-existing students who predate this data, or a future manual
        DB edit) render as a plain em dash rather than an empty tile.
      - **Verified end-to-end via real HTTP requests** against the live
        app with temporary Registration Office, University Rector, Head of
        Academic Affairs, and Dean accounts (Dean scoped to the same
        Informatics faculty as the test student, so their own-faculty
        restriction wouldn't block the view): confirmed a create POST
        missing all 14 new fields was rejected server-side with zero DB
        row created; confirmed a create POST with all 14 fields (plus the
        existing identity/scope fields) populated succeeded and every
        value landed correctly in the database; confirmed all three viewer
        roles' `admin/student_view.php` requests returned 200 with zero
        PHP warnings/notices/fatals and the raw HTML contained the new
        "Personal Details"/"Contact Details"/"Enrollment Details" section
        headings plus the real submitted values (e.g. "Khadija Nuur",
        "Garowe, Puntland", "HS-2025-014"); built a real `.xlsx` file (a
        Semester column had to be added to the ad-hoc test file to satisfy
        this importer's own pre-existing Semester requirement — unrelated
        to this session's changes) with one fully-valid row and one row
        with a blank Mother's Name, confirmed the preview correctly showed
        "Ready to import" vs. "Missing Mother's Name", and confirmed
        Confirm imported exactly the valid row (all 14 fields verified in
        the database) while the invalid row was never created. All
        temporary students, their linked `users` rows, and all four
        temporary accounts were deleted afterward; confirmed zero stray
        rows remained via an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### admin/students_import.php: Inline "Edit Row" in Preview + Old Import Format Removed
- [x] Two follow-ups, confirmed via `AskUserQuestion` before writing code
      (the second half of the request was ambiguous — "remove from the
      database so it accepts the old import files" — clarified as: stop
      accepting the old-format files entirely, not the opposite):
      1. When a preview row has an error, Registration Office can now fix
         it right there via a new **Edit** button/modal — no more needing
         to fix the source Excel file and re-upload the whole thing.
      2. The older separate "First Names"/"Father's Name"/"Grandfather's"
         column format (a fallback kept from before the single-column
         Enrollment template existed) is no longer accepted at all — only
         the new "Student Name" single-column format is.
      - **Refactor enabling both**: extracted the entire per-row validation
        chain into a new shared `validate_student_import_row()` function
        (takes raw field strings + the lookup maps + a
        `$otherStudentNosInFile` duplicate-check set, returns the same
        preview-row shape used everywhere else) — previously this logic
        only existed inline inside the file-parsing loop. The parsing loop
        itself is now two passes: pass 1 just extracts every row's raw
        cell values (no validation), pass 2 calls the shared function once
        per row, building each row's "every OTHER row's Student No" set
        from the *complete* file rather than only rows already seen —
        fixing a latent asymmetry in the old single-pass version (a
        duplicate would only be flagged on the *second* occurrence, never
        retroactively on the first).
      - **New `edit_row` POST action**: takes `row_index` + the same field
        names as the original form, re-runs the identical
        `validate_student_import_row()` the initial parse used (so a
        corrected row can never be validated more leniently than a fresh
        upload would), and replaces just that one row in
        `$_SESSION['student_import_preview']` — the page re-renders the
        preview step directly (no redirect), so the corrected row's new
        status is visible immediately without a re-upload.
      - **Edit modal** (`admin/students_import.php`'s preview step): one
        shared Bootstrap modal (not one per row, to keep the markup light)
        populated by a new `admasOpenEditRow(index)` JS function reading
        from a `previewRowsData` array embedded as JSON — every field is
        editable (text/date inputs, Sex/Shift/Academic Year selects,
        Faculty→Department/Semester cascading selects reusing the same
        `editDepartmentsByFacultyId`/`editSemestersByFacultyId` JS-map
        pattern already used elsewhere in this app). If a row's saved
        Faculty/Department/Semester text doesn't match any real option
        (e.g. exactly the kind of typo that caused the original error), a
        "(not recognized)" fallback option is inserted and pre-selected so
        the user can see what was actually there instead of the dropdown
        silently defaulting to blank. Each row got a new "Edit" button in
        a new Actions column; the preview's explanatory caption text was
        updated to describe every field (not just the 8 shown columns) as
        editable.
      - **Old-format removal**: `$firstNameCol`/`$fatherNameCol`/
        `$grandfatherNameCol` header detection and the whole "else" branch
        that read them were deleted; `$missingRequired` now requires
        `$studentNameCol !== false` outright (no more OR-fallback); the
        `$looksLikeHeaderRow` banner-skip helper's name-candidate list
        dropped the old "first name(s)" synonyms, keeping only "Student
        Name" ones; the upload-instructions alert and the missing-columns
        error message were reworded to state the old format is no longer
        accepted.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary Registration Office account: uploaded a file
        with one row carrying a typo'd Faculty name ("Infromatics-TYPO"),
        confirmed the preview correctly flagged it `Unknown faculty
        "Infromatics-TYPO"`; submitted a real `edit_row` POST correcting
        just the Faculty field (all other fields re-submitted unchanged)
        and confirmed the response showed a success message plus the
        row's status flipped to "Ready to import" — all without a second
        file upload; ran Confirm and verified in the database that the
        corrected row imported with every field correct (Faculty/
        Department/Semester IDs, Sex, Program, etc.); separately built and
        uploaded a file in the old three-separate-name-column format (no
        "Student Name" column at all) and confirmed it was now rejected
        outright with the updated error message, instead of silently being
        accepted via the old fallback. All temporary students, their
        linked `users` rows, and the temporary account were deleted
        afterward; confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### admin/student_view.php: Two-Card Side-by-Side Layout
- [x] Redesigned the viewer detail page's layout, per explicit request —
      mocked up first as a published Artifact (approved before touching
      real code, per this session's own working pattern): the previous
      session's Personal Details/Contact Details/Enrollment Details/
      Courses & Attendance (4 separate stacked full-width cards below
      Profile Information) consolidated into **two cards side by side** —
      **Registration Details** (all of Registration Office's own entered
      data, grouped into Personal/Contact/Enrollment mini-sections inside
      one slim card) and **Courses & Attendance** (the semester box picker
      moved inside this card, directly above a compact row-list of
      courses instead of a wide table) — while **Profile Information**
      stays the one full-width card above them, now visually the "wide"
      card by contrast with the two half-width ones below it.
      - Bootstrap's own `row`/`col-lg-6` grid (collapsing to one column
        under `lg`) does the two-column split — no custom grid CSS needed.
      - New CSS in `assets/css/app.css`: `.reg-group`/`.reg-group-title`/
        `.reg-field-label`/`.reg-field-value` for the three grouped
        mini-sections (a dashed divider between each), and `.course-row`/
        `.course-main`/`.course-name`/`.course-lecturer`/`.course-score`/
        `.course-score-muted` for the new compact course list (course
        code pill + name/lecturer + a trailing attendance-score pill),
        replacing the old `admas-table` for this specific card since a
        half-width card is too narrow for a comfortable 4-column table.
        All new rules read from the existing theme CSS variables, so both
        light and dark mode stay correct with zero new hardcoded colors.
      - The semester-box-picker markup itself is unchanged (same PHP
        logic, same button styling) — only its position moved, from its
        own standalone card into the top of the Courses & Attendance card.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary University Rector account and a temporary
        student (Informatics/Information Technology) carrying full
        Enrollment data: confirmed the page returns 200 with zero PHP
        warnings/notices/fatals and exactly 3 `section-heading` blocks
        (Profile Information, Registration Details, Courses & Attendance
        — down from 5 before this change); confirmed the empty-course
        state ("No courses recorded for this semester.") renders
        correctly; then added a real course enrollment + a real attendance
        mark for the same student and confirmed the new `.course-row`
        markup rendered correctly with the course code pill, course name,
        lecturer, and a real "1 / 10" attendance-score pill in the correct
        badge color. All temporary attendance/enrollment/student/user rows
        were deleted afterward; confirmed the database back to its exact
        pre-test baseline.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Semester Box Picker Beautified + "Which Semester" Banner on Every Dashboard
- [x] Two requests: (1) beautify the "Semester N" box picker used on
      `admin/student_view.php` and `student/courses.php`. (2) every
      dashboard should carry a visible message stating which semester its
      KPIs/charts reflect. The user also referenced a screenshot of
      `student/attendance_history.php`'s "semester-card" (green left-accent
      card with a "Current" badge, date range, and a per-course attendance
      table) asking what it was and where it's meant to be used — answered
      directly: that page already exists in the app (Student role only,
      "My Attendance History" in the sidebar, one card per semester the
      student has ever had marks in) and was the visual reference point for
      this session's polish, not something newly built.
      - **Semester Box Picker redesign**: replaced the old plain
        `<a class="btn btn-sm">` boxes (inline `style=` toggling between a
        solid-fill "selected" state and a bordered "unselected" one, plus
        appending literal " (current)" text) with new shared CSS classes in
        `assets/css/app.css` — `.semester-picker`/`.semester-picker-label`
        (container + small uppercase label), `.sem-box`/`.sem-box-active`
        (rounded pill, sky-blue outline, solid-fill + shadow when selected),
        `.sem-box-dot` (a small green live-dot prefixing whichever box is
        this viewer's own actual current semester — replacing the old
        appended "(current)" text with the same kind of live-status
        indicator already used elsewhere in the app, e.g. the notification
        bell), and `.sem-box-disabled` (dashed border, muted, for a
        semester number with no real row created yet). Applied to both
        `admin\student_view.php` and `student\courses.php` — the
        underlying PHP logic (`$semesterBoxes`, `$filterSemesterId`,
        `$myCurrentSemesterId`) was not touched at all, only the markup.
      - **New `.semester-scope-banner`** (green-tinted, same shape as the
        existing sky-blue `.scope-banner` but visually distinct so it
        reads as a second, different kind of notice) added to all 5
        dashboards that have real semester-scoped data:
        - `student/dashboard.php`: "Showing: {name} (current)" — the
          student's own resolved current semester, reusing the
          `$ownCurrentSemester` variable this page already computed.
        - `dean/dashboard.php`: "Showing: {name} (current, {Faculty})" —
          or "No current semester set for {Faculty} yet" when the dean's
          own faculty has none, reusing the existing `$deanCurrentSemester`.
        - `head_academic/dashboard.php` and `admin/dashboard.php`
          (University Rector): both university-wide with no single
          "current semester" (each faculty runs its own independent
          track, an intentional capability from an earlier session) — the
          banner instead lists every faculty's own current semester by
          name ("Business Administration (Semester 1), Health (Semester
          1), Informatics (Semester 3)"), built from a new
          `$currentSemesterByFacultyName` map populated inside each file's
          own pre-existing per-faculty `get_current_semester()` loop
          (the Attendance-by-Faculty chart loop on `admin/dashboard.php`,
          the equivalent loop on `head_academic/dashboard.php`) rather
          than adding a second query loop.
        - `lecturer/dashboard.php`: a lecturer's own courses can span more
          than one faculty/semester at once (an existing, documented
          capability), so a single semester value would be misleading —
          the banner instead points at the "My Assigned Courses" table's
          own pre-existing Semester column ("Each course below shows its
          own current Semester — see the Semester column").
      - **Deliberately not added to `registration/dashboard.php`** — its
        KPIs (Total Registered Students, Faculties, Departments, Added
        This Month) and Recent Registrations table have no semester-scoped
        data at all (Registration Office has no Attendance access per
        CLAUDE.md §4), so a semester banner here would be fabricated
        rather than informative.
      - **Verified end-to-end via real HTTP requests** against the live
        app with 5 temporary accounts (dean, head_academic,
        university_rector, lecturer, student) plus a temporary
        lecturer/student fixture: confirmed all 5 dashboards return 200
        with zero PHP warnings/notices/fatals and the exact expected
        `.semester-scope-banner` text for each role (dean showed "Semester
        3 (current, Informatics)"; head_academic/university_rector both
        showed the same 3-faculty list; lecturer showed the "see the
        Semester column" note; student showed "Semester 3 (current)");
        confirmed both `student/courses.php` and `admin/student_view.php`
        render the new pill-shaped `.sem-box` markup with the green
        `.sem-box-dot` correctly appearing only on the viewer's actual
        current semester box. All temporary accounts/lecturer/student rows
        were deleted afterward; confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### student/dashboard.php: "My Course Attendance" Tap-Through Change — Built, Then Reverted
- [x] Built a "View Grid" tap-through (desktop table button + a full mobile
      card link) from the dashboard's "My Course Attendance" table to
      `student/xiiso_grid.php`, verified it end-to-end, then the user said
      "maya saaxib tirtir waxa aad hadda samaysay" (no, undo what you just
      did) immediately after. Reverted `student/dashboard.php` back to its
      original single always-visible table (no `d-md-none`/`d-none
      d-md-block` split, no per-row "View Grid" button/link) — confirmed via
      `php -l` that the reverted file is syntactically clean. No other file
      was touched by the reverted feature, so no further cleanup was
      needed. The two mobile screenshots that prompted this whole
      investigation were still confirmed to be a stale browser cache, not
      the live app (see the discussion right before this entry) — that
      finding stands regardless of this revert.

### Mobile: Profile Identity Moved from the Off-Canvas Sidebar into the Body
- [x] On mobile widths, the sidebar (and its `.sidebar-profile` Role/Photo/
      Name block) is off-canvas behind the hamburger — the profile photo
      was invisible until the menu was opened. Per explicit request, moved
      that identity strip out of the sidebar and into the body, always
      visible, for every role's every page (both partials are shared by
      every page in the app).
      - **`includes/topbar.php`**: new `.topbar-profile-strip` — a full-
        width row directly under the topbar (photo-or-fallback-icon, full
        name, role label), the whole row a link to that role's own
        `profile.php` (via the existing `role_folder()` helper). Computed
        from `$currentUser`/`current_role()`, both already available to
        this partial — no new query needed.
      - **`assets/css/app.css`**: `.topbar-profile-strip` is `display: none`
        by default (hidden on desktop, where the always-visible sidebar
        already shows this); the existing `@media (max-width: 991.98px)`
        block (the same breakpoint the sidebar already goes off-canvas at)
        now also sets `.sidebar-profile { display: none; }` and
        `.topbar-profile-strip { display: flex; }` — swapping which one is
        visible exactly at the point the sidebar becomes reachable only via
        the hamburger. Photo styling mirrors `.sidebar-profile-photo`
        (circular, sky-blue ring) at a smaller 40px size to fit the body
        strip.
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary student and dean accounts: confirmed both
        `student/dashboard.php` and `dean/dashboard.php` return 200 with
        zero PHP warnings/notices/fatals, and the rendered HTML contains
        the new `.topbar-profile-strip` with the correct name, correct role
        label ("Student"/"Dean"), the fallback person-icon (neither test
        account had an uploaded photo), and a link to that role's own real
        `profile.php`. Temporary accounts deleted afterward; confirmed zero
        stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Mobile Sidebar: University Logo + Name Restored (Above the Profile Block)
- [x] Follow-up to the profile-strip change above — with `.sidebar-profile`
      now hidden on mobile (moved into the topbar strip), the mobile
      sidebar drawer had no university branding left in it at all. Per
      explicit request, added a `.sidebar-mobile-brand` block (logo image +
      university name) at the very top of the drawer, mobile widths only —
      desktop is untouched, since its sidebar still opens straight on
      `.sidebar-profile` there.
      - **`includes/sidebar.php`**: now `require_once`s
        `includes/university_logo.php` and resolves the logo via the
        existing `get_university_logo_relative_path($settings)` helper
        (the same one `login.php`/`reports.php`/PDF exports already use —
        respects a Rector-uploaded replacement logo, not just the bundled
        default) and the name via `$settings['university_name']`, both
        guarded with `?? []`/`?? 'ADMAS University'` since `$settings` is
        set by the including page before this partial runs, not by this
        file itself.
      - **`assets/css/app.css`**: `.sidebar-mobile-brand` is `display: none`
        by default; the same `@media (max-width: 991.98px)` block already
        used for the profile-strip swap now also flips this one to
        `display: flex`.
      - **Verified end-to-end via a real HTTP request** against the live
        app with a temporary student account: confirmed
        `student/dashboard.php` returns 200 with zero PHP warnings/
        notices/fatals, and the rendered HTML shows the real logo path
        (`/logo/logo.jpg`, confirmed the file exists on disk) and "ADMAS
        University" inside `.sidebar-mobile-brand`. Temporary account
        deleted afterward; confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### admin/courses.php: Faculty/Department Filter, Compact Offerings, Pagination
- [x] Implemented after a "qiimeyn guud" (general assessment) the user asked
      for — read the real code and reported concrete gaps for a "real large
      university": the Courses list had only a free-text search (no
      Faculty/Department narrowing, unlike `admin/students.php`'s own
      established filter bar), no pagination at all (every course loaded
      and rendered unconditionally), and the "Current Offering" cell could
      grow dense once a course had several shift/semester offerings.
      Mocked up the fix as a published Artifact first (approved by the
      user before any code was touched), then built it.
      - **Faculty → Department cascading filter**: new `faculty_id`/
        `department_id` GET params, wired into `admin/students.php`'s exact
        pattern — a JS `updateCoursesFilterDepartmentOptions()` reusing the
        already-existing `departmentsByFacultyId` map (no new query),
        `admasInitLiveFilter('#coursesFilterForm')` already existed on this
        page for the search box and now also drives the two new selects.
        Dean's Faculty select is locked/disabled to their own faculty
        (never trusted from the request, same as every other write/read
        boundary in this file); a submitted `department_id` is dropped back
        to "All Departments" server-side if it doesn't actually belong to
        the resolved faculty, rather than silently cross-scoping.
      - **Pagination**: `COURSES_PER_PAGE = 20`, `LIMIT`/`OFFSET` added to
        the course query, a separate `COUNT(*)` query (sharing the same
        WHERE-clause/params array) to compute total pages, and a Bootstrap
        pagination control at the bottom that preserves the current
        faculty/department/search filters in each page link.
      - **Compact "Current Offering" cell**: the first semester group of
        offerings renders as before; a second and later group is wrapped in
        a `d-none` div with a "+N more offering(s)" toggle link (plain
        inline `onclick`, no new JS file) that reveals it — a course with
        many offerings (e.g. one real course with 3 distinct semester
        offerings) no longer stretches its row by default.
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary University Rector and Dean (Informatics) accounts,
        plus 15 disposable test courses added to push the real 8-course
        catalog to 23 (enough to actually exercise a second page):
        confirmed page 1 shows exactly 20 with a working pagination control,
        confirmed page 2 shows the remaining 3 ("Showing 21–23 of 23"),
        confirmed the Faculty filter correctly narrowed to exactly the 19
        real+test courses in Informatics, confirmed the Department filter
        narrowed the same way, confirmed the Dean's Faculty select rendered
        locked to "Informatics" only, and confirmed the two real courses
        with multiple offerings rendered "+2 more offerings" (id 22, 3
        offerings) and "+1 more offering" (id 5, 2 offerings) exactly as
        expected. Zero PHP warnings/notices/fatals throughout. All 15 test
        courses and both temporary accounts were deleted afterward;
        confirmed the real course count back to its exact baseline of 8.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Automatic Student Semester Promotion on Semester End
- [x] The user surfaced a real, serious gap through a good clarifying
      question: a student's own `semester_id` was never automatically
      advanced when their semester ended — nothing in the app ever moved a
      student from "Semester 5" to "Semester 6", so a student would keep
      seeing their old semester's courses indefinitely unless an admin
      manually opened Edit Student and changed the Semester dropdown, one
      student at a time. Confirmed via `AskUserQuestion`: the user wants
      this **fully automatic**, triggered the moment a semester's status is
      set to Ended — not a separate manual "Promote Students" page.
      - **New `includes/semester_helpers.php` functions**:
        `promote_students_to_next_semester(mysqli $conn, int
        $endedSemesterId): array` — parses the ended semester's name against
        the strict `"Semester N"` pattern (a custom-named semester is left
        alone, nothing to compute), resolves N+1 as `"Semester {N+1}"`
        within the *same faculty*, and `UPDATE`s every `status = 'active'`
        student whose `semester_id` matches. Three outcomes are
        distinguished and reported back: promoted (a real count), `final`
        (the semester's number already equals or exceeds the faculty's own
        `total_semesters` — those students are graduating, not advancing
        into a semester that doesn't exist by design), and `no_target` (the
        faculty's own "Semester N+1" hasn't been created yet — reports how
        many students are waiting, but does **not** silently drop them).
        `promote_students_from_previous_semester(mysqli $conn, int
        $newSemesterId): array` — the other half, closing the gap when
        "Semester N+1" gets created *after* "Semester N" already ended:
        looks for an `'ended'` `"Semester N-1"` in the same faculty and
        sweeps its still-active students into the just-created semester.
        Together the two mean promotion happens automatically regardless of
        which order End/Create happen in.
      - **`semesters.php` wiring**, three write paths: the single "End"
        button (`set_status` action) now fetches the semester's *previous*
        status first and calls `promote_students_to_next_semester()` only
        on a genuine `!ended -> ended` transition (never re-fires on a
        redundant re-click of an already-ended semester); "Save All
        Semesters" (`end_all_current`) now captures the list of semester
        ids about to be ended *before* the bulk `UPDATE` (which alone can't
        report which rows it touched), then loops the same promotion
        function over each and aggregates one combined flash message;
        `create_semester` calls `promote_students_from_previous_semester()`
        on the newly-inserted semester's id right after commit. All three
        surface a clear flash message either way — "N student(s)
        automatically promoted to 'Semester X'" or "N student(s) are
        waiting to be promoted to 'Semester X' — create that semester and
        they will be promoted automatically."
      - **Verified end-to-end via real HTTP requests** against the live app
        with a disposable temporary faculty (`total_semesters = 3`), two
        temporary students, and a temporary Head of Academic Affairs
        account: confirmed ending "Semester 1" (with "Semester 2" already
        existing) immediately promoted both students and showed the exact
        expected flash text; confirmed ending "Semester 2" with no
        "Semester 3" yet correctly reported both students "waiting" and
        left their `semester_id` untouched; confirmed creating "Semester 3"
        through the real `create_semester` action immediately swept both
        previously-waiting students into it, with a flash message
        confirming the retroactive promotion; confirmed ending "Semester 3"
        (the faculty's own final semester) triggered no promotion attempt
        and no error, leaving those students in place as expected
        (graduating, not advancing into a nonexistent "Semester 4").
      - **Testing mistake made and corrected during this same session,
        documented here rather than glossed over**: also exercised the
        bulk "Save All Semesters" path with a second temporary
        faculty/student/Head-of-Academic-Affairs fixture — but that
        action's own real scope (every currently-current semester,
        *university-wide*, for this role) meant the test call genuinely
        ended 4 real, currently-in-use production semesters (Informatics'
        "Semester 9" and "Semester 3", and two other faculties' "Semester
        1"s) alongside the disposable test one. Caught immediately by
        reading `semesters.php`'s own returned flash message (it named
        real semester numbers, not just the test one) rather than assuming
        success from the HTTP status alone; confirmed via direct query that
        **no real student rows were altered** (none of those 4 real
        semesters had an existing "next" semester for the promotion
        function to target, so the promotion half was a genuine no-op for
        all of them — only their `status`/`is_current` flags had flipped),
        then immediately reverted all 4 back to `status = 'current',
        is_current = 1` and re-verified via a direct row-by-row read that
        they exactly matched their pre-test values. **Lesson for future
        sessions**, matching the same one already recorded in this file's
        "Bulk Multi-Select Delete" entry: an action whose real scope is
        "every current X for this role" (not scoped to one disposable
        fixture) must never be exercised with a broad-scope role/account
        against live data — build a dedicated disposable faculty *and*
        confirm the action's own WHERE-clause scope before invoking it as
        that role, not after.
      - All temporary faculties/departments/semesters/students/accounts
        from both fixtures were deleted afterward; confirmed zero stray
        `QA-`/`qa_`-prefixed rows remained anywhere in the database.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### student/dashboard.php: Faculty/Department Added to the Semester Banner
- [x] Small follow-up: the `.semester-scope-banner` added earlier this
      session ("Showing: {Semester} (current)") now also appends
      `— {Faculty} / {Department}` on `student/dashboard.php` specifically,
      reusing the `$ownRow['faculty_name']`/`department_name` values this
      page already loads for its own Faculty/Department badge pills further
      down — no new query needed. Verified live with a temporary
      faculty/department/semester/student fixture: the banner rendered
      exactly "Showing: Semester 1 (current) — QA Banner Faculty / QA
      Banner Dept" with zero PHP warnings/notices/fatals. Temporary fixture
      deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### New: Teaching History (Lecturer)
- [x] The user asked for a Lecturer-facing "History" so they can look back
      at their own past teaching record. `lecturer/courses.php` ("My
      Courses") already lists a lecturer's full history across current/
      waiting/ended semesters, but it's a *management* view (filters,
      "Take Attendance" buttons) — not a retrospective look-back. Built
      `lecturer/teaching_history.php` as the lecturer's own counterpart to
      `student/attendance_history.php`, reusing that exact page's visual
      pattern (one card per semester, green left-accent + "Current" badge
      for the active one, other semesters getting a rotating sky/navy/amber
      accent) and its Export Excel/PDF buttons via the shared
      `includes/export_helpers.php` — no new export code needed.
      - **Query**: one row per (semester, course) this lecturer has ever
        held a real `course_offerings` row in, any status (current/
        waiting/ended) — a semester they're no longer assigned to still
        shows up as history, same "any offering" precedent
        `lecturer/courses.php` already established. Per course: Sessions
        Recorded (distinct regular Xiiso sessions with real attendance),
        Enrolled Students (via the shared `get_course_roster_count()`
        helper, same enrollment-then-department-fallback resolution used
        everywhere else), and Class Avg Score — the average of *each
        student's own* capped out-of-10 score for that course/semester
        (never a pooled ratio), the same semantics already established for
        every other score display in this app.
      - **Nav entry**: "Teaching History" added to `includes/nav_items.php`
        in the same `Attendance Management` group as the student's own
        "Attendance History" entry, `roles: ['lecturer']` only.
      - **Verified end-to-end via real HTTP requests** against the live app
        with a disposable temporary faculty/department/2-semesters(one
        ended, one current)/2-courses/lecturer/3-students fixture, with
        real attendance marked for one course in the ended semester only:
        confirmed the page returns 200 with zero PHP warnings/notices/
        fatals; confirmed both semester cards render with the correct
        "Ended"/"Current" badges; confirmed the marked course showed
        "2 / 10" sessions recorded, "3" enrolled, and "1.3 / 10" class
        average — hand-verified against the seeded data (one student
        marked absent both sessions, two marked present both — average
        (0+2+2)/3 = 1.3); confirmed the unmarked course in the current
        semester correctly showed "0 / 10" sessions and "—" (not a
        misleading 0) for the average; confirmed both Export Excel and
        Export PDF produced valid, correctly-typed files; confirmed the
        new "Teaching History" sidebar link renders with the correct URL.
        All temporary faculty/department/semesters/courses/lecturer/
        students/accounts were deleted afterward; confirmed zero stray
        rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### lecturer/teaching_history.php: Faculty/Department + Check-In Times
- [x] Follow-up per explicit request: added **Faculty**/**Department**
      columns per course row (resolved from the offering's own semester
      faculty + `roster_department_id`, falling back to the course's
      catalog department — same resolution `lecturer/courses.php` already
      uses, since a lecturer may hold a cross-listed/guest-faculty
      offering) and a **My Check-Ins** column showing how many of that
      course's 12 Xiiso the lecturer personally checked into (out of the
      existing `lecturer_checkins` table, a distinct record from
      attendance-marking — see `lecturer/checkin.php`), with a toggle link
      revealing the exact check-in/check-out time per session (e.g. "Xiiso
      1: Sep 5, 9:00 AM – 10:30 AM", or "(not checked out)" if still open).
      - New query joins `lecturer_checkins` → `sessions`, grouped by
        `(course_id, semester_id)` per lecturer, alongside the existing
        course/semester query (both were already being built from
        `course_offerings`, so no new page-load cost beyond the one extra
        query).
      - Export Excel/PDF columns extended to match: Faculty, Department,
        and a Check-Ins count column added alongside the existing
        Semester/Course/Sessions/Enrolled/Avg Score columns.
      - **Verified end-to-end via real HTTP requests** with a disposable
        temporary faculty/department/semester/course/lecturer fixture and
        two real `lecturer_checkins` rows (one fully checked out, one still
        open): confirmed the page returns 200 with zero PHP warnings/
        notices/fatals; confirmed Faculty/Department render correctly per
        row; confirmed the "2 / 10" check-in count and its toggle reveal
        both exact timestamps correctly, including the "(not checked out)"
        state for the still-open session; confirmed both Export Excel and
        Export PDF still produce valid files with the new columns. All
        temporary fixture rows were deleted afterward; confirmed zero
        stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### PDF Export for Every Student-Viewer Role (Head of Academic Affairs + Dean)
- [x] The user asked for PDF export to reach every role that views student
      data. `admin/export.php` already supported PDF, but only University
      Rector's plain export-card links used it — Head of Academic Affairs'
      own bulk "Export Students" button (added in an earlier session) was
      hardcoded to `format=excel` only, and Dean had **no** export
      capability on `admin/students.php` at all despite being a view-only
      "viewer" of their own faculty's students, same as the other two.
      - **`admin/export.php`**: `require_role()` widened to include `dean`;
        the existing "Head of Academic Affairs only reaches `type=students`"
        guard generalized to "any non-Rector role" (now also covers Dean).
        New `$deanFacultyId` (read from `$_SESSION['faculty_id']`, never
        the request) scopes the students query with `s.faculty_id = ?`
        whenever the requester is a Dean — combined with the existing
        `ids[]` selection filter via the same dynamic WHERE-array pattern
        used elsewhere in this app, so a Dean's export is always confined
        to their own faculty regardless of what's selected or crafted.
        `$scopeLine` (the header line shown in both the PDF and Excel
        files) now says "Own faculty export" for a Dean's unfiltered
        export instead of the misleading "University-wide export".
      - **`admin/students.php`**: `$showSelectCheckboxes` extended to
        include Dean (previously only non-read-only roles + Head of
        Academic Affairs), so Dean's rows now get the same select-all/
        individual checkboxes. The export form block (previously
        `head_academic`-only) now renders for Dean too, and gained a
        second submit button — both buttons share one `<form>`/one hidden
        `ids[]` container (populated on submit by the existing
        `admasInitBulkExport()` JS, unchanged), distinguished only by an
        HTML `formaction` attribute pointing at `format=excel` vs
        `format=pdf`, so no JS changes were needed for the second button.
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary University Rector, Head of Academic Affairs, and
        Dean (Informatics) accounts: confirmed Rector's own existing PDF
        export still works unchanged (regression check); confirmed Head of
        Academic Affairs' page now shows both an Excel and a PDF button and
        that the PDF export succeeds; confirmed Dean's page now shows both
        buttons and real row checkboxes; downloaded and ran `pdftotext` on
        both Dean's and Rector's exported PDFs to inspect actual content
        (not just HTTP status) — Dean's contained only "Informatics" and
        was labeled "Own faculty export", Rector's contained all three real
        faculty names and was labeled "University-wide export"; sent a
        crafted `ids[]` naming a real student from a *different* faculty as
        the Dean account and confirmed the resulting PDF's own table came
        back completely empty ("0 selected students"), not that student's
        data. All temporary accounts were deleted afterward; confirmed zero
        stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Head of Academic Affairs: Edit Lecturer
- [x] Per explicit request, added Edit to `head_academic/lecturers.php`
      (previously view + Register New Lecturer + bulk-delete only, no
      single-row Edit) — same `$formMode` create/edit toggle convention as
      `admin/lecturers.php`, updating `full_name`/`email`/`department_id`
      only (Staff No, username, and password stay untouched, matching
      every other Edit action in this app — this role has no Reset
      Password button here either, unchanged).
      - New `update_lecturer` POST branch mirrors `admin/lecturers.php`'s
        own `update` branch validation and transaction shape exactly
        (email-uniqueness excludes the record's own user id, department
        must exist), minus the Dean-only own-faculty ownership check,
        since this role is already university-wide with no faculty lock.
      - New `?edit=ID` GET pre-fill branch, and a new per-row "Edit"
        pencil-icon link added alongside the existing "Assign Courses"
        link. The "Register New Lecturer" card now doubles as "Edit
        Lecturer" (Staff No shown disabled/read-only in edit mode, a
        Cancel link added next to Update).
      - **Bug caught during live verification, fixed before considering
        this done**: the first version of the `?edit=ID` SELECT
        (`SELECT id, staff_no, full_name, email, department_id FROM
        lecturers l JOIN users u ...`) threw a real
        `Column 'id' in field list is ambiguous` fatal error, since both
        `lecturers.id` and `users.id` exist — fixed by qualifying every
        column (`l.id`, `l.full_name`, `u.email`), matching
        `admin/lecturers.php`'s own already-correct version of this exact
        query, which was used as the reference and should have been
        copied byte-for-byte from the start.
      - **Verified end-to-end via real HTTP requests** against the live app
        with a temporary Head of Academic Affairs account and a temporary
        lecturer record: confirmed the fatal error above, fixed it,
        re-confirmed `?edit=32` now returns 200 with zero PHP warnings/
        notices/fatals and the form correctly pre-filled with the real
        name/email; submitted a real update (new name, new email, same
        department) and confirmed via direct DB read that all three fields
        persisted correctly; confirmed the list page shows "Lecturer
        updated successfully." and a real "Edit" link on every row.
        Temporary lecturer and both temporary accounts deleted afterward;
        confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Head of Academic Affairs: Delete Lecturer + Unified Lecturer Username/Password Scheme
- [x] Two requests: (1) single-row Delete on `head_academic/lecturers.php`
      (previously bulk-select-only, no per-row button). (2) a lecturer's
      auto-generated username AND temporary password should both be the
      same "name + staff-number-digits" value (e.g. "garjeex04" for
      full name "Garjeex ..." and staff no ending "...4") — the exact
      value `generate_lecturer_username()` already computed for the
      *username* was never reused for the *password*, which was instead
      always the bare staff number, so the two never matched (unlike the
      analogous fix already applied to student credentials).
      - **`head_academic/lecturers.php`**: new `delete` POST branch
        (single `lecturer_id`) calling the page's existing
        `delete_lecturer_row_head_academic()` helper — the same blocker
        rules bulk-delete already used, no new logic. New per-row Delete
        trash-icon button/form next to Edit/Assign Courses, with the same
        `confirm()` dialog convention used everywhere else in this app.
      - **Username = Password, at every lecturer-account-creation site**:
        `admin/lecturers.php`, `head_academic/lecturers.php`, and
        `admin/lecturers_import.php` all changed `$tempPassword = $staffNo;`
        to `$tempPassword = $username;` (the value already computed by
        `generate_lecturer_username()` a line above) — one-line fix at each
        of the three sites.
      - **Reset Password now also normalizes the username, not just the
        password** (`admin/lecturers.php`'s `reset_password` action) — same
        fix already applied to students' own Reset Password earlier this
        project, for the same reason: a lecturer account created before
        this session's change (or under any other naming) would otherwise
        end up with a mismatched username/password pair after a reset.
      - **Real bug caught and fixed before this was usable**:
        `generate_lecturer_username()`'s collision-retry loop checks
        `lecturer_username_exists()` against *every* row in `users` with no
        exclusion — recomputing an *existing* lecturer's own username
        during Reset Password would see their own current row as a
        "collision" the instant it already matched the target value,
        appending a spurious numeric suffix (e.g. "garjeex041") on every
        subsequent reset even though nothing was actually wrong. Fixed by
        adding an optional `?int $excludeUserId` parameter to both
        `lecturer_username_exists()` and `generate_lecturer_username()` in
        `includes/lecturer_accounts.php`, passed as the target lecturer's
        own `user_id` from the reset action — the same self-exclusion
        pattern a uniqueness check always needs when recomputing a value
        for a record that may already legitimately hold it.
      - **Discovered, not fixed (out of scope for this request, flagged for
        the record)**: `admin/lecturers.php` now has `require_role([
        'university_rector', 'dean'])` with `$isReadOnly` covering *both*
        of those roles (from two separate earlier sessions converting each
        to a faculty-scoped/oversight Viewer) — meaning its own Reset
        Password action, Edit, Delete, and Create are all currently
        unreachable by any live role. Not a regression from this session's
        changes and not something this request asked to fix, so left
        as-is; the Reset Password fix above was verified by calling
        `generate_lecturer_username()` directly (bypassing the
        now-universally-read-only web layer) rather than via a live HTTP
        request, since no role can currently reach that action to test it
        through the UI.
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary Head of Academic Affairs, University Rector, and
        Dean accounts: registered a real lecturer ("Garjeex Cabdi Ali",
        staff no ending "04") via `head_academic/lecturers.php`'s real form
        and confirmed the flash message showed "Username: garjeex04 —
        Temporary Password: garjeex04" (identical values, matching the
        user's own example exactly); confirmed a real login with those
        exact credentials succeeded; confirmed the new single-row Delete
        button removed the lecturer and deactivated (not deleted) their
        user account, with the correct flash message. For the Reset
        Password fix (unreachable via HTTP per the discovery above),
        verified directly at the function level with a simulated
        pre-existing lecturer account (username `oldstyle_username_xyz`):
        confirmed `generate_lecturer_username()` correctly computed
        "xasan07" and that applying it (username + password_hash update)
        made `password_verify('xasan07', ...)` succeed; confirmed calling
        the same function a second time immediately after (simulating a
        redundant re-reset) returned the *identical* "xasan07" with no
        spurious numeric suffix, proving the self-exclusion fix works. All
        temporary lecturers/accounts were deleted afterward; confirmed zero
        stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### User Management: Filter/Search Bar + Project-Wide Icon Buttons Now Show Text Labels
- [x] Two requests together: (1) add a filter/search feature to User
      Management; (2) Reset Password/Deactivate — and, per the user's own
      explicit wording, "dhammaan buttonyada projectigayga" (ALL buttons in
      the project) — should show text labels, not just an icon.
      - **Filter/search bar**, added to both `admin/users.php` (University
        Rector) and `head_academic/users.php` (Head of Academic Affairs):
        a Role dropdown (`role_label()`-driven, `head_academic/users.php`'s
        own list correctly excludes `university_rector` — this page never
        manages that role, matching its existing `load_manageable_user()`
        boundary), a Status dropdown (Active/Inactive), and a
        `data-live-search` text box matching username/full name/email —
        all real SQL `WHERE` conditions via the same dynamic
        `$userWhere`/`$userParams`/`$userTypes` prepared-statement-array
        pattern already established across this app (`admin/students.php`
        etc.), not client-side filtering. Wired to the existing shared
        `assets/js/live_filter.js` (`admasInitLiveFilter('#usersFilterForm')`)
        for auto-submit-on-change, the same convention already used
        elsewhere. Empty-state message now distinguishes "No users match
        the selected filters." from "No users exist yet."
      - **Icon-only → icon+text buttons, project-wide**: this app already
        had a `.btn-icon-label` CSS class (icon + text, gap, no-wrap) used
        in a few places (e.g. "View Profile" links) but most CRUD row
        actions across the app were still the older icon-only `.btn-icon`.
        Swept every remaining `.btn-icon` instance found via a full-repo
        grep and converted each to `.btn-icon-label` with a short visible
        label matching its existing `title` attribute — no logic changes,
        pure markup: `admin/users.php` / `head_academic/users.php` (Reset →
        "Reset", Activate/Deactivate → "Activate"/"Deactivate"),
        `admin/lecturers.php` (Assign/Edit/Reset/Delete),
        `admin/students.php` (Edit/Reset/Delete), `head_academic/lecturers.php`
        (Edit/Assign/Delete), `semesters.php` (Edit semester/Delete
        semester), `admin/faculties.php` (Edit/Delete),
        `admin/departments.php` (Edit/Delete), `admin/academic_years.php`
        (Edit/Delete), `admin/course_enrollments.php` (Remove),
        `lecturer_courses.php` (Remove), `messages.php` (the mobile-only
        chat-header "Back" button). `admin/courses.php` was found already
        converted from an earlier session in this same work — confirmed,
        not re-edited.
      - **Verified end-to-end via `php -l`** on all 17 touched/related
        files (zero syntax errors) and **real HTTP requests** against the
        live app with two temporary accounts (a `university_rector` and a
        `head_academic`, both deleted afterward): confirmed
        `admin/users.php` renders the filter form and the labeled
        Reset/Deactivate buttons; confirmed `?role=dean` and
        `?search=temp_btnqa` both correctly narrowed the real rendered
        table to matching rows only; confirmed `admin/students.php`,
        `admin/lecturers.php`, `admin/faculties.php`,
        `admin/departments.php`, `admin/academic_years.php`, `semesters.php`,
        `admin/course_enrollments.php`, `lecturer_courses.php`, and
        `messages.php` all return 200 with zero PHP warnings/notices/
        fatals; confirmed `head_academic/users.php`'s filter form and
        `head_academic/lecturers.php`'s Edit/Assign/Delete labels render
        correctly in the raw HTML for the temporary Head of Academic
        Affairs account. Both temporary accounts were deleted afterward;
        confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Head of Academic Affairs Real Account: Username = Password + Login Page Redesign
- [x] Two requests: (1) the real Head of Academic Affairs account
      ("eng soofe", `users.id = 66`) should have `soofe12345678` as both
      its username and password. (2) redesign `login.php`'s visual look —
      the user felt it read as too generic/similar to a stock "attendance
      system" login template.
      - **Credential fix**: `username` and `password_hash` both set
        directly to `soofe12345678` (via a one-off script against the
        real row, not through the Reset Password UI, since this session's
        credential schemes are department/faculty-code-derived and this
        was a literal explicit value rather than a generated one),
        `must_change_password` cleared. Verified via `password_verify()`
        against the freshly-stored hash rather than a live login attempt
        (the harness's own auto-mode classifier declined to run an actual
        HTTP login as this real production account, which is the correct
        call for a real credential) — confirmed the hash genuinely matches
        `soofe12345678`.
      - **Login page redesign**: per this session's established
        mockup-first workflow, built and published an Artifact mockup
        (a new asymmetric layout — a navy identity panel on the left with
        a subtle geometric pattern, the real ADMAS logo, a headline, and
        small stat chips; a clean white sign-in form on the right; new
        typography pairing Fraunces (display) + Manrope (UI/body),
        replacing the previous split sky-blue/navy two-tone card and
        Playfair Display) and got explicit approval ("samey waan ka
        helaye" — make it, I like it) before touching real code.
      - **`login.php` rebuilt** to the approved design while preserving
        every existing behavior byte-for-byte: the PHP auth/session logic
        block above the HTML was untouched; `$universityName`/
        `$campusShort`/`$loginLogoPath` (real logo + settings-driven,
        never hardcoded, per CLAUDE.md's branding rule) still drive the
        identity panel; the Role select still re-selects `$submittedRole`
        and the identifier field still re-fills `$submittedIdentifier` on
        a failed attempt; the error message still renders from
        `$errorMessage`. The Password/QR Code tab switcher keeps real
        Bootstrap `data-bs-toggle="tab"` markup (just restyled as a pill
        switch) so `assets/js/qr_login.js`'s `shown.bs.tab` listener and
        polling logic work unmodified; the password field keeps the exact
        `.toggle-password` / `data-target="password"` markup
        `assets/js/password-toggle.js` expects; the QR pane keeps the
        exact element ids (`qrLoginImage`, `qrLoginLoading`,
        `qrLoginStatus`, `qrLoginRefreshBtn`) that script reads. Forgot
        Password and "New student? Register" links are unchanged
        destinations. A responsive `@media` breakpoint collapses the
        two-column layout to a single column (identity panel becomes a
        compact header strip) on narrow screens, replacing the old
        page's separate tablet/phone breakpoints with one simpler rule
        appropriate to the new asymmetric layout.
      - **Verified end-to-end via real HTTP requests** against the live
        app: confirmed `login.php` returns 200 with zero PHP
        warnings/notices/fatals, the real logo path
        (`/AttendancySystem/logo/logo.jpg`) renders, the Role select and
        `qrTabBtn`/`toggle-password` markup are present; submitted a
        deliberately invalid login (`role=student`,
        `username_or_email=nonexistent_qa_user`) and confirmed the "Invalid
        username, password, or role" error renders in the new design and
        the Role select correctly stays re-selected to "student" — proving
        the failed-attempt state (error banner + form re-population) still
        works exactly as before under the new markup. `php -l` clean.
      - **Reverted immediately after, per explicit follow-up request**
        ("war tirtir waxa aad samaysay" — undo what you just did): the
        user approved the published mockup ("samey waan ka helaye") but,
        after seeing the real implemented page live, asked for it back.
        `login.php` was restored to its exact pre-redesign version (the
        original split sky-blue/navy card, Playfair Display, `nav-tabs`
        Password/QR Code Scan switcher) — `php -l` re-confirmed clean.
        The unrelated Head of Academic Affairs credential fix above (both
        username and password set to `soofe12345678`) was intentionally
        left in place, since the revert request followed right after the
        login-page work specifically and that account change was a
        separate, already-confirmed, non-visual fix. The published mockup
        Artifact itself was left untouched (not deleted) — it's a private,
        disposable design reference the user can revisit later, not
        something that affects the live app either way.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Student Credentials: Faculty Code Instead of Department Code
- [x] Per explicit request, the auto-generated student username/password
      scheme (`"{Code}-{StudentNo}"`, e.g. `IT-1472/23`) switched from the
      student's **Department** code to their **Faculty** code (e.g.
      `INF-1472/23`). `faculties` had no code column at all before this
      session (only `departments.code` existed) — confirmed with the user
      via `AskUserQuestion` before building: a new required, admin-typed
      `code` field on Faculty Management, not an auto-derived one.
      - **Schema** (`migrations/2026_08_faculties_code.sql`, mirrored into
        `admas_attendance_schema.sql`, `mysqldump` backup taken first):
        `faculties.code VARCHAR(20) NOT NULL UNIQUE`. The 3 real existing
        faculties were best-effort backfilled from their name's first 3
        letters (Informatics→INF, Health→HEA, Business
        Administration→BUS, confirmed no collision) — editable afterward
        via the real form if a different code is preferred.
      - **`admin/faculties.php`** (University Rector view-only, Head of
        Academic Affairs full CRUD — unchanged access from earlier
        sessions): added a required "Faculty Code" field (letters/numbers
        only, case-normalized uppercase) to the Add/Edit modal, with
        server-side uniqueness + format validation, a new "Code" column
        (sky-blue badge) on the All Faculties table, and the JS
        `openFacultyModal()`/reopen-on-error state threaded through to
        match.
      - **`includes/lecturer_accounts.php`**'s `student_credential_value()`
        — parameter renamed `$departmentCode` → `$facultyCode` (docblock
        updated); the function body itself (`"{code}-{studentNo}"`) was
        already generic and needed no logic change.
      - **Every call site switched from a department lookup to a faculty
        lookup**: `admin/students.php`'s Add Student create branch (now
        joins `faculties` instead of just reading `departments.code`) and
        its Reset Password action (joins `faculties` on
        `students.faculty_id`); `admin/students_import.php` (new
        `$facultyCodeById` map built from `faculties`, replacing
        `$departmentCodeById`, threaded through
        `validate_student_import_row()`'s parameter and its two call
        sites — the initial parse loop and the `edit_row` action — plus
        the `confirm` action's credential generation); `admin/users.php`
        and `head_academic/users.php`'s own Reset Password actions (both
        joined `departments` before, now join `faculties`). All
        user-facing help text ("will be generated automatically...",
        the import page's instructions) updated from "DepartmentCode" to
        "FacultyCode" wording to match.
      - **Verified end-to-end via real HTTP requests** against the live
        app with temporary Head of Academic Affairs, Registration Office,
        and University Rector accounts: confirmed the Code column/badge
        renders on `admin/faculties.php` for all 3 real faculties;
        confirmed a duplicate Code and a blank Code are both rejected
        server-side with zero DB change; created a real student under
        Informatics (faculty code `INF`) via the real Add Student form and
        confirmed the flash message read exactly `Username:
        INF-QA-FACCODE-001 — Temporary Password: INF-QA-FACCODE-001`
        (matching the DB row exactly, not the old `IT-...` department-code
        form); confirmed a real login with those exact credentials
        succeeded; force-set that student's username back to an old-style
        value and confirmed the real Reset Password button on
        `admin/users.php` correctly re-normalized both username and
        password to `INF-QA-FACCODE-001` again. All temporary
        accounts/students were deleted afterward; confirmed zero stray
        rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Dean: Courses/Lecturers/Semesters Restored to Full CRUD (own faculty only)
- [x] Reversed part of the earlier "Dean: Full CRUD Converted to a
      Faculty-Scoped Viewer" session, per explicit request — Dean can once
      again create/edit/delete Courses (including a course's first
      offering and cross-faculty cross-listing via
      `admin/course_offerings_search.php`), Lecturers (create/edit/delete/
      reset password), and Semesters/Xiiso (create semesters, generate
      sessions, set dates, Start/Current/End/Waiting status, "Save All
      Semesters"), all strictly within their own faculty. Confirmed the
      exact scope via `AskUserQuestion` before touching any code (full
      CRUD vs. add-only for each of the three areas) — the user chose full
      CRUD for all three. Students, Departments, Course Enrollments, and
      Attendance marking were **deliberately left read-only** for Dean —
      not part of this request, and Course Enrollments in particular
      stays real student-roster data, distinct from the course/offering
      schedule metadata this change covers.
      - **`admin/courses.php`**, **`admin/course_offerings.php`**,
        **`admin/lecturers.php`**, **`semesters.php`**: each file's
        `$isReadOnly` flag narrowed from `in_array($role,
        ['university_rector', 'dean'], true)` to `$role ===
        'university_rector'` — the pre-existing `$role === 'dean'`
        faculty-scoping throughout each file (own-department locks,
        `dean_owns_semester()`, cross-faculty rejection messages) was
        never removed by the earlier Viewer conversion, only hidden
        behind this one flag, so restoring it re-engaged every scoping
        check automatically with no other logic changes needed. Scope
        banners changed from "— view only" back to plain "Access scope:
        {Faculty} Faculty only" on all four files.
      - **`admin/courses.php`**: split a new `$enrollmentsReadOnly` flag
        (`in_array($role, ['university_rector', 'dean'], true)`,
        unchanged) from the page's own `$isReadOnly`, since the "Enroll
        Students" row link points at `admin/course_enrollments.php` —
        deliberately still read-only for Dean — so its label/behavior
        must track that page's own permission, not this page's now-wider
        one. Applied the identical split to
        **`admin/course_offerings_search.php`** (its "Add Offering"
        label now correctly reflects the wider Course grant, its "Enroll
        Students"/"View Enrollment" label stays tied to the narrower
        enrollment one).
      - **`lecturer_courses.php`** ("Assign Courses", the lecturer-first
        mirror of Manage Offerings — writes the same `course_offerings`
        table): `role_may_edit_faculty()` restored from an unconditional
        `return false` for Dean to `return $facultyId === $deanFacultyId`
        (the original pre-conversion own-faculty check); the "Assign to a
        New Course" form column, previously hidden outright for Dean via
        `$role !== 'dean'`, is now shown — its own internal Dean-specific
        branches (locked Faculty select, hidden Offering-Faculty/Roster-
        Department fields since Dean never creates a cross-faculty guest
        offering from this page) were already fully built out from before
        the conversion and needed no changes, only un-hiding the wrapper.
      - **`admin/users.php`**'s `ROLE_INFO` reference table and
        CLAUDE.md §4's live RBAC table both updated to describe the new
        (accurate) split — full CRUD on Courses/Lecturers/Semesters,
        read-only VIEW on Departments/Students/Attendance/Course
        Enrollments — replacing §4's previous (already only partially
        accurate) "Full read-only VIEW access... everywhere" description.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary Dean account (Informatics faculty) and a
        temporary University Rector account (regression check): confirmed
        all three pages render real Add/Create UI for Dean; created a
        real course with a first offering (semester + shift, own faculty)
        and confirmed both rows landed correctly in the database;
        confirmed a crafted course-create against a foreign-faculty
        semester and a crafted lecturer-create against a foreign-faculty
        department were both rejected with the exact expected message and
        zero DB rows created; registered a real lecturer via the real
        form and confirmed the account was created correctly; created a
        real semester via `semesters.php` in the Dean's own faculty and
        confirmed it landed with the correct `faculty_id`; used
        `lecturer_courses.php`'s real "Assign to a New Course" form to
        assign the new lecturer to the new course on a second shift and
        confirmed a second, correct `course_offerings` row was created
        (not overwriting the first shift's row, proving multi-shift
        support still works through this restored path). Regression-
        checked University Rector: confirmed all three pages still show
        no real write UI (a loose "Add Course" text match turned out to
        be inside an unrelated JS comment, re-verified by inspecting the
        actual scope banner, which correctly read "view only
        (oversight)"), and a crafted write POST as Rector was rejected
        with zero DB change. All temporary courses/offerings/lecturer/
        semester/accounts were deleted afterward; confirmed zero stray
        rows remained via an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Student Credentials: Trailing ID Segment Only, Not the Full Student No
- [x] Follow-up refinement to the Faculty Code credential scheme — the user
      pointed out a real example (`BUS25-009`) where the generated
      credential (`BUS-BUS25-009`) duplicated "BUS" because the Student No
      itself already had a faculty-like prefix baked into it by whoever
      typed it in. Asked for the ID part alone (just the trailing
      `009`) combined with the real Faculty Code, confirmed with three
      more examples of differently-shaped Student Nos: `NUR-008`,
      `INF-1829/25`, `INF-1720/24`.
      - **`includes/lecturer_accounts.php`**'s `student_credential_value()`
        now extracts only the substring after the Student No's **last**
        hyphen (`strrpos($studentNo, '-')`) as the "ID" — a Student No with
        no hyphen at all (e.g. `1829/25`, which uses `/` not `-`) is used
        unchanged, matching all three of the user's non-`BUS25-009`
        examples exactly without any special-casing.
      - **New collision risk, and the fix for it**: the old
        `"{FacultyCode}-{StudentNo}"` scheme could never collide, since
        `students.student_no` is globally unique — but two different
        students in the *same* faculty can share the same trailing ID
        segment once everything before the last hyphen is discarded (e.g.
        `IT-009` and `CS-009` would both shorten to `-009`). Fixed by
        widening the function's signature to `(mysqli $conn, string
        $facultyCode, string $studentNo, ?int $excludeUserId = null)` and
        adding the same collision-retry suffix loop already used for
        lecturer/admin usernames (reusing the existing, role-agnostic
        `lecturer_username_exists()` helper) — a genuine collision now
        gets a numeric suffix (`BUS-009`, `BUS-0091`, ...) instead of two
        students silently sharing one login. All 5 call sites
        (`admin/students.php`'s create and Reset Password actions,
        `admin/students_import.php`'s bulk-import confirm,
        `admin/users.php`'s and `head_academic/users.php`'s Reset Password
        actions) updated to pass `$conn`, and the three Reset-Password call
        sites additionally pass the target's own `user_id` as
        `$excludeUserId` so re-normalizing an existing student's own
        already-correct credential never falsely flags itself as a
        collision (same self-exclusion pattern already established for
        lecturer username resets).
      - **Confirmed via a full real-data scan** (all 82 real students,
        computing each one's new candidate credential from
        `student_no`/`faculty_code` and checking for duplicates) that
        **zero collisions exist today** — the retry logic is a safety net
        for the future, not something currently needed to resolve existing
        data.
      - **Deliberately did not bulk-reset any real student's existing
        credentials** — this change only affects (a) a brand-new student
        created from this point on, and (b) any student whose password is
        explicitly Reset going forward (via the real "Reset Password"
        button on `admin/students.php`/`admin/users.php`/
        `head_academic/users.php`), matching the exact same rollout
        pattern already used for the earlier Department-Code-to-Faculty-
        Code change. A real, pre-existing student (e.g. the real
        `BUS25-009` from the user's own screenshot, if their account
        predates this change) keeps whatever credential they already have
        until an admin explicitly resets it.
      - **Verified end-to-end**: direct function calls reproduced all four
        of the user's own examples exactly (`BUS25-009` → `BUS-009`,
        `008` → `NUR-008`, `1829/25` → `INF-1829/25`, `1720/24` →
        `INF-1720/24`); created a real student via the live
        `admin/students.php` Add Student form (Student No
        `BUS25-QATEST01`) and confirmed via direct DB read that the
        generated username/password hash both matched `BUS-QATEST01`
        exactly; forced a real collision (manually occupied `BUS-COLQA`
        with a throwaway user row, then generated a second credential for
        a different Student No sharing the same trailing segment) and
        confirmed the collision-retry loop correctly produced `BUS-COLQA1`
        instead of colliding. All temporary students/users created for
        this test were deleted afterward; confirmed zero stray rows
        remained via an exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Class Time Table: Lecturer's Own Page, Print-Style for Dean/Head Academic/Rector, Spacing Fix
- [x] Three follow-ups to the Class Time Table feature, requested together:
      (1) Lecturer had no dedicated Class Time Table page/sidebar item at
      all (only Student did) — added one. (2) the print-style grid's Time
      column and day cells read as cramped in a screenshot the user shared
      (text like "Room 1" sitting tight against the lecturer name with
      almost no gap) — widened padding/line-height. (3) the shared
      `class_timetable.php` page (Dean/Head of Academic Affairs/University
      Rector) still used the older tinted-box `.timetable-slot` grid style
      from before the print-style redesign — converted it to the same
      print-style card (logo header, Faculty/Semester line, "REGISTRAR"
      signature) already used by `student/class_timetable.php`.
      - **New `lecturer/class_timetable.php`**: same print-style layout as
        `student/class_timetable.php` (logo + university name/campus
        header, this lecturer's own full name where the student page shows
        Faculty, "Class Time Table" title, the Sat-Thu grid via the shared
        `render_class_timetable_grid_table()`, "REGISTRAR" signature).
        Reuses the exact same `$myCourses` query shape already established
        on `lecturer/dashboard.php` (own `lecturers.id` resolved from
        `current_user()['id']`, current-offering-only via `course_offerings`
        joined to the *current* semester) — deliberately kept in sync with
        the dashboard's own timetable card rather than reinvented, so the
        two can never disagree about what a lecturer is currently teaching.
        New nav entry in `includes/nav_items.php` (`roles: ['lecturer']`,
        same filename-sharing/default-per-role-folder-convention pattern
        already used for the Student entry right above it).
      - **Spacing fix** (`assets/css/app.css`, `.timetable-print-table`/
        `.timetable-time-col`/`.timetable-print-cell`): added real
        `padding` to both `<th>`/`<td>` (previously relying on Bootstrap's
        bare `table-sm` default), widened the Time column from 110px to
        130px, raised `.timetable-print-cell`'s `line-height` from 1.4 to
        1.6 and added `margin-bottom` between each cell's stacked
        course/lecturer/room lines — applied to the full-size table used
        by all three full-page views. The dashboard-widget
        `.timetable-print-compact` modifier got the same treatment at its
        own smaller scale (a touch more padding/line-height without
        growing the card significantly).
      - **`class_timetable.php` (Dean/Head Academic/Rector) print-style
        conversion**: added the `includes/university_logo.php` require and
        a print header block (logo, university name/campus, a "Faculty:"
        line that reflects whichever Faculty filter is actually selected —
        the Dean's own locked faculty, the chosen filter, or "All
        Faculties" when unfiltered — and a semester line the same way,
        falling back to "Current Semester(s)"). Replaced the old manual
        `.timetable-slot` grid markup with a call to the same shared
        `render_class_timetable_grid_table()` function the other two pages
        already use (`course_name` label key, Sat-Thu day order, no more
        Friday column on this page either, matching the other two). The
        existing Faculty/Department/Semester/Shift filter form above it,
        and every one of its existing scoping rules (Dean locked to their
        own faculty, `se.status = 'current'` default when no semester is
        chosen), were left completely untouched — only the result grid's
        visual style changed.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary lecturer (assigned to a real scheduled
        offering) and a temporary University Rector account: confirmed
        both `lecturer/class_timetable.php` and `class_timetable.php`
        return 200 with zero PHP warnings/notices/fatals; confirmed the
        lecturer's page shows only their one assigned course with its full
        name ("Calculus", not a code) and the new wider-spaced cell
        markup; confirmed the Rector's page renders "Faculty: All
        Faculties" (no filter applied) and every cell across all real
        scheduled offerings shows the full course name with the same
        spacing fix; confirmed the sidebar link on the lecturer's page
        resolves to the correct `lecturer/class_timetable.php` URL. The
        temporary lecturer/user accounts were deleted afterward and the
        one real `course_offerings` row temporarily reassigned to the test
        lecturer was restored to `lecturer_id = NULL`; confirmed zero
        stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Global .admas-card Frame: Applied Everywhere, Then Reverted; Student/Lecturer Dashboards Reorganized Into a 2x2 Grid
- [x] Per explicit user choice via `AskUserQuestion`, the gradient-border/
      glow-shadow/top-accent-bar frame originally built for
      `.timetable-print-card` (Class Time Table) was extended to the base
      `.admas-card` rule in `assets/css/app.css` (every card project-wide:
      tables, forms, KPI cards, chat, settings). After seeing it live, the
      user found it visually excessive ("waa ila fool xumaaday") and asked
      for it to be fully reverted from every page. `.admas-card` was
      restored to its exact original rule (`background: var(--admas-surface);
      border-radius: 16px; border: 1px solid var(--admas-border); box-shadow:
      0 1px 2px var(--admas-shadow);`, no `::after` bar) — the dedicated,
      separately-approved `.timetable-print-card`/`.timetable-print-compact`
      frame used specifically by Class Time Table was left untouched, since
      that was a distinct, earlier-approved feature, not part of the
      reverted global change.
      - The user separately asked for a suggested alternative — a more
        restrained, ADMAS-brand-consistent card border/accent that still
        works in both light and dark mode. Proposed via text (a subtle
        sky-tinted border + a thin plain sky-blue left accent bar, no
        gradient ring/glow), user confirmed to apply project-wide, then
        built: `.admas-card` now carries a `1.5px solid rgb(14 165 233 /
        0.35)` border, a soft `box-shadow: 0 4px 14px var(--admas-shadow)`,
        and a new `::before` 3px solid `var(--admas-sky)` bar down the left
        edge — no `overflow: hidden`, so nothing inside a card can get
        clipped. `.kpi-card`'s own later, more-specific `::before` rule
        (its category-colored left bar — sky/navy/green/amber) still wins
        the cascade on KPI cards specifically, so those keep their existing
        distinct coloring rather than being flattened to plain sky.
        Verified live across 6 different page types (dashboard, a list/
        table page, a form-heavy page, chat, settings, semesters) with a
        temporary University Rector account — all 200, zero PHP
        warnings/notices/fatals; temporary account deleted afterward.
- [x] **Student/Lecturer dashboards: 4 cards reorganized into a 2-column
      grid.** Per a screenshot showing "My Attendance by Course" / "My
      Assigned Courses" (lecturer) or "Course Attendance" (student) / Class
      Time Table / "Pending Xiiso Sessions" (lecturer) or "My Last
      Documents" (student) stacked at different widths, the user asked for
      these 4 to sit two-by-two side by side. Confirmed via `AskUserQuestion`:
      not a forced 1:1 square (a table/timetable card would be unusably
      cramped) — each card's height grows naturally to fit its own content,
      just paired two-per-row.
      - `student/dashboard.php` and `lecturer/dashboard.php`: the old
        `col-xl-5/7` (student) and `col-xl-4/8` (lecturer) split for the
        chart+table row was changed to an even `col-lg-6/6`, and the
        previously full-width Class Time Table card and 4th card (My Last
        Documents / Pending Xiiso Sessions) were pulled into the same
        `row g-3` as two more `col-lg-6` columns — all 4 cards now share
        one row-wrapping grid (new `dashboard-quad` class on the row,
        currently just a marker hook, no CSS rule yet), each with `h-100`
        so a pair sitting on the same visual row stretches to match its
        taller neighbor. The existing "no chart data" / "no pending
        sessions" fallbacks (`col-lg-12` when the paired card has nothing
        to show) were preserved from the original conditional-width logic.
      - **Verified end-to-end via real HTTP requests** with temporary
        lecturer and student accounts, first with no real course/schedule
        data (confirming both the empty-state `col-lg-12` fallbacks) and
        then with the lecturer assigned to a real scheduled
        `course_offerings` row (confirming all 4 cards render as
        `col-lg-6` — a real 2x2 grid — with real data and zero PHP
        warnings/notices/fatals). Temporary accounts deleted afterward and
        the one real offering's `lecturer_id` restored to `NULL`.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Student/Lecturer Dashboard Density Pass #2: Shrink the 4-Card Quad to Fit a Laptop Screen
- [x] User reported the student and lecturer dashboards need a bit of
      scrolling to see everything on a real laptop (1920x1080-class HP
      screen, 100% browser zoom), and asked for the 4-card quad grid (added
      in the "reorganized into a 2x2 grid" session above) to shrink further
      to match how compact Rector/Dean/Head of Academic Affairs' own
      dashboards already are — those three don't carry the extra Class Time
      Table card these two do, which is the main source of the extra
      height.
      - `student/dashboard.php`/`lecturer/dashboard.php`: tightened
        vertical spacing throughout — the welcome subtitle `mb-4` → `mb-2`,
        the KPI row `g-3 mb-4` → `g-2 mb-3`, the quad row `g-3` → `g-2`,
        and every one of the 4 quad cards' own padding `p-3` → `p-2`. Chart
        canvas height attribute reduced (130/140 → 95).
      - `.timetable-print-compact` (the print-style Class Time Table card,
        used only inside this quad grid) shrunk a further notch: logo
        42px → 32px, header gap/margin tightened, every font-size a touch
        smaller (university 0.95→0.82rem, faculty/year 0.76→0.68rem, meta
        0.76→0.7rem), Time column 108→88px, table cell padding
        0.45rem→0.3rem, cell line-height 1.5→1.35.
      - New rule inside the existing 768–1600px laptop-density `@media`
        block in `assets/css/app.css`: `.dashboard-quad .admas-table th/td`
        get tighter padding/font-size, and `.dashboard-quad h6` (each
        card's own title) shrinks too — scoped to the new `dashboard-quad`
        row class so it only affects these two dashboards' 4-card grid, not
        every table project-wide.
      - **Verified via `php -l` and real HTTP requests** with a temporary
        lecturer (assigned to a real scheduled `course_offerings` row, so
        all 4 cards render with real data, not an empty-state fallback) and
        a temporary student: both dashboards return 200 with zero PHP
        warnings/notices/fatals, and the new `p-2 h-100`/`height="95"`
        markup is present in the rendered HTML. Temporary accounts deleted
        afterward and the one real offering's `lecturer_id` restored to
        `NULL`.
      - **Flagged honestly**: no browser/screenshot tool is available in
        this environment, so the actual "does it now fit without scrolling
        on that specific HP laptop" outcome could not be visually confirmed
        — only that the CSS/markup changes are syntactically correct and
        render without errors. Real confirmation still needs the user's own
        screen.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Lecturer Check-In: AJAX Check In/Out (no more scroll-to-top) + Visual Polish
- [x] Two complaints about `lecturer/checkin.php`: (1) clicking Check In or
      Check Out submitted a plain form → full page reload → the browser
      landed back at the top of the page, forcing a scroll back down to
      wherever the lecturer actually was in a long session list; (2) the
      page looked plain/unpolished (a heavy hardcoded 2px sky border around
      the whole table, no summary at a glance, bare buttons).
      - **Scroll fix, the real complaint**: moved `lecturer_owns_current_session()`
        out of this file's own local function and into
        `includes/attendance_helpers.php` (a shared helper, docblock
        explains why: both the page's own no-JS POST fallback and the new
        AJAX endpoint need the identical ownership check, so they can never
        drift). New `ajax/lecturer_checkin_action.php` — same two actions
        (`check_in`/`check_out`), same validation/uniqueness rules as the
        original inline POST handler, returning JSON instead of a
        redirect. New `assets/js/lecturer_checkin.js` intercepts both
        forms' `submit` (event delegation on the table, works for every
        row without per-row listeners), posts via `fetch()`, and on
        success swaps just that row's Check-In/Check-Out cells and action
        button in place — no navigation happens at all, so the scroll
        position never moves. A transient flash alert (auto-dismissing
        after 4s) replaces the old full-reload flash message; the two KPI
        counts (Checked In / Not Checked In) are nudged up/down in place
        too. The original plain-POST handler in `lecturer/checkin.php`
        itself was deliberately left in place as a no-JS fallback (a
        real `<form>` still posts there correctly if JS is blocked) —
        this is additive, not a replacement.
      - **Visual polish**: removed the jarring hardcoded
        `style="border: 2px solid var(--admas-sky);"` around the table
        card (now just the standard `.admas-card`, consistent with every
        other page). Added a 3-card KPI summary strip above the table
        (Sessions So Far / Checked In / Not Checked In Yet, reusing the
        same `.kpi-card` markup/coloring already used on every dashboard —
        plain non-link `<div>`s here since there's nowhere useful to
        navigate to from them). Check-In/Check-Out times now render as
        colored `badge-pill`s (green "checked in" pill, neutral-gray
        "checked out" pill) instead of bare text; the action buttons
        gained `rounded-pill` styling to match the pill language used
        elsewhere in the app; the "Done" state gained a check-circle icon.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary lecturer assigned to a real current-semester
        offering with a real past session date: confirmed the page loads
        with correct KPI counts (0 checked in / 11 pending); confirmed a
        real AJAX `check_in` succeeds and returns the expected JSON;
        confirmed a duplicate `check_in` on the same session is correctly
        rejected with no DB change; confirmed a real AJAX `check_out`
        succeeds; confirmed an unauthenticated request to the same
        endpoint is rejected (302, not a raw exception); reloaded the page
        afterward and confirmed the KPI counts updated server-side (1
        checked in / 10 pending) and the row correctly shows the "Done"
        badge — proving the AJAX writes and the page's own read path agree.
        Zero PHP warnings/notices/fatals throughout. Temporary lecturer,
        its `lecturer_checkins` row, and the account were deleted
        afterward; the real offering's `lecturer_id` restored to `NULL`.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Dean/Head of Academic Affairs Dashboards: Chart Order Swapped
- [x] Per explicit request, reordered the chart rows on `dean/dashboard.php`
      and `head_academic/dashboard.php` — the "oversight" charts added in an
      earlier session (Students per Faculty/Department, Lecturer Workload,
      Registrations (6mo)) now render **above** the attendance charts
      (Weekly Attendance, Attendance by Faculty, Attendance by Department/
      Semester), the reverse of their previous order. Pure markup
      reordering (moved the two `<div class="row g-3">` blocks, no query/PHP
      logic changed) — both files' underlying data computation was
      untouched.
      - **Verified end-to-end via real HTTP requests** with temporary Dean
        and Head of Academic Affairs accounts: confirmed both dashboards
        return 200 with zero PHP warnings/notices/fatals, and confirmed via
        the raw HTML's line order that "Students per Department/Lecturer
        Workload/Registrations (6mo)" now appears before "Attendance by
        Semester/Attendance by Department" on Dean's dashboard, and
        "Students per Faculty/Lecturer Workload/Registrations (6mo)" now
        appears before "Weekly Attendance/Attendance by Department/
        Attendance by Faculty" on Head of Academic Affairs' dashboard;
        confirmed the Lecturer Workload chart's own `<canvas>` element still
        renders exactly once on each page (no accidental duplication from
        the block move). Temporary accounts deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Lecturer Credentials Use Full Staff ID; Dean/Registration Office/Head of Academic Affairs Now Get Their Exact Name as Username + a Manually-Typed Password
- [x] Two credential-scheme changes, confirmed via `AskUserQuestion` before
      building (both decisions the user explicitly chose over the
      alternative offered):
      1. **Lecturer username/password now use the lecturer's FULL Staff ID**,
         not just its last 2 digits. `generate_lecturer_username()`
         (`includes/lecturer_accounts.php`) changed from
         `name_username_base(...) . id_sequence_suffix($staffNo)` (e.g.
         "acali07") to `name_username_base(...) . <full staff_no,
         alphanumeric-only, lowercased>` (e.g. staff_no "STF-2044" ->
         "xasanstf2044" for "Xasan Cali") — the collision-retry suffix loop
         is unchanged. Since every lecturer-account-creation site
         (`admin/lecturers.php`, `head_academic/lecturers.php`,
         `admin/lecturers_import.php`) already sets `$tempPassword =
         $username;` (from the earlier "Unified Lecturer Username/Password
         Scheme" session), the password automatically follows the same
         full-ID scheme with no further changes needed at those call sites.
      2. **Dean / Head of Academic Affairs / Registration Office accounts
         created via "+ Create New User"** (on `admin/users.php`'s and
         `head_academic/users.php`'s own Assign Role panels) no longer get
         an auto-generated username or a random temporary password:
         - `generate_admin_username()` changed from an initial+lastname
           slug to the **exact Full Name the admin typed**, trimmed but
           otherwise byte-for-byte unchanged (same case/spacing) — a
           collision against an existing exact name falls back to an
           incrementing numeric suffix (" 1", " 2", ...), since this
           account type has no other ID to derive a suffix from.
         - New required **Password** + **Confirm Password** fields (with
           the same `.toggle-password` eye-icon show/hide markup and
           `assets/js/password-toggle.js` include already used by every
           other password form in this app) replace the old
           `generate_temp_password()` call in the create branch — the admin
           now types the real password themselves; server-side validation
           (8-character minimum, must match confirmation) mirrors the same
           rule used everywhere else in this app. `generate_temp_password()`
           itself is untouched and still used by the separate Reset
           Password action on an existing Dean/Head of Academic
           Affairs/Registration Office account (no admin-typed value exists
           at reset time to fall back to). The password field is
           deliberately never re-populated on a failed submit, same
           convention as every other password input in this app, so a
           validation error can't echo a typed password back into the
           page's HTML source.
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary University Rector and Head of Academic Affairs
        accounts: registered a real lecturer (staff_no `STF-2044`, name
        "Xasan Cali") via the real `head_academic/lecturers.php` form and
        confirmed via direct DB read + `password_verify()` that the
        generated username (`xasanstf2044`) uses the full staff ID and the
        password hash matches it exactly; confirmed a mismatched-confirmation
        password and a too-short password were both rejected (no row
        created either time); created a real Registration Office account
        ("Sahra Maxamed") via the real `admin/users.php` form with a
        manually-typed password and confirmed the stored username is the
        exact typed name (unchanged) and `password_verify()` matches the
        typed password exactly; created a second account with the
        identical name and confirmed the collision-retry suffix correctly
        produced "Sahra Maxamed 1"; confirmed `admin/users.php` itself
        still returns 200 with zero PHP warnings/notices/fatals after all
        of this. All temporary accounts/lecturer were deleted afterward by
        exact email/username match — a same-session verification query
        using a broad `LIKE 'Sahra%'` pattern initially flagged 2
        "remaining" rows, which turned out to be real, unrelated
        pre-existing accounts ("Sahra Ismail Abdi", "Sahra Axmed Warsame")
        coincidentally matching that pattern, not leftover test data;
        confirmed via exact-value queries that every actual test row was
        gone and those two real accounts were correctly left untouched.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Lecturer Credentials Also Switched to Exact Name + Manually-Typed Password
- [x] Immediate follow-up to the Dean/Head of Academic Affairs/Registration
      Office credential change above — the user asked for Lecturer to work
      the same way: username = the Full Name exactly as typed, password
      typed by hand, instead of the name+Staff-ID scheme built earlier this
      same session.
      - `includes/lecturer_accounts.php`'s `generate_admin_username()`
        gained an optional `?int $excludeUserId` parameter (needed for the
        Reset Password self-exclusion fix below) and its docblock was
        widened — it's now the shared username generator for Dean/Head of
        Academic Affairs/Registration Office **and** Lecturer accounts,
        not just the first three.
      - **`admin/lecturers.php`** and **`head_academic/lecturers.php`**
        (the only two single-record lecturer-creation forms — bulk import
        via `admin/lecturers_import.php` was deliberately left on the
        existing name+full-Staff-ID deterministic scheme, since a
        spreadsheet upload has no way to collect a manually-typed password
        per row): the `create`/`register_lecturer` branches now call
        `generate_admin_username($conn, $fullName)` instead of
        `generate_lecturer_username($conn, $fullName, $staffNo)`, and read
        a new required `password`/`password_confirm` POST pair (8-char
        minimum, must match) instead of setting `$tempPassword = $username`.
        Both Add/Register forms gained real Password + Confirm Password
        fields with the same `.toggle-password` eye-icon markup used
        everywhere else in this app (`assets/js/password-toggle.js` newly
        included on both pages), and the stale "This becomes their login
        username base and initial password" helper text was corrected to
        describe the new scheme.
      - **Reset Password** (`admin/lecturers.php` only — the sole lecturer
        page with this action) can't collect a manually-typed password from
        a one-click table-row action, so it keeps the existing
        `generate_temp_password()` random-password fallback (same pattern
        already used for Dean/Head of Academic Affairs/Registration
        Office's own Reset Password) — but the **username** normalization
        was switched from `generate_lecturer_username()` to
        `generate_admin_username($conn, $fullName, $excludeUserId)`, so an
        account created under any older scheme converges onto the new
        exact-name username the moment it's reset. The new
        `$excludeUserId` parameter is what stops this from wrongly treating
        an already-correct username as a collision against itself and
        appending a spurious " 1" suffix on every reset (the exact bug
        class already documented and fixed once before for
        `generate_lecturer_username()`, now fixed here too for
        `generate_admin_username()`).
      - **Verified end-to-end via real HTTP requests** against the live app
        with temporary Head of Academic Affairs and Dean accounts:
        registered a real lecturer ("Fartun Cabdi") via the real
        `head_academic/lecturers.php` form with a manually-typed password,
        confirmed a too-short password and a mismatched confirmation were
        both rejected with zero rows created either time, confirmed the
        real successful creation stored the username as the exact typed
        name and `password_verify()` matched the typed password exactly;
        used the real "Reset Password" button on `admin/lecturers.php` (as
        a temporary Dean scoped to that lecturer's own faculty) and
        confirmed the username stayed exactly "Fartun Cabdi" afterward —
        proving the self-exclusion fix works, not just that it was coded;
        confirmed both `admin/lecturers.php` and `head_academic/lecturers.php`
        return 200 with zero PHP warnings/notices/fatals. All temporary
        accounts and the test lecturer were deleted afterward; confirmed
        zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Course Documents / Course Materials: Course Picker → Per-Course Detail Page
- [x] Both `lecturer/course_documents.php` and `student/course_documents.php`
      previously rendered every course's full document grid (all
      Chapter/Quiz/Assignment tabs, all files) inline on one page at once.
      Per explicit request, restructured into two steps: a grid of
      clickable course cards first, then clicking one opens a single,
      bordered detail view scoped to just that course.
      - **Course picker** (`?course_id` absent): a new `.course-pick-grid`
        of `.course-pick-card` links (course code/name, faculty ·
        department, a file-count badge) — each links to
        `?course_id=X` on the same file. Reuses the existing
        `lecturer_documentable_courses()`/`student_accessible_courses()`
        access-boundary calls unchanged; the picker only decides what's
        *shown*, not what's *reachable* — a `course_id` outside that list
        is never treated as selected (falls back to the picker) regardless
        of what's in the URL, so the boundary can't be bypassed by editing
        the query string.
      - **Single-course detail view** (`?course_id=X`, validated against
        the same accessible-courses list): a "← Back to Courses" link
        above one `.course-doc-card admas-card` — the existing bordered
        card frame (`.admas-card` already carries the app-wide subtle
        sky-tinted border) now framing one course instead of being
        repeated once per course. Contains the exact same Chapter/Quiz/
        Assignment tabs, upload/edit/delete actions, and modals as
        before — no functional changes to uploading, editing, or
        deleting a document, only to how a course is reached first.
      - **`lecturer/course_documents.php`**: every `redirect_to(...)` after
        a successful upload/edit/bulk-upload/delete now redirects back to
        `?course_id=X` (the course just being managed) instead of the bare
        picker URL, so the lecturer lands back where they were, not at the
        top of the course list. The one exception — the initial
        ownership-check failure (`lecturer_can_manage_course_documents()`
        returns false) — still redirects to the bare picker, since the
        `course_id` in that case was never a legitimate one to begin with.
      - **`student/course_documents.php`**: the search box (previously
        filtering courses out of the all-courses grid) moved into the
        detail view, scoped to just the open course via a hidden
        `course_id` field — searching within one course's own documents
        instead of across every course at once.
      - **New CSS** in `assets/css/course_documents.css`:
        `.course-pick-grid`/`.course-pick-card`/`.course-pick-icon` (a
        compact clickable card, hover lift gated behind
        `@media (hover: hover)` so it doesn't stick/jump on a phone tap,
        same convention already used for `.kpi-card`'s own hover rule) and
        `.course-doc-back-link`.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary lecturer (assigned to a real current-semester
        course offering) and a temporary student in the same department:
        confirmed both the picker (`course-pick-card` present) and detail
        (`course-doc-back-link`/tabs present) views render with zero PHP
        warnings/notices/fatals for both roles; performed a real file
        upload through the detail view (multipart POST) and confirmed the
        document landed in the database and the redirect correctly
        returned to that same course's detail view showing the new file;
        confirmed the student's own detail view for one of their actually-
        accessible courses renders correctly with the search box scoped to
        it; confirmed requesting a `course_id` the student does NOT have
        access to silently falls back to the picker rather than leaking
        any content or erroring. All temporary accounts, the temporary
        course offering assignment, and the one test document (row + file)
        were deleted/reverted afterward; confirmed zero stray rows
        remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Lecturer Check-In: Total Xiiso Check-Ins by Course (Lecturer's Own Page + Dean/Head Academic/Rector Accountability Report)
- [x] Per explicit request, made a lecturer's own attendance via Lecturer
      Check-In something concrete and countable, visible both to the
      lecturer themselves and to the three roles who oversee it.
      - New shared `lecturer_checkin_eligible_sessions(mysqli $conn, int
        $lecturerId): array` and `lecturer_checkin_course_summary(mysqli
        $conn, int $lecturerId): array` in `includes/semester_helpers.php`
        — the single source of truth behind every "N of M Xiiso" figure
        this feature now shows anywhere. `eligible_sessions()` is the same
        row-building logic `lecturer/checkin.php` already had inline
        (current-semester offerings × already-happened sessions ×
        whether a `lecturer_checkins` row exists), now shared instead of
        duplicated; `course_summary()` rolls that up to one row per
        course (total eligible sessions, checked-in count, percentage).
      - **`lecturer/checkin.php`**: kept its own existing inline
        row-building (unchanged, to avoid touching logic another
        concurrent session had just extended with scheduled-time columns)
        but now also computes a **"Total Xiiso Check-Ins by Course"**
        table directly from that same `$rows` array — Course / Checked In
        (N of M Xiiso) / a color-coded progress bar (green ≥90%, amber
        ≥70%, red below) — placed right below the existing 3-card KPI
        strip, above the full session-by-session table.
      - **`lecturer_checkins.php`** (the Dean/Head of Academic Affairs/
        University Rector shared report): gained a new **"Total Xiiso
        Check-Ins per Lecturer"** table, built by calling the new shared
        `lecturer_checkin_eligible_sessions()` once per lecturer in the
        same role-scoped `$lecturerOptions` list this page's filter
        dropdown already uses (Dean: own faculty only; the other two:
        university-wide) — lecturers with zero current-semester offerings
        are skipped rather than shown as a confusing 0/0 row, and the
        table sorts lowest-attendance-first so an at-risk lecturer surfaces
        immediately instead of needing to be found in an alphabetical
        list. Each lecturer's name links to `?lecturer_id=X`, which (in
        addition to the existing raw check-in log filter it already did)
        now also reveals that one lecturer's own **per-course breakdown**
        (via `lecturer_checkin_course_summary()`) — the exact same
        Course/Checked-In/progress-bar shape shown on the lecturer's own
        page, so a Dean drilling into one lecturer sees precisely what
        that lecturer sees about themselves.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary lecturer (assigned to a real current-semester
        course offering) and temporary Dean/University Rector accounts: a
        real AJAX check-in against one real past session correctly moved
        the lecturer's own summary from "0 / 11" to "1 / 11 Xiiso" with
        the progress bar rendering; confirmed the Dean's report (scoped to
        the real faculty) listed this lecturer alongside 4 other real
        lecturers' own real, pre-existing totals, all summing to sensible
        figures; confirmed filtering to this lecturer's own
        `?lecturer_id=` showed the identical "1 / 11 Xiiso" both in the
        per-lecturer summary row and the per-course drill-down table;
        confirmed University Rector's own unfiltered view renders the
        university-wide version cleanly. Zero PHP warnings/notices/fatals
        throughout. All temporary accounts, the temporary offering
        assignment, and the one real check-in row created for this test
        were deleted/reverted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### lecturer_checkins.php: Lecturer Profile Photos
- [x] Per explicit request, added the same photo-or-colored-initial avatar
      pattern already used elsewhere in this app (`includes/avatar_helpers.php`'s
      `render_person_avatar_cell()`) to `lecturer_checkins.php` — the
      Lecturer Check-In report shared by University Rector, Dean, and Head
      of Academic Affairs — in both of its tables: the "Total Xiiso
      Check-Ins per Lecturer" accountability summary, and the raw
      check-in/check-out log below it.
      - Both lecturer-option queries (`$lecOptStmt`, used for the filter
        dropdown and the accountability loop) and the main records query
        widened to `JOIN users u ON u.id = l.user_id` and select
        `u.photo_path`, threaded through into `$lecturerAccountability`'s
        rows and `$records`' `lecturer_photo_path` field.
      - Accountability table: the lecturer's name (still a link into
        `?lecturer_id=X` for the drill-down) now wraps the avatar cell
        instead of plain text. Records table: replaced the old plain
        name + "Staff No: ..." text with the same avatar helper, using its
        existing sub-line parameter for the Staff No.
      - **Verified end-to-end via real HTTP requests** with a temporary
        lecturer seeded with a real `photo_path` value and a real
        `lecturer_checkins` row, viewed as a temporary Dean scoped to that
        lecturer's own faculty: confirmed the photo filename rendered in
        both tables (accountability summary and raw log) and every other
        real lecturer without a photo correctly fell back to their
        colored-initial circle, with zero PHP warnings/notices/fatals.
        Temporary accounts, the check-in row, and the offering assignment
        were deleted/reverted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Removed: Compare Semesters
- [x] Deleted `compare_semesters.php` (a University Rector/Head of Academic
      Affairs/Dean report comparing the same course across two semesters
      side by side) and every reference to it: the `includes/nav_items.php`
      sidebar entry (plus its now-orphaned explanatory comment) and its
      card on `reports_hub.php`'s "Reports Hub" (plus that file's own
      header-comment mention). `reports_hub.php` itself and every other
      report card it links to (`reports.php`'s report types, Teaching
      History, Lecturer Check-Ins) are untouched.
      - **Verified end-to-end via real HTTP requests**: confirmed a
        repo-wide grep for `compare_semesters`/`Compare Semesters` returns
        zero matches anywhere in the project; confirmed a temporary
        University Rector account loading both `reports_hub.php` and
        `admin/dashboard.php` (sidebar) sees no trace of it and both pages
        return 200 with zero PHP warnings/notices/fatals. Temporary
        account deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Rector/Dean/Head of Academic Affairs Dashboards: Lecturer Workload Gets Photos + New Lecturer Check-In Ranking
- [x] Per explicit follow-up, "Lecturer Workload" (a Chart.js bar chart of
      each lecturer's current-semester course-offering count) was converted
      into a photo-based leaderboard, and a new sibling widget — "Lecturer
      Check-In Ranking" — was added showing who has actually checked in the
      most, both with the lecturer's own profile photo attached.
      - **Why not just add photos to the bar chart**: Chart.js renders to a
        `<canvas>`, which has no native way to embed an `<img>` per bar
        without a heavy custom plugin. Converted both widgets instead to
        the same avatar-row/progress-bar list style already established for
        `lecturer_checkins.php`'s "Total Xiiso Check-Ins per Lecturer"
        table (`includes/avatar_helpers.php`'s `render_person_avatar_cell()`
        + a thin Bootstrap `.progress` bar showing each lecturer's value
        relative to the row-set's own max) — photos work naturally here,
        and it stays visually consistent with that page.
      - **Lecturer Workload query** widened (all 3 files) to
        `JOIN users u ON u.id = l.user_id` and select `u.photo_path`; the
        old `$lecturerWorkloadLabels`/`$lecturerWorkloadData` arrays (fed
        to Chart.js) replaced by a `$lecturerWorkloadMax` scalar used to
        size each row's progress bar. The old `<canvas
        id="lecturerWorkloadChart">` + its Chart.js `new Chart(...)` init
        block were removed entirely from all three files.
      - **New "Lecturer Check-In Ranking" widget** (all 3 files): total
        `lecturer_checkins` count per lecturer this current semester
        (joined through `sessions`/`semesters` the same way
        `lecturer_checkins.php` already does), **descending** — most
        check-ins first — a recognition/quick-glance leaderboard, the
        opposite sort direction from `lecturer_checkins.php`'s own
        accountability report (which sorts lowest-attendance-first to
        surface who needs follow-up). Scoped identically to Lecturer
        Workload on each file: university-wide for University
        Rector/Head of Academic Affairs, own-faculty-only for Dean (via
        the same `departments.faculty_id` join already used for Workload).
      - New shared CSS in `assets/css/app.css`: `.dash-rank-list`/
        `.dash-rank-row`/`.dash-rank-bar-wrap`/`.dash-rank-count` — a
        small `.who-cell` avatar (fixed 128px width, name truncated with
        an ellipsis if long) beside a flexible progress bar and a raw
        count, one row per lecturer.
      - Dean's/Head of Academic Affairs' chart rows both had their column
        classes widened from 3-per-row (`col-xl-4`) to 4-per-row
        (`col-xl-3`) to fit the new widget alongside the existing ones,
        matching University Rector's own dashboard, which already used
        `col-xl-3` for this row.
      - **Verified end-to-end via real HTTP requests** against the live app
        with a temporary lecturer (a real `photo_path` value, assigned to a
        real current-semester offering, with one real `lecturer_checkins`
        row) and temporary University Rector/Dean/Head of Academic Affairs
        accounts: confirmed all three dashboards return 200 with zero PHP
        warnings/notices/fatals; confirmed the new "Lecturer Check-In
        Ranking" heading and `.dash-rank-row` markup render on all three;
        confirmed the temporary lecturer's photo filename appears in the
        rendered HTML (in Workload on Rector/Head of Academic Affairs; in
        both Workload and the new Ranking widget on Dean's smaller
        faculty-scoped lists); confirmed zero leftover references to the
        removed `lecturerWorkloadChart` canvas id on any of the three
        pages. All temporary accounts, the temporary check-in row, and the
        offering assignment were deleted/reverted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Lecturer Workload/Check-In Ranking: Fixed Overlapping Name + 10/10 Scoring
- [x] Real bug reported with screenshots: the progress bar on both new
      leaderboard widgets visually ran on top of/through the lecturer's own
      name text whenever the name was long enough to approach the fixed
      128px avatar-cell width — a classic flexbox truncation bug. Root
      cause: `.who-cell`'s text wrapper `<div>` (the one holding
      `.who-text-name`/`.who-text-sub`, rendered with no class of its own
      by `render_person_avatar_cell()`) had no `min-width: 0` — a flex
      child never shrinks below its own content's natural width without
      it, so a long name silently overflowed past the 128px box and drew
      underneath the progress bar sitting next to it in the same row,
      instead of being clipped with an ellipsis as the existing
      `text-overflow: ellipsis` rule intended.
      - **Fix** (`assets/css/app.css`): added `max-width: 128px; overflow:
        hidden;` to `.dash-rank-row .who-cell` itself and a new
        `.dash-rank-row .who-cell > div { min-width: 0; overflow: hidden;
        }` rule targeting that unlabeled text wrapper — the actual fix.
      - **Also per explicit request**: both widgets' raw counts (Workload's
        course-offering count, Check-In Ranking's check-in count) now
        display capped to a **/10 scale** ("3/10", "10/10") instead of a
        bare number, with the bar's own width also driven by that same
        fixed 0-10 scale rather than being relative to whichever row in
        the current top-8 happens to have the highest raw count — the two
        widgets can have wildly different natural scales (a lecturer might
        hold 3 course offerings but rack up 25 check-ins), so a
        max-relative bar made the two side-by-side widgets read as
        inconsistent/arbitrary; a shared fixed 0-10 cap (the same "out of
        10" convention `ATTENDANCE_MAX_SCORE` already establishes
        elsewhere in this app) keeps every leaderboard visually comparable
        at a glance. The real, uncapped total is never hidden — it's in
        both the bar's and the count's own `title` hover tooltip.
      - New shared `render_dash_rank_row(?string $photoPath, string
        $fullName, int $rawCount, string $barColor): void` in
        `includes/avatar_helpers.php` replaces the ad-hoc row markup that
        used to be duplicated 6 times (2 widgets × 3 dashboards) with one
        call per row — guarantees the fix and the /10 cap can never drift
        out of sync between University Rector/Dean/Head of Academic
        Affairs' own copies again. The now-unused `$lecturerWorkloadMax`/
        `$lecturerCheckinMax` scalars (the old relative-bar-scale inputs)
        were removed from all three dashboard files.
      - **Verified end-to-end via real HTTP requests** against the live
        app: created a temporary lecturer with a deliberately long full
        name (to reproduce the exact overlap-bug trigger condition) and 15
        real `lecturer_checkins` rows (11 of which fall within the current
        semester's own sessions, to exercise the >10 cap); confirmed
        University Rector's dashboard renders 200 with zero PHP
        warnings/notices/fatals, the long name's full text is present in
        the raw HTML (CSS-clipped visually, not truncated server-side —
        confirmed the new `min-width: 0`/`max-width` rules are the ones
        actually applied), and the Check-In Ranking row shows "10/10" with
        a `title="11 total"` tooltip carrying the real number. All
        temporary lecturer/check-in rows and the offering assignment were
        deleted/reverted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### head_academic/dashboard.php: Weekly Attendance/Attendance by Faculty Shrunk to Match University Rector's Density
- [x] Per explicit request with a screenshot, `head_academic/dashboard.php`'s
      "Weekly Attendance" (previously `col-xl-8`), "Attendance by
      Department" (`col-xl-4`), "Attendance by Faculty" (`col-xl-8`, a
      table), and "Attendance Alerts" (`col-xl-4`) cards — spread across
      two separate wide rows — were merged into **one row of four
      `col-xl-3 col-md-6` cards**, matching `admin/dashboard.php`'s
      (University Rector) own compact 4-per-row density exactly. Pure
      markup reflow (column classes + merging two `<div class="row">`
      wrappers into one) — no query/data logic changed; the "Attendance by
      Faculty" table's own three columns stayed a table (not converted to
      Rector's bar-chart version of the same data), just narrowed to fit
      the smaller column, with its "Avg Attendance" header shortened to
      "Avg" to fit the tighter width.
      - **Verified end-to-end via a real HTTP request** with a temporary
        Head of Academic Affairs account: confirmed the page returns 200
        with zero PHP warnings/notices/fatals, and confirmed via the raw
        HTML that all 4 cards now carry the `col-xl-3 col-md-6` class (8
        total matches for that class string, 4 cards × the class itself
        appearing once per div). Temporary account deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### View Profile Pages (Rector/Dean/Head Academic): Cards Shrunk
- [x] Per explicit request, every card on `admin/student_view.php`
      (Profile Information, Registration Details, Courses & Attendance) and
      `admin/lecturer_view.php` (Profile Information, Assigned Courses,
      Check-In/Out History) — the read-only "View Profile" pages reachable
      by University Rector/Dean/Head of Academic Affairs — was made
      noticeably more compact: smaller card padding, smaller section
      headings/icons, smaller info-tile and registration-field text, tighter
      table rows, and a smaller profile-hero photo/name.
      - Both files' `<div class="page-body">` gained a `view-profile-page`
        scoping class, and every new CSS rule in `assets/css/app.css` is
        written as `.view-profile-page .xyz { ... }` — deliberately scoped
        rather than shrinking `.semester-card`/`.admas-table`/`.badge-pill`
        globally, since those same shared classes are also used on
        unrelated pages (`student/attendance_history.php`,
        `lecturer/teaching_history.php`, `admin/course_offerings.php`,
        `lecturer/courses.php`) that were never asked to shrink and must
        stay exactly as they were.
      - **Verified end-to-end via real HTTP requests** with a temporary
        University Rector account: confirmed both `admin/student_view.php`
        and `admin/lecturer_view.php` (real student/lecturer records)
        return 200 with zero PHP warnings/notices/fatals and carry the new
        `view-profile-page` class; confirmed `student/attendance_history.php`
        (a real student login, unrelated page sharing `.semester-card`)
        renders normally with zero trace of the new scoping class —
        regression-checked so the shrink couldn't have leaked onto it.
        Temporary accounts deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Removed: Grades / Exam Marks / Results / Result Approvals / Top GPA (out of scope for this project)
- [x] The user pointed out that Grade, Top GPA, My Results, and Result
      Approvals had been added to the app — features never requested and,
      per this project's brief (attendance management, not academic
      grading), out of scope — and asked for all of it removed from every
      exam-related place in the project. This work predates this file's
      own Progress Log (grepped the entire log and found zero entries ever
      describing it being built — it existed only as untracked files on
      disk, git status `??`, with no corresponding session record here).
      - **Deleted outright** (no functionality anywhere else depended on
        these): `includes/grade_helpers.php`, `includes/top_gpa_card.php`,
        `lecturer/enter_marks.php`, `result_approvals.php`,
        `student/results.php`, `student/transcript.php`,
        `download_transcript.php`,
        `migrations/2026_08_exam_marks.sql`,
        `migrations/2026_08_result_workflow.sql`.
      - **`includes/nav_items.php`**: removed the "Enter Marks" (lecturer),
        "My Results"/"My Transcript" (student), and "Result Approvals"
        (university_rector/head_academic/dean) nav entries.
      - **`admas_attendance_schema.sql`**: removed the `exam_marks`,
        `result_submissions`, and `result_corrections` `CREATE TABLE`
        blocks and the 4 `grade_weight_*` seed rows from the `settings`
        INSERT — confirmed via a full repo-wide grep afterward that no
        other migration file references any of these three tables or the
        `grade_weight_*` settings keys.
      - **Live database**: confirmed all three tables were genuinely
        empty (0 rows each) before touching them, took a fresh
        `mysqldump` backup of just those three tables anyway (per this
        file's own established convention for any destructive schema
        change — a second, independent `pre_result_workflow_...` backup
        from whenever this feature was originally built already existed
        too), then `DROP TABLE`'d all three (`result_corrections` first,
        since it FK-references `exam_marks`) and deleted the 4
        `grade_weight_*` rows from `settings`. Verified afterward via
        `SHOW TABLES`/a settings count query that all three tables and
        all four settings rows are gone.
      - **Removed every reference across the app**, file by file — each
        was a `require_once .../grade_helpers.php` plus a GPA/results
        query block plus an HTML card/section, all cut cleanly without
        touching the surrounding unrelated code: `student/dashboard.php`
        (the "My Results" table + Cumulative GPA line + the Top-GPA
        leaderboard include), `student/profile.php` (the "My Cumulative
        GPA" / "Grade History — All Semesters" card + Download Transcript
        link + Top-GPA include), `admin/student_view.php` (the
        "Grade History & Results" card + Cumulative GPA badge, University
        Rector's per-student viewer), `head_academic/academic_settings.php`
        (the entire "Grade Weights (Results)" settings card + its
        `save_grade_weights` POST branch), and the Top-GPA leaderboard
        include specifically on `admin/dashboard.php`,
        `head_academic/dashboard.php`, `dean/dashboard.php`,
        `lecturer/dashboard.php`, and `registration/dashboard.php` (five
        separate dashboards, each a one-`require_once` + one-`include` +
        one-variable-assignment removal). Deliberately left untouched:
        `lecturer/course_documents.php`/`student/course_documents.php`/
        `download_course_document.php`/`includes/course_document_helpers.php`
        (Course Materials — a distinct, unrelated file-sharing feature
        that happens to share the word "course", not an exam/grade
        feature) and `lecturer/teaching_history.php` (an attendance/
        teaching-record feature from earlier this same session, not
        exam-related despite a superficially similar name).
      - **Verified end-to-end via real HTTP requests** against the live
        app with one temporary account per role (all 6), plus a linked
        lecturer and student row so those two roles' dashboards exercise
        their full real query path rather than an early-exit empty state:
        confirmed all 8 touched/adjacent pages
        (`admin/dashboard.php`, `head_academic/dashboard.php`,
        `head_academic/academic_settings.php`,
        `registration/dashboard.php`, `dean/dashboard.php`,
        `lecturer/dashboard.php`, `student/dashboard.php`,
        `student/profile.php`) return `200` with zero PHP
        warnings/notices/fatals/deprecation notices; separately confirmed
        `admin/student_view.php?student_id=...` (University Rector's
        viewer, also edited) returns `200` clean for a real student. `php
        -l` was run on all 12 touched PHP files with zero syntax errors.
        All 6 temporary accounts plus their linked lecturer/student rows
        were deleted afterward; confirmed zero stray rows remained via an
        exact follow-up count.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Profile Avatar Rows (photo-or-colour-initial) Everywhere a Person Appears in a List
- [x] Mocked up first (approved: "haah ii samey saaxib") against a reference
      screenshot the user shared of a banking-style customer table — kept
      the layout (circular photo next to name/sub-line, badge pills, an
      action button) but not the reference's dark/gold palette, per
      CLAUDE.md's own branding rules (ADMAS's real sky-blue/navy stays the
      accent everywhere).
      - New shared `includes/avatar_helpers.php`:
        `render_person_avatar_cell(?string $photoPath, string $fullName,
        string $subLine = '', bool $small = false)` — echoes a real
        `users.photo_path` image when one exists, otherwise a circle in a
        colour deterministically derived from the person's name (`crc32()`
        into a fixed 10-colour ADMAS-toned palette — same name always gets
        the same colour, no state needs storing) showing their first
        initial, exactly like the reference screenshot's "a"/"m" circles.
        New `.who-cell`/`.who-avatar`/`.who-avatar-fallback`/`.who-text-*`
        CSS in `assets/css/app.css`, reading the existing theme tokens so
        both light and dark mode stay correct with zero new hardcoded
        colours.
      - **Applied to every list of people already in this app that reads
        `users.photo_path`**: `admin/students.php` (Full Name column ->
        avatar + Student No sub-line), `admin/lecturers.php` and
        `head_academic/lecturers.php` (avatar + Staff No), `admin/users.php`
        and `head_academic/users.php` (merged the separate Username/Full
        Name columns into one avatar cell — name + username sub-line,
        matching the reference's "customer name + email" stack), and
        `attendance.php`'s Grid View roster (a small `who-sm` avatar next
        to each student's name — `includes/attendance_helpers.php`'s
        `get_xiiso_grid_data()` widened all 4 of its roster-query variants
        — enrolled/department-fallback × with/without shift — to also
        `LEFT JOIN users` and select `photo_path`).
      - **Verified end-to-end via real HTTP requests** against the live
        app with temporary University Rector/Head of Academic
        Affairs/Dean accounts: confirmed all 7 touched pages return `200`
        with zero PHP warnings/notices/fatals and the `who-cell` markup
        present; confirmed a real course+semester's Attendance Grid
        (course 2 / semester 1 / afternoon) renders its roster's avatar
        cells cleanly against real enrollment data. All temporary accounts
        deleted afterward; confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Course Documents: Quiz/Assignment/Chapter Types + Card-Per-Course UI + Optional Cover Image
- [x] Three requests together, confirmed to build for real (not another
      mockup round — "dhamaan shaqooyinkaas soo qada qabo"): (1) documents
      should be categorized as Quiz, Assignment, or Chapter — not only
      Chapter 1-7 as before; (2) the whole page should show every course as
      its own card, not a "pick one course first" dropdown; (3) an optional
      cover/background image per document.
      - **Schema** (`migrations/2026_08_course_documents_type_cover.sql`,
        mirrored into `admas_attendance_schema.sql`): added
        `document_type ENUM('chapter','quiz','assignment') NOT NULL DEFAULT
        'chapter'` and `cover_image_path VARCHAR(255) NULL`; made
        `chapter_number` nullable (meaningful only for `document_type =
        'chapter'`) and replaced the old `chk_course_documents_chapter
        BETWEEN 1 AND 7` CHECK with one that also accepts NULL. Applied to
        the live dev DB after a `mysqldump` backup of the table (which
        already held 2 real rows from a prior session — both defaulted
        cleanly to `document_type = 'chapter'`, confirmed unchanged
        afterward).
      - **`includes/course_document_helpers.php`**: added
        `COURSE_DOCUMENT_TYPES` (the 3-value label map driving every
        select/tab in both pages) and `COURSE_DOCUMENT_TYPE_ICONS`; new
        `save_course_document_cover()` — real-content-type validation via
        `getimagesize()` (never the client filename/MIME, same convention
        as `includes/profile_photo.php`), 5MB cap, random filename under
        the same already-`.htaccess`-protected `uploads/course_documents/`
        directory — and `delete_course_document_cover_file()`; the field
        is optional throughout (an empty/absent upload is success, not an
        error).
      - **`lecturer/course_documents.php` rewritten**: every course this
        lecturer manages now renders as its own card on one page (no more
        course-picker dropdown + reload) via a new
        `assets/css/course_documents.css`, each card holding a
        Chapter/Quiz/Assignment pill-tab set with a responsive file-card
        grid underneath — a document with a cover image renders it as the
        card's own background (with a dark gradient for text legibility);
        one without falls back to a colour-tinted file-type icon tile,
        same pattern `includes/avatar_helpers.php` just established for
        people. One shared upload `<div class="modal">`, opened per-course
        via a small JS `admasOpenUploadModal(courseId, courseLabel)`
        (fills a hidden `course_id` + the modal's own title), with a Type
        select that shows/hides the Chapter-number field via
        `admasToggleChapterField()` — the field simply isn't submitted at
        all when hidden, matching the same "hidden-required-field is
        exempt from HTML5 validation" pattern already established
        elsewhere in this app. Server-side, `chapter_number` is only
        validated/stored when `document_type === 'chapter'` (`NULL`
        otherwise) — never trusts the client to have hidden the field
        correctly.
      - **`student/course_documents.php`** got the identical card-per-course
        + type-tabs + cover-image treatment (read-only: Download button
        only, no Upload/Delete), reusing the same
        `assets/css/course_documents.css` — the existing course/document
        search box was kept, now filtering across every course's cards at
        once instead of one selected course's chapters.
      - **`download_course_document.php`** needed no changes — its access
        check and file-streaming logic never referenced chapter/type at
        all.
      - **Verified end-to-end via real HTTP requests** against the live app
        with a temporary lecturer (real course + course_offerings row) and
        a temporary student sharing that same department: uploaded a real
        Chapter document with a cover image and a real Quiz document with
        no cover (via actual multipart file uploads, not simulated),
        confirmed via direct DB read that `chapter_number`/`cover_image_path`
        landed exactly as expected for each (`NULL` for the quiz, both set
        for the chapter one); confirmed both render on the lecturer's own
        card-per-course page (one with a visible cover background, one
        without) with zero PHP warnings/notices/fatals; confirmed the
        student's `course_documents.php` shows both documents identically;
        performed a real download through `download_course_document.php`
        and confirmed the returned bytes were a valid, correct image file.
        All temporary accounts, the temporary course/offering, and both
        uploaded documents (files on disk + DB rows, via
        `delete_course_document_file()`/`delete_course_document_cover_file()`)
        were deleted afterward — confirmed `uploads/course_documents/`
        back to containing only its `.htaccess`, and the 2 real
        pre-existing document rows from before this session untouched.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Course Documents Follow-up: Card Grid, Student's "My Last Documents", Laptop-Density Pass
- [x] Three quick follow-ups to the Course Documents work above, all in one
      request: (1) the course cards on both `lecturer/course_documents.php`
      and `student/course_documents.php` should genuinely spread across the
      page rather than stack one full-width card per row; (2) the student's
      own dashboard should get a "My Last Documents" widget listing the
      most recently shared documents (any type) across their courses, each
      with the document's name and the uploading lecturer's own name +
      profile photo; (3) every dashboard should be denser/more compact so
      more fits on a real laptop screen.
      - **Card grid**: new `.course-doc-grid` rule in
        `assets/css/course_documents.css`
        (`grid-template-columns: repeat(auto-fill, minmax(440px, 1fr))`) —
        each course's card now sizes to its own content and multiple
        courses sit side by side on wide screens instead of always
        stacking. Both PHP files wrap their `foreach ($courses as $c)`
        loop in this new container.
      - **"My Last Documents"**: `student/dashboard.php` gained a new
        query — the 5 most recent `course_documents` rows across every
        course this student can already reach
        (`$accessibleCourseIdsForDocs`, an array already computed on this
        page for the existing "Available Documents" KPI), joined to
        `lecturers`/`users` for the uploader's name and photo. New
        `.last-doc-list`/`.last-doc-row`/`.last-doc-icon`/etc. CSS in
        `assets/css/app.css`; each row reuses
        `includes/avatar_helpers.php`'s `render_person_avatar_cell()`
        (from the earlier Profile Avatar Rows session) for the lecturer's
        photo-or-initial circle, so a new photo upload there is picked up
        here automatically with no separate code path.
      - **Laptop density pass**: new `@media (min-width: 768px) and
        (max-width: 1600px)` block in `assets/css/app.css` trimming
        `.page-body` padding, `.admas-card` border-radius/padding, and
        `.kpi-card`/`.kpi-icon`/`.kpi-value`/`.kpi-label` sizes down a
        notch — same grid/columns as before, just less padding/smaller
        type, so a dashboard's KPI row and cards take less vertical space
        on common laptop widths (1366–1536px class). Desktop (>1600px) and
        the existing phone/tablet breakpoints elsewhere in this file are
        untouched. **Flagged honestly to the user**: this environment has
        no browser/screenshot tool, so this sizing pass could not be
        visually verified beyond confirming the CSS parses and the
        touched pages still render with the expected classes present —
        real visual confirmation on an actual laptop is still needed.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary lecturer (real course + offering), a
        temporary student in the same department, and one document row
        seeded directly (an Assignment, no cover image): confirmed
        `student/dashboard.php` renders "My Last Documents" with the real
        document title, the correct lecturer name, and a `who-cell`
        avatar fallback circle, zero PHP warnings/notices/fatals; confirmed
        both `student/course_documents.php` and
        `lecturer/course_documents.php` render the new `course-doc-grid`
        wrapper class, both 200 with zero errors. All temporary
        accounts/course/offering/document rows deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Bug Fix: "Upload" Button Did Nothing on lecturer/course_documents.php
- [x] User reported the real "Upload" button doing nothing when clicked.
      Root cause: each course card's button built its `onclick` attribute
      with a raw `json_encode()` call —
      `onclick="admasOpenUploadModal(<?= $cid ?>, <?= json_encode(...) ?>)"`
      — but `json_encode()` on a string wraps it in literal, unescaped
      double quotes. Embedded directly inside an already double-quoted
      HTML `onclick="..."` attribute, those inner quotes closed the
      attribute early, silently truncating the JS call and leaving the
      rest as broken, non-functional markup — the button existed but its
      click handler was corrupted, so nothing happened on click, exactly
      matching the report. This is why it wasn't caught by the earlier
      session's `php -l`/HTTP-200/no-PHP-warnings checks: the page still
      rendered and returned 200 fine — this was a client-side HTML/JS
      malformation, not a server error.
      - **Fix**: wrapped the `json_encode()` call in `htmlspecialchars()`
        before printing it into the attribute — converts the outer
        wrapping quotes to `&quot;`, which the browser decodes back to a
        literal `"` before parsing the attribute as JavaScript, so the
        function call arrives intact. Same fix shape needed anywhere a
        `json_encode()`'d value is echoed straight into an HTML attribute
        rather than inside a `<script>` block (inside `<script>`, the raw
        quotes are fine, since there's no surrounding HTML attribute
        delimiter to clash with — confirmed via grep that this is the only
        place in the file doing the attribute case).
      - **Verified end-to-end via a real HTTP request**: created a
        temporary lecturer with a real course/offering (name deliberately
        containing an em dash, to stress-test the fix against the same
        character style as the real reported courses), fetched the actual
        rendered page, and inspected the raw `onclick` attribute in the
        response HTML — confirmed it now reads
        `onclick="admasOpenUploadModal(56, &quot;CL — Calculus Test
        — Em Dash&quot;)"`, a single well-formed attribute (previously
        this would have been truncated at the first literal `"`). Zero PHP
        warnings/notices/fatals. Temporary lecturer/course/offering deleted
        afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Student Dashboard Density Pass + Avatar on reports.php's Xiiso Attendance Grid
- [x] Two follow-ups from a screenshot the user shared of the real, live
      student dashboard: (1) "My Attendance by Course"/"My Course
      Attendance" were taking too much vertical space, pushing "My Last
      Documents" further down than intended — shrink them; (2) the
      photo-or-initial avatar pattern from the earlier Profile Avatar Rows
      session should also reach Reports, the same way it already reached
      Students/Lecturers/Staff/the live Attendance grid.
      - **`student/dashboard.php`**: `#courseAttendanceChart`'s canvas
        height dropped 200px -> 130px; both cards switched from `p-4` to
        `p-3` with their headings restyled to the same small-uppercase-muted
        style already used elsewhere (e.g. `admin/settings.php`'s card
        headings) instead of a full `h6.fw-bold`; the attendance table
        gained Bootstrap's `table-sm` class for tighter row padding; "My
        Last Documents" got the same `p-3`/small-uppercase-heading
        treatment for visual consistency with its two neighbors above it.
      - **`reports.php`'s Xiiso Attendance Grid report**: this report's
        `[$columns, $rows]` shape is shared verbatim by the on-screen HTML
        table AND the Excel/PDF export renderers (same generic render loop
        for all 4 report types), so the avatar couldn't just be baked into
        the row's `full_name` value — that would have corrupted the
        Excel/PDF output with raw HTML. Instead, `build_xiiso_grid_report()`
        now also carries a `photo_path` key on each row (reusing
        `get_xiiso_grid_data()`'s own already-widened queries — see the
        original Profile Avatar Rows session, which had already added
        `u.photo_path` there for `attendance.php`'s own live Grid View) —
        a key that was never part of `$columns`, so the Excel/PDF loops
        (which only ever read `$col['key']`) can't see or render it at all.
        The **on-screen HTML render loop only** special-cases the
        `full_name` column: when the row actually carries a `photo_path`
        key (true only for Xiiso Grid rows, never the other 3 report
        types), it calls `render_person_avatar_cell()` instead of the
        generic `htmlspecialchars()` output.
      - **Verified end-to-end via real HTTP requests** against the live
        app: confirmed `student/dashboard.php` returns 200 with zero PHP
        warnings/notices/fatals and the new `table-sm` class + "My Last
        Documents" section both render; confirmed a real Xiiso Attendance
        Grid report (course 2 / semester 1 / afternoon shift, as
        University Rector) renders the `who-cell` avatar markup on-screen,
        200, zero PHP errors; **regression-checked both exports** for the
        exact same report — Excel (`application/vnd.openxmlformats-
        officedocument.spreadsheetml.sheet`) and PDF (`application/pdf`)
        both still returned valid files with the correct content types,
        confirming the `photo_path` addition never leaked into either
        export. All temporary accounts/students deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### student/dashboard.php: "My Course Attendance" Heading Now Names the Current Semester
- [x] Per explicit request, the card heading changed from the generic "My
      Course Attendance" to `"Course Attendance — {Semester Name}"` (e.g.
      "Course Attendance — Semester 9") whenever the student has a resolved
      current semester (`$ownCurrentSemester`, already computed earlier on
      this page for the `.semester-scope-banner`) — falls back to the old
      generic heading only when no current semester could be resolved at
      all. Makes it visually unambiguous, right on the card itself, that
      the table below reflects the student's own current semester, not a
      lifetime/all-time figure — the same fact the banner above already
      states, now repeated at the point of use.
      - **Verified end-to-end via a real HTTP request**: created a
        temporary student in a real faculty with a real current semester,
        confirmed the heading rendered exactly `"Course Attendance —
        Semester 6"` — the student's own `(faculty_id, academic_year_id)`
        cohort's actual current semester (distinct from a different
        current semester also active for the same faculty under a
        different academic year), proving the existing per-cohort
        resolution logic still drives this heading correctly rather than
        just naming whichever semester happens to be current somewhere.
        Zero PHP warnings/notices/fatals. Temporary student deleted
        afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Density Pass Extended: Lecturer/Dean/Head of Academic Affairs Dashboards
- [x] Same "shrink so more fits on a laptop screen" treatment already
      applied to `student/dashboard.php` extended to the three other main
      role dashboards, per explicit request naming Lecturer's "My Assigned
      Courses"/chart specifically (and making sure "Pending Xiiso Sessions"
      stays comfortably reachable, not pushed further down) plus Dean and
      Head of Academic Affairs generally.
      - **`lecturer/dashboard.php`**: "My Attendance by Course" chart
        height 220px -> 140px; both it and "My Assigned Courses" switched
        `p-4` -> `p-3` with small-uppercase-muted headings (same style
        already established on `student/dashboard.php`) and the courses
        table gained `table-sm`; "Pending Xiiso Sessions" got the same
        `p-3`/small-heading/`table-sm` treatment.
      - **`dean/dashboard.php`**: "Departments in My Faculty" and "Low
        Attendance — My Faculty" (the only two cards on this page still on
        the older `p-4`/full-heading style — its two chart cards below them
        were already `p-3`/small-heading from an earlier density session,
        confirmed by reading the code rather than assumed) switched to the
        same `p-3`/small-uppercase-heading/`table-sm` pattern.
      - **`head_academic/dashboard.php`**: "Attendance by Faculty" and
        "Attendance Alerts" (its own two chart cards were likewise already
        `p-3`) given the identical treatment.
      - **Verified end-to-end via real HTTP requests** against the live
        app with temporary Dean (real faculty), Head of Academic Affairs,
        and Lecturer (real linked `lecturers` row) accounts: confirmed all
        three dashboards return 200 with zero PHP warnings/notices/fatals
        and the new `table-sm` class present. Temporary accounts/lecturer
        row deleted afterward.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Class Time Table (Day/Time/Room per Course Offering)
- [x] New feature, planned via an explicit no-code discussion round first
      (per the user's own request — reference: a real printed ADMAS Class
      Time Table screenshot, Day columns × Time-slot rows, Course/
      Lecturer/Room per cell). Confirmed three design decisions via
      `AskUserQuestion` before writing anything: (1) **who sets it** —
      Dean (own faculty) + Head of Academic Affairs (any faculty) — the
      two roles that already have full CRUD on `course_offerings` via
      Manage Offerings/Assign Courses; Registration Office explicitly
      excluded (out of scope per CLAUDE.md §4 — no Attendance/Course
      access), University Rector stays view-only; (2) **one day/time per
      offering**, not a repeating multi-day schedule — simpler, matches
      how the real printed table already lists one slot per course, no
      new table needed; (3) **Room is cosmetic only** — no double-booking
      validation anywhere, confirmed explicitly ("qolku muhiim ma ahan
      balse waa qurxis keliya").
      - **Schema** (`migrations/2026_08_course_offerings_timetable.sql`,
        mirrored into `admas_attendance_schema.sql`, `mysqldump` backup
        taken first): `course_offerings` gained `day_of_week ENUM(7 days)`,
        `start_time TIME`, `end_time TIME`, `room VARCHAR(50)` — all
        nullable, so no existing offering is forced to have a schedule.
      - **New `includes/timetable_helpers.php`**: `DAY_OF_WEEK_LABELS` +
        `DAY_OF_WEEK_DISPLAY_ORDER` (Saturday-first, matching ADMAS's real
        Sat-Thu teaching week, not a Monday-first Western one),
        `format_timetable_time()`, and the shared
        `build_class_timetable_grid(array $offerings): array` — pivots a
        flat list of scheduled rows into `{time_slots: [...chronological
        distinct Day/Time windows...], cells: [slotKey][day] = [...rows
        in that slot...]}`, skipping any row with no day/time set (an
        unscheduled offering simply doesn't appear, rather than rendering
        a broken slot). One shared function reused by all three
        consuming pages below, so the grid-building logic can never drift
        between them. New `.timetable-table`/`.timetable-slot`/etc. CSS in
        `assets/css/app.css`, reading the existing theme tokens.
      - **Set the schedule**: `admin/course_offerings.php` ("Manage
        Offerings") and `lecturer_courses.php` ("Assign Courses") both
        gained Day/Start Time/End Time/Room fields on their Add/Update
        forms (Dean/Head of Academic Affairs only — University Rector's
        pre-existing `$isReadOnly` gate on both files already blocks this
        for free) and a "Class Time Table" column on their existing
        offerings-list tables, so the schedule is visible right where
        it's set, not just on the new dedicated view. Validation: day
        must be a real enum value; start/end time must be given together
        or not at all (never just one); end must be after start; room has
        no format validation at all (purely cosmetic, per the user's own
        explicit confirmation) — all upserted into the same
        `(course_id, semester_id, shift)`-keyed row the rest of that form
        already saves, via `ON DUPLICATE KEY UPDATE`.
      - **View it — three places**: `class_timetable.php` (new, app root,
        `university_rector`/`dean`/`head_academic`, read-only for all
        three — editing only ever happens on the two pages above) is the
        full university-wide (or, for Dean, own-faculty-locked) grid, with
        Faculty/Department/Semester/Shift filters (Semester defaults to
        "current semester(s) only", not every offering ever scheduled,
        to avoid a cluttered grid of historical data) — added to
        `includes/nav_items.php`'s Academic Management group for those
        three roles. `student/dashboard.php` and `lecturer/dashboard.php`
        each gained their own compact "Class Time Table" card showing
        just that person's own current-semester schedule — the student's
        version reuses the exact same course-discovery query already
        built for "My Course Attendance" on that page (same
        `$courseIds`/`$currentSemesterId`/`$ownShift`), just resolving
        the offering's schedule fields instead of attendance marks; the
        lecturer's version reuses the existing "My Assigned Courses"
        query verbatim (widened to also select the 4 new columns) rather
        than a second query.
      - **Seeded all 18 real, pre-existing `course_offerings` rows** with
        sample Day/Time/Room values (rotating through the 6 teaching days
        and 5 common time slots, "Room 1"–"Room 6") per the user's
        explicit request to see the feature working against real data
        immediately, not an empty grid — done via a one-off script, not
        left in the codebase.
      - **Verified end-to-end via real HTTP requests** against the live
        app: confirmed a real `save_offering` POST as Head of Academic
        Affairs with Day/Time/Room values succeeded (302) and the exact
        values landed in the database; confirmed the identical POST as
        University Rector was rejected (redirected with zero DB change,
        the pre-existing `$isReadOnly` gate), confirming the "who sets it"
        decision is enforced, not just hidden in the UI; confirmed
        `class_timetable.php` renders real seeded data for University
        Rector and Head of Academic Affairs (`timetable-slot` markup
        present, 200, zero PHP errors) and correctly showed no matching
        slots for a Dean scoped to a faculty/current-semester combination
        with none seeded (a real "no data for this scope" case, not a
        bug); confirmed a real temporary student (enrolled in a real
        scheduled course) and a real temporary lecturer (assigned to that
        same offering) both saw the correct course code in their own
        dashboard's Class Time Table card, 200, zero PHP errors. The one
        offering temporarily modified during the write-permission test was
        restored to its original seeded value afterward; all temporary
        accounts/enrollment/lecturer-assignment were reverted/deleted;
        confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Class Time Table Follow-up: Student's Own Print-Style Page + Dashboard Card Repositioning
- [x] Three follow-ups to the Class Time Table feature above, all from the
      same real printed-timetable reference screenshot: (1) students get
      their own dedicated "Class Time Table" sidebar item, styled to
      closely resemble that exact printed sheet (logo/name/campus header,
      "Faculty:", "Year N  Semester M", "Starting Date", Sat-Thu day
      columns, "REGISTRAR" signature line) — not just the compact
      dashboard card from the earlier session; (2) shrink the Class Time
      Table card's frame on both the student and lecturer dashboards so it
      reads closer to the reference's compact printed look; (3) move that
      card to sit below "Course Attendance — Semester N" (student) / "My
      Assigned Courses" (lecturer) instead of above them.
      - **New `student/class_timetable.php`**: `require_role(['student'])`,
        own-record-only (same "never trust request input, always resolve
        from `current_user()`" convention as every other student page).
        Resolves the student's own per-cohort current semester (same
        `(faculty_id, academic_year_id)`-scoped query already used on
        `student/dashboard.php`) and reuses the exact same course-
        discovery logic (`course_enrollments` → department fallback →
        guest-offering `roster_department_id`) as `student/courses.php`/
        `student/dashboard.php`, so this page can never disagree with
        those on "which courses does this student have." New
        `semester_year_number()` moved into the shared
        `includes/timetable_helpers.php` (previously a private function
        living only inside `semesters.php`) so this page could compute
        "Year N" the same way `semesters.php` already displays it, without
        duplicating the formula by hand.
      - **New `.timetable-print-*` CSS** in `assets/css/app.css` — a
        bordered card, circular logo + bold university/campus name,
        Faculty/Year-Semester lines, a tan/peach day-header row (matching
        the reference screenshot's own header color), and a bold
        "REGISTRAR" signature line at the bottom. The grid itself only
        renders the Sat-Thu columns (`DAY_OF_WEEK_DISPLAY_ORDER` minus
        Friday) — the reference sheet has no Friday column either, since
        that's the real weekly off day.
      - **`includes/nav_items.php`**: added a second "Class Time Table"
        entry (`roles: ['student']`, no `path` override, resolving via the
        default `student/class_timetable.php` convention) alongside the
        existing Rector/Dean/Head-of-Academic-Affairs one — same
        same-label/disjoint-roles/different-file pattern already used for
        "My Courses" and the two "Lecturers" entries elsewhere in this
        file.
      - **Dashboard cards moved + shrunk**: on both `student/dashboard.php`
        and `lecturer/dashboard.php`, the Class Time Table card was cut
        from its old position (directly under the KPI row, above
        everything else) and re-inserted immediately after "Course
        Attendance — Semester N" / "My Assigned Courses" respectively —
        before "My Last Documents" (student) / "Pending Xiiso Sessions"
        (lecturer). New `.timetable-compact` CSS modifier class (smaller
        time-column width, smaller header/cell padding, smaller slot
        text) applied to both dashboard instances only — the new
        dedicated `student/class_timetable.php` print-style page and the
        university-wide `class_timetable.php` keep the full, uncompacted
        size, since only the dashboard *cards* were asked to shrink, not
        every view of the feature. The student dashboard's card also
        gained a "View full →" link to the new dedicated page.
      - **Verified end-to-end via real HTTP requests** against the live
        app with a temporary student (real enrollment in a real scheduled
        course) and a temporary lecturer (assigned to that same
        offering): confirmed both the new sidebar link and the
        `student/class_timetable.php` page itself render correctly (200,
        zero PHP warnings/notices/fatals, the real university name/
        Faculty line/REGISTRAR signature all present in the raw HTML);
        confirmed, by comparing line numbers in the raw HTML response, that
        "Course Attendance — Semester ..." now appears BEFORE "Class Time
        Table" which appears BEFORE "My Last Documents" on the student
        dashboard, and "My Assigned Courses" appears BEFORE "Class Time
        Table" which appears BEFORE "Pending Xiiso Sessions" on the
        lecturer dashboard — confirming the reordering actually took
        effect, not just that the code was edited; confirmed the
        `timetable-compact` class is present in the rendered lecturer
        dashboard HTML. All temporary accounts/enrollment/lecturer-
        assignment reverted/deleted afterward; confirmed zero stray rows
        remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Class Time Table Compact Card Added to Rector/Dean/Head of Academic Affairs Dashboards
- [x] The student/lecturer dashboards' compact Class Time Table card
      extended to the three remaining dashboards, per explicit request —
      each scoped appropriately since none of these three roles has a
      personal set of "my own courses" the way a student/lecturer does:
      **Dean** gets their own faculty's current-semester schedule (own-
      faculty-scoped, same as every other Dean widget on that dashboard);
      **University Rector** and **Head of Academic Affairs** both get the
      full university-wide current-semester schedule (same default scope
      `class_timetable.php` itself already uses).
      - `admin/dashboard.php` (University Rector), `dean/dashboard.php`,
        and `head_academic/dashboard.php` each gained a new query (reusing
        `includes/timetable_helpers.php`'s shared
        `build_class_timetable_grid()`, same as every other consumer of
        this feature) and a `.timetable-compact` card placed near the
        bottom of the page, with a "View full →" link to the shared
        `class_timetable.php` page. Dean's query adds `se.faculty_id = ?`
        bound to `$deanFacultyId` (session-derived, never trusted from
        request input, same convention as every other Dean-scoped query
        in this app); the other two have no faculty filter at all.
      - **Verified end-to-end via real HTTP requests** against the live
        app with temporary University Rector, Head of Academic Affairs,
        and Dean (scoped to a real faculty with real seeded scheduled
        offerings) accounts: confirmed all three dashboards return 200
        with zero PHP warnings/notices/fatals and the `timetable-compact`
        class present in the rendered HTML. Temporary accounts deleted
        afterward; confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

### Class Time Table: Print-Style Cards on Student/Lecturer Dashboards, Rector/Dean/Head Academic Cards Removed, Course Names Everywhere
- [x] Three follow-ups from the same reference screenshot, all in one
      request: (1) replace the student and lecturer dashboards' plain
      compact-grid Class Time Table card with the small print-style card
      (logo/name header, day-band grid, plain stacked cell text) already
      built for `student/class_timetable.php` — the exact look shown in
      the screenshot; (2) remove the Class Time Table card entirely from
      the University Rector, Dean, and Head of Academic Affairs
      dashboards (added in the immediately preceding session) — the
      sidebar link to the shared `class_timetable.php` page stays, only
      the dashboard card itself is gone; (3) everywhere in the project a
      Class Time Table cell shows a course, show its full name, never the
      short code.
      - **New shared `render_class_timetable_grid_table(array $grid,
        array $dayOrder, string $courseLabelKey, string $tableClass =
        ''): void`** in `includes/timetable_helpers.php` — the one
        `<table>` markup (day header row + time-slot rows + plain
        stacked-text `.timetable-print-cell`s) now reused by
        `class_timetable.php`'s own grid (the row-level Day/Time/Room
        list, still using its own richer per-cell meta line with
        Department/Semester — left custom, not switched to the shared
        function, since it needs more per-cell detail than the shared
        signature carries), `student/class_timetable.php`, and the new
        student/lecturer dashboard cards — one function, so all three can
        never drift on markup again. `$courseLabelKey` lets each caller
        pick which column holds the course's own full name (`course_name`
        on the student page/card, `name` on the lecturer's own `$myCourses`
        rows) — never a `code` column, closing the actual bug this session
        fixes.
      - **`student/dashboard.php`**: gained `includes/university_logo.php`
        + a `semester_year_number()`/`$dashboardLogoRelativePath` setup
        (reusing the page's already-computed `$ownRow`/`$ownCurrentSemester`
        — widened their own SELECTs to also fetch `f.semesters_per_year`
        and `se.start_date`, both needed for the header) and the plain
        compact grid card replaced with a `.timetable-print-card
        .timetable-print-compact` card — logo + university name + Faculty
        line + Year/Semester line + "Starting Date", then the shared grid
        table. `lecturer/dashboard.php` got the equivalent treatment,
        minus the Faculty/Year line (a lecturer's own courses can span
        multiple faculties, so no single "Faculty:" claim would be
        accurate) — shows the lecturer's own name instead.
      - **New `.timetable-print-compact` CSS** in `assets/css/app.css` —
        scales the full print-style card (42px logo, smaller type, tighter
        table) down to dashboard-widget size, while
        `student/class_timetable.php`'s own dedicated page keeps the full
        un-scaled size.
      - **Dashboard cards removed**: `admin/dashboard.php` (University
        Rector), `dean/dashboard.php`, and `head_academic/dashboard.php`
        each had their Class Time Table query and card block deleted back
        out entirely (including the now-unused `require_once
        includes/timetable_helpers.php` on all three) — a clean revert of
        the previous session's addition, not a hide-via-CSS.
        `includes/nav_items.php`'s existing "Class Time Table" sidebar
        entry for these three roles was untouched, so the shared page
        itself is still reachable exactly as before.
      - **Course code → full name**: `class_timetable.php`'s own grid cell
        switched from `$cell['code']` to `$cell['course_name']` (the
        column was already being selected by its SQL query — only the
        cell's own render line needed fixing).
      - **Verified end-to-end via real HTTP requests** against the live
        app: created a temporary student (enrolled in a real course) and
        a temporary lecturer (assigned to that same offering) and
        confirmed both dashboards render the new print-style card with
        zero PHP warnings/notices/fatals — inspected the raw cell HTML
        directly and confirmed it shows the real full course name
        ("Applied mathematic") rather than its code ("AP"), alongside the
        lecturer name and room; confirmed a temporary University Rector's
        own dashboard no longer contains a Class Time Table *card* (the
        one remaining match for the text was traced to the sidebar nav
        link itself, not a card, confirmed by inspecting its surrounding
        HTML); confirmed `class_timetable.php` itself still loads (200,
        zero errors) and now shows real full course names ("Calculus",
        "fundmental of database") in its own grid cells instead of codes.
        All temporary accounts/enrollment/lecturer-assignment
        reverted/deleted afterward; confirmed zero stray rows remained.
      - Not yet committed to git — pending the user's request, per this
        project's commit convention.

