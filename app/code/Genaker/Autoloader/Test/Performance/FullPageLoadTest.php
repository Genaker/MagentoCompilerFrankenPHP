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
 * Full Page Load Performance Test
 * Simulates full Magento page loads to measure autoloader and opcache influence
 */
class FullPageLoadTest extends TestCase
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
     * Test full page load performance
     */
    public function testFullPageLoadPerformance(): void
    {
        echo "\n=== Full Page Load Performance Test ===\n";
        
        // Check opcache
        $opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status() !== false;
        echo "OPcache enabled: " . ($opcacheEnabled ? 'Yes' : 'No') . "\n";
        
        // Check combined files
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        echo "\nCombined Files Status:\n";
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
        
        // Simulate page load by instantiating common Magento classes
        $testClasses = [
            // Core Magento classes
            \Magento\Framework\App\ObjectManager::class,
            \Magento\Framework\App\State::class,
            \Magento\Store\Model\StoreManagerInterface::class,
            \Magento\Framework\App\Config\ScopeConfigInterface::class,
            // App code classes
            \Genaker\Autoloader\Model\ComposerAutoloader::class,
        ];
        
        $iterations = 50;
        $warmupIterations = 10;
        
        echo "\nWarmup iterations: {$warmupIterations}\n";
        for ($i = 0; $i < $warmupIterations; $i++) {
            foreach ($testClasses as $className) {
                try {
                    class_exists($className, true);
                } catch (\Exception $e) {
                    // Ignore
                }
            }
        }
        
        echo "Test iterations: {$iterations}\n";
        $times = [];
        $memoryBefore = memory_get_usage(true);
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            // Simulate loading classes that would be needed for a page
            foreach ($testClasses as $className) {
                try {
                    if (class_exists($className, true) || interface_exists($className, true)) {
                        // Class loaded
                    }
                } catch (\Exception $e) {
                    // Ignore
                }
            }
            
            $end = microtime(true);
            $times[] = ($end - $start) * 1000;
        }
        
        $memoryAfter = memory_get_usage(true);
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        $medianTime = $this->calculateMedian($times);
        
        echo "\nResults:\n";
        echo "  Average: " . number_format($avgTime, 4) . " ms\n";
        echo "  Median: " . number_format($medianTime, 4) . " ms\n";
        echo "  Min: " . number_format($minTime, 4) . " ms\n";
        echo "  Max: " . number_format($maxTime, 4) . " ms\n";
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
        
        $this->assertLessThan(100, $avgTime, 'Average class loading time should be reasonable');
    }

    /**
     * Test autoloader stack performance
     */
    public function testAutoloaderStackPerformance(): void
    {
        echo "\n=== Autoloader Stack Performance ===\n";
        
        $autoloaders = spl_autoload_functions();
        echo "Total autoloaders: " . count($autoloaders) . "\n";
        
        // Test loading classes through autoloader stack
        $testClasses = [
            'Genaker\Autoloader\Model\ComposerAutoloader',
            'GuzzleHttp\Client',
            'Symfony\Component\Console\Application',
        ];
        
        $iterations = 100;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            foreach ($testClasses as $className) {
                class_exists($className, true);
            }
            
            $end = microtime(true);
            $times[] = ($end - $start) * 1000;
        }
        
        $avgTime = array_sum($times) / count($times);
        
        echo "Iterations: {$iterations}\n";
        echo "Classes per iteration: " . count($testClasses) . "\n";
        echo "Average time: " . number_format($avgTime, 4) . " ms\n";
        echo "Total time: " . number_format(array_sum($times), 2) . " ms\n";
        
        $this->assertLessThan(10, $avgTime, 'Autoloader should be fast');
    }

    /**
     * Test combined file loading performance
     */
    public function testCombinedFileLoadingPerformance(): void
    {
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        echo "\n=== Combined File Loading Performance ===\n";
        echo "Note: Combined files are already loaded via registration.php\n";
        echo "This test checks file existence and size only.\n";
        
        if (file_exists($vendorFile)) {
            $size = filesize($vendorFile);
            $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($vendorFile);
            echo "Vendor file: " . $this->formatBytes($size) . " (" . ($cached ? 'OPcache cached' : 'not cached') . ")\n";
        } else {
            echo "Vendor file: Not found\n";
        }
        
        if (file_exists($appCodeFile)) {
            $size = filesize($appCodeFile);
            $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($appCodeFile);
            echo "App code file: " . $this->formatBytes($size) . " (" . ($cached ? 'OPcache cached' : 'not cached') . ")\n";
        } else {
            echo "App code file: Not found\n";
        }
        
        $this->assertTrue(true); // Informational test
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
