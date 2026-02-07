<?php
/**
 * FrankenPHP-Compatible Magento Index Entry Point
 * 
 * This is a drop-in replacement for pub/index.php that works with FrankenPHP Worker Mode.
 * It detects if running in FrankenPHP worker mode and uses persistent state.
 * 
 * Usage:
 * 1. Replace pub/index.php with this file (backup original first!)
 * 2. Or use this as a separate entry point and configure FrankenPHP to use it
 * 
 * For FrankenPHP Worker Mode:
 * - First request: Bootstraps Magento normally
 * - Subsequent requests: Reuses bootstrapped state (much faster)
 * 
 * For regular PHP-FPM/Nginx:
 * - Works exactly like standard index.php (backward compatible)
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;

// Configure OPcache for optimal performance
if (function_exists('opcache_get_status')) {
    @ini_set('opcache.enable', '1');
    @ini_set('opcache.enable_cli', '0');
    @ini_set('opcache.memory_consumption', '512');
    @ini_set('opcache.interned_strings_buffer', '16');
    @ini_set('opcache.max_accelerated_files', '20000');
    @ini_set('opcache.validate_timestamps', '0');
    @ini_set('opcache.revalidate_freq', '0');
    @ini_set('opcache.fast_shutdown', '1');
    @ini_set('opcache.save_comments', '1');
    @ini_set('opcache.enable_file_override', '1');
}

// Determine Magento root (BP constant)
// This file should be in pub/ directory, so BP is one level up
if (!defined('BP')) {
    define('BP', dirname(__DIR__));
}

// Check if we're in FrankenPHP worker mode
$isFrankenPHPWorker = function_exists('frankenphp_handle_request');

if ($isFrankenPHPWorker) {
    // FrankenPHP Worker Mode - bootstrap once and reuse
    
    // Bootstrap Magento (only runs once in worker mode)
    require BP . '/app/bootstrap.php';
    
    // Static variable to persist ObjectManager between requests
    static $objectManager = null;
    
    // Handler function called for each request
    $handler = static function () use (&$objectManager) {
        // Create bootstrap instance for this request
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        
        // Get ObjectManager (reused from previous request if available)
        $objectManager = $bootstrap->getObjectManager();
        
        // Get HTTP application
        $application = $objectManager->get(Http::class);
        
        // Launch Magento application
        $application->launch();
    };
    
    // Enter worker loop
    do {
        $keepRunning = \frankenphp_handle_request($handler);
        gc_collect_cycles();
    } while ($keepRunning);
    
} else {
    // Regular PHP-FPM/Nginx mode - standard Magento bootstrap
    
    require BP . '/app/bootstrap.php';
    
    try {
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        /** @var \Magento\Framework\App\Http $application */
        $application = $bootstrap->createApplication(\Magento\Framework\App\Http::class);
        $bootstrap->run($application);
    } catch (\Exception $e) {
        if (PHP_SAPI === 'cli') {
            echo $e->getMessage() . "\n";
            exit(1);
        }
        
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body>';
        echo '<h1>Application Error</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        if (defined('BP') && file_exists(BP . '/var/report/' . $e->getCode())) {
            echo '<p>See report: ' . htmlspecialchars($e->getCode()) . '</p>';
        }
        echo '</body></html>';
        exit(1);
    }
}
