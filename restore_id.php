<?php
require './includes/bootstrap.php';
update_activity('restore_id');
$template->title = 'Restore ID';
$template->onload = 'focusId(\'memorable_name\')';

// Initialize variables
$uid = null;
$password = null;
$merge_uid = filter_input(INPUT_POST, 'merge_uid', FILTER_VALIDATE_BOOLEAN);

// Handle memorable name and password submission
if (!empty($_POST['memorable_name'])) {
    $memorable_name = filter_input(INPUT_POST, 'memorable_name', FILTER_SANITIZE_STRING);
    $memorable_password = $_POST['memorable_password'] ?? '';
    $password_hash = $memorable_password ? password_hash($memorable_password, PASSWORD_DEFAULT) : '';

    $res = $db->q(
        'SELECT user_settings.uid, users.password 
         FROM user_settings 
         INNER JOIN users ON user_settings.uid = users.uid 
         WHERE LOWER(user_settings.memorable_name) = LOWER(?) 
         AND user_settings.memorable_password = ?',
        $memorable_name, $password_hash
    );

    $user_data = $res->fetch();
    if ($user_data) {
        $uid = $user_data['uid'];
        $password = $user_data['password'];
    } else {
        error::add('Your memorable information was incorrect.');
    }
}

// Handle UID and password input
if (!empty($_POST['UID']) && !empty($_POST['password'])) {
    $uid = filter_input(INPUT_POST, 'UID', FILTER_SANITIZE_STRING);
    $password = $_POST['password'];
}

// Handle recovery email link
if (!empty($_GET['UID']) && !empty($_GET['password'])) {
    $uid = filter_input(INPUT_GET, 'UID', FILTER_SANITIZE_STRING);
    $password = urldecode(filter_input(INPUT_GET, 'password', FILTER_SANITIZE_STRING));
}

// Handle ID card upload
if (isset($_POST['do_upload']) && isset($_FILES['id_card'])) {
    $id_card_content = file_get_contents($_FILES['id_card']['tmp_name']);
    list($uid, $password) = explode("\n", $id_card_content, 2);
    $uid = trim($uid);
    $password = trim($password);
}

// Process ID restoration
if (!empty($uid) && !empty($password)) {
    $previous_id = $_SESSION['UID'] ?? '';
    $previous_post_count = $_SESSION['post_count'] ?? 0;

    // Try to activate with the provided password
    if (activate_id($uid, $password)) {
        load_settings();
        $notice = 'Welcome back.';

        if ($merge_uid && !$perm->uid_banned($previous_id)) {
            $db->q('UPDATE topics SET author = ? WHERE author = ?', $uid, $previous_id);
            $db->q('UPDATE replies SET author = ? WHERE author = ?', $uid, $previous_id);
            $db->q('UPDATE users SET post_count = post_count + ? WHERE uid = ?', $previous_post_count, $uid);
            $db->q('UPDATE users SET post_count = 0 WHERE uid = ?', $previous_id);
            $db->q('UPDATE private_messages SET source = ? WHERE source = ?', $uid, $previous_id);
            $db->q('UPDATE private_messages SET destination = ? WHERE destination = ?', $uid, $previous_id);
            $notice .= ' Your IDs have been merged.';
        }

        redirect($notice, '');
    } else {
        error::add('The username or password was incorrect.');
    }
}

error::output();
?>

<p>Your internal ID can be restored in a number of ways. If none of these work, you may be able to <a href="<?php echo DIR; ?>recover_ID_by_email">recover your ID by e-mail</a>.</p>
<?php if (isset($_SESSION['post_count']) && $_SESSION['post_count'] > 0): ?>
    <p>If you check the "<strong>Merge IDs</strong>" option, the post and PM history of your current ID will be merged into the restored ID.</p>
<?php endif; ?>

<fieldset>
    <legend>Input memorable name and password</legend>
    <p>Memorable information can be set from the <a href="<?php echo DIR; ?>dashboard">dashboard</a></p>
    <form action="" method="post">
        <div class="row">
            <label for="memorable_name">Memorable name</label>
            <input type="text" id="memorable_name" name="memorable_name" maxlength="100" autofocus required />
        </div>
        <div class="row">
            <label for="memorable_password">Memorable password</label>
            <input type="password" id="memorable_password" name="memorable_password" required />
        </div>
        <div class="row">
            <input type="submit" value="Restore" class="inline" />
            <?php if (isset($_SESSION['post_count']) && $_SESSION['post_count'] > 0): ?>
                <input type="checkbox" name="merge_uid" id="merge_uid" value="1" class="inline" />
                <label for="merge_uid" class="inline">Merge IDs</label>
            <?php endif; ?>
        </div>
    </form>
</fieldset>

<fieldset>
    <legend>Input UID and password</legend>
    <p>Your internal ID and password are automatically set upon creation of your ID. They are available from the <a href="<?php echo DIR; ?>back_up_ID">back up</a> page.</p>
    <form action="" method="post">
        <div class="row">
            <label for="UID">Internal ID</label>
            <input type="text" id="UID" name="UID" size="23" maxlength="23" required />
        </div>
        <div class="row">
            <label for="password">Internal password</label>
            <input type="password" id="password" name="password" size="32" maxlength="32" required />
        </div>
        <div class="row">
            <input type="submit" value="Restore" class="inline" />
            <?php if (isset($_SESSION['post_count']) && $_SESSION['post_count'] > 0): ?>
                <input type="checkbox" name="merge_uid" id="merge_uid2" value="1" class="inline" />
                <label for="merge_uid2" class="inline">Merge IDs</label>
            <?php endif; ?>
        </div>
    </form>
</fieldset>

<fieldset>
    <legend>Upload ID card</legend>
    <p>If you have an <a href="<?php echo DIR; ?>generate_ID_card">ID card</a>, upload it here.</p>
    <form enctype="multipart/form-data" action="" method="post">
        <div class="row">
            <input name="id_card" type="file" accept=".txt" required />
        </div>
        <div class="row">
            <input name="do_upload" type="submit" value="Upload and restore" class="inline" />
            <?php if (isset($_SESSION['post_count']) && $_SESSION['post_count'] > 0): ?>
                <input type="checkbox" name="merge_uid" id="merge_uid3" value="1" class="inline" />
                <label for="merge_uid3" class="inline">Merge IDs</label>
            <?php endif; ?>
        </div>
    </form>
</fieldset>

<?php
$template->render();