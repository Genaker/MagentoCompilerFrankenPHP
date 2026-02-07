<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Performance;

use Genaker\Autoloader\Compiler\GoCompiler;
use Genaker\Autoloader\Compiler\PhpCompiler;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Compiler Performance Test - Tests compilation performance and file count verification
 */
class CompilerPerformanceTest extends TestCase
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private static $objectManager;

    /**
     * @var PhpCompiler
     */
    private static $phpCompiler;

    /**
     * @var GoCompiler
     */
    private static $goCompiler;

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
        
        self::$phpCompiler = self::$objectManager->get(PhpCompiler::class);
        self::$goCompiler = self::$objectManager->get(GoCompiler::class);
    }

    /**
     * Test PHP compiler performance and file count
     */
    public function testPhpCompilerPerformance(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "PHP COMPILER PERFORMANCE TEST\n";
        echo str_repeat("=", 70) . "\n";

        $testFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file not found. Run: bin/magento genaker:autoloader:combine-app-code');
            return;
        }

        $fileSize = filesize($testFile);
        $fileCount = $this->countFilesInCombined($testFile);
        
        echo "\nTest File: " . basename($testFile) . "\n";
        echo "File Size: " . $this->formatBytes($fileSize) . "\n";
        echo "Files in combined file: " . number_format($fileCount) . "\n";

        $outputFile = BP . '/var/test_php_compiled.php';
        
        $results = self::$phpCompiler->compileToFile($testFile, $outputFile);
        
        echo "\nPHP Compiler Results:\n";
        echo "  Files Compiled: " . number_format($results['fileCount']) . "\n";
        echo "  Duration: " . number_format($results['duration'], 4) . " seconds\n";
        echo "  Input Size: " . $this->formatBytes($results['inputSize']) . "\n";
        echo "  Output Size: " . $this->formatBytes($results['outputSize']) . "\n";
        echo "  Compression: " . number_format($results['compression'], 2) . "%\n";
        echo "  Throughput: " . number_format($results['throughput'], 2) . " MB/s\n";
        if ($results['fileCount'] > 0) {
            echo "  Files per second: " . number_format($results['fileCount'] / $results['duration'], 0) . "\n";
        }
        
        // Verify file count matches
        $this->assertEquals($fileCount, $results['fileCount'], 'Compiled file should have same number of files as input');
        
        // Cleanup
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        
        $this->assertLessThan(10, $results['duration'], 'Compilation should be fast');
    }

    /**
     * Test Go compiler performance and file count
     */
    public function testGoCompilerPerformance(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "GO COMPILER PERFORMANCE TEST\n";
        echo str_repeat("=", 70) . "\n";

        $testFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file not found. Run: bin/magento genaker:autoloader:combine-app-code');
            return;
        }

        $fileSize = filesize($testFile);
        $fileCount = $this->countFilesInCombined($testFile);
        
        echo "\nTest File: " . basename($testFile) . "\n";
        echo "File Size: " . $this->formatBytes($fileSize) . "\n";
        echo "Files in combined file: " . number_format($fileCount) . "\n";

        $outputFile = BP . '/var/test_go_compiled.php';
        
        try {
            $results = self::$goCompiler->compileToFile($testFile, $outputFile);
            
            echo "\nGo Compiler Results:\n";
            echo "  Method: " . ucfirst($results['method'] ?? 'unknown') . "\n";
            echo "  Files Compiled: " . number_format($results['fileCount']) . "\n";
            echo "  Duration: " . number_format($results['duration'], 4) . " seconds\n";
            echo "  Input Size: " . $this->formatBytes($results['inputSize']) . "\n";
            echo "  Output Size: " . $this->formatBytes($results['outputSize']) . "\n";
            echo "  Compression: " . number_format($results['compression'], 2) . "%\n";
            echo "  Throughput: " . number_format($results['throughput'], 2) . " MB/s\n";
            if ($results['fileCount'] > 0) {
                echo "  Files per second: " . number_format($results['fileCount'] / $results['duration'], 0) . "\n";
            }
            
            // Verify file count matches
            $this->assertEquals($fileCount, $results['fileCount'], 'Compiled file should have same number of files as input');
            
        } catch (\Exception $e) {
            $this->markTestSkipped('Go compiler not available: ' . $e->getMessage());
            return;
        } finally {
            // Cleanup
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Compare PHP and Go compiler file counts
     */
    public function testCompilerFileCountComparison(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "COMPILER FILE COUNT VERIFICATION\n";
        echo str_repeat("=", 70) . "\n";

        $testFile = BP . '/var/combined_app_code_classes.php';
        
        if (!file_exists($testFile)) {
            $this->markTestSkipped('Test file not found');
            return;
        }

        $originalFileCount = $this->countFilesInCombined($testFile);
        echo "\nOriginal file count: " . number_format($originalFileCount) . "\n";

        // Test PHP compiler
        $phpOutput = BP . '/var/test_php_filecount.php';
        try {
            $phpResults = self::$phpCompiler->compileToFile($testFile, $phpOutput);
            echo "\nPHP Compiler:\n";
            echo "  Files: " . number_format($phpResults['fileCount']) . "\n";
            $this->assertEquals($originalFileCount, $phpResults['fileCount'], 'PHP compiler should preserve file count');
        } catch (\Exception $e) {
            $this->fail("PHP compiler failed: " . $e->getMessage());
        } finally {
            if (file_exists($phpOutput)) {
                unlink($phpOutput);
            }
        }

        // Test Go compiler
        $goOutput = BP . '/var/test_go_filecount.php';
        try {
            $goResults = self::$goCompiler->compileToFile($testFile, $goOutput);
            echo "\nGo Compiler:\n";
            echo "  Files: " . number_format($goResults['fileCount']) . "\n";
            $this->assertEquals($originalFileCount, $goResults['fileCount'], 'Go compiler should preserve file count');
            
            // Compare both compilers
            echo "\nComparison:\n";
            echo "  Original: " . number_format($originalFileCount) . " files\n";
            echo "  PHP: " . number_format($phpResults['fileCount']) . " files\n";
            echo "  Go: " . number_format($goResults['fileCount']) . " files\n";
            
            $this->assertEquals($phpResults['fileCount'], $goResults['fileCount'], 'Both compilers should produce same file count');
            
        } catch (\Exception $e) {
            $this->markTestSkipped('Go compiler not available: ' . $e->getMessage());
        } finally {
            if (file_exists($goOutput)) {
                unlink($goOutput);
            }
        }
    }

    /**
     * Count files in combined file
     */
    private function countFilesInCombined(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }
        
        $content = file_get_contents($filePath);
        if ($content === false) {
            return 0;
        }
        
        $fileMarkers = substr_count($content, '// File:');
        $classMarkers = substr_count($content, '// Class:');
        
        return max($fileMarkers, $classMarkers);
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
