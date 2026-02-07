<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Performance;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * HTTP Performance Test - Tests website entry point with opcache influence
 * Usage: vendor/bin/phpunit app/code/Genaker/Autoloader/Test/Performance/HttpPerformanceTest.php
 */
class HttpPerformanceTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private static $objectManager;

    /**
     * @var string
     */
    private $baseUrl;

    public static function setUpBeforeClass(): void
    {
        require __DIR__ . '/../../../../../../app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        
        try {
            $appState = self::$objectManager->get(State::class);
            $appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // Area code already set
        }
    }

    protected function setUp(): void
    {
        // Get base URL from store configuration
        try {
            /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
            $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            $this->baseUrl = rtrim($storeManager->getStore()->getBaseUrl(), '/');
        } catch (\Exception $e) {
            // Fallback to localhost if store config not available
            $this->baseUrl = 'http://localhost';
        }
    }

    /**
     * Test HTTP request performance with opcache
     */
    public function testHttpRequestPerformance(): void
    {
        if (!$this->isHttpAvailable()) {
            $this->markTestSkipped('HTTP server not available. Run this test against a live Magento instance.');
        }

        $testUrl = $this->baseUrl;
        $iterations = 10;
        
        echo "\n=== HTTP Performance Test ===\n";
        echo "Base URL: {$testUrl}\n";
        echo "Iterations: {$iterations}\n";
        echo "Testing with opcache...\n";
        
        // Check opcache status
        $opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status() !== false;
        echo "OPcache enabled: " . ($opcacheEnabled ? 'Yes' : 'No') . "\n";
        
        if ($opcacheEnabled) {
            $opcacheStatus = opcache_get_status();
            echo "OPcache hits: " . ($opcacheStatus['opcache_statistics']['hits'] ?? 'N/A') . "\n";
            echo "OPcache misses: " . ($opcacheStatus['opcache_statistics']['misses'] ?? 'N/A') . "\n";
        }
        
        $times = [];
        $successful = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            try {
                $ch = curl_init($testUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request for faster testing
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification
                
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($httpCode >= 200 && $httpCode < 400) {
                    $successful++;
                }
            } catch (\Exception $e) {
                // Request failed
            }
            
            $end = microtime(true);
            $times[] = ($end - $start) * 1000; // Convert to milliseconds
            
            // Small delay between requests
            usleep(100000); // 100ms
        }
        
        if (count($times) === 0) {
            $this->markTestSkipped('No successful HTTP requests');
        }
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        echo "\nResults:\n";
        echo "  Successful requests: {$successful}/{$iterations}\n";
        echo "  Average response time: " . number_format($avgTime, 2) . " ms\n";
        echo "  Min response time: " . number_format($minTime, 2) . " ms\n";
        echo "  Max response time: " . number_format($maxTime, 2) . " ms\n";
        echo "  Total time: " . number_format(array_sum($times), 2) . " ms\n";
        
        $this->assertGreaterThan(0, $successful, 'At least some requests should succeed');
        $this->assertLessThan(10000, $avgTime, 'Average response time should be reasonable');
    }

    /**
     * Test opcache statistics before and after requests
     */
    public function testOpcacheStatistics(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('OPcache not available');
        }

        $statusBefore = opcache_get_status();
        
        if ($statusBefore === false) {
            $this->markTestSkipped('OPcache is disabled');
        }

        echo "\n=== OPcache Statistics ===\n";
        echo "OPcache enabled: Yes\n";
        echo "Cached scripts: " . ($statusBefore['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
        echo "Cache hits: " . ($statusBefore['opcache_statistics']['hits'] ?? 'N/A') . "\n";
        echo "Cache misses: " . ($statusBefore['opcache_statistics']['misses'] ?? 'N/A') . "\n";
        
        if (isset($statusBefore['opcache_statistics']['hits']) && 
            isset($statusBefore['opcache_statistics']['misses'])) {
            $total = $statusBefore['opcache_statistics']['hits'] + $statusBefore['opcache_statistics']['misses'];
            if ($total > 0) {
                $hitRate = ($statusBefore['opcache_statistics']['hits'] / $total) * 100;
                echo "Hit rate: " . number_format($hitRate, 2) . "%\n";
            }
        }
        
        // Make some requests to trigger opcache
        if ($this->isHttpAvailable()) {
            for ($i = 0; $i < 5; $i++) {
                $ch = curl_init($this->baseUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification
                curl_exec($ch);
                curl_close($ch);
                usleep(50000); // 50ms delay
            }
        }
        
        $statusAfter = opcache_get_status();
        
        if ($statusAfter !== false) {
            echo "\nAfter requests:\n";
            echo "Cached scripts: " . ($statusAfter['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
            echo "Cache hits: " . ($statusAfter['opcache_statistics']['hits'] ?? 'N/A') . "\n";
            echo "Cache misses: " . ($statusAfter['opcache_statistics']['misses'] ?? 'N/A') . "\n";
        }
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Test memory usage during HTTP requests
     */
    public function testMemoryUsage(): void
    {
        if (!$this->isHttpAvailable()) {
            $this->markTestSkipped('HTTP server not available');
        }

        $memoryBefore = memory_get_usage(true);
        
        // Make several requests
        for ($i = 0; $i < 5; $i++) {
            $ch = curl_init($this->baseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification
            curl_exec($ch);
            curl_close($ch);
        }
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        echo "\n=== Memory Usage ===\n";
        echo "Memory before: " . $this->formatBytes($memoryBefore) . "\n";
        echo "Memory after: " . $this->formatBytes($memoryAfter) . "\n";
        echo "Memory used: " . $this->formatBytes($memoryUsed) . "\n";
        
        $this->assertGreaterThanOrEqual(0, $memoryUsed);
    }

    /**
     * Test combined file influence on HTTP performance
     */
    public function testCombinedFileInfluence(): void
    {
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        echo "\n=== Combined Files Influence ===\n";
        
        if (file_exists($vendorFile)) {
            $vendorSize = filesize($vendorFile);
            echo "Vendor combined file: " . $this->formatBytes($vendorSize) . "\n";
        } else {
            echo "Vendor combined file: Not found\n";
        }
        
        if (file_exists($appCodeFile)) {
            $appCodeSize = filesize($appCodeFile);
            echo "App code combined file: " . $this->formatBytes($appCodeSize) . "\n";
        } else {
            echo "App code combined file: Not found\n";
        }
        
        // Check if files are in opcache
        if (function_exists('opcache_is_script_cached')) {
            $vendorCached = file_exists($vendorFile) && opcache_is_script_cached($vendorFile);
            $appCodeCached = file_exists($appCodeFile) && opcache_is_script_cached($appCodeFile);
            
            echo "Vendor file in OPcache: " . ($vendorCached ? 'Yes' : 'No') . "\n";
            echo "App code file in OPcache: " . ($appCodeCached ? 'Yes' : 'No') . "\n";
        }
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Check if HTTP server is available
     */
    private function isHttpAvailable(): bool
    {
        if (empty($this->baseUrl) || $this->baseUrl === 'http://localhost') {
            return false;
        }
        
        try {
            $ch = curl_init($this->baseUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
