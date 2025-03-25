<?php
require './includes/bootstrap.php';

// Validate and sanitize the topic ID
$topic_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($topic_id === false || $topic_id <= 0) {
    error::fatal('Invalid ID.');
}

// Fetch topic data
$res = $db->q('SELECT headline, visits, replies, author FROM topics WHERE id = ?', $topic_id);
$topic_data = $res->fetch();

if ($topic_data === false) {
    $template->title = 'Non-existent topic';
    error::fatal('There is no such topic. It may have been deleted.');
}

list($topic_headline, $topic_visits, $topic_replies, $topic_author) = $topic_data;

update_activity('topic_trivia', $topic_id);

$template->title = 'Trivia for topic: <a href="' . htmlspecialchars(DIR . 'topic/' . $topic_id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($topic_headline, ENT_QUOTES, 'UTF-8') . '</a>';

// Fetch statistics
$topic_watchers = $db->q('SELECT count(*) FROM watchlists WHERE topic_id = ?', $topic_id)->fetchColumn();
$topic_readers = $db->q('SELECT count(*) FROM activity WHERE action_name = ? AND action_id = ?', 'topic', $topic_id)->fetchColumn();
$topic_writers = $db->q('SELECT count(*) FROM activity WHERE action_name = ? AND action_id = ?', 'replying', $topic_id)->fetchColumn();
$topic_participants = $db->q('SELECT count(DISTINCT author) FROM replies WHERE parent_id = ? AND author != ?', $topic_id, $topic_author)->fetchColumn() + 1; // Include topic author
?>

<table>
    <tr>
        <th class="minimal">Total visits</th>
        <td><?php echo format_number($topic_visits); ?></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Watchers</th>
        <td><?php echo format_number($topic_watchers); ?></td>
    </tr>
    <tr>
        <th class="minimal">Participants</th>
        <td><?php echo ($topic_participants === 1) ? '(Just the creator.)' : format_number($topic_participants); ?></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Replies</th>
        <td><?php echo format_number($topic_replies); ?></td>
    </tr>
    <tr>
        <th class="minimal">Current readers</th>
        <td><?php echo format_number($topic_readers); ?></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Current reply writers</th>
        <td><?php echo format_number($topic_writers); ?></td>
    </tr>
</table>

<?php
$template->render();
?>