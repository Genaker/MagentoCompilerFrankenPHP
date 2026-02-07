<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Compiler;

/**
 * Go Compiler - Compiles PHP code using Go tool (Docker or local binary)
 */
class GoCompiler
{
    private const DEFAULT_DOCKER_IMAGE = 'genaker/go-compile-php:latest';

    /**
     * Compile file using Go tool
     */
    public function compileToFile(string $inputFile, string $outputFile, ?string $tool = null): array
    {
        $startTime = microtime(true);
        $inputSize = filesize($inputFile);
        
        // Count files BEFORE compilation (since comments are removed)
        $originalContent = file_get_contents($inputFile);
        $fileCount = $this->countFilesInContent($originalContent);
        
        // Always use local binary first (no Docker overhead)
        $goTool = $tool ?: $this->findGoTool();
        if (!$goTool) {
            throw new \RuntimeException("Go compiler not found. Build it first: cd app/code/Genaker/Autoloader/Tools/go-compile-php && go build -o go-compile-php main.go");
        }
        
        $this->compileWithBinary($inputFile, $outputFile, $goTool);
        
        if (!file_exists($outputFile)) {
            throw new \RuntimeException("Compilation failed - output file not created");
        }
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $outputSize = filesize($outputFile);
        $compression = (1 - ($outputSize / $inputSize)) * 100;
        $throughput = ($inputSize / 1024 / 1024) / $duration;
        
        return [
            'duration' => $duration,
            'inputSize' => $inputSize,
            'outputSize' => $outputSize,
            'compression' => $compression,
            'throughput' => $throughput,
            'fileCount' => $fileCount,
            'method' => 'local',
        ];
    }


    /**
     * Compile using local binary
     */
    private function compileWithBinary(string $inputFile, string $outputFile, string $tool): void
    {
        $command = escapeshellarg($tool) . ' ' . escapeshellarg($inputFile) . ' ' . escapeshellarg($outputFile);
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($outputFile)) {
            $error = implode("\n", $output);
            throw new \RuntimeException("Go compilation failed: {$error}");
        }
    }


    /**
     * Find Go compiler tool
     */
    private function findGoTool(): ?string
    {
        $tool = 'go-compile-php';
        $paths = [
            BP . '/app/code/Genaker/Autoloader/Tools/go-compile-php/' . $tool,
            $tool,
            '/usr/local/bin/' . $tool,
            '/usr/bin/' . $tool,
            getenv('HOME') . '/go/bin/' . $tool,
            getenv('GOPATH') . '/bin/' . $tool,
        ];
        
        foreach ($paths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        $which = shell_exec("which {$tool} 2>/dev/null");
        if ($which && trim($which)) {
            return trim($which);
        }
        
        return null;
    }

    /**
     * Count files in compiled content
     */
    private function countFilesInContent(string $content): int
    {
        $fileMarkers = substr_count($content, '// File:');
        $classMarkers = substr_count($content, '// Class:');
        return max($fileMarkers, $classMarkers);
    }
}
