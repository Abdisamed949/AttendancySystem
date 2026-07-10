<?php
/**
 * Logs the current user out and returns to the login page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$_SESSION = [];
session_unset();
session_destroy();

redirect_to('login.php');
