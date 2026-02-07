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
 * Comparison test: Autoloader enabled vs disabled
 * Note: This test requires manual module disable/enable to get accurate comparison
 */
class AutoloaderComparisonTest extends TestCase
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
            $appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // Area code already set
        }
    }

    /**
     * Test that autoloader module is currently enabled
     */
    public function testAutoloaderModuleStatus(): void
    {
        /** @var \Magento\Framework\Module\Manager $moduleManager */
        $moduleManager = self::$objectManager->get(\Magento\Framework\Module\Manager::class);
        
        $isEnabled = $moduleManager->isEnabled('Genaker_Autoloader');
        
        echo "\n=== Module Status ===\n";
        echo "Genaker_Autoloader module: " . ($isEnabled ? 'ENABLED' : 'DISABLED') . "\n";
        
        if ($isEnabled) {
            echo "\nTo test with autoloader DISABLED, run:\n";
            echo "  bin/magento module:disable Genaker_Autoloader\n";
            echo "  bin/magento cache:flush\n";
            echo "Then run this test again.\n";
        } else {
            echo "\nTo test with autoloader ENABLED, run:\n";
            echo "  bin/magento module:enable Genaker_Autoloader\n";
            echo "  bin/magento cache:flush\n";
            echo "Then run this test again.\n";
        }
        
        // This test always passes - it's informational
        $this->assertTrue(true);
    }

    /**
     * Test autoloader performance metrics
     */
    public function testAutoloaderPerformanceMetrics(): void
    {
        $classMapFile = BP . '/vendor/composer/autoload_classmap.php';
        $combinedFile = BP . '/var/combined_vendor_classes.php';
        
        $vendorDir = dirname(dirname($classMapFile));
        $classMap = include $classMapFile;
        
        $testClasses = array_slice(array_keys($classMap), 0, 100);
        
        // Measure autoloading time
        $start = microtime(true);
        $loaded = 0;
        
        foreach ($testClasses as $className) {
            if (class_exists($className, true) || 
                interface_exists($className, true) || 
                trait_exists($className, true)) {
                $loaded++;
            }
        }
        
        $end = microtime(true);
        $time = ($end - $start) * 1000; // Convert to milliseconds
        
        echo "\n=== Performance Metrics ===\n";
        echo "Classes tested: " . count($testClasses) . "\n";
        echo "Classes loaded: {$loaded}\n";
        echo "Time taken: " . number_format($time, 2) . " ms\n";
        echo "Average per class: " . number_format($time / count($testClasses), 4) . " ms\n";
        
        // Check which method is being used
        $usingCombined = file_exists($combinedFile);
        echo "Using combined file: " . ($usingCombined ? 'Yes' : 'No') . "\n";
        
        if ($usingCombined) {
            echo "Combined file size: " . $this->formatBytes(filesize($combinedFile)) . "\n";
        }
        
        echo "Classmap file size: " . $this->formatBytes(filesize($classMapFile)) . "\n";
        
        $this->assertGreaterThan(0, $loaded, 'At least some classes should be loadable');
        $this->assertLessThan(10000, $time, 'Autoloading should complete in reasonable time');
    }

    /**
     * Test autoloader stack
     */
    public function testAutoloaderStack(): void
    {
        $autoloaders = spl_autoload_functions();
        
        echo "\n=== Autoloader Stack ===\n";
        echo "Total autoloaders: " . count($autoloaders) . "\n\n";
        
        $hasCustomAutoloader = false;
        foreach ($autoloaders as $index => $autoloader) {
            $info = '';
            if (is_array($autoloader)) {
                $class = is_object($autoloader[0]) ? get_class($autoloader[0]) : $autoloader[0];
                $info = "{$class}::{$autoloader[1]}";
                
                // Check if it's our custom autoloader
                if (strpos($class, 'Genaker\\Autoloader') !== false || 
                    (is_object($autoloader[0]) && $autoloader[0] instanceof \Closure)) {
                    $hasCustomAutoloader = true;
                    $info .= " [CUSTOM]";
                }
            } elseif (is_string($autoloader)) {
                $info = $autoloader;
            } else {
                $info = "Closure";
                // Check if it's our closure
                $hasCustomAutoloader = true;
                $info .= " [CUSTOM - possibly Genaker_Autoloader]";
            }
            
            echo "  [{$index}] {$info}\n";
        }
        
        echo "\nCustom autoloader detected: " . ($hasCustomAutoloader ? 'Yes' : 'No') . "\n";
        
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
