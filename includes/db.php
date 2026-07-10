<?php
/**
 * Shared MySQL connection helper for the ADMAS Attendance System.
 * Uses mysqli and supports environment-based configuration.
 */
declare(strict_types=1);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('DB_PORT') ?: '3306');
$dbname = getenv('DB_NAME') ?: 'admas_attendance';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($host, $username, $password, $dbname, $port);

if ($mysqli->connect_errno) {
    error_log('Database connection failed: ' . $mysqli->connect_error);

    if (!headers_sent()) {
        http_response_code(503);
        echo 'Database connection unavailable.';
    }

    exit;
}

$mysqli->set_charset('utf8mb4');

/**
 * Return the shared mysqli connection.
 */
function db(): mysqli
{
    global $mysqli;

    return $mysqli;
}

/**
 * Close the shared connection when the request is done.
 */
function db_close(): void
{
    global $mysqli;

    if (isset($mysqli) && $mysqli instanceof mysqli) {
        $mysqli->close();
    }
}
