<?php
declare(strict_types=1);

require './includes/bootstrap.php';
update_activity('history');
force_id();

$page = new Paginate();
$template->title = 'Your posting history';

if ($page->current > 1) {
    $template->title .= ', page #' . number_format($page->current);
}

if (isset($_POST['clear_citations']) && check_token()) {
    $db->q('DELETE FROM citations WHERE uid = ?', $_SESSION['UID']);
    redirect('Citations cleared.', '');
}

if ($notifications['citations']) {
    if (!isset($_GET['citations'])) {
        echo '<h4 class="section">Replies to your replies</h4>';
    } else {
        $template->title = 'Replies to your replies';
    }

    // Delete notifications of replies-to-replies that no longer exist.
    $db->q(
        "DELETE citations FROM citations
        INNER JOIN replies ON citations.reply = replies.id 
        INNER JOIN topics ON citations.topic = topics.id 
        WHERE citations.uid = ? AND (topics.deleted = '1' OR replies.deleted = '1')",
        $_SESSION['UID']
    );

    // List replies to user's replies.
    $res = $db->q(
        'SELECT DISTINCT citations.reply AS id, replies.parent_id, replies.time, replies.body, topics.headline, topics.time AS parent_time
        FROM citations 
        INNER JOIN replies ON citations.reply = replies.id 
        INNER JOIN topics ON replies.parent_id = topics.id 
        WHERE citations.uid = ? ORDER BY citations.reply DESC LIMIT ?, ?',
        $_SESSION['UID'],
        $page->offset,
        $page->limit
    );

    $columns = [
        'Reply to your reply',
        'Topic',
        'Age ▼'
    ];
    $citations = new Table($columns, 1);
    $citations->add_td_class(1, 'topic_headline');
    $citations->add_td_class(0, 'reply_body_snippet');

    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        $values = [
            '<a href="' . DIR . 'topic/' . $row['parent_id'] . ($_SESSION['settings']['posts_per_page'] ? '/reply/' : '#reply_') . $row['id'] . '">' . parser::snippet($row['body']) . '</a>',
            '<a href="' . DIR . 'topic/' . $row['parent_id'] . '">' . htmlspecialchars($row['headline']) . '</a> <span class="help unimportant" title="' . format_date($row['parent_time']) . '">(' . age($row['parent_time']) . ' old)</span>',
            '<span class="help" title="' . format_date($row['time']) . '">' . age($row['time']) . '</span>'
        ];
        $citations->row($values);
    }
    $citations->output('(It appears that the reply to your reply has since been deleted.)');
    ?>
    <form action="" method="post">
        <?php csrf_token() ?>
        <input type="submit" name="clear_citations" value="Clear citations" class="help" title="You will no longer be notified of these replies." />
    </form>
    <?php
}

if (!isset($_GET['citations'])) {
    if ($notifications['citations']) {
        echo '<h4 class="section">Your posts</h4>';
    }

    // List topics.
    $res = $db->q(
        'SELECT id, time, replies, visits, headline, poll, locked, sticky 
        FROM topics 
        WHERE author = ? AND deleted = 0 
        ORDER BY id DESC LIMIT ?, ?',
        $_SESSION['UID'],
        $page->offset,
        $page->limit
    );

    $columns = [
        'Headline',
        'Replies',
        'Visits',
        'Age ▼'
    ];
    $topics = new Table($columns, 0);
    $topics->add_td_class(0, 'topic_headline');

    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        $values = [
            format_headline(htmlspecialchars($row['headline']), $row['id'], $row['replies'], $row['poll'], $row['locked'], $row['sticky']),
            replies($row['id'], $row['replies']),
            format_number($row['visits']),
            '<span class="help" title="' . format_date($row['time']) . '">' . age($row['time']) . '</span>'
        ];
        $topics->row($values);
    }
    $num_topics_fetched = $topics->row_count;
    $topics->output();

    // List replies.
    $res = $db->q(
        'SELECT replies.id, replies.parent_id, replies.time, replies.body, topics.headline, topics.time, topics.replies
        FROM replies 
        INNER JOIN topics ON replies.parent_id = topics.id 
        WHERE replies.author = ? AND replies.deleted = 0 AND topics.deleted = 0 
        ORDER BY replies.id DESC LIMIT ?, ?',
        $_SESSION['UID'],
        $page->offset,
        $page->limit
    );

    $columns = [
        'Reply snippet',
        'Topic',
        'Replies',
        'Age ▼'
    ];
    $replies = new Table($columns, 1);
    $replies->add_td_class(1, 'topic_headline');
    $replies->add_td_class(0, 'reply_body_snippet');

    while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
        $values = [
            '<a href="' . DIR . 'topic/' . $row['parent_id'] . ($_SESSION['settings']['posts_per_page'] ? '/reply/' : '#reply_') . $row['id'] . '">' . parser::snippet($row['body']) . '</a>',
            '<a href="' . DIR . 'topic/' . $row['parent_id'] . '">' . htmlspecialchars($row['headline']) . '</a> <span class="help unimportant" title="' . format_date($row['time']) . '">(' . age($row['time']) . ' old)</span>',
            replies($row['parent_id'], $row['replies']),
            '<span class="help" title="' . format_date($row['time']) . '">' . age($row['time']) . '</span>'
        ];
        $replies->row($values);
    }
    $num_replies_fetched = $replies->row_count;
    $replies->output();
}

if (($num_topics_fetched ?? 0) + ($num_replies_fetched ?? 0) == 0 && !isset($_GET['citations'])) {
    echo '<p>You haven\'t posted anything yet.</p>';
}

$page->navigation('history', $num_replies_fetched ?? 0);
$template->render();
?>