<?php
declare(strict_types=1);

function load_class(string $class): void {
    require SITE_ROOT . '/includes/class.' . strtolower($class) . '.php';
}

function check_user_agent(string $type): bool {
    $user_agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    return match ($type) {
        'bot' => (bool)preg_match('/googlebot|adsbot|yahooseeker|yahoobot|bingbot|watchmouse|pingdom\.com|feedfetcher-google/', $user_agent),
        'mobile' => (bool)preg_match('/phone|iphone|itouch|ipod|symbian|android|htc_|htc-|palmos|blackberry|opera mini|mobi|windows ce|nokia|fennec|hiptop|kindle|mot |mot-|webos\/|samsung|sonyericsson|^sie-|nintendo|mobile/', $user_agent),
        default => false,
    };
}

function check_proxy(string $ip): bool {
    $reversed_ip = implode('.', array_reverse(explode('.', $ip)));
    return in_array(gethostbyname("$reversed_ip.rbl.efnetrbl.org"), ['127.0.0.1']) ||
           in_array(gethostbyname("$reversed_ip.niku.2ch.net"), ['127.0.0.2']) ||
           in_array(gethostbyname("$reversed_ip.80.208.77.188.166.ip-port.exitlist.torproject.org"), ['127.0.0.2']);
}

function hash_password(string $password): string {
    return password_hash($password . SALT, PASSWORD_ARGON2ID);
}

function tripcode(string $name_input): array {
    $parts = explode('#', $name_input);
    $name = $parts[0];
    $trip = '';

    if (isset($parts[1]) || isset($parts[2])) {
        $trip = $parts[1] ?? $parts[2] ?? '';
        if (function_exists('mb_convert_encoding')) {
            mb_substitute_character('none');
            $trip = mb_convert_encoding($trip, 'Shift_JIS', 'UTF-8') ?: $trip;
        }
        $salt = preg_replace('/[^\.-z]/', '.', substr($trip . 'H.', 1, 2));
        $salt = strtr($salt, ':;<=>?@[\]^_`', 'ABCDEFGabcdef');
        $trip = isset($parts[2])
            ? '!!' . substr(crypt($trip, TRIP_SEED), -10)
            : '!' . substr(crypt($trip, $salt), -10);
    }
    return [$name, $trip];
}

function create_id(): void {
    global $db;
    if (DEFCON < 5 || check_user_agent('bot')) {
        return;
    }

    $cookie_options = ['expires' => time() + 315569260, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];

    if (RECAPTCHA_ENABLE) {
        $uids_recent = (int)$db->q(
            'SELECT COUNT(*) FROM users WHERE ip_address = :ip AND first_seen > :time',
            ['ip' => $_SERVER['REMOTE_ADDR'], 'time' => time() - 3600]
        )->fetchColumn();
        if ($uids_recent > RECAPTCHA_MAX_UIDS_PER_HOUR) {
            show_captcha('Please enable cookies to use this site.');
        }
    }

    $user_id = bin2hex(random_bytes(12));
    $password = bin2hex(random_bytes(16));

    $db->q(
        'INSERT INTO users (uid, password, ip_address, first_seen, last_seen) VALUES (:uid, :pass, :ip, :time, :time)',
        ['uid' => $user_id, 'pass' => $password, 'ip' => $_SERVER['REMOTE_ADDR'], 'time' => time()]
    );

    $_SESSION['first_seen'] = time();
    setcookie('UID', $user_id, $cookie_options);
    setcookie('password', $password, $cookie_options);
    $_SESSION['UID'] = $user_id;
    $_SESSION['topic_visits'] = [];
    $_SESSION['post_count'] = 0;
    $_SESSION['notice'] = m('Notice: Welcome', SITE_TITLE);
}

function generate_password(): string {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    return substr(str_shuffle($characters), 0, 32);
}

