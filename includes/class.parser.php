<?php

declare(strict_types=1);

/**
 * The parser and related methods. Accessed statically.
 */
class Parser
{
    private const MARKUP = [
        // Bold
        '/\[b\](.*?)\[\/b\]/is',
        "/'''(.+?)'''/",
        // Italic
        '/\[i\](.*?)\[\/i\]/is',
        "/''(.+?)''/",
        // Spoiler
        '/\[spoiler\](.*?)\[\/spoiler\]/is',
        '/\*\*(.*?)\*\*/is',
        // Underline
        '/\[u\](.*?)\[\/u\]/is',
        // Strikethrough
        '/\[s\](.*?)\[\/s\]/is',
        // Linkify URLs
        '@\b(?<!\[)(https?|ftp)://(www\.)?([A-Z0-9.-]+)(/)?([A-Z0-9/&#+%~=_|?.,!:;-]*[A-Z0-9/&#+%=~_|])?@i',
        // Linkify text in the form of [http://example.org text]
        '@\[(https?:\/\/[\@a-z0-9\x21\x23-\x27\x2a-\x2e\x3a\x3b\/;\x3f-\x7a\x7e\x3d]+) (.+?)\]@i',
        // Quotes
        '/^&gt;(.*)$/m',
        // Headers
        '/\[h\](.+?)\[\/h\]/m',
        '/==(.+?)==\s+?/m',
        // Bordered text
        '/\[border\](.+?)\[\/border\]/ms',
        // Convert double dash to em dash
        '/--/',
        // Highlights
        '/\[hl\](.+?)\[\/hl\]/ms',
        // Monospace
        '/\[code\](.+?)\[\/code\]/ms',
        // Shift-JIS
        '/\[aa\](.+?)\[\/aa\]/ms',
    ];

    private const REPLACEMENTS = [
        '<strong>$1</strong>', // Bold
        '<strong>$1</strong>', // Bold
        '<em>$1</em>', // Italic
        '<em>$1</em>', // Italic
        '<span class="spoiler">$1</span>', // Spoiler
        '<span class="spoiler">$1</span>', // Spoiler
        '<u>$1</u>', // Underline
        '<s>$1</s>', // Strikethrough
        '<a href="$0" rel="nofollow">$0</a>', // Linkify URLs
        '<a href="$1" title="$1" rel="nofollow">$2</a>', // Linkify [url text]
        '<span class="quote"><strong>&gt;</strong> $1</span>', // Quotes
        '<h4 class="user">$1</h4>', // Headers
        '<h4 class="user">$1</h4>', // Headers
        '<div class="border">$1</div>', // Bordered text
        '—', // Double dash to em dash
        '<span class="highlight">$1</span>', // Highlights
        '<pre style="display:inline">$1</pre>', // Monospace
        '<pre class="shift_jis">$1</pre>', // Shift-JIS
    ];

    /**
     * Converts user input to HTML
     * @param string $text The text to parse
     * @param int|null $uid The user ID for signature parsing
     * @return string The parsed HTML
     */
    public static function parse(string $text, ?int $uid = null): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $text = str_replace("\r", '', $text);

        // Temporarily remove content between [noparse] tags
        $noparseBlocks = [];
        $text = preg_replace_callback('/\[noparse\](.*?)\[\/noparse\]/s', function ($matches) use (&$noparseBlocks) {
            $noparseBlocks[] = str_replace('@', '&#64;', $matches[1]);
            return '[noparse][/noparse]';
        }, $text);

        // Replace markup with HTML
        $text = preg_replace(self::MARKUP, self::REPLACEMENTS, $text);

        // Parse user signatures (~~~~)
        if (SIGNATURES && $uid !== null && strpos($text, '~~~~') !== false) {
            $text = self::parseSignature($text, $uid);
        }

        // Parse PHP tags
        if (strpos($text, '[php]') !== false) {
            $text = preg_replace_callback('|\[php\](.+?)\[/php\]|ms', [self::class, 'highlightPhp'], $text);
        }

        // Parse tables
        if (strpos($text, "\n|") !== false) {
            $text = self::table($text);
        }

        // Add [play] links for streaming videos
        if (EMBED_VIDEOS) {
            $text = self::embedVideos($text);
        }

        // Restore [noparse] content
        if (!empty($noparseBlocks)) {
            $chunks = explode('[noparse][/noparse]', $text);
            $text = '';
            foreach ($chunks as $key => $chunk) {
                $text .= $chunk;
                if (isset($noparseBlocks[$key])) {
                    $text .= $noparseBlocks[$key];
                }
            }
        }

        $text = nl2br($text);

        // Fix <pre> tags
        if (strpos($text, '<pre') !== false) {
            $text = preg_replace_callback('|\<pre(.+?)\</pre\>|s', [self::class, 'fixPreTags'], $text);
        }

