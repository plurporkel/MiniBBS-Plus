<?php
// Display notice if it exists in session
if (!empty($_SESSION['notice'])) {
    // Using htmlspecialchars to prevent XSS
    $notice = htmlspecialchars($_SESSION['notice'], ENT_QUOTES, 'UTF-8');
    echo "<script>showNotice('{$notice}');</script>";
    unset($_SESSION['notice']);
}
?>

<!-- Main content section -->
<div class="ajax-content">
    <h2><?php echo htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php echo $this->content; ?>
</div>

<!-- Quick action form -->
<form id="quick_action" action="" method="post" class="noscreen" aria-hidden="true">
    <?php csrf_token(); ?>
    <input type="hidden" name="confirm" value="1">
</form>