function activate_id(string $uid, string $password): bool {
    global $db;
    if (!empty($_SESSION['UID']) && $uid === $_SESSION['UID']) {
        $_SESSION['ID_activated'] = true;
        return true;
    }

    $stmt = $db->q('SELECT password, first_seen, topic_visits, namefag, post_count FROM users WHERE uid = :uid', ['uid' => $uid]);
    [$db_password, $first_seen, $topic_visits, $name, $post_count] = $stmt->fetch(PDO::FETCH_NUM) ?: [null, null, null, null, 0];

    if ($db_password && $password === $db_password) {
        $cookie_options = ['expires' => time() + 315569260, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
        $_SESSION['UID'] = $uid;
        $_SESSION['ID_activated'] = true;
        $_SESSION['first_seen'] = (int)$first_seen;
        $_SESSION['poster_name'] = $name;
        $_SESSION['topic_visits'] = json_decode($topic_visits ?: '[]', true);
        $_SESSION['post_count'] = (int)$post_count;

        if (($_COOKIE['UID'] ?? '') !== $uid) {
            setcookie('UID', $uid, $cookie_options);
            setcookie('password', $password, $cookie_options);
        }
        return true;
    }
    return false;
}

function force_id(): void {
    global $db, $perm;
    if (empty($_SESSION['ID_activated'])) {
        error::fatal(m('Error: No ID'));
    }
    if (ALLOW_BAN_READING && !defined('REPRIEVE_BAN')) {
        $perm->die_on_ban();
    }
    if ($_SESSION['post_count'] < 15 && empty($_SESSION['IP_checked'])) {
        $is_whitelisted = (bool)$db->q('SELECT COUNT(*) FROM whitelist WHERE uid = :uid', ['uid' => $_SESSION['UID']])->fetchColumn();
        if (!$is_whitelisted && check_proxy($_SERVER['REMOTE_ADDR'])) {
            if (show_captcha('You appear to be using a proxy (' . htmlspecialchars($_SERVER['REMOTE_ADDR']) . '). Please fill in the CAPTCHA.')) {
                $_SESSION['IP_checked'] = true;
                $db->q('INSERT INTO whitelist (uid) VALUES (:uid)', ['uid' => $_SESSION['UID']]);
            }
        } else {
            $_SESSION['IP_checked'] = true;
        }
    }
}

function load_settings(): array {
    global $db;
    require SITE_ROOT . '/config/default_dashboard.php';
    $settings = array_column($default_dashboard, 'default', null);

    if (!empty($_SESSION['UID'])) {
        $stmt = $db->q('SELECT * FROM user_settings WHERE uid = :uid', ['uid' => $_SESSION['UID']]);
        $custom_settings = array_filter($stmt->fetch(PDO::FETCH_ASSOC) ?: [], 'is_string');
        $settings = array_merge($settings, $custom_settings);
    }
    return $settings;
}

function show_captcha(string $message): bool {
    global $template;
    if (!empty($_SESSION['is_human'])) {
        return true;
    }

    $template->title = 'CAPTCHA';
    require_once 'includes/recaptcha.php'; // TODO: Update to reCAPTCHA v3
    if (!empty($_POST['recaptcha_response_field'])) {
        $resp = recaptcha_check_answer(RECAPTCHA_PRIVATE_KEY, $_SERVER['REMOTE_ADDR'], $_POST['recaptcha_challenge_field'], $_POST['recaptcha_response_field']);
        if ($resp->is_valid) {
            $_SESSION['is_human'] = true;
            return true;
        }
        $error = $resp->error;
    }

    echo '<p>' . ($message ?: m('CAPTCHA preface')) . '</p>';
    echo '<form action="" method="post">' . recaptcha_get_html(RECAPTCHA_PUBLIC_KEY, $error ?? null);
    foreach ($_POST as $k => $v) {
        if (in_array($k, ['recaptcha_challenge_field', 'recaptcha_response_field'])) continue;
        echo is_array($v)
            ? array_reduce(array_keys($v), fn($carry, $nk) => $carry . '<input type="hidden" name="' . htmlspecialchars("$k[$nk]") . '" value="' . htmlspecialchars($v[$nk]) . '">', '')
            : '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
    }
    echo '<input type="submit" value="Continue"></form>';
    $template->render();
    exit;
}

function update_activity(string $action_name, string $action_id = ''): void {
    global $db;
    if (empty($_SESSION['UID'])) {
        return;
    }

    $time = time();
    $db->q(
        'INSERT INTO activity (time, uid, action_name, action_id) VALUES (:time, :uid, :action, :id) 
         ON DUPLICATE KEY UPDATE time = :time, action_name = :action, action_id = :id',
        ['time' => $time, 'uid' => $_SESSION['UID'], 'action' => $action_name, 'id' => $action_id]
    );
}

function id_exists(string $id): bool {
    global $db;
    return (bool)$db->q('SELECT 1 FROM users WHERE uid = :uid', ['uid' => $id])->fetchColumn();
}

function is_ignored(string ...$fields): bool {
    global $db;
    if (empty($_SESSION['settings']['ostrich_mode'])) {
        return false;
    }

    $_SESSION['ignored_phrases'] ??= array_filter(
        explode("\n", str_replace("\r", '', $db->q(
            'SELECT ignored_phrases FROM ignore_lists WHERE uid = :uid',
            ['uid' => $_SESSION['UID']]
        )->fetchColumn() ?: ''))
    );

    foreach ($fields as $field) {
        foreach ($_SESSION['ignored_phrases'] as $phrase) {
            if (str_starts_with($phrase, '/') && strlen($phrase) < 28 && preg_match('|^/.+/$|', $phrase)) {
                if (preg_match($phrase, $field)) return true;
            } elseif (stripos($field, $phrase) !== false) {
                return true;
            }
        }
    }
    return false;
}

function super_trim(string $text): string {
    static $nonprinting = ["\r", "\u{00AD}", "\u{FEFF}", "\u{200B}", "\u{200D}", "\u{200C}"];
    return preg_replace('/(\r?\n[ \t]*){3,}/', "\n\n\n", trim(str_replace($nonprinting, '', $text)));
}

function age(int $timestamp, ?int $comparison = null): string {
    $comparison ??= time();
    $age = abs($comparison - $timestamp);
    $units = [
        'second' => 60, 'minute' => 60, 'hour' => 24, 'day' => 7, 'week' => 4.25, 'month' => 12,
    ];

    foreach ($units as $unit => $max) {
        $next = $age / $max;
        if ($next < 1) {
            $age = (int)$age;
            return "$age $unit" . ($age === 1 ? '' : 's');
        }
        $age = $next;
    }
    $age = round($age, 1);
    return "$age year" . (floor($age) === 1 ? '' : 's');
}

function format_date(int $timestamp): string {
    return date('Y-m-d H:i:s \U\T\C — l \t\h\e jS \o\f F Y, g:i A', $timestamp);
}

function format_number(int $number): string {
    return $number === 0 ? '-' : number_format($number);
}

function format_name(?string $name, ?string $tripcode, ?string $link = null, ?int $poster_number = null, bool $shorthand = false): string {
    static $anonymous = null;
    $anonymous ??= m('Anonymous');

    if (empty($name) && empty($tripcode)) {
        return $poster_number !== null ? "$anonymous <strong>" . number_to_letter($poster_number) . '</strong>' : $anonymous;
    }

    $formatted = empty($name) ? $tripcode : '<strong>' . htmlspecialchars($name) . '</strong>';
    if (!empty($link)) {
        $formatted = '<a href="' . DIR . htmlspecialchars($link) . '">' . $formatted . '</a>';
    }
    if ($tripcode) {
        $formatted .= $shorthand ? '<span class="help" title="' . $tripcode . '">' . $formatted . '</span>' : " $tripcode";
    }
    return $formatted;
}

function number_to_letter(int $number): string {
    static $alphabet = null;
    $alphabet ??= range('A', 'Y');
    return $number < 24 ? $alphabet[$number] : 'Z-' . ($number - 23);
}

function format_headline(string $headline, int $id, int $reply_count, bool $poll, bool $locked, bool $sticky): string {
    $url = DIR . 'topic/' . $id . page($reply_count);
    $visited = !empty($_SESSION['topic_visits'][$id]) ? ' class="visited"' : '';
    $output = '<a href="' . $url . '"' . $visited . '>' . $headline . '</a>';

    if ($poll) $output .= ' <span class="poll_marker">(Poll)</span>';
    if (!empty($_SESSION['settings']['posts_per_page']) && $reply_count > $_SESSION['settings']['posts_per_page']) {
        $pages = ceil($reply_count / $_SESSION['settings']['posts_per_page']);
        $output .= ' <span class="headline_pages">[' . implode(', ', array_map(
            fn($i) => '<a href="' . DIR . 'topic/' . $id . '/' . $i . '">' . $i . '</a>',
            range(1, $pages)
        )) . ']</span>';
    }
    $output .= '<small class="topic_info">' . ($locked ? '[LOCKED]' : '') . ($sticky ? ' [STICKY]' : '') . '</small>';
    return $output;
}

function replies(int $topic_id, int $topic_replies): string {
    $output = format_number($topic_replies);
    $visited = $_SESSION['topic_visits'][$topic_id] ?? null;

    if ($visited === null) {
        return "<strong>$output</strong>";
    }
    if ($visited < $topic_replies) {
        $new_replies = $topic_replies - $visited;
        $label = $new_replies == $topic_replies ? 'all-' : "<strong>$new_replies</strong> ";
        $output .= ' <span class="new_replies">(<a href="' . DIR . 'topic/' . $topic_id . page($topic_replies, $visited + 1) . '#new">' . $label . 'new</a>)</span>';
    }
    return $output;
}

function page(int $total_replies, ?int $reply_number = null): string {
    $ppp = $_SESSION['settings']['posts_per_page'] ?? 0;
    return (!$ppp || $total_replies <= $ppp) ? '' : '/' . ($reply_number ? ceil($reply_number / $ppp) : 1);
}

function redirect(?string $notice = null, ?string $location = null): never {
    if ($notice) $_SESSION['notice'] = $notice;
    $location ??= $_SERVER['HTTP_REFERER'] ?? '';
    if (str_starts_with($location, URL)) $location = substr($location, strlen(URL));
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
        $_SESSION['redirected_by_ajax'] = true;
    }
    header('Location: ' . URL . $location);
    exit;
}

