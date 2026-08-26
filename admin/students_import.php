<?php
/**
 * Bulk-import Students from an Excel (.xlsx/.xls) or CSV file.
 * Flow: upload -> preview (per-row validation) -> confirm -> redirect back
 * to admin/students.php with a success message (same pattern as
 * courses_import.php and lecturers_import.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/nav_items.php';
require_once __DIR__ . '/../includes/lecturer_accounts.php';
require_once __DIR__ . '/../includes/audit_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Registration Office also has bulk Excel import per CLAUDE.md §4 — see
// admin/students.php for the same reasoning.
require_role(['registration']);

use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

$conn = db();

const IMPORT_SHIFT_LABELS = [
    'morning' => 'Morning Shift',
    'afternoon' => 'Afternoon Shift',
    'weekend' => 'Weekend',
];

/**
 * Accept either the raw enum value ("morning") or the friendly label
 * ("Morning Shift"), case-insensitively.
 */
function normalize_shift_input(string $input): ?string
{
    $normalized = mb_strtolower(trim($input));
    if (array_key_exists($normalized, IMPORT_SHIFT_LABELS)) {
        return $normalized;
    }
    foreach (IMPORT_SHIFT_LABELS as $value => $label) {
        if (mb_strtolower($label) === $normalized) {
            return $value;
        }
    }

    return null;
}

/**
 * Tolerant date-cell parser for the optional Birth Date/Enrollment Date
 * columns — accepts an already-formatted string (the common case, since
 * the sheet is read with $formatData = true so a real date cell usually
 * arrives pre-formatted) via strtotime(), or a raw Excel date serial
 * number (a cell PhpSpreadsheet couldn't format, e.g. no explicit date
 * format applied in the sheet). Returns null for blank/unparseable input
 * — the caller treats null as "leave this field blank", not an error,
 * since these are optional fields.
 */
