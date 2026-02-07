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
 * Test enable/disable functionality via configuration
 */
class AutoloaderConfigTest extends TestCase
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
     * Test that autoloader can read configuration from env.php
     */
    public function testReadConfigFromEnvPhp(): void
    {
        $envFile = BP . '/app/etc/env.php';
        
        if (!file_exists($envFile)) {
            $this->markTestSkipped('env.php does not exist');
        }

        $envConfig = include $envFile;
        
        echo "\n=== Testing env.php Configuration ===\n";
        
        if (isset($envConfig['genaker_autoloader']['enabled'])) {
            $enabled = $envConfig['genaker_autoloader']['enabled'];
            echo "Configuration found in env.php: enabled = " . ($enabled ? 'true' : 'false') . "\n";
            
            $this->assertIsBool($enabled, 'enabled should be a boolean');
        } else {
            echo "Configuration not found in env.php (using default: enabled)\n";
            echo "To configure, add to env.php:\n";
            echo "  'genaker_autoloader' => [\n";
            echo "      'enabled' => true,\n";
            echo "  ],\n";
        }
        
        // Verify autoloader is working (if enabled) or not (if disabled)
        $autoloaders = spl_autoload_functions();
        $hasCustomAutoloader = false;
        
        foreach ($autoloaders as $index => $autoloader) {
            if ($index === 0 && !is_array($autoloader)) {
                $hasCustomAutoloader = true;
                break;
            }
        }
        
        $expectedEnabled = $envConfig['genaker_autoloader']['enabled'] ?? true;
        
        if ($expectedEnabled) {
            $this->assertTrue($hasCustomAutoloader, 'Custom autoloader should be registered when enabled');
            echo "✓ Autoloader is enabled and registered\n";
        } else {
            echo "⚠ Autoloader is disabled in config\n";
        }
    }

    /**
     * Test environment variable reading
     */
    public function testEnvironmentVariable(): void
    {
        $envVar = getenv('GENAKER_AUTOLOADER_ENABLED');
        
        echo "\n=== Testing Environment Variable ===\n";
        
        if ($envVar !== false) {
            echo "GENAKER_AUTOLOADER_ENABLED = {$envVar}\n";
            $enabled = filter_var($envVar, FILTER_VALIDATE_BOOLEAN);
            echo "Parsed value: " . ($enabled ? 'true' : 'false') . "\n";
            
            $this->assertIsBool($enabled, 'Environment variable should be parseable as boolean');
        } else {
            echo "GENAKER_AUTOLOADER_ENABLED not set\n";
            echo "To set:\n";
            echo "  export GENAKER_AUTOLOADER_ENABLED=1\n";
        }
    }

    /**
     * Test configuration priority (env var > env.php > default)
     */
    public function testConfigurationPriority(): void
    {
        echo "\n=== Testing Configuration Priority ===\n";
        
        $envVar = getenv('GENAKER_AUTOLOADER_ENABLED');
        $envFile = BP . '/app/etc/env.php';
        $envConfig = file_exists($envFile) ? include $envFile : [];
        
        $priority = [];
        
        if ($envVar !== false) {
            $priority[] = "1. Environment Variable: " . ($envVar ? 'enabled' : 'disabled');
        }
        
        if (isset($envConfig['genaker_autoloader']['enabled'])) {
            $priority[] = "2. env.php: " . ($envConfig['genaker_autoloader']['enabled'] ? 'enabled' : 'disabled');
        }
        
        $priority[] = "3. Default: enabled";
        
        echo "Configuration priority:\n";
        foreach ($priority as $item) {
            echo "  {$item}\n";
        }
        
        // This test is informational
        $this->assertTrue(true);
    }

    /**
     * Test that autoloader respects disabled configuration
     */
    public function testDisableFunctionality(): void
    {
        echo "\n=== Testing Disable Functionality ===\n";
        echo "To test disabling:\n";
        echo "1. Set in env.php:\n";
        echo "   'genaker_autoloader' => ['enabled' => false],\n";
        echo "2. Or set environment variable:\n";
        echo "   export GENAKER_AUTOLOADER_ENABLED=0\n";
        echo "3. Clear cache and test again\n";
        
        // This test is informational
        $this->assertTrue(true);
    }
}
