<?php
require './includes/bootstrap.php';
$template->title = 'Recover ID by e-mail';
$template->onload = 'focusId(\'e-mail\');';

// Initialize session variable to avoid undefined index errors
$_SESSION['recovery_email_count'] = $_SESSION['recovery_email_count'] ?? 0;

// Handle form submission
$email = filter_input(INPUT_POST, 'e-mail', FILTER_VALIDATE_EMAIL);
if ($email !== false) {
    // Check recovery attempt limit
    if ($_SESSION['recovery_email_count'] > 3) {
        error::add('How many times do you need to recover your password in one day?');
    } else {
        // Fetch user IDs associated with the email
        $res = $db->q('SELECT uid FROM user_settings WHERE email = ?', $email);
        $uids = $res->fetchAll(PDO::FETCH_COLUMN);

        if (empty($uids)) {
            error::add('There are no IDs associated with that e-mail.');
        } else {
            // Generate recovery tokens for each UID
            $recovery_tokens = [];
            foreach ($uids as $uid) {
                $token = bin2hex(random_bytes(16)); // Secure random token
                $db->q('INSERT INTO recovery_tokens (uid, token, expiry) VALUES (?, ?, ?)', $uid, $token, time() + 3600);
                $recovery_tokens[$uid] = $token;
            }

            // Build email body with recovery links
            $email_body = "To recover your ID, use the following links:\n\n";
            foreach ($recovery_tokens as $uid => $token) {
                $email_body .= "ID: $uid\nRecovery link: " . DIR . "restore_ID/$uid/$token\n\n";
            }

            // Send the email (basic mail() for now; PHPMailer recommended)
            $headers = 'From: ' . SITE_TITLE . ' <' . MAILER_ADDRESS . '>';
            if (mail($email, SITE_TITLE . ' ID recovery', $email_body, $headers)) {
                $_SESSION['recovery_email_count']++;
                redirect('ID recovery e-mail sent.', '');
            } else {
                error::add('Failed to send recovery email.');
            }
        }
    }
}

// Output any errors
error::output();
?>

<p>If your ID has an e-mail address associated with it (as set in the <a href="<?php echo DIR; ?>dashboard">dashboard</a>), this tool can be used to recover it. You will be sent a recovery link for every ID associated with your e-mail address.</p>

<form action="" method="post">
    <div class="row">
        <label for="e-mail">Your e-mail address</label>
        <input type="email" id="e-mail" name="e-mail" required autofocus />
    </div>
    <div class="row">
        <input type="submit" value="Send recovery e-mail" />
    </div>
</form>

<?php
$template->render();
?>