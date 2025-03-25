<?php
declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/install_error.log');

// Log the start of the script
error_log("Installation script started");

define('SITE_ROOT', realpath(__DIR__));
require SITE_ROOT . '/includes/functions.php';
spl_autoload_register('load_class');

// Initialize session with error handling
try {
    session_start([
        'name' => 'SID',
        'cookie_lifetime' => 315569260, // ~10 years
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
    error_log("Session started successfully");
} catch (Exception $e) {
    error_log("Session start failed: " . $e->getMessage());
    die("Session initialization failed. Please check your PHP configuration.");
}

// Set execution time limit
set_time_limit(0);

// Check write permissions with detailed logging
$unwritable_dirs = array_filter(
    ['img', 'thumbs', 'cache', 'config'],
    function($dir) {
        $path = SITE_ROOT . '/' . $dir;
        $writable = is_writable($path);
        error_log("Directory {$dir} writable: " . ($writable ? 'yes' : 'no'));
        return !$writable;
    }
);

if (!empty($unwritable_dirs)) {
    error_log("Unwritable directories found: " . implode(', ', $unwritable_dirs));
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

// Pre-installation checks with logging
if (file_exists(SITE_ROOT . '/config/config.php')) {
    error_log("Config file already exists");
    die('MiniBBS is already installed (config.php exists).');
}
if (!file_exists(SITE_ROOT . '/config/config_preview.php')) {
    error_log("Config preview file missing");
    die('Missing /config/config_preview.php.');
}
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    error_log("PHP version check failed: " . PHP_VERSION);
    die('MiniBBS requires PHP 8.2 or greater; you are running ' . PHP_VERSION . '.');
}
if (!extension_loaded('pdo') || !extension_loaded('pdo_mysql')) {
    error_log("PDO extensions check failed");
    die('PDO and PDO MySQL extensions are required.');
}

// Log POST data for debugging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST request received");
    error_log("POST data: " . print_r($_POST, true));
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

    // Create config template
    $config_template = <<<EOT
<?php
// Database settings
define('DB_HOST', '{$input['db_server']}');
define('DB_NAME', '{$input['db_name']}');
define('DB_USER', '{$input['db_username']}');
define('DB_PASS', '{$input['db_password']}');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

// Site settings
define('SITE_ROOT', __DIR__ . '/..');
define('SITE_URL', 'http://{$input['hostname']}{$input['directory']}');
define('SITE_ADMIN_EMAIL', '{$input['admin_email']}');
define('SITE_TIMEZONE', 'UTC');
define('SITE_LANGUAGE', 'en');

// Security settings
define('COOKIE_PATH', '/');
define('COOKIE_DOMAIN', '');
define('COOKIE_SECURE', false);
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Lax');
define('SESSION_NAME', 'MINIBBS_SESSID');

// Debug settings
define('DEBUG_MODE', false);
define('ERROR_REPORTING', E_ALL);
define('DISPLAY_ERRORS', 0);
define('LOG_ERRORS', 1);
define('ERROR_LOG', SITE_ROOT . '/logs/error.log');

// Cache settings
define('CACHE_ENABLED', true);
define('CACHE_DIR', SITE_ROOT . '/cache');
define('CACHE_TIME', 3600);

// Upload settings
define('UPLOAD_MAX_SIZE', 5242880); // 5MB
define('UPLOAD_ALLOWED_TYPES', 'jpg,jpeg,png,gif');
define('UPLOAD_DIR', SITE_ROOT . '/uploads');

// Maintenance settings
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'Site is under maintenance. Please check back later.');

// Rate limiting
define('RATE_LIMIT_ENABLED', true);
define('RATE_LIMIT_REQUESTS', 60);
define('RATE_LIMIT_WINDOW', 60);

// Anti-spam
define('CAPTCHA_ENABLED', true);
define('RECAPTCHA_PUBLIC_KEY', '{$input['captcha_public']}');
define('RECAPTCHA_PRIVATE_KEY', '{$input['captcha_private']}');

// Logging
define('ACCESS_LOG_ENABLED', true);
define('ACCESS_LOG_FILE', SITE_ROOT . '/logs/access.log');
define('ADMIN_LOG_FILE', SITE_ROOT . '/logs/admin.log');
define('ERROR_LOG_FILE', SITE_ROOT . '/logs/error.log');

// Create required directories if they don't exist
foreach (['/logs', '/cache', '/uploads', '/tmp'] as \$dir) {
    \$path = SITE_ROOT . \$dir;
    if (!is_dir(\$path)) {
        mkdir(\$path, 0755, true);
    }
}

// Initialize error logging
ini_set('error_log', ERROR_LOG_FILE);
ini_set('log_errors', LOG_ERRORS);
ini_set('display_errors', DISPLAY_ERRORS);
error_reporting(ERROR_REPORTING);

// Set timezone
date_default_timezone_set(SITE_TIMEZONE);

// Start session with secure settings
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', COOKIE_SECURE ? '1' : '0');
ini_set('session.cookie_samesite', COOKIE_SAMESITE);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.name', SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => COOKIE_PATH,
    'domain' => COOKIE_DOMAIN,
    'secure' => COOKIE_SECURE,
    'httponly' => COOKIE_HTTPONLY,
    'samesite' => COOKIE_SAMESITE
]);

