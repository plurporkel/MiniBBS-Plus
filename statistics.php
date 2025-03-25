<?php
require './includes/bootstrap.php';
force_id();
update_activity('statistics');
$template->title = 'Statistics';

// Fetch statistics from the database
$num_topics = $db->q('SELECT count(*) FROM topics WHERE deleted = 0')->fetchColumn();
$num_replies = $db->q('SELECT count(*) FROM replies WHERE deleted = 0')->fetchColumn();
$your_topics = $db->q('SELECT count(*) FROM topics WHERE author = ? AND deleted = 0', $_SESSION['UID'] ?? '')->fetchColumn();
$your_replies = $db->q('SELECT count(*) FROM replies WHERE author = ? AND deleted = 0', $_SESSION['UID'] ?? '')->fetchColumn();
$your_ranking = $db->q('SELECT COUNT(*) + 1 FROM users WHERE post_count > ?', $_SESSION['post_count'] ?? 0)->fetchColumn();
$num_users = $db->q('SELECT COUNT(*) FROM users WHERE post_count > 0')->fetchColumn();
$num_bans = $db->q('SELECT count(*) FROM bans')->fetchColumn();
$average_replies_to_you = round($db->q('SELECT AVG(replies) FROM topics WHERE author = ?', $_SESSION['UID'] ?? '')->fetchColumn() ?? 0, 2);

// Calculate derived statistics
$replies_per_topic = $num_topics ? round($num_replies / $num_topics) : 0;
$your_replies_per_topic = $your_topics ? round($your_replies / $your_topics, 2) : 0;
$your_posts = $your_topics + $your_replies;
$total_posts = $num_topics + $num_replies;

// Calculate days since start and first visit
$now = time();
$days_since_start = floor(($now - SITE_FOUNDED) / 86400) ?: 1;
$days_since_first_visit = floor(($now - ($_SESSION['first_seen'] ?? $now)) / 86400) ?: 1;

// Calculate averages
$posts_per_user = $num_users ? round($total_posts / $num_users, 2) : 0;
$posts_per_day = round($total_posts / $days_since_start);
$topics_per_day = round($num_topics / $days_since_start);
$replies_per_day = round($num_replies / $days_since_start);
$your_posts_per_day = round($your_posts / $days_since_first_visit, 2);
?>

<table>
    <tr>
        <th></th>
        <th class="minimal">Amount</th>
        <th>Comment</th>
    </tr>
    <tr class="odd">
        <th class="minimal">Total existing posts</th>
        <td class="minimal"><?php echo format_number($total_posts); ?></td>
        <td></td>
    </tr>
    <tr>
        <th class="minimal">Existing topics</th>
        <td class="minimal"><?php echo format_number($num_topics); ?></td>
        <td></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Existing replies</th>
        <td class="minimal"><?php echo format_number($num_replies); ?></td>
        <td>That's ~<?php echo $replies_per_topic; ?> replies/topic.</td>
    </tr>
    <tr>
        <th class="minimal">Posts/day</th>
        <td class="minimal">~<?php echo format_number($posts_per_day); ?></td>
        <td></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Topics/day</th>
        <td class="minimal">~<?php echo format_number($topics_per_day); ?></td>
        <td></td>
    </tr>
    <tr>
        <th class="minimal">Replies/day</th>
        <td class="minimal">~<?php echo format_number($replies_per_day); ?></td>
        <td></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Posts/user</th>
        <td class="minimal">~<?php echo $posts_per_user; ?></td>
        <td></td>
    </tr>
    <tr>
        <th class="minimal">Active bans</th>
        <td class="minimal"><?php echo format_number($num_bans); ?></td>
        <td></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Days since launch</th>
        <td class="minimal"><?php echo number_format($days_since_start); ?></td>
        <td>Went live on <?php echo date('Y-m-d', SITE_FOUNDED); ?>, <?php echo age(SITE_FOUNDED); ?> ago.</td>
    </tr>
</table>

<table>
    <tr>
        <th></th>
        <th>Amount</th>
        <th>Comment</th>
    </tr>
    <tr class="odd">
        <th class="minimal">Days since your first visit</th>
        <td class="minimal"><?php echo format_number($days_since_first_visit); ?></td>
        <td>We first saw you on <?php echo date('Y-m-d', $_SESSION['first_seen'] ?? $now); ?>, <?php echo age($_SESSION['first_seen'] ?? $now); ?> ago.</td>
    </tr>
    <tr>
        <th class="minimal">Total posts by you</th>
        <td class="minimal"><?php echo format_number($your_posts); ?></td>
        <td></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Topics started by you</th>
        <td class="minimal"><?php echo format_number($your_topics); ?></td>
        <td></td>
    </tr>
    <tr>
        <th class="minimal">Replies by you</th>
        <td class="minimal"><?php echo format_number($your_replies); ?></td>
        <td>That's ~<?php echo $your_replies_per_topic; ?> replies/topic</td>
    </tr>
    <tr class="odd">
        <th class="minimal">Posts/day by you</th>
        <td class="minimal">~<?php echo $your_posts_per_day; ?></td>
        <td></td>
    </tr>
    <tr>
        <th class="minimal">Your ranking by post count</th>
        <td class="minimal">#<?php echo $your_ranking; ?></td>
        <td></td>
    </tr>
    <tr class="odd">
        <th class="minimal">Average replies to your topics</th>
        <td class="minimal">~<?php echo $average_replies_to_you; ?></td>
        <td></td>
    </tr>
</table>

<?php
$template->render();
?>