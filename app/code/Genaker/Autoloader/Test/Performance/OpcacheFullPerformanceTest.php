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
 * Full OPcache Performance Test
 * Comprehensive performance comparison with optimization enabled vs disabled
 */
class OpcacheFullPerformanceTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private static $objectManager;

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
    }

    /**
     * Comprehensive performance test comparing optimization settings
     */
    public function testFullPerformanceComparison(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('OPcache not available');
        }

        echo "\n" . str_repeat("=", 70) . "\n";
        echo "COMPREHENSIVE OPCACHE PERFORMANCE COMPARISON\n";
        echo str_repeat("=", 70) . "\n";

        // Get base URL for HTTP tests
        try {
            /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
            $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            $baseUrl = rtrim($storeManager->getStore()->getBaseUrl(), '/');
        } catch (\Exception $e) {
            $baseUrl = null;
        }

        // Test 1: Class Loading Performance
        echo "\n--- Test 1: Class Loading Performance ---\n";
        $classResults = $this->testClassLoadingPerformance();
        
        // Test 2: HTTP Request Performance
        if ($baseUrl) {
            echo "\n--- Test 2: HTTP Request Performance ---\n";
            $httpResults = $this->testHttpRequestPerformance($baseUrl);
        }
        
        // Test 3: OPcache Statistics
        echo "\n--- Test 3: OPcache Statistics ---\n";
        $opcacheStats = $this->testOpcacheStatistics();
        
        // Test 4: Combined File Loading
        echo "\n--- Test 4: Combined File Status ---\n";
        $combinedFileStats = $this->testCombinedFileStatus();
        
        // Summary
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "SUMMARY\n";
        echo str_repeat("=", 70) . "\n";
        
        echo "\nClass Loading:\n";
        echo "  With Optimization: " . number_format($classResults['optimized']['avg'], 4) . " ms\n";
        echo "  Without Optimization: " . number_format($classResults['unoptimized']['avg'], 4) . " ms\n";
        $classImprovement = (($classResults['unoptimized']['avg'] - $classResults['optimized']['avg']) / $classResults['unoptimized']['avg']) * 100;
        echo "  Improvement: " . number_format($classImprovement, 2) . "% " . ($classImprovement > 0 ? 'faster' : 'slower') . "\n";
        
        if ($baseUrl && isset($httpResults)) {
            echo "\nHTTP Requests:\n";
            echo "  With Optimization: " . number_format($httpResults['optimized']['avg'], 2) . " ms\n";
            echo "  Without Optimization: " . number_format($httpResults['unoptimized']['avg'], 2) . " ms\n";
            $httpImprovement = (($httpResults['unoptimized']['avg'] - $httpResults['optimized']['avg']) / $httpResults['unoptimized']['avg']) * 100;
            echo "  Improvement: " . number_format($httpImprovement, 2) . "% " . ($httpImprovement > 0 ? 'faster' : 'slower') . "\n";
        }
        
        echo "\nOPcache Status:\n";
        echo "  Cached Scripts: " . ($opcacheStats['cached_scripts'] ?? 'N/A') . "\n";
        echo "  Memory Used: " . ($opcacheStats['memory_used'] ?? 'N/A') . "\n";
        echo "  Hit Rate: " . ($opcacheStats['hit_rate'] ?? 'N/A') . "%\n";
        
        echo "\nCombined Files:\n";
        echo "  Vendor File: " . ($combinedFileStats['vendor_size'] ?? 'N/A') . " (" . ($combinedFileStats['vendor_cached'] ? 'cached' : 'not cached') . ")\n";
        echo "  App Code File: " . ($combinedFileStats['app_code_size'] ?? 'N/A') . " (" . ($combinedFileStats['app_code_cached'] ? 'cached' : 'not cached') . ")\n";
        
        echo "\n" . str_repeat("=", 70) . "\n";
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Test class loading performance with different optimization settings
     */
    private function testClassLoadingPerformance(): array
    {
        $testClasses = [
            \Magento\Framework\App\ObjectManager::class,
            \Magento\Framework\App\State::class,
            \Magento\Store\Model\StoreManagerInterface::class,
            \Magento\Framework\App\Config\ScopeConfigInterface::class,
            \Magento\Catalog\Model\Product::class,
            \Magento\Customer\Model\Customer::class,
            \Genaker\Autoloader\Model\ComposerAutoloader::class,
            \GuzzleHttp\Client::class,
            \Symfony\Component\Console\Application::class,
            \Magento\Framework\HTTP\Client\Curl::class,
        ];
        
        $iterations = 200;
        $warmupIterations = 50;
        
        // Test with optimization enabled
        $this->configureOpcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        
        // Warmup
        for ($i = 0; $i < $warmupIterations; $i++) {
            foreach ($testClasses as $className) {
                try {
                    class_exists($className, true);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }
        
        // Test
        $timesOptimized = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            foreach ($testClasses as $className) {
                try {
                    class_exists($className, true);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
            $end = microtime(true);
            $timesOptimized[] = ($end - $start) * 1000;
        }
        
        // Test with optimization disabled
        $this->configureOpcache(false);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        
        // Warmup
        for ($i = 0; $i < $warmupIterations; $i++) {
            foreach ($testClasses as $className) {
                try {
                    class_exists($className, true);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }
        
        // Test
        $timesUnoptimized = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            foreach ($testClasses as $className) {
                try {
                    class_exists($className, true);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
            $end = microtime(true);
            $timesUnoptimized[] = ($end - $start) * 1000;
        }
        
        return [
            'optimized' => [
                'avg' => array_sum($timesOptimized) / count($timesOptimized),
                'median' => $this->calculateMedian($timesOptimized),
                'min' => min($timesOptimized),
                'max' => max($timesOptimized),
            ],
            'unoptimized' => [
                'avg' => array_sum($timesUnoptimized) / count($timesUnoptimized),
                'median' => $this->calculateMedian($timesUnoptimized),
                'min' => min($timesUnoptimized),
                'max' => max($timesUnoptimized),
            ],
        ];
    }

    /**
     * Test HTTP request performance
     */
    private function testHttpRequestPerformance(string $baseUrl): array
    {
        $iterations = 30;
        $warmupIterations = 10;
        
        // Test with optimization enabled
        $this->configureOpcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        
        // Warmup
        for ($i = 0; $i < $warmupIterations; $i++) {
            $this->makeHttpRequest($baseUrl);
        }
        
        // Test
        $timesOptimized = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->makeHttpRequest($baseUrl);
            $end = microtime(true);
            $timesOptimized[] = ($end - $start) * 1000;
        }
        
        // Test with optimization disabled
        $this->configureOpcache(false);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        
        // Warmup
        for ($i = 0; $i < $warmupIterations; $i++) {
            $this->makeHttpRequest($baseUrl);
        }
        
        // Test
        $timesUnoptimized = [];
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $this->makeHttpRequest($baseUrl);
            $end = microtime(true);
            $timesUnoptimized[] = ($end - $start) * 1000;
        }
        
        return [
            'optimized' => [
                'avg' => array_sum($timesOptimized) / count($timesOptimized),
                'median' => $this->calculateMedian($timesOptimized),
                'min' => min($timesOptimized),
                'max' => max($timesOptimized),
            ],
            'unoptimized' => [
                'avg' => array_sum($timesUnoptimized) / count($timesUnoptimized),
                'median' => $this->calculateMedian($timesUnoptimized),
                'min' => min($timesUnoptimized),
                'max' => max($timesUnoptimized),
            ],
        ];
    }

    /**
     * Test OPcache statistics
     */
    private function testOpcacheStatistics(): array
    {
        if (!function_exists('opcache_get_status')) {
            return [];
        }

        $status = opcache_get_status();
        if ($status === false) {
            return [];
        }

        $hits = $status['opcache_statistics']['hits'] ?? 0;
        $misses = $status['opcache_statistics']['misses'] ?? 0;
        $total = $hits + $misses;
        $hitRate = $total > 0 ? ($hits / $total) * 100 : 0;

        return [
            'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
            'memory_used' => $this->formatBytes($status['memory_usage']['used_memory'] ?? 0),
            'memory_free' => $this->formatBytes($status['memory_usage']['free_memory'] ?? 0),
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => number_format($hitRate, 2),
        ];
    }

    /**
     * Test combined file status
     */
    private function testCombinedFileStatus(): array
    {
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        $result = [];
        
        if (file_exists($vendorFile)) {
            $result['vendor_size'] = $this->formatBytes(filesize($vendorFile));
            $result['vendor_cached'] = function_exists('opcache_is_script_cached') && opcache_is_script_cached($vendorFile);
        }
        
        if (file_exists($appCodeFile)) {
            $result['app_code_size'] = $this->formatBytes(filesize($appCodeFile));
            $result['app_code_cached'] = function_exists('opcache_is_script_cached') && opcache_is_script_cached($appCodeFile);
        }
        
        return $result;
    }

    /**
     * Configure OPcache optimization settings
     */
    private function configureOpcache(bool $enableOptimization): void
    {
        if (!function_exists('opcache_get_status')) {
            return;
        }

        @ini_set('opcache.enable', '1');
        @ini_set('opcache.enable_cli', '0');
        @ini_set('opcache.memory_consumption', '512');
        @ini_set('opcache.interned_strings_buffer', '16');
        @ini_set('opcache.max_accelerated_files', '20000');
        @ini_set('opcache.save_comments', '1');
        @ini_set('opcache.enable_file_override', '1');
        
        if ($enableOptimization) {
            @ini_set('opcache.optimization_level', '0x7FFFBFFF');
            @ini_set('opcache.fast_shutdown', '1');
            @ini_set('opcache.validate_timestamps', '0');
            @ini_set('opcache.revalidate_freq', '0');
        } else {
            @ini_set('opcache.optimization_level', '0');
            @ini_set('opcache.fast_shutdown', '0');
            @ini_set('opcache.validate_timestamps', '1');
            @ini_set('opcache.revalidate_freq', '2');
        }
    }

    /**
     * Make HTTP request
     */
    private function makeHttpRequest(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'success' => $httpCode >= 200 && $httpCode < 400,
            'http_code' => $httpCode,
        ];
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
