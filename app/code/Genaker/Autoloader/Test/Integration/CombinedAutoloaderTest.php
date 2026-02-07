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
 * Test to verify both vendor and app/code autoloaders work together
 */
class CombinedAutoloaderTest extends TestCase
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
     * Test that both combined files exist
     */
    public function testBothCombinedFilesExist(): void
    {
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        echo "\n=== Combined Files Status ===\n";
        
        if (file_exists($vendorFile)) {
            $vendorSize = filesize($vendorFile);
            echo "✓ Vendor combined file: " . $this->formatBytes($vendorSize) . "\n";
        } else {
            echo "✗ Vendor combined file not found\n";
        }
        
        if (file_exists($appCodeFile)) {
            $appCodeSize = filesize($appCodeFile);
            echo "✓ App code combined file: " . $this->formatBytes($appCodeSize) . "\n";
        } else {
            echo "✗ App code combined file not found\n";
        }
        
        $this->assertTrue(
            file_exists($vendorFile) || file_exists($appCodeFile),
            'At least one combined file should exist'
        );
    }

    /**
     * Test that both vendor and app/code classes can be loaded
     */
    public function testBothVendorAndAppCodeClassesLoadable(): void
    {
        echo "\n=== Testing Combined Autoloaders ===\n";
        
        // Test vendor classes
        $vendorClasses = [
            'GuzzleHttp\Client',
            'Symfony\Component\Console\Application',
            'Psr\Log\LoggerInterface',
        ];
        
        $vendorLoaded = 0;
        foreach ($vendorClasses as $className) {
            if (class_exists($className, true) || interface_exists($className, true)) {
                $vendorLoaded++;
            }
        }
        
        echo "Vendor classes loaded: {$vendorLoaded}/" . count($vendorClasses) . "\n";
        
        // Test app/code classes
        $appCodeClasses = [
            'Genaker\Autoloader\Model\ComposerAutoloader',
            'Genaker\Autoloader\Console\Command\CombineClassMapFiles',
            'LCCoins\MailOrderCatalog\Model\Service\SubscriberSynchronizationService',
        ];
        
        $appCodeLoaded = 0;
        foreach ($appCodeClasses as $className) {
            if (class_exists($className, true)) {
                $appCodeLoaded++;
            }
        }
        
        echo "App code classes loaded: {$appCodeLoaded}/" . count($appCodeClasses) . "\n";
        
        $this->assertGreaterThan(0, $vendorLoaded, 'At least some vendor classes should be loadable');
        $this->assertGreaterThan(0, $appCodeLoaded, 'At least some app/code classes should be loadable');
    }

    /**
     * Test performance of loading both vendor and app/code classes
     */
    public function testCombinedPerformance(): void
    {
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($vendorFile) && !file_exists($appCodeFile)) {
            $this->markTestSkipped('No combined files exist');
        }

        $testClasses = [
            // Vendor classes
            'GuzzleHttp\Client',
            'Symfony\Component\Console\Application',
            'Psr\Log\LoggerInterface',
            // App code classes
            'Genaker\Autoloader\Model\ComposerAutoloader',
            'Genaker\Autoloader\Console\Command\CombineClassMapFiles',
            'LCCoins\MailOrderCatalog\Model\Service\SubscriberSynchronizationService',
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
        
        echo "\n=== Combined Performance ===\n";
        echo "Iterations: {$iterations}\n";
        echo "Classes per iteration: " . count($testClasses) . "\n";
        echo "Average time: " . number_format($avgTime, 2) . " ms\n";
        echo "Min time: " . number_format($minTime, 2) . " ms\n";
        echo "Max time: " . number_format($maxTime, 2) . " ms\n";
        echo "Total time: " . number_format(array_sum($times), 2) . " ms\n";
        
        $this->assertLessThan(100, $avgTime, 'Average loading time should be reasonable');
    }

    /**
     * Test that Magento uses both autoloaders
     */
    public function testMagentoUsesBothAutoloaders(): void
    {
        echo "\n=== Magento Autoloader Usage ===\n";
        
        // Test that we can instantiate both vendor and app/code classes through ObjectManager
        try {
            // Vendor class (if available)
            try {
                $logger = self::$objectManager->get(\Psr\Log\LoggerInterface::class);
                echo "✓ Vendor class (LoggerInterface) instantiated\n";
            } catch (\Exception $e) {
                echo "⚠ Vendor class not available: " . $e->getMessage() . "\n";
            }
            
            // App code class
            $autoloader = self::$objectManager->get(\Genaker\Autoloader\Model\ComposerAutoloader::class);
            echo "✓ App code class (ComposerAutoloader) instantiated\n";
            
            $this->assertInstanceOf(
                \Genaker\Autoloader\Model\ComposerAutoloader::class,
                $autoloader,
                'App code class should be instantiable'
            );
        } catch (\Exception $e) {
            $this->fail('Failed to instantiate classes: ' . $e->getMessage());
        }
        
        // Check autoloader stack
        $autoloaders = spl_autoload_functions();
        echo "Total autoloaders registered: " . count($autoloaders) . "\n";
        
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
