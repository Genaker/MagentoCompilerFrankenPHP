<?php
/**
 * Magento Worker Script for FrankenPHP Worker Mode
 * 
 * This script boots Magento once and stays in memory.
 * FrankenPHP Worker Mode keeps this script running between requests,
 * providing persistent application state.
 * 
 * Usage: Configure in FrankenPHP with --worker flag
 * Example: frankenphp php-server --worker magento_worker.php --workers 4
 */

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;

// Determine Magento root (BP constant)
// Inside Docker: script is at /app/config/magento_worker.php, Magento is at /app/public
// Outside Docker: script is in app/code/Genaker/Autoloader/Tools/franken-php-go/, Magento is 4 levels up
if (file_exists('/app/public/app/bootstrap.php')) {
    // Docker environment
    $magentoRoot = '/app/public';
} else {
    // Local/Warden environment - go up 4 levels from script directory
    $magentoRoot = realpath(__DIR__ . '/../../../../');
    if (!$magentoRoot) {
        die("ERROR: Cannot find Magento root directory\n");
    }
}

// Set Magento base path
define('BP', $magentoRoot);

// Magento bootstrap
require BP . '/app/bootstrap.php';

// Get ObjectManager factory (persists between requests in Worker Mode)
// We'll create bootstrap per request but reuse ObjectManager when possible
$objectManager = null;

// Define request handler - this is called for each HTTP request
// Superglobals ($_SERVER, $_GET, etc.) are reset for each request by FrankenPHP
$handler = static function () use (&$objectManager) {
    // Create bootstrap instance for this request
    // $_SERVER is populated by FrankenPHP with request data
    $bootstrap = Bootstrap::create(BP, $_SERVER);
    
    // Get ObjectManager (may reuse cached instance in worker mode)
    $objectManager = $bootstrap->getObjectManager();
    
    // Get HTTP application
    $application = $objectManager->get(Http::class);
    
    // Launch Magento application
    // This processes the current HTTP request
    $application->launch();
    
    // Cleanup after request (but ObjectManager stays in memory)
    // Magento will handle its own cleanup via shutdown handlers
};

// Worker loop - FrankenPHP handles request routing
// frankenphp_handle_request() processes each incoming request
// Check if function exists (only available in worker mode)
if (function_exists('frankenphp_handle_request')) {
    do {
        $keepRunning = \frankenphp_handle_request($handler);
        // Garbage collection after each request
        gc_collect_cycles();
    } while ($keepRunning);
} else {
    // Fallback: if not in worker mode, just run once
    // This shouldn't happen in proper worker mode setup
    $handler();
}