function parse_import_date(string $input): ?string
{
    $input = trim($input);
    if ($input === '') {
        return null;
    }

    if (is_numeric($input)) {
        try {
            $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $input);

            return $dateTime->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    $timestamp = strtotime($input);

    return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
}

/**
 * Validate + resolve one import row's raw field values into the same
 * "preview row" shape stored in $_SESSION['student_import_preview'] and
 * later read back by the 'confirm' action. Shared by the initial file-parse
 * loop AND the 'edit_row' action (a Registration Office user correcting one
 * bad row in-place, without re-uploading the whole file) — both call this
 * exact function so the two can never validate a row differently.
 *
 * $raw keys (all raw strings, already trim()med by the caller):
 * student_no, student_name, mother_name, sex_input, birth_date_input,
 * street_address, phone, email_input, emergency_contact_name,
 * emergency_contact_phone, nationality, enrollment_date_input,
 * certificate_type, school_roll_number, degree, program, class_year,
 * year_input, faculty_input, department_input, semester_input, shift_input.
 *
 * $otherStudentNosInFile — uppercased student_no values from every OTHER
 * row currently in the file/session (never including this row's own),
 * used for the "duplicate within this file" check.
 */
function validate_student_import_row(
    mysqli $conn,
    array $raw,
    int $rowNumber,
    array $academicYearByLowerLabel,
    array $facultyByLowerName,
    array $departmentByFacultyAndLowerName,
    array $departmentCodeById,
    array $semesterByFacultyAndLowerName,
    array $semesterByFacultyAndDigits,
    array $otherStudentNosInFile
): array {
    $studentNo = strtoupper(trim((string) $raw['student_no']));
    $nameParts = split_student_full_name((string) $raw['student_name']);
    $firstName = $nameParts['first_name'];
    $fatherName = $nameParts['father_name'];
    $grandfatherName = $nameParts['grandfather_name'] ?? '';
    $fullName = trim($firstName . ' ' . $fatherName . ' ' . $grandfatherName);

    $motherName = trim((string) $raw['mother_name']);
    $sexInput = trim((string) $raw['sex_input']);
    $birthDateInput = trim((string) $raw['birth_date_input']);
    $streetAddress = trim((string) $raw['street_address']);
    $phone = trim((string) $raw['phone']);
    $emailInput = trim((string) $raw['email_input']);
    $emergencyContactName = trim((string) $raw['emergency_contact_name']);
    $emergencyContactPhone = trim((string) $raw['emergency_contact_phone']);
    $nationality = trim((string) $raw['nationality']);
    $enrollmentDateInput = trim((string) $raw['enrollment_date_input']);
    $certificateType = trim((string) $raw['certificate_type']);
    $schoolRollNumber = trim((string) $raw['school_roll_number']);
    $degree = trim((string) $raw['degree']);
    $program = trim((string) $raw['program']);
    $classYear = trim((string) $raw['class_year']);
    $yearInput = trim((string) $raw['year_input']);
    $facultyInput = trim((string) $raw['faculty_input']);
    $departmentInput = trim((string) $raw['department_input']);
    $semesterInput = trim((string) $raw['semester_input']);
    $shiftInput = trim((string) $raw['shift_input']);

    $status = 'ok';
    $message = 'Ready to import';
    $academicYearId = 0;
    $facultyId = 0;
    $departmentId = 0;
    $semesterId = 0;
    $shift = null;
    $sex = null;
    $birthDate = null;
    $enrollmentDate = null;

    if ($studentNo === '') {
        $status = 'error';
        $message = 'Missing Student No';
    } elseif ($firstName === '') {
        $status = 'error';
        $message = 'Missing First Name';
    } elseif ($fatherName === '') {
        $status = 'error';
        $message = "Missing Father's Name";
    } elseif ($yearInput === '') {
        $status = 'error';
        $message = 'Missing Academic Year';
    } elseif ($facultyInput === '') {
        $status = 'error';
        $message = 'Missing Faculty';
    } elseif ($departmentInput === '') {
        $status = 'error';
        $message = 'Missing Department';
    } elseif ($semesterInput === '') {
        $status = 'error';
        $message = 'Missing Semester';
    } elseif ($shiftInput === '') {
        $status = 'error';
        $message = 'Missing Shift';
    } elseif ($motherName === '') {
        $status = 'error';
        $message = "Missing Mother's Name";
    } elseif ($sexInput === '') {
        $status = 'error';
        $message = 'Missing Sex';
    } elseif ($birthDateInput === '') {
        $status = 'error';
        $message = 'Missing Birth Date';
    } elseif ($streetAddress === '') {
        $status = 'error';
        $message = 'Missing Street Address';
    } elseif ($phone === '') {
        $status = 'error';
        $message = 'Missing Student Phone';
    } elseif ($emailInput === '') {
        $status = 'error';
        $message = 'Missing Student Email';
    } elseif ($emergencyContactName === '') {
        $status = 'error';
        $message = 'Missing Emergency Contact Name';
    } elseif ($emergencyContactPhone === '') {
        $status = 'error';
        $message = 'Missing Emergency Contact Phone';
    } elseif ($nationality === '') {
        $status = 'error';
        $message = 'Missing Nationality';
    } elseif ($enrollmentDateInput === '') {
        $status = 'error';
        $message = 'Missing Enrollment Date';
    } elseif ($certificateType === '') {
        $status = 'error';
        $message = 'Missing Certificate Type';
    } elseif ($schoolRollNumber === '') {
        $status = 'error';
        $message = 'Missing School Roll Number';
    } elseif ($degree === '') {
        $status = 'error';
        $message = 'Missing Degree';
    } elseif ($program === '') {
        $status = 'error';
        $message = 'Missing Program';
    } elseif ($classYear === '') {
        $status = 'error';
        $message = 'Missing Class Year';
    } else {
        $academicYearId = $academicYearByLowerLabel[mb_strtolower($yearInput)] ?? 0;
        if ($academicYearId === 0) {
            $status = 'error';
            $message = 'Unknown academic year "' . $yearInput . '"';
        }
    }

    if ($status === 'ok') {
        $facultyId = $facultyByLowerName[mb_strtolower($facultyInput)] ?? 0;
        if ($facultyId === 0) {
            $status = 'error';
            $message = 'Unknown faculty "' . $facultyInput . '"';
        }
    }

    if ($status === 'ok') {
        $deptKey = $facultyId . '|' . mb_strtolower($departmentInput);
        $departmentId = $departmentByFacultyAndLowerName[$deptKey] ?? 0;
        if ($departmentId === 0) {
            $status = 'error';
            $message = 'Unknown department "' . $departmentInput . '" in faculty "' . $facultyInput . '"';
        }
    }

    if ($status === 'ok') {
        $semKey = $facultyId . '|' . mb_strtolower($semesterInput);
        $semesterId = $semesterByFacultyAndLowerName[$semKey] ?? 0;

        if ($semesterId === 0) {
            $digits = preg_replace('/\D/', '', $semesterInput);
            if ($digits !== '') {
                $digitsId = $semesterByFacultyAndDigits[$facultyId . '|' . $digits] ?? 0;
                if ($digitsId > 0) {
                    $semesterId = $digitsId;
                }
            }
        }

        if ($semesterId === 0) {
            $status = 'error';
            $message = 'Unknown semester "' . $semesterInput . '" in faculty "' . $facultyInput . '"';
        }
    }

    if ($status === 'ok') {
        $shift = normalize_shift_input($shiftInput);
        if ($shift === null) {
            $status = 'error';
            $message = 'Invalid Shift "' . $shiftInput . '" (use Morning Shift, Afternoon Shift, or Weekend)';
        }
    }

    if ($status === 'ok') {
        if (in_array($studentNo, $otherStudentNosInFile, true)) {
            $status = 'error';
            $message = 'Duplicate Student No within this file';
        } else {
            $dupNoStmt = $conn->prepare('SELECT id FROM students WHERE UPPER(student_no) = ?');
            $dupNoStmt->bind_param('s', $studentNo);
            $dupNoStmt->execute();
            if ($dupNoStmt->get_result()->fetch_assoc()) {
                $status = 'error';
                $message = 'Student No already exists';
            }
            $dupNoStmt->close();
        }
    }

    // Presence of these was already required above; here we validate the
    // actual format/uniqueness of what was given.
    if ($status === 'ok' && $sexInput !== '') {
        $normalizedSex = mb_strtolower($sexInput);
        $sex = in_array($normalizedSex, ['male', 'm'], true) ? 'male'
            : (in_array($normalizedSex, ['female', 'f'], true) ? 'female' : null);
        if ($sex === null) {
            $status = 'error';
            $message = 'Invalid Sex "' . $sexInput . '" (use Male or Female)';
        }
    }

    if ($status === 'ok' && $birthDateInput !== '') {
        $birthDate = parse_import_date($birthDateInput);
        if ($birthDate === null) {
            $status = 'error';
            $message = 'Invalid Birth Date "' . $birthDateInput . '"';
        }
    }

    if ($status === 'ok' && $enrollmentDateInput !== '') {
        $enrollmentDate = parse_import_date($enrollmentDateInput);
        if ($enrollmentDate === null) {
            $status = 'error';
            $message = 'Invalid Enrollment Date "' . $enrollmentDateInput . '"';
        }
    }

    if ($status === 'ok' && $emailInput !== '') {
        if (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $status = 'error';
            $message = 'Invalid Student Email "' . $emailInput . '"';
        } else {
            $emailCheckStmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
            $emailCheckStmt->bind_param('s', $emailInput);
            $emailCheckStmt->execute();
            if ($emailCheckStmt->get_result()->fetch_assoc()) {
                $status = 'error';
                $message = 'Student Email already used by another account';
            }
            $emailCheckStmt->close();
        }
    }

    return [
        'row' => $rowNumber,
        'student_no' => $studentNo,
        'first_name' => $firstName,
        'father_name' => $fatherName,
        'grandfather_name' => $grandfatherName,
        'full_name' => $fullName,
        'mother_name' => $motherName,
        'sex' => $sex,
        'sex_input' => $sexInput,
        'birth_date' => $birthDate,
        'birth_date_input' => $birthDateInput,
        'street_address' => $streetAddress,
        'phone' => $phone,
        'email' => $emailInput !== '' ? $emailInput : null,
        'email_input' => $emailInput,
        'emergency_contact_name' => $emergencyContactName,
        'emergency_contact_phone' => $emergencyContactPhone,
        'nationality' => $nationality,
        'enrollment_date' => $enrollmentDate,
        'enrollment_date_input' => $enrollmentDateInput,
        'certificate_type' => $certificateType,
        'school_roll_number' => $schoolRollNumber,
        'degree' => $degree,
        'program' => $program,
        'class_year' => $classYear,
        'year_input' => $yearInput,
        'academic_year_id' => $academicYearId,
        'faculty_input' => $facultyInput,
        'faculty_id' => $facultyId,
        'department_input' => $departmentInput,
        'department_id' => $departmentId,
        'department_code' => $departmentCodeById[$departmentId] ?? '',
        'semester_input' => $semesterInput,
        'semester_id' => $semesterId,
        'shift_input' => $shiftInput,
        'shift' => $shift,
        'status' => $status,
        'message' => $message,
    ];
}

// ---------------------------------------------------------------------
// Downloadable template (must run before any HTML output)
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'template') {
    // Matches the university's real "Enrollment" Excel form (Student ID
    // Number, Student Name, Mother Name, Sex, ..., Class Year), plus
    // "Student No" (this app's required unique admission/ID number — same
    // column as "Student ID Number") and "Shift" (not part of the real
    // Enrollment form, added here since this app needs it to build a
    // student's course roster).
    $templateHeaders = [
        'Student No', 'Student Name', "Mother's Name", 'Sex', 'Birth Date', 'Street Address',
        'Student Phone', 'Student Email', 'Emergency Contact Name', 'Emergency Contact Phone',
        'Nationality', 'Enrollment Date', 'Certificate Type', 'School Roll Number', 'Degree',
        'Faculty', 'Department', 'Program', 'Academic Year', 'Semester', 'Class Year', 'Shift',
    ];
    $templateSample = [
        '1472/23', 'Amina Hassan Ali', 'Khadija Nuur', 'Female', '2004-03-12', 'Garowe, Puntland',
        '+252 90 111 2222', 'amina.hassan@example.com', 'Hassan Ali', '+252 90 333 4444',
        'Somali', '2025-09-01', 'High School Diploma', 'HS-2025-014', 'Bachelor',
        'Engineering & IT', 'Computer Science', 'Computer Science', '2025', 'Semester 1', '1st Year', 'Morning Shift',
    ];

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($templateHeaders, null, 'A1');
    $sheet->fromArray($templateSample, null, 'A2');
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($templateHeaders));
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="student_import_template.xlsx"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

