<?php

declare(strict_types=1);

/**
 * Handles user input and PHP errors. Accessed statically.
 */
class ErrorHandler
{
    /** @var array<string> An array of error message strings. */
    private static array $errors = [];

    private static array $levels = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parsing error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core error',
        E_CORE_WARNING => 'Core warning',
        E_COMPILE_ERROR => 'Compile error',
        E_COMPILE_WARNING => 'Compile warning',
        E_USER_ERROR => 'User error',
        E_USER_WARNING => 'User warning',
        E_USER_NOTICE => 'User notice',
        E_STRICT => 'Runtime notice',
    ];

    /**
     * Handles errors issued by PHP or trigger_error()
     * @param int $level Error level
     * @param string $message Error message
     * @param string $file File where error occurred
     * @param int $line Line number
     * @return void
     */
    public static function errorHandler(int $level, string $message, string $file, int $line): void
    {
        global $perm;

        if ($level === E_NOTICE || $level === E_STRICT) {
            return;
        }

        $isAdmin = is_object($perm) && $perm->is_admin();
        $detailedErrors = PHP_ERROR_SHOW || $isAdmin;

        if ($detailedErrors) {
            $message = sprintf(
                '<strong>%s (%d):</strong> %s in <strong>%s</strong> on line <strong>%d</strong>',
                self::$levels[$level] ?? 'Unknown',
                $level,
                htmlspecialchars(strip_tags($message), ENT_QUOTES, 'UTF-8'),
                htmlspecialchars(str_replace(SITE_ROOT, '', $file), ENT_QUOTES, 'UTF-8'),
                $line
            );
        } else {
            $message = PHP_ERROR_MESSAGE;
        }

        self::fatal($message);
    }

    /**
     * Handles exceptions (usually issued by the database)
     * @param Throwable $exception The exception to handle
     * @return void
     */
    public static function exceptionHandler(Throwable $exception): void
    {
        global $perm;

        $isAdmin = is_object($perm) && $perm->is_admin();
        $detailedErrors = PHP_ERROR_SHOW || $isAdmin;

        $message = match (get_class($exception)) {
            'DatabaseConnectionException' => 'Failed to establish a connection to the database.',
            'PDOException' => DB_ERROR_MESSAGE,
            default => PHP_ERROR_MESSAGE,
        };

        if ($detailedErrors) {
            $message .= ' Message: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
            if ($isAdmin && !$exception instanceof DatabaseConnectionException) {
                // TODO: Output pretty trace here.
            }
        }

        self::fatal($message);
    }

    /**
     * Adds an error message, which may be printed later
     * @param string $message Error message
     * @return void
     */
    public static function add(string $message): void
    {
        self::$errors[] = $message;
    }

    /**
     * Prints an error message and kills the script
     * @param string $message Fatal error message
     * @return never
     */
    public static function fatal(string $message): never
    {
        global $template;

        self::add($message);
        self::output();

        if (is_object($template)) {
            $template->title = 'Fatal error';
            if (defined('DEFAULT_MENU')) {
                $template->render();
            } else {
                $template->render('fallback');
            }
        }

        exit;
    }

    /**
     * Returns true if no errors have been issued
     * @return bool
     */
    public static function valid(): bool
    {
        return empty(self::$errors);
    }

    /**
     * If any errors are registered, print them.
     * @return void
     */
    public static function output(): void
    {
        if (empty(self::$errors)) {
            return;
        }

        echo '<h3 id="error">Error</h3><ul class="body standalone">';
        foreach (self::$errors as $error_message) {
            echo '<li>' . htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }

    /**
     * Clears all registered errors
     * @return void
     */
    public static function clear(): void
    {
        self::$errors = [];
    }
}