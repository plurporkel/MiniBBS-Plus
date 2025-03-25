<?php
require './includes/bootstrap.php';
force_id();
$template->onload = "focusId('reason');";

// Check if user has at least one post
if (($_SESSION['post_count'] ?? 0) < 1) {
    error::fatal('You need at least one post to file a report.');
}

// Check permission to report
if (!$perm->get('report')) {
    error::fatal(m('Error: Access denied'));
}

// Validate post ID from GET parameters
$post_id = filter_input(INPUT_GET, 'reply', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'topic', FILTER_VALIDATE_INT);
if ($post_id === false || $post_id <= 0) {
    error::fatal('No valid ID was specified.');
}

// Determine post type and fetch relevant data
if (isset($_GET['reply'])) {
    $res = $db->q('SELECT parent_id, deleted FROM replies WHERE id = ?', $post_id);
    $post_type = 'reply';
} else {
    $res = $db->q('SELECT 1 AS exists, deleted FROM topics WHERE id = ?', $post_id);
    $post_type = 'topic';
}

$post_data = $res->fetch(PDO::FETCH_ASSOC);
if (!$post_data || $post_data['deleted']) {
    error::fatal('There is no such ' . $post_type . '.');
}

$location = $post_type === 'reply' 
    ? 'topic/' . $post_data['parent_id'] . '#reply_' . $post_id 
    : 'topic/' . $post_id;

$template->title = 'Report <a href="' . htmlspecialchars(DIR . $location) . '">a ' . $post_type . '</a>';

// Handle form submission
if (isset($_POST['reason'])) {
    check_token();
    $reason = trim($_POST['reason'] ?? '');
    check_length($reason, 'report reason', 0, 512);
    
    // Check report limit
    $res = $db->q('SELECT COUNT(*) FROM reports WHERE reporter = ?', $_SESSION['UID'] ?? '');
    if ($res->fetchColumn() > 12) {
        error::add('Please wait a while before reporting any more posts.');
    }
    
    if (error::valid()) {
        $db->q(
            'INSERT INTO reports (type, post_id, reason, reporter) VALUES (?, ?, ?, ?)', 
            $post_type, $post_id, $reason, $_SESSION['UID'] ?? ''
        );
        redirect('Thanks for your report.', $location);
    }
}

error::output();
?>

<p><?php echo m('Report: Help') ?></p>

<form action="" method="post">
    <?php csrf_token() ?>
    <label for="reason">Reason</label>
    <input type="text" id="reason" name="reason" size="80" maxlength="512" autofocus required />
    <input type="submit" name="submit" value="Report <?php echo $post_type ?>" />
</form>

<?php
$template->render();