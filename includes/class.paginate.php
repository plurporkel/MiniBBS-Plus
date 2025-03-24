<?php

declare(strict_types=1);

/**
 * Handles pagination of various lists (not topics)
 */
class Paginate
{
    /** @var int The current page number. */
    public int $current = 1;

    /** @var int The maximum number of items to be displayed per page. */
    public int $limit;

    /** @var int The table offset. */
    public int $offset;

    /**
     * Constructor to set the page and LIMIT string.
     * @param int|null $page The page number (optional, defaults to $_GET['p'])
     */
    public function __construct(?int $page = null)
    {
        if ($page === null) {
            $page = $_GET['p'] ?? 1;
        }

        $this->current = max(1, filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]));
        $this->limit = ITEMS_PER_PAGE;
        $this->offset = ITEMS_PER_PAGE * ($this->current - 1);
    }

    /**
     * Generates HTML links for navigating between pages.
     * @param string $section_name The section name for the URL
     * @param int $num_items_fetched The number of items fetched on the current page
     * @return void
     */
    public function navigation(string $section_name, int $num_items_fetched): void
    {
        $output = '';

        if ($this->current > 1) {
            $output .= '<li><a href="' . DIR . $section_name . '">Latest</a></li>';
        }

        if ($this->current > 2) {
            $newer = $this->current - 1;
            $output .= '<li><a href="' . DIR . $section_name . '/' . $newer . '">Newer</a></li>';
        }

        if ($num_items_fetched === ITEMS_PER_PAGE) {
            $older = $this->current + 1;
            $output .= '<li><a href="' . DIR . $section_name . '/' . $older . '">Older</a></li>';
        }

        if (!empty($output)) {
            echo "\n" . '<ul class="menu">' . $output . "\n" . '</ul>' . "\n";
        }
    }
}