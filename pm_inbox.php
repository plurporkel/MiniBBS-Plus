<?php
define('REPRIEVE_BAN', true); // Must come before bootstrap
require './includes/bootstrap.php';
force_id();

$page = new Paginate();
$outbox = !empty($_GET['outbox']);
$ignorebox = !empty($_GET['ignored']);
$uid = $_SESSION['UID'] ?? '';

$template->title = $outbox ? 'Outbox' : ($ignorebox ? 'Ignored private messages' : 'Inbox');
if ($page->current > 1) {
    $template->title .= ', page #' . number_format($page->current);
}

$res = $db->q('SELECT 1 FROM pm_ignorelist WHERE uid = ? AND ignored_uid = "*"', $uid);
$ignoring_all_users = (bool) $res->fetchColumn();

$num_ignored = 0;
if (!$ignorebox) {
    $res = $db->q('SELECT COUNT(*) FROM private_messages WHERE ignored = 1 AND destination = ?', $uid);
    $num_ignored = (int) $res->fetchColumn();
}

$db->select('id, parent, source, destination, contents, time, name, trip')
   ->from('private_messages');

if ($outbox) {
    $db->where('source = ?', $uid);
} elseif ($ignorebox) {
    $db->where('ignored = 1 AND destination = ?', $uid);
} elseif ($perm->get('read_admin_pms')) {
    $db->where('ignored = 0 AND (destination = ? OR destination = "mods" OR destination = "admins")', $uid);
} elseif ($perm->get('read_mod_pms')) {
    $db->where('ignored = 0 AND (destination = ? OR destination = "mods")', $uid);
} else {
    $db->where('ignored = 0 AND destination = ?', $uid);
}

$res = $db->group_by('parent')
          ->order_by('time DESC')
          ->limit($page->offset, $page->limit)
          ->exec();

$columns = ['Recipient' => !$outbox ? 'Author' : 'Recipient', 'Snippet', 'Age ▼'];
if ($perm->get('delete')) {
    $columns[] = 'Delete';
}
$pms = new Table($columns, 1);
$pms->add_td_class(1, 'snippet');

while ($pm = $res->fetchObject()) {
    $author = $outbox 
        ? (in_array($pm->destination, ['mods', 'admins']) 
            ? ucfirst($pm->destination) 
            : ($perm->get('view_profile') 
                ? '<a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($pm->destination) . '">' . htmlspecialchars($pm->destination) . '</a>' 
                : 'A poster'))
        : ($pm->source === 'system' 
            ? m('System') 
            : ($perm->get('view_profile') 
                ? '<a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($pm->source) . '">' . htmlspecialchars($pm->source) . '</a>' 
                : format_name($pm->name, $pm->trip)));

    $values = [
        $author,
        '<a href="' . htmlspecialchars(DIR) . 'private_message/' . htmlspecialchars($pm->parent) . '">' . htmlspecialchars(parser::snippet($pm->contents)) . '</a>',
        '<span class="help" title="' . htmlspecialchars(format_date($pm->time)) . '">' . htmlspecialchars(age($pm->time)) . '</span>'
    ];
    
    if ($perm->get('delete')) {
        $values[] = '<a href="' . htmlspecialchars(DIR) . 'delete_message/' . htmlspecialchars($pm->parent) . '">✘</a>';
    }
    
    $pms->row($values);
}
?>

<ul class="menu">
    <li><a href="<?= htmlspecialchars(DIR) ?>compose_message/mods">Mod PM</a></li>
    <li><a href="<?= htmlspecialchars(DIR) ?>compose_message/admins">Admin PM</a></li>
    <?php if ($ignorebox || $outbox): ?>
        <li><a href="<?= htmlspecialchars(DIR) ?>private_messages">Inbox</a></li>
    <?php endif; ?>
    <?php if (!$outbox): ?>
        <li><a href="<?= htmlspecialchars(DIR) ?>outbox">Outbox</a></li>
    <?php endif; ?>
    <?php if (!$ignorebox && $num_ignored > 0): ?>
        <li><a href="<?= htmlspecialchars(DIR) ?>ignored_PMs">Show ignored PMs</a> (<?= $num_ignored ?>)</li>
    <?php endif; ?>
    <?php if (!$ignoring_all_users): ?>
        <li><a href="<?= htmlspecialchars(DIR) ?>ignore_PM/*" class="help" title="You will no longer be notified of any PM, except those sent by mods or admins. All currently unread messages will be marked as read." onclick="return quickAction(this, 'Really ignore all future user-to-user PMs?');">Ignore all PMs</a></li>
    <?php else: ?>
        <li><a href="<?= htmlspecialchars(DIR) ?>unignore_PM/*" class="help" title="You are not currently being notified of new PMs, except those sent by mods or admins." onclick="return quickAction(this, 'Really stop ignoring PMs?');">Stop ignoring PMs</a></li>
    <?php endif; ?>
</ul>

<?php
$pms->output('(No PMs to display.)');
$page->navigation($outbox ? 'outbox' : 'private_messages', $pms->row_count);
$template->render();