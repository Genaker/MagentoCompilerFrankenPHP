<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Compiler;

/**
 * PHP Compiler - Compiles PHP code using PHP built-in functions
 */
class PhpCompiler
{
    /**
     * Compile PHP content
     */
    public function compile(string $content): string
    {
        $result = $content;
        
        // Remove single-line comments
        $result = preg_replace('/\/\/.*$/m', '', $result);
        
        // Remove multi-line comments
        $result = preg_replace('/\/\*[\s\S]*?\*\//', '', $result);
        
        // Remove excessive empty lines
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
     * Compile file and return compiled content
     */
    public function compileFile(string $inputFile): string
    {
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("Input file not found: {$inputFile}");
        }
        
        $content = file_get_contents($inputFile);
        if ($content === false) {
            throw new \RuntimeException("Failed to read input file: {$inputFile}");
        }
        
        return $this->compile($content);
    }

    /**
     * Compile file and save to output
     */
    public function compileToFile(string $inputFile, string $outputFile): array
    {
        $startTime = microtime(true);
        $inputSize = filesize($inputFile);
        
        // Count files BEFORE compilation (since comments are removed)
        $originalContent = file_get_contents($inputFile);
        $fileCount = $this->countFilesInContent($originalContent);
        
        $compiled = $this->compileFile($inputFile);
        
        // Ensure output directory exists
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        
        $written = file_put_contents($outputFile, $compiled);
        if ($written === false) {
            throw new \RuntimeException("Failed to write output file: {$outputFile}");
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
        ];
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
