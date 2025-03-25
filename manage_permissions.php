<?php
require './includes/bootstrap.php';
force_id();

if (!$perm->get('manage_permissions')) {
    error::fatal(m('Error: Access denied'));
}

// Validate UID from GET parameter
$uid = filter_input(INPUT_GET, 'uid', FILTER_SANITIZE_STRING);
if (!$uid || !id_exists($uid)) {
    error::fatal('There is no such UID.');
}

if (isset($_POST['form_sent'])) {
    check_token();
    
    $log_name = trim($_POST['log_name'] ?? '');
    $user_group = $_POST['user_group'] ?? '';
    
    if (empty($log_name)) {
        error::add('The log name cannot be empty.');
    }
    if (!ctype_digit($user_group)) {
        error::add('Invalid user group.');
    }
    
    if (error::valid()) {
        $db->q(
            'INSERT INTO group_users 
            (uid, group_id, log_name) VALUES 
            (?, ?, ?) ON DUPLICATE KEY UPDATE 
            group_id = ?, log_name = ?',
            $uid, $user_group, $log_name, $user_group, $log_name
        );
        log_mod('perm_change', $uid, $user_group);
        cache::clear('group_users');
        redirect('Permissions updated.', 'profile/' . $uid);
    }
}
error::output();

$template->title = 'Manage permissions for <a href="' . htmlspecialchars(DIR) . 'profile/' . htmlspecialchars($uid) . '">' . htmlspecialchars($uid) . '</a>';
?>

<p>A user's permission set is determined by their user group. "Log name" is the name that appears in the mod logs for this poster.</p>
<form action="" method="post">
    <?php csrf_token() ?>
    <div class="row">
        <label for="user_group" class="short">User group</label>
        <select name="user_group" id="user_group" class="inline">
            <?php
            $groups = $perm->get_groups();
            $current_group = $perm->get('name', $uid);
            foreach ($groups as $id => $settings) {
                $selected = ($settings['name'] === $current_group) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($id) . '"' . $selected . '>' . htmlspecialchars($settings['name']) . '</option>';
            }
            ?>
        </select>
    </div>
    <div class="row">
        <label for="log_name" class="short">Log name</label>
        <input name="log_name" id="log_name" type="text" 
               value="<?= htmlspecialchars($perm->get_name($uid) ?? '') ?>" class="inline">
    </div>
    <div class="row">
        <input type="submit" name="form_sent" value="Update" tabindex="4" class="short_indent">
    </div>
</form>

<?php
$template->render();