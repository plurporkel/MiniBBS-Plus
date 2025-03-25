<?php
require './includes/bootstrap.php';
update_activity('mod_log', 1);

$page = new Paginate();
$template->title = ($page->current === 1) 
    ? 'Latest moderator logs' 
    : 'Mod logs, page #' . number_format($page->current);

$now = time();
setcookie('last_mod_action', $now, $now + 315569260, '/', '', false, true);

$res = $db->q('SELECT COUNT(*) FROM mod_actions WHERE time > ?', $now - 86400);
$todays_count = $res->fetchColumn();

$res = $db->q("SELECT mod_uid, COUNT(*) AS action_count FROM mod_actions WHERE mod_uid != 'system' GROUP BY mod_uid ORDER BY action_count DESC");
$stats = array_column($res->fetchAll(PDO::FETCH_ASSOC), 'action_count', 'mod_uid');
$total = array_sum($stats);

if ($total > 0) {
    echo '<p>A total of <strong>' . number_format($total) . '</strong> actions have been taken by the mods (' . number_format($todays_count) . ' in the last 24 hours); ';
    $punctuation = '';
    foreach ($stats as $mod_id => $count) {
        $mod_name = $perm->get_name($mod_id) ?: 'an unnamed mod';
        if ($count == $total) {
            echo 'all by ' . htmlspecialchars($mod_name);
            break;
        }
        echo $punctuation . ' ' . number_format($count) . ' by <a href="' . htmlspecialchars(DIR) . 'mod_log/mod/' . htmlspecialchars($mod_name) . '">' . htmlspecialchars($mod_name) . '</a>';
        $punctuation = ',';
    }
    echo '.</p>';
}

$searchable_types = [
    '' => 'All logs',
    'delete' => 'Deletion logs',
    'edit' => 'Edit logs',
    'ban' => 'Ban logs',
    'unban' => 'Unban logs',
    'lock' => 'Lock logs',
    'stick' => 'Sticky logs',
    'defcon' => 'DEFCON logs',
    'cms' => 'CMS logs',
    'merge' => 'Merge logs',
    'system' => 'System logs'
];
?>
<fieldset>
    <legend>Search logs</legend>
    <form action="" method="post">
        <select name="log_type">
            <?php foreach ($searchable_types as $type => $label): ?>
                <option value="<?= htmlspecialchars($type) ?>"<?= ($type === ($_REQUEST['log_type'] ?? '')) ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
        
        <label class="inline">Mod:</label>
        <select name="mod">
            <option value="">All mods</option>
            <?php foreach ($stats as $mod_id => $tmp): ?>
                <?php $mod_name = $perm->get_name($mod_id) ?: ''; ?>
                <option value="<?= htmlspecialchars($mod_name) ?>"<?= ($mod_name === ($_REQUEST['mod'] ?? '')) ? ' selected' : '' ?>><?= htmlspecialchars($mod_name) ?></option>
            <?php endforeach; ?>
        </select>
        
        <label class="inline help" title="For example, an IP address or topic ID.">Target:</label>
        <input type="text" name="target" class="inline" value="<?= htmlspecialchars($_REQUEST['target'] ?? '') ?>">
        
        <input type="submit" value="Search" class="inline">
    </form>
</fieldset>
<?php
$db->select('m.id, m.action, m.target, m.reason, m.param, m.mod_uid, m.time, m.hidden, t.headline, r.body')
   ->from('mod_actions m')
   ->join('topics t', "m.action = 'delete_topic' AND m.target = t.id")
   ->join('replies r', "m.action = 'delete_reply' AND m.target = r.id");

$log_type = $_REQUEST['log_type'] ?? '';
if (!empty($log_type) && isset($searchable_types[$log_type])) {
    $db->where('m.action = ?', $log_type);
}

$mod = $_REQUEST['mod'] ?? '';
if (!empty($mod) && ($mod_id = $perm->get_uid($mod))) {
    $db->where('m.mod_uid = ?', $mod_id);
}

$target = $_REQUEST['target'] ?? '';
if (!empty($target) && strlen($target) < 60) {
    $clean_target = preg_replace('/[^0-9a-z.]/i', '', $target);
    $db->where('m.target = ?', $clean_target);
}

$res = $db->order_by('m.time DESC')->limit($page->offset, $page->limit)->exec();
$columns = ['Mod', 'Action', 'Time ▼'];
$table = new Table($columns, 1);

function censor_ip($ip) {
    $parts = explode('.', $ip, 2);
    return $parts[0] . '.' . preg_replace('/\d/', '*', $parts[1] ?? '');
}

