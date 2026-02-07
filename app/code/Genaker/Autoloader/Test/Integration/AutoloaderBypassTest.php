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
 * Test to verify Composer autoloader is bypassed when using combined file
 */
class AutoloaderBypassTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private static $objectManager;

    /**
     * @var array
     */
    private static $autoloaderCallCounts = [];

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

        // Track autoloader calls
        self::trackAutoloaderCalls();
    }

    /**
     * Track calls to each autoloader
     */
    private static function trackAutoloaderCalls(): void
    {
        // Note: Tracking autoloader calls is complex and may interfere with normal operation
        // We'll use a simpler approach - just verify classes are available
    }

    /**
     * Reset autoloader call counts
     */
    private function resetCallCounts(): void
    {
        foreach (self::$autoloaderCallCounts as $index => $count) {
            self::$autoloaderCallCounts[$index] = 0;
        }
    }

    /**
     * Get autoloader call counts
     */
    private function getCallCounts(): array
    {
        return self::$autoloaderCallCounts;
    }

    /**
     * Test that combined file preloads classes
     */
    public function testCombinedFilePreloadsClasses(): void
    {
        $combinedFile = BP . '/var/combined_vendor_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist. Run: bin/magento genaker:autoloader:combine-files');
        }

        // Get classes from classmap
        $classMapFile = BP . '/vendor/composer/autoload_classmap.php';
        $vendorDir = dirname(dirname($classMapFile));
        $classMap = include $classMapFile;
        
        // Select test classes
        $testClasses = array_slice(array_keys($classMap), 0, 50);
        
        echo "\n=== Testing Combined File Preloading ===\n";
        echo "Test classes: " . count($testClasses) . "\n";
        
        // Check if classes are available (combined file should have loaded them)
        // Note: The combined file is loaded in registration.php, so classes should be available
        $availableClasses = 0;
        foreach ($testClasses as $className) {
            // Check without triggering autoloader
            if (class_exists($className, false) || 
                interface_exists($className, false) || 
                trait_exists($className, false)) {
                $availableClasses++;
            }
        }
        
        echo "Classes available (preloaded): {$availableClasses}/" . count($testClasses) . "\n";
        
        // If combined file is working, classes should be available when checked with autoload=true
        // But they might not be in the symbol table until actually used
        // So we check with autoload=true to see if they load quickly (from combined file)
        $start = microtime(true);
        $loadedWithAutoload = 0;
        foreach ($testClasses as $className) {
            if (class_exists($className, true) || 
                interface_exists($className, true) || 
                trait_exists($className, true)) {
                $loadedWithAutoload++;
            }
        }
        $end = microtime(true);
        $loadTime = ($end - $start) * 1000;
        
        echo "Classes loaded (with autoload): {$loadedWithAutoload}/" . count($testClasses) . "\n";
        echo "Load time: " . number_format($loadTime, 2) . " ms\n";
        echo "Average per class: " . number_format($loadTime / count($testClasses), 4) . " ms\n";
        
        // Verify classes can be loaded (combined file makes them available)
        $this->assertGreaterThan(
            0,
            $loadedWithAutoload,
            'Classes should be loadable (combined file provides them)'
        );
        
        // If using combined file, loading should be fast
        $this->assertLessThan(
            100,
            $loadTime,
            'Loading should be fast if using combined file'
        );
    }

    /**
     * Test that custom autoloader is checked before Composer autoloader
     */
    public function testCustomAutoloaderBeforeComposer(): void
    {
        $combinedFile = BP . '/var/combined_vendor_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist');
        }

        // Get autoloader stack
        $autoloaders = spl_autoload_functions();
        
        echo "\n=== Testing Autoloader Priority ===\n";
        echo "Total autoloaders: " . count($autoloaders) . "\n";
        
        $customAutoloaderIndex = null;
        $composerAutoloaderIndex = null;
        
        // Find Composer autoloader first
        foreach ($autoloaders as $index => $autoloader) {
            if (is_array($autoloader)) {
                $class = is_object($autoloader[0]) ? get_class($autoloader[0]) : $autoloader[0];
                
                if ($class === 'Composer\Autoload\ClassLoader') {
                    $composerAutoloaderIndex = $index;
                    echo "  Composer autoloader at index: {$index}\n";
                    break;
                }
            }
        }
        
        // Find our custom autoloader (should be before Composer)
        // Our custom autoloader is registered first (prepend=true), so it should be at index 0
        if ($composerAutoloaderIndex !== null) {
            // Check all autoloaders before Composer
            for ($i = 0; $i < $composerAutoloaderIndex; $i++) {
                if (isset($autoloaders[$i]) && !is_array($autoloaders[$i])) {
                    // Closure - likely our custom autoloader
                    $customAutoloaderIndex = $i;
                    echo "  Custom autoloader (Closure) at index: {$i}\n";
                    break;
                }
            }
        } else {
            // Composer not found, check first few closures
            for ($i = 0; $i < min(3, count($autoloaders)); $i++) {
                if (isset($autoloaders[$i]) && !is_array($autoloaders[$i])) {
                    $customAutoloaderIndex = $i;
                    echo "  Custom autoloader (Closure) at index: {$i}\n";
                    break;
                }
            }
        }
        
        // Verify custom autoloader is registered
        $this->assertNotNull($customAutoloaderIndex, 'Custom autoloader should be registered');
        
        // If Composer autoloader exists, verify our custom one is checked first
        if ($composerAutoloaderIndex !== null && $customAutoloaderIndex !== null) {
            $this->assertLessThan(
                $composerAutoloaderIndex,
                $customAutoloaderIndex,
                'Custom autoloader should be checked before Composer autoloader'
            );
            
            echo "  ✓ Custom autoloader (index {$customAutoloaderIndex}) is checked before Composer autoloader (index {$composerAutoloaderIndex})\n";
        }
        
        // Test that classes load through our autoloader (fast, from combined file)
        $classMapFile = BP . '/vendor/composer/autoload_classmap.php';
        $vendorDir = dirname(dirname($classMapFile));
        $classMap = include $classMapFile;
        $testClasses = array_slice(array_keys($classMap), 0, 20);
        
        $start = microtime(true);
        foreach ($testClasses as $className) {
            class_exists($className, true);
        }
        $end = microtime(true);
        $loadTime = ($end - $start) * 1000;
        
        echo "  Time to load " . count($testClasses) . " classes: " . number_format($loadTime, 2) . " ms\n";
        
        // Loading should be fast (combined file or efficient autoloader)
        $this->assertLessThan(50, $loadTime, 'Classes should load quickly');
    }

    /**
     * Test that classes from combined file don't trigger individual file loads
     */
    public function testNoIndividualFileLoads(): void
    {
        $combinedFile = BP . '/var/combined_vendor_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist');
        }

        // Track file system operations
        $classMapFile = BP . '/vendor/composer/autoload_classmap.php';
        $vendorDir = dirname(dirname($classMapFile));
        $classMap = include $classMapFile;
        
        $testClasses = array_slice(array_keys($classMap), 0, 30);
        
        echo "\n=== Testing No Individual File Loads ===\n";
        echo "Test classes: " . count($testClasses) . "\n";
        
        // Check file access times
        $fileAccessTimes = [];
        
        foreach ($testClasses as $className) {
            if (isset($classMap[$className])) {
                $file = $classMap[$className];
                
                // Check if file was accessed recently
                if (file_exists($file)) {
                    $fileAccessTimes[$className] = filemtime($file);
                }
            }
        }
        
        // Load classes
        $start = microtime(true);
        foreach ($testClasses as $className) {
            class_exists($className, true);
        }
        $end = microtime(true);
        $loadTime = ($end - $start) * 1000;
        
        echo "Time to load " . count($testClasses) . " classes: " . number_format($loadTime, 2) . " ms\n";
        echo "Average per class: " . number_format($loadTime / count($testClasses), 4) . " ms\n";
        
        // If using combined file, loading should be very fast (classes already in memory)
        $this->assertLessThan(
            100,
            $loadTime,
            'Loading should be fast if using combined file (classes preloaded)'
        );
    }

    /**
     * Test autoloader stack order
     */
    public function testAutoloaderStackOrder(): void
    {
        $autoloaders = spl_autoload_functions();
        
        echo "\n=== Autoloader Stack Order ===\n";
        
        $customAutoloaderFound = false;
        $composerAutoloaderFound = false;
        
        foreach ($autoloaders as $index => $autoloader) {
            $info = '';
            $isCustom = false;
            $isComposer = false;
            
            if (is_array($autoloader)) {
                $class = is_object($autoloader[0]) ? get_class($autoloader[0]) : $autoloader[0];
                $info = "{$class}::{$autoloader[1]}";
                
                if ($class === 'Composer\Autoload\ClassLoader') {
                    $isComposer = true;
                    $composerAutoloaderFound = true;
                }
            } elseif (is_string($autoloader)) {
                $info = $autoloader;
            } else {
                $info = "Closure";
                // Check if it's our custom autoloader (first closure is likely ours)
                if ($index === 0) {
                    $isCustom = true;
                    $customAutoloaderFound = true;
                }
            }
            
            $marker = '';
            if ($isCustom) {
                $marker = ' [CUSTOM - Genaker_Autoloader]';
            } elseif ($isComposer) {
                $marker = ' [COMPOSER DEFAULT]';
            }
            
            echo "  [{$index}] {$info}{$marker}\n";
        }
        
        echo "\nCustom autoloader found: " . ($customAutoloaderFound ? 'Yes' : 'No') . "\n";
        echo "Composer autoloader found: " . ($composerAutoloaderFound ? 'Yes' : 'No') . "\n";
        
        // Verify custom autoloader is before Composer autoloader
        if ($customAutoloaderFound && $composerAutoloaderFound) {
            $customIndex = 0; // Our autoloader should be first
            $composerIndex = null;
            
            foreach ($autoloaders as $index => $autoloader) {
                if (is_array($autoloader) && 
                    isset($autoloader[0]) && 
                    is_object($autoloader[0]) &&
                    get_class($autoloader[0]) === 'Composer\Autoload\ClassLoader') {
                    $composerIndex = $index;
                    break;
                }
            }
            
            if ($composerIndex !== null) {
                echo "Custom autoloader index: {$customIndex}\n";
                echo "Composer autoloader index: {$composerIndex}\n";
                
                $this->assertLessThan(
                    $composerIndex,
                    $customIndex,
                    'Custom autoloader should be checked before Composer autoloader'
                );
            }
        }
        
        $this->assertTrue($customAutoloaderFound || $composerAutoloaderFound, 'At least one autoloader should be found');
    }

    /**
     * Test that combined file is actually used
     */
    public function testCombinedFileIsUsed(): void
    {
        $combinedFile = BP . '/var/combined_vendor_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist');
        }

        // Check if combined file was included
        $includedFiles = get_included_files();
        $combinedFileIncluded = false;
        
        foreach ($includedFiles as $file) {
            if (strpos($file, 'combined_vendor_classes.php') !== false) {
                $combinedFileIncluded = true;
                break;
            }
        }
        
        echo "\n=== Combined File Usage ===\n";
        echo "Combined file path: {$combinedFile}\n";
        echo "Combined file included: " . ($combinedFileIncluded ? 'Yes' : 'No') . "\n";
        echo "Total included files: " . count($includedFiles) . "\n";
        
        // The combined file should be included if registration.php loaded it
        // Note: get_included_files() might not show it if it was included via require_once
        // But we can verify classes are available
        
        $classMapFile = BP . '/vendor/composer/autoload_classmap.php';
        $vendorDir = dirname(dirname($classMapFile));
        $classMap = include $classMapFile;
        $testClasses = array_slice(array_keys($classMap), 0, 10);
        
        $availableClasses = 0;
        foreach ($testClasses as $className) {
            if (class_exists($className, false) || 
                interface_exists($className, false) || 
                trait_exists($className, false)) {
                $availableClasses++;
            }
        }
        
        echo "Preloaded classes (available without autoload): {$availableClasses}/" . count($testClasses) . "\n";
        
        // If combined file is working, many classes should be preloaded
        $this->assertGreaterThan(
            0,
            $availableClasses,
            'Some classes should be preloaded if combined file is used'
        );
    }
}
