<?php
/**
 * @category Genaker
 * @copyright Copyright (c) Genaker
 */

namespace Genaker\Autoloader\Test\Performance;

use Magento\Framework\App\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * GoGentoServerGo Performance Test
 * Compares GoGentoServerGo vs Regular PHP with OPcache
 */
class GoGentoServerPerformanceTest extends TestCase
{
    private const GOGENTO_PORT = 8080;
    private const PHP_PORT = 8081;
    private const NUM_REQUESTS = 100;
    private const CONCURRENT = 10;

    /**
     * Test GoGentoServerGo performance
     */
    public function testGoGentoServerPerformance(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "GOGENTOSERVERGO PERFORMANCE TEST\n";
        echo str_repeat("=", 80) . "\n";

        $url = "http://localhost:" . self::GOGENTO_PORT . "/";
        
        if (!$this->isServerRunning(self::GOGENTO_PORT)) {
            $this->markTestSkipped("GoGentoServerGo not running on port " . self::GOGENTO_PORT);
            return;
        }

        $results = $this->runPerformanceTest($url, "GoGentoServerGo");
        
        echo "\nGoGentoServerGo Results:\n";
        echo "  Requests per second: " . number_format($results['rps'], 2) . "\n";
        echo "  Time per request: " . number_format($results['time_per_request'], 2) . " ms\n";
        echo "  Total time: " . number_format($results['total_time'], 2) . " seconds\n";
        echo "  Failed requests: " . $results['failed'] . "\n";
        
        $this->assertLessThan(100, $results['failed'], 'Should have minimal failed requests');
        $this->assertGreaterThan(0, $results['rps'], 'Should handle requests');
    }

    /**
     * Test PHP Built-in Server performance
     */
    public function testPhpServerPerformance(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "PHP BUILT-IN SERVER PERFORMANCE TEST\n";
        echo str_repeat("=", 80) . "\n";

        $url = "http://localhost:" . self::PHP_PORT . "/";
        
        if (!$this->isServerRunning(self::PHP_PORT)) {
            $this->markTestSkipped("PHP server not running on port " . self::PHP_PORT);
            return;
        }

        $results = $this->runPerformanceTest($url, "PHP Server");
        
        echo "\nPHP Server Results:\n";
        echo "  Requests per second: " . number_format($results['rps'], 2) . "\n";
        echo "  Time per request: " . number_format($results['time_per_request'], 2) . " ms\n";
        echo "  Total time: " . number_format($results['total_time'], 2) . " seconds\n";
        echo "  Failed requests: " . $results['failed'] . "\n";
        
        $this->assertLessThan(100, $results['failed'], 'Should have minimal failed requests');
        $this->assertGreaterThan(0, $results['rps'], 'Should handle requests');
    }

    /**
     * Compare both servers
     */
    public function testPerformanceComparison(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "PERFORMANCE COMPARISON\n";
        echo str_repeat("=", 80) . "\n";

        $gogentoUrl = "http://localhost:" . self::GOGENTO_PORT . "/";
        $phpUrl = "http://localhost:" . self::PHP_PORT . "/";
        
        $gogentoRunning = $this->isServerRunning(self::GOGENTO_PORT);
        $phpRunning = $this->isServerRunning(self::PHP_PORT);
        
        if (!$gogentoRunning && !$phpRunning) {
            $this->markTestSkipped("Neither server is running");
            return;
        }

        $gogentoResults = null;
        $phpResults = null;

        if ($gogentoRunning) {
            $gogentoResults = $this->runPerformanceTest($gogentoUrl, "GoGentoServerGo");
        }

        if ($phpRunning) {
            $phpResults = $this->runPerformanceTest($phpUrl, "PHP Server");
        }

        echo "\n" . str_repeat("-", 80) . "\n";
        echo "COMPARISON SUMMARY\n";
        echo str_repeat("-", 80) . "\n";

        if ($gogentoResults && $phpResults) {
            $speedup = $gogentoResults['rps'] / $phpResults['rps'];
            
            echo "\nRequests per second:\n";
            echo "  GoGentoServerGo: " . number_format($gogentoResults['rps'], 2) . " req/s\n";
            echo "  PHP Server: " . number_format($phpResults['rps'], 2) . " req/s\n";
            
            if ($gogentoResults['rps'] > $phpResults['rps']) {
                echo "  → GoGentoServerGo is " . number_format($speedup, 2) . "x FASTER\n";
            } else {
                echo "  → PHP Server is " . number_format(1/$speedup, 2) . "x FASTER\n";
            }
            
            echo "\nTime per request:\n";
            echo "  GoGentoServerGo: " . number_format($gogentoResults['time_per_request'], 2) . " ms\n";
            echo "  PHP Server: " . number_format($phpResults['time_per_request'], 2) . " ms\n";
            
            $timeImprovement = (($phpResults['time_per_request'] - $gogentoResults['time_per_request']) / $phpResults['time_per_request']) * 100;
            if ($timeImprovement > 0) {
                echo "  → GoGentoServerGo is " . number_format($timeImprovement, 2) . "% faster\n";
            } else {
                echo "  → PHP Server is " . number_format(abs($timeImprovement), 2) . "% faster\n";
            }
        } elseif ($gogentoResults) {
            echo "\nOnly GoGentoServerGo results available\n";
        } elseif ($phpResults) {
            echo "\nOnly PHP Server results available\n";
        }
    }

    /**
     * Run performance test using cURL
     */
    private function runPerformanceTest(string $url, string $name): array
    {
        $startTime = microtime(true);
        $successful = 0;
        $failed = 0;
        $totalTime = 0;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        // Run requests
        for ($i = 0; $i < self::NUM_REQUESTS; $i++) {
            $reqStart = microtime(true);
            $result = curl_exec($ch);
            $reqTime = (microtime(true) - $reqStart) * 1000; // Convert to ms
            
            if ($result !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
                $successful++;
                $totalTime += $reqTime;
            } else {
                $failed++;
            }
        }
        
        curl_close($ch);
        
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        $rps = $successful / $duration;
        $avgTime = $successful > 0 ? $totalTime / $successful : 0;
        
        return [
            'rps' => $rps,
            'time_per_request' => $avgTime,
            'total_time' => $duration,
            'successful' => $successful,
            'failed' => $failed,
        ];
    }

    /**
     * Check if server is running on port
     */
    private function isServerRunning(int $port): bool
    {
        $ch = curl_init("http://localhost:$port/");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $result !== false && $httpCode > 0;
    }
}