        return $text;
    }

    /**
     * Removes unnecessary HTML linebreaks from <pre> tags
     * @param array $matches Regex matches
     * @return string Fixed <pre> tag
     */
    private static function fixPreTags(array $matches): string
    {
        return str_replace('<br />', '', $matches[0]);
    }

    /**
     * Highlights code between [php] tags
     * @param array $matches Regex matches
     * @return string Highlighted PHP code
     */
    private static function highlightPhp(array $matches): string
    {
        $text = '<?php ' . trim($matches[1], "\r\n");
        $text = highlight_string(html_entity_decode($text), true);
        $text = preg_replace('/<span style="color: #([A-Z0-9]+)">&lt;\?php(&nbsp;| )/i', '<span style="color: #$1">', $text);
        $text = substr_replace($text, '', strpos($text, "\n"), 1);
        $text = substr_replace($text, '', strrpos($text, "\n"), 1);
        return '<div class="php">' . $text . '</div>';
    }

    /**
     * Parses user signatures (~~~~)
     * @param string $text The text to parse
     * @param int $uid The user ID
     * @return string The text with signatures replaced
     */
    private static function parseSignature(string $text, int $uid): string
    {
        $hash = sha1($uid . TRIP_SEED);
        $colors = str_split(substr($hash, 0, 24), 6);
        $percents = array_map('hexdec', str_split(substr($hash, 0, 8), 2));
        $weight = 100 / array_sum($percents);
        $percents = array_map(fn($p) => $p * $weight, $percents);

        $signature = '<span class="signature help" title="' . $hash . '">';
        foreach ($colors as $key => $color) {
            $signature .= '<span class="signature_part" style="width:' . $percents[$key] . '%; background-color:#' . $color . ';"></span>';
        }
        $signature .= '</span>';

        return str_replace('~~~~', $signature, $text);
    }

    /**
     * Embeds video links
     * @param string $text The text to parse
     * @return string The text with video links embedded
     */
    private static function embedVideos(string $text): string
    {
        if (strpos($text, 'youtube.com') !== false) {
            $text = preg_replace(
                "/(<a href=\"https?:\/\/(www\.)?youtube\.com\/watch\?([&;a-zA-Z0-9=_]+)?v=([^',\.& \t\r\n\v\f]+)([^',\. \t\r\n\v\f]+)?\"([^<]*)*<\/a>)/",
                "\\1 [<a href=\"javascript:void(0);\" onclick=\"play_video('youtube','\\4', this, '$record_class', '$record_ID');\" class=\"video youtube\">play</a>]",
                $text
            );
        }
        if (strpos($text, 'vimeo.com') !== false) {
            $text = preg_replace(
                "/(<a href=\"http:\/\/(www\.)?vimeo\.com\/([0-9]+)([^',\. \t\r\n\v\f]+)?\"([^<]*)*<\/a>)/",
                "\\1 [<a href=\"javascript:void(0);\" onclick=\"play_video('vimeo','\\3', this, '$record_class', '$record_ID');\" class=\"video vimeo\">play</a>]",
                $text
            );
        }
        return $text;
    }

    /**
     * Transforms table mark-up into HTML
     * @param string $post The post text
     * @param int $recurseLevel Recursion level
     * @return string The parsed text
     */
    private static function table(string $post, int $recurseLevel = 0): string
    {
        $columns = [];
        $mainColumns = [];
        $rows = [];

        $delim = "\n|";
        if ($post[0] === '|') {
            $delim = '|';
        }
        list($beforeTable, $remainder) = explode($delim, $post, 2);
        $afterTable = '';
        $remainder = '|' . $remainder;

        $tableLines = explode("\n", $remainder);

        foreach ($tableLines as $row => $line) {
            if ($line[0] !== '|') {
                array_splice($tableLines, 0, $row);
                $afterTable = implode("\n", $tableLines);
                break;
            }

            if ($row === 0 && $line[1] === '|') {
                $columns = explode('||', $line);
                array_shift($columns);
                foreach ($columns as $key => $column) {
                    if ($column[0] === '!') {
                        $columns[$key] = ltrim($column, '!');
                        $mainColumns[] = $key;
                    }
                }
            } else {
                $cells = explode('|', $line);
                array_shift($cells);
                $rows[$row] = $cells;
            }
        }

        if (!empty($rows) && count($rows) < 700) {
            $table = new Table($columns, $mainColumns, empty($mainColumns) ? 'minimal_table' : null);
            foreach ($rows as $row) {
                $table->row($row);
            }
            $table = str_replace(["\r", "\n"], '', (string)$table);
            $post = $beforeTable . $table . $afterTable;

            if ($recurseLevel < 6 && strpos($post, "\n|") !== false) {
                $post = self::table($post, $recurseLevel + 1);
            }
        }

        return $post;
    }

    /**
     * Condenses text into a shorter string
     * @param string $text The text to snippet
     * @param int $snippetLength The length of the snippet
     * @return string The snippet
     */
    public static function snippet(string $text, int $snippetLength = 80): string
    {
        if ($text === '') {
            return '~';
        }

        $text = preg_replace('/(@|>)(.*)/m', ' ~ ', $text);
        $text = preg_replace('/ ~ [\s~]+/', ' ~ ', $text);
        $text = preg_replace(self::MARKUP, '$1', $text);
        $text = str_replace(["\r", "\n"], ' ', $text);
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if (ctype_digit((string)($_SESSION['settings']['snippet_length'] ?? ''))) {
            $snippetLength = (int)$_SESSION['settings']['snippet_length'];
        }

        if (strlen($text) > $snippetLength) {
            $text = substr($text, 0, $snippetLength) . '&hellip;';
        }

        return $text;
    }
}