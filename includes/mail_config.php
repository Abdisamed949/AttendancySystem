<?php
/**
 * Gmail SMTP configuration for outgoing mail (Forgot Password codes).
 *
 * >>> PUT YOUR REAL GMAIL APP PASSWORD BELOW, in SMTP_PASSWORD. <<<
 *
 * SMTP_USERNAME must be a real Gmail address, and SMTP_PASSWORD must be a
 * 16-character Gmail "App Password" (Google Account -> Security -> 2-Step
 * Verification -> App Passwords) — NOT your normal Gmail login password;
 * Gmail rejects SMTP logins using the regular account password once 2-Step
 * Verification is on, which is required to generate an App Password at all.
 *
 * Do not commit real credentials to any shared/public repository.
 */
declare(strict_types=1);

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_USERNAME', 'maticn033@gmail.com'); // <-- replace with the sending Gmail address
    define('SMTP_PASSWORD', 'jtbexzfyamqmjwvf');    // <-- replace with a real Gmail App Password
    define('SMTP_FROM_NAME', 'ADMAS Attendance System');
}
