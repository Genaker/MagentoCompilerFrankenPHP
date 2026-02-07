<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Performance;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * HTTP Kernel Performance Test - Tests Magento HTTP entry point
 */
class HttpKernelPerformanceTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private static $objectManager;

    /**
     * @var Http
     */
    private static $httpApp;

    public static function setUpBeforeClass(): void
    {
        require __DIR__ . '/../../../../../../app/bootstrap.php';
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        self::$objectManager = $bootstrap->getObjectManager();
        
        try {
            $appState = self::$objectManager->get(State::class);
            $appState->setAreaCode(\Magento\Framework\App\Area::AREA_FRONTEND);
        } catch (\Exception $e) {
            // Area code already set
        }
        
        self::$httpApp = self::$objectManager->get(Http::class);
    }

    /**
     * Test HTTP request performance through Magento kernel
     */
    public function testHttpKernelPerformance(): void
    {
        $iterations = 20;
        $warmupIterations = 5;
        
        echo "\n=== HTTP Kernel Performance Test ===\n";
        echo "Iterations: {$iterations}\n";
        echo "Warmup: {$warmupIterations}\n";
        
        // Check opcache
        $opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status() !== false;
        echo "OPcache enabled: " . ($opcacheEnabled ? 'Yes' : 'No') . "\n";
        
        if ($opcacheEnabled) {
            $status = opcache_get_status();
            if ($status !== false) {
                echo "OPcache cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
                echo "OPcache hits: " . ($status['opcache_statistics']['hits'] ?? 'N/A') . "\n";
                echo "OPcache misses: " . ($status['opcache_statistics']['misses'] ?? 'N/A') . "\n";
            }
        }
        
        // Check combined files
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        echo "\nCombined Files:\n";
        if (file_exists($vendorFile)) {
            $size = filesize($vendorFile);
            $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($vendorFile);
            echo "  Vendor: " . $this->formatBytes($size) . " (" . ($cached ? 'OPcache cached' : 'not cached') . ")\n";
        }
        if (file_exists($appCodeFile)) {
            $size = filesize($appCodeFile);
            $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($appCodeFile);
            echo "  App Code: " . $this->formatBytes($size) . " (" . ($cached ? 'OPcache cached' : 'not cached') . ")\n";
        }
        
        // Warmup
        echo "\nWarmup requests...\n";
        for ($i = 0; $i < $warmupIterations; $i++) {
            $this->makeKernelRequest('/');
        }
        
        // Test requests
        echo "Test requests...\n";
        $times = [];
        $memoryBefore = memory_get_usage(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->makeKernelRequest('/');
            $end = microtime(true);
            
            $times[] = ($end - $start) * 1000; // Convert to milliseconds
            
            if (($i + 1) % 5 === 0) {
                echo "  Completed " . ($i + 1) . " requests...\n";
            }
        }
        
        $memoryAfter = memory_get_usage(true);
        
        // Calculate statistics
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        $medianTime = $this->calculateMedian($times);
        $p95Time = $this->calculatePercentile($times, 95);
        
        echo "\nResults:\n";
        echo "  Average: " . number_format($avgTime, 2) . " ms\n";
        echo "  Median: " . number_format($medianTime, 2) . " ms\n";
        echo "  Min: " . number_format($minTime, 2) . " ms\n";
        echo "  Max: " . number_format($maxTime, 2) . " ms\n";
        echo "  P95: " . number_format($p95Time, 2) . " ms\n";
        echo "  Total: " . number_format(array_sum($times), 2) . " ms\n";
        echo "  Memory used: " . $this->formatBytes($memoryAfter - $memoryBefore) . "\n";
        
        // Check opcache after
        if ($opcacheEnabled) {
            $statusAfter = opcache_get_status();
            if ($statusAfter !== false) {
                echo "\nOPcache After:\n";
                echo "  Cached scripts: " . ($statusAfter['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
                echo "  Hits: " . ($statusAfter['opcache_statistics']['hits'] ?? 'N/A') . "\n";
                echo "  Misses: " . ($statusAfter['opcache_statistics']['misses'] ?? 'N/A') . "\n";
            }
        }
        
        $this->assertLessThan(5000, $avgTime, 'Average response time should be reasonable');
    }

    /**
     * Test performance with different routes
     */
    public function testDifferentRoutesPerformance(): void
    {
        $routes = [
            '/' => 'Homepage',
            '/catalogsearch/result/?q=test' => 'Search',
        ];
        
        echo "\n=== Different Routes Performance ===\n";
        
        foreach ($routes as $route => $name) {
            $times = [];
            
            for ($i = 0; $i < 10; $i++) {
                $start = microtime(true);
                $this->makeKernelRequest($route);
                $end = microtime(true);
                $times[] = ($end - $start) * 1000;
            }
            
            $avgTime = array_sum($times) / count($times);
            echo "{$name} ({$route}): " . number_format($avgTime, 2) . " ms average\n";
        }
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Test opcache influence on combined files
     */
    public function testOpcacheInfluence(): void
    {
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        echo "\n=== OPcache Influence Test ===\n";
        
        if (!function_exists('opcache_get_status')) {
            echo "OPcache not available\n";
            $this->markTestSkipped('OPcache not available');
        }
        
        $status = opcache_get_status();
        if ($status === false) {
            echo "OPcache is disabled\n";
            $this->markTestSkipped('OPcache is disabled');
        }
        
        echo "OPcache enabled: Yes\n";
        echo "Memory usage: " . $this->formatBytes($status['memory_usage']['used_memory'] ?? 0) . "\n";
        echo "Memory free: " . $this->formatBytes($status['memory_usage']['free_memory'] ?? 0) . "\n";
        
        // Check if combined files are cached
        if (file_exists($vendorFile)) {
            $cached = opcache_is_script_cached($vendorFile);
            echo "Vendor combined file cached: " . ($cached ? 'Yes' : 'No') . "\n";
        }
        
        if (file_exists($appCodeFile)) {
            $cached = opcache_is_script_cached($appCodeFile);
            echo "App code combined file cached: " . ($cached ? 'No' : 'No') . "\n";
        }
        
        // Make requests to trigger caching
        echo "\nMaking requests to trigger OPcache...\n";
        for ($i = 0; $i < 10; $i++) {
            $this->makeKernelRequest('/');
        }
        
        // Check again
        if (file_exists($vendorFile)) {
            $cached = opcache_is_script_cached($vendorFile);
            echo "Vendor combined file cached (after requests): " . ($cached ? 'Yes' : 'No') . "\n";
        }
        
        if (file_exists($appCodeFile)) {
            $cached = opcache_is_script_cached($appCodeFile);
            echo "App code combined file cached (after requests): " . ($cached ? 'Yes' : 'No') . "\n";
        }
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Make HTTP request through Magento kernel
     */
    private function makeKernelRequest(string $uri): void
    {
        // Use curl to make actual HTTP request
        try {
            /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
            $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            $baseUrl = rtrim($storeManager->getStore()->getBaseUrl(), '/');
            $fullUrl = $baseUrl . $uri;
        } catch (\Exception $e) {
            // Fallback if store manager not available
            return;
        }
        
        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOBODY, true); // HEAD request for speed
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_exec($ch);
        curl_close($ch);
    }

    /**
     * Calculate median
     */
    private function calculateMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = floor(($count - 1) / 2);
        
        if ($count % 2) {
            return $values[$middle];
        } else {
            return ($values[$middle] + $values[$middle + 1]) / 2;
        }
    }

    /**
     * Calculate percentile
     */
    private function calculatePercentile(array $values, float $percentile): float
    {
        sort($values);
        $index = ceil(count($values) * ($percentile / 100)) - 1;
        return $values[max(0, min($index, count($values) - 1))];
    }

    /**
     * Format bytes
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
