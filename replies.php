<?php
require './includes/bootstrap.php';

$page = new Paginate();
$template->title = ($page->current === 1) 
    ? 'Latest replies' 
    : 'Replies, page #' . number_format($page->current);

update_activity($page->current === 1 ? 'latest_replies' : 'replies', $page->current);

$res = $db->q(
    'SELECT replies.id, replies.parent_id, replies.time, replies.body, replies.namefag, replies.tripfag, replies.link, 
            topics.headline, topics.time AS parent_time 
     FROM replies 
     INNER JOIN topics ON replies.parent_id = topics.id 
     WHERE replies.deleted = 0 AND topics.deleted = 0 
     ORDER BY replies.id DESC 
     LIMIT ?, ?',
    $page->offset, $page->limit
);

$columns = ['Snippet', 'Topic', 'Name', 'Age ▼'];
$replies_table = new Table($columns, 1);
$replies_table->add_td_class(1, 'topic_headline');
$replies_table->add_td_class(0, 'snippet');

while ($reply = $res->fetchObject()) {
    $values = [
        '<a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($reply->parent_id) . '#reply_' . htmlspecialchars($reply->id) . '">' . htmlspecialchars(parser::snippet($reply->body)) . '</a>',
        '<a href="' . htmlspecialchars(DIR) . 'topic/' . htmlspecialchars($reply->parent_id) . '">' . htmlspecialchars($reply->headline) . '</a> <span class="help unimportant" title="' . htmlspecialchars(format_date($reply->parent_time)) . '">(' . htmlspecialchars(age($reply->parent_time)) . ' old)</span>',
        format_name($reply->namefag, $reply->tripfag, $reply->link),
        '<span class="help" title="' . htmlspecialchars(format_date($reply->time)) . '">' . htmlspecialchars(age($reply->time)) . '</span>'
    ];
    $replies_table->row($values);
}

$replies_table->output();
$page->navigation('replies', $replies_table->row_count);
$template->render();