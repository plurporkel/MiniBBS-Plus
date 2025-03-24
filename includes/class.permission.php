<?php

declare(strict_types=1);

/**
 * Handles bans & gets the current user's permissions, as determined by their group membership.
 * The default group is ID #1, 'user'. Posters who belong to other groups are listed in the "group_users" table.
 * The "group_users" columns are separated from the "users" table to allow for caching ($_SESSION
 * would not allow us to immediately demod someone.) The "groups" table stores group settings.
 */
class Permission
{
    /**
     * Banned IPs, UIDs, wildcard and CIDR ranges, each in their own array.
     * 'uid'  : Banned UIDs, stored as values with a numerical index.
     * 'ip'   : Specifically banned IPs, stored as values with a numerical index.
     * 'range': Banned wildcard and CIDR ranges, with the ban (e.g., 127.0.0.1/24 or 127.0.*.*) as key.
     *          The value is an array with two elements: min IP (long), max IP (long).
     */
    private array $bans = [
        'uid'   => [],
        'ip'    => [],
        'range' => []
    ];

    /** @var array<string, mixed> Cached results of ban checks */
    private array $ban_checks = [];

    /** @var array<int, array<string, mixed>> Groups and their settings */
    private array $groups = [];

    /** @var array<int, array<string, mixed>> Users who belong to a non-default group */
    private array $group_users = [];

    /** @var int The group ID to which the current user belongs */
    private int $current_group = 1;

    /** @var int The IDs (from the groups table) of the default user groups */
    const USER_GROUP  = 1;
    const MOD_GROUP   = 2;
    const ADMIN_GROUP = 3;

    /**
     * Fetches groups, group users, and bans from cache or database
     */
    public function __construct()
    {
        global $db;

        $group_users = cache::fetch('group_users');
        if ($group_users === false) {
            $res = $db->q('SELECT uid, group_id, log_name FROM group_users');
            $group_users = array_map('reset', $res->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC));
            cache::set('group_users', $group_users);
        }
        $this->group_users = $group_users;

