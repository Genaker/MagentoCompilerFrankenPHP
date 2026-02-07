<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Console\Command;

use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to combine all PHP files from autoload_classmap.php into a single file
 * Usage: bin/magento genaker:autoloader:combine-files [--output=path/to/output.php]
 */
class CombineClassMapFiles extends Command
{
    private const DEFAULT_OUTPUT = 'var/combined_vendor_classes.php';

    public function __construct(
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('genaker:autoloader:combine-files')
            ->setDescription('Combine all PHP files from composer autoload_classmap.php into a single file')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path (relative to Magento root)',
                self::DEFAULT_OUTPUT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
        } catch (\Exception $e) {
            // Area code already set
        }

        try {
            $rootDir = BP;
            $classMapFile = $rootDir . '/vendor/composer/autoload_classmap.php';
            $outputFile = $rootDir . '/' . $input->getOption('output');

            if (!file_exists($classMapFile)) {
                $output->writeln("<error>Classmap file not found: {$classMapFile}</error>");
                return Command::FAILURE;
            }

            $output->writeln("<info>Loading classmap...</info>");
            
            $vendorDir = dirname(dirname($classMapFile));
            $baseDir = dirname($vendorDir);
            
            $classMap = include $classMapFile;
            
            if (!is_array($classMap) || count($classMap) === 0) {
                $output->writeln("<error>Classmap is empty or invalid</error>");
                return Command::FAILURE;
            }

            $output->writeln("<info>Found " . count($classMap) . " classes in classmap</info>");
            $output->writeln("<info>Combining files...</info>");

            $combinedContent = $this->combineFiles($classMap, $output);
            
            // Create output directory if it doesn't exist
            $outputDir = dirname($outputFile);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            file_put_contents($outputFile, $combinedContent);
            
            $fileSize = filesize($outputFile);
            $output->writeln("<info>✓ Successfully combined files into: {$outputFile}</info>");
            $output->writeln("<info>  File size: " . $this->formatBytes($fileSize) . "</info>");
            $output->writeln("<info>  Classes included: " . count($classMap) . "</info>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            $output->writeln("<error>Stack trace: {$e->getTraceAsString()}</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Combine all PHP files into a single file (optimized)
     */
    private function combineFiles(array $classMap, OutputInterface $output): string
    {
        // Pre-build header (avoid repeated string operations)
        $header = "<?php\n/**\n * Combined Vendor Classes\n * Generated: " . date('Y-m-d H:i:s') . "\n * Total classes: " . count($classMap) . "\n *\n * This file contains all PHP classes from vendor/composer/autoload_classmap.php\n * combined into a single file for easier autoloading.\n */\n\n";
        
        // Use string concatenation instead of array for better memory efficiency
        $combined = $header;
        
        $processed = 0;
        $skipped = 0;
        $errors = 0;
        $totalFiles = count($classMap);
        
        // Pre-compile regex patterns (faster than compiling on each iteration)
        $phpTagPattern = '/^<\?php\s*/';
        $shortTagPattern = '/^<\?\s*/';
        $closingTagPattern = '/\?>\s*$/';
        
        // Process files in batches to reduce memory pressure
        $batchSize = 100;
        $batchCount = 0;

        foreach ($classMap as $className => $filePath) {
            // Fast file existence check
            if (!file_exists($filePath)) {
                $skipped++;
                continue;
            }

            try {
                // Use file_get_contents with error suppression for better performance
                $content = @file_get_contents($filePath, false, null, 0, 10485760); // Limit to 10MB per file
                
                if ($content === false) {
                    $errors++;
                    continue;
                }

                // Optimize: Single pass regex replacements (faster than multiple)
                // Remove opening PHP tags
                if (strpos($content, '<?php') === 0) {
                    $content = preg_replace($phpTagPattern, '', $content);
                } elseif (strpos($content, '<?') === 0) {
                    $content = preg_replace($shortTagPattern, '', $content);
                }
                
                // Remove closing PHP tag if present (check end of string first)
                $contentLen = strlen($content);
                if ($contentLen > 2 && substr($content, -2) === '?>') {
                    $content = preg_replace($closingTagPattern, '', $content);
                }

                // Build content block efficiently
                $combined .= "// ============================================================================\n";
                $combined .= "// Class: {$className}\n";
                $combined .= "// File: {$filePath}\n";
                $combined .= "// ============================================================================\n\n";
                $combined .= $content;
                $combined .= "\n\n\n";

                $processed++;
                
                // Progress reporting every batch
                if ($processed % $batchSize === 0) {
                    $batchCount++;
                    $output->writeln("  Processed {$processed}/{$totalFiles} files...");
                    // Optional: Clear memory periodically for very large filesets
                    if ($batchCount % 10 === 0 && function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                // Only show warnings for first few errors to avoid spam
                if ($errors <= 5) {
                    $output->writeln("<comment>Warning: Failed to process {$filePath}: {$e->getMessage()}</comment>");
                }
            }
        }

        // Append footer
        $combined .= "// End of combined file\n";
        $combined .= "// Processed: {$processed} files\n";
        $combined .= "// Skipped: {$skipped} files\n";
        $combined .= "// Errors: {$errors} files\n";

        $output->writeln("<info>  Processed: {$processed} files</info>");
        if ($skipped > 0) {
            $output->writeln("<comment>  Skipped: {$skipped} files (not found)</comment>");
        }
        if ($errors > 0) {
            $output->writeln("<error>  Errors: {$errors} files</error>");
        }

        return $combined;
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
