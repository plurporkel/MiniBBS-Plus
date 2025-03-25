<?php
require './includes/bootstrap.php';
$template->title = 'Message manager';

if (!$perm->get('manage_messages')) {
    error::fatal(m('Error: Access denied'));
}

$messages = $lang->get_default_messages();
?>

<p>From this page, you can edit the text of MiniBBS's interface.</p>

<?php
// Print custom messages
$columns = ['Key', 'Message'];
$custom_table = new Table($columns, 1);
$custom_table->add_td_class(0, 'topic_headline');

$res = $db->q('SELECT `key`, `message` AS text FROM messages');
while ($message = $res->fetchObject()) {
    // Unset default message if overridden
    unset($messages[$message->key]);

    $values = [
        '<a href="' . htmlspecialchars(DIR) . 'edit_message/' . urlencode($message->key) . '">' . htmlspecialchars($message->key) . '</a>',
        htmlspecialchars($message->text)
    ];
    $custom_table->row($values);
}

if ($custom_table->row_count) {
    echo '<h4 class="section">Custom messages</h4>';
    $custom_table->output();
}

// Print default messages
$default_table = new Table($columns, 1);
$default_table->add_td_class(0, 'topic_headline');

foreach ($messages as $key => $text) {
    $values = [
        '<a href="' . htmlspecialchars(DIR) . 'edit_message/' . urlencode($key) . '">' . htmlspecialchars($key) . '</a>',
        htmlspecialchars($text)
    ];
    $default_table->row($values);
}

if ($default_table->row_count) {
    echo '<h4 class="section">Default messages</h4>';
    $default_table->output();
}

$template->render();