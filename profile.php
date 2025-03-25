<?php
require './includes/bootstrap.php';
force_id();

if (!$perm->get('view_profile')) {
    error::fatal(m('Error: Access denied'));
}

// Safely retrieve and validate UID from GET request
$uid = filter_input(INPUT_GET, 'uid', FILTER_SANITIZE_STRING);
if (empty($uid)) {
    error::fatal('No UID specified.');
}

// Handle mass delete action
if (isset($_POST['mass_delete']) && check_token() && $perm->get('delete')) {
    $posts_deleted = 0;
    if (is_array($_POST['topics'] ?? [])) {
        foreach ($_POST['topics'] as $topic_id) {
            delete_topic($topic_id, false);
            $posts_deleted++;
        }
    }
    if (is_array($_POST['replies'] ?? [])) {
        foreach ($_POST['replies'] as $reply_id) {
            delete_reply($reply_id, false);
            $posts_deleted++;
        }
    }
    $_SESSION['notice'] = number_format($posts_deleted) . ' post' . ($posts_deleted === 1 ? '' : 's') . ' deleted.';
}

// Handle mass undelete action
if (isset($_POST['mass_undelete']) && check_token() && $perm->get('undelete')) {
    $posts_restored = 0;
    if (is_array($_POST['undelete_topics'] ?? [])) {
        foreach ($_POST['undelete_topics'] as $topic_id) {
            $db->q("UPDATE topics SET deleted = '0' WHERE id = ?", $topic_id);
            log_mod('undelete_topic', $topic_id);
            $posts_restored++;
        }
    }
    if (is_array($_POST['undelete_replies'] ?? [])) {
        foreach ($_POST['undelete_replies'] as $reply_id) {
            $db->q("UPDATE replies SET deleted = '0' WHERE id = ?", $reply_id);
            log_mod('undelete_reply', $reply_id);
            $posts_restored++;
        }
    }
    $_SESSION['notice'] = number_format($posts_restored) . ' post' . ($posts_restored === 1 ? '' : 's') . ' restored.';
}

// Fetch user data
$res = $db->q('SELECT first_seen, last_seen, ip_address FROM users WHERE uid = ?', $uid);
$user = $res->fetchObject();

if (!$user) {
    error::fatal('There is no such user.');
}

// Fetch post counts
$res = $db->q('SELECT count(*) FROM topics WHERE author = ? AND deleted = 0', $uid);
$topic_count = $res->fetchColumn();
$res = $db->q('SELECT count(*) FROM replies WHERE author = ? AND deleted = 0', $uid);
$reply_count = $res->fetchColumn();
$post_count = $topic_count + $reply_count;

// Determine IP viewing permission
$view_ip = !$perm->get('limit_ip') || $perm->get('limit_ip_max') > $post_count || $user->first_seen > time() - 86400;

if ($view_ip) {
    $id_hostname = @gethostbyaddr($user->ip_address);
    if ($id_hostname === $user->ip_address) {
        $id_hostname = false;
    }
}

// Check ban status
$banned = false;
if ($perm->uid_banned($uid)) {
    list($ban_reason, $ban_expiry, $ban_filed) = $perm->get_ban_log($uid);
    if (!empty($ban_filed) && ($ban_expiry == 0 || $ban_expiry > time())) {
        $banned = true;
    }
}

// Set page title with escaped UID
$template->title = 'Profile of poster ' . htmlspecialchars($uid);

// Display user information
echo '<p>First seen <strong class="help" title="' . htmlspecialchars(format_date($user->first_seen)) . '">' . htmlspecialchars(age($user->first_seen)) . ' ago</strong>';

if ($view_ip) {
    echo ' using the IP address <strong><a href="' . DIR . 'IP_address/' . urlencode($user->ip_address) . '">' . htmlspecialchars($user->ip_address) . '</a></strong> ';
    if ($id_hostname) {
        echo '(<strong>' . htmlspecialchars($id_hostname) . '</strong>)';
    } else {
        echo '(no valid host name)';
    }
}

echo ' and last seen <strong class="help" title="' . htmlspecialchars(format_date($user->last_seen)) . '">' . htmlspecialchars(age($user->last_seen)) . ' ago</strong>, has started <strong>' . number_format($topic_count) . '</strong> existing topic' . ($topic_count == 1 ? '' : 's') . ' and posted <strong>' . number_format($reply_count) . '</strong> existing repl' . ($reply_count == 1 ? 'y' : 'ies') . '.</p>';

