<?php
require './includes/bootstrap.php';
force_id();

// Check permissions
if (!$perm->get('handle_reports')) {
    error::fatal(m('Error: Access denied'));
}

// Handle dismiss all reports with CSRF protection
if (isset($_POST['dismiss_all'])) {
    check_token(); // Assumes a CSRF token validation function
    $db->q('DELETE FROM reports');
    redirect('All reports dismissed.', '');
}

$template->title = 'Reported posts';

// Fetch reported topics with prepared statement
$topic_query = $db->q(
    'SELECT 
        reports.reason, reports.reporter,
        topics.id, topics.headline, topics.body, topics.author, topics.namefag, topics.tripfag, topics.link, topics.locked, topics.replies,
        images.file_name
     FROM reports
     INNER JOIN topics ON reports.post_id = topics.id
     LEFT OUTER JOIN images ON topics.id = images.topic_id
     WHERE reports.type = ?
     ORDER BY topics.id',
    'topic'
);

$previous_topic = null;
while ($topic = $topic_query->fetchObject()) {
    if (!isset($previous_topic) || $previous_topic != $topic->id) {
        if ($previous_topic !== null) {
            echo '</div>';
        }
        ?>
        <h3>Topic: <strong><a href="<?php echo htmlspecialchars(DIR . 'topic/' . $topic->id); ?>"><?php echo htmlspecialchars($topic->headline); ?></a></strong> by <?php echo format_name($topic->namefag, $topic->tripfag, $topic->link, 0); ?> <span class="reply_id unimportant"><a href="<?php echo htmlspecialchars(DIR . 'topic/' . $topic->id); ?>">#<?php echo number_format($topic->id); ?></a></span></h3>
        <div class="body">
            <?php if ($topic->file_name): ?>
                <a href="<?php echo htmlspecialchars(DIR . 'img/' . $topic->file_name); ?>" class="thickbox">
                    <img src="<?php echo htmlspecialchars(DIR . 'thumbs/' . $topic->file_name); ?>" alt="" />
                </a>
            <?php endif; ?>
            <?php echo parser::parse($topic->body); ?>
            <ul class="menu">
                <?php if ($perm->get('edit_others')): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'edit_topic/' . $topic->id); ?>">Edit</a></li>
                <?php endif; ?>
                <?php if ($perm->get('view_profile')): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'profile/' . $topic->author); ?>">Profile</a></li>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'compose_message/' . $topic->author); ?>">PM</a></li>
                <?php endif; ?>
                <?php if ($perm->get('lock') && !$topic->locked): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'lock_topic/' . $topic->id); ?>" onclick="return quickAction(this, 'Really lock this topic?');">Lock</a></li>
                <?php endif; ?>
                <?php if ($perm->get('delete')): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'delete_topic/' . $topic->id); ?>" onclick="return quickAction(this, 'Really delete this topic?');">Delete</a></li>
                    <?php if ($topic->file_name): ?>
                        <li><a href="<?php echo htmlspecialchars(DIR . 'delete_image/' . $topic->id); ?>" onclick="return quickAction(this, 'Really delete this image?');">Delete image</a></li>
                    <?php endif; ?>
                <?php endif; ?>
                <li><?php echo $topic->replies . ' repl' . ($topic->replies == 1 ? 'y' : 'ies'); ?></li>
            </ul>
        <?php
    }
    ?>
    <div class="report_reason">Reported by <a href="<?php echo htmlspecialchars(DIR . 'profile/' . $topic->reporter); ?>"><?php echo htmlspecialchars($topic->reporter); ?></a> (<a href="<?php echo htmlspecialchars(DIR . 'compose_message/' . $topic->reporter); ?>">PM</a>)<?php if (!empty($topic->reason)) echo ': <strong>' . parser::parse($topic->reason) . '</strong>'; ?></div>
    <?php
    $previous_topic = $topic->id;
}
if ($previous_topic !== null) {
    echo '</div>';
}

