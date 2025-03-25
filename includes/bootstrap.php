<?php
declare(strict_types=1);

define('SITE_ROOT', realpath(__DIR__ . '/..'));

if (!file_exists(SITE_ROOT . '/config/config.php')) {
    throw new RuntimeException('MiniBBS is not properly installed.');
}

// Globally required files
require SITE_ROOT . '/config/config.php';
require SITE_ROOT . '/includes/functions.php';

// Autoload classes
spl_autoload_register('load_class');

// Error handling
set_error_handler([error::class, 'error_handler']);
set_exception_handler([error::class, 'exception_handler']);

$script_start = microtime(true);
$template = new Template($script_start);
$db = new Database($db_info['username'], $db_info['password'], $db_info['server'], $db_info['database']);

// Load and define configuration
$config = cache::fetch('config') ?: $db->q('SELECT name, value FROM config')
    ->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP)
    ->map('reset')
    ->getArrayCopy();
cache::set('config', $config);
foreach ($config as $name => $value) {
    define($name, $value);
}
unset($config);

$lang = new Language(LANGUAGE);
$perm = new Permission();

defined('MINIMAL_BOOTSTRAP') or define('MINIMAL_BOOTSTRAP', false);

// Initialize environment
if (!MINIMAL_BOOTSTRAP) {
    date_default_timezone_set('UTC');
    define('MOBILE_MODE', (bool)preg_match('/mobile/i', $_SERVER['HTTP_USER_AGENT'] ?? ''));
    header('Content-Type: text/html; charset=UTF-8');
    session_cache_limiter('nocache');

    // Secure session configuration
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '86400'); // 24 hours
    ini_set('session.use_only_cookies', '1');

    $cookie_options = [
        'expires' => time() + 315569260, // ~10 years
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    // Session start and regeneration
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    // Regenerate session ID periodically to prevent fixation
    if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] > 3600) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// Hostname check
if (HOSTNAME !== ($_SERVER['HTTP_HOST'] ?? '')) {
    header('Location: ' . URL);
    exit;
}

// Fetch DEFCON setting
$defcon = cache::fetch('defcon');
if ($defcon === false) {
    $defcon = (int)$db->q("SELECT value FROM flood_control WHERE setting = 'defcon'")->fetchColumn();
    cache::set('defcon', $defcon);
}
define('DEFCON', $defcon);

// Handle user ID and login
if (empty($_COOKIE['UID']) && !$perm->ip_banned($_SERVER['REMOTE_ADDR'])) {
    create_id();
} elseif (!empty($_COOKIE['password']) && empty($_SESSION['ID_activated'])) {
    if (!activate_id($_COOKIE['UID'], $_COOKIE['password'])) {
        create_id();
    }
}

// Load user settings
$_SESSION['settings'] ??= load_settings();

// Set permissions
$perm->set_group();

if (DEFCON < 2 && !$perm->is_admin()) {
    die(m('Lockdown mode'));
}

// Clean up old sessions and tokens periodically
if (rand(1, 100) === 1) { // 1% chance to run cleanup
    // Clean up expired recovery tokens
    $db->q('DELETE FROM recovery_tokens WHERE expiry < ?', time());
    
    // Clean up old sessions
    $db->q('DELETE FROM sessions WHERE last_activity < ?', time() - 86400);
}

// Additional checks for authenticated users
if (!empty($_SESSION['ID_activated']) && !MINIMAL_BOOTSTRAP) {
    // Clear PM notifications
    if (isset($reading_pm) && ctype_digit($_GET['id'] ?? '')) {
        $db->q('DELETE FROM pm_notifications WHERE uid = :uid AND parent_id = :parent', [
            'uid' => $_SESSION['UID'],
            'parent' => $_GET['id'],
        ]);
    }

    // Check for unread PMs
    $stmt = $db->q('SELECT COUNT(*), parent_id, pm_id FROM pm_notifications WHERE uid = :uid ORDER BY pm_id ASC', [
        'uid' => $_SESSION['UID'],
    ]);
    [$notifications['pms'], $new_parent, $new_pm] = $stmt->fetch(PDO::FETCH_NUM);
    if ($notifications['pms'] > 0) {
        $_SESSION['notice'] = m('Notice: New PM', "$new_parent" . ($new_pm != $new_parent ? "#reply_$new_pm" : ''), number_format($notifications['pms']));
        if ($notifications['pms'] > 2) {
            $_SESSION['notice'] .= m('Notice: New PM clear');
        }
    }

    // Fetch last actions
    $last_actions = $db->q('SELECT feature, time FROM last_actions')
        ->fetchAll(PDO::FETCH_KEY_PAIR);

    // Check citations
    $notifications['citations'] = (int)$db->q('SELECT COUNT(*) FROM citations WHERE uid = :uid', [
        'uid' => $_SESSION['UID'],
    ])->fetchColumn();

    // Check watchlist
    $notifications['watchlist'] = (int)$db->q('SELECT COUNT(*) FROM watchlists WHERE uid = :uid AND new_replies = 1', [
        'uid' => $_SESSION['UID'],
    ])->fetchColumn();

    // Check reports (for mods)
    if ($perm->get('handle_reports')) {
        $notifications['reports'] = (int)$db->q('SELECT COUNT(*) FROM reports')->fetchColumn();
    }
}

// Ban check
if (!ALLOW_BAN_READING && !defined('REPRIEVE_BAN')) {
    $perm->die_on_ban();
}

// Cache custom stylesheet timestamp
if (!empty($_SESSION['settings']['custom_style']) && empty($_SESSION['style_last_modified'])) {
    $_SESSION['style_last_modified'] = time();
}