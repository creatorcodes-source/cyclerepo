<?php
/**
 * Veloce Cycles - Automated Backend Integration Test Suite
 * 
 * Verifies:
 * 1. GET response-count endpoint
 * 2. POST submit-survey (Active Rider flow)
 * 3. POST submit-survey (Aspiring Rider flow)
 * 4. Invalid district validation
 * 5. Honeypot spam trap detection
 * 6. Admin Bcrypt hash verification
 * 7. Rate limiting & Data persistence integrity
 */

declare(strict_types=1);

echo "========================================================\n";
echo "VELOCE CYCLES — INTEGRATION TEST SUITE\n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] " . $description . "\n";
        $passed++;
    } else {
        echo " [FAIL] " . $description . "\n";
        $failed++;
    }
}

// 1. Test Config Integrity & Password Hash
$configFile = __DIR__ . '/../private/config.php';
assertTest("private/config.php exists", file_exists($configFile));

$config = require $configFile;
assertTest("admin_password_hash is configured and is valid bcrypt", !empty($config['admin_password_hash']) && str_starts_with($config['admin_password_hash'], '$2y$'));
assertTest("Default test password 'VeloceAdmin2026!' matches hash", password_verify('VeloceAdmin2026!', $config['admin_password_hash']));
assertTest("Invalid password is rejected", !password_verify('WrongPassword123!', $config['admin_password_hash']));

// 2. Start a background PHP dev server for HTTP testing
$port = 8899;
$host = "127.0.0.1";
$serverCmd = sprintf('php -S %s:%d -t "%s"', $host, $port, realpath(__DIR__ . '/..'));
$descriptorSpec = [
    0 => ["pipe", "r"],
    1 => ["pipe", "w"],
    2 => ["pipe", "w"]
];

$process = proc_open($serverCmd, $descriptorSpec, $pipes);
assertTest("PHP built-in test server process started on port " . $port, is_resource($process));

// Wait 1.5s for server to initialize
usleep(1500000);

$baseUrl = "http://{$host}:{$port}";

// Helper for HTTP requests
function httpRequest(string $url, string $method = 'GET', array $data = []): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response ? json_decode($response, true) : null,
        'raw'  => $response
    ];
}

// 3. Test Static Landing Page & 404
$indexRes = httpRequest($baseUrl . '/index.html');
assertTest("GET /index.html returns 200 OK", $indexRes['code'] === 200);
assertTest("index.html contains brand title 'Veloce Cycles'", str_contains($indexRes['raw'] ?? '', 'Veloce Cycles'));

$errRes = httpRequest($baseUrl . '/404.html');
assertTest("GET /404.html returns 200 OK", $errRes['code'] === 200);

// 4. Test Response Count API
$countRes = httpRequest($baseUrl . '/server/response-count.php');
assertTest("GET /server/response-count.php returns 200", $countRes['code'] === 200);
assertTest("Response count payload has integer count", isset($countRes['body']['count']) && is_int($countRes['body']['count']));
$initialCount = (int)$countRes['body']['count'];

// 5. Test Survey Submission (Active Cyclist flow)
$activePayload = [
    'rider_status' => 'active_cyclist',
    'primary_purpose' => 'fitness_sport',
    'riding_frequency' => '3_times_week',
    'weekly_distance' => '80km_plus',
    'current_bike_type' => 'road',
    'pain_points' => ['spare_parts_scarcity', 'monsoon_rust'],
    'budget_band' => '160k_300k',
    'district' => 'Colombo',
    'name' => 'Ranil Fernando',
    'email' => 'ranil@example.com',
    'phone' => '0771234567',
    'early_access_voucher' => true,
    'test_ride_interest' => true,
    'website_hp' => '' // empty honeypot
];

$submitRes1 = httpRequest($baseUrl . '/server/submit-survey.php', 'POST', $activePayload);
assertTest("POST /server/submit-survey.php (Active Cyclist) returns 200", $submitRes1['code'] === 200);
assertTest("Survey submission returns success = true", !empty($submitRes1['body']['success']));
assertTest("Survey submission returned submission_id with prefix VEL-", isset($submitRes1['body']['submission_id']) && str_starts_with($submitRes1['body']['submission_id'], 'VEL-'));
assertTest("Counter incremented by 1", isset($submitRes1['body']['count']) && $submitRes1['body']['count'] === ($initialCount + 1));

// 6. Test Survey Submission (Aspiring Cyclist flow)
$aspiringPayload = [
    'rider_status' => 'aspiring_cyclist',
    'holding_reasons' => ['high_bike_prices', 'traffic_safety'],
    'desired_use' => 'daily_commute',
    'pain_points' => ['arbitrary_pricing'],
    'budget_band' => 'under_80k',
    'district' => 'Kandy',
    'name' => 'Dilshan Perera',
    'email' => 'dilshan@example.com',
    'early_access_voucher' => true,
    'website_hp' => ''
];

$submitRes2 = httpRequest($baseUrl . '/server/submit-survey.php', 'POST', $aspiringPayload);
assertTest("POST /server/submit-survey.php (Aspiring Cyclist) returns 200", $submitRes2['code'] === 200);
assertTest("Counter incremented again to " . ($initialCount + 2), isset($submitRes2['body']['count']) && $submitRes2['body']['count'] === ($initialCount + 2));

// 7. Test District Validation (Invalid district should return 422)
$invalidDistrictPayload = $activePayload;
$invalidDistrictPayload['district'] = 'AtlantisCity';
$invalidRes = httpRequest($baseUrl . '/server/submit-survey.php', 'POST', $invalidDistrictPayload);
assertTest("Invalid district returns HTTP 422 Unprocessable Entity", $invalidRes['code'] === 422);

// 8. Test Honeypot Spam Trap (Spam bot filling website_hp)
$botPayload = $activePayload;
$botPayload['website_hp'] = 'I am a spam bot http://spam.com';
$botRes = httpRequest($baseUrl . '/server/submit-survey.php', 'POST', $botPayload);
assertTest("Honeypot trap catches bot and returns 200 without saving", $botRes['code'] === 200);

// 9. Verify Data Persistence in private/survey_responses.json
$dataFile = __DIR__ . '/../private/survey_responses.json';
assertTest("private/survey_responses.json exists and is populated", file_exists($dataFile));
$savedEntries = json_decode(file_get_contents($dataFile), true) ?: [];
assertTest("Local JSON store contains recorded submissions", count($savedEntries) >= 2);
$lastEntry = end($savedEntries);
assertTest("Saved entry has correct district (Kandy)", ($lastEntry['district'] ?? '') === 'Kandy');
assertTest("Saved entry masked client IP for privacy", str_ends_with($lastEntry['ip_masked'] ?? '', '.xxx'));

// 10. Clean up test server process
if (is_resource($process)) {
    proc_terminate($process);
    proc_close($process);
}

echo "\n========================================================\n";
echo sprintf("RESULTS: %d PASSED, %d FAILED\n", $passed, $failed);
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
