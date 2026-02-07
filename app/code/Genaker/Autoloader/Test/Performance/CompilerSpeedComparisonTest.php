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
 * Compiler Speed Comparison Test - Compares PHP vs Go compiler performance
 */
class CompilerSpeedComparisonTest extends TestCase
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
     * Compare PHP and Go compiler speeds
     */
    public function testCompilerSpeedComparison(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "COMPILER SPEED COMPARISON TEST\n";
        echo str_repeat("=", 80) . "\n";

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
        echo "\n" . str_repeat("-", 80) . "\n";

        // Test PHP Compiler
        $phpOutput = BP . '/var/test_php_speed.php';
        echo "\n[PHP COMPILER]\n";
        $phpStart = microtime(true);
        try {
            $phpResults = self::$phpCompiler->compileToFile($testFile, $phpOutput);
            $phpEnd = microtime(true);
            $phpTotalTime = $phpEnd - $phpStart;
            
            echo "  Status: ✓ Success\n";
            echo "  Duration: " . number_format($phpResults['duration'], 4) . " seconds\n";
            echo "  Total Time (with I/O): " . number_format($phpTotalTime, 4) . " seconds\n";
            echo "  Input Size: " . $this->formatBytes($phpResults['inputSize']) . "\n";
            echo "  Output Size: " . $this->formatBytes($phpResults['outputSize']) . "\n";
            echo "  Compression: " . number_format($phpResults['compression'], 2) . "%\n";
            echo "  Throughput: " . number_format($phpResults['throughput'], 2) . " MB/s\n";
            echo "  Files Compiled: " . number_format($phpResults['fileCount']) . "\n";
            if ($phpResults['fileCount'] > 0) {
                echo "  Files per second: " . number_format($phpResults['fileCount'] / $phpResults['duration'], 0) . "\n";
            }
            
            $phpSuccess = true;
        } catch (\Exception $e) {
            echo "  Status: ✗ Failed - " . $e->getMessage() . "\n";
            $phpSuccess = false;
            $phpResults = null;
        } finally {
            if (file_exists($phpOutput)) {
                unlink($phpOutput);
            }
        }

        // Test Go Compiler
        $goOutput = BP . '/var/test_go_speed.php';
        echo "\n[GO COMPILER]\n";
        $goStart = microtime(true);
        try {
            $goResults = self::$goCompiler->compileToFile($testFile, $goOutput);
            $goEnd = microtime(true);
            $goTotalTime = $goEnd - $goStart;
            
            echo "  Status: ✓ Success\n";
            echo "  Method: " . ucfirst($goResults['method'] ?? 'unknown') . "\n";
            echo "  Duration: " . number_format($goResults['duration'], 4) . " seconds\n";
            echo "  Total Time (with I/O): " . number_format($goTotalTime, 4) . " seconds\n";
            echo "  Input Size: " . $this->formatBytes($goResults['inputSize']) . "\n";
            echo "  Output Size: " . $this->formatBytes($goResults['outputSize']) . "\n";
            echo "  Compression: " . number_format($goResults['compression'], 2) . "%\n";
            echo "  Throughput: " . number_format($goResults['throughput'], 2) . " MB/s\n";
            echo "  Files Compiled: " . number_format($goResults['fileCount']) . "\n";
            if ($goResults['fileCount'] > 0) {
                echo "  Files per second: " . number_format($goResults['fileCount'] / $goResults['duration'], 0) . "\n";
            }
            
            $goSuccess = true;
        } catch (\Exception $e) {
            echo "  Status: ✗ Failed - " . $e->getMessage() . "\n";
            $goSuccess = false;
            $goResults = null;
        } finally {
            if (file_exists($goOutput)) {
                unlink($goOutput);
            }
        }

        // Comparison
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "COMPARISON SUMMARY\n";
        echo str_repeat("=", 80) . "\n";

        if ($phpSuccess && $goSuccess) {
            $speedDifference = (($phpResults['duration'] - $goResults['duration']) / $goResults['duration']) * 100;
            $throughputDifference = (($goResults['throughput'] - $phpResults['throughput']) / $phpResults['throughput']) * 100;
            
            echo "\nSpeed Comparison:\n";
            echo "  PHP Duration: " . number_format($phpResults['duration'], 4) . "s\n";
            echo "  Go Duration: " . number_format($goResults['duration'], 4) . "s\n";
            
            if ($phpResults['duration'] < $goResults['duration']) {
                $faster = "PHP";
                $speedup = ($goResults['duration'] / $phpResults['duration']);
                echo "  → PHP is " . number_format($speedup, 2) . "x FASTER than Go\n";
            } else {
                $faster = "Go";
                $speedup = ($phpResults['duration'] / $goResults['duration']);
                echo "  → Go is " . number_format($speedup, 2) . "x FASTER than PHP\n";
            }
            
            echo "\nThroughput Comparison:\n";
            echo "  PHP Throughput: " . number_format($phpResults['throughput'], 2) . " MB/s\n";
            echo "  Go Throughput: " . number_format($goResults['throughput'], 2) . " MB/s\n";
            
            if ($phpResults['throughput'] > $goResults['throughput']) {
                echo "  → PHP has " . number_format(abs($throughputDifference), 2) . "% HIGHER throughput\n";
            } else {
                echo "  → Go has " . number_format(abs($throughputDifference), 2) . "% HIGHER throughput\n";
            }
            
            echo "\nFile Count Verification:\n";
            echo "  Original: " . number_format($fileCount) . " files\n";
            echo "  PHP Output: " . number_format($phpResults['fileCount']) . " files\n";
            echo "  Go Output: " . number_format($goResults['fileCount']) . " files\n";
            
            $this->assertEquals($fileCount, $phpResults['fileCount'], 'PHP compiler should preserve file count');
            $this->assertEquals($fileCount, $goResults['fileCount'], 'Go compiler should preserve file count');
            $this->assertEquals($phpResults['fileCount'], $goResults['fileCount'], 'Both compilers should produce same file count');
            
        } elseif ($phpSuccess) {
            echo "\nOnly PHP compiler succeeded. Go compiler is not available.\n";
            $this->assertTrue(true);
        } elseif ($goSuccess) {
            echo "\nOnly Go compiler succeeded. PHP compiler failed.\n";
            $this->assertTrue(true);
        } else {
            $this->fail("Both compilers failed");
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
