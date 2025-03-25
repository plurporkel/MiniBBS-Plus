<?php
declare(strict_types=1);

require './includes/bootstrap.php';

http_response_code(403);
$template->title = '403 Forbidden';
?>

<div class="error-page">
    <h1>403 Forbidden</h1>
    <p>Sorry, you don't have permission to access this resource.</p>
    
    <div class="error-actions">
        <ul>
            <li><a href="<?php echo DIR; ?>">Return to Homepage</a></li>
            <?php if (empty($_SESSION['ID_activated'])): ?>
                <li><a href="<?php echo DIR; ?>login">Login</a></li>
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