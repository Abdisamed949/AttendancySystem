<?php
/**
 * Retired — the forgot/reset password flow is now a single page
 * (forgot_password.php, phases "identify" and "code"). This file only
 * exists to redirect any stale bookmarks/links to the merged page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

redirect_to('forgot_password.php');
