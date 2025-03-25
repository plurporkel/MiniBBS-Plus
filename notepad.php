<?php
require './includes/bootstrap.php';
force_id();

$template->title = 'Notepad';
update_activity('notepad', 1);
$template->onload = "focusId('notepad_list'); init();";

$notepad_content = '';
if (isset($_POST['form_sent'])) {
    check_token();
    $notepad_content = trim($_POST['notepad_list'] ?? '');
    check_length($notepad_content, 'notepad list', 0, 4000);
    
    if (error::valid()) {
        $db->q(
            'INSERT INTO notepad (uid, notepad_content) VALUES (?, ?) ON DUPLICATE KEY UPDATE notepad_content = ?',
            $_SESSION['UID'] ?? '', $notepad_content, $notepad_content
        );
        $_SESSION['notice'] = 'Notepad updated.';
        $notepad_content = ''; // Clear after successful save
    }
} else {
    $fetch_notepad_list = $db->q('SELECT notepad_content FROM notepad WHERE uid = ?', $_SESSION['UID'] ?? '');
    $notepad_content = $fetch_notepad_list->fetchColumn() ?: '';
}

error::output();
?>

<p>This is your notepad, use it to keep notes, save drafts, to-do lists, etc.</p>
<form action="" method="post">
    <?php csrf_token() ?>
    <div>
        <textarea id="notepad_list" name="notepad_list" cols="80" rows="10"><?= htmlspecialchars($notepad_content) ?></textarea>
    </div>
    <div class="row">
        <input type="submit" name="form_sent" value="Update">
    </div>
</form>

<?php
$template->render();