EOT;

    // Database setup
    try {
        $dsn = "mysql:host={$input['db_server']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        // First connect without database to create it if needed
        $pdo = new PDO($dsn, $input['db_username'], $input['db_password'], $options);
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$input['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$input['db_name']}`");

        // Define tables to create
        $tables = [
            'activity' => "CREATE TABLE IF NOT EXISTS `activity` (
                `uid` varchar(24) NOT NULL,
                `action_name` varchar(255) NOT NULL,
                `action_id` varchar(255) NOT NULL DEFAULT '',
                `time` int(11) NOT NULL,
                PRIMARY KEY (`uid`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'sessions' => "CREATE TABLE IF NOT EXISTS `sessions` (
                `id` varchar(128) NOT NULL,
                `uid` varchar(24) NOT NULL,
                `last_activity` int(11) NOT NULL,
                `data` text NOT NULL,
                PRIMARY KEY (`id`),
                KEY `last_activity` (`last_activity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'citations' => "CREATE TABLE IF NOT EXISTS `citations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uid` varchar(24) NOT NULL,
                `topic` int(11) NOT NULL,
                `reply` int(11) NOT NULL,
                `time` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `uid` (`uid`),
                KEY `topic` (`topic`),
                KEY `reply` (`reply`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'images' => "CREATE TABLE IF NOT EXISTS `images` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `md5` varchar(32) NOT NULL,
                `file_name` varchar(255) NOT NULL,
                `topic_id` int(11) DEFAULT NULL,
                `reply_id` int(11) DEFAULT NULL,
                `deleted` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `md5` (`md5`),
                KEY `topic_id` (`topic_id`),
                KEY `reply_id` (`reply_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'users' => "CREATE TABLE IF NOT EXISTS `users` (
                `uid` varchar(24) NOT NULL,
                `password` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `role` varchar(20) NOT NULL DEFAULT 'user',
                `created_at` int(11) NOT NULL,
                `updated_at` int(11) NOT NULL,
                `last_seen` int(11) NOT NULL,
                `status` varchar(20) NOT NULL DEFAULT 'active',
                `topic_visits` text NOT NULL DEFAULT '',
                `ip_address` varchar(45) NOT NULL DEFAULT '',
                `namefag` varchar(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'topics' => "CREATE TABLE IF NOT EXISTS `topics` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `uid` varchar(24) NOT NULL,
                `time` int(11) NOT NULL,
                `last_reply` int(11) NOT NULL,
                `replies` int(11) NOT NULL DEFAULT '0',
                `views` int(11) NOT NULL DEFAULT '0',
                `sticky` tinyint(1) NOT NULL DEFAULT '0',
                `locked` tinyint(1) NOT NULL DEFAULT '0',
                `deleted` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `uid` (`uid`),
                KEY `time` (`time`),
                KEY `last_reply` (`last_reply`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'replies' => "CREATE TABLE IF NOT EXISTS `replies` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `topic_id` int(11) NOT NULL,
                `uid` varchar(24) NOT NULL,
                `time` int(11) NOT NULL,
                `message` text NOT NULL,
                `deleted` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `topic_id` (`topic_id`),
                KEY `uid` (`uid`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'groups' => "CREATE TABLE IF NOT EXISTS `groups` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `link` varchar(255) NOT NULL,
                `edit_limit` int(11) NOT NULL DEFAULT '0',
                `post_reply` tinyint(1) NOT NULL DEFAULT '0',
                `post_topic` tinyint(1) NOT NULL DEFAULT '0',
                `post_image` tinyint(1) NOT NULL DEFAULT '0',
                `post_link` tinyint(1) NOT NULL DEFAULT '0',
                `pm_users` tinyint(1) NOT NULL DEFAULT '0',
                `pm_mods` tinyint(1) NOT NULL DEFAULT '0',
                `read_mod_pms` tinyint(1) NOT NULL DEFAULT '0',
                `read_admin_pms` tinyint(1) NOT NULL DEFAULT '0',
                `report` tinyint(1) NOT NULL DEFAULT '0',
                `handle_reports` tinyint(1) NOT NULL DEFAULT '0',
                `delete` tinyint(1) NOT NULL DEFAULT '0',
                `undelete` tinyint(1) NOT NULL DEFAULT '0',
                `edit` tinyint(1) NOT NULL DEFAULT '0',
                `edit_others` tinyint(1) NOT NULL DEFAULT '0',
                `view_profile` tinyint(1) NOT NULL DEFAULT '0',
                `ban` tinyint(1) NOT NULL DEFAULT '0',
                `stick` tinyint(1) NOT NULL DEFAULT '0',
                `lock` tinyint(1) NOT NULL DEFAULT '0',
                `delete_ip_ids` tinyint(1) NOT NULL DEFAULT '0',
                `nuke_id` tinyint(1) NOT NULL DEFAULT '0',
                `nuke_ip` tinyint(1) NOT NULL DEFAULT '0',
                `exterminate` tinyint(1) NOT NULL DEFAULT '0',
                `cms` tinyint(1) NOT NULL DEFAULT '0',
                `bulletin` tinyint(1) NOT NULL DEFAULT '0',
                `defcon` tinyint(1) NOT NULL DEFAULT '0',
                `defcon_all` tinyint(1) NOT NULL DEFAULT '0',
                `delete_all_pms` tinyint(1) NOT NULL DEFAULT '0',
                `admin_dashboard` tinyint(1) NOT NULL DEFAULT '0',
                `manage_permissions` tinyint(1) NOT NULL DEFAULT '0',
                `merge` tinyint(1) NOT NULL DEFAULT '0',
                `limit_ip` tinyint(1) NOT NULL DEFAULT '0',
                `limit_ip_max` int(11) NOT NULL DEFAULT '0',
                `manage_messages` tinyint(1) NOT NULL DEFAULT '0',
                `hide_log` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'group_users' => "CREATE TABLE IF NOT EXISTS `group_users` (
                `uid` varchar(24) NOT NULL,
                `group_id` int(11) NOT NULL,
                `log_name` varchar(255) NOT NULL,
                PRIMARY KEY (`uid`,`group_id`),
                KEY `group_id` (`group_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'bans' => "CREATE TABLE IF NOT EXISTS `bans` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ip` varchar(45) NOT NULL,
                `reason` text NOT NULL,
                `time` int(11) NOT NULL,
                `expires` int(11) NOT NULL DEFAULT '0',
                `uid` varchar(24) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `ip` (`ip`),
                KEY `time` (`time`),
                KEY `expires` (`expires`),
                KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'config' => "CREATE TABLE IF NOT EXISTS `config` (
                `name` varchar(255) NOT NULL,
                `value` text NOT NULL,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'mod_actions' => "CREATE TABLE IF NOT EXISTS `mod_actions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uid` varchar(24) NOT NULL,
                `action` varchar(255) NOT NULL,
                `time` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `uid` (`uid`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'private_messages' => "CREATE TABLE IF NOT EXISTS `private_messages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `source` varchar(24) NOT NULL,
                `destination` varchar(24) NOT NULL,
                `contents` text NOT NULL,
                `parent` int(11) NOT NULL DEFAULT '0',
                `time` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `source` (`source`),
                KEY `destination` (`destination`),
                KEY `parent` (`parent`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'pm_notifications' => "CREATE TABLE IF NOT EXISTS `pm_notifications` (
                `uid` varchar(24) NOT NULL,
                `pm_id` int(11) NOT NULL,
                `parent_id` int(11) NOT NULL,
                PRIMARY KEY (`uid`,`pm_id`),
                KEY `parent_id` (`parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'watchlists' => "CREATE TABLE IF NOT EXISTS `watchlists` (
                `uid` varchar(24) NOT NULL,
                `topic_id` int(11) NOT NULL,
                PRIMARY KEY (`uid`,`topic_id`),
                KEY `topic_id` (`topic_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'ignore_lists' => "CREATE TABLE IF NOT EXISTS `ignore_lists` (
                `uid` varchar(24) NOT NULL,
                `ignored_uid` varchar(24) NOT NULL,
                PRIMARY KEY (`uid`,`ignored_uid`),
                KEY `ignored_uid` (`ignored_uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'revisions' => "CREATE TABLE IF NOT EXISTS `revisions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `post_id` int(11) NOT NULL,
                `uid` varchar(24) NOT NULL,
                `message` text NOT NULL,
                `time` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `post_id` (`post_id`),
                KEY `uid` (`uid`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'poll_options' => "CREATE TABLE IF NOT EXISTS `poll_options` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `topic_id` int(11) NOT NULL,
                `option_text` varchar(255) NOT NULL,
                `votes` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `topic_id` (`topic_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'poll_votes' => "CREATE TABLE IF NOT EXISTS `poll_votes` (
                `topic_id` int(11) NOT NULL,
                `uid` varchar(24) NOT NULL,
                `option_id` int(11) NOT NULL,
                PRIMARY KEY (`topic_id`,`uid`),
                KEY `option_id` (`option_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'bulletins' => "CREATE TABLE IF NOT EXISTS `bulletins` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `message` text NOT NULL,
                `time` int(11) NOT NULL,
                `expires` int(11) NOT NULL DEFAULT '0',
                `uid` varchar(24) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `time` (`time`),
                KEY `expires` (`expires`),
                KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'failed_postings' => "CREATE TABLE IF NOT EXISTS `failed_postings` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `ip` varchar(45) NOT NULL,
                `time` int(11) NOT NULL,
                `count` int(11) NOT NULL DEFAULT '1',
                PRIMARY KEY (`id`),
                KEY `ip` (`ip`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'search_log' => "CREATE TABLE IF NOT EXISTS `search_log` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `query` varchar(255) NOT NULL,
                `time` int(11) NOT NULL,
                `ip` varchar(45) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `time` (`time`),
                KEY `ip` (`ip`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'reports' => "CREATE TABLE IF NOT EXISTS `reports` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `post_id` int(11) NOT NULL,
                `uid` varchar(24) NOT NULL,
                `reason` text NOT NULL,
                `time` int(11) NOT NULL,
                `handled` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `post_id` (`post_id`),
                KEY `uid` (`uid`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'recovery_tokens' => "CREATE TABLE IF NOT EXISTS `recovery_tokens` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `uid` varchar(24) NOT NULL,
                `token` varchar(255) NOT NULL,
                `time` int(11) NOT NULL,
                `used` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `uid` (`uid`),
                KEY `time` (`time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'messages' => "CREATE TABLE IF NOT EXISTS `messages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `content` text NOT NULL,
                `time` int(11) NOT NULL,
                `uid` varchar(24) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `time` (`time`),
                KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'notepad' => "CREATE TABLE IF NOT EXISTS `notepad` (
                `uid` varchar(24) NOT NULL,
                `content` text NOT NULL,
                PRIMARY KEY (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'user_settings' => "CREATE TABLE IF NOT EXISTS `user_settings` (
                `uid` varchar(24) NOT NULL,
                `memorable_name` varchar(100) DEFAULT NULL,
                `memorable_password` varchar(255) DEFAULT NULL,
                `email` varchar(255) DEFAULT NULL,
                `custom_menu` text,
                `custom_style` text,
                `posts_per_page` int(11) DEFAULT NULL,
                `ostrich_mode` tinyint(1) DEFAULT '0',
                PRIMARY KEY (`uid`),
                UNIQUE KEY `memorable_name` (`memorable_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'whitelist' => "CREATE TABLE IF NOT EXISTS `whitelist` (
                `ip` varchar(45) NOT NULL,
                `reason` text NOT NULL,
                `time` int(11) NOT NULL,
                `uid` varchar(24) NOT NULL,
                PRIMARY KEY (`ip`),
                KEY `time` (`time`),
                KEY `uid` (`uid`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'flood_control' => "CREATE TABLE IF NOT EXISTS `flood_control` (
                `setting` varchar(255) NOT NULL,
                `value` text NOT NULL,
                PRIMARY KEY (`setting`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'last_actions' => "CREATE TABLE IF NOT EXISTS `last_actions` (
                `feature` varchar(255) NOT NULL,
                `time` int(11) NOT NULL,
                PRIMARY KEY (`feature`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

            'pages' => "CREATE TABLE IF NOT EXISTS `pages` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `url` varchar(255) NOT NULL,
                `page_title` varchar(255) NOT NULL,
                `content` text NOT NULL,
                `markup` tinyint(1) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `url` (`url`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        ];

        // Create tables
        foreach ($tables as $table => $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                throw new Exception("Error creating table '$table': " . $e->getMessage());
            }
        }

        $user_id = bin2hex(random_bytes(12));
        $raw_password = bin2hex(random_bytes(16));
        $hashed_password = password_hash($raw_password, PASSWORD_ARGON2ID);

        // Insert admin user
        $stmt = $pdo->prepare(
            "INSERT INTO `users` 
            (`uid`, `password`, `email`, `role`, `created_at`, `updated_at`, `last_seen`, `status`) 
            VALUES 
            (:uid, :password, :email, 'admin', :time, :time, :time, 'active')"
        );
        $stmt->execute([
            'uid' => $user_id,
            'password' => $hashed_password,
            'email' => $input['admin_email'],
            'time' => time()
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

        // Load and insert default config
        require SITE_ROOT . '/config/default_config.php';
        
        // Update config with user settings
        $config_defaults['SITE_TITLE'] = $input['board_name'];
        $config_defaults['RECAPTCHA_PUBLIC_KEY'] = $input['captcha_public'];
        $config_defaults['RECAPTCHA_PRIVATE_KEY'] = $input['captcha_private'];
        $config_defaults['SALT'] = bin2hex(random_bytes(32));
        $config_defaults['TRIP_SEED'] = bin2hex(random_bytes(32));
        $config_defaults['DEFCON'] = '5';
        $config_defaults['LANGUAGE'] = 'en';
        $config_defaults['POSTS_PER_PAGE_DEFAULT'] = '50';
        $config_defaults['ITEMS_PER_PAGE'] = '50';
        $config_defaults['ALLOW_IMAGES'] = '1';
        $config_defaults['ALLOW_BAN_READING'] = '1';
        $config_defaults['ALLOW_USER_PM'] = '1';
        $config_defaults['SIGNATURES'] = '1';
        $config_defaults['FORCED_ANON'] = '0';

        // Insert config values
        $stmt = $pdo->prepare("INSERT INTO `config` (`name`, `value`) VALUES (?, ?)");
        foreach ($config_defaults as $name => $value) {
            $stmt->execute([$name, $value]);
        }

        // Write config file
        if (!file_put_contents(SITE_ROOT . '/config/config.php', $config_template)) {
            throw new Exception('Could not write config file.');
        }

        // Installation complete
        echo json_encode([
            'success' => true,
            'message' => 'Installation complete! Your admin ID is ' . $user_id . ' and password is ' . $raw_password,
            'user_id' => $user_id,
            'password' => $raw_password
        ]);
        exit;

    } catch (Exception $e) {
        error_log("Installation error: " . $e->getMessage());
        die(json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]));
    }
}

// Display installation form if not submitted
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Install MiniBBS</title>
    <style>
        body { padding: 3% 4%; background-color: #f0f0f0; color: #333; font-family: Arial; }
        #wrapper { max-width: 800px; padding: 2em; margin: auto; background-color: #fff; border-radius: 1em; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #2c3e50; margin-bottom: 1em; }
        label { display: block; margin-top: 1em; color: #34495e; }
        input[type="text"], input[type="password"] { width: 100%; padding: 8px; margin: 0.5em 0; border: 1px solid #bdc3c7; border-radius: 4px; }
        input[type="submit"] { display: block; width: 100%; padding: 10px; margin-top: 2em; background-color: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer; }
        input[type="submit"]:hover { background-color: #2980b9; }
        .error { color: #e74c3c; margin: 1em 0; }
    </style>
</head>
<body>
    <div id="wrapper">
        <h1>Install MiniBBS</h1>
        <form method="post" action="install.php">
            <input type="hidden" name="form_sent" value="1">
            <input type="hidden" name="form[csrf_token]" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            
            <label>Database Username:</label>
            <input type="text" name="form[db_username]" value="<?php echo htmlspecialchars($input['db_username']); ?>">
            
            <label>Database Password:</label>
            <input type="password" name="form[db_password]" value="<?php echo htmlspecialchars($input['db_password']); ?>">
            
            <label>Database Server:</label>
            <input type="text" name="form[db_server]" value="<?php echo htmlspecialchars($input['db_server']); ?>">
            
            <label>Database Name:</label>
            <input type="text" name="form[db_name]" value="<?php echo htmlspecialchars($input['db_name']); ?>">
            
            <label>Board Name:</label>
            <input type="text" name="form[board_name]" value="<?php echo htmlspecialchars($input['board_name']); ?>">
            
            <label>Admin Email:</label>
            <input type="text" name="form[admin_email]" value="<?php echo htmlspecialchars($input['admin_email'] ?? ''); ?>">
            
            <label>Admin Log Name:</label>
            <input type="text" name="form[log_name]" value="<?php echo htmlspecialchars($input['log_name']); ?>">
            
            <label>reCAPTCHA Public Key:</label>
            <input type="text" name="form[captcha_public]" value="<?php echo htmlspecialchars($input['captcha_public']); ?>">
            
            <label>reCAPTCHA Private Key:</label>
            <input type="text" name="form[captcha_private]" value="<?php echo htmlspecialchars($input['captcha_private']); ?>">
            
            <input type="submit" value="Install">
        </form>
    </div>
</body>
</html>