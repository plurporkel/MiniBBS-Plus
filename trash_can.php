<?php
require './includes/bootstrap.php';
force_id();
$template->title = 'Your trash can';
update_activity('trash_can', 1);

if (isset($_POST['empty_trash'])) {
    if (!check_token()) {
        error::fatal(m('Error: Invalid token'));
    }
    $db->q('DELETE FROM trash WHERE uid = ?', $_SESSION['UID']);
    $_SESSION['notice'] = 'Trash emptied.';
}

echo '<p>Your deleted topics and replies are archived here.</p>';

$fetch_trash = $db->q(
    "(SELECT id, '' AS parent_id, headline, body, time FROM topics WHERE author = ? AND deleted = '1') 
    UNION
    (SELECT id, parent_id, '' AS headline, body, time FROM replies WHERE author = ? AND deleted = '1') 
    ORDER BY time DESC", 
    $_SESSION['UID'], $_SESSION['UID']
);

$columns = [
    'Headline',
    'Body',
    'Age ▼'
];
$table = new Table($columns, 1);

while ($trash = $fetch_trash->fetchObject()) {
    if (empty($trash->headline)) {
        $trash->headline = '<span class="unimportant"><a href="' . htmlspecialchars(DIR . 'topic/' . (int)$trash->parent_id . '#reply_' . (int)$trash->id, ENT_QUOTES, 'UTF-8') . '">(Reply.)</a></span>';
    } else {
        $trash->headline = '<a href="' . htmlspecialchars(DIR . 'topic/' . (int)$trash->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($trash->headline, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    $values = [
        $trash->headline,
        parser::snippet($trash->body),
        '<span class="help" title="' . htmlspecialchars(format_date($trash->time), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(age($trash->time), ENT_QUOTES, 'UTF-8') . '</span>'
    ];

    $table->row($values);
}

$table->output();
?>

<form action="" method="post">
    <?php csrf_token(); ?>
    <input type="submit" name="empty_trash" value="Empty trash" onclick="return confirm('Really empty your trash can?');" />
</form>

<?php
$template->render();
?>