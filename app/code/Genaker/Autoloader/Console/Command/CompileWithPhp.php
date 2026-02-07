<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Console\Command;

use Genaker\Autoloader\Compiler\PhpCompiler;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to compile combined files using PHP
 */
class CompileWithPhp extends Command
{
    public function __construct(
        private readonly State $appState,
        private readonly PhpCompiler $phpCompiler
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('genaker:autoloader:compile-php')
            ->setDescription('Compile combined PHP files using PHP-based compiler')
            ->addOption(
                'input',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Input file path (relative to Magento root)'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path (relative to Magento root)'
            )
            ->addOption(
                'vendor',
                null,
                InputOption::VALUE_NONE,
                'Compile vendor combined file'
            )
            ->addOption(
                'app-code',
                null,
                InputOption::VALUE_NONE,
                'Compile app/code combined file'
            )
            ->addOption(
                'both',
                null,
                InputOption::VALUE_NONE,
                'Compile both vendor and app/code files'
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
            
            $compileVendor = $input->getOption('vendor') || $input->getOption('both');
            $compileAppCode = $input->getOption('app-code') || $input->getOption('both');
            
            // If no specific option, use input/output options
            if (!$compileVendor && !$compileAppCode) {
                $inputFile = $input->getOption('input');
                $outputFile = $input->getOption('output');
                
                if (!$inputFile) {
                    $output->writeln("<error>Input file is required. Use --input or --vendor/--app-code</error>");
                    return Command::FAILURE;
                }
                
                $inputPath = $rootDir . '/' . $inputFile;
                $outputPath = $outputFile ? $rootDir . '/' . $outputFile : $rootDir . '/var/' . basename($inputFile, '.php') . '_compiled_php.php';
                
                if (!file_exists($inputPath)) {
                    $output->writeln("<error>Input file not found: {$inputPath}</error>");
                    return Command::FAILURE;
                }
                
                return $this->compileFile($inputPath, $outputPath, $output);
            }

            $success = true;
            
            // Compile vendor file
            if ($compileVendor) {
                $vendorInput = $rootDir . '/var/combined_vendor_classes.php';
                $vendorOutput = $rootDir . '/var/combined_vendor_classes_compiled_php.php';
                
                if (!file_exists($vendorInput)) {
                    $output->writeln("<error>Vendor combined file not found. Run: bin/magento genaker:autoloader:combine-files</error>");
                    $success = false;
                } else {
                    $result = $this->compileFile($vendorInput, $vendorOutput, $output);
                    if ($result !== Command::SUCCESS) {
                        $success = false;
                    }
                }
            }
            
            // Compile app/code file
            if ($compileAppCode) {
                $appCodeInput = $rootDir . '/var/combined_app_code_classes.php';
                $appCodeOutput = $rootDir . '/var/combined_app_code_classes_compiled_php.php';
                
                if (!file_exists($appCodeInput)) {
                    $output->writeln("<error>App code combined file not found. Run: bin/magento genaker:autoloader:combine-app-code</error>");
                    $success = false;
                } else {
                    $result = $this->compileFile($appCodeInput, $appCodeOutput, $output);
                    if ($result !== Command::SUCCESS) {
                        $success = false;
                    }
                }
            }
            
            return $success ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Compile a single file
     */
    private function compileFile(string $inputFile, string $outputFile, OutputInterface $output): int
    {
        $output->writeln("<info>Compiling file using PHP compiler</info>");
        $output->writeln("<info>Input: {$inputFile}</info>");
        $output->writeln("<info>Output: {$outputFile}</info>");
        
        try {
            $results = $this->phpCompiler->compileToFile($inputFile, $outputFile);
            
            $output->writeln("<info>✓ Successfully compiled file</info>");
            $output->writeln("<info>  Files compiled: " . number_format($results['fileCount']) . "</info>");
            $output->writeln("<info>  Input size: " . $this->formatBytes($results['inputSize']) . "</info>");
            $output->writeln("<info>  Output size: " . $this->formatBytes($results['outputSize']) . "</info>");
            $output->writeln("<info>  Compression: " . number_format($results['compression'], 2) . "%</info>");
            $output->writeln("<info>  Duration: " . number_format($results['duration'], 4) . " seconds</info>");
            $output->writeln("<info>  Throughput: " . number_format($results['throughput'], 2) . " MB/s</info>");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Compilation failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
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
