<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Performance;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use PHPUnit\Framework\TestCase;

/**
 * Compiler Performance Comparison Test
 * Compares Go-based compiler vs PHP-based compiler performance
 */
class CompilerComparisonTest extends TestCase
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
     * Compare Go vs PHP compiler performance
     */
    public function testCompilerPerformanceComparison(): void
    {
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "COMPILER PERFORMANCE COMPARISON: GO vs PHP\n";
        echo str_repeat("=", 70) . "\n";

        // Test both app code and vendor files
        $appCodeFile = BP . '/var/combined_app_code_classes.php';
        $vendorFile = BP . '/var/combined_vendor_classes.php';
        
        $results = [];
        
        // Test App Code file
        if (file_exists($appCodeFile)) {
            echo "\n--- Testing App Code File ---\n";
            $fileSize = filesize($appCodeFile);
            $fileCount = $this->countFilesInCombined($appCodeFile);
            echo "File: " . basename($appCodeFile) . "\n";
            echo "Size: " . $this->formatBytes($fileSize) . "\n";
            echo "Files: " . number_format($fileCount) . "\n";
            
            echo "\n  PHP Compiler:\n";
            $phpResults = $this->testPhpCompiler($appCodeFile);
            $results['app_code']['php'] = $phpResults;
            $results['app_code']['file_count'] = $fileCount;
            
            echo "\n  Go Compiler:\n";
            $goResults = $this->testGoCompiler($appCodeFile);
            $results['app_code']['go'] = $goResults;
        }
        
        // Test Vendor file
        if (file_exists($vendorFile)) {
            echo "\n--- Testing Vendor File ---\n";
            $fileSize = filesize($vendorFile);
            $fileCount = $this->countFilesInCombined($vendorFile);
            echo "File: " . basename($vendorFile) . "\n";
            echo "Size: " . $this->formatBytes($fileSize) . "\n";
            echo "Files: " . number_format($fileCount) . "\n";
            
            echo "\n  PHP Compiler:\n";
            $phpResults = $this->testPhpCompiler($vendorFile);
            $results['vendor']['php'] = $phpResults;
            $results['vendor']['file_count'] = $fileCount;
            
            echo "\n  Go Compiler:\n";
            $goResults = $this->testGoCompiler($vendorFile);
            $results['vendor']['go'] = $goResults;
        }
        
        // Summary Comparison
        echo "\n" . str_repeat("=", 70) . "\n";
        echo "SUMMARY COMPARISON\n";
        echo str_repeat("=", 70) . "\n";
        
        if (isset($results['app_code'])) {
            $this->printComparison('App Code', $results['app_code']);
        }
        
        if (isset($results['vendor'])) {
            $this->printComparison('Vendor', $results['vendor']);
        }
        
        // Overall totals
        if (isset($results['app_code']) && isset($results['vendor'])) {
            $totalFiles = $results['app_code']['file_count'] + $results['vendor']['file_count'];
            $totalPhpTime = $results['app_code']['php']['duration'] + $results['vendor']['php']['duration'];
            $totalGoTime = null;
            if ($results['app_code']['go'] && $results['vendor']['go']) {
                $totalGoTime = $results['app_code']['go']['duration'] + $results['vendor']['go']['duration'];
            }
            
            echo "\n--- Overall Totals ---\n";
            echo "Total Files: " . number_format($totalFiles) . "\n";
            echo "PHP Total Time: " . number_format($totalPhpTime, 4) . " seconds\n";
            if ($totalGoTime !== null) {
                echo "Go Total Time: " . number_format($totalGoTime, 4) . " seconds\n";
                $improvement = (($totalPhpTime - $totalGoTime) / $totalPhpTime) * 100;
                echo "Overall Speedup: " . number_format(abs($improvement), 2) . "% " . 
                     ($improvement > 0 ? 'faster' : 'slower') . "\n";
                echo "Overall Speedup: " . number_format($totalPhpTime / $totalGoTime, 2) . "x\n";
            }
        }
        
        $this->assertTrue(true); // Informational test
    }

    /**
     * Print comparison results
     */
    private function printComparison(string $label, array $data): void
    {
        $fileCount = $data['file_count'];
        $phpResults = $data['php'];
        $goResults = $data['go'] ?? null;
        
        echo "\n--- {$label} Files ({$fileCount} files) ---\n";
        
        echo "\nPHP Compiler:\n";
        echo "  Files Compiled: " . number_format($fileCount) . "\n";
        echo "  Duration: " . number_format($phpResults['duration'], 4) . " seconds\n";
        echo "  Output Size: " . $this->formatBytes($phpResults['outputSize']) . "\n";
        echo "  Compression: " . number_format($phpResults['compression'], 2) . "%\n";
        echo "  Throughput: " . number_format($phpResults['throughput'], 2) . " MB/s\n";
        if ($fileCount > 0) {
            echo "  Files per second: " . number_format($fileCount / $phpResults['duration'], 0) . "\n";
        }
        
        echo "\nGo Compiler:\n";
        if ($goResults !== null) {
            $method = $goResults['method'] ?? 'unknown';
            echo "  Method: " . ucfirst($method) . "\n";
            echo "  Files Compiled: " . number_format($fileCount) . "\n";
            echo "  Duration: " . number_format($goResults['duration'], 4) . " seconds\n";
            echo "  Output Size: " . $this->formatBytes($goResults['outputSize']) . "\n";
            echo "  Compression: " . number_format($goResults['compression'], 2) . "%\n";
            echo "  Throughput: " . number_format($goResults['throughput'], 2) . " MB/s\n";
            if ($fileCount > 0) {
                echo "  Files per second: " . number_format($fileCount / $goResults['duration'], 0) . "\n";
            }
            
            $speedImprovement = (($phpResults['duration'] - $goResults['duration']) / $phpResults['duration']) * 100;
            echo "\nSpeed Comparison:\n";
            echo "  Go is " . number_format(abs($speedImprovement), 2) . "% " . 
                 ($speedImprovement > 0 ? 'faster' : 'slower') . " than PHP\n";
            
            if ($speedImprovement > 0) {
                $speedup = $phpResults['duration'] / $goResults['duration'];
                echo "  Speedup: " . number_format($speedup, 2) . "x faster\n";
            }
        } else {
            echo "  Status: Go compiler not available\n";
        }
    }

    /**
     * Test PHP compiler performance
     */
    private function testPhpCompiler(string $inputFile): array
    {
        $outputFile = BP . '/var/test_compiled_php.php';
        
        $startTime = microtime(true);
        
        // Read file
        $content = file_get_contents($inputFile);
        $inputSize = strlen($content);
        
        // Compile using PHP
        $compiled = $this->compilePhp($content);
        
        // Write output
        file_put_contents($outputFile, $compiled);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        $outputSize = filesize($outputFile);
        $compression = (1 - ($outputSize / $inputSize)) * 100;
        $throughput = ($inputSize / 1024 / 1024) / $duration;
        
        // Cleanup
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        
        return [
            'duration' => $duration,
            'outputSize' => $outputSize,
            'compression' => $compression,
            'throughput' => $throughput,
        ];
    }

    /**
     * Test Go compiler performance (supports both Docker and local binary)
     */
    private function testGoCompiler(string $inputFile): ?array
    {
        $outputFile = BP . '/var/test_compiled_go.php';
        
        // Try Docker first
        $useDocker = $this->isDockerAvailable();
        $dockerImage = 'genaker/go-compile-php:latest';
        
        if ($useDocker && $this->dockerImageExists($dockerImage)) {
            echo "  Using Docker: {$dockerImage}\n";
            return $this->testGoCompilerDocker($inputFile, $outputFile, $dockerImage);
        }
        
        // Fallback to local binary
        $goTool = $this->findGoTool();
        
        if (!$goTool) {
            if ($useDocker) {
                echo "  Docker available but image not built. Building...\n";
                if ($this->buildDockerImage($dockerImage)) {
                    return $this->testGoCompilerDocker($inputFile, $outputFile, $dockerImage);
                }
            }
            echo "  Go compiler not found. Install from: app/code/Genaker/Autoloader/Tools/go-compile-php\n";
            echo "  Or build Docker image: cd app/code/Genaker/Autoloader/Tools/go-compile-php && docker build -t {$dockerImage} .\n";
            return null;
        }
        
        echo "  Using local binary: {$goTool}\n";
        
        $startTime = microtime(true);
        
        // Execute Go compiler
        $command = escapeshellarg($goTool) . ' ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile);
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        if ($returnCode !== 0 || !file_exists($outputFile)) {
            echo "  Error: Go compiler failed\n";
            if (!empty($output)) {
                echo "  " . implode("\n  ", $output) . "\n";
            }
            return null;
        }
        
        $inputSize = filesize($inputFile);
        $outputSize = filesize($outputFile);
        $compression = (1 - ($outputSize / $inputSize)) * 100;
        $throughput = ($inputSize / 1024 / 1024) / $duration;
        
        // Cleanup
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        
        return [
            'duration' => $duration,
            'outputSize' => $outputSize,
            'compression' => $compression,
            'throughput' => $throughput,
            'method' => 'local',
        ];
    }

    /**
     * Test Go compiler using Docker
     */
    private function testGoCompilerDocker(string $inputFile, string $outputFile, string $dockerImage): ?array
    {
        $inputDir = dirname($inputFile);
        $inputBasename = basename($inputFile);
        $outputBasename = basename($outputFile);
        
        $startTime = microtime(true);
        
        // Run Docker container
        $dockerCmd = sprintf(
            'docker run --rm -v %s:/workspace -w /workspace %s %s %s 2>&1',
            escapeshellarg($inputDir),
            escapeshellarg($dockerImage),
            escapeshellarg($inputBasename),
            escapeshellarg($outputBasename)
        );
        
        $output = [];
        $returnCode = 0;
        exec($dockerCmd, $output, $returnCode);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        
        if ($returnCode !== 0 || !file_exists($outputFile)) {
            echo "  Error: Docker Go compiler failed\n";
            if (!empty($output)) {
                echo "  " . implode("\n  ", $output) . "\n";
            }
            return null;
        }
        
        $inputSize = filesize($inputFile);
        $outputSize = filesize($outputFile);
        $compression = (1 - ($outputSize / $inputSize)) * 100;
        $throughput = ($inputSize / 1024 / 1024) / $duration;
        
        // Cleanup
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
        
        return [
            'duration' => $duration,
            'outputSize' => $outputSize,
            'compression' => $compression,
            'throughput' => $throughput,
            'method' => 'docker',
        ];
    }

    /**
     * Find Go compiler tool
     */
    private function findGoTool(): ?string
    {
        $tool = 'go-compile-php';
        
        // Check common locations
        $paths = [
            $tool,
            '/usr/local/bin/' . $tool,
            '/usr/bin/' . $tool,
            getenv('HOME') . '/go/bin/' . $tool,
            getenv('GOPATH') . '/bin/' . $tool,
            BP . '/app/code/Genaker/Autoloader/Tools/go-compile-php/' . $tool,
        ];
        
        foreach ($paths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        // Try which command
        $which = shell_exec("which {$tool} 2>/dev/null");
        if ($which && trim($which)) {
            return trim($which);
        }
        
        return null;
    }

    /**
     * Compile PHP code (PHP implementation)
     */
    private function compilePhp(string $content): string
    {
        $result = $content;
        
        // Remove single-line comments
        $result = preg_replace('/\/\/.*$/m', '', $result);
        
        // Remove multi-line comments
        $result = preg_replace('/\/\*[\s\S]*?\*\//', '', $result);
        
        // Remove empty lines
        $result = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $result);
        
        // Optimize whitespace
        $result = preg_replace('/[ \t]+/', ' ', $result);
        
        // Remove trailing whitespace
        $lines = explode("\n", $result);
        $lines = array_map('rtrim', $lines);
        $result = implode("\n", $lines);
        
        return $result;
    }

    /**
     * Check if Docker is available
     */
    private function isDockerAvailable(): bool
    {
        $dockerCheck = shell_exec("docker --version 2>/dev/null");
        return !empty($dockerCheck);
    }

    /**
     * Check if Docker image exists
     */
    private function dockerImageExists(string $image): bool
    {
        $cmd = "docker images -q " . escapeshellarg($image) . " 2>/dev/null";
        $result = shell_exec($cmd);
        return !empty(trim($result));
    }

    /**
     * Build Docker image
     */
    private function buildDockerImage(string $image): bool
    {
        $toolDir = BP . '/app/code/Genaker/Autoloader/Tools/go-compile-php';
        
        if (!file_exists($toolDir . '/Dockerfile')) {
            return false;
        }
        
        $cmd = sprintf(
            'docker build -t %s %s 2>&1',
            escapeshellarg($image),
            escapeshellarg($toolDir)
        );
        
        exec($cmd, $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Count files in combined file by counting file markers
     */
    private function countFilesInCombined(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }
        
        // Count file markers in combined file
        // Format: "// File: " or "// Class: "
        $content = file_get_contents($filePath);
        if ($content === false) {
            return 0;
        }
        
        // Count file/class markers
        $fileMarkers = substr_count($content, '// File:');
        $classMarkers = substr_count($content, '// Class:');
        
        // Return the higher count (some files might use different markers)
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