function check_length(string $text, string $name, int $min_length, int $max_length): void {
    $length = strlen($text);
    if ($min_length > 0 && empty($text)) {
        error::add("The $name cannot be blank.");
    } elseif ($length > $max_length) {
        error::add("The $name was " . number_format($length - $max_length) . " characters over the limit (" . number_format($max_length) . ").");
    } elseif ($length < $min_length) {
        error::add("The $name was too short.");
    }
}

function csrf_token(): void {
    $_SESSION['token'] ??= bin2hex(random_bytes(16)); // Stronger token
    echo '<input type="hidden" name="CSRF_token" value="' . htmlspecialchars($_SESSION['token']) . '" class="noscreen">';
}

function check_token(): bool {
    if (($_POST['CSRF_token'] ?? '') !== ($_SESSION['token'] ?? '')) {
        error::add(m('Error: Invalid token'));
        return false;
    }
    return true;
}

function delete_topic(int $id, bool $notify = true): void {
    global $db, $perm;
    $stmt = $db->q('SELECT author, namefag, tripfag FROM topics WHERE id = :id', ['id' => $id]);
    [$author_id, $author_name, $author_trip] = $stmt->fetch(PDO::FETCH_NUM) ?: ['', '', ''];
    $author_name = trim("$author_name $author_trip");

    if ($perm->is_admin($author_id) && $_SESSION['UID'] !== $author_id) {
        error::fatal(m('Error: Access denied'));
    }

    delete_image('topic', $id);
    $stmt = $db->q('SELECT id FROM replies WHERE parent_id = :id', ['id' => $id]);
    while ($reply_id = $stmt->fetchColumn()) {
        delete_image('reply', (int)$reply_id);
    }

    $db->q("UPDATE topics SET deleted = 1 WHERE id = :id", ['id' => $id]);
    $db->q("DELETE FROM reports WHERE post_id = :id AND type = 'topic'", ['id' => $id]);
    $db->q("DELETE FROM citations WHERE topic = :id", ['id' => $id]);
    log_mod('delete_topic', (string)$id, $author_name);

    if ($author_id !== $_SESSION['UID'] && $notify) {
        system_message($author_id, m('PM: Deleted topic', (string)$id));
    }
}

