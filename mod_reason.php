<?php
require './includes/bootstrap.php';

$template->title = 'Edit mod reason';
$template->onload = "focusId('mod_reason');";

// Validate ID from GET
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    error::fatal('No valid ID specified.');
}

$res = $db->q('SELECT reason, action, mod_uid FROM mod_actions WHERE id = ?', $id);
$log = $res->fetchObject();

if (!$log || $log->mod_uid !== ($_SESSION['UID'] ?? '')) {
    error::fatal('You can only edit your own actions.');
}

if (isset($_POST['reason'])) {
    check_token();
    
    $reason = trim($_POST['reason'] ?? '');
    if (strlen($reason) > 260) {
        error::add('Your reason must be under 260 characters.');
    }
    
    if (error::valid()) {
        $db->q('UPDATE mod_actions SET reason = ? WHERE id = ?', $reason, $id);
        redirect('Reason updated.', 'mod_log');
    }
}

error::output();
?>

<p>You're editing an action of type "<kbd><?= htmlspecialchars($log->action) ?></kbd>".</p>

<form action="" method="post">
    <?php csrf_token() ?>
    <input type="text" name="reason" id="mod_reason" size="50" maxlength="260" value="<?= htmlspecialchars($log->reason ?? '') ?>">
    <input type="submit" value="Edit reason">
</form>

<?php
$template->render();