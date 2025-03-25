<?php
declare(strict_types=1);

require './includes/bootstrap.php';

// Check user permission
if (!$perm->get('exterminate')) {
    error::fatal(m('Error: Access denied'));
}

// Set page title
$template->title = 'Exterminate trolls by phrase';

// Handle form submission
if (isset($_POST['exterminate'])) {
    // Verify CSRF token
    if (!check_token()) {
        error::fatal(m('Error: Invalid token'));
    }

    // Clean and validate input
    $phrase = trim(str_replace("\r", '', $_POST['phrase'] ?? ''));
    $range = $_POST['range'] ?? '';

    // Custom CSRF-like session check
    if (empty($_POST['start_time']) || $_POST['start_time'] != ($_SESSION['exterminate_start_time'] ?? '')) {
        error::fatal('Session error.');
    }

    // Validate phrase length
    if (strlen($phrase) < 4) {
        error::add('That phrase is too short. It must be at least 4 characters.');
    }

    // Validate time range
    if (!ctype_digit($range)) {
        error::add('Invalid time range selected.');
    }

    // Process deletion if no errors
    if (error::valid()) {
        $like_phrase = '%' . $phrase . '%';
        $affect_posts_after = $_SERVER['REQUEST_TIME'] - (int)$range;

        // Delete replies and update topic reply counts
        $fetch_replies = $db->q('SELECT id, parent_id FROM replies WHERE body LIKE ? AND time > ?', $like_phrase, $affect_posts_after);
        while ($reply = $fetch_replies->fetch()) {
            $db->q('UPDATE topics SET replies = replies - 1 WHERE id = ?', $reply['parent_id']);
            delete_image('reply', $reply['id']);
        }
        $db->q('DELETE FROM replies WHERE body LIKE ? AND time > ?', $like_phrase, $affect_posts_after);

        // Delete topics and their associated replies
        $fetch_topics = $db->q('SELECT id FROM topics WHERE (body LIKE ? OR headline LIKE ?) AND time > ?', $like_phrase, $like_phrase, $affect_posts_after);
        while ($topic = $fetch_topics->fetch()) {
            delete_image('topic', $topic['id']);
            $fetch_replies = $db->q('SELECT id FROM replies WHERE parent_id = ?', $topic['id']);
            while ($reply = $fetch_replies->fetch()) {
                delete_image('reply', $reply['id']);
            }
            $db->q('DELETE FROM replies WHERE parent_id = ?', $topic['id']);
        }
        $db->q('DELETE FROM topics WHERE (body LIKE ? OR headline LIKE ?) AND time > ?', $like_phrase, $like_phrase, $affect_posts_after);

        // Set success notice and redirect
        $_SESSION['notice'] = 'Finished.';
        redirect('Posts containing the phrase have been deleted.', '');
    } else {
        error::output(); // Display validation errors
    }
}

// Set session variable for CSRF-like protection
$_SESSION['exterminate_start_time'] = $_SERVER['REQUEST_TIME'];
?>

<p>This feature removes all posts that contain the exact phrase you specify in the body or headline.</p>

<form action="" method="post" onsubmit="return confirm('Are you sure you want to do this?');">
    <?php csrf_token() ?>
    <div class="noscreen">
        <input type="hidden" name="start_time" value="<?php echo $_SESSION['exterminate_start_time']; ?>" />
    </div>
    <div class="row">
        <label for="phrase">Phrase</label>
        <textarea id="phrase" name="phrase"><?php echo htmlspecialchars($_POST['phrase'] ?? ''); ?></textarea>
    </div>
    <div class="row">
        <label for="range" class="inline">Affect posts made within:</label>
        <select id="range" name="range" class="inline">
            <option value="28800"<?php echo ($_POST['range'] ?? '') == '28800' ? ' selected' : ''; ?>>Last 8 hours</option>
            <option value="86400"<?php echo ($_POST['range'] ?? '') == '86400' ? ' selected' : ''; ?>>Last 24 hours</option>
            <option value="259200"<?php echo ($_POST['range'] ?? '') == '259200' ? ' selected' : ''; ?>>Last 72 hours</option>
            <option value="604800"<?php echo ($_POST['range'] ?? '') == '604800' ? ' selected' : ''; ?>>Last week</option>
            <option value="2629743"<?php echo ($_POST['range'] ?? '') == '2629743' ? ' selected' : ''; ?>>Last month</option>
        </select>
    </div>
    <div class="row">
        <input type="submit" name="exterminate" value="Do it" />
    </div>
</form>

<?php
$template->render();
?>