function delete_reply(int $id, bool $notify = true): void {
    global $db, $perm;
    $stmt = $db->q('SELECT author, namefag, tripfag, time, parent_id FROM replies WHERE id = :id', ['id' => $id]);
    [$author_id, $author_name, $author_trip, $reply_time, $parent_id] = $stmt->fetch(PDO::FETCH_NUM) ?: ['', '', '', 0, 0];
    $author_name = trim("$author_name $author_trip");

    if (!$parent_id) error::fatal('No such reply.');
    if ($perm->is_admin($author_id) && $_SESSION['UID'] !== $author_id) {
        error::fatal(m('Error: Access denied'));
    }

    delete_image('reply', $id);
    $db->q("UPDATE replies SET deleted = 1 WHERE id = :id", ['id' => $id]);
    $db->q("DELETE FROM reports WHERE post_id = :id AND type = 'reply'", ['id' => $id]);
    $db->q("DELETE FROM citations WHERE reply = :id", ['id' => $id]);
    $db->q('UPDATE topics SET replies = replies - 1 WHERE id = :id', ['id' => $parent_id]);

    $stmt = $db->q('SELECT last_post, replies FROM topics WHERE id = :id', ['id' => $parent_id]);
    [$topic_bump, $topic_replies] = $stmt->fetch(PDO::FETCH_NUM);
    if (!$topic_replies) {
        $db->q('UPDATE topics SET last_post = time WHERE id = :id', ['id' => $parent_id]);
    } elseif ($topic_bump == $reply_time) {
        $db->q('UPDATE topics SET last_post = (SELECT time FROM replies WHERE parent_id = :id AND deleted = 0 ORDER BY time DESC LIMIT 1) WHERE id = :id', ['id' => $parent_id]);
    }

    log_mod('delete_reply', (string)$id, $author_name);
    if ($author_id !== $_SESSION['UID'] && $notify) {
        system_message($author_id, m('PM: Deleted reply', (string)$id));
    }
}

