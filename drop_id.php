<?php
declare(strict_types=1);

require './includes/bootstrap.php';
$template->title = 'Drop ID';

if (isset($_POST['drop_ID']) && check_token()) {
    // Destroy the session completely
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            $_SERVER['REQUEST_TIME'] - 3600,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    session_destroy();

    // Remove UID and password cookies
    setcookie('UID', '', $_SERVER['REQUEST_TIME'] - 3600, '/');
    setcookie('password', '', $_SERVER['REQUEST_TIME'] - 3600, '/');

    redirect('Your ID has been dropped.', '');
}
?>

<p><em>Dropping</em> your ID will simply remove the UID, password, and mode cookies from your browser, effectively logging you out. If you want to keep your post history, settings, etc., <a href="<?php echo DIR; ?>back_up_ID">back up your ID</a> and/or <a href="<?php echo DIR; ?>dashboard">set a memorable password</a> before doing this.</p>

<form action="" method="post">
    <?php csrf_token() ?>
    <input type="submit" name="drop_ID" value="Drop my ID" />
</form>

<?php
$template->render();
?>