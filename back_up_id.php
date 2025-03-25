<?php
declare(strict_types=1);

require './includes/bootstrap.php';
update_activity('back_up_id');
force_id();
$template->title = 'Back up ID';

if (($_GET['action'] ?? null) === 'generate_id_card') {
    $site_title = htmlspecialchars(SITE_TITLE);
    header('Content-Type: text/plain');
    header("Content-Disposition: attachment; filename=\"{$site_title}_ID.crd\"");
    echo $_SESSION['UID'] . "\n" . $_COOKIE['password'];
    exit;
} else {
    $uid = htmlspecialchars($_SESSION['UID']);
    $password = htmlspecialchars($_COOKIE['password']);
    ?>
    <table>
        <tr>
            <th class="minimal">Your unique ID</th>
            <td><code><?php echo $uid; ?></code></td>
        </tr>
        <tr>
            <th class="minimal">Your password</th>
            <td><code class="spoiler"><?php echo $password; ?></code></td>
        </tr>
    </table>
    <p>You may want to <a href="<?php echo DIR; ?>generate_ID_card">download your ID card as a file</a>.</p>
    <?php
}
$template->render();
?>