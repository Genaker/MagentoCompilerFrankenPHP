<?php
/**
 * HTTP Performance Test Script
 * Tests Magento HTTP entry point with opcache influence
 * 
 * Usage: php app/code/Genaker/Autoloader/Test/Performance/http-performance-test.php [url]
 */

require __DIR__ . '/../../../../../../app/bootstrap.php';

use Magento\Framework\App\Bootstrap;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

try {
    $appState = $objectManager->get(\Magento\Framework\App\State::class);
    $appState->setAreaCode(\Magento\Framework\App\Area::AREA_GLOBAL);
} catch (\Exception $e) {
    // Area code already set
}

// Get base URL
try {
    /** @var \Magento\Store\Model\StoreManagerInterface $storeManager */
    $storeManager = $objectManager->get(\Magento\Store\Model\StoreManagerInterface::class);
    $baseUrl = rtrim($storeManager->getStore()->getBaseUrl(), '/');
} catch (\Exception $e) {
    $baseUrl = $argv[1] ?? 'http://localhost';
}

echo "=== HTTP Performance Test ===\n";
echo "Base URL: {$baseUrl}\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n\n";

// Check opcache status
$opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status() !== false;
echo "OPcache Status:\n";
echo "  Enabled: " . ($opcacheEnabled ? 'Yes' : 'No') . "\n";

if ($opcacheEnabled) {
    $status = opcache_get_status();
    if ($status !== false) {
        echo "  Cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
        echo "  Cache hits: " . ($status['opcache_statistics']['hits'] ?? 'N/A') . "\n";
        echo "  Cache misses: " . ($status['opcache_statistics']['misses'] ?? 'N/A') . "\n";
        
        $hits = $status['opcache_statistics']['hits'] ?? 0;
        $misses = $status['opcache_statistics']['misses'] ?? 0;
        $total = $hits + $misses;
        if ($total > 0) {
            $hitRate = ($hits / $total) * 100;
            echo "  Hit rate: " . number_format($hitRate, 2) . "%\n";
        }
    }
}

// Check combined files
echo "\nCombined Files:\n";
$vendorFile = BP . '/var/combined_vendor_classes.php';
$appCodeFile = BP . '/var/combined_app_code_classes.php';

if (file_exists($vendorFile)) {
    $size = filesize($vendorFile);
    $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($vendorFile);
    echo "  Vendor: " . formatBytes($size) . " (" . ($cached ? 'cached' : 'not cached') . ")\n";
} else {
    echo "  Vendor: Not found\n";
}

if (file_exists($appCodeFile)) {
    $size = filesize($appCodeFile);
    $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($appCodeFile);
    echo "  App Code: " . formatBytes($size) . " (" . ($cached ? 'cached' : 'not cached') . ")\n";
} else {
    echo "  App Code: Not found\n";
}

// Test HTTP requests
echo "\n=== HTTP Request Performance ===\n";

$iterations = 20;
$warmupIterations = 5;

echo "Warmup requests: {$warmupIterations}\n";
for ($i = 0; $i < $warmupIterations; $i++) {
    makeRequest($baseUrl);
    usleep(100000); // 100ms
}

echo "Test requests: {$iterations}\n";

$times = [];
$successful = 0;
$memoryBefore = memory_get_usage(true);

for ($i = 0; $i < $iterations; $i++) {
    $start = microtime(true);
    $result = makeRequest($baseUrl);
    $end = microtime(true);
    
    if ($result['success']) {
        $successful++;
    }
    
    $times[] = ($end - $start) * 1000; // Convert to milliseconds
    
    if (($i + 1) % 5 === 0) {
        echo "  Completed " . ($i + 1) . " requests...\n";
    }
    
    usleep(50000); // 50ms delay between requests
}

$memoryAfter = memory_get_usage(true);

// Calculate statistics
$avgTime = array_sum($times) / count($times);
$minTime = min($times);
$maxTime = max($times);
$medianTime = calculateMedian($times);
$p95Time = calculatePercentile($times, 95);
$p99Time = calculatePercentile($times, 99);

echo "\nResults:\n";
echo "  Successful: {$successful}/{$iterations} (" . number_format(($successful / $iterations) * 100, 1) . "%)\n";
echo "  Average: " . number_format($avgTime, 2) . " ms\n";
echo "  Median: " . number_format($medianTime, 2) . " ms\n";
echo "  Min: " . number_format($minTime, 2) . " ms\n";
echo "  Max: " . number_format($maxTime, 2) . " ms\n";
echo "  P95: " . number_format($p95Time, 2) . " ms\n";
echo "  P99: " . number_format($p99Time, 2) . " ms\n";
echo "  Total: " . number_format(array_sum($times), 2) . " ms\n";
echo "  Memory used: " . formatBytes($memoryAfter - $memoryBefore) . "\n";

// Check opcache after requests
if ($opcacheEnabled) {
    $statusAfter = opcache_get_status();
    if ($statusAfter !== false) {
        echo "\nOPcache After Requests:\n";
        echo "  Cached scripts: " . ($statusAfter['opcache_statistics']['num_cached_scripts'] ?? 'N/A') . "\n";
        echo "  Cache hits: " . ($statusAfter['opcache_statistics']['hits'] ?? 'N/A') . "\n";
        echo "  Cache misses: " . ($statusAfter['opcache_statistics']['misses'] ?? 'N/A') . "\n";
        
        $hitsAfter = $statusAfter['opcache_statistics']['hits'] ?? 0;
        $missesAfter = $statusAfter['opcache_statistics']['misses'] ?? 0;
        $totalAfter = $hitsAfter + $missesAfter;
        if ($totalAfter > 0) {
            $hitRateAfter = ($hitsAfter / $totalAfter) * 100;
            echo "  Hit rate: " . number_format($hitRateAfter, 2) . "%\n";
        }
    }
}

echo "\n=== Test Complete ===\n";

/**
 * Make HTTP request
 */
function makeRequest(string $url): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_NOBODY, false); // GET request to load full page
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Disable SSL host verification
    
    $start = microtime(true);
    $result = curl_exec($ch);
    $end = microtime(true);
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $sizeDownload = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    
    curl_close($ch);
    
    return [
        'success' => $httpCode >= 200 && $httpCode < 400,
        'http_code' => $httpCode,
        'time' => ($end - $start) * 1000,
        'total_time' => $totalTime * 1000,
        'size' => $sizeDownload,
    ];
}

/**
 * Calculate median
 */
function calculateMedian(array $values): float
{
    sort($values);
    $count = count($values);
    $middle = floor(($count - 1) / 2);
    
    if ($count % 2) {
        return $values[$middle];
    } else {
        return ($values[$middle] + $values[$middle + 1]) / 2;
    }
}

/**
 * Calculate percentile
 */
function calculatePercentile(array $values, float $percentile): float
{
    sort($values);
    $index = ceil(count($values) * ($percentile / 100)) - 1;
    return $values[max(0, min($index, count($values) - 1))];
}

/**
 * Format bytes
 */
function formatBytes(int $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}
