<?php
declare(strict_types=1);

require './includes/bootstrap.php';

// Determine sorting mode (topics by creation date or last bump)
$topics_mode = !empty($_GET['topics']) || (!empty($_SESSION['settings']['topics_mode']) && empty($_GET['bumps']));

// Handle pagination
$page = new Paginate();
update_activity('topics', $page->current);

$template->title = $page->current === 1
    ? ($topics_mode ? 'Latest topics' : 'Latest bumps')
    : 'Topics, page #' . number_format($page->current);

// Update last_bump and last_topic cookies
$cookie_options = [
    'expires' => time() + 315569260, // ~10 years
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
];
$last_seen = $topics_mode ? ($_COOKIE['last_topic'] ?? 0) : ($_COOKIE['last_bump'] ?? 0);

if (empty($_COOKIE['last_bump']) || (int)$_COOKIE['last_bump'] <= $last_actions['last_bump']) {
    setcookie('last_bump', (string)time(), $cookie_options);
    $_COOKIE['last_bump'] = time();
}
if (empty($_COOKIE['last_topic']) || (int)$_COOKIE['last_topic'] <= $last_actions['last_topic']) {
    setcookie('last_topic', (string)time(), $cookie_options);
    $_COOKIE['last_topic'] = time();
}

// Display bulletins
$last_seen_bulletin = (int)($_COOKIE['last_bulletin'] ?? 0);
if (defined('BULLETINS_ON_INDEX') && BULLETINS_ON_INDEX > 0 && 
    (!isset($last_actions['last_bulletin']) || $last_actions['last_bulletin'] > $last_seen_bulletin)) {
    setcookie('last_bulletin', (string)time(), $cookie_options);

    $columns = ['Author', 'Bulletin', 'Age ▼'];
    if ($perm->get('delete')) {
        $columns[] = 'Delete';
    }

    $table = new Table($columns, 1);
    $stmt = $db->q(
        'SELECT id, message, time, author, name, trip 
         FROM bulletins 
         WHERE time > :last_seen 
         ORDER BY id DESC 
         LIMIT :limit',
        ['last_seen' => $last_seen_bulletin, 'limit' => (int)BULLETINS_ON_INDEX]
    );

    while ($bulletin = $stmt->fetchObject()) {
        $values = [
            format_name($bulletin->name, $bulletin->trip, $perm->get('link', $bulletin->author)),
            parser::parse($bulletin->message),
            '<span class="help" title="' . format_date($bulletin->time) . '">' . age($bulletin->time) . '</span>',
        ];
        if ($perm->get('delete')) {
            $csrf_token = bin2hex(random_bytes(16));
            $_SESSION['csrf_token'] = $csrf_token; // Store in session for validation
            $values[] = '<a href="' . DIR . 'delete_bulletin/' . $bulletin->id . '?csrf=' . $csrf_token . '" 
                          onclick="return quickAction(this, \'Really delete this bulletin?\');">✘</a>';
        }
        $table->row($values);
    }
    $table->output();
}

// Display topic list
$order_name = $topics_mode ? 'Age' : (MOBILE_MODE ? 'Bump' : 'Last bump');
$columns = ['Headline', 'Snippet', 'Author', 'Replies', 'Visits', $order_name . ' ▼'];
if (MOBILE_MODE) unset($columns[4]);
if (!$_SESSION['settings']['celebrity_mode']) unset($columns[2]);
if (!$_SESSION['settings']['spoiler_mode']) unset($columns[1]);

$table = new Table($columns, 0);
$table->add_td_class(0, 'topic_headline');
$table->add_td_class(1, 'snippet');

$order_by = $topics_mode ? 'id' : 'last_post';
$query = $db->select('t.id, t.time, t.replies, t.visits, t.headline, t.body, t.last_post, t.locked, t.sticky, t.poll, t.namefag, t.tripfag')
    ->from('topics t')
    ->where('t.deleted = :deleted')
    ->order_by("t.sticky DESC, t.$order_by DESC")
    ->limit($page->offset, $page->limit)
    ->prepare(['deleted' => 0]);

if ($notifications['citations']) {
    $query->select('citations.topic AS citation')
          ->distinct()
          ->join('citations', '(citations.uid = :uid AND citations.topic = t.id)')
          ->prepare(['uid' => $_SESSION['UID']]);
}

$stmt = $query->exec();
$new_items = false;

while ($topic = $stmt->fetchObject()) {
    if (is_ignored($topic->headline, $topic->body, $topic->namefag, $topic->tripfag)) {
        $table->row_count++;
        continue;
    }

    $row_class = '';
    $order_time = $topics_mode ? $topic->time : $topic->last_post;
    $headline = htmlspecialchars($topic->headline, ENT_QUOTES, 'UTF-8');

    if (!empty($topic->citation)) {
        $headline = '<em class="help" title="New reply to your reply inside!">' . $headline . '</em>';
    }

    $snippet = parser::snippet($topic->body);
    if (!$_SESSION['settings']['spoiler_mode'] && !MOBILE_MODE) {
        $headline = '<span title="' . htmlspecialchars($snippet, ENT_QUOTES, 'UTF-8') . '">' . $headline . '</span>';
    }

    $values = [
        format_headline($headline, $topic->id, $topic->replies, $topic->poll, $topic->locked, $topic->sticky),
        $snippet,
        format_name($topic->namefag, $topic->tripfag, null, null, true),
        replies($topic->id, $topic->replies),
        format_number($topic->visits),
        '<span class="help" title="' . format_date($order_time) . '">' . age($order_time) . '</span>',
    ];

    if (MOBILE_MODE) unset($values[4]);
    if (!$_SESSION['settings']['celebrity_mode']) unset($values[2]);
    if (!$_SESSION['settings']['spoiler_mode']) unset($values[1]);

    if ($order_time > $last_seen) {
        $new_items = true;
    } elseif ($new_items) {
        $row_class = 'last_seen_marker';
        $new_items = false;
    }

    $table->row($values, $row_class);
}

$table->output('(No one has created a topic yet.)');

$navigation_path = $topics_mode ? 'topics' : 'bumps';
$page->navigation($navigation_path, $table->row_count);
$template->render();