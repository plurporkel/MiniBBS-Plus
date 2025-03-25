<?php
/* Cleans up the database; called (at most) every 48 hours over AJAX by stuff.php. */

define('MINIMAL_BOOTSTRAP', true);
require './includes/bootstrap.php';

// Use time() for consistency and check cache
$now = time();
if (cache::fetch('maintenance') > $now - 172800) {
    exit('Too early.');
}

cache::set('maintenance', $now);

// Continue execution even if the request times out
ignore_user_abort(true);
// Set a short time limit for the request
set_time_limit(1);

$deleted_rows = 0;

try {
    // Delete activity older than an hour
    $res = $db->q('DELETE FROM activity WHERE time < ?', $now - 3600);
    $deleted_rows += $res->rowCount();

    // Delete search logs older than an hour
    $res = $db->q('DELETE FROM search_log WHERE time < ?', $now - 3600);
    $deleted_rows += $res->rowCount();

    // Delete users with 0 posts and no activity for two weeks
    $res = $db->q('DELETE FROM users WHERE last_seen < ? AND post_count = 0', $now - 1209600);
    $deleted_rows += $res->rowCount();

    // Optimize tables (MySQL-specific, no-op in some InnoDB configs)
    $db->q('OPTIMIZE TABLE activity, search_log, users');

    // Log the maintenance action
    log_mod('db_maintenance', '', $deleted_rows, '', 'system');
} catch (Exception $e) {
    // Log error to Apache/PHP error log
    error_log("Maintenance failed: " . $e->getMessage());
}

// Render template silently (assuming false suppresses output)
$template->render(false);