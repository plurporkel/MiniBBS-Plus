<?php
require './includes/bootstrap.php';

// Validate and sanitize the style ID
$style_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($style_id === false || $style_id <= 0) {
    error::fatal('Invalid style ID.');
}

// Fetch style from the database
$res = $db->q('SELECT style AS css, title, public, uid, name, trip, modified, basis FROM user_styles WHERE id = ?', $style_id);
$style = $res->fetchObject();

if (!$style) {
    error::fatal('No style was found.');
}

// Check access permissions (default to empty string if UID is unset)
$user_id = $_SESSION['UID'] ?? '';
if ($style->uid != $user_id && !$style->public) {
    error::fatal('This style is private.');
}

// Set page title with escaped output
$template->title = '<a href="' . htmlspecialchars(DIR . 'theme_gallery', ENT_QUOTES, 'UTF-8') . '">Theme</a>: ' . htmlspecialchars($style->title, ENT_QUOTES, 'UTF-8');

// Handle form actions
if (isset($_POST['preview']) && check_token()) {
    $template->style_override = $style->basis;
    $template->head = '<style>' . htmlspecialchars($style->css, ENT_QUOTES, 'UTF-8') . '</style>';
} elseif (isset($_POST['edit'])) {
    header('Location: ' . URL . 'edit_style/' . $style_id);
    exit();
}

// Basic CSS highlighting
$highlighted_style = htmlspecialchars($style->css, ENT_QUOTES, 'UTF-8');
$highlighted_style = preg_replace('/(^|{|;)(.+?):/m', '$1<span style="color: #008000">$2</span>:', $highlighted_style);
$highlighted_style = preg_replace('/(^|})(.+?){/m', '$1<span style="color: #003AFF">$2</span>{', $highlighted_style);
?>

<form action="" method="post">
    <?php csrf_token(); ?>
    <input type="submit" name="preview" value="Preview" class="inline" />
    <input type="submit" name="edit" value="<?php echo ($style->uid == $user_id ? 'Edit' : 'Clone and edit'); ?>" class="inline" />
</form>

<p>This theme was last modified <strong><span class="help" title="<?php echo htmlspecialchars(format_date($style->modified), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(age($style->modified), ENT_QUOTES, 'UTF-8'); ?></span> ago</strong> by <?php echo trim(format_name($style->name, $style->trip)); ?>.</p>

<pre style="display: block; background-color: #F0F0F0; padding: 1em; color: #000; margin-top: 1em;">
<?php echo $highlighted_style; ?>
</pre>

<?php
$template->render();