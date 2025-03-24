<?php
declare(strict_types=1);

/**
 * Prepares and renders the page template.
 */
class Template {
    public string $title = '';
    public string $head = '';
    public string $onload = '';
    public string|false $style_override = false;
    private string $content = '';
    private float $start_time;

    /** @var array<string, string> */
    public array $menu_options = [
        'Hot'       => 'hot_topics',
        'Topics'    => 'topics',
        'Bumps'     => 'bumps',
        'Replies'   => 'replies',
        'New topic' => 'new_topic',
        'Watchlist' => 'watchlist',
        'Bulletins' => 'bulletins',
        'Activity'  => 'activity',
        'Search'    => 'search',
        'Stuff'     => 'stuff',
        'You'       => 'history',
    ];

    /** @var array<string, array<string, string>> */
    public array $menu_children = [
        'You' => [
            'Dashboard'  => 'dashboard',
            'Inbox'      => 'private_messages',
            'Restore ID' => 'restore_ID',
        ],
    ];

    public function __construct(?float $start_time = null) {
        $this->start_time = $start_time ?? microtime(true);
        ob_start();
    }

    public function render(string|bool $template = 'default'): never {
        if ($this->content !== '') {
            throw new RuntimeException('Template is already rendered.');
        }

        $this->content = ob_get_clean();
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
        $template = $is_ajax ? 'ajax' : $template;

        if (defined('MOD_GZIP') && MOD_GZIP) {
            ob_start('ob_gzhandler');
        }

        if ($template === false) {
            echo $this->content;
        } else {
            require SITE_ROOT . '/includes/template.' . $template . '.php';
        }
        exit;
    }

    public function get_stylesheet(): string {
        if ($this->style_override !== false) {
            return $this->style_override;
        }
        $user_style = $_SESSION['settings']['style'] ?? '';
        return file_exists(SITE_ROOT . '/style/themes/' . $user_style . '.css') ? $user_style : DEFAULT_STYLESHEET;
    }

    /** @return array<string, string> */
    public function get_default_menu(): array {
        return $this->compile_menu(DEFAULT_MENU);
    }

    /** @return array<string, string> */
    public function get_user_menu(): array {
        global $notifications;
        $main_menu = !empty($_SESSION['settings']['custom_menu'])
            ? $this->compile_menu($_SESSION['settings']['custom_menu'])
            : $this->get_default_menu();

        if (!empty($notifications['reports'])) {
            $main_menu = ['Reports' => 'reports'] + $main_menu;
        }
        return $main_menu;
    }

    /** @return array<string, string> */
    private function compile_menu(string $string, bool $recurse = true): array {
        $menu = [];
        $custom_url = null;
        $custom_text = null;
        $submenus = [];

        foreach (explode(' ', trim(htmlspecialchars($string))) as $token) {
            if ($token === 'New_topic') $token = 'New topic';

            if (isset($this->menu_options[$token])) {
                $menu[$token] = $this->menu_options[$token];
            } elseif (str_starts_with($token, '[')) {
                if ($custom_text !== null) $custom_text = null;
                $custom_url = ltrim($token, '[/');
            } elseif ($custom_url !== null) {
                $custom_text = ($custom_text ?? '') . ' ' . $token;
                if (str_ends_with($token, ']')) {
                    $menu[trim($custom_text, ']')] = $custom_url;
                    $custom_url = $custom_text = null;
                }
            } elseif ($recurse && str_starts_with($token, '{') && !empty($menu)) {
                $submenu = ltrim($token, '{');
                $next = strtok('}');
                while ($next !== false) {
                    $submenu .= ' ' . $next;
                    $next = strtok('}');
                }
                $submenus[end($menu)] = trim($submenu);
            }
        }

        foreach ($submenus as $parent => $submenu) {
            $this->menu_children[$parent] = $this->compile_menu($submenu, false);
        }
        return $menu;
    }

    public function mark_new(string $text, string $path): string {
        global $last_actions, $notifications;

        return match ($path) {
            'bumps', 'topics', 'bulletins' => $this->mark_new_simple($text, "last_" . rtrim($path, 's'), $last_actions),
            'history' => $notifications['citations'] ?? 0 > 0
                ? '<span class="new_items">' . $text . ' <em><a href="' . DIR . 'citations" class="help" title="' . $notifications['citations'] . ' new repl' . ($notifications['citations'] > 1 ? 'ies' : 'y') . ' to your replies">(' . number_format($notifications['citations']) . ')</a></em></span>'
                : $text,
            'watchlist', 'reports', 'private_messages' => !empty($notifications[$path === 'private_messages' ? 'pms' : $path])
                ? '<span class="new_items">' . $text . ' <em>(' . number_format($notifications[$path === 'private_messages' ? 'pms' : $path]) . ')</em></span>'
                : $text,
            default => $text,
        };
    }

    private function mark_new_simple(string $text, string $cookie_name, array $last_actions): string {
        return (isset($_COOKIE[$cookie_name]) && (int)$_COOKIE[$cookie_name] < ($last_actions[$cookie_name] ?? 0))
            ? '<span class="new_items">' . $text . '<em>!</em></span>'
            : $text;
    }

    /** @return array{total_time: float, query_time: float, query_count: int, query_percent: int} */
    public function get_stats(): array {
        global $db;
        $total_time = round(microtime(true) - $this->start_time, 3);
        $query_time = $db->query_time();
        $query_count = $db->query_count();
        return [
            'total_time' => $total_time,
            'query_time' => $query_time,
            'query_count' => $query_count,
            'query_percent' => $total_time > 0 ? (int)round($query_time * 100 / $total_time) : 0,
        ];
    }
}