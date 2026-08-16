<?php
/**
 * Danger Zone: full factory reset (handover feature).
 *
 * Wipes all institution-specific and test data back to an empty shell,
 * keeping only University Rector login access, the `roles` lookup
 * table, and the schema itself. A full mysqldump backup is always taken
 * first; the wipe only runs if that backup succeeds.
 */
declare(strict_types=1);

const FACTORY_RESET_BACKUP_DIR = 'C:\\xampp\\backups\\admas_attendance\\';
const FACTORY_RESET_CONFIRM_PHRASE = 'FACTORY RESET';

/**
 * Run a full mysqldump backup of the current database to a timestamped
 * file outside the web root, before any destructive action is taken.
 *
 * @return array{success: bool, path: string, size: int, error: string}
 */
function factory_reset_run_backup(): array
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $dbname = getenv('DB_NAME') ?: 'admas_attendance';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') ?: '';

    if (!is_dir(FACTORY_RESET_BACKUP_DIR)) {
        if (!mkdir(FACTORY_RESET_BACKUP_DIR, 0755, true) && !is_dir(FACTORY_RESET_BACKUP_DIR)) {
            return ['success' => false, 'path' => '', 'size' => 0, 'error' => 'Could not create backup directory: ' . FACTORY_RESET_BACKUP_DIR];
        }
    }

    $filename = 'admas_attendance_pre_reset_' . date('Y-m-d_His') . '.sql';
    $path = FACTORY_RESET_BACKUP_DIR . $filename;

    $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    $command = [$mysqldumpPath, '--host=' . $host, '--port=' . $port, '--user=' . $username, '--single-transaction', '--routines', '--events', $dbname];

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['file', $path, 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge($_SERVER, ['MYSQL_PWD' => $password]);

    $process = proc_open($command, $descriptorSpec, $pipes, null, $env);
    if (!is_resource($process)) {
        return ['success' => false, 'path' => '', 'size' => 0, 'error' => 'Could not start mysqldump process.'];
    }

    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        if (is_file($path)) {
            unlink($path);
        }

        return ['success' => false, 'path' => '', 'size' => 0, 'error' => trim((string) $stderr) !== '' ? trim((string) $stderr) : 'mysqldump exited with code ' . $exitCode];
    }

    $size = is_file($path) ? filesize($path) : 0;
    if ($size === false || $size === 0) {
        if (is_file($path)) {
            unlink($path);
        }

        return ['success' => false, 'path' => '', 'size' => 0, 'error' => 'Backup file was created but is empty.'];
    }

    return ['success' => true, 'path' => $path, 'size' => $size, 'error' => ''];
}

/**
 * Execute the full data wipe inside a single transaction: deletes every
 * table's rows in FK-safe order except `roles` and `users` rows for
 * university_rector, then resets the `settings` keys that would otherwise
 * dangle-reference now-deleted rows.
 *
 * @return array{counts: array<string, int>, remaining_admins: array<int, string>}
 */
function factory_reset_execute(mysqli $conn): array
{
    $deleteOrder = [
        'course_offerings' => 'DELETE FROM course_offerings',
        'course_enrollments' => 'DELETE FROM course_enrollments',
        'attendance' => 'DELETE FROM attendance',
        'notifications' => 'DELETE FROM notifications',
        'password_resets' => 'DELETE FROM password_resets',
        'role_assignments' => 'DELETE FROM role_assignments',
        'sessions' => 'DELETE FROM sessions',
        'courses' => 'DELETE FROM courses',
        'students' => 'DELETE FROM students',
        'semesters' => 'DELETE FROM semesters',
        'lecturers' => 'DELETE FROM lecturers',
        'users' => "DELETE FROM users WHERE role_id <> (SELECT id FROM roles WHERE name = 'university_rector')",
        'departments' => 'DELETE FROM departments',
        'academic_years' => 'DELETE FROM academic_years',
        'faculties' => 'DELETE FROM faculties',
    ];

    $conn->begin_transaction();

    try {
        $counts = [];
        foreach ($deleteOrder as $table => $sql) {
            if (!$conn->query($sql)) {
                throw new RuntimeException('Failed to delete from ' . $table . ': ' . $conn->error);
            }
            $counts[$table] = $conn->affected_rows;
        }

        $settingsResets = [
            'university_name' => '',
            'campus' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'default_faculty_id' => '0',
            'default_department_id' => '0',
            'current_academic_year_id' => '',
            'current_semester_id' => '',
        ];
        $settingStmt = $conn->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
        foreach ($settingsResets as $key => $value) {
            $settingStmt->bind_param('ss', $key, $value);
            $settingStmt->execute();
        }
        $settingStmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $adminUsernames = [];
    $adminResult = $conn->query("SELECT username FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'university_rector') ORDER BY username");
    if ($adminResult) {
        while ($row = $adminResult->fetch_assoc()) {
            $adminUsernames[] = $row['username'];
        }
    }

    return ['counts' => $counts, 'remaining_admins' => $adminUsernames];
}
