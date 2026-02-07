<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Console\Command;

use Genaker\Autoloader\Compiler\GoCompiler;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command to compile combined files using a Go tool
 * Usage: bin/magento genaker:autoloader:compile-go [--tool=path/to/tool] [--input=file.php] [--output=file.php]
 */
class CompileWithGo extends Command
{
    private const DEFAULT_TOOL = 'go-compile-php';
    private const DEFAULT_INPUT = 'var/combined_vendor_classes.php';
    private const DEFAULT_OUTPUT = 'var/combined_vendor_classes_compiled.php';

    public function __construct(
        private readonly State $appState,
        private readonly GoCompiler $goCompiler
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('genaker:autoloader:compile-go')
            ->setDescription('Compile combined PHP files using Go compiler (direct binary, no Docker overhead)')
            ->addOption(
                'tool',
                't',
                InputOption::VALUE_OPTIONAL,
                'Path to Go compilation tool (default: go-compile-php)',
                self::DEFAULT_TOOL
            )
            ->addOption(
                'input',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Input file path (relative to Magento root)',
                self::DEFAULT_INPUT
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path (relative to Magento root)',
                self::DEFAULT_OUTPUT
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
            $tool = $input->getOption('tool');
            
            // Check if Go tool exists
            if (!$this->isToolAvailable($tool)) {
                $output->writeln("<error>Go tool not found: {$tool}</error>");
                $output->writeln("<comment>Build the Go compiler:</comment>");
                $output->writeln("<comment>docker run --rm -v \$(pwd)/app/code/Genaker/Autoloader/Tools/go-compile-php:/workspace -w /workspace golang:1.21-alpine go build -o go-compile-php main.go</comment>");
                return Command::FAILURE;
            }

            $compileVendor = $input->getOption('vendor') || $input->getOption('both');
            $compileAppCode = $input->getOption('app-code') || $input->getOption('both');
            
            // If no specific option, use input/output options
            if (!$compileVendor && !$compileAppCode) {
                $inputFile = $rootDir . '/' . $input->getOption('input');
                $outputFile = $rootDir . '/' . $input->getOption('output');
                
                if (!file_exists($inputFile)) {
                    $output->writeln("<error>Input file not found: {$inputFile}</error>");
                    return Command::FAILURE;
                }
                
                return $this->compileFile($tool, $inputFile, $outputFile, $output, $input);
            }

            $success = true;
            
            // Compile vendor file
            if ($compileVendor) {
                $vendorInput = $rootDir . '/var/combined_vendor_classes.php';
                $vendorOutput = $rootDir . '/var/combined_vendor_classes_compiled.php';
                
                if (!file_exists($vendorInput)) {
                    $output->writeln("<error>Vendor combined file not found. Run: bin/magento genaker:autoloader:combine-files</error>");
                    $success = false;
                } else {
                    $result = $this->compileFile($tool, $vendorInput, $vendorOutput, $output, $input);
                    if ($result !== Command::SUCCESS) {
                        $success = false;
                    }
                }
            }
            
            // Compile app/code file
            if ($compileAppCode) {
                $appCodeInput = $rootDir . '/var/combined_app_code_classes.php';
                $appCodeOutput = $rootDir . '/var/combined_app_code_classes_compiled.php';
                
                if (!file_exists($appCodeInput)) {
                    $output->writeln("<error>App code combined file not found. Run: bin/magento genaker:autoloader:combine-app-code</error>");
                    $success = false;
                } else {
                    $result = $this->compileFile($tool, $appCodeInput, $appCodeOutput, $output, $input);
                    if ($result !== Command::SUCCESS) {
                        $success = false;
                    }
                }
            }
            
            return $success ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $output->writeln("<error>Error: {$e->getMessage()}</error>");
            $output->writeln("<error>Stack trace: {$e->getTraceAsString()}</error>");
            return Command::FAILURE;
        }
    }

    /**
     * Check if Go tool is available (local binary or Docker)
     */
    private function isToolAvailable(string $tool): bool
    {
        // Check if Docker is available
        $dockerAvailable = $this->isDockerAvailable();
        
        // If using Docker, check if image exists or can be built
        if ($dockerAvailable && ($tool === 'go-compile-php' || $tool === 'genaker/go-compile-php')) {
            return true; // Docker can build/use the image
        }
        
        // Check if tool exists as absolute path
        if (file_exists($tool) && is_executable($tool)) {
            return true;
        }
        
        // Check if tool is in PATH
        $which = shell_exec("which {$tool} 2>/dev/null");
        if ($which && trim($which)) {
            return true;
        }
        
        // Check if it's a Go binary in common locations
        $commonPaths = [
            '/usr/local/bin/' . $tool,
            '/usr/bin/' . $tool,
            getenv('HOME') . '/go/bin/' . $tool,
            getenv('GOPATH') . '/bin/' . $tool,
        ];
        
        foreach ($commonPaths as $path) {
            if ($path && file_exists($path) && is_executable($path)) {
                return true;
            }
        }
        
        return false;
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
     * Compile a single file using Go tool
     */
    private function compileFile(string $tool, string $inputFile, string $outputFile, OutputInterface $output, ?InputInterface $input = null): int
    {
        $output->writeln("<info>Compiling file using Go compiler: {$tool}</info>");
        $output->writeln("<info>Input: {$inputFile}</info>");
        $output->writeln("<info>Output: {$outputFile}</info>");
        
        try {
            $results = $this->goCompiler->compileToFile($inputFile, $outputFile, $tool);
            
            $output->writeln("<info>✓ Successfully compiled file</info>");
            $output->writeln("<info>  Files compiled: " . number_format($results['fileCount']) . "</info>");
            $output->writeln("<info>  Input size: " . $this->formatBytes($results['inputSize']) . "</info>");
            $output->writeln("<info>  Output size: " . $this->formatBytes($results['outputSize']) . "</info>");
            $output->writeln("<info>  Compression: " . number_format($results['compression'], 2) . "%</info>");
            $output->writeln("<info>  Duration: " . number_format($results['duration'], 4) . " seconds</info>");
            $output->writeln("<info>  Throughput: " . number_format($results['throughput'], 2) . " MB/s</info>");
            if (isset($results['method'])) {
                $output->writeln("<info>  Method: " . ucfirst($results['method']) . "</info>");
            }
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln("<error>Compilation failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
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