$new_items = false;
while ($log = $res->fetchObject()) {
    $undo = '';
    
    $mod_name = ($log->mod_uid === 'system') ? m('System') : ($perm->get_name($log->mod_uid) ?: '?');
    if ($perm->get('view_profile') && $log->mod_uid !== 'system') {
        $mod_name = '<a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($log->mod_uid) . '">' . htmlspecialchars($mod_name) . '</a>';
    }

    switch ($log->action) {
        case 'db_maintenance':
            $action = 'Optimized the database; ' . number_format((int) $log->param) . ' rows removed.';
            break;
        case 'delete_image':
            $action = 'Deleted an image (' . htmlspecialchars($log->param) . ').';
            break;
        case 'delete_page':
            $action = 'Deleted a page.';
            if ($perm->get('cms')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'undelete_page/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really undelete page?\');">undo</a>]';
            }
            break;
        case 'undelete_page':
            $action = 'Restored a page.';
            if ($perm->get('cms')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'delete_page/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really delete page?\');">undo</a>]';
            }
            break;
        case 'edit_topic':
            $action = 'Edited <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('edit_others')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'revert_change/' . htmlspecialchars($log->param) . '" onclick="return quickAction(this, \'Really revert that topic edit?\');">undo</a>]';
            }
            break;
        case 'edit_reply':
            $action = 'Edited <a href="' . htmlspecialchars(DIR) . 'reply/' . htmlspecialchars($log->target) . '">a reply</a>.';
            if ($perm->get('edit_others')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'revert_change/' . htmlspecialchars($log->param) . '" onclick="return quickAction(this, \'Really revert that reply edit?\');">undo</a>]';
            }
            break;
        case 'ban_uid':
            $action = ($log->target === ($_SESSION['UID'] ?? '')) 
                ? 'Banned <em>your</em> UID'
                : ($perm->get('view_profile') 
                    ? 'Banned <a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>' 
                    : 'Banned a UID');
            $action .= ($log->param == 0) ? ' indefinitely.' : ' for ' . age($log->param, $log->time) . '.';
            if ($log->param != '0' && (int) $log->param < $now) {
                $undo = '[expired]';
            } elseif ($perm->get('ban')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'unban_poster/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unban ' . htmlspecialchars($log->target, ENT_QUOTES) . '?\');">undo</a>]';
            }
            break;
        case 'unban_uid':
            $action = ($log->target === ($_SESSION['UID'] ?? '')) 
                ? 'Unbanned <em>your</em> UID.'
                : ($perm->get('view_profile') 
                    ? 'Unbanned <a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>.' 
                    : 'Unbanned a UID.');
            break;
        case 'ban_ip':
            $action = ($log->target === ($_SERVER['REMOTE_ADDR'] ?? '')) 
                ? 'Banned <em>your</em> IP (' . htmlspecialchars($log->target) . ')'
                : ($perm->get('view_profile') 
                    ? 'Banned <a href="' . htmlspecialchars(DIR) . 'IP_address/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>' 
                    : 'Banned an IP (' . censor_ip($log->target) . ')');
            $action .= ($log->param == 0) ? ' indefinitely.' : ' for ' . age($log->param, $log->time) . '.';
            if ($log->param != '0' && (int) $log->param < $now) {
                $undo = '[expired]';
            } elseif ($perm->get('ban')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'unban_IP/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unban ' . htmlspecialchars($log->target, ENT_QUOTES) . '?\');">undo</a>]';
            }
            break;
        case 'unban_ip':
            $action = ($log->target === ($_SERVER['REMOTE_ADDR'] ?? '')) 
                ? 'Unbanned <em>your</em> IP (' . htmlspecialchars($log->target) . ').'
                : ($perm->get('view_profile') 
                    ? 'Unbanned <a href="' . htmlspecialchars(DIR) . 'IP_address/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>.' 
                    : 'Unbanned an IP (' . censor_ip($log->target) . ').');
            break;
        case 'ban_cidr':
            [$subnet, $suffix] = explode('/', $log->target, 2);
            $affected = pow(2, 32 - (int) $suffix);
            $action = $perm->get('view_profile') 
                ? 'Banned a CIDR range (' . htmlspecialchars($log->target) . ') of ' . number_format($affected) . ' IP addresses'
                : 'Banned a CIDR range (' . htmlspecialchars(censor_ip($subnet) . '/' . $suffix) . ') of ' . number_format($affected) . ' IP addresses';
            $action .= ($log->param == 0) ? ' indefinitely.' : ' for ' . age($log->param, $log->time) . '.';
            if ($log->param != '0' && (int) $log->param < $now) {
                $undo = '[expired]';
            } elseif ($perm->get('ban')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'unban_CIDR/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unban ' . htmlspecialchars($log->target, ENT_QUOTES) . '?\');">undo</a>]';
            }
            break;
        case 'unban_cidr':
            [$subnet, $suffix] = explode('/', $log->target, 2);
            $action = $perm->get('view_profile') 
                ? 'Unbanned a CIDR range (' . htmlspecialchars($log->target) . ').'
                : 'Unbanned a CIDR range (' . htmlspecialchars(censor_ip($subnet) . '/' . $suffix) . ').';
            break;
        case 'ban_wild':
            $action = 'Banned a wildcard range (' . htmlspecialchars($log->target) . ')' . 
                      (($log->param == 0) ? ' indefinitely.' : ' for ' . age($log->param, $log->time) . '.');
            if ($log->param != '0' && (int) $log->param < $now) {
                $undo = '[expired]';
            } elseif ($perm->get('ban')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'unban_wild/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unban ' . htmlspecialchars($log->target, ENT_QUOTES) . '?\');">undo</a>]';
            }
            break;
        case 'unban_wild':
            $action = 'Unbanned a wildcard range (' . htmlspecialchars($log->target) . ').';
            break;
        case 'stick_topic':
            $action = 'Stuck <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('stick')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'unstick_topic/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unstick that topic?\');">undo</a>]';
            }
            break;
        case 'unstick_topic':
            $action = 'Unstuck <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('stick')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'stick_topic/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really sticky that topic?\');">undo</a>]';
            }
            break;
        case 'lock_topic':
            $action = 'Locked <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('lock')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'unlock_topic/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unlock that topic?\');">undo</a>]';
            }
            break;
        case 'unlock_topic':
            $action = 'Unlocked <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('lock')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'lock_topic/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really lock that topic?\');">undo</a>]';
            }
            break;
        case 'delete_topic':
            $action = $perm->get('undelete') 
                ? 'Deleted <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>'
                : 'Deleted topic #' . number_format((int) $log->target);
            $action .= ' ("' . htmlspecialchars($log->headline ?? '') . '") by ' . (empty(trim($log->param)) ? m('Anonymous') : htmlspecialchars($log->param)) . '.';
            if ($perm->get('undelete')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'undelete_topic/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really restore that topic?\');">undo</a>]';
            }
            break;
        case 'delete_reply':
            $action = $perm->get('undelete') 
                ? 'Deleted <a href="' . htmlspecialchars(DIR) . 'reply/' . htmlspecialchars($log->target) . '">a reply</a>'
                : 'Deleted reply #' . number_format((int) $log->target);
            $action .= ' ("' . htmlspecialchars(parser::snippet($log->body ?? '')) . '") by ' . (empty(trim($log->param)) ? m('Anonymous') : htmlspecialchars($log->param)) . '.';
            if ($perm->get('undelete')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'undelete_reply/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really restore that reply?\');">undo</a>]';
            }
            break;
        case 'undelete_topic':
            $action = 'Restored <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('delete')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'delete_topic/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really delete that topic?\');">undo</a>]';
            }
            break;
        case 'undelete_reply':
            $action = 'Restored <a href="' . htmlspecialchars(DIR) . 'reply/' . htmlspecialchars($log->target) . '">a reply</a>.';
            if ($perm->get('delete')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'delete_reply/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really delete that reply?\');">undo</a>]';
            }
            break;
        case 'delete_bulletin':
            $action = 'Deleted a bulletin.';
            break;
        case 'delete_ip_ids':
            $action = $perm->get('view_profile') 
                ? 'Purged all UIDs associated with <a href="' . htmlspecialchars(DIR) . 'IP_address/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>.'
                : 'Purged all UIDs associated with an IP (' . censor_ip($log->target) . ').';
            break;
        case 'nuke_id':
            $action = ($log->target === ($_SESSION['UID'] ?? '')) 
                ? 'Nuked <em>your</em> UID.'
                : ($perm->get('view_profile') 
                    ? 'Nuked <a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>.' 
                    : 'Nuked an ID.');
            break;
        case 'nuke_ip':
            $action = ($log->target === ($_SERVER['REMOTE_ADDR'] ?? '')) 
                ? 'Nuked <em>your</em> IP (' . htmlspecialchars($log->target) . ').'
                : ($perm->get('view_profile') 
                    ? 'Nuked <a href="' . htmlspecialchars(DIR) . 'IP_address/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>.' 
                    : 'Nuked an IP (' . censor_ip($log->target) . ').');
            break;
        case 'defcon':
            $action = 'Adjusted the DEFCON to ' . htmlspecialchars($log->target) . '.';
            if ($perm->get('defcon')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'defcon">undo</a>]';
            }
            break;
        case 'cms_new':
            $action = 'Created a page (<a href="' . htmlspecialchars(DIR) . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>).';
            break;
        case 'cms_edit':
            $action = 'Edited a page (<a href="' . htmlspecialchars(DIR) . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>).';
            if ($perm->get('cms')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'revert_change/' . htmlspecialchars($log->param) . '" onclick="return quickAction(this, \'Really revert that page edit?\');">undo</a>]';
            }
            break;
        case 'revert_page':
            $action = 'Reverted changes to a page.';
            if ($perm->get('cms')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'revert_change/' . htmlspecialchars($log->param) . '" onclick="return quickAction(this, \'Really undo that page reversion?\');">undo</a>]';
            }
            break;
        case 'revert_reply':
            $action = 'Reverted changes to <a href="' . htmlspecialchars(DIR) . 'reply/' . htmlspecialchars($log->target) . '">a reply</a>.';
            if ($perm->get('edit_others')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'revert_change/' . htmlspecialchars($log->param) . '" onclick="return quickAction(this, \'Really undo that reply reversion?\');">undo</a>]';
            }
            break;
        case 'revert_topic':
            $action = 'Reverted changes to <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('edit_others')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'revert_change/' . htmlspecialchars($log->param) . '" onclick="return quickAction(this, \'Really undo that topic reversion?\');">undo</a>]';
            }
            break;
        case 'perm_change':
            $action = $perm->get('view_profile') 
                ? 'Changed permissions of <a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($log->target) . '">' . htmlspecialchars($log->target) . '</a>.'
                : 'Changed permissions of a UID.';
            if ($perm->get('manage_permissions')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'manage_permissions/' . htmlspecialchars($log->target) . '">undo</a>]';
            }
            break;
        case 'merge':
            $action = 'Merged a topic (#' . number_format((int) $log->target) . ') into <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->param) . '">another</a>.';
            if ($perm->get('merge')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'undo_merge/' . htmlspecialchars($log->target) . '" onclick="return quickAction(this, \'Really unmerge that topic?\');">undo</a>]';
            }
            break;
        case 'unmerge':
            $action = 'Unmerged <a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($log->target) . '">a topic</a>.';
            if ($perm->get('merge')) {
                $undo = '[<a href="' . htmlspecialchars(DIR) . 'merge/' . htmlspecialchars($log->target) . '">undo</a>]';
            }
            break;
        default:
            $action = 'Undefined action (' . htmlspecialchars($log->action) . ')';
    }

    if (!empty($log->reason)) {
        $action .= ' Reason: "' . htmlspecialchars($log->reason) . '".';
    }

    if ($perm->get('hide_log')) {
        $undo .= $log->hidden 
            ? ' [<a href="' . htmlspecialchars(DIR) . 'unhide_log/' . htmlspecialchars($log->id) . '" onclick="return quickAction(this, \'Really unhide that log?\');">unhide</a>] ' 
            : ' [<a href="' . htmlspecialchars(DIR) . 'hide_log/' . htmlspecialchars($log->id) . '" onclick="return quickAction(this, \'Really hide the log summary from unprivileged users?\');">hide</a>] ';
        if ($log->hidden) $action .= ' <em>(Hidden.)</em>';
    } elseif ($log->hidden) {
        $action = '<em>(Hidden.)</em>';
    }

    if ($log->mod_uid === ($_SESSION['UID'] ?? '')) {
        $undo = '<a href="' . htmlspecialchars(DIR) . 'edit_reason/' . htmlspecialchars($log->id) . '" onclick="return editReason(this, \'' . rawurlencode($log->reason ?? '') . '\', \'' . htmlspecialchars($_SESSION['token'] ?? '') . '\')" title="Edit reason" class="help mod_edit">[+]</a> ' . $undo;
    }

    if (!empty($undo)) {
        $action .= ' <span class="undo">' . $undo . '</span>';
    }

    $values = [
        $mod_name,
        $action,
        '<span class="help" title="' . htmlspecialchars(format_date($log->time)) . '">' . htmlspecialchars(age($log->time)) . '</span>'
    ];

    $row_class = '';
    if ($log->time > ($_COOKIE['last_mod_action'] ?? 0)) {
        $new_items = true;
    } elseif ($new_items) {
        $row_class = 'last_seen_marker';
        $new_items = false;
    }

    $table->row($values, $row_class);
}

$table->output('(No mod actions to display.)');
$page->navigation('mod_log', $table->row_count);
$template->render();