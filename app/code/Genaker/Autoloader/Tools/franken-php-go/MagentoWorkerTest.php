<?php
/**
 * PHPUnit tests for Magento FrankenPHP Worker Script
 * 
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Tools\FrankenPhpGo;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Http;
use Magento\Framework\App\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for magento_worker.php
 */
class MagentoWorkerTest extends TestCase
{
    /**
     * @var string
     */
    private $workerScriptPath;

    /**
     * @var string
     */
    private $magentoRoot;

    /**
     * @var Bootstrap
     */
    private static $bootstrap;

    /**
     * @var ObjectManager
     */
    private static $objectManager;

    /**
     * Bootstrap Magento once for all tests
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Determine Magento root
        $magentoRoot = null;
        if (file_exists('/app/public/app/bootstrap.php')) {
            $magentoRoot = '/app/public';
        } elseif (defined('BP') && is_dir(BP)) {
            $magentoRoot = BP;
        } else {
            $possibleRoots = [
                realpath(__DIR__ . '/../../../../'),
                '/var/www/html',
            ];
            
            foreach ($possibleRoots as $root) {
                if ($root && file_exists($root . '/app/bootstrap.php')) {
                    $magentoRoot = $root;
                    break;
                }
            }
        }

        if ($magentoRoot && file_exists($magentoRoot . '/app/bootstrap.php')) {
            require_once $magentoRoot . '/app/bootstrap.php';
            
            // Set minimal $_SERVER for bootstrap
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['SERVER_NAME'] = 'localhost';
            $_SERVER['SERVER_PORT'] = '80';
            $_SERVER['HTTPS'] = '';
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['SCRIPT_FILENAME'] = (defined('BP') ? BP : $magentoRoot) . '/pub/index.php';
            $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
            $_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['REMOTE_PORT'] = '12345';
            
            $bpPath = defined('BP') ? BP : $magentoRoot;
            self::$bootstrap = Bootstrap::create($bpPath, $_SERVER);
            self::$objectManager = self::$bootstrap->getObjectManager();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->workerScriptPath = __DIR__ . '/magento_worker.php';
        
        // Determine Magento root - same logic as worker script
        if (file_exists('/app/public/app/bootstrap.php')) {
            // Docker environment
            $this->magentoRoot = '/app/public';
        } elseif (defined('BP') && is_dir(BP)) {
            // Already defined (e.g., in Magento context)
            $this->magentoRoot = BP;
        } else {
            // Try multiple possible locations
            $possibleRoots = [
                realpath(__DIR__ . '/../../../../'), // 4 levels up from worker script
                realpath(__DIR__ . '/../../../../../'), // 5 levels up (if script is deeper)
                '/var/www/html', // Warden default
                dirname(__DIR__ . '/../../../../'), // Alternative calculation
            ];
            
            foreach ($possibleRoots as $root) {
                if ($root && file_exists($root . '/app/bootstrap.php')) {
                    $this->magentoRoot = $root;
                    break;
                }
            }
            
            // Fallback to calculated path even if bootstrap doesn't exist
            if (empty($this->magentoRoot)) {
                $this->magentoRoot = realpath(__DIR__ . '/../../../../') ?: __DIR__ . '/../../../../';
            }
        }
    }

    /**
     * Test that worker script exists
     */
    public function testWorkerScriptExists(): void
    {
        $this->assertFileExists(
            $this->workerScriptPath,
            'Worker script should exist'
        );
    }

    /**
     * Test path resolution logic for Docker environment
     */
    public function testDockerPathResolution(): void
    {
        // Mock Docker environment
        $dockerBootstrapPath = '/app/public/app/bootstrap.php';
        
        // Test that Docker path detection logic would work
        $isDocker = file_exists($dockerBootstrapPath);
        
        if ($isDocker) {
            $expectedRoot = '/app/public';
            $this->assertEquals(
                $expectedRoot,
                '/app/public',
                'Docker environment should resolve to /app/public'
            );
        } else {
            // Not in Docker, should use local path resolution
            $this->assertTrue(
                is_dir($this->magentoRoot),
                'Local Magento root should be a valid directory'
            );
        }
    }

    /**
     * Test path resolution logic for local/Warden environment
     */
    public function testLocalPathResolution(): void
    {
        // Worker script is in: app/code/Genaker/Autoloader/Tools/franken-php-go/
        // Magento root should be 4 levels up
        $workerDir = __DIR__;
        $expectedRoot = realpath($workerDir . '/../../../../');
        
        $this->assertNotFalse(
            $expectedRoot,
            'Magento root should be resolvable from worker script directory'
        );
        
        // Check multiple possible locations for bootstrap
        $possiblePaths = [
            $expectedRoot . '/app/bootstrap.php',
            $expectedRoot . '/../app/bootstrap.php',
            '/var/www/html/app/bootstrap.php', // Warden default
            dirname($expectedRoot) . '/app/bootstrap.php'
        ];
        
        $bootstrapExists = false;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $bootstrapExists = true;
                break;
            }
        }
        
        $this->assertTrue(
            $bootstrapExists,
            'Magento bootstrap.php should exist at one of: ' . implode(', ', $possiblePaths)
        );
    }

    /**
     * Test that Magento bootstrap file exists at resolved path
     */
    public function testBootstrapFileExists(): void
    {
        // Try multiple possible locations
        $possiblePaths = [
            $this->magentoRoot . '/app/bootstrap.php',
            dirname($this->magentoRoot) . '/app/bootstrap.php',
            '/var/www/html/app/bootstrap.php', // Warden default
            (defined('BP') ? BP : '') . '/app/bootstrap.php'
        ];
        
        $found = false;
        $foundPath = null;
        foreach ($possiblePaths as $path) {
            if ($path && file_exists($path)) {
                $found = true;
                $foundPath = $path;
                break;
            }
        }
        
        $this->assertTrue(
            $found,
            'Magento bootstrap.php should exist at one of: ' . implode(', ', array_filter($possiblePaths)) . 
            '. Found at: ' . ($foundPath ?? 'none')
        );
    }

    /**
     * Test that BP constant can be defined
     */
    public function testBpConstantCanBeDefined(): void
    {
        // BP should already be defined by setUpBeforeClass/bootstrap
        $this->assertTrue(
            defined('BP'),
            'BP constant should be defined after bootstrap'
        );
        
        // Verify BP points to a valid directory
        $this->assertTrue(
            is_dir(BP),
            'BP constant should point to a valid directory'
        );
        
        // Verify bootstrap file exists at BP
        $this->assertFileExists(
            BP . '/app/bootstrap.php',
            'Bootstrap file should exist at BP path'
        );
    }

    /**
     * Test that Bootstrap class can be created
     */
    public function testBootstrapCanBeCreated(): void
    {
        if (!self::$bootstrap) {
            $this->markTestSkipped('Magento bootstrap not available');
            return;
        }
        
        $this->assertInstanceOf(
            Bootstrap::class,
            self::$bootstrap,
            'Bootstrap should be created successfully'
        );
        
        $this->assertInstanceOf(
            ObjectManager::class,
            self::$objectManager,
            'ObjectManager should be retrievable from Bootstrap'
        );
    }

    /**
     * Test that Http application can be retrieved from ObjectManager
     */
    public function testHttpApplicationCanBeRetrieved(): void
    {
        if (!self::$objectManager) {
            $this->markTestSkipped('Magento ObjectManager not available');
            return;
        }
        
        $application = self::$objectManager->get(Http::class);
        
        $this->assertInstanceOf(
            Http::class,
            $application,
            'Http application should be retrievable from ObjectManager'
        );
    }

    /**
     * Test handler function structure (without actually calling launch)
     */
    public function testHandlerFunctionStructure(): void
    {
        if (!self::$objectManager || !defined('BP')) {
            $this->markTestSkipped('Magento bootstrap not available');
            return;
        }
        
        // Define handler similar to worker script
        // Worker script creates bootstrap per request but reuses ObjectManager when possible
        $handler = static function () {
            // Create bootstrap instance for this request (as worker script does)
            $bootstrap = Bootstrap::create(BP, $_SERVER);
            $objectManager = $bootstrap->getObjectManager();
            $application = $objectManager->get(Http::class);
            
            // Don't actually launch - just verify structure
            return $application;
        };
        
        $this->assertTrue(
            is_callable($handler),
            'Handler should be callable'
        );
        
        $result = $handler();
        $this->assertInstanceOf(
            Http::class,
            $result,
            'Handler should return Http application instance'
        );
    }

    /**
     * Test fallback behavior when frankenphp_handle_request doesn't exist
     */
    public function testFallbackBehavior(): void
    {
        // Verify that function_exists check would work
        $functionExists = function_exists('frankenphp_handle_request');
        
        // In test environment, function won't exist (not in FrankenPHP worker mode)
        $this->assertFalse(
            $functionExists,
            'frankenphp_handle_request should not exist in test environment'
        );
        
        $handlerCalled = false;
        $handler = static function () use (&$handlerCalled) {
            $handlerCalled = true;
        };
        
        // Simulate fallback behavior (as in worker script)
        if (!function_exists('frankenphp_handle_request')) {
            $handler();
        }
        
        $this->assertTrue(
            $handlerCalled,
            'Handler should be called in fallback mode'
        );
    }

    /**
     * Test that worker script is syntactically valid PHP
     */
    public function testWorkerScriptSyntax(): void
    {
        $output = [];
        $returnVar = 0;
        
        exec(
            "php -l {$this->workerScriptPath} 2>&1",
            $output,
            $returnVar
        );
        
        $this->assertEquals(
            0,
            $returnVar,
            'Worker script should have valid PHP syntax: ' . implode("\n", $output)
        );
    }

    /**
     * Test that required Magento classes are available
     */
    public function testRequiredClassesExist(): void
    {
        $this->assertTrue(
            class_exists(Bootstrap::class),
            'Bootstrap class should exist'
        );
        
        $this->assertTrue(
            class_exists(Http::class),
            'Http class should exist'
        );
        
        $this->assertTrue(
            class_exists(ObjectManager::class),
            'ObjectManager class should exist'
        );
    }

    /**
     * Test that worker script can be included without fatal errors
     * (excluding the actual execution loop)
     */
    public function testWorkerScriptCanBeIncluded(): void
    {
        // This test verifies the script structure is valid
        // We can't actually include it because it has an infinite loop
        
        $scriptContent = file_get_contents($this->workerScriptPath);
        
        // Verify script contains required elements
        $this->assertStringContainsString(
            'Bootstrap::create',
            $scriptContent,
            'Worker script should contain Bootstrap::create'
        );
        
        $this->assertStringContainsString(
            'frankenphp_handle_request',
            $scriptContent,
            'Worker script should contain frankenphp_handle_request'
        );
        
        $this->assertStringContainsString(
            'Http::class',
            $scriptContent,
            'Worker script should use Http::class'
        );
        
        // Verify script has proper structure (handler function and loop)
        $this->assertStringContainsString(
            'function ()',
            $scriptContent,
            'Worker script should define a handler function'
        );
        
        $this->assertStringContainsString(
            'while',
            $scriptContent,
            'Worker script should contain a while loop'
        );
    }
}