if ($banned) {
    echo '<p>This poster is currently <strong>banned</strong>. The ban was filed <span class="help" title="' . htmlspecialchars(format_date($ban_filed)) . '">' . htmlspecialchars(age($ban_filed)) . ' ago</span> and will ';
    if ($ban_expiry == 0) {
        echo 'last indefinitely';
    } else {
        echo 'expire in ' . htmlspecialchars(age($ban_expiry));
    }
    echo '.</p>';
}
?>
<form action="<?php echo DIR ?>ban" method="post">
    <?php csrf_token() ?>
    <input type="hidden" name="target" value="<?php echo htmlspecialchars($uid) ?>" />
    <div class="row">
        <label for="ban_length" class="inline">Ban length</label>
        <input type="text" name="length" id="ban_length" value="<?php echo !$banned ? '1 day' : '' ?>" class="inline help" tabindex="1" title="A ban length of 'indefinite' or '0' will never expire." onclick="this.value = ''" />
        <label for="ban_reason" class="inline">Reason</label>
        <input type="text" name="reason" id="ban_reason" value="<?php echo htmlspecialchars($ban_reason ?? '') ?>" class="inline help" maxlength="260" tabindex="2" title="Optional." />
        <?php if ($view_ip): ?>
            <label for="autoban_ip" class="inline">Ban last IP</label>
            <input type="checkbox" name="autoban_ip" id="autoban_ip" value="1" class="inline" checked="checked" />
        <?php endif; ?>
        <input type="submit" value="<?php echo $banned ? 'Update ban length' : 'Ban' ?>" class="inline" />
    </div>
</form>
<?php
// Display menu
echo '<ul class="menu"><li><a href="' . DIR . 'compose_message/' . $uid . '">Send PM</a>';
if ($banned) {
    echo '<li><a href="' . DIR . 'unban_poster/' . $uid . '" onclick="return quickAction(this, \'Really unban this poster?\');">Unban ID</a></li>';
}
echo '<li><a href="' . DIR . 'nuke_ID/' . $uid . '" onclick="return quickAction(this, \'Really delete all topics and replies by this poster?\');">Delete all posts</a></li>';
echo '<li><a href="' . DIR . 'delete_all_PMs/' . $uid . '" onclick="return quickAction(this, \'Really delete all PMs sent by this user?\');">Delete all PMs</a></li>';
if ($perm->get('manage_permissions')) {
    echo '<li><a href="' . DIR . 'manage_permissions/' . $uid . '">Manage permissions</a></li>';
}
echo '</ul>',
'<form action="" method="post" id="mass_delete">';
csrf_token();

// Pagination setup
$page = new Paginate();
if ($page->current > 1) {
    $template->title .= ', page #' . number_format($page->current);
}

$master_checkbox = '<input type="checkbox" name="master_checkbox" class="inline" onclick="checkAll(\'mass_delete\')" title="Check/uncheck all" />';
if ($topic_count > 0) {
    echo '<h4 class="section">Topics</h4>';

    $res = $db->q(
        'SELECT id, time, replies, visits, headline, author_ip, namefag, tripfag, locked, sticky, poll
        FROM topics 
        WHERE author = ? AND deleted = 0 
        ORDER BY id DESC 
        LIMIT ' . $page->offset . ', ' . $page->limit,
        $uid
    );

    $columns = [
        $master_checkbox . 'Headline',
        'Name',
        'IP address',
        'Replies',
        'Visits',
        'Age ▼'
    ];
    if (!$view_ip) {
        unset($columns[2]);
    }
    $topics = new Table($columns, 0);
    $topics->add_td_class(0, 'topic_headline');

    while ($topic = $res->fetchObject()) {
        $values = [
            '<input type="checkbox" name="topics[]" value="' . $topic->id . '" class="inline" onclick="highlightRow(this)" />' . format_headline(htmlspecialchars($topic->headline), $topic->id, $topic->replies, $topic->poll, $topic->locked, $topic->sticky),
            format_name($topic->namefag, $topic->tripfag, null, null, true),
            '<a href="' . DIR . 'IP_address/' . $topic->author_ip . '">' . $topic->author_ip . '</a>',
            replies($topic->id, $topic->replies),
            format_number($topic->visits),
            '<span class="help" title="' . htmlspecialchars(format_date($topic->time)) . '">' . htmlspecialchars(age($topic->time)) . '</span>'
        ];

        if (!$view_ip) {
            unset($values[2]);
        }

        $topics->row($values);
    }
    $num_topics_fetched = $topics->row_count;
    $topics->output();
}

