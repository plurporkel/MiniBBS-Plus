<?php
define('MINIMAL_BOOTSTRAP', true);
require './includes/bootstrap.php';

// Safely retrieve the custom style ID, defaulting to 0 if not set
$custom_style_id = $_SESSION['settings']['custom_style'] ?? 0;
if (!$custom_style_id) {
    exit();
}

// Fetch the style from the database
$res = $db->q('SELECT style AS css, modified FROM user_styles WHERE id = ?', $custom_style_id);
$style = $res->fetchObject();

// Check if the style exists
if (!$style) {
    header('HTTP/1.1 404 Not Found');
    exit('Custom style not found.');
}

// Set caching headers
header('Pragma:');
header('Expires:');
header('Cache-Control: private, max-age=43200, pre-check=43200');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $style->modified) . ' GMT');
header('Content-type: text/css');

// Output CSS with stricter escaping
echo htmlspecialchars($style->css, ENT_QUOTES, 'UTF-8');

// Render minimal template (assumed to output nothing)
$template->render(false);
?>