        $groups = cache::fetch('groups');
        if ($groups === false) {
            $res = $db->q('SELECT * FROM groups');
            $groups = array_map('reset', $res->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC));
            cache::set('groups', $groups);
        }
        $this->groups = $groups;

        if ($this->groups[self::USER_GROUP]['name'] !== 'user') {
            throw new Exception('The default user group (group ID #' . self::USER_GROUP . ') should be named "user" in the database (not "' . htmlspecialchars($this->groups[self::USER_GROUP]['name']) . '").');
        }

        $bans = cache::fetch('bans');
        if ($bans === false) {
            $res = $db->q('SELECT `type`, `target` FROM bans');
            $bans = $res->fetchAll(PDO::FETCH_COLUMN | PDO::FETCH_GROUP);
            $bans = array_merge($this->bans, $bans);

            if (isset($bans['cidr'])) {
                foreach ($bans['cidr'] as $cidr) {
                    list($subnet, $suffix) = explode('/', $cidr);
                    $min_ip = ip2long($subnet);
                    $min_ip &= ~((1 << (32 - $suffix)) - 1);
                    $max_ip = $min_ip + pow(2, 32 - $suffix) - 1;
                    $bans['range'][$cidr] = [$min_ip, $max_ip];
                }
                unset($bans['cidr']);
            }

            if (isset($bans['wild'])) {
                foreach ($bans['wild'] as $wildcard) {
                    $min_ip = ip2long(str_replace('*', '0', $wildcard));
                    $max_ip = ip2long(str_replace('*', '255', $wildcard));
                    $bans['range'][$wildcard] = [$min_ip, $max_ip];
                }
                unset($bans['wild']);
            }

            cache::set('bans', $bans);
        }
        $this->bans = $bans;
    }

    /**
     * Sets the group of the current user
     */
    public function set_group(): void
    {
        $this->current_group = $_SESSION['UID'] ?? null
            ? ($this->group_users[$_SESSION['UID']]['group_id'] ?? self::USER_GROUP)
            : self::USER_GROUP;
    }

    /**
     * Returns the value of a group setting for a UID
     *
     * @param string $setting The setting to retrieve
     * @param int|null $uid The user ID (null for current user)
     * @return mixed
     */
    public function get(string $setting, ?int $uid = null): mixed
    {
        $group = $uid === null ? $this->current_group : ($this->group_users[$uid]['group_id'] ?? self::USER_GROUP);
        return $this->groups[$group][$setting] ?? null;
    }

    /**
     * Fetches the log_name of a UID
     *
     * @param int|null $uid The user ID (null for current user)
     * @return string|null
     */
    public function get_name(?int $uid = null): ?string
    {
        $uid = $uid ?? $_SESSION['UID'] ?? null;
        return $this->group_users[$uid]['log_name'] ?? null;
    }

    /**
     * Fetches the UID of a user with $log_name
     *
     * @param string $log_name The login name to search for
     * @return int|false
     */
    public function get_uid(string $log_name): int|false
    {
        foreach ($this->group_users as $uid => $properties) {
            if ($properties['log_name'] === $log_name) {
                return $uid;
            }
        }
        return false;
    }

    /**
     * Returns an array of UIDs in group_users with a specific permission
     *
     * @param string $permission The permission to check
     * @return array<int>
     */
    public function users_with_permission(string $permission): array
    {
        return array_filter(
            array_keys($this->group_users),
            fn($uid) => $this->get($permission, $uid)
        );
    }

    /**
     * Returns an array of groups
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_groups(): array
    {
        return $this->groups;
    }

    /**
     * Checks admin status for a UID (or current user if null)
     *
     * @param int|null $uid The user ID (null for current user)
     * @return bool
     */
    public function is_admin(?int $uid = null): bool
    {
        $uid = $uid ?? $_SESSION['UID'] ?? null;
        return ($this->group_users[$uid]['group_id'] ?? null) === self::ADMIN_GROUP;
    }

    /**
     * Checks mod status for a UID (or current user if null)
     *
     * @param int|null $uid The user ID (null for current user)
     * @return bool
     */
    public function is_mod(?int $uid = null): bool
    {
        $uid = $uid ?? $_SESSION['UID'] ?? null;
        return ($this->group_users[$uid]['group_id'] ?? null) === self::MOD_GROUP;
    }

    /**
     * Returns the ban target if $ip is banned or in a range ban, or false otherwise
     *
     * @param string $ip The IP address to check
     * @param bool $range_check Whether to check range bans
     * @return string|false
     */
    public function ip_banned(string $ip, bool $range_check = true): string|false
    {
        if (isset($this->ban_checks[$ip])) {
            return $this->ban_checks[$ip];
        }

        $res = in_array($ip, $this->bans['ip'], true) ? $ip : false;

        if ($range_check && !empty($this->bans['range'])) {
            $long_ip = ip2long($ip);
            foreach ($this->bans['range'] as $ban => $range) {
                if ($long_ip >= $range[0] && $long_ip <= $range[1]) {
                    $res = $ban;
                    break;
                }
            }
        }

        $this->ban_checks[$ip] = $res;
        return $res;
    }

    /**
     * Returns true if $uid is banned, false otherwise
     *
     * @param int $uid The user ID to check
     * @return bool
     */
    public function uid_banned(int $uid): bool
    {
        if (isset($this->ban_checks[$uid])) {
            return $this->ban_checks[$uid];
        }

        $res = in_array((string)$uid, $this->bans['uid'], true);
        $this->ban_checks[$uid] = $res;
        return $res;
    }

    /**
     * Kills the script if the current user's IP or UID is banned
     */
    public function die_on_ban(): void
    {
        global $db;

        $uid = $_SESSION['UID'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        if ($uid && $this->uid_banned($uid)) {
            $ban_target = (string)$uid;
            $ban_message = 'Your UID is banned.';
        } elseif ($ban_target = $this->ip_banned($ip)) {
            $ban_message = "Your IP address ({$ip}) is banned.";
        } else {
            return;
        }

        list($ban_reason, $ban_expiry, $ban_time) = $this->get_ban_log($ban_target);
        $ban_appealed = $this->get_ban_appeal($ban_target);

        if ($ban_expiry !== '0' && $ban_expiry < $_SERVER['REQUEST_TIME']) {
            $db->q('DELETE FROM bans WHERE target = ?', $ban_target);
            cache::clear('bans');
            $_SESSION['notice'] = 'Your ban expired! Welcome back.';
            return;
        }

        if (!empty($ban_reason)) {
            $ban_message .= ' Reason: "<strong>' . htmlspecialchars($ban_reason) . '</strong>". ';
        }

        $ban_message .= ' This ban was filed ' . age($ban_time) . ' ago and ';
        $ban_message .= $ban_expiry > 0
            ? 'will expire in <strong>' . age($ban_expiry) . '</strong>.'
            : 'is not set to expire.';

        if ($ban_appealed) {
            $ban_message .= ' You have already appealed this ban.';
        } elseif (defined('ALLOW_BAN_APPEALS') && ALLOW_BAN_APPEALS) {
            $ban_message .= ' You may <a href="' . DIR . 'appeal_ban">appeal</a>.';
        }

        ErrorHandler::fatal($ban_message);
    }

    /**
     * Fetches the ban reason and expiry from the mod logs
     *
     * @param string $target The ban target (UID or IP)
     * @return array|false
     */
    public function get_ban_log(string $target): array|false
    {
        global $db;

        $res = $db->q(
            "SELECT reason, param, time
            FROM mod_actions
            WHERE target = ? AND `type` = 'ban'
            ORDER BY time DESC
            LIMIT 1",
            $target
        );
        return $res->fetch(PDO::FETCH_NUM);
    }

    /**
     * Returns whether $target has appealed their ban
     *
     * @param string $target The ban target (UID or IP)
     * @return string|false
     */
    public function get_ban_appeal(string $target): string|false
    {
        global $db;

        $res = $db->q('SELECT appealed FROM bans WHERE target = ?', $target);
        return $res->fetchColumn();
    }

    /**
     * Determines the ban type ('uid', 'ip', 'cidr', 'wild') of $target
     *
     * @param string $target The ban target to analyze
     * @return string
     * @throws Exception If the target format is invalid
     */
    public function get_ban_type(string $target): string
    {
        if (filter_var($target, FILTER_VALIDATE_IP)) {
            return 'ip';
        } elseif (strpos($target, '*') !== false) {
            if (filter_var(str_replace('*', '0', $target), FILTER_VALIDATE_IP)) {
                return 'wild';
            }
            throw new Exception(htmlspecialchars($target) . ' is not a valid wildcard ban.');
        } elseif (strpos($target, '/') !== false) {
            list($subnet, $suffix) = explode('/', $target);
            if (ctype_digit($suffix) && $suffix <= 32 && filter_var($subnet, FILTER_VALIDATE_IP)) {
                return 'cidr';
            }
            throw new Exception('/' . htmlspecialchars($suffix) . ' is not a valid CIDR suffix or invalid IP.');
        } elseif (id_exists($target)) {
            return 'uid';
        }
        throw new Exception(htmlspecialchars($target) . ' does not seem to be a valid IP, UID or range ban.');
    }
}