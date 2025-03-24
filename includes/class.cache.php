<?php

declare(strict_types=1);

/**
 * Cache handler supporting APCu and file-based caching
 */
class Cache
{
    private const CACHE_DIR = '/cache/';
    private const CACHE_EXT = '.cache.php';

    /**
     * Fetch a cached value
     * @param string $key Cache key
     * @return mixed Cached value or false if not found
     */
    public static function fetch(string $key): mixed
    {
        if (!ENABLE_CACHING) {
            return false;
        }

        if (function_exists('apcu_fetch')) {
            return apcu_fetch($key);
        }

        $file = self::getCacheFilePath($key);
        if (file_exists($file)) {
            $cached_var = include $file;
            return $cached_var ?? false;
        }

        return false;
    }

    /**
     * Store a value in cache
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return bool Success status
     */
    public static function set(string $key, mixed $value): bool
    {
        if (!ENABLE_CACHING) {
            return false;
        }

        if (function_exists('apcu_store')) {
            return apcu_store($key, $value);
        }

        $file = self::getCacheFilePath($key);
        $content = '<?php return ' . var_export($value, true) . ';';
        try {
            return file_put_contents($file, $content, LOCK_EX) !== false;
        } catch (Exception $e) {
            trigger_error(
                "Unable to cache '{$key}': " . $e->getMessage(),
                E_USER_WARNING
            );
            return false;
        }
    }

    /**
     * Clear a cached value
     * @param string $key Cache key
     * @return bool Success status
     */
    public static function clear(string $key): bool
    {
        if (function_exists('apcu_delete')) {
            return apcu_delete($key);
        }

        $file = self::getCacheFilePath($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return true;
    }

    /**
     * Get the full path for a cache file
     * @param string $key Cache key
     * @return string File path
     */
    private static function getCacheFilePath(string $key): string
    {
        return SITE_ROOT . self::CACHE_DIR . $key . self::CACHE_EXT;
    }
}