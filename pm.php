<?php
$reading_pm = true;
define('REPRIEVE_BAN', true);
require './includes/bootstrap.php';

force_id();
$template->title = 'Private message';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    error::fatal('Invalid ID.');
}

$uid = $_SESSION['UID'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$has_appealed = $perm->uid_banned($uid) ? $perm->get_ban_appeal($uid) : ($perm->ip_banned($ip) ? $perm->get_ban_appeal($ip) : false);

$res = $db->q(
    'SELECT id, source, parent, destination, contents, time, name, trip, topic, reply, ignored
    FROM private_messages 
    WHERE id = ? OR parent = ? 
    ORDER BY id',
    $id, $id
);

$pm = $res->fetchObject();
if (!$pm) {
    $template->title = 'Non-existent message';
    error::fatal('There is no such private message.');
}

$op_destination = $pm->destination;

if (!$perm->get('read_admin_pms')) {
    if ($pm->destination !== $uid && $pm->source !== $uid && !in_array($pm->destination, ['mods', 'admins'])) {
        error::fatal('This message is not addressed to you.');
    }
    if (($pm->destination === 'admins' && $pm->source !== $uid) || ($pm->destination === 'mods' && $pm->source !== $uid && !$perm->get('read_mod_pms'))) {
        error::fatal(m('Error: Access denied'));
    }
}

if ((int) $pm->parent !== $id) {
    redirect('', 'private_message/' . $pm->parent);
}

if ($pm->destination === 'mods') {
    $template->title .= ' to all moderators';
} elseif ($pm->destination === 'admins') {
    $template->title .= ' to all administrators';
} elseif ($pm->source === 'system') {
    $template->title = 'System message';
    $system_pm = true;
}
?>

<table>
    <thead>
        <tr>
            <th class="minimal">Author</th>
            <th>Message</th>
            <th class="minimal">Age ▼</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $participants = [];
        $i = 0;
        do {
            $participants[$pm->source] = $participants[$pm->source] ?? count($participants);
            $author = $pm->source === 'system' 
                ? m('System') 
                : '<span class="poster_number_' . $participants[$pm->source] . '">' . format_name($pm->name, $pm->trip, $perm->get('link', $pm->source), $participants[$pm->source]) . '</span>' . 
                  ($pm->source === $uid ? ' <span class="unimportant">(you)</span>' : '');
        ?>
            <tr id="reply_box_<?= htmlspecialchars($pm->id) ?>"<?= ($i++ & 1) ? ' class="odd"' : '' ?>>
                <td class="minimal"><?= $author ?></td>
                <td class="pm_body" id="reply_<?= htmlspecialchars($pm->id) ?>">
                    <?= parser::parse($pm->contents, $pm->source) ?>
                    <?php if (!empty($pm->topic)): ?>
                        <?php
                        $tmp = $db->q('SELECT headline, body, namefag, tripfag FROM topics WHERE id = ?', $pm->topic);
                        $topic = $tmp->fetchObject();
                        $recipient_name = $topic->namefag;
                        $recipient_trip = $topic->tripfag;
                        
                        if (!empty($pm->reply)) {
                            $tmp = $db->q('SELECT namefag, tripfag, body FROM replies WHERE id = ?', $pm->reply);
                            $reply = $tmp->fetchObject();
                            $recipient_name = $reply->namefag;
                            $recipient_trip = $reply->tripfag;
                            $reply_body = $reply->body;
                        }
                        ?>
                        <p class="unimportant">(This message was sent via <?= $pm->destination === $uid ? 'your' : 'the recipient\'s' ?> 
                            <?= empty($pm->reply) 
                                ? 'original post' 
                                : '<a href="' . htmlspecialchars(DIR) . 'reply/' . htmlspecialchars($pm->reply) . '" class="help" title="' . htmlspecialchars(parser::snippet($reply_body ?? '')) . '">reply</a>' ?> 
                            as <?= format_name($recipient_name, $recipient_trip) ?> in 
                            "<strong><a href="<?= htmlspecialchars(DIR) ?>topic/<?= htmlspecialchars($pm->topic) ?>" class="help" title="<?= htmlspecialchars(parser::snippet($topic->body)) ?>"><?= htmlspecialchars($topic->headline) ?></a></strong>".)</p>
                    <?php endif; ?>
                    <ul class="menu">
                        <?php if ($pm->ignored && $pm->destination === $uid): ?>
                            <li><a href="<?= htmlspecialchars(DIR) ?>unignore_PM/<?= htmlspecialchars($pm->id) ?>" onclick="return quickAction(this, 'Really stop ignoring PMs from this user?');">Unignore</a></li>
                        <?php elseif ($pm->destination === $uid && $pm->source !== 'system'): ?>
                            <li><a href="<?= htmlspecialchars(DIR) ?>ignore_PM/<?= htmlspecialchars($pm->id) ?>" onclick="return quickAction(this, 'Really ignore all future PMs from this user?');">Ignore</a></li>
                        <?php endif; ?>
                        <?php if ($perm->get('view_profile') && $pm->source !== 'system'): ?>
                            <li><a href="<?= htmlspecialchars(DIR) ?>profile/<?= htmlspecialchars($pm->source) ?>">Profile</a></li>
                        <?php endif; ?>
                        <?php if ($perm->get('delete')): ?>
                            <li><a href="<?= htmlspecialchars(DIR) ?>delete_message/<?= htmlspecialchars($pm->id) ?>" onclick="return quickAction(this, 'Really delete this PM?');">Delete</a></li>
                        <?php else: ?>
                            <li><a href="<?= htmlspecialchars(DIR) ?>report_PM/<?= htmlspecialchars($pm->id) ?>">Report</a></li>
                        <?php endif; ?>
                        <?php if ($pm->parent == $pm->id && in_array($op_destination, ['mods', 'admins']) && $perm->get($op_destination === 'mods' ? 'read_mod_pms' : 'read_admin_pms')): ?>
                            <li><a href="<?= htmlspecialchars(DIR) ?>dismiss_PM/<?= htmlspecialchars($pm->id) ?>" onclick="return quickAction(this, 'Really dismiss this PM?');">Dismiss</a></li>
                        <?php endif; ?>
                    </ul>
                </td>
                <td class="minimal"><span class="help" title="<?= htmlspecialchars(format_date($pm->time)) ?>"><?= htmlspecialchars(age($pm->time)) ?></span></td>
            </tr>
        <?php } while ($pm = $res->fetchObject()); ?>
    </tbody>
</table>

<?php if (empty($has_appealed) && empty($system_pm)): ?>
<ul class="menu">
    <li><a href="<?= htmlspecialchars(DIR) ?>reply_to_message/<?= htmlspecialchars($id) ?>" onclick="$('#quick_reply').toggle(); $('#qr_text').get(0).scrollIntoView(true); $('#qr_text').focus(); return false;">Reply</a></li>
    <li><a href="<?= htmlspecialchars(DIR) ?>private_messages">Inbox</a></li>
</ul>

<div id="quick_reply" class="noscreen">
    <form action="<?= htmlspecialchars(DIR) ?>reply_to_message/<?= htmlspecialchars($id) ?>" method="post">
        <input name="form_sent" type="hidden" value="1">
        <?php csrf_token(); ?>
        <div class="row">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" size="30" maxlength="30" tabindex="1" value="<?= htmlspecialchars($_SESSION['poster_name'] ?? '') ?>">
        </div>
        <textarea name="contents" cols="80" rows="10" tabindex="2" id="qr_text"></textarea>
        <?php if (in_array($op_destination, ['mods', 'admins']) && $perm->get($op_destination === 'mods' ? 'read_mod_pms' : 'read_admin_pms')): ?>
            <div class="row">
                <input type="checkbox" name="dismiss" id="dismiss" class="inline" checked>
                <label for="dismiss" class="inline help" title="If checked, other <?= htmlspecialchars($op_destination) ?> will no longer be notified of this message or its current replies (unless the original sender replies again).">Dismiss message</label>
            </div>
        <?php endif; ?>
        <div class="row">
            <input type="submit" name="preview" value="Preview" class="inline" tabindex="3">
            <input type="submit" name="submit" value="Send" class="inline" tabindex="4">
        </div>
    </form>
</div>
<?php endif; ?>

<?php
$template->render();