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
 * Console command to combine all PHP files from app/code into a single file
 * Usage: bin/magento genaker:autoloader:combine-app-code [--output=path/to/output.php]
 */
class CombineAppCodeFiles extends Command
{
    private const DEFAULT_OUTPUT = 'var/combined_app_code_classes.php';

    public function __construct(
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('genaker:autoloader:combine-app-code')
            ->setDescription('Combine all PHP files from app/code into a single file')
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
            $appCodeDir = $rootDir . '/app/code';
            $outputFile = $rootDir . '/' . $input->getOption('output');

            if (!is_dir($appCodeDir)) {
                $output->writeln("<error>app/code directory not found: {$appCodeDir}</error>");
                return Command::FAILURE;
            }

            $output->writeln("<info>Scanning app/code directory...</info>");
            
            // Find all PHP files in app/code
            $phpFiles = $this->findPhpFiles($appCodeDir);
            $fileCount = count($phpFiles);
            
            if ($fileCount === 0) {
                $output->writeln("<error>No PHP files found in app/code</error>");
                return Command::FAILURE;
            }

            $output->writeln("<info>Found {$fileCount} PHP files</info>");
            $output->writeln("<info>Combining files...</info>");

            $combinedContent = $this->combineFiles($phpFiles, $output);
            
            // Create output directory if it doesn't exist
            $outputDir = dirname($outputFile);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            file_put_contents($outputFile, $combinedContent);
            
            $fileSize = filesize($outputFile);
            $output->writeln("<info>✓ Successfully combined files into: {$outputFile}</info>");
            $output->writeln("<info>  File size: " . $this->formatBytes($fileSize) . "</info>");
            $output->writeln("<info>  Files included: {$fileCount}</info>");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            $output->writeln("<error>Stack trace: {$e->getTraceAsString()}</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Find all PHP files in app/code directory (optimized)
     */
    private function findPhpFiles(string $directory): array
    {
        $files = [];
        // Use optimized iterator flags
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::CURRENT_AS_PATHNAME
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY // Only files, skip directories
        );

        // Pre-compute skip patterns for better performance
        $testPattern = '/Test/';
        $registrationFile = 'registration.php';
        $baseDir = BP . '/app/code/';

        foreach ($iterator as $filePath) {
            // Fast extension check
            if (substr($filePath, -4) !== '.php') {
                continue;
            }
            
            // Optimize: Use strpos instead of basename() for registration check
            if (substr($filePath, -17) === $registrationFile) {
                continue;
            }
            
            // Skip test files (fast check)
            if (strpos($filePath, $testPattern) !== false) {
                continue;
            }
            
            $files[] = $filePath;
        }

        return $files;
    }

    /**
     * Combine all PHP files into a single file (optimized)
     */
    private function combineFiles(array $files, OutputInterface $output): string
    {
        // Pre-build header and pre-compute base path
        $basePath = BP . '/app/code/';
        $basePathLen = strlen($basePath);
        $header = "<?php\n/**\n * Combined App Code Classes\n * Generated: " . date('Y-m-d H:i:s') . "\n * Total files: " . count($files) . "\n *\n * This file contains all PHP classes from app/code\n * combined into a single file for easier autoloading.\n */\n\n";
        
        // Use string concatenation for better memory efficiency
        $combined = $header;
        
        $processed = 0;
        $skipped = 0;
        $errors = 0;
        $totalFiles = count($files);
        
        // Pre-compile regex patterns
        $phpTagPattern = '/^<\?php\s*/';
        $shortTagPattern = '/^<\?\s*/';
        $closingTagPattern = '/\?>\s*$/';
        
        // Process in batches
        $batchSize = 100;
        $batchCount = 0;

        foreach ($files as $filePath) {
            // Fast file existence check
            if (!file_exists($filePath)) {
                $skipped++;
                continue;
            }

            try {
                // Read file with size limit
                $content = @file_get_contents($filePath, false, null, 0, 10485760); // 10MB limit
                
                if ($content === false) {
                    $errors++;
                    continue;
                }

                // Optimize: Single pass tag removal
                if (strpos($content, '<?php') === 0) {
                    $content = preg_replace($phpTagPattern, '', $content);
                } elseif (strpos($content, '<?') === 0) {
                    $content = preg_replace($shortTagPattern, '', $content);
                }
                
                // Remove closing tag efficiently
                $contentLen = strlen($content);
                if ($contentLen > 2 && substr($content, -2) === '?>') {
                    $content = preg_replace($closingTagPattern, '', $content);
                }

                // Get relative path efficiently (avoid str_replace overhead)
                $relativePath = substr($filePath, $basePathLen);

                // Build content block
                $combined .= "// ============================================================================\n";
                $combined .= "// File: {$relativePath}\n";
                $combined .= "// ============================================================================\n\n";
                $combined .= $content;
                $combined .= "\n\n\n";

                $processed++;
                
                // Progress reporting
                if ($processed % $batchSize === 0) {
                    $batchCount++;
                    $output->writeln("  Processed {$processed}/{$totalFiles} files...");
                    // Periodic garbage collection for large operations
                    if ($batchCount % 10 === 0 && function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            } catch (\Exception $e) {
                $errors++;
                // Limit error output to avoid spam
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
