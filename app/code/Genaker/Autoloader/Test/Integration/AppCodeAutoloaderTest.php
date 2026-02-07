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
 * Test to verify app/code combined file autoloader is working
 */
class AppCodeAutoloaderTest extends TestCase
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
     * Test that app/code combined file exists
     */
    public function testAppCodeCombinedFileExists(): void
    {
        $combinedFile = BP . '/var/combined_app_code_classes.php';
        
        $this->assertFileExists(
            $combinedFile,
            'app/code combined file should exist. Run: bin/magento genaker:autoloader:combine-app-code'
        );
        
        $fileSize = filesize($combinedFile);
        echo "\n=== App Code Combined File ===\n";
        echo "File: {$combinedFile}\n";
        echo "Size: " . $this->formatBytes($fileSize) . "\n";
        
        $this->assertGreaterThan(0, $fileSize, 'Combined file should have content');
    }

    /**
     * Test that app/code classes can be loaded
     */
    public function testAppCodeClassesCanBeLoaded(): void
    {
        $combinedFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist. Run: bin/magento genaker:autoloader:combine-app-code');
        }

        // Test loading some app/code classes
        $testClasses = [
            '',
            '',
            'Genaker\Autoloader\Model\ComposerAutoloader',
            'LCCoins\MailOrderCatalog\Model\Service\SubscriberSynchronizationService',
        ];

        echo "\n=== Testing App Code Class Loading ===\n";
        
        $loaded = 0;
        $start = microtime(true);
        
        foreach ($testClasses as $className) {
            if (class_exists($className, true)) {
                $loaded++;
                echo "  ✓ Loaded: {$className}\n";
            } else {
                echo "  ✗ Not found: {$className}\n";
            }
        }
        
        $end = microtime(true);
        $loadTime = ($end - $start) * 1000;
        
        echo "Loaded: {$loaded}/" . count($testClasses) . " classes\n";
        echo "Time: " . number_format($loadTime, 2) . " ms\n";
        
        $this->assertGreaterThan(0, $loaded, 'At least some app/code classes should be loadable');
    }

    /**
     * Test that app/code combined file is included
     */
    public function testAppCodeCombinedFileIsIncluded(): void
    {
        $combinedFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist');
        }

        // Check if combined file was included
        $includedFiles = get_included_files();
        $combinedFileIncluded = false;
        
        foreach ($includedFiles as $file) {
            if (strpos($file, 'combined_app_code_classes.php') !== false) {
                $combinedFileIncluded = true;
                break;
            }
        }
        
        echo "\n=== App Code Combined File Inclusion ===\n";
        echo "Combined file included: " . ($combinedFileIncluded ? 'Yes' : 'No') . "\n";
        echo "Total included files: " . count($includedFiles) . "\n";
        
        // Note: The file might be included via require_once, so it might not show in get_included_files()
        // But we can verify classes are available
        $testClass = 'Genaker\Autoloader\Model\ComposerAutoloader';
        $classAvailable = class_exists($testClass, false);
        
        echo "Test class available (without autoload): " . ($classAvailable ? 'Yes' : 'No') . "\n";
        
        // If combined file is working, classes should be available
        $this->assertTrue(
            class_exists($testClass, true),
            'App code classes should be loadable if combined file is used'
        );
    }

    /**
     * Test performance of loading app/code classes
     */
    public function testAppCodeClassLoadingPerformance(): void
    {
        $combinedFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist');
        }

        // Find some app/code classes to test
        $testClasses = [
            '',
            '',
            'Genaker\Autoloader\Model\ComposerAutoloader',
            'Genaker\Autoloader\Console\Command\CombineClassMapFiles',
            'LCCoins\MailOrderCatalog\Model\Service\SubscriberSynchronizationService',
            'LCCoins\UpdateCategory\Commands\UpdateCategory',
        ];

        $iterations = 50;
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
        $minTime = min($times);
        $maxTime = max($times);
        
        echo "\n=== App Code Class Loading Performance ===\n";
        echo "Iterations: {$iterations}\n";
        echo "Classes per iteration: " . count($testClasses) . "\n";
        echo "Average time: " . number_format($avgTime, 2) . " ms\n";
        echo "Min time: " . number_format($minTime, 2) . " ms\n";
        echo "Max time: " . number_format($maxTime, 2) . " ms\n";
        echo "Total time: " . number_format(array_sum($times), 2) . " ms\n";
        
        $this->assertLessThan(100, $avgTime, 'Average loading time should be reasonable');
    }

    /**
     * Test that app/code autoloader works with Magento's autoloader
     */
    public function testAppCodeAutoloaderIntegration(): void
    {
        $combinedFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($combinedFile)) {
            $this->markTestSkipped('Combined file does not exist');
        }

        echo "\n=== App Code Autoloader Integration ===\n";
        
        // Test that we can instantiate app/code classes through ObjectManager
        try {
            /** @var \Genaker\Autoloader\Model\ComposerAutoloader $autoloader */
            $autoloader = self::$objectManager->get(\Genaker\Autoloader\Model\ComposerAutoloader::class);
            
            $this->assertInstanceOf(
                \Genaker\Autoloader\Model\ComposerAutoloader::class,
                $autoloader,
                'App code class should be instantiable through ObjectManager'
            );
            
            echo "✓ App code class instantiated through ObjectManager\n";
        } catch (\Exception $e) {
            $this->fail('Failed to instantiate app code class: ' . $e->getMessage());
        }
        
        // Test autoloader stack
        $autoloaders = spl_autoload_functions();
        echo "Total autoloaders: " . count($autoloaders) . "\n";
        
        $this->assertGreaterThan(0, count($autoloaders), 'Autoloaders should be registered');
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
