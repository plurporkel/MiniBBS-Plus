<?php
define('REPRIEVE_BAN', true); // Must come before bootstrap
require './includes/bootstrap.php';

force_id();
$template->title = 'Create private message';

$banned = false;
$uid = $_SESSION['UID'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$now = time();

if ($perm->uid_banned($uid)) {
    $banned = $uid;
} elseif ($perm->ip_banned($ip)) {
    $banned = $ip;
}

if ($banned) {
    if (!defined('ALLOW_BAN_APPEALS') || !ALLOW_BAN_APPEALS) {
        error::fatal('Ban appeals are disabled on this board.');
    }
    if ($perm->get_ban_appeal($banned)) {
        error::fatal('You have already appealed your ban.');
    }
} elseif (($_SESSION['post_count'] ?? 0) < (defined('POSTS_FOR_USER_PM') ? POSTS_FOR_USER_PM : 0)) {
    error::fatal('Sorry, you need at least ' . POSTS_FOR_USER_PM . ' post' . (POSTS_FOR_USER_PM > 1 ? 's' : '') . ' to send PMs (you currently have ' . ($_SESSION['post_count'] ?? 0) . ').');
}

$parent = 0;
$destination = '';
$topic_id = 0;
$reply_id = 0;

if ($banned) {
    $destination = 'mods';
    $template->title = 'Ban appeal';
} elseif (in_array($_GET['to'] ?? '', ['mods', 'admins'])) {
    $destination = $_GET['to'];
    $template->title .= ' for the ' . htmlspecialchars($destination);
} elseif ($perm->get('view_profile') && !empty($_GET['to'])) {
    $destination = filter_input(INPUT_GET, 'to', FILTER_SANITIZE_STRING);
    $template->title .= ' for poster <a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($destination) . '">' . htmlspecialchars($destination) . '</a>';
} elseif (ctype_digit($_GET['replyto'] ?? '')) {
    $replyto = (int) $_GET['replyto'];
    $res = $db->q('SELECT contents, source, destination, parent FROM private_messages WHERE id = ?', $replyto);
    $prev = $res->fetch(PDO::FETCH_ASSOC);
    
    if (!$prev) {
        $template->title = 'Non-existent message';
        error::fatal('The message you tried to reply to does not exist.');
    }
    
    if ($prev['destination'] !== $uid && $prev['source'] !== $uid && !$perm->get('read_mod_pms')) {
        error::fatal('The message you tried to reply to was not addressed to you.');
    }
    
    if ((int) $prev['parent'] !== $replyto) {
        error::fatal('You can only reply to a parent message.');
    }
    
    $parent = $replyto;
    $destination = ($uid === $prev['source']) ? $prev['destination'] : $prev['source'];
} elseif ($_GET['topic'] || $_GET['reply']) {
    if (!defined('ALLOW_USER_PM') || !ALLOW_USER_PM) {
        error::fatal('Messaging other users is currently not allowed.');
    }
    
    if (ctype_digit($_GET['topic'] ?? '')) {
        $topic_id = (int) $_GET['topic'];
        $res = $db->q('SELECT author FROM topics WHERE id = ?', $topic_id);
        $destination = $res->fetchColumn();
        if ($destination === false) {
            error::fatal('There is no topic with that ID.');
        }
        $template->title .= ' for <a href="' . htmlspecialchars(DIR) . 'topic/' . $topic_id . '">topic</a> author';
    } elseif (ctype_digit($_GET['reply'] ?? '')) {
        $reply_id = (int) $_GET['reply'];
        $res = $db->q('SELECT author, parent_id FROM replies WHERE id = ?', $reply_id);
        $row = $res->fetch(PDO::FETCH_NUM);
        if (!$row) {
            error::fatal('There is no reply with that ID.');
        }
        [$destination, $topic_id] = $row;
        $template->title .= ' for <a href="' . htmlspecialchars(DIR) . 'topic/' . $topic_id . '#reply_' . $reply_id . '">reply</a> author';
    } else {
        error::fatal('The post ID was not valid.');
    }
} else {
    error::fatal('You did not specify any valid destination for this message.');
}

if (isset($_POST['submit'])) {
    $contents = super_trim($_POST['contents'] ?? '');
    [$name, $trip] = tripcode($_POST['name'] ?? '');
    
    check_token();
    check_length($contents, 'body', 3, defined('MAX_LENGTH_BODY') ? MAX_LENGTH_BODY : 10000);
    check_length($name, 'name', 0, 30);
    
    if (!$perm->is_admin() && !$perm->is_mod()) {
        $res = $db->q('SELECT 1 FROM private_messages WHERE source = ? AND time > ? LIMIT 1', $uid, $now - (defined('FLOOD_CONTROL_PM') ? FLOOD_CONTROL_PM : 60));
        if ($res->fetchColumn()) {
            error::add('Please wait at least ' . FLOOD_CONTROL_PM . ' seconds between private messages.');
        }
        
        $global_check = $db->q('SELECT COUNT(*) FROM private_messages WHERE time > ?', $now - 300);
        if ($global_check->fetchColumn() > (defined('MAX_GLOBAL_PM') ? MAX_GLOBAL_PM : 100)) {
            error::add('Too many PMs have been sent in the last 5 minutes. Try again in a moment.');
        }
    }
    
    if (error::valid()) {
        $ignored = 0;
        if (!$perm->is_admin() && !$perm->is_mod()) {
            $res = $db->q('SELECT 1 FROM pm_ignorelist WHERE uid = ? AND (ignored_uid = ? OR ignored_uid = "*")', $destination, $uid);
            $ignored = $res->fetchColumn() ? 1 : 0;
        }
        
        if ($banned) {
            $contents .= "\n\n(This is an appeal of the ban of " . htmlspecialchars($banned) . ".)";
        }
        
        $db->q(
            'INSERT INTO private_messages 
            (source, destination, name, trip, contents, time, parent, topic, reply, ignored) VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $uid, $destination, $name, $trip, $contents, $now, $parent, $topic_id, $reply_id, $ignored
        );
        
        if ($new_id = $db->lastInsertId()) {
            $notice = 'Private message sent.';
            if ($parent === 0) {
                $db->q('UPDATE private_messages SET parent = ? WHERE id = ?', $new_id, $new_id);
                $parent = $new_id;
            }
            if ($banned) {
                $db->q('UPDATE bans SET appealed = 1 WHERE target = ?', $banned);
            }
            
            if (isset($_POST['dismiss']) && $perm->get('read_mod_pms')) {
                $db->q('DELETE FROM pm_notifications WHERE parent_id = ?', $parent);
                $notice = 'Private message sent and dismissed.';
            }
            
            if (!$ignored) {
                $recipients = ($destination === 'mods') ? $perm->users_with_permission('read_mod_pms') :
                             ($destination === 'admins') ? $perm->users_with_permission('read_admin_pms') :
                             [$destination];
                
                foreach ($recipients as $recipient) {
                    if (strlen($recipient) >= 20 || $recipient === $uid) continue;
                    $db->q('INSERT INTO pm_notifications (uid, pm_id, parent_id) VALUES (?, ?, ?)', $recipient, $new_id, $parent);
                }
            }
            
            redirect($notice, 'private_message/' . $new_id);
        } else {
            error::add('An error occurred while sending your private message.');
        }
    }
}

$set_name = $_POST['form_sent'] ? ($_POST['name'] ?? '') : ($_SESSION['poster_name'] ?? '');
$message_body = $_POST['form_sent'] ? ($_POST['contents'] ?? '') : '';

error::output();
?>

<?php if ($banned): ?>
<p>This is the only PM you'll be able to send while banned, so make it count.</p>
<?php endif; ?>

<form action="" method="post">
    <input name="form_sent" type="hidden" value="1">
    <?php csrf_token(); ?>
    
    <?php if (isset($_POST['preview']) && !empty($_POST['contents'])): ?>
        <h3 id="preview">Preview</h3>
        <div class="body standalone"><?= parser::parse($_POST['contents'], $uid) ?></div>
    <?php endif; ?>
    
    <div class="row">
        <label for="name">Name</label>
        <input id="name" name="name" type="text" size="30" maxlength="30" tabindex="1" value="<?= htmlspecialchars($set_name) ?>">
    </div>
    
    <label for="contents" class="noscreen">Message</label>
    <textarea name="contents" cols="80" rows="10" tabindex="2" id="contents"><?= htmlspecialchars($message_body) ?></textarea>
    
    <?php if (!empty($_GET['replyto']) && in_array($prev['destination'] ?? '', ['mods', 'admins']) && $perm->get('read_mod_pms')): ?>
        <div class="row">
            <input type="checkbox" name="dismiss" id="dismiss" class="inline" <?= isset($_POST['dismiss']) ? 'checked' : '' ?>>
            <label for="dismiss" class="inline help" title="If checked, other <?= htmlspecialchars($prev['destination']) ?> will no longer be notified of the original message or its current replies (unless the sender replies again).">Dismiss message</label>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <input type="submit" name="preview" value="Preview" class="inline" tabindex="3">
        <input type="submit" name="submit" value="Send" class="inline" tabindex="4">
    </div>
</form>

<?php
$template->render();