<?php
/**
 * Veloce Cycles - Survey Submission Endpoint
 * 
 * Handles multi-step conditional survey responses:
 * - Server-side validation & sanitization
 * - Spam honeypot detection
 * - Rate limiting
 * - Local fallback storage in private/
 * - Server-side forwarding to Google Apps Script webhook (if configured)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$configFile = __DIR__ . '/../private/config.php';
$config = file_exists($configFile) ? require $configFile : [];

// Retrieve JSON or Form-encoded payload
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawBody = file_get_contents('php://input');

if (str_contains($contentType, 'application/json') || (!empty($rawBody) && ($jsonParsed = json_decode($rawBody, true)) !== null)) {
    $input = json_decode($rawBody, true) ?? [];
} else {
    $input = $_POST;
}

// 1. Honeypot Spam Trap Check
if (!empty($input['website_hp'])) {
    // Silently return success to fool spam bots without saving junk data
    echo json_encode(['success' => true, 'message' => 'Thank you! Your response has been recorded.', 'count' => 125]);
    exit;
}

// 2. Basic Rate Limiting by IP
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = __DIR__ . '/../private/rate_limits.json';
$now = time();
$maxSubmissions = (int)($config['rate_limit_max_submissions'] ?? 15);
$windowSeconds  = (int)($config['rate_limit_window_seconds'] ?? 3600);

$rateData = [];
if (file_exists($rateLimitFile)) {
    $rawLimits = @file_get_contents($rateLimitFile);
    if ($rawLimits) {
        $rateData = json_decode($rawLimits, true) ?: [];
    }
}

// Clean old rate limit entries
foreach ($rateData as $ip => $timestamps) {
    $rateData[$ip] = array_filter((array)$timestamps, fn($t) => ($now - $t) < $windowSeconds);
    if (empty($rateData[$ip])) {
        unset($rateData[$ip]);
    }
}

if (isset($rateData[$clientIp]) && count($rateData[$clientIp]) >= $maxSubmissions) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many submissions. Please try again later.']);
    exit;
}

// 3. Official Sri Lankan Districts
$validDistricts = [
    'Colombo', 'Gampaha', 'Kalutara', 'Kandy', 'Matale', 'Nuwara Eliya',
    'Galle', 'Matara', 'Hambantota', 'Jaffna', 'Kilinochchi', 'Mannar',
    'Vavuniya', 'Mullaittivu', 'Batticaloa', 'Ampara', 'Trincomalee',
    'Kurunegala', 'Puttalam', 'Anuradhapura', 'Polonnaruwa', 'Badulla',
    'Monaragala', 'Ratnapura', 'Kegalle'
];

// 4. Validate Required Survey Data
$riderStatus = trim((string)($input['rider_status'] ?? ''));
$validRiderStatuses = ['active_cyclist', 'occasional_rider', 'aspiring_cyclist'];

if (!in_array($riderStatus, $validRiderStatuses, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please select a valid rider status.']);
    exit;
}

$district = trim((string)($input['district'] ?? ''));
if (!in_array($district, $validDistricts, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please select a valid Sri Lankan district.']);
    exit;
}

$budgetBand = trim((string)($input['budget_band'] ?? ''));
$validBudgets = ['under_80k', '80k_160k', '160k_300k', 'above_300k'];
if (!in_array($budgetBand, $validBudgets, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Please select a valid budget range.']);
    exit;
}

// Sanitize optional fields
$primaryPurpose  = htmlspecialchars(trim((string)($input['primary_purpose'] ?? '')), ENT_QUOTES, 'UTF-8');
$ridingFrequency = htmlspecialchars(trim((string)($input['riding_frequency'] ?? '')), ENT_QUOTES, 'UTF-8');
$weeklyDistance  = htmlspecialchars(trim((string)($input['weekly_distance'] ?? '')), ENT_QUOTES, 'UTF-8');
$currentBikeType = htmlspecialchars(trim((string)($input['current_bike_type'] ?? '')), ENT_QUOTES, 'UTF-8');

$holdingReasons  = is_array($input['holding_reasons'] ?? null) 
    ? array_map(fn($r) => htmlspecialchars(substr((string)$r, 0, 100), ENT_QUOTES, 'UTF-8'), $input['holding_reasons'])
    : [htmlspecialchars(trim((string)($input['holding_reasons'] ?? '')), ENT_QUOTES, 'UTF-8')];

$painPoints = is_array($input['pain_points'] ?? null) 
    ? array_map(fn($p) => htmlspecialchars(substr((string)$p, 0, 100), ENT_QUOTES, 'UTF-8'), $input['pain_points'])
    : [];

$name  = htmlspecialchars(substr(trim((string)($input['name'] ?? '')), 0, 100), ENT_QUOTES, 'UTF-8');
$email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '';
$phone = htmlspecialchars(substr(trim((string)($input['phone'] ?? '')), 0, 30), ENT_QUOTES, 'UTF-8');
$earlyVoucher = !empty($input['early_access_voucher']);
$testRide     = !empty($input['test_ride_interest']);

$timestamp = date('c');
$submissionId = 'VEL-' . strtoupper(bin2hex(random_bytes(4)));

$surveyRecord = [
    'id'                   => $submissionId,
    'timestamp'            => $timestamp,
    'rider_status'         => $riderStatus,
    'primary_purpose'      => $primaryPurpose,
    'riding_frequency'     => $ridingFrequency,
    'weekly_distance'      => $weeklyDistance,
    'current_bike_type'    => $currentBikeType,
    'holding_reasons'      => array_filter($holdingReasons),
    'pain_points'          => array_filter($painPoints),
    'budget_band'          => $budgetBand,
    'district'             => $district,
    'name'                 => $name,
    'email'                => $email,
    'phone'                => $phone,
    'early_access_voucher' => $earlyVoucher,
    'test_ride_interest'   => $testRide,
    'ip_masked'            => preg_replace('/\.\d+$/', '.xxx', $clientIp)
];

// 5. Update Local Response Counter
$counterFile = $config['counter_storage_path'] ?? (__DIR__ . '/../private/response_counter.json');
$currentCount = (int)($config['initial_counter_seed'] ?? 124);

if (file_exists($counterFile)) {
    $rawCount = @file_get_contents($counterFile);
    if ($rawCount) {
        $cData = json_decode($rawCount, true);
        if (isset($cData['count'])) {
            $currentCount = (int)$cData['count'];
        }
    }
}
$currentCount++;
@file_put_contents($counterFile, json_encode(['count' => $currentCount, 'updated_at' => $timestamp]), LOCK_EX);

// 6. Record to Local Responses JSON Store
$dataFile = $config['data_storage_path'] ?? (__DIR__ . '/../private/survey_responses.json');
$responses = [];
if (file_exists($dataFile)) {
    $rawResponses = @file_get_contents($dataFile);
    if ($rawResponses) {
        $responses = json_decode($rawResponses, true) ?: [];
    }
}
$responses[] = $surveyRecord;
@file_put_contents($dataFile, json_encode($responses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

// 7. Update Rate Limiter
$rateData[$clientIp][] = $now;
@file_put_contents($rateLimitFile, json_encode($rateData), LOCK_EX);

// 8. Forward to Google Apps Script Webhook (Server-Side cURL) if configured
$webhookUrl = trim((string)($config['google_sheets_webhook_url'] ?? ''));
$webhookSuccess = false;

if (!empty($webhookUrl) && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    $payloadJson = json_encode($surveyRecord);
    $ch = curl_init($webhookUrl);
    if ($ch) {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'User-Agent: VeloceCycles-Server/1.0'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // SSL compatibility across Windows local dev and Linux hosting
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $webhookSuccess = ($httpCode >= 200 && $httpCode < 400);
    }
}

// 9. Return Clean Public Response
echo json_encode([
    'success' => true,
    'message' => 'Thank you! Your response has been recorded.',
    'submission_id' => $submissionId,
    'count' => $currentCount
]);
