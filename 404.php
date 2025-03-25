<?php
declare(strict_types=1);

require './includes/bootstrap.php';

http_response_code(404);
$template->title = '404 Not Found';

// Get the requested URL for display
$requested_url = htmlspecialchars($_SERVER['REQUEST_URI'], ENT_QUOTES, 'UTF-8');
?>

<div class="error-page">
    <h1>404 Not Found</h1>
    <p>Sorry, the page you're looking for doesn't exist.</p>
    <p class="requested-url">Requested URL: <?php echo $requested_url; ?></p>
    
    <div class="error-suggestions">
        <h2>You might want to:</h2>
        <ul>
            <li>Check the URL for typos</li>
            <li>Make sure the page hasn't been moved or deleted</li>
            <li>Check your access permissions</li>
        </ul>
    </div>

    <div class="error-actions">
        <ul>
            <li><a href="<?php echo DIR; ?>">Return to Homepage</a></li>
            <li><a href="javascript:history.back()">Go Back</a></li>
            <?php if (!empty($_SESSION['ID_activated']) && $perm->is_admin()): ?>
                <li><a href="<?php echo DIR; ?>admin">Go to Admin Panel</a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<style>
.error-page {
    text-align: center;
    padding: 2em;
    margin: 2em auto;
    max-width: 600px;
    background: var(--bg-color);
    border: 1px solid var(--border-color);
    border-radius: 4px;
}

.error-page h1 {
    color: var(--text-color);
    margin-bottom: 1em;
}

.requested-url {
    color: var(--muted-color);
    font-family: monospace;
    margin: 1em 0;
    word-break: break-all;
}

.error-suggestions {
    text-align: left;
    margin: 2em 0;
    padding: 1em;
    background: var(--alt-bg-color);
    border-radius: 4px;
}

.error-suggestions h2 {
    font-size: 1.1em;
    margin-bottom: 0.5em;
}

.error-suggestions ul {
    list-style: disc;
    padding-left: 2em;
}

.error-suggestions li {
    margin: 0.3em 0;
    color: var(--text-color);
}

.error-actions {
    margin-top: 2em;
}

.error-actions ul {
    list-style: none;
    padding: 0;
}

.error-actions li {
    margin: 0.5em 0;
}

.error-actions a {
    color: var(--link-color);
    text-decoration: none;
}

.error-actions a:hover {
    text-decoration: underline;
}
</style>

<?php
$template->render();
?> 