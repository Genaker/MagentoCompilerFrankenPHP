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
 * OPcache Optimization Performance Test
 * Compares performance with OPcache optimization enabled vs disabled
 */
class OpcacheOptimizationTest extends TestCase
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
     * Test performance with OPcache optimization enabled
     */
    public function testPerformanceWithOptimizationEnabled(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('OPcache not available');
        }

        // Enable optimization
        $this->configureOpcache(true);
        
        // Clear OPcache to start fresh
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        echo "\n=== Performance Test: OPcache Optimization ENABLED ===\n";
        $results = $this->runPerformanceTest();
        $this->printResults($results, 'Optimization Enabled');
        
        $this->assertLessThan(100, $results['avgTime'], 'Average time should be reasonable');
    }

    /**
     * Test performance with OPcache optimization disabled
     */
    public function testPerformanceWithOptimizationDisabled(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('OPcache not available');
        }

        // Disable optimization
        $this->configureOpcache(false);
        
        // Clear OPcache to start fresh
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        echo "\n=== Performance Test: OPcache Optimization DISABLED ===\n";
        $results = $this->runPerformanceTest();
        $this->printResults($results, 'Optimization Disabled');
        
        $this->assertLessThan(100, $results['avgTime'], 'Average time should be reasonable');
    }

    /**
     * Compare performance with optimization enabled vs disabled
     */
    public function testCompareOptimizationSettings(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('OPcache not available');
        }

        echo "\n=== OPcache Optimization Comparison ===\n";
        
        // Test with optimization enabled
        $this->configureOpcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $resultsEnabled = $this->runPerformanceTest();
        
        // Test with optimization disabled
        $this->configureOpcache(false);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $resultsDisabled = $this->runPerformanceTest();
        
        echo "\n=== Comparison Results ===\n";
        echo "Optimization Enabled:\n";
        echo "  Average: " . number_format($resultsEnabled['avgTime'], 4) . " ms\n";
        echo "  Median: " . number_format($resultsEnabled['medianTime'], 4) . " ms\n";
        echo "  Min: " . number_format($resultsEnabled['minTime'], 4) . " ms\n";
        echo "  Max: " . number_format($resultsEnabled['maxTime'], 4) . " ms\n";
        
        echo "\nOptimization Disabled:\n";
        echo "  Average: " . number_format($resultsDisabled['avgTime'], 4) . " ms\n";
        echo "  Median: " . number_format($resultsDisabled['medianTime'], 4) . " ms\n";
        echo "  Min: " . number_format($resultsDisabled['minTime'], 4) . " ms\n";
        echo "  Max: " . number_format($resultsDisabled['maxTime'], 4) . " ms\n";
        
        $improvement = (($resultsDisabled['avgTime'] - $resultsEnabled['avgTime']) / $resultsDisabled['avgTime']) * 100;
        echo "\nPerformance Improvement: " . number_format($improvement, 2) . "% " . ($improvement > 0 ? 'faster' : 'slower') . " with optimization enabled\n";
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Test HTTP request performance with different optimization settings
     */
    public function testHttpPerformanceComparison(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('OPcache not available');
        }

        echo "\n=== HTTP Performance Comparison ===\n";
        
        try {
            /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
            $storeManager = self::$objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
            $baseUrl = rtrim($storeManager->getStore()->getBaseUrl(), '/');
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot get base URL');
            return;
        }

        // Test with optimization enabled
        $this->configureOpcache(true);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $httpResultsEnabled = $this->runHttpPerformanceTest($baseUrl);
        
        // Test with optimization disabled
        $this->configureOpcache(false);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $httpResultsDisabled = $this->runHttpPerformanceTest($baseUrl);
        
        echo "\nOptimization Enabled - HTTP:\n";
        echo "  Average: " . number_format($httpResultsEnabled['avgTime'], 2) . " ms\n";
        echo "  Successful: " . $httpResultsEnabled['successful'] . "/" . $httpResultsEnabled['total'] . "\n";
        
        echo "\nOptimization Disabled - HTTP:\n";
        echo "  Average: " . number_format($httpResultsDisabled['avgTime'], 2) . " ms\n";
        echo "  Successful: " . $httpResultsDisabled['successful'] . "/" . $httpResultsDisabled['total'] . "\n";
        
        $improvement = (($httpResultsDisabled['avgTime'] - $httpResultsEnabled['avgTime']) / $httpResultsDisabled['avgTime']) * 100;
        echo "\nHTTP Performance Improvement: " . number_format($improvement, 2) . "% " . ($improvement > 0 ? 'faster' : 'slower') . " with optimization enabled\n";
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Configure OPcache optimization settings
     */
    private function configureOpcache(bool $enableOptimization): void
    {
        if (!function_exists('opcache_get_status')) {
            return;
        }

        // Base configuration
        @ini_set('opcache.enable', '1');
        @ini_set('opcache.enable_cli', '0');
        @ini_set('opcache.memory_consumption', '512');
        @ini_set('opcache.interned_strings_buffer', '16');
        @ini_set('opcache.max_accelerated_files', '20000');
        @ini_set('opcache.save_comments', '1');
        @ini_set('opcache.enable_file_override', '1');
        
        if ($enableOptimization) {
            // Enable optimizations
            @ini_set('opcache.optimization_level', '0x7FFFBFFF'); // Maximum optimization
            @ini_set('opcache.fast_shutdown', '1');
            @ini_set('opcache.validate_timestamps', '0'); // No validation for production
            @ini_set('opcache.revalidate_freq', '0');
            echo "OPcache optimization: ENABLED\n";
        } else {
            // Disable optimizations
            @ini_set('opcache.optimization_level', '0'); // No optimization
            @ini_set('opcache.fast_shutdown', '0');
            @ini_set('opcache.validate_timestamps', '1'); // Enable validation
            @ini_set('opcache.revalidate_freq', '2');
            echo "OPcache optimization: DISABLED\n";
        }
    }

    /**
     * Run performance test
     */
    private function runPerformanceTest(): array
    {
        $testClasses = [
            \Magento\Framework\App\ObjectManager::class,
            \Magento\Framework\App\State::class,
            \Magento\Store\Model\StoreManagerInterface::class,
            \Magento\Framework\App\Config\ScopeConfigInterface::class,
            \Genaker\Autoloader\Model\ComposerAutoloader::class,
            \GuzzleHttp\Client::class,
            \Symfony\Component\Console\Application::class,
        ];
        
        $iterations = 100;
        $warmupIterations = 20;
        
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
        $times = [];
        $memoryBefore = memory_get_usage(true);
        
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
            $times[] = ($end - $start) * 1000;
        }
        
        $memoryAfter = memory_get_usage(true);
        
        return [
            'times' => $times,
            'avgTime' => array_sum($times) / count($times),
            'medianTime' => $this->calculateMedian($times),
            'minTime' => min($times),
            'maxTime' => max($times),
            'totalTime' => array_sum($times),
            'memoryUsed' => $memoryAfter - $memoryBefore,
        ];
    }

    /**
     * Run HTTP performance test
     */
    private function runHttpPerformanceTest(string $baseUrl): array
    {
        $iterations = 20;
        $warmupIterations = 5;
        
        // Warmup
        for ($i = 0; $i < $warmupIterations; $i++) {
            $this->makeHttpRequest($baseUrl);
        }
        
        // Test
        $times = [];
        $successful = 0;
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            $result = $this->makeHttpRequest($baseUrl);
            $end = microtime(true);
            
            if ($result['success']) {
                $successful++;
            }
            
            $times[] = ($end - $start) * 1000;
        }
        
        return [
            'times' => $times,
            'avgTime' => array_sum($times) / count($times),
            'successful' => $successful,
            'total' => $iterations,
        ];
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
     * Print results
     */
    private function printResults(array $results, string $label): void
    {
        echo "\nResults ({$label}):\n";
        echo "  Iterations: " . count($results['times']) . "\n";
        echo "  Average: " . number_format($results['avgTime'], 4) . " ms\n";
        echo "  Median: " . number_format($results['medianTime'], 4) . " ms\n";
        echo "  Min: " . number_format($results['minTime'], 4) . " ms\n";
        echo "  Max: " . number_format($results['maxTime'], 4) . " ms\n";
        echo "  Total: " . number_format($results['totalTime'], 2) . " ms\n";
        echo "  Memory used: " . $this->formatBytes($results['memoryUsed']) . "\n";
        
        // Check OPcache status
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status();
            if ($status !== false) {
                echo "\nOPcache Status:\n";
                echo "  Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
                echo "  Hits: " . ($status['opcache_statistics']['hits'] ?? 'N/A') . "\n";
                echo "  Misses: " . ($status['opcache_statistics']['misses'] ?? 'N/A') . "\n";
            }
        }
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
