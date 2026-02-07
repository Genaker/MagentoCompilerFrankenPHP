<?php
/**
 * GoGentoServerGo Helper Functions
 * 
 * Provides PHP functions to interact with the Go runtime's variable cache
 */

if (!function_exists('franken_cache_set')) {
    /**
     * Store a variable in the persistent cache
     * 
     * @param string $key Variable key
     * @param mixed $value Variable value (will be serialized)
     * @return bool Success status
     */
    function franken_cache_set(string $key, $value): bool {
        // Get cached variables from environment
        $cacheJson = getenv('GOGENTO_CACHE_' . $key);
        if ($cacheJson !== false) {
            // Variable exists, update it
            // In worker mode, this will persist across requests
            $_ENV['GOGENTO_CACHE_' . $key] = serialize($value);
            return true;
        }
        
        // Store in environment for Go runtime to pick up
        $_ENV['GOGENTO_CACHE_' . $key] = serialize($value);
        putenv('GOGENTO_CACHE_' . $key . '=' . serialize($value));
        
        return true;
    }
}

if (!function_exists('franken_cache_get')) {
    /**
     * Retrieve a variable from the persistent cache
     * 
     * @param string $key Variable key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed Cached value or default
     */
    function franken_cache_get(string $key, $default = null) {
        $cacheJson = getenv('GOGENTO_CACHE_' . $key);
        if ($cacheJson !== false) {
            $value = @unserialize($cacheJson);
            if ($value !== false) {
                return $value;
            }
        }
        
        return $default;
    }
}

if (!function_exists('franken_cache_delete')) {
    /**
     * Delete a variable from the persistent cache
     * 
     * @param string $key Variable key
     * @return bool Success status
     */
    function franken_cache_delete(string $key): bool {
        unset($_ENV['GOGENTO_CACHE_' . $key]);
        putenv('GOGENTO_CACHE_' . $key);
        return true;
    }
}

if (!function_exists('franken_cache_clear')) {
    /**
     * Clear all cached variables
     * 
     * @return bool Success status
     */
    function franken_cache_clear(): bool {
        foreach ($_ENV as $key => $value) {
            if (strpos($key, 'GOGENTO_CACHE_') === 0) {
                unset($_ENV[$key]);
                putenv($key);
            }
        }
        return true;
    }
}

if (!function_exists('franken_cache_stats')) {
    /**
     * Get cache statistics
     * 
     * @return array Cache statistics
     */
    function franken_cache_stats(): array {
        $statsJson = getenv('GOGENTO_CACHE_STATS');
        if ($statsJson !== false) {
            $stats = @json_decode($statsJson, true);
            if (is_array($stats)) {
                return $stats;
            }
        }
        
        return [
            'total_variables' => 0,
            'expired' => 0,
            'active' => 0,
            'ttl_seconds' => 1800,
        ];
    }
}

if (!function_exists('franken_is_worker_mode')) {
    /**
     * Check if running in worker mode
     * 
     * @return bool True if worker mode, false if classic mode
     */
    function franken_is_worker_mode(): bool {
        return getenv('GOGENTO_MODE') === 'worker';
    }
}

// Auto-load helper functions in worker mode
if (getenv('GOGENTO_MODE') === 'worker') {
    // Application stays bootstrapped between requests
    // Variables persist across requests
    register_shutdown_function(function() {
        // Save any modified variables back to cache
        foreach ($_ENV as $key => $value) {
            if (strpos($key, 'GOGENTO_CACHE_') === 0 && strpos($key, 'GOGENTO_CACHE_STATS') !== 0) {
                // Variable was modified, ensure it persists
                putenv($key . '=' . $value);
            }
        }
    });
}