// Fetch reported replies with prepared statement
$reply_query = $db->q(
    'SELECT 
        reports.reason, reports.reporter,
        replies.id, replies.parent_id, replies.body, replies.author, replies.namefag, replies.tripfag, replies.link,
        images.file_name
     FROM reports
     INNER JOIN replies ON reports.post_id = replies.id
     LEFT OUTER JOIN images ON replies.id = images.reply_id
     WHERE reports.type = ?
     ORDER BY replies.id',
    'reply'
);

$previous_reply = null;
while ($reply = $reply_query->fetchObject()) {
    if (!isset($previous_reply) || $previous_reply != $reply->id) {
        if ($previous_reply !== null) {
            echo '</div>';
        }
        ?>
        <h3><a href="<?php echo htmlspecialchars(DIR . 'topic/' . $reply->parent_id . '#reply_' . $reply->id); ?>">Reply</a> by <?php echo format_name($reply->namefag, $reply->tripfag, $reply->link); ?> <span class="reply_id unimportant"><a href="<?php echo htmlspecialchars(DIR . 'topic/' . $reply->parent_id . '#reply_' . $reply->id); ?>">#<?php echo number_format($reply->id); ?></a></span></h3>
        <div class="body">
            <?php if ($reply->file_name): ?>
                <a href="<?php echo htmlspecialchars(DIR . 'img/' . $reply->file_name); ?>" class="thickbox">
                    <img src="<?php echo htmlspecialchars(DIR . 'thumbs/' . $reply->file_name); ?>" alt="" />
                </a>
            <?php endif; ?>
            <?php
            $reply_body = parser::parse($reply->body);
            $reply_body = preg_replace('/^@([0-9]+|OP),?([0-9]+)?/m', '<span class="unimportant"><a href="' . DIR . 'topic/' . $reply->parent_id . '#reply_$1$2">$0</a></span>', $reply_body);
            echo $reply_body;
            ?>
            <ul class="menu">
                <?php if ($perm->get('edit_others')): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'edit_reply/' . $reply->parent_id . '/' . $reply->id); ?>">Edit</a></li>
                <?php endif; ?>
                <?php if ($perm->get('view_profile')): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'profile/' . $reply->author); ?>">Profile</a></li>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'compose_message/' . $reply->author); ?>">PM</a></li>
                <?php endif; ?>
                <?php if ($perm->get('delete')): ?>
                    <li><a href="<?php echo htmlspecialchars(DIR . 'delete_reply/' . $reply->id); ?>" onclick="return quickAction(this, 'Really delete this reply?');">Delete</a></li>
                    <?php if ($reply->file_name): ?>
                        <li><a href="<?php echo htmlspecialchars(DIR . 'delete_image/' . $reply->parent_id . '/' . $reply->id); ?>" onclick="return quickAction(this, 'Really delete this image?');">Delete image</a></li>
                    <?php endif; ?>
                <?php endif; ?>
            </ul>
        <?php
    }
    ?>
    <div class="report_reason">Reported by <a href="<?php echo htmlspecialchars(DIR . 'profile/' . $reply->reporter); ?>"><?php echo htmlspecialchars($reply->reporter); ?></a> (<a href="<?php echo htmlspecialchars(DIR . 'compose_message/' . $reply->reporter); ?>">PM</a>)<?php if (!empty($reply->reason)) echo ': <strong>' . parser::parse($reply->reason) . '</strong>'; ?></div>
    <?php
    $previous_reply = $reply->id;
}
if ($previous_reply !== null) {
    echo '</div>';
}
?>

<div class="row">
    <form action="" method="post">
        <?php csrf_token(); // Assumes a CSRF token generation function ?>
        <input type="submit" name="dismiss_all" value="Dismiss all" onclick="return confirm('Really dismiss all reports?');" />
    </form>
</div>

<?php
$template->render();