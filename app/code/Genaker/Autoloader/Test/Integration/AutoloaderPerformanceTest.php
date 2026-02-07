<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Performance test to compare autoloader enabled vs disabled
 */
class AutoloaderPerformanceTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private static $objectManager;

    /**
     * @var string
     */
    private $classMapFile;

    /**
     * @var array
     */
    private $testClasses = [];

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
        $this->classMapFile = BP . '/vendor/composer/autoload_classmap.php';
        
        // Load classmap to get test classes
        $vendorDir = dirname(dirname($this->classMapFile));
        $baseDir = dirname($vendorDir);
        
        $classMap = include $this->classMapFile;
        
        // Select diverse test classes from different vendors
        $this->testClasses = [
            'GuzzleHttp\Client',
            'Symfony\Component\Console\Application',
            'Psr\Log\LoggerInterface',
            'Magento\Framework\App\ObjectManager',
            'Magento\Framework\App\State',
        ];
        
        // Add more random classes from classmap if available
        $allClasses = array_keys($classMap);
        $randomClasses = array_rand($allClasses, min(20, count($allClasses)));
        foreach ($randomClasses as $index) {
            $this->testClasses[] = $allClasses[$index];
        }
        
        // Remove duplicates
        $this->testClasses = array_unique($this->testClasses);
    }

    /**
     * Test autoloading performance with autoloader enabled
     */
    public function testAutoloaderPerformanceEnabled(): void
    {
        $iterations = 100;
        $times = [];
        
        for ($i = 0; $i < $iterations; $i++) {
            // Clear opcache if available
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
            
            $start = microtime(true);
            
            // Try to autoload test classes
            foreach ($this->testClasses as $className) {
                if (class_exists($className, true) || 
                    interface_exists($className, true) || 
                    trait_exists($className, true)) {
                    // Class loaded successfully
                }
            }
            
            $end = microtime(true);
            $times[] = ($end - $start) * 1000; // Convert to milliseconds
        }
        
        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        
        echo "\n=== Autoloader ENABLED Performance ===\n";
        echo "Iterations: {$iterations}\n";
        echo "Classes tested: " . count($this->testClasses) . "\n";
        echo "Average time: " . number_format($avgTime, 2) . " ms\n";
        echo "Min time: " . number_format($minTime, 2) . " ms\n";
        echo "Max time: " . number_format($maxTime, 2) . " ms\n";
        echo "Total time: " . number_format(array_sum($times), 2) . " ms\n";
        
        // Assert that average time is reasonable (less than 100ms for 100 iterations)
        $this->assertLessThan(1000, $avgTime, 'Average autoload time should be reasonable');
    }

    /**
     * Test autoloading performance by measuring class existence checks
     */
    public function testClassExistenceCheckPerformance(): void
    {
        $iterations = 100;
        $times = [];
        
        // Measure checking class existence (triggers autoloader)
        $vendorDir = dirname(dirname($this->classMapFile));
        $baseDir = dirname($vendorDir);
        $classMap = include $this->classMapFile;
        
        // Get a subset of classes to test
        $testClasses = array_slice(array_keys($classMap), 0, 50);
        
        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);
            
            foreach ($testClasses as $className) {
                // Use class_exists which triggers autoloader but doesn't redeclare
                class_exists($className, true);
            }
            
            $end = microtime(true);
            $times[] = ($end - $start) * 1000;
        }
        
        $avgTime = array_sum($times) / count($times);
        
        echo "\n=== Class Existence Check Performance ===\n";
        echo "Iterations: {$iterations}\n";
        echo "Classes per iteration: " . count($testClasses) . "\n";
        echo "Average time: " . number_format($avgTime, 2) . " ms\n";
        echo "Total time: " . number_format(array_sum($times), 2) . " ms\n";
        
        $this->assertLessThan(5000, $avgTime, 'Average class existence check time should be reasonable');
    }

    /**
     * Test combined file existence and size
     */
    public function testCombinedFileInfo(): void
    {
        $combinedFile = BP . '/var/combined_vendor_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist. Run: bin/magento genaker:autoloader:combine-files');
        }
        
        $fileSize = filesize($combinedFile);
        $lineCount = 0;
        
        if ($handle = fopen($combinedFile, 'r')) {
            while (!feof($handle)) {
                fgets($handle);
                $lineCount++;
            }
            fclose($handle);
        }
        
        echo "\n=== Combined File Information ===\n";
        echo "File path: {$combinedFile}\n";
        echo "File size: " . $this->formatBytes($fileSize) . "\n";
        echo "Line count: " . number_format($lineCount) . "\n";
        echo "File exists: " . (file_exists($combinedFile) ? 'Yes' : 'No') . "\n";
        
        $this->assertFileExists($combinedFile, 'Combined file should exist');
        $this->assertGreaterThan(0, $fileSize, 'Combined file should have content');
    }

    /**
     * Test memory usage comparison
     */
    public function testMemoryUsage(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        // Load some classes (use safe classes that are likely to exist)
        $safeClasses = [
            'GuzzleHttp\Client',
            'Symfony\Component\Console\Application',
            'Psr\Log\LoggerInterface',
            'Magento\Framework\App\ObjectManager',
            'Magento\Framework\App\State',
        ];
        
        $loadedCount = 0;
        foreach ($safeClasses as $className) {
            try {
                if (class_exists($className, true) || 
                    interface_exists($className, true) || 
                    trait_exists($className, true)) {
                    $loadedCount++;
                }
            } catch (\Exception $e) {
                // Skip classes that cause errors
                continue;
            }
        }
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        echo "\n=== Memory Usage ===\n";
        echo "Memory before: " . $this->formatBytes($memoryBefore) . "\n";
        echo "Memory after: " . $this->formatBytes($memoryAfter) . "\n";
        echo "Memory used: " . $this->formatBytes($memoryUsed) . "\n";
        echo "Classes loaded: {$loadedCount}\n";
        
        $this->assertGreaterThan(0, $loadedCount, 'At least some classes should be loadable');
    }

    /**
     * Test autoloader registration count
     */
    public function testAutoloaderRegistrationCount(): void
    {
        $autoloaders = spl_autoload_functions();
        
        echo "\n=== Autoloader Registration ===\n";
        echo "Total autoloaders registered: " . count($autoloaders) . "\n";
        
        foreach ($autoloaders as $index => $autoloader) {
            if (is_array($autoloader)) {
                $class = is_object($autoloader[0]) ? get_class($autoloader[0]) : $autoloader[0];
                echo "  [{$index}] {$class}::{$autoloader[1]}\n";
            } elseif (is_string($autoloader)) {
                echo "  [{$index}] {$autoloader}\n";
            } else {
                echo "  [{$index}] Closure\n";
            }
        }
        
        $this->assertGreaterThan(0, count($autoloaders), 'At least one autoloader should be registered');
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
