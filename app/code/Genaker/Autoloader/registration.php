<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Genaker_Autoloader',
    __DIR__
);

// Register Composer autoload classmap early
(function () {
    // Cache root directory calculation
    static $rootDir = null;
    if ($rootDir === null) {
        $rootDir = dirname(dirname(dirname(__DIR__)));
    }
    
    // Pre-compute file paths (check for compiled versions first)
    $combinedFile = $rootDir . '/var/combined_vendor_classes.php';
    $combinedFileCompiled = $rootDir . '/var/combined_vendor_classes_compiled.php';
    $appCodeCombinedFile = $rootDir . '/var/combined_app_code_classes.php';
    $appCodeCombinedFileCompiled = $rootDir . '/var/combined_app_code_classes_compiled.php';
    $classMapFile = $rootDir . '/vendor/composer/autoload_classmap.php';
    
    // Use compiled files if they exist, otherwise use regular combined files
    if (file_exists($combinedFileCompiled)) {
        $combinedFile = $combinedFileCompiled;
    }
    if (file_exists($appCodeCombinedFileCompiled)) {
        $appCodeCombinedFile = $appCodeCombinedFileCompiled;
    }
    
    // Check if autoloader is enabled (cached check)
    // Priority: 1. Environment variable, 2. env.php config, 3. Default: enabled
    static $isEnabled = null;
    if ($isEnabled === null) {
        $isEnabled = true; // Default: enabled
        
        // Check environment variable first (fastest)
        $envValue = getenv('GENAKER_AUTOLOADER_ENABLED');
        if ($envValue !== false) {
            $isEnabled = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        } else {
            // Check env.php configuration (cache result in static)
            static $envConfigCache = null;
            if ($envConfigCache === null) {
                $envFile = $rootDir . '/app/etc/env.php';
                if (file_exists($envFile)) {
                    try {
                        $envConfigCache = include $envFile;
                    } catch (\Exception $e) {
                        $envConfigCache = false; // Mark as failed to avoid retry
                    }
                } else {
                    $envConfigCache = false; // File doesn't exist
                }
            }
            
            if ($envConfigCache !== false && isset($envConfigCache['genaker_autoloader']['enabled'])) {
                $isEnabled = (bool)$envConfigCache['genaker_autoloader']['enabled'];
            }
        }
    }
    
    // If disabled, skip autoloader registration
    if (!$isEnabled) {
        return;
    }
    
    // Load app/code combined file first (if exists) - single file load is fastest
    if (file_exists($appCodeCombinedFile)) {
        require_once $appCodeCombinedFile;
    }
    
    // Try to use vendor combined file first (fastest, single file)
    if (file_exists($combinedFile)) {
        require_once $combinedFile;
        return; // Early return - combined file loaded, no need for fallback
    }
    
    // Fallback to classmap file if combined file doesn't exist
    if (file_exists($classMapFile)) {
        // Pre-compute vendor and base directories
        $vendorDir = dirname(dirname($classMapFile));
        $baseDir = dirname($vendorDir);
        
        // Load classmap in isolated scope
        $classMap = (function () use ($classMapFile, $vendorDir, $baseDir) {
            return include $classMapFile;
        })();
        
        // Only register if we have classes
        if (!empty($classMap) && is_array($classMap)) {
            // Optimize: use isset() check before file_exists() for better performance
            spl_autoload_register(function ($className) use ($classMap) {
                if (!isset($classMap[$className])) {
                    return false;
                }
                
                $file = $classMap[$className];
                // Use require_once directly - file_exists() check is redundant if classmap is correct
                // and adds overhead. If file doesn't exist, require_once will fail gracefully.
                require_once $file;
                return true;
            }, true, false); // Prepend to autoload stack
        }
    }
})();
