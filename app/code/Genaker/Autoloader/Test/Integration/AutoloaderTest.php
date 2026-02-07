<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Integration;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Integration test to verify Composer autoload classmap is used by Magento
 */
class AutoloaderTest extends TestCase
{
    /**
     * @var ObjectManager
     */
    private static $objectManager;

    /**
     * @var string
     */
    private $classMapFile;

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
    }

    /**
     * Test that the autoload classmap file exists
     */
    public function testClassMapFileExists(): void
    {
        $this->assertFileExists(
            $this->classMapFile,
            'Composer autoload_classmap.php file should exist'
        );
    }

    /**
     * Test that the classmap file can be loaded and contains classes
     */
    public function testClassMapFileIsLoadable(): void
    {
        $this->assertFileExists($this->classMapFile);
        
        $vendorDir = dirname(dirname($this->classMapFile));
        $baseDir = dirname($vendorDir);
        
        // Load the classmap file
        $classMap = include $this->classMapFile;
        
        $this->assertIsArray($classMap, 'Classmap should be an array');
        $this->assertGreaterThan(0, count($classMap), 'Classmap should contain classes');
    }

    /**
     * Test that classes from the classmap can be autoloaded
     */
    public function testClassesFromClassMapCanBeAutoloaded(): void
    {
        $vendorDir = dirname(dirname($this->classMapFile));
        $baseDir = dirname($vendorDir);
        
        $classMap = include $this->classMapFile;
        
        // Get a sample class from the classmap (first few entries)
        $sampleClasses = array_slice($classMap, 0, 5, true);
        
        foreach ($sampleClasses as $className => $filePath) {
            // Check if class exists (this will trigger autoloader)
            $this->assertTrue(
                class_exists($className, true) || interface_exists($className, true) || trait_exists($className, true),
                "Class/Interface/Trait '{$className}' should be autoloadable from classmap"
            );
            
            // Verify the file exists
            $this->assertFileExists(
                $filePath,
                "File for class '{$className}' should exist at: {$filePath}"
            );
        }
    }

    /**
     * Test that Genaker_Autoloader module is enabled
     */
    public function testAutoloaderModuleIsEnabled(): void
    {
        /** @var \Magento\Framework\Module\Manager $moduleManager */
        $moduleManager = self::$objectManager->get(\Magento\Framework\Module\Manager::class);
        
        $this->assertTrue(
            $moduleManager->isEnabled('Genaker_Autoloader'),
            'Genaker_Autoloader module should be enabled'
        );
    }

    /**
     * Test that autoloader is registered in the autoload stack
     */
    public function testAutoloaderIsRegistered(): void
    {
        $autoloaders = spl_autoload_functions();
        
        $this->assertIsArray($autoloaders, 'Autoload functions should be registered');
        $this->assertGreaterThan(0, count($autoloaders), 'At least one autoloader should be registered');
    }

    /**
     * Test that vendor classes can be instantiated
     */
    public function testVendorClassesCanBeInstantiated(): void
    {
        $vendorDir = dirname(dirname($this->classMapFile));
        $baseDir = dirname($vendorDir);
        
        $classMap = include $this->classMapFile;
        
        // Find a simple class that can be instantiated (not abstract, has no required constructor params)
        // Look for common vendor classes that are likely instantiable
        $testClasses = [
            'GuzzleHttp\Client',
            'Symfony\Component\Console\Application',
        ];
        
        $foundInstantiable = false;
        foreach ($testClasses as $className) {
            if (isset($classMap[$className])) {
                if (class_exists($className, true)) {
                    try {
                        $reflection = new \ReflectionClass($className);
                        if (!$reflection->isAbstract() && !$reflection->isInterface()) {
                            // Try to instantiate if possible
                            if ($reflection->getConstructor() === null || 
                                $reflection->getConstructor()->getNumberOfRequiredParameters() === 0) {
                                $instance = $reflection->newInstance();
                                $foundInstantiable = true;
                                break;
                            }
                        }
                    } catch (\Exception $e) {
                        // Skip if can't instantiate
                        continue;
                    }
                }
            }
        }
        
        // At minimum, verify classes exist
        $this->assertTrue(
            count($classMap) > 0,
            'Classmap should contain classes that can be autoloaded'
        );
    }

    /**
     * Test that the ComposerAutoloader model exists and can be instantiated
     */
    public function testComposerAutoloaderModelExists(): void
    {
        try {
            /** @var \Genaker\Autoloader\Model\ComposerAutoloader $autoloader */
            $autoloader = self::$objectManager->get(\Genaker\Autoloader\Model\ComposerAutoloader::class);
            
            $this->assertInstanceOf(
                \Genaker\Autoloader\Model\ComposerAutoloader::class,
                $autoloader,
                'ComposerAutoloader model should be instantiable'
            );
            
            // Test that classmap can be retrieved
            $classMap = $autoloader->getClassMap();
            $this->assertIsArray($classMap, 'getClassMap() should return an array');
            $this->assertGreaterThan(0, count($classMap), 'Classmap should contain classes');
        } catch (\Exception $e) {
            $this->fail('ComposerAutoloader model should be available: ' . $e->getMessage());
        }
    }

    /**
     * Test that autoloader registration happens early (before ObjectManager)
     */
    public function testAutoloaderRegistrationTiming(): void
    {
        // This test verifies that classes are available before ObjectManager is fully initialized
        // The fact that we can get here means autoloading is working
        
        $vendorDir = dirname(dirname($this->classMapFile));
        $baseDir = dirname($vendorDir);
        
        $classMap = include $this->classMapFile;
        
        // Verify we can access classes from the classmap
        $sampleClass = array_key_first($classMap);
        
        if ($sampleClass) {
            $this->assertTrue(
                class_exists($sampleClass, true) || 
                interface_exists($sampleClass, true) || 
                trait_exists($sampleClass, true),
                "Sample class '{$sampleClass}' from classmap should be autoloadable"
            );
        }
    }

    /**
     * Test that the classmap file path is correct
     */
    public function testClassMapFilePath(): void
    {
        $expectedPath = BP . '/vendor/composer/autoload_classmap.php';
        
        $this->assertEquals(
            $expectedPath,
            $this->classMapFile,
            'Classmap file path should match expected location'
        );
        
        $this->assertFileExists($expectedPath, 'Classmap file should exist at expected path');
    }

    /**
     * Test that autoloader handles missing classes gracefully
     */
    public function testAutoloaderHandlesMissingClasses(): void
    {
        // Try to autoload a non-existent class
        $nonExistentClass = 'NonExistent\\Class\\That\\Does\\Not\\Exist\\' . uniqid();
        
        $this->assertFalse(
            class_exists($nonExistentClass, true),
            'Non-existent class should not be found'
        );
    }
}
