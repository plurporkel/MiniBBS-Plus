<?php
/**
 * This file is included from class.template.php's load() function.
 * $this references variables and functions within that class.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(strip_tags($this->title), ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars(SITE_TITLE, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(DIR, ENT_QUOTES, 'UTF-8'); ?>favicon.png">

    <!-- Core Styles -->
    <link rel="stylesheet" type="text/css" media="screen" href="<?php echo htmlspecialchars(DIR, ENT_QUOTES, 'UTF-8'); ?>style/main.css?17">
    <link rel="stylesheet" type="text/css" media="screen" href="<?php echo htmlspecialchars(DIR . 'style/themes/' . $this->get_stylesheet() . '.css?10', ENT_QUOTES, 'UTF-8'); ?>">

    <!-- Custom Style Override -->
    <?php if (!empty($_SESSION['settings']['custom_style']) && $this->style_override === false): ?>
        <link rel="stylesheet" type="text/css" media="screen" href="<?php echo htmlspecialchars(DIR . 'custom_style/' . $_SESSION['settings']['custom_style'] . '/' . (int)$_SESSION['style_last_modified'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>

    <!-- Mobile Specific -->
    <?php if (MOBILE_MODE): ?>
        <link rel="stylesheet" type="text/css" media="screen" href="<?php echo htmlspecialchars(DIR . 'style/mobile.css?3', ENT_QUOTES, 'UTF-8'); ?>">
    <?php elseif (FANCY_IMAGE): ?>
        <link rel="stylesheet" type="text/css" media="screen" href="<?php echo htmlspecialchars(DIR . 'style/thickbox.css', ENT_QUOTES, 'UTF-8'); ?>">
        <script type="text/javascript" src="<?php echo htmlspecialchars(DIR . 'javascript/thickbox.js', ENT_QUOTES, 'UTF-8'); ?>"></script>
        <script type="text/javascript">var tb_pathToImage = "<?php echo htmlspecialchars(URL . 'javascript/img/loading.gif', ENT_QUOTES, 'UTF-8'); ?>";</script>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script type="text/javascript" src="<?php echo htmlspecialchars(DIR . 'javascript/main.js?14', ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php echo $this->head; ?>
</head>

<body<?php echo !empty($this->onload) ? ' onload="' . htmlspecialchars($this->onload, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>>
    <span id="top" aria-hidden="true"></span>

    <!-- Notice -->
    <?php if (!empty($_SESSION['notice'])): ?>
        <div id="notice" ondblclick="this.remove();" role="alert">
            <?php echo htmlspecialchars(m('Notice label'), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($_SESSION['notice'], ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php unset($_SESSION['notice']); ?>
    <?php endif; ?>

    <!-- Header -->
    <header id="header">
        <h1 id="logo"><a rel="index" href="<?php echo htmlspecialchars(DIR, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(SITE_TITLE, ENT_QUOTES, 'UTF-8'); ?></a></h1>

        <form action="<?php echo htmlspecialchars(DIR . 'search', ENT_QUOTES, 'UTF-8'); ?>" method="post" class="search-form">
            <input id="search_phrase" name="phrase" type="search" size="24" maxlength="255" class="inline" required>
            <input type="submit" value="Search" name="deep_search" class="inline">
        </form>

        <nav id="main_menu" class="menu" aria-label="Main navigation">
            <ul>
            <?php foreach ($this->get_user_menu() as $linked_text => $path): ?>
                <li id="menu_<?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>">
                    <a href="<?php echo htmlspecialchars((strpos($path, 'http') === 0 ? '' : DIR) . $path, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php 
                        echo htmlspecialchars($this->mark_new($linked_text, $path), ENT_QUOTES, 'UTF-8');
                        if (isset($this->menu_children[$linked_text])) echo '<span class="dropdown" aria-hidden="true">▼</span>';
                        ?>
                    </a>
                    <?php if (isset($this->menu_children[$linked_text])): ?>
                        <ul class="submenu">
                        <?php foreach ($this->menu_children[$linked_text] as $child_text => $child_path): ?>
                            <li>
                                <a href="<?php echo htmlspecialchars((strpos($child_path, 'http') === 0 ? '' : DIR) . $child_path, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars($this->mark_new($child_text, $child_path), ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        </nav>
    </header>

    <!-- Main Content -->
    <main>
        <h2><?php echo htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8'); ?></h2>
        <?php echo $this->content; ?>
    </main>

    <!-- Footer -->
    <?php $stats = $this->get_stats(); ?>
    <footer id="footer" class="unimportant">
        <?php echo htmlspecialchars(m('Footer', $stats['total_time'], $stats['query_percent'], $stats['query_count']), ENT_QUOTES, 'UTF-8'); ?>
    </footer>

    <!-- Quick Action Form -->
    <form id="quick_action" action="" method="post" class="noscreen" aria-hidden="true">
        <?php csrf_token(); ?>
        <input type="hidden" name="confirm" value="1">
    </form>

    <span id="bottom" aria-hidden="true"></span>
</body>
</html>