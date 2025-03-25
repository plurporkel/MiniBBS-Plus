<?php
declare(strict_types=1);

define('SITE_ROOT', realpath(__DIR__));
require SITE_ROOT . '/includes/functions.php';
spl_autoload_register('load_class');

// Initialize session
session_start([
    'name' => 'SID',
    'cookie_lifetime' => 315569260, // ~10 years
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

// Set execution time limit
set_time_limit(0);

// Check write permissions
$unwritable_dirs = array_filter(
    ['img', 'thumbs', 'cache', 'config'],
    fn($dir) => !is_writable(SITE_ROOT . '/' . $dir)
);

if (!empty($unwritable_dirs)) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Permission Error</title>
    <style>
        body { padding: 3% 4%; background-color: #FFBCCD; color: #000; font-family: Arial; }
        #wrapper { max-width: 1000px; padding: 0.5% 2em; margin: auto; background-color: #fff; border-radius: 1em; }
        h1 { text-align: center; font-family: Georgia; }
        li { color: #B75461; }
    </style>
</head>
<body>
    <div id="wrapper">
        <h1>Permission Required</h1>
        <p>To install MiniBBS, PHP must have write-access to the following directories:</p>
        <ul>
HTML;
    foreach ($unwritable_dirs as $dir) {
        echo '<li>/' . htmlspecialchars($dir, ENT_QUOTES, 'UTF-8') . '/</li>';
    }
    echo <<<HTML
        </ul>
        <p>Before installation can continue, set each directory to 755 (or 775 with proper group ownership) using your FTP client or the <kbd>chmod</kbd> command.</p>
    </div>
</body>
</html>
HTML;
    exit;
}

// Database schema (updated to InnoDB and utf8mb4)
$tables = [
    'activity' => "CREATE TABLE IF NOT EXISTS `activity` (
        `uid` CHAR(23) NOT NULL,
        `time` INT UNSIGNED NOT NULL,
        `action_name` VARCHAR(60) NOT NULL,
        `action_id` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`uid`),
        INDEX `time` (`time`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    // Add other tables similarly, updating ENGINE and CHARSET
    // Example for 'users':
    'users' => "CREATE TABLE IF NOT EXISTS `users` (
        `uid` CHAR(23) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `first_seen` INT UNSIGNED NOT NULL,
        `last_seen` INT UNSIGNED NOT NULL,
        `topic_visits` TEXT NOT NULL,
        `ip_address` VARCHAR(45) NOT NULL,
        `namefag` TEXT NOT NULL,
        `post_count` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`uid`),
        INDEX `ip_address` (`ip_address`, `first_seen`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
    // Add remaining tables...
];

// Pre-installation checks
if (file_exists(SITE_ROOT . '/config/config.php')) {
    die('MiniBBS is already installed (config.php exists).');
}
if (!file_exists(SITE_ROOT . '/config/config_preview.php')) {
    die('Missing /config/config_preview.php.');
}
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    die('MiniBBS requires PHP 8.2 or greater; you are running ' . PHP_VERSION . '.');
}
if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
    die('PDO and PDO MySQL extensions are required.');
}

// Default form inputs
$input = [
    'db_username' => '',
    'db_password' => '',
    'db_server' => 'localhost',
    'db_name' => '',
    'hostname' => filter_var(getenv('HTTP_HOST'), FILTER_SANITIZE_URL),
    'directory' => rtrim(str_replace('//', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/',
    'board_name' => '',
    'log_name' => '',
    'captcha_public' => '',
    'captcha_private' => '',
];

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_sent'])) {
    $form = array_map('trim', $_POST['form'] ?? []);
    if (!isset($form['csrf_token']) || $form['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Invalid CSRF token.');
    }

    $input = array_merge($input, $form);
    $input['hostname'] = rtrim($input['hostname'], '/');

    // Prepare config.php
    $config_template = file_get_contents(SITE_ROOT . '/config/config_preview.php');
    $hard_config = [
        '%%DB_USERNAME%%' => $input['db_username'],
        '%%DB_PASSWORD%%' => $input['db_password'],
        '%%DB_SERVER%%' => $input['db_server'],
        '%%DB_NAME%%' => $input['db_name'],
        '%%HOSTNAME%%' => $input['hostname'],
        '%%DIRECTORY%%' => $input['directory'],
        '%%FOUNDED%%' => time(),
    ];

    foreach ($hard_config as $find => $replace) {
        $config_template = str_replace($find, addcslashes($replace, "'"), $config_template);
    }

    // Database setup
    try {
        $pdo = new PDO(
            "mysql:host={$input['db_server']};dbname={$input['db_name']};charset=utf8mb4",
            $input['db_username'],
            $input['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        foreach ($tables as $table => $query) {
            $pdo->exec($query);
        }

        $user_id = bin2hex(random_bytes(12)); // Stronger UID
        $password = password_hash(generate_password(), PASSWORD_ARGON2ID); // Modern hashing

        // Insert admin user (example with prepared statement)
        $stmt = $pdo->prepare(
            "INSERT INTO `users` (`uid`, `password`, `first_seen`, `last_seen`, `topic_visits`, `ip_address`, `namefag`) 
            VALUES (:uid, :password, :time, :time, '', :ip, '')"
        );
        $stmt->execute([
            'uid' => $user_id,
            'password' => $password,
            'time' => time(),
            'ip' => $_SERVER['REMOTE_ADDR'],
        ]);

        // Insert groups, config, etc. (similarly updated with PDO)

        if (file_put_contents(SITE_ROOT . '/config/config.php', $config_template)) {
            header("Location: http://{$input['hostname']}{$input['directory']}restore_ID/{$user_id}/{$password}");
            exit;
        } else {
            throw new Exception('Unable to create config.php.');
        }
    } catch (Exception $e) {
        $error = 'Installation failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MiniBBS Installation</title>
    <style>
        body { padding: 3% 4%; background-color: #E0EBF9; color: #000; font-family: Arial; }
        #wrapper { max-width: 1000px; padding: 0.5% 2em; margin: auto; background-color: #fff; }
        h1 { text-align: center; font-family: Georgia; }
        legend { font-weight: bold; }
        label { font-style: italic; float: left; padding-right: 0.6em; text-align: right; width: 11em; }
        input[type="text"] { padding: 0.3em; border: 1px solid #9FCECE; }
        input:focus { background-color: #F7FCFF; }
        p.caption { margin-top: 0.1em; margin-left: 11.5em; }
        div.row { margin-bottom: 1em; }
        #error { background-color: #FFD8D8; padding: 0.3em; color: #990000; }
    </style>
</head>
<body>
    <div id="wrapper">
        <h1>MiniBBS Installation</h1>
        <p>Welcome to MiniBBS! If you have any issues, contact <a href="http://minibbs.org/">the developers</a>.</p>

        <?php if (isset($error)): ?>
            <div id="error"><?= $error ?></div>
        <?php endif; ?>

        <form action="" method="post">
            <input type="hidden" name="form[csrf_token]" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <fieldset>
                <legend>Database</legend>
                <!-- Form fields remain similar, just updated with htmlspecialchars -->
                <div class="row">
                    <label for="db_username">Database username</label>
                    <input type="text" id="db_username" name="form[db_username]" value="<?= htmlspecialchars($input['db_username']) ?>">
                    <p class="caption">The username provided by your host.</p>
                </div>
                <!-- Add other fields similarly -->
            </fieldset>
            <!-- Other fieldsets (URL, Basic Settings) follow the same pattern -->
            <input type="submit" name="form_sent" value="Install">
        </form>
    </div>
</body>
</html>