// ---------------------------------------------------------------------
// Download Students — a real, ready-to-re-import backup of every current
// student (or, when filtered, a matching subset), in the EXACT column
// shape as the template above (same headers, same order) so it can be
// uploaded straight back through this same page with zero remapping —
// built specifically so Registration Office can snapshot the real roster
// before a Danger Zone factory reset, then re-import it afterward.
// Unlike the template, every row here is a real current student, not a
// sample. Faculty/Department/Semester/Shift/Academic Year filters are all
// optional — omitted or "0"/blank means no filter on that field, same
// convention as admin/students.php's own filter bar.
// ---------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'download_students') {
    $backupHeaders = [
        'Student No', 'Student Name', "Mother's Name", 'Sex', 'Birth Date', 'Street Address',
        'Student Phone', 'Student Email', 'Emergency Contact Name', 'Emergency Contact Phone',
        'Nationality', 'Enrollment Date', 'Certificate Type', 'School Roll Number', 'Degree',
        'Faculty', 'Department', 'Program', 'Academic Year', 'Semester', 'Class Year', 'Shift',
    ];

    $dlFacultyId = (int) ($_GET['faculty_id'] ?? 0);
    $dlDepartmentId = (int) ($_GET['department_id'] ?? 0);
    $dlSemesterId = (int) ($_GET['semester_id'] ?? 0);
    $dlAcademicYearId = (int) ($_GET['academic_year_id'] ?? 0);
    $dlShift = (string) ($_GET['shift'] ?? '');

    $backupWhere = [];
    $backupParams = [];
    $backupTypes = '';
    if ($dlFacultyId > 0) {
        $backupWhere[] = 's.faculty_id = ?';
        $backupParams[] = $dlFacultyId;
        $backupTypes .= 'i';
    }
    if ($dlDepartmentId > 0) {
        $backupWhere[] = 's.department_id = ?';
        $backupParams[] = $dlDepartmentId;
        $backupTypes .= 'i';
    }
    if ($dlSemesterId > 0) {
        $backupWhere[] = 's.semester_id = ?';
        $backupParams[] = $dlSemesterId;
        $backupTypes .= 'i';
    }
    if ($dlAcademicYearId > 0) {
        $backupWhere[] = 's.academic_year_id = ?';
        $backupParams[] = $dlAcademicYearId;
        $backupTypes .= 'i';
    }
    if ($dlShift !== '' && array_key_exists($dlShift, IMPORT_SHIFT_LABELS)) {
        $backupWhere[] = 's.shift = ?';
        $backupParams[] = $dlShift;
        $backupTypes .= 's';
    }

    $backupSql = "SELECT s.student_no, s.full_name, s.mother_name, s.sex, s.birth_date, s.street_address,
                s.phone, u.email, s.emergency_contact_name, s.emergency_contact_phone,
                s.nationality, s.enrollment_date, s.certificate_type, s.school_roll_number, s.degree,
                f.name AS faculty_name, d.name AS department_name, s.program, ay.label AS academic_year_label,
                sem.name AS semester_name, s.class_year, s.shift
         FROM students s
         JOIN users u ON u.id = s.user_id
         JOIN faculties f ON f.id = s.faculty_id
         JOIN departments d ON d.id = s.department_id
         JOIN academic_years ay ON ay.id = s.academic_year_id
         LEFT JOIN semesters sem ON sem.id = s.semester_id";
    if ($backupWhere !== []) {
        $backupSql .= ' WHERE ' . implode(' AND ', $backupWhere);
    }
    $backupSql .= ' ORDER BY f.name, d.name, s.full_name';

    $backupStmt = $conn->prepare($backupSql);
    if ($backupTypes !== '') {
        $backupStmt->bind_param($backupTypes, ...$backupParams);
    }
    $backupStmt->execute();
    $backupResult = $backupStmt->get_result();

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray($backupHeaders, null, 'A1');

    $rowNum = 2;
    $sexLabels = ['male' => 'Male', 'female' => 'Female'];
    // Every column is filled — a blank real value is replaced with a
    // placeholder (free-text fields get "N/A"; Sex/Birth Date/Enrollment
    // Date, which the importer validates as an enum/date rather than free
    // text, get a safe default so the file re-imports with zero "Missing
    // X" errors even for older records that predate these fields being
    // required) — per explicit request, so this backup is always
    // immediately re-importable regardless of data gaps in the live
    // record. Registration Office can correct any placeholder value for a
    // real student later through the normal Edit flow.
    $today = date('Y-m-d');
    while ($row = $backupResult->fetch_assoc()) {
        $sheet->fromArray([
            $row['student_no'],
            $row['full_name'],
            $row['mother_name'] !== null && $row['mother_name'] !== '' ? $row['mother_name'] : 'N/A',
            $sexLabels[$row['sex']] ?? 'Male',
            $row['birth_date'] ?: $today,
            $row['street_address'] !== null && $row['street_address'] !== '' ? $row['street_address'] : 'N/A',
            $row['phone'] !== null && $row['phone'] !== '' ? $row['phone'] : 'N/A',
            $row['email'] !== null && $row['email'] !== '' ? $row['email'] : 'N/A',
            $row['emergency_contact_name'] !== null && $row['emergency_contact_name'] !== '' ? $row['emergency_contact_name'] : 'N/A',
            $row['emergency_contact_phone'] !== null && $row['emergency_contact_phone'] !== '' ? $row['emergency_contact_phone'] : 'N/A',
            $row['nationality'] !== null && $row['nationality'] !== '' ? $row['nationality'] : 'N/A',
            $row['enrollment_date'] ?: $today,
            $row['certificate_type'] !== null && $row['certificate_type'] !== '' ? $row['certificate_type'] : 'N/A',
            $row['school_roll_number'] !== null && $row['school_roll_number'] !== '' ? $row['school_roll_number'] : 'N/A',
            $row['degree'] !== null && $row['degree'] !== '' ? $row['degree'] : 'N/A',
            $row['faculty_name'],
            $row['department_name'],
            $row['program'] !== null && $row['program'] !== '' ? $row['program'] : 'N/A',
            $row['academic_year_label'],
            $row['semester_name'] ?? '',
            $row['class_year'] !== null && $row['class_year'] !== '' ? $row['class_year'] : 'N/A',
            IMPORT_SHIFT_LABELS[$row['shift']] ?? $row['shift'],
        ], null, 'A' . $rowNum);
        $rowNum++;
    }
    $backupStmt->close();
    $backupExportedCount = $rowNum - 2;

    // Log every download — not the write actions this helper normally
    // records, but a real, occasional-oversight-worthy event since it's a
    // full export of real students' personal data. Doubles as the source
    // for the "last downloaded" summary shown on the dedicated Download
    // Students page. Looked up directly here (small, single-row queries)
    // rather than via $existingFaculties/etc. — those aren't defined yet
    // at this point in the file (this action block runs and exits before
    // that later section), so reusing them here would always resolve to
    // an empty/undefined array and silently report "no filter" even when
    // one was genuinely applied.
    $backupFilterParts = [];
    $backupFacultyName = '';
    $backupDepartmentName = '';
    $backupAcademicYearLabel = '';
    if ($dlFacultyId > 0) {
        $lookupStmt = $conn->prepare('SELECT name FROM faculties WHERE id = ?');
        $lookupStmt->bind_param('i', $dlFacultyId);
        $lookupStmt->execute();
        if ($lookupRow = $lookupStmt->get_result()->fetch_assoc()) {
            $backupFacultyName = (string) $lookupRow['name'];
            $backupFilterParts[] = 'Faculty: ' . $backupFacultyName;
        }
        $lookupStmt->close();
    }
    if ($dlDepartmentId > 0) {
        $lookupStmt = $conn->prepare('SELECT name FROM departments WHERE id = ?');
        $lookupStmt->bind_param('i', $dlDepartmentId);
        $lookupStmt->execute();
        if ($lookupRow = $lookupStmt->get_result()->fetch_assoc()) {
            $backupDepartmentName = (string) $lookupRow['name'];
            $backupFilterParts[] = 'Department: ' . $backupDepartmentName;
        }
        $lookupStmt->close();
    }
    if ($dlSemesterId > 0) {
        $lookupStmt = $conn->prepare('SELECT name FROM semesters WHERE id = ?');
        $lookupStmt->bind_param('i', $dlSemesterId);
        $lookupStmt->execute();
        if ($lookupRow = $lookupStmt->get_result()->fetch_assoc()) {
            $backupFilterParts[] = 'Semester: ' . $lookupRow['name'];
        }
        $lookupStmt->close();
    }
    if ($dlAcademicYearId > 0) {
        $lookupStmt = $conn->prepare('SELECT label FROM academic_years WHERE id = ?');
        $lookupStmt->bind_param('i', $dlAcademicYearId);
        $lookupStmt->execute();
        if ($lookupRow = $lookupStmt->get_result()->fetch_assoc()) {
            $backupAcademicYearLabel = (string) $lookupRow['label'];
            $backupFilterParts[] = 'Academic Year: ' . $backupAcademicYearLabel;
        }
        $lookupStmt->close();
    }
    if ($dlShift !== '' && array_key_exists($dlShift, IMPORT_SHIFT_LABELS)) {
        $backupFilterParts[] = 'Shift: ' . IMPORT_SHIFT_LABELS[$dlShift];
    }
    $backupDetails = $backupExportedCount . ' student(s) downloaded'
        . ($backupFilterParts !== [] ? ' (' . implode(', ', $backupFilterParts) . ')' : ' (no filter — everyone)');
    audit_log($conn, 'download_students', 'student', null, null, $backupDetails);

    // Filename reflects whichever of Faculty/Department/Academic Year was
    // actually filtered on — e.g. "students_Informatics_Computer-Science_2025_2026-08-25.xlsx"
    // — falling back to the plain date-only name when nothing was filtered
    // (a full "everyone" export). Semester/Shift aren't part of the name
    // (per explicit request naming only these three), and every piece is
    // sanitized to safe filename characters.
    $sanitizeForFilename = static function (string $value): string {
        $value = str_replace(['/', '\\'], '-', $value);
        $value = preg_replace('/[^A-Za-z0-9 _.-]/', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/', '_', trim($value)) ?? '');

        return $value;
    };
    $filenameParts = ['students'];
    if ($backupFacultyName !== '') {
        $filenameParts[] = $sanitizeForFilename($backupFacultyName);
    }
    if ($backupDepartmentName !== '') {
        $filenameParts[] = $sanitizeForFilename($backupDepartmentName);
    }
    if ($backupAcademicYearLabel !== '') {
        $filenameParts[] = $sanitizeForFilename($backupAcademicYearLabel);
    }
    $filenameParts[] = date('Y-m-d');
    $backupFilename = implode('_', array_filter($filenameParts, static fn ($p) => $p !== '')) . '.xlsx';

    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($backupHeaders));
    $sheet->getStyle("A1:{$lastCol}1")->getFont()->setBold(true);
    foreach (range('A', $lastCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $backupFilename . '"');
    header('Cache-Control: max-age=0');

    (new XlsxWriter($spreadsheet))->save('php://output');
    exit;
}

