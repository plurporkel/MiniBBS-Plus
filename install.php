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

        // Create tables
        foreach ($tables as $table => $query) {
            $pdo->exec($query);
        }

        $user_id = bin2hex(random_bytes(12)); // Stronger UID
        $password = password_hash(generate_password(), PASSWORD_ARGON2ID); // Modern hashing

        // Insert admin user
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

        // Create basic user groups
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO `groups` 
            (`id`, `name`, `link`, `edit_limit`, `post_reply`, `post_topic`, `post_image`, `post_link`, 
            `pm_users`, `pm_mods`, `read_mod_pms`, `read_admin_pms`, `report`, `handle_reports`, 
            `delete`, `undelete`, `edit`, `edit_others`, `view_profile`, `ban`, `stick`, `lock`, 
            `delete_ip_ids`, `nuke_id`, `nuke_ip`, `exterminate`, `cms`, `bulletin`, `defcon`, 
            `defcon_all`, `delete_all_pms`, `admin_dashboard`, `manage_permissions`, `merge`, 
            `limit_ip`, `limit_ip_max`, `manage_messages`, `hide_log`) VALUES
            (1, 'user', '', 600, 1, 1, 1, 1, 1, 1, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0),
            (2, 'mod', 'mod', 0, 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 1, 1, 0, 1, 1, 1, 0, 1, 0, 0, 1, 1, 35, 0, 1),
            (3, 'admin', 'admin', 0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0, 0, 1, 1)"
        );
        $stmt->execute();

        // Set admin privs
        $stmt = $pdo->prepare(
            "INSERT INTO `group_users` (`uid`, `group_id`, `log_name`) VALUES (:uid, 3, :log_name)"
        );
        $stmt->execute([
            'uid' => $user_id,
            'log_name' => $input['log_name']
        ]);

        // Insert flood control settings
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO `flood_control` (`setting`, `value`) VALUES ('defcon', '5'), ('search_disabled', '0')"
        );
        $stmt->execute();

        // Insert last actions
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO `last_actions` (`feature`, `time`) VALUES ('last_bump', :time), ('last_topic', :time)"
        );
        $stmt->execute(['time' => time()]);

        // Insert markup page
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO `pages` (`id`, `url`, `page_title`, `content`, `markup`) VALUES
            (1, 'markup_syntax', 'Markup syntax', :content, 0)"
        );
        $stmt->execute(['content' => file_get_contents(SITE_ROOT . '/config/markup_syntax.txt')]);

        // Load and insert default config
        require SITE_ROOT . '/config/default_config.php';
        
        // Update config with user settings
        $config_defaults['SITE_TITLE'] = $input['board_name'];
        $config_defaults['RECAPTCHA_PUBLIC_KEY'] = $input['captcha_public'];
        $config_defaults['RECAPTCHA_PRIVATE_KEY'] = $input['captcha_private'];
        $config_defaults['SALT'] = bin2hex(random_bytes(32));
        $config_defaults['TRIP_SEED'] = bin2hex(random_bytes(32));

        // Insert config values
        $stmt = $pdo->prepare("INSERT IGNORE INTO `config` (`name`, `value`) VALUES (:name, :value)");
        foreach ($config_defaults as $key => $value) {
            $stmt->execute(['name' => $key, 'value' => $value]);
        }

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
                <div class="row">
                    <label for="db_username">Database username</label>
                    <input type="text" id="db_username" name="form[db_username]" value="<?= htmlspecialchars($input['db_username']) ?>">
                    <p class="caption">The username provided by your host to connect to your database.</p>
                </div>
                
                <div class="row">
                    <label for="db_password">Database password</label>
                    <input type="text" id="db_password" name="form[db_password]" value="<?= htmlspecialchars($input['db_password']) ?>">
                    <p class="caption">The password provided by your host to connect to your database.</p>
                </div>
                
                <div class="row">
                    <label for="db_server">Database server</label>
                    <input type="text" id="db_server" name="form[db_server]" value="<?= htmlspecialchars($input['db_server']) ?>">
                    <p class="caption">The hostname of your database server; often "localhost", but not always.</p>
                </div>
                
                <div class="row">
                    <label for="db_name">Database name</label>
                    <input type="text" id="db_name" name="form[db_name]" value="<?= htmlspecialchars($input['db_name']) ?>">
                    <p class="caption">The name of the database you created for MiniBBS.</p>
                </div>
            </fieldset>
            
            <fieldset>
                <legend>URL</legend>
                <div class="row">
                    <label for="hostname">Hostname</label>
                    <input type="text" id="hostname" name="form[hostname]" value="<?= htmlspecialchars($input['hostname']) ?>">
                    <p class="caption">The hostname (domain name with subdomain) of your new board, <em>not</em> including the directory or any slashes.</p>
                </div>
                
                <div class="row">
                    <label for="directory">Directory</label>
                    <input type="text" id="directory" name="form[directory]" value="<?= htmlspecialchars($input['directory']) ?>">
                    <p class="caption">The directory in which your board will reside, <em>including</em> the opening and (if applicable) closing slash.</p>
                </div>
            </fieldset>
            
            <fieldset>
                <legend>Basic settings</legend>
                <p>You can reconfigure these options later from the admin dashboard.</p>
                
                <div class="row">
                    <label for="board_name">Board name</label>
                    <input type="text" id="board_name" name="form[board_name]" value="<?= htmlspecialchars($input['board_name']) ?>">
                    <p class="caption">The name of your board/site.</p>
                </div>
                
                <div class="row">
                    <label for="log_name">Your screenname</label>
                    <input type="text" id="log_name" name="form[log_name]" value="<?= htmlspecialchars($input['log_name']) ?>">
                    <p class="caption">Your personal screenname. This will appear in the mod logs for actions by your account.</p>
                </div>
                
                <div class="row">
                    <label for="captcha_public">reCAPTCHA public key</label>
                    <input type="text" id="captcha_public" name="form[captcha_public]" value="<?= htmlspecialchars($input['captcha_public']) ?>" size="35">
                </div>
                
                <div class="row">
                    <label for="captcha_private">reCAPTCHA private key</label>
                    <input type="text" id="captcha_private" name="form[captcha_private]" value="<?= htmlspecialchars($input['captcha_private']) ?>" size="35">
                    <p class="caption">In order for MiniBBS to properly deal with bots, you'll need to <a href="https://www.google.com/recaptcha/admin/create">generate these keys</a> using Google's free reCAPTCHA service.</p>
                </div>
            </fieldset>
            
            <p>That's it. Once installed, you can further configure your board from the admin dashboard linked on the "Stuff" page. Remember not to clear your cookies before setting a memorable name and password; you'll be logged in as an admin immediately. You should also delete this file (install.php) after installation.</p>
            
            <input type="submit" name="form_sent" value="Install">
        </form>
    </div>
</body>
</html>