function delete_image(string $mode, int $post_id, bool $hard_delete = false): void {
    global $db;
    if (!in_array($mode, ['reply', 'topic'])) {
        error::fatal('Invalid image deletion type.');
    }

    $stmt = $db->q("SELECT COUNT(*), file_name FROM images WHERE md5 = (SELECT md5 FROM images WHERE {$mode}_id = :id LIMIT 1) AND deleted = 0", ['id' => $post_id]);
    [$usages, $filename] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, null];

    if ($filename) {
        if ($usages === 1) {
            @unlink(SITE_ROOT . '/img/' . $filename);
            @unlink(SITE_ROOT . '/thumbs/' . $filename);
        }
        $db->q("UPDATE images SET deleted = 1 WHERE {$mode}_id = :id AND file_name = :file" . ($hard_delete ? '' : ' LIMIT 1'), ['id' => $post_id, 'file' => $filename]);
    }
}

function log_mod(string $action, string $target, string $param = '', string $reason = '', ?string $mod = null): void {
    global $db;
    $mod ??= $_SESSION['UID'];
    $type = match ($action) {
        'delete_image', 'delete_topic', 'delete_reply', 'delete_bulletin', 'undelete_topic', 'undelete_reply', 'nuke_ip', 'nuke_id' => 'delete',
        'edit_topic', 'edit_reply' => 'edit',
        'ban_ip', 'ban_uid', 'ban_cidr', 'ban_wild' => 'ban',
        'unban_ip', 'unban_uid', 'unban_cidr', 'unban_wild' => 'unban',
        'stick_topic', 'unstick_topic' => 'stick',
        'lock_topic', 'unlock_topic' => 'lock',
        'cms_new', 'cms_edit', 'delete_page', 'undelete_page' => 'cms',
        'merge', 'unmerge' => 'merge',
        'db_maintenance' => 'system',
        default => $action,
    };

    $db->q(
        'INSERT INTO mod_actions (action, type, target, mod_uid, mod_ip, reason, param, time) VALUES (:action, :type, :target, :mod, :ip, :reason, :param, :time)',
        ['action' => $action, 'type' => $type, 'target' => $target, 'mod' => $mod, 'ip' => $_SERVER['REMOTE_ADDR'], 'reason' => $reason, 'param' => $param, 'time' => time()]
    );
}

function system_message(string $uid, string $message): bool {
    global $db;
    $time = time();
    $db->q(
        'INSERT INTO private_messages (source, destination, contents, parent, time) VALUES (:source, :dest, :content, 0, :time)',
        ['source' => 'system', 'dest' => $uid, 'content' => $message, 'time' => $time]
    );

    if ($new_id = (int)$db->lastInsertId()) {
        $db->q('UPDATE private_messages SET parent = :id WHERE id = :id', ['id' => $new_id]);
        $db->q('INSERT INTO pm_notifications (uid, pm_id, parent_id) VALUES (:uid, :pm, :parent)', ['uid' => $uid, 'pm' => $new_id, 'parent' => $new_id]);
        return true;
    }
    return false;
}

function get_styles(): array {
    return array_map(fn($path) => basename($path, '.css'), glob(SITE_ROOT . '/style/themes/*.css') ?: []);
}