$studentRoleId = 0;
$roleResult = $conn->query("SELECT id FROM roles WHERE name = 'student'");
if ($roleResult && ($roleRow = $roleResult->fetch_assoc())) {
    $studentRoleId = (int) $roleRow['id'];
}

$currentUser = current_user();

// ---------------------------------------------------------------------
// University settings (drives the sky-blue top strip)
// ---------------------------------------------------------------------
$settings = [];
$settingsResult = $conn->query('SELECT `key`, `value` FROM settings');
if ($settingsResult) {
    while ($row = $settingsResult->fetch_assoc()) {
        $settings[$row['key']] = $row['value'];
    }
}

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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Never resume a stale preview across a fresh page load.
    unset($_SESSION['student_import_preview']);
}

$step = 'upload';
$previewRows = [];

$existingAcademicYears = $conn->query('SELECT id, label FROM academic_years ORDER BY label')->fetch_all(MYSQLI_ASSOC);
$academicYearByLowerLabel = [];
foreach ($existingAcademicYears as $ay) {
    $academicYearByLowerLabel[mb_strtolower(trim((string) $ay['label']))] = (int) $ay['id'];
}

$existingFaculties = $conn->query('SELECT id, name, code FROM faculties ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$facultyByLowerName = [];
foreach ($existingFaculties as $fac) {
    $facultyByLowerName[mb_strtolower(trim((string) $fac['name']))] = (int) $fac['id'];
}

$existingDepartments = $conn->query('SELECT id, code, name, faculty_id FROM departments ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departmentByFacultyAndLowerName = [];
$departmentCodeById = [];
foreach ($existingDepartments as $dept) {
    $key = (int) $dept['faculty_id'] . '|' . mb_strtolower(trim((string) $dept['name']));
    $departmentByFacultyAndLowerName[$key] = (int) $dept['id'];
    $departmentCodeById[(int) $dept['id']] = (string) $dept['code'];
}

// Semesters are scoped to a faculty, same lookup shape as departments —
// a "Semester 1" name only resolves within the row's already-resolved
// Faculty, never globally.
$existingSemesters = $conn->query('SELECT id, name, faculty_id FROM semesters ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$semesterByFacultyAndLowerName = [];
// Fallback for a bare number ("1", "Semester 1") against a semester named
// e.g. "semester1" — only kept when the digits are unambiguous within the
// faculty (no two semesters in the same faculty reducing to the same digits).
$semesterByFacultyAndDigits = [];
foreach ($existingSemesters as $sem) {
    $facultyId = (int) $sem['faculty_id'];
    $name = mb_strtolower(trim((string) $sem['name']));
    $key = $facultyId . '|' . $name;
    $semesterByFacultyAndLowerName[$key] = (int) $sem['id'];

    $digits = preg_replace('/\D/', '', $name);
    if ($digits !== '') {
        $digitsKey = $facultyId . '|' . $digits;
        $semesterByFacultyAndDigits[$digitsKey] = array_key_exists($digitsKey, $semesterByFacultyAndDigits)
            ? -1 // ambiguous: two semesters in this faculty share the same digits — don't guess
            : (int) $sem['id'];
    }
}

// ---------------------------------------------------------------------
// Handle POST actions: preview, confirm, cancel
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'preview') {
        if (empty($existingFaculties) || empty($existingDepartments) || empty($existingAcademicYears) || empty($existingSemesters)) {
            $errorMessage = 'At least one Academic Year, Faculty, Department, and Semester must exist before importing students.';
        } elseif (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = 'Please choose a valid Excel or CSV file to upload.';
        } else {
            $originalName = (string) $_FILES['import_file']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
                $errorMessage = 'Unsupported file type. Please upload a .xlsx, .xls, or .csv file.';
            } else {
                try {
                    $reader = match ($extension) {
                        'xlsx' => new Xlsx(),
                        'xls' => new Xls(),
                        'csv' => new Csv(),
                    };
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($_FILES['import_file']['tmp_name']);
                    $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

                    if (empty($rows)) {
                        $errorMessage = 'The uploaded file is empty.';
                    } else {
                        // Some sheets put shared info at the top as "Field:", "value"
                        // rows (e.g. "Faculty:", "Informatic") before the actual
                        // Name/ID table — common when a whole sheet is one class
                        // where every student shares the same Academic Year/Faculty/
                        // Department/Semester/Shift. Peel those off as defaults so
                        // the per-row table only strictly needs Student No + Full
                        // Name; any of these 5 fields can still be overridden with
                        // its own column in the table below.
                        $batchDefaults = ['academic_year' => '', 'faculty' => '', 'department' => '', 'semester' => '', 'shift' => ''];
                        $batchFieldLabels = [
                            'academic_year' => ['academic year', 'sanadka waxbarasho', 'sanadka waxbarashada', 'sanadka'],
                            'faculty' => ['faculty', 'kulliyadda', 'kulliyad'],
                            'department' => ['department', 'waaxda', 'waax'],
                            'semester' => ['semester', 'semesterka'],
                            'shift' => ['shift', 'shiftka'],
                        ];

                        $tableStart = 0;
                        while ($tableStart < count($rows)) {
                            $label = str_replace([':', '-'], '', mb_strtolower(trim((string) ($rows[$tableStart][0] ?? ''))));
                            $matchedField = null;
                            foreach ($batchFieldLabels as $field => $labels) {
                                if (in_array($label, $labels, true)) {
                                    $matchedField = $field;
                                    break;
                                }
                            }
                            if ($matchedField === null) {
                                break;
                            }
                            $batchDefaults[$matchedField] = trim((string) ($rows[$tableStart][1] ?? ''));
                            $tableStart++;
                        }
                        // Skip a blank separator row between the batch-header block
                        // and the table.
                        while ($tableStart < count($rows) && count(array_filter($rows[$tableStart], static fn ($c) => trim((string) $c) !== '')) === 0) {
                            $tableStart++;
                        }

                        // Some real sheets also have a decorative title banner above
                        // the table (university name, faculty, course/lecturer info —
                        // same idea as attendance_import.php's own banner-skipping),
                        // which won't match the "Field:", "value" pattern above and
                        // isn't blank either. Keep advancing past any row that doesn't
                        // actually look like the real header row (i.e. doesn't contain
                        // a Student No/REG-No-like or First Name-like cell anywhere in
                        // it) until the real header row is found.
                        $looksLikeHeaderRow = static function (array $row): bool {
                            $normalizedCells = array_map(
                                static fn ($h) => str_replace('-', '', mb_strtolower(trim((string) $h))),
                                $row
                            );
                            $studentNoCandidates = ['student no', 'studentno', 'student id number', 'studentidnumber', 'id', 'idga ardayga', 'idga', 'lambarka ardayga', 'lambarka', 'reg no', 'regno', 'reg/no'];
                            $studentNameCandidates = ['student name', 'studentname', 'full name', 'fullname', 'magaca ardayga', 'magaca oo dhan'];

                            return find_import_column($normalizedCells, $studentNoCandidates) !== false
                                || find_import_column($normalizedCells, $studentNameCandidates) !== false;
                        };
                        while ($tableStart < count($rows) && !$looksLikeHeaderRow($rows[$tableStart])) {
                            $tableStart++;
                        }

                        if ($tableStart >= count($rows)) {
                            $errorMessage = 'No student table was found in the uploaded file.';
                        } else {
                        $headerRow = array_map(static fn ($h) => str_replace('-', '', mb_strtolower(trim((string) $h))), $rows[$tableStart]);
                        $dataRows = array_slice($rows, $tableStart + 1);
                        $rowNumber = $tableStart + 1;

                        // Each field accepts English and Somali header synonyms, so
                        // staff can write the sheet in whichever language is natural
                        // for them without needing to rename columns first. Hyphens
                        // are stripped from both sides above, so "ID-ga"/"id ga"/
                        // "idga" all match the same candidate.
                        $studentNoCol = find_import_column($headerRow, ['student no', 'studentno', 'student id number', 'studentidnumber', 'id', 'idga ardayga', 'idga', 'lambarka ardayga', 'lambarka', 'reg no', 'regno', 'reg/no']);
                        // "Student Name" — the real Enrollment template's own single
                        // combined-name column. The older separate "First Names"/
                        // "Father's Name"/"G.Father's" column format is no longer
                        // accepted at all — only this new full-Enrollment-template
                        // format is, per explicit instruction. The name is always
                        // split via split_student_full_name() into the 3 stored
                        // columns.
                        $studentNameCol = find_import_column($headerRow, ['student name', 'studentname', 'full name', 'fullname', 'magaca ardayga', 'magaca oo dhan']);
                        $yearCol = find_import_column($headerRow, ['academic year', 'sanadka waxbarasho', 'sanadka waxbarashada', 'sanadka']);
                        $facultyCol = find_import_column($headerRow, ['faculty', 'kulliyadda', 'kulliyad']);
                        $departmentCol = find_import_column($headerRow, ['department', 'department name', 'waaxda', 'waax']);
                        $semesterCol = find_import_column($headerRow, ['semester', 'semesterka']);
                        $shiftCol = find_import_column($headerRow, ['shift', 'shiftka']);
                        // The Enrollment template's remaining fields — this is real
                        // registration data, so every one of these is required per row
                        // (checked below); a row with any of these blank is flagged as an
                        // error, not silently imported with a blank value.
                        $motherNameCol = find_import_column($headerRow, ["mother's name", 'mothers name', 'mother name', 'magaca hooyada']);
                        $sexCol = find_import_column($headerRow, ['sex', 'gender', 'jinsiyada']);
                        $birthDateCol = find_import_column($headerRow, ['birth date', 'birthdate', 'date of birth', 'dob', 'taariikhda dhalashada']);
                        $streetAddressCol = find_import_column($headerRow, ['street address', 'street addres', 'address', 'cinwaanka']);
                        $phoneCol = find_import_column($headerRow, ['student phone', 'phone', 'phone number', 'telefoonka']);
                        $emailCol = find_import_column($headerRow, ['student email', 'email', 'email address', 'emailka']);
                        $emergencyContactNameCol = find_import_column($headerRow, ['emergency contact name', 'emergency contact', 'qofka xaalada degdegga ah']);
                        $emergencyContactPhoneCol = find_import_column($headerRow, ['emergency contact phone', 'emergency phone', 'taleefanka xaalada degdegga ah']);
                        $nationalityCol = find_import_column($headerRow, ['nationality', 'jinsiyadda']);
                        $enrollmentDateCol = find_import_column($headerRow, ['enrollment date', 'enrollmentdate', 'date of enrollment', 'taariikhda diiwaangelinta']);
                        $certificateTypeCol = find_import_column($headerRow, ['certificate type', 'certificatetype', 'nooca shahaadada']);
                        $schoolRollNumberCol = find_import_column($headerRow, ['school roll number', 'schoolrollnumber', 'roll number', 'lambarka dugsiga']);
                        $degreeCol = find_import_column($headerRow, ['degree', 'shahaadada']);
                        $programCol = find_import_column($headerRow, ['program', 'barnaamijka']);
                        $classYearCol = find_import_column($headerRow, ['class year', 'classyear', 'sanadka fasalka']);

                        $missingRequired = $studentNoCol === false
                            || $studentNameCol === false
                            || ($yearCol === false && $batchDefaults['academic_year'] === '')
                            || ($facultyCol === false && $batchDefaults['faculty'] === '')
                            || ($departmentCol === false && $batchDefaults['department'] === '')
                            || ($semesterCol === false && $batchDefaults['semester'] === '')
                            || ($shiftCol === false && $batchDefaults['shift'] === '');

                        if ($missingRequired) {
                            $errorMessage = 'The file must have "Student No" (or "Student ID Number"/"REG/NO") and "Student Name" columns (the older separate "First Names"/"Father\'s Name" column format is no longer accepted), plus Academic Year, Faculty, Department, Semester and Shift — either as columns in the table, or as "Field:", "value" rows above it.';
                        } else {
                            // Pass 1: pull every row's raw cell values only — no
                            // validation yet — so pass 2 can build each row's
                            // "every OTHER row's Student No" duplicate-check set from
                            // the complete file, not just rows seen earlier in it.
                            $rawRows = [];
                            foreach ($dataRows as $row) {
                                $rowNumber++;
                                $studentNoRaw = trim((string) ($row[$studentNoCol] ?? ''));
                                $studentNameRaw = trim((string) ($row[$studentNameCol] ?? ''));

                                if ($studentNoRaw === '' && $studentNameRaw === '') {
                                    continue;
                                }

                                $yearInput = $yearCol !== false ? trim((string) ($row[$yearCol] ?? '')) : '';
                                if ($yearInput === '') {
                                    $yearInput = $batchDefaults['academic_year'];
                                }
                                $facultyInput = $facultyCol !== false ? trim((string) ($row[$facultyCol] ?? '')) : '';
                                if ($facultyInput === '') {
                                    $facultyInput = $batchDefaults['faculty'];
                                }
                                $departmentInput = $departmentCol !== false ? trim((string) ($row[$departmentCol] ?? '')) : '';
                                if ($departmentInput === '') {
                                    $departmentInput = $batchDefaults['department'];
                                }
                                $semesterInput = $semesterCol !== false ? trim((string) ($row[$semesterCol] ?? '')) : '';
                                if ($semesterInput === '') {
                                    $semesterInput = $batchDefaults['semester'];
                                }
                                $shiftInput = $shiftCol !== false ? trim((string) ($row[$shiftCol] ?? '')) : '';
                                if ($shiftInput === '') {
                                    $shiftInput = $batchDefaults['shift'];
                                }

                                $rawRows[] = [
                                    'row_number' => $rowNumber,
                                    'student_no' => $studentNoRaw,
                                    'student_name' => $studentNameRaw,
                                    'mother_name' => $motherNameCol !== false ? trim((string) ($row[$motherNameCol] ?? '')) : '',
                                    'sex_input' => $sexCol !== false ? trim((string) ($row[$sexCol] ?? '')) : '',
                                    'birth_date_input' => $birthDateCol !== false ? trim((string) ($row[$birthDateCol] ?? '')) : '',
                                    'street_address' => $streetAddressCol !== false ? trim((string) ($row[$streetAddressCol] ?? '')) : '',
                                    'phone' => $phoneCol !== false ? trim((string) ($row[$phoneCol] ?? '')) : '',
                                    'email_input' => $emailCol !== false ? trim((string) ($row[$emailCol] ?? '')) : '',
                                    'emergency_contact_name' => $emergencyContactNameCol !== false ? trim((string) ($row[$emergencyContactNameCol] ?? '')) : '',
                                    'emergency_contact_phone' => $emergencyContactPhoneCol !== false ? trim((string) ($row[$emergencyContactPhoneCol] ?? '')) : '',
                                    'nationality' => $nationalityCol !== false ? trim((string) ($row[$nationalityCol] ?? '')) : '',
                                    'enrollment_date_input' => $enrollmentDateCol !== false ? trim((string) ($row[$enrollmentDateCol] ?? '')) : '',
                                    'certificate_type' => $certificateTypeCol !== false ? trim((string) ($row[$certificateTypeCol] ?? '')) : '',
                                    'school_roll_number' => $schoolRollNumberCol !== false ? trim((string) ($row[$schoolRollNumberCol] ?? '')) : '',
                                    'degree' => $degreeCol !== false ? trim((string) ($row[$degreeCol] ?? '')) : '',
                                    'program' => $programCol !== false ? trim((string) ($row[$programCol] ?? '')) : '',
                                    'class_year' => $classYearCol !== false ? trim((string) ($row[$classYearCol] ?? '')) : '',
                                    'year_input' => $yearInput,
                                    'faculty_input' => $facultyInput,
                                    'department_input' => $departmentInput,
                                    'semester_input' => $semesterInput,
                                    'shift_input' => $shiftInput,
                                ];
                            }

                            // Pass 2: validate each row against every OTHER row's
                            // (uppercased) Student No — the exact same function the
                            // 'edit_row' action reuses below.
                            foreach ($rawRows as $i => $rawRow) {
                                $otherStudentNos = [];
                                foreach ($rawRows as $j => $otherRow) {
                                    if ($j !== $i) {
                                        $otherStudentNos[] = strtoupper(trim((string) $otherRow['student_no']));
                                    }
                                }
                                $previewRows[] = validate_student_import_row(
                                    $conn,
                                    $rawRow,
                                    (int) $rawRow['row_number'],
                                    $academicYearByLowerLabel,
                                    $facultyByLowerName,
                                    $departmentByFacultyAndLowerName,
                                    $departmentCodeById,
                                    $semesterByFacultyAndLowerName,
                                    $semesterByFacultyAndDigits,
                                    $otherStudentNos
                                );
                            }

                            if (empty($previewRows)) {
                                $errorMessage = 'No data rows were found in the uploaded file.';
                            } else {
                                $_SESSION['student_import_preview'] = $previewRows;
                                $step = 'preview';
                            }
                        }
                        }
                    }
                } catch (\Throwable $e) {
                    $errorMessage = 'Could not read the uploaded file. Please make sure it is a valid Excel or CSV file.';
                }
            }
        }
    } elseif ($action === 'edit_row') {
        // Lets Registration Office correct a single bad row directly in the
        // preview — without re-uploading the whole file — by re-running the
        // exact same validate_student_import_row() the initial parse used,
        // then swapping just that one row in the session array.
        $previewRows = $_SESSION['student_import_preview'] ?? [];
        $rowIndex = (int) ($_POST['row_index'] ?? -1);

        if (empty($previewRows) || !array_key_exists($rowIndex, $previewRows)) {
            $errorMessage = 'Your import session expired or that row no longer exists. Please upload the file again.';
            $previewRows = [];
            unset($_SESSION['student_import_preview']);
        } else {
            $raw = [
                'student_no' => (string) ($_POST['student_no'] ?? ''),
                'student_name' => (string) ($_POST['student_name'] ?? ''),
                'mother_name' => (string) ($_POST['mother_name'] ?? ''),
                'sex_input' => (string) ($_POST['sex'] ?? ''),
                'birth_date_input' => (string) ($_POST['birth_date'] ?? ''),
                'street_address' => (string) ($_POST['street_address'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'email_input' => (string) ($_POST['email'] ?? ''),
                'emergency_contact_name' => (string) ($_POST['emergency_contact_name'] ?? ''),
                'emergency_contact_phone' => (string) ($_POST['emergency_contact_phone'] ?? ''),
                'nationality' => (string) ($_POST['nationality'] ?? ''),
                'enrollment_date_input' => (string) ($_POST['enrollment_date'] ?? ''),
                'certificate_type' => (string) ($_POST['certificate_type'] ?? ''),
                'school_roll_number' => (string) ($_POST['school_roll_number'] ?? ''),
                'degree' => (string) ($_POST['degree'] ?? ''),
                'program' => (string) ($_POST['program'] ?? ''),
                'class_year' => (string) ($_POST['class_year'] ?? ''),
                'year_input' => (string) ($_POST['year_input'] ?? ''),
                'faculty_input' => (string) ($_POST['faculty_input'] ?? ''),
                'department_input' => (string) ($_POST['department_input'] ?? ''),
                'semester_input' => (string) ($_POST['semester_input'] ?? ''),
                'shift_input' => (string) ($_POST['shift_input'] ?? ''),
            ];

            $otherStudentNos = [];
            foreach ($previewRows as $i => $existingRow) {
                if ($i !== $rowIndex) {
                    $otherStudentNos[] = strtoupper(trim((string) $existingRow['student_no']));
                }
            }

            $previewRows[$rowIndex] = validate_student_import_row(
                $conn,
                $raw,
                (int) $previewRows[$rowIndex]['row'],
                $academicYearByLowerLabel,
                $facultyByLowerName,
                $departmentByFacultyAndLowerName,
                $departmentCodeById,
                $semesterByFacultyAndLowerName,
                $semesterByFacultyAndDigits,
                $otherStudentNos
            );

            $_SESSION['student_import_preview'] = $previewRows;
            $step = 'preview';
            $successMessage = $previewRows[$rowIndex]['status'] === 'ok'
                ? 'Row ' . (int) $previewRows[$rowIndex]['row'] . ' corrected — now ready to import.'
                : 'Row ' . (int) $previewRows[$rowIndex]['row'] . ' updated, but still has an error: ' . $previewRows[$rowIndex]['message'];
        }
    } elseif ($action === 'confirm') {
        $previewRows = $_SESSION['student_import_preview'] ?? [];

        if (empty($previewRows)) {
            $_SESSION['flash_error'] = 'Your import session expired. Please upload the file again.';
            unset($_SESSION['student_import_preview']);
            redirect_to('admin/students.php');
        }

        $validRows = array_values(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));

        if (empty($validRows)) {
            $_SESSION['flash_error'] = 'There were no valid rows to import.';
            unset($_SESSION['student_import_preview']);
            redirect_to('admin/students.php');
        }

        $imported = 0;
        foreach ($validRows as $row) {
            $conn->begin_transaction();
            try {
                $studentNo = $row['student_no'];
                $credentialValue = student_credential_value($conn, $row['department_code'], $studentNo);
                $username = $credentialValue;
                $tempPassword = $credentialValue;
                $passwordHash = password_hash($tempPassword, PASSWORD_DEFAULT);
                $emailParam = $row['email'];

                $insertUserStmt = $conn->prepare(
                    'INSERT INTO users (username, password_hash, full_name, email, role_id, status) VALUES (?, ?, ?, ?, ?, "active")'
                );
                $insertUserStmt->bind_param('ssssi', $username, $passwordHash, $row['full_name'], $emailParam, $studentRoleId);
                $insertUserStmt->execute();
                $newUserId = (int) $conn->insert_id;
                $insertUserStmt->close();

                $insertStudentStmt = $conn->prepare(
                    'INSERT INTO students (
                        student_no, first_name, father_name, grandfather_name, mother_name, sex,
                        birth_date, street_address, phone, emergency_contact_name, emergency_contact_phone,
                        nationality, enrollment_date, certificate_type, school_roll_number, degree, program,
                        class_year, user_id, academic_year_id, faculty_id, department_id, semester_id, shift, status
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active")'
                );
                $grandfatherParam = $row['grandfather_name'] !== '' ? $row['grandfather_name'] : null;
                $motherNameParam = $row['mother_name'] !== '' ? $row['mother_name'] : null;
                $streetAddressParam = $row['street_address'] !== '' ? $row['street_address'] : null;
                $phoneParam = $row['phone'] !== '' ? $row['phone'] : null;
                $emergencyContactNameParam = $row['emergency_contact_name'] !== '' ? $row['emergency_contact_name'] : null;
                $emergencyContactPhoneParam = $row['emergency_contact_phone'] !== '' ? $row['emergency_contact_phone'] : null;
                $nationalityParam = $row['nationality'] !== '' ? $row['nationality'] : null;
                $certificateTypeParam = $row['certificate_type'] !== '' ? $row['certificate_type'] : null;
                $schoolRollNumberParam = $row['school_roll_number'] !== '' ? $row['school_roll_number'] : null;
                $degreeParam = $row['degree'] !== '' ? $row['degree'] : null;
                $programParam = $row['program'] !== '' ? $row['program'] : null;
                $classYearParam = $row['class_year'] !== '' ? $row['class_year'] : null;
                $insertStudentStmt->bind_param(
                    'ssssssssssssssssssiiiiis',
                    $studentNo,
                    $row['first_name'],
                    $row['father_name'],
                    $grandfatherParam,
                    $motherNameParam,
                    $row['sex'],
                    $row['birth_date'],
                    $streetAddressParam,
                    $phoneParam,
                    $emergencyContactNameParam,
                    $emergencyContactPhoneParam,
                    $nationalityParam,
                    $row['enrollment_date'],
                    $certificateTypeParam,
                    $schoolRollNumberParam,
                    $degreeParam,
                    $programParam,
                    $classYearParam,
                    $newUserId,
                    $row['academic_year_id'],
                    $row['faculty_id'],
                    $row['department_id'],
                    $row['semester_id'],
                    $row['shift']
                );
                $insertStudentStmt->execute();
                $insertStudentStmt->close();

                $conn->commit();
                $imported++;
            } catch (\Throwable $e) {
                $conn->rollback();
            }
        }

        unset($_SESSION['student_import_preview']);
        $skipped = count($previewRows) - $imported;
        $_SESSION['flash_success'] = "Imported {$imported} student(s) successfully."
            . ($skipped > 0 ? " Skipped {$skipped} invalid row(s)." : '')
            . ($imported > 0 ? ' Use "Reset Password" on a student\'s row if you need to issue their credentials again.' : '');

        redirect_to('admin/students.php');
    } elseif ($action === 'cancel') {
        unset($_SESSION['student_import_preview']);
        redirect_to('admin/students_import.php');
    }
}

$validCount = count(array_filter($previewRows, static fn ($r) => $r['status'] === 'ok'));
$invalidCount = count($previewRows) - $validCount;

// Data for the "Edit" modal — lets Registration Office correct one bad row
// in place instead of re-uploading the whole file. Departments/Semesters
// are grouped by faculty for the same client-side cascade pattern already
// used on admin/students.php.
$editDepartmentsByFacultyId = [];
foreach ($existingDepartments as $dept) {
    $editDepartmentsByFacultyId[(int) $dept['faculty_id']][] = ['id' => (int) $dept['id'], 'name' => $dept['name']];
}
$editSemestersByFacultyId = [];
foreach ($existingSemesters as $sem) {
    $editSemestersByFacultyId[(int) $sem['faculty_id']][] = ['id' => (int) $sem['id'], 'name' => $sem['name']];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Students — ADMAS Attendance System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>
    <?php include __DIR__ . '/../includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/../includes/topbar.php'; ?>

        <div class="page-body">
            <div class="scope-banner">
                <i class="bi bi-shield-check"></i>
                <?= current_role() === 'registration'
                    ? 'Access scope: All faculties — enrollment-focused'
                    : 'Access scope: Full system — all faculties, departments, and students' ?>
            </div>

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                <div>
                    <h4 class="fw-bold mb-1" style="color: var(--admas-text);">Import Students from Excel</h4>
                    <p class="text-muted mb-0">Bulk-register students from a .xlsx, .xls, or .csv file.</p>
                </div>
                <a href="<?= htmlspecialchars(BASE_URL) ?>/admin/students.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Students
                </a>
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

            <?php if ($step === 'upload'): ?>
                <div class="admas-card p-4" style="max-width: 720px;">
                    <h6 class="fw-bold mb-3" style="color: var(--admas-text);">Upload File</h6>

                    <div class="alert alert-light border small mb-3">
                        Matches the university's real Enrollment form. Every column is required for each row —
                        this is the student's real registration record, not optional extra detail:
                        <strong>Student No</strong> (or "Student ID Number"/"REG/NO" — the student's existing
                        admission/ID number, must be unique), <strong>Student Name</strong> (the full name in one
                        column — the older separate "First Names"/"Father's Name" column format is no longer
                        accepted), <strong>Mother's Name</strong>,
                        <strong>Sex</strong>, <strong>Birth Date</strong>, <strong>Street Address</strong>,
                        <strong>Student Phone</strong>, <strong>Student Email</strong>,
                        <strong>Emergency Contact Name</strong>, <strong>Emergency Contact Phone</strong>,
                        <strong>Nationality</strong>, <strong>Enrollment Date</strong>,
                        <strong>Certificate Type</strong>, <strong>School Roll Number</strong>,
                        <strong>Degree</strong>, <strong>Program</strong>, <strong>Class Year</strong>,
                        <strong>Academic Year</strong>, <strong>Faculty</strong>, <strong>Department</strong>,
                        <strong>Semester</strong> (must match an existing semester name within that Faculty), and
                        <strong>Shift</strong> (Morning Shift / Afternoon Shift / Weekend — not part of the
                        university's own Enrollment form, but required here so a course roster can be built).
                        A row missing any of these is flagged as an error and skipped, not imported with a blank.
                        <br>
                        A username and temporary password (both "DepartmentCode-StudentNo", e.g. "IT-1472/23") will
                        be generated automatically for each imported student.
                        <br>
                        Column headers may also be written in Somali (e.g. "Magaca Ardayga" for Student Name,
                        "ID-ga Ardayga" for Student No, "Kulliyadda" for Faculty, "Waaxda" for Department).
                        <br>
                        <a href="?action=template" class="fw-semibold">
                            <i class="bi bi-download"></i> Download the Enrollment starter template (.xlsx)
                        </a>
                    </div>

                    <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        <div class="mb-3">
                            <label for="importFileInput" class="form-label">Excel or CSV file</label>
                            <input type="file" class="form-control" id="importFileInput" name="import_file" accept=".xlsx,.xls,.csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);"
                                <?= (empty($existingFaculties) || empty($existingDepartments) || empty($existingAcademicYears) || empty($existingSemesters)) ? 'disabled' : '' ?>>
                            <i class="bi bi-eye"></i> Preview Import
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="admas-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                        <h6 class="fw-bold mb-0" style="color: var(--admas-text);">Preview</h6>
                        <div>
                            <span class="badge-pill badge-active me-1"><?= $validCount ?> ready</span>
                            <?php if ($invalidCount > 0): ?>
                                <span class="badge-pill badge-absent"><?= $invalidCount ?> with errors</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive mb-3">
                        <table class="table admas-table align-middle">
                            <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Student No</th>
                                    <th>Student Name</th>
                                    <th>Academic Year</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Semester</th>
                                    <th>Shift</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewRows as $index => $r): ?>
                                    <tr>
                                        <td><?= (int) $r['row'] ?></td>
                                        <td><?= htmlspecialchars($r['student_no']) ?></td>
                                        <td><?= htmlspecialchars($r['full_name']) ?></td>
                                        <td><?= htmlspecialchars($r['year_input']) ?></td>
                                        <td><?= htmlspecialchars($r['faculty_input']) ?></td>
                                        <td><?= htmlspecialchars($r['department_input']) ?></td>
                                        <td><?= htmlspecialchars($r['semester_input']) ?></td>
                                        <td><?= htmlspecialchars($r['shift_input']) ?></td>
                                        <td>
                                            <?php if ($r['status'] === 'ok'): ?>
                                                <span class="badge-pill badge-active"><i class="bi bi-check-lg"></i> <?= htmlspecialchars($r['message']) ?></span>
                                            <?php else: ?>
                                                <span class="badge-pill badge-absent"><i class="bi bi-x-lg"></i> <?= htmlspecialchars($r['message']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="admasOpenEditRow(<?= (int) $index ?>)">
                                                <i class="bi bi-pencil-square"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="text-muted small mb-3">
                        The preview shows the core identity/scope columns only, but every column (including
                        Mother's Name, Sex, Birth Date, Address, Phone, Email, Emergency Contact, Nationality,
                        Enrollment Date, Certificate Type, School Roll Number, Degree, Program, and Class Year) is
                        editable via each row's <strong>Edit</strong> button — no need to fix a mistake in the
                        original file and re-upload; correcting a row here re-checks it immediately.
                    </div>

                    <div class="d-flex gap-2">
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php">
                            <input type="hidden" name="action" value="confirm">
                            <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);" <?= $validCount === 0 ? 'disabled' : '' ?>>
                                <i class="bi bi-check2-circle"></i> Confirm Import (<?= $validCount ?>)
                            </button>
                        </form>
                        <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-outline-secondary">Upload a Different File</button>
                        </form>
                    </div>
                </div>

                <!-- Edit Row modal — corrects one preview row in place (action=edit_row)
                     without re-uploading the whole file; populated from previewRowsData
                     by admasOpenEditRow() below. -->
                <div class="modal fade" id="editRowModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/admin/students_import.php" id="editRowForm">
                                <input type="hidden" name="action" value="edit_row">
                                <input type="hidden" name="row_index" id="editRowIndex" value="">
                                <div class="modal-header">
                                    <h6 class="modal-title fw-bold" id="editRowModalLabel">Edit Row</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Student No</label>
                                            <input type="text" class="form-control" name="student_no" id="editStudentNo" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Student Name</label>
                                            <input type="text" class="form-control" name="student_name" id="editStudentName" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Mother's Name</label>
                                            <input type="text" class="form-control" name="mother_name" id="editMotherName" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Sex</label>
                                            <select class="form-select" name="sex" id="editSex" required>
                                                <option value="">Select sex</option>
                                                <option value="Male">Male</option>
                                                <option value="Female">Female</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Birth Date</label>
                                            <input type="date" class="form-control" name="birth_date" id="editBirthDate" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Street Address</label>
                                            <input type="text" class="form-control" name="street_address" id="editStreetAddress" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Student Phone</label>
                                            <input type="text" class="form-control" name="phone" id="editPhone" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Student Email</label>
                                            <input type="email" class="form-control" name="email" id="editEmail" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Emergency Contact Name</label>
                                            <input type="text" class="form-control" name="emergency_contact_name" id="editEmergencyContactName" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Emergency Contact Phone</label>
                                            <input type="text" class="form-control" name="emergency_contact_phone" id="editEmergencyContactPhone" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Nationality</label>
                                            <input type="text" class="form-control" name="nationality" id="editNationality" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Enrollment Date</label>
                                            <input type="date" class="form-control" name="enrollment_date" id="editEnrollmentDate" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Certificate Type</label>
                                            <input type="text" class="form-control" name="certificate_type" id="editCertificateType" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">School Roll Number</label>
                                            <input type="text" class="form-control" name="school_roll_number" id="editSchoolRollNumber" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Degree</label>
                                            <input type="text" class="form-control" name="degree" id="editDegree" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Program</label>
                                            <input type="text" class="form-control" name="program" id="editProgram" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Class Year</label>
                                            <input type="text" class="form-control" name="class_year" id="editClassYear" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Academic Year</label>
                                            <select class="form-select" name="year_input" id="editAcademicYear" required>
                                                <option value="">Select academic year</option>
                                                <?php foreach ($existingAcademicYears as $ay): ?>
                                                    <option value="<?= htmlspecialchars($ay['label']) ?>"><?= htmlspecialchars($ay['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Faculty</label>
                                            <select class="form-select" name="faculty_input" id="editFaculty" required onchange="admasEditRowFacultyChanged()">
                                                <option value="">Select faculty</option>
                                                <?php foreach ($existingFaculties as $f): ?>
                                                    <option value="<?= htmlspecialchars($f['name']) ?>" data-faculty-id="<?= (int) $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Department</label>
                                            <select class="form-select" name="department_input" id="editDepartment" required>
                                                <option value="">Select faculty first</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Semester</label>
                                            <select class="form-select" name="semester_input" id="editSemester" required>
                                                <option value="">Select faculty first</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Shift</label>
                                            <select class="form-select" name="shift_input" id="editShift" required>
                                                <option value="">Select shift</option>
                                                <?php foreach (IMPORT_SHIFT_LABELS as $shiftLabel): ?>
                                                    <option value="<?= htmlspecialchars($shiftLabel) ?>"><?= htmlspecialchars($shiftLabel) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary" style="background-color: var(--admas-sky); border-color: var(--admas-sky);">
                                        <i class="bi bi-check2"></i> Save &amp; Re-check Row
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($step === 'preview'): ?>
        <script>
            const previewRowsData = <?= json_encode(array_values($previewRows), JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const editDepartmentsByFacultyId = <?= json_encode($editDepartmentsByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const editSemestersByFacultyId = <?= json_encode($editSemestersByFacultyId, JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function admasBuildEditSelectOptions(select, options, selectedName, placeholder) {
                select.innerHTML = '';
                const placeholderOpt = document.createElement('option');
                placeholderOpt.value = '';
                placeholderOpt.textContent = placeholder;
                select.appendChild(placeholderOpt);
                options.forEach((opt) => {
                    const el = document.createElement('option');
                    el.value = opt.name;
                    el.textContent = opt.name;
                    select.appendChild(el);
                });
                select.value = selectedName || '';
                if (select.value !== (selectedName || '')) {
                    // The row's saved text didn't match any real option (e.g. a typo
                    // that caused the original error) — keep it visible as a free-text
                    // fallback option so the user can see exactly what was there.
                    const fallback = document.createElement('option');
                    fallback.value = selectedName;
                    fallback.textContent = selectedName + ' (not recognized)';
                    select.insertBefore(fallback, select.firstChild.nextSibling);
                    select.value = selectedName;
                }
            }

            function admasEditRowFacultyChanged(preserveDepartment, preserveSemester) {
                const facultySelect = document.getElementById('editFaculty');
                const selectedOption = facultySelect.options[facultySelect.selectedIndex];
                const facultyId = selectedOption ? selectedOption.getAttribute('data-faculty-id') : null;
                const departments = (facultyId && editDepartmentsByFacultyId[facultyId]) ? editDepartmentsByFacultyId[facultyId] : [];
                const semesters = (facultyId && editSemestersByFacultyId[facultyId]) ? editSemestersByFacultyId[facultyId] : [];
                admasBuildEditSelectOptions(document.getElementById('editDepartment'), departments, preserveDepartment || '', 'Select department');
                admasBuildEditSelectOptions(document.getElementById('editSemester'), semesters, preserveSemester || '', 'Select semester');
            }

            function admasOpenEditRow(index) {
                const r = previewRowsData[index];
                if (!r) { return; }

                document.getElementById('editRowIndex').value = index;
                document.getElementById('editStudentNo').value = r.student_no || '';
                document.getElementById('editStudentName').value = r.full_name || '';
                document.getElementById('editMotherName').value = r.mother_name || '';
                document.getElementById('editSex').value = r.sex_input || '';
                document.getElementById('editBirthDate').value = r.birth_date || r.birth_date_input || '';
                document.getElementById('editStreetAddress').value = r.street_address || '';
                document.getElementById('editPhone').value = r.phone || '';
                document.getElementById('editEmail').value = r.email_input || r.email || '';
                document.getElementById('editEmergencyContactName').value = r.emergency_contact_name || '';
                document.getElementById('editEmergencyContactPhone').value = r.emergency_contact_phone || '';
                document.getElementById('editNationality').value = r.nationality || '';
                document.getElementById('editEnrollmentDate').value = r.enrollment_date || r.enrollment_date_input || '';
                document.getElementById('editCertificateType').value = r.certificate_type || '';
                document.getElementById('editSchoolRollNumber').value = r.school_roll_number || '';
                document.getElementById('editDegree').value = r.degree || '';
                document.getElementById('editProgram').value = r.program || '';
                document.getElementById('editClassYear').value = r.class_year || '';
                document.getElementById('editAcademicYear').value = r.year_input || '';
                document.getElementById('editFaculty').value = r.faculty_input || '';
                document.getElementById('editShift').value = r.shift_input || '';

                admasEditRowFacultyChanged(r.department_input || '', r.semester_input || '');

                const modal = new bootstrap.Modal(document.getElementById('editRowModal'));
                modal.show();
            }

            <?php if ($successMessage !== '' && str_starts_with($successMessage, 'Row ')): ?>
                // Auto-scroll the corrected row into view after a save.
                document.addEventListener('DOMContentLoaded', function () {
                    const alertBox = document.querySelector('.alert-success');
                    if (alertBox) { alertBox.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                });
            <?php endif; ?>
        </script>
    <?php endif; ?>
</body>
</html>
