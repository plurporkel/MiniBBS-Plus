<?php

declare(strict_types=1);

/**
 * Builds an HTML table.
 */
class Table
{
    /** @var array<int, string> An array of headings for each column */
    private array $columns = [];

    /** @var array<int, array<int, string>> A multidimensional array of data, grouped by row */
    private array $rows = [];

    /** @var int The number of rows */
    public int $row_count = 0;

    /** @var array<int, array<string>> CSS classes for each column */
    private array $column_classes = [];

    /** @var array<int, array<string>> CSS classes for each row */
    private array $row_classes = [];

    /** @var string|null A CSS class for the table */
    private ?string $table_class = null;

    /**
     * Sets the content of each <th> cell, and optionally the primary column.
     *
     * @param array<int, string> $columns An array of values for the header cells.
     * @param int|array<int> $primary An int or array of ints numbering columns *not* to be set to class="minimal".
     * @param string|null $class A CSS class to be assigned to the table.
     */
    public function __construct(array $columns = [], int|array $primary = null, ?string $class = null)
    {
        $this->columns = $columns;
        $this->table_class = $class;

        if ($primary !== null) {
            $primary = (array) $primary;
            foreach ($primary as $key) {
                unset($columns[$key]);
            }
        }

        foreach ($columns as $key => $column) {
            $this->add_td_class($key, 'minimal');
        }
    }

    /**
     * Sets a CSS class to be applied to every cell in a particular column.
     *
     * @param int $column_number The column number
     * @param string $class The CSS class to add
     */
    public function add_td_class(int $column_number, string $class): void
    {
        $this->column_classes[$column_number][] = $class;
    }

    /**
     * Create a row of values and optionally set the row's CSS classes
     *
     * @param array<int, string> $values The row values
     * @param string|array<string> $classes The CSS classes for the row
     */
    public function row(array $values, string|array $classes = []): void
    {
        $this->rows[] = $values;

        $classes = (array) $classes;
        if ($this->row_count % 2 === 1) {
            $classes[] = 'odd';
        }
        if (!empty($classes)) {
            $this->row_classes[count($this->rows) - 1] = $classes;
        }

        $this->row_count++;
    }

    /**
     * Returns the table when object is cast as a string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->output('', true);
    }

    /**
     * If we have any rows, build and output the table. Otherwise, display $no_rows_message.
     *
     * @param string $no_rows_message Message to display if no rows
     * @param bool $return Whether to return the output instead of echoing
     * @return string|null
     */
    public function output(string $no_rows_message = '', bool $return = false): ?string
    {
        if ($return) {
            ob_start();
        }

        if ($this->row_count === 0) {
            echo '<p>' . $no_rows_message . '</p>';
        } else {
            echo '<table' . ($this->table_class ? ' class="' . $this->table_class . '"' : '') . '>';
            echo '<thead><tr>';
            foreach ($this->columns as $key => $column) {
                $class_attr = $this->column_classes[$key] ?? [];
                echo '<th' . ($class_attr ? ' class="' . implode(' ', $class_attr) . '"' : '') . '>' . $column . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($this->rows as $key => $values) {
                $row_class = $this->row_classes[$key] ?? [];
                echo '<tr' . ($row_class ? ' class="' . implode(' ', $row_class) . '"' : '') . '>';
                foreach ($values as $column_key => $value) {
                    $col_class = $this->column_classes[$column_key] ?? [];
                    echo '<td' . ($col_class ? ' class="' . implode(' ', $col_class) . '"' : '') . '>' . $value . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if ($return) {
            $table = ob_get_contents();
            ob_end_clean();
            return $table;
        }

        return null;
    }
}