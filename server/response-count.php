<?php
/**
 * Veloce Cycles - Live Public Response Count Endpoint
 * 
 * Returns only the public aggregate counter.
 * NEVER leaks credentials, personal data, or spreadsheet references.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=30'); // Cache for 30s to preserve performance

$configFile = __DIR__ . '/../private/config.php';
$config = file_exists($configFile) ? require $configFile : [];

$counterFile = $config['counter_storage_path'] ?? (__DIR__ . '/../private/response_counter.json');
$seedCount = (int)($config['initial_counter_seed'] ?? 124);

$currentCount = $seedCount;

if (file_exists($counterFile)) {
    $raw = @file_get_contents($counterFile);
    if ($raw !== false) {
        $data = json_decode($raw, true);
        if (isset($data['count']) && is_numeric($data['count'])) {
            $currentCount = (int)$data['count'];
        }
    }
} else {
    // Initialize counter if not yet created
    @file_put_contents($counterFile, json_encode(['count' => $seedCount, 'updated_at' => date('c')]), LOCK_EX);
}

echo json_encode([
    'count' => $currentCount,
    'status' => 'success'
]);
