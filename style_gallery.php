<?php
require './includes/bootstrap.php';

$page = new Paginate();

$db->select('id, title, name, trip, color, modified, basis, uid, public')
   ->from('user_styles')
   ->order_by('modified DESC')
   ->limit($page->offset, $page->limit);

if (!isset($_GET['mine'])) {
    $db->where('public = 1');
    $template->title = 'Custom theme gallery';
} else {
    $db->where('uid = ?', $_SESSION['UID'] ?? '');
    $template->title = 'My themes';
}

$res = $db->exec();

if ($page->current > 1) {
    $template->title .= ', page #' . number_format($page->current);
}
?>

<ul class="menu">
    <li><a href="<?php echo htmlspecialchars(DIR . 'new_style', ENT_QUOTES, 'UTF-8'); ?>">New theme</a></li>
    <?php if (isset($_GET['mine'])): ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'theme_gallery', ENT_QUOTES, 'UTF-8'); ?>">All themes</a></li>
    <?php else: ?>
        <li><a href="<?php echo htmlspecialchars(DIR . 'theme_gallery/you', ENT_QUOTES, 'UTF-8'); ?>">My themes</a></li>
    <?php endif; ?>
</ul>

<?php
$columns = [
    '',
    'Title',
    'Basis',
    'Author',
    'Modified ▼'
];
$table = new Table($columns, 1);

while ($style = $res->fetchObject()) {
    $values = [
        '<div style="height: 1em; width: 1em; border: 1px solid #aaa; background-color:' . htmlspecialchars($style->color, ENT_QUOTES, 'UTF-8') . '" class="theme_color"> </div>',
        '<strong><a href="' . htmlspecialchars(DIR . 'view_style/' . $style->id, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($style->title, ENT_QUOTES, 'UTF-8') . '</a></strong>',
        empty($style->basis) ? '-' : htmlspecialchars($style->basis, ENT_QUOTES, 'UTF-8'),
        format_name($style->name, $style->trip),
        '<span class="help" title="' . htmlspecialchars(format_date($style->modified), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(age($style->modified), ENT_QUOTES, 'UTF-8') . '</span>'
    ];

    if ($style->uid == ($_SESSION['UID'] ?? '')) {
        $values[1] .= ' (' . ($style->public ? '' : 'private; ') . '<a href="' . htmlspecialchars(DIR . 'edit_style/' . $style->id, ENT_QUOTES, 'UTF-8') . '">edit</a>)';
    }

    $table->row($values);
}
$table->output(isset($_GET['mine']) ? 'You haven\'t created any themes yet.' : 'No one has submitted a public theme yet.');
$page->navigation('theme_gallery', $table->row_count);
$template->render();