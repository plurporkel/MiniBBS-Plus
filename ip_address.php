<?php
require './includes/bootstrap.php';
force_id();

if (!$perm->get('view_profile')) {
    error::fatal(m('Error: Access denied'));
}

// Validate IP address from GET parameter
$ip_address = filter_input(INPUT_GET, 'ip', FILTER_VALIDATE_IP);
if ($ip_address === false) {
    error::fatal('That is not a valid IP address.');
}

$hostname = gethostbyaddr($ip_address);
$hostname = ($hostname === $ip_address) ? false : $hostname;

$template->title = 'Information on IP address ' . htmlspecialchars($ip_address);
$template->onload = 'focusId("ban_length"); init();';

// Check for ban
$banned = false;
if ($perm->ip_banned($ip_address, false)) {
    [$ban_reason, $ban_expiry, $ban_filed] = $perm->get_ban_log($ip_address);
    if (!empty($ban_filed) && ($ban_expiry == 0 || $ban_expiry > time())) {
        $banned = true;
    }
}

// Get statistics with prepared statements
$ip_num_topics = $db->q('SELECT count(*) FROM topics WHERE author_ip = ?', $ip_address)->fetchColumn();
$ip_num_replies = $db->q('SELECT count(*) FROM replies WHERE author_ip = ?', $ip_address)->fetchColumn();
$ip_num_ids = $db->q('SELECT count(*) FROM users WHERE ip_address = ?', $ip_address)->fetchColumn();

// Output IP info
?>
<p>This IP address (
    <?php if ($hostname): ?>
        <strong><?= htmlspecialchars($hostname) ?></strong>
    <?php else: ?>
        no valid host name
    <?php endif; ?>
) is associated with <strong><?= (int) $ip_num_ids ?></strong> ID<?= $ip_num_ids == 1 ? '' : 's' ?>
and has been used to post <strong><?= $ip_num_topics ?></strong> existing topic<?= $ip_num_topics == 1 ? '' : 's' ?>
and <strong><?= $ip_num_replies ?></strong> existing repl<?= $ip_num_replies == 1 ? 'y' : 'ies' ?>.</p>

<?php if ($banned): ?>
    <p>This IP is currently <strong>banned</strong>. The ban was filed 
        <span class="help" title="<?= htmlspecialchars(format_date($ban_filed)) ?>">
            <?= htmlspecialchars(age($ban_filed)) ?> ago
        </span> and will 
        <?php if ($ban_expiry == 0): ?>
            last indefinitely
        <?php else: ?>
            expire in <?= htmlspecialchars(age($ban_expiry)) ?>
        <?php endif; ?>.
    </p>
<?php endif; ?>

<form action="<?= htmlspecialchars(DIR) ?>ban" method="post">
    <?php csrf_token() ?>
    <input type="hidden" name="target" value="<?= htmlspecialchars($ip_address) ?>">
    <div class="row">
        <label for="ban_length" class="inline">Ban length</label>
        <input type="text" name="length" id="ban_length" 
               value="<?= !$banned ? '1 day' : '' ?>" 
               class="inline help" tabindex="1" 
               title="A ban length of 'indefinite' or '0' will never expire." 
               onclick="this.value = ''">
        <label for="ban_reason" class="inline">Reason</label>
        <input type="text" name="reason" id="ban_reason" 
               value="<?= htmlspecialchars($ban_reason ?? '') ?>" 
               class="inline help" maxlength="260" tabindex="2" 
               title="Optional.">
        <input type="submit" value="<?= $banned ? 'Update ban length' : 'Ban' ?>" class="inline">
    </div>
</form>

<ul class="menu">
    <?php if ($banned): ?>
        <li><a href="<?= htmlspecialchars(DIR) ?>unban_IP/<?= htmlspecialchars($ip_address) ?>">Unban</a></li>
    <?php endif; ?>
    <li><a href="<?= htmlspecialchars(DIR) ?>delete_IP_IDs/<?= htmlspecialchars($ip_address) ?>">Delete all IDs</a></li>
    <li><a href="<?= htmlspecialchars(DIR) ?>nuke_IP/<?= htmlspecialchars($ip_address) ?>">Delete all posts</a></li>
    <li><a target="_blank" href="https://whois.domaintools.com/<?= htmlspecialchars($ip_address) ?>">Whois</a></li>
</ul>

<?php
if ($ip_num_ids > 0) {
    echo '<h4 class="section">IDs</h4>';
    
    $res = $db->q('SELECT uid, created_at, post_count FROM users WHERE ip_address = ? ORDER BY post_count DESC, created_at DESC LIMIT 5000', $ip_address);
    
    $columns = ['ID', 'Post count ▼', 'First seen'];
    $id_table = new Table($columns, 0);
    
    while ($id = $res->fetchObject()) {
        if ($perm->get('limit_ip') && $perm->get('limit_ip_max') < $id->post_count) {
            $values = [
                '(Hidden.)',
                format_number($perm->get('limit_ip_max')) . '+',
                '?'
            ];
        } else {
            $values = [
                '<a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($id->uid) . '">' . htmlspecialchars($id->uid) . '</a>',
                format_number($id->post_count),
                '<span class="help" title="' . htmlspecialchars(format_date($id->created_at)) . '">' . htmlspecialchars(age($id->created_at)) . '</span>'
            ];
        }
        $id_table->row($values);
    }
    $id_table->output();
}
$template->render();