if ($reply_count > 0) {
    echo '<h4 class="section">Replies</h4>';

    $res = $db->q(
        'SELECT replies.id, replies.parent_id, replies.time, replies.body, replies.author_ip, replies.namefag, replies.tripfag, 
        topics.headline, topics.time AS topic_time 
        FROM replies 
        INNER JOIN topics ON replies.parent_id = topics.id 
        WHERE replies.author = ? AND replies.deleted = 0 AND topics.deleted = 0 
        ORDER BY id DESC 
        LIMIT ' . $page->offset . ', ' . $page->limit,
        $uid
    );

    if ($topic_count) {
        $master_checkbox = '';
    }

    $columns = [
        $master_checkbox . 'Reply snippet',
        'Topic',
        'Name',
        'IP address',
        'Age ▼'
    ];
    if (!$view_ip) {
        unset($columns[3]);
    }
    $replies = new Table($columns, 1);
    $replies->add_td_class(1, 'topic_headline');
    $replies->add_td_class(0, 'reply_body_snippet');

    while ($reply = $res->fetchObject()) {
        $values = [
            '<input type="checkbox" name="replies[]" value="' . $reply->id . '" class="inline" onclick="highlightRow(this)" /><a href="' . DIR . 'topic/' . $reply->parent_id . ($_SESSION['settings']['posts_per_page'] ? '/reply/' : '#reply_') . $reply->id . '">' . parser::snippet($reply->body) . '</a>',
            '<a href="' . DIR . 'topic/' . $reply->parent_id . '">' . htmlspecialchars($reply->headline) . '</a> <span class="help unimportant" title="' . htmlspecialchars(format_date($reply->topic_time)) . '">(' . htmlspecialchars(age($reply->topic_time)) . ' old)</span>',
            format_name($reply->namefag, $reply->tripfag, null, null, true),
            '<a href="' . DIR . 'IP_address/' . $reply->author_ip . '">' . $reply->author_ip . '</a>',
            '<span class="help" title="' . htmlspecialchars(format_date($reply->time)) . '">' . htmlspecialchars(age($reply->time)) . '</span>'
        ];

        if (!$view_ip) {
            unset($values[3]);
        }

        $replies->row($values);
    }
    $num_replies_fetched = $replies->row_count;
    $replies->output();
}

if ($perm->get('delete') && ($num_topics_fetched ?? 0) + ($num_replies_fetched ?? 0)) {
    echo '<div class="row"><input name="mass_delete" type="submit" value="Delete selected" onclick="return confirm(\'Really delete selected posts?\')" /></div></form>';
}

echo '</form>';

$page->navigation('profile/' . $uid, $num_replies_fetched ?? 0);

// Fetch and display trashed posts
$fetch_trash = $db->q(
    "(SELECT id, 0 as parent_id, headline, body, time FROM topics WHERE author = ? AND deleted = '1') 
    UNION 
    (SELECT id, parent_id, '' AS headline, body, time FROM replies WHERE author = ? AND deleted = '1') 
    ORDER BY time DESC",
    $uid, $uid
);

$master_checkbox = '<input type="checkbox" name="master_checkbox" class="inline" onclick="checkAll(\'mass_undelete\')" title="Check/uncheck all" />';
$columns = [
    $master_checkbox . 'Headline',
    'Body',
    'Age ▼'
];
$table = new Table($columns, 1);

while ($trash = $fetch_trash->fetchObject()) {
    if (empty($trash->headline)) {
        $trash->headline = '<input type="checkbox" name="undelete_replies[]" value="' . $trash->id . '" class="inline" onclick="highlightRow(this)" /><span class="unimportant"><a href="' . DIR . 'topic/' . $trash->parent_id . '#reply_' . $trash->id . '">(Reply.)</a></span>';
    } else {
        $trash->headline = '<input type="checkbox" name="undelete_topics[]" value="' . $trash->id . '" class="inline" onclick="highlightRow(this)" /><a href="' . DIR . 'topic/' . $trash->id . '">' . htmlspecialchars($trash->headline) . '</a>';
    }

    $values = [
        $trash->headline,
        parser::snippet($trash->body),
        '<span class="help" title="' . htmlspecialchars(format_date($trash->time)) . '">' . htmlspecialchars(age($trash->time)) . '</span>'
    ];

    $table->row($values);
}

if ($page->current == 1 && $table->row_count > 0) {
    echo '<h4 class="section">Trash</h4><form action="" method="post" id="mass_undelete">';
    csrf_token();
    $table->output();
    echo '<div class="row"><input name="mass_undelete" type="submit" value="Restore selected" onclick="return confirm(\'Really restore selected posts?\')" /></form>';
}

$template->render();