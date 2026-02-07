<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Psr\Log\LoggerInterface;

/**
 * Composer Autoloader - Ensures composer autoload_classmap is properly loaded
 */
class ComposerAutoloader
{
    private const CLASSMAP_FILE = 'vendor/composer/autoload_classmap.php';
    
    private ?array $classMap = null;
    private bool $isRegistered = false;

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly ReadFactory $readFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Register autoloader early
     */
    public function register(): void
    {
        if ($this->isRegistered) {
            return;
        }

        try {
            $this->loadClassMap();
            $this->registerAutoloader();
            $this->isRegistered = true;
        } catch (\Exception $e) {
            $this->logger->error('Genaker_Autoloader: Failed to register autoloader', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Load the composer autoload_classmap.php file
     */
    private function loadClassMap(): void
    {
        if ($this->classMap !== null) {
            return;
        }

        $rootDir = $this->directoryList->getRoot();
        $classMapPath = $rootDir . '/' . self::CLASSMAP_FILE;

        if (!file_exists($classMapPath)) {
            $this->logger->warning('Genaker_Autoloader: Classmap file not found', [
                'path' => $classMapPath
            ]);
            $this->classMap = [];
            return;
        }

        try {
            // Load the classmap file
            $vendorDir = dirname(dirname($classMapPath));
            $baseDir = dirname($vendorDir);
            
            // Temporarily set variables for the classmap file
            $originalVendorDir = isset($GLOBALS['vendorDir']) ? $GLOBALS['vendorDir'] : null;
            $originalBaseDir = isset($GLOBALS['baseDir']) ? $GLOBALS['baseDir'] : null;
            
            $GLOBALS['vendorDir'] = $vendorDir;
            $GLOBALS['baseDir'] = $baseDir;
            
            $classMap = include $classMapPath;
            
            // Restore original values
            if ($originalVendorDir !== null) {
                $GLOBALS['vendorDir'] = $originalVendorDir;
            } else {
                unset($GLOBALS['vendorDir']);
            }
            
            if ($originalBaseDir !== null) {
                $GLOBALS['baseDir'] = $originalBaseDir;
            } else {
                unset($GLOBALS['baseDir']);
            }
            
            $this->classMap = is_array($classMap) ? $classMap : [];
            
            $this->logger->info('Genaker_Autoloader: Classmap loaded', [
                'count' => count($this->classMap)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Genaker_Autoloader: Failed to load classmap', [
                'error' => $e->getMessage(),
                'path' => $classMapPath
            ]);
            $this->classMap = [];
        }
    }

    /**
     * Register custom autoloader function
     */
    private function registerAutoloader(): void
    {
        spl_autoload_register(function ($className) {
            if (isset($this->classMap[$className])) {
                $file = $this->classMap[$className];
                
                // Handle relative paths
                if (!file_exists($file) && strpos($file, 'vendor/') === 0) {
                    $rootDir = $this->directoryList->getRoot();
                    $file = $rootDir . '/' . $file;
                }
                
                if (file_exists($file)) {
                    require_once $file;
                    return true;
                }
            }
            
            return false;
        }, true, true); // Prepend to autoload stack, throw exceptions
    }

    /**
     * Get loaded classmap
     */
    public function getClassMap(): array
    {
        if ($this->classMap === null) {
            $this->loadClassMap();
        }
        return $this->classMap ?? [];
    }

    /**
     * Check if a class exists in the classmap
     */
    public function classExists(string $className): bool
    {
        if ($this->classMap === null) {
            $this->loadClassMap();
        }
        return isset($this->classMap[$className]);
    }
}
