<?php
/**
 * Veloce Cycles - Protected Single-Admin Dashboard
 * 
 * SECURITY COMPLIANCE:
 * - Uses one-way bcrypt password hash verification (no plaintext password stored)
 * - Session regeneration on authentication
 * - Output escaping against XSS
 * - Google Sheets webhook testing performed server-side
 */

declare(strict_types=1);

// Configure secure session cookie parameters
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', '1');
}

session_start();

$configFile = __DIR__ . '/../private/config.php';
$config = file_exists($configFile) ? require $configFile : [];

// Extract centralized brand name from src/scripts/config.js
$brandShort = 'Veloce';
$brandFullName = 'Veloce Cycles';
$configJsPath = __DIR__ . '/../src/scripts/config.js';
if (file_exists($configJsPath)) {
    $jsContent = (string)file_get_contents($configJsPath);
    if (preg_match('/brandShort\s*:\s*["\']([^"\']+)["\']/', $jsContent, $m)) {
        $brandShort = trim($m[1]);
    }
    if (preg_match('/brandFullName\s*:\s*["\']([^"\']+)["\']/', $jsContent, $m)) {
        $brandFullName = trim($m[1]);
    }
}

$storedHash = $config['admin_password_hash'] ?? '';
$loginError = '';
$notification = '';

// Handle CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 1. Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $password = (string)($_POST['password'] ?? '');
    
    // Check brute-force attempts
    $failCount = (int)($_SESSION['login_fail_count'] ?? 0);
    $lastFailTime = (int)($_SESSION['login_last_fail'] ?? 0);
    
    if ($failCount >= 5 && (time() - $lastFailTime) < 300) {
        $remaining = 300 - (time() - $lastFailTime);
        $loginError = "Too many failed attempts. Please wait {$remaining} seconds before retrying.";
    } elseif (!empty($storedHash) && password_verify($password, $storedHash)) {
        session_regenerate_id(true);
        $_SESSION['is_veloce_admin'] = true;
        $_SESSION['admin_logged_in_at'] = time();
        unset($_SESSION['login_fail_count'], $_SESSION['login_last_fail']);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $_SESSION['login_fail_count'] = $failCount + 1;
        $_SESSION['login_last_fail'] = time();
        $loginError = 'Invalid password. Please verify the credentials.';
    }
}

// Check if currently authenticated
$isAuthenticated = !empty($_SESSION['is_veloce_admin']);

// 2. Handle CSV Export for Authenticated Admin
if ($isAuthenticated && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $dataFile = $config['data_storage_path'] ?? (__DIR__ . '/../private/survey_responses.json');
    $responses = file_exists($dataFile) ? (json_decode(file_get_contents($dataFile), true) ?: []) : [];
    
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $brandShort));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '_survey_responses_' . date('Y-m-d_His') . '.csv"');
    
    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM for Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($out, [
        'Submission ID', 'Timestamp', 'Rider Status', 'Primary Purpose', 
        'Riding Frequency', 'Weekly Distance', 'Current Bike Type', 
        'Holding Reasons', 'Pain Points', 'Budget Band', 'District', 
        'Name', 'Email', 'Phone', 'Early Voucher Lead', 'Test Ride Lead'
    ]);
    
    foreach ($responses as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['timestamp'] ?? '',
            $r['rider_status'] ?? '',
            $r['primary_purpose'] ?? '',
            $r['riding_frequency'] ?? '',
            $r['weekly_distance'] ?? '',
            $r['current_bike_type'] ?? '',
            is_array($r['holding_reasons'] ?? null) ? implode('; ', $r['holding_reasons']) : ($r['holding_reasons'] ?? ''),
            is_array($r['pain_points'] ?? null) ? implode('; ', $r['pain_points']) : ($r['pain_points'] ?? ''),
            $r['budget_band'] ?? '',
            $r['district'] ?? '',
            $r['name'] ?? '',
            $r['email'] ?? '',
            $r['phone'] ?? '',
            !empty($r['early_access_voucher']) ? 'Yes' : 'No',
            !empty($r['test_ride_interest']) ? 'Yes' : 'No',
        ]);
    }
    fclose($out);
    exit;
}

// 3. Handle Hash Generator Tool Form
$generatedHashResult = '';
if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_hash') {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $plain = (string)($_POST['new_plain_password'] ?? '');
        if (strlen($plain) >= 8) {
            $generatedHashResult = password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
            $notification = 'New encrypted access key generated successfully. Update your server configuration.';
        } else {
            $notification = 'Password must be at least 8 characters.';
        }
    }
}

// Load data for dashboard
$dataFile = $config['data_storage_path'] ?? (__DIR__ . '/../private/survey_responses.json');
$counterFile = $config['counter_storage_path'] ?? (__DIR__ . '/../private/response_counter.json');

$responses = file_exists($dataFile) ? (json_decode(@file_get_contents($dataFile), true) ?: []) : [];
$counterData = file_exists($counterFile) ? (json_decode(@file_get_contents($counterFile), true) ?: []) : [];
$totalCounter = (int)($counterData['count'] ?? count($responses));

// Compute Stats
$riderCounts = ['active_cyclist' => 0, 'occasional_rider' => 0, 'aspiring_cyclist' => 0];
$districtCounts = [];
$budgetCounts = ['under_80k' => 0, '80k_160k' => 0, '160k_300k' => 0, 'above_300k' => 0];
$painPointCounts = [];
$leadVoucherCount = 0;
$testRideCount = 0;

foreach ($responses as $item) {
    $status = $item['rider_status'] ?? 'unknown';
    if (isset($riderCounts[$status])) $riderCounts[$status]++;
    
    $dst = $item['district'] ?? 'Unknown';
    $districtCounts[$dst] = ($districtCounts[$dst] ?? 0) + 1;
    
    $bgt = $item['budget_band'] ?? 'unknown';
    if (isset($budgetCounts[$bgt])) $budgetCounts[$bgt]++;
    
    if (is_array($item['pain_points'] ?? null)) {
        foreach ($item['pain_points'] as $p) {
            $painPointCounts[$p] = ($painPointCounts[$p] ?? 0) + 1;
        }
    }
    
    if (!empty($item['early_access_voucher'])) $leadVoucherCount++;
    if (!empty($item['test_ride_interest'])) $testRideCount++;
}
arsort($districtCounts);
arsort($painPointCounts);

$webhookConfigured = !empty($config['google_sheets_webhook_url']);
$sheetUrl = $config['client_google_sheet_url'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($brandFullName) ?> — Admin Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #090D16;
            --bg-card: #111726;
            --bg-card-subtle: #182033;
            --border-color: #24304A;
            --text-main: #F1F5F9;
            --text-muted: #94A3B8;
            --accent-orange: #FF6B00;
            --accent-cyan: #00D2FF;
            --accent-green: #10B981;
            --accent-red: #EF4444;
            --font-display: 'Outfit', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg-body);
            color: var(--text-main);
            font-family: var(--font-body);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .header {
            background: rgba(17, 23, 38, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: white;
            font-family: var(--font-display);
            font-weight: 800;
            font-size: 1.25rem;
            letter-spacing: -0.02em;
        }
        .brand-badge {
            background: var(--accent-orange);
            color: black;
            font-size: 0.7rem;
            font-weight: 800;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            width: 100%;
            flex: 1;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            text-decoration: none;
            border: none;
            transition: all 0.2s ease;
        }
        .btn-primary {
            background: var(--accent-orange);
            color: #000;
        }
        .btn-primary:hover {
            background: #ff8526;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: var(--bg-card-subtle);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }
        .btn-secondary:hover {
            background: #24304A;
        }
        .btn-danger {
            background: rgba(239, 68, 68, 0.15);
            color: var(--accent-red);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.25);
        }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .grid-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.25rem;
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .stat-value {
            font-size: 2rem;
            font-weight: 800;
            font-family: var(--font-display);
            color: #fff;
        }
        .stat-sub {
            font-size: 0.75rem;
            color: var(--accent-green);
            margin-top: 0.25rem;
        }
        .grid-charts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        @media (max-width: 768px) {
            .grid-charts { grid-template-columns: 1fr; }
            .header { padding: 1rem; }
        }
        .chart-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
        }
        .bar-container {
            width: 100%;
            background: var(--bg-card-subtle);
            border-radius: 6px;
            height: 8px;
            overflow: hidden;
            margin-top: 0.25rem;
        }
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-orange), var(--accent-cyan));
            border-radius: 6px;
        }
        .table-responsive {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }
        th {
            background: var(--bg-card-subtle);
            padding: 0.875rem 1rem;
            color: var(--text-muted);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        td {
            padding: 0.875rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255, 255, 255, 0.02); }
        .tag {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .tag-cyclist { background: rgba(0, 210, 255, 0.15); color: var(--accent-cyan); }
        .tag-occasional { background: rgba(255, 107, 0, 0.15); color: var(--accent-orange); }
        .tag-aspiring { background: rgba(16, 185, 129, 0.15); color: var(--accent-green); }
        .login-box {
            max-width: 420px;
            margin: 5rem auto;
            padding: 2.5rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.5);
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            font-weight: 500;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: white;
            font-family: inherit;
            font-size: 0.95rem;
        }
        input:focus {
            outline: none;
            border-color: var(--accent-orange);
            box-shadow: 0 0 0 2px rgba(255, 107, 0, 0.2);
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            color: #FCA5A5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .alert-info {
            background: rgba(0, 210, 255, 0.15);
            color: #7DD3FC;
            border: 1px solid rgba(0, 210, 255, 0.3);
        }
        .code-box {
            background: #05080E;
            padding: 0.75rem;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.8rem;
            word-break: break-all;
            margin-top: 0.5rem;
            border: 1px solid #1E293B;
            color: #38BDF8;
        }
    </style>
</head>
<body>

    <header class="header">
        <a href="../index.html" class="brand">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="5.5" cy="17.5" r="3.5"/>
                <circle cx="18.5" cy="17.5" r="3.5"/>
                <path d="M15 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm-3 11.5L9 6H5M12 9l3.5 3.5M18.5 17.5l-3-6H12"/>
            </svg>
            <?= htmlspecialchars(strtoupper($brandShort)) ?>
            <span class="brand-badge">Admin</span>
        </a>
        <div>
            <?php if ($isAuthenticated): ?>
                <a href="../index.html" class="btn btn-secondary" style="margin-right: 0.5rem;">View Website</a>
                <a href="logout.php" class="btn btn-danger">Logout</a>
            <?php else: ?>
                <a href="../index.html" class="btn btn-secondary">Back to Website</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">

        <?php if (!$isAuthenticated): ?>
            <div class="login-box">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <h1 style="font-family: var(--font-display); font-size: 1.5rem; margin-bottom: 0.5rem;">Sign In</h1>
                    <p style="color: var(--text-muted); font-size: 0.875rem;">Authorized personnel access only</p>
                </div>

                <?php if (!empty($loginError)): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($loginError) ?></div>
                <?php endif; ?>

                <form method="POST" action="index.php">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autofocus placeholder="Enter password">
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 0.8rem;">
                        Sign In
                    </button>
                </form>
            </div>

        <?php else: ?>

            <?php if (!empty($notification)): ?>
                <div class="alert alert-info"><?= htmlspecialchars($notification) ?></div>
            <?php endif; ?>

            <div class="grid-stats">
                <div class="stat-card">
                    <div class="stat-label">Total Survey Responses</div>
                    <div class="stat-value"><?= number_format($totalCounter) ?></div>
                    <div class="stat-sub"><?= count($responses) ?> verified database records</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Cyclists</div>
                    <div class="stat-value" style="color: var(--accent-cyan);"><?= $riderCounts['active_cyclist'] ?></div>
                    <div class="stat-sub"><?= count($responses) ? round(($riderCounts['active_cyclist'] / count($responses)) * 100) : 0 ?>% of surveyed</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Aspiring Riders</div>
                    <div class="stat-value" style="color: var(--accent-green);"><?= $riderCounts['aspiring_cyclist'] ?></div>
                    <div class="stat-sub">Market expansion opportunity</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Early Adopter Leads</div>
                    <div class="stat-value" style="color: var(--accent-orange);"><?= $leadVoucherCount ?></div>
                    <div class="stat-sub"><?= $testRideCount ?> requested test rides</div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h2 style="font-family: var(--font-display); font-size: 1.25rem;">Market Validation Overview</h2>
                    <p style="color: var(--text-muted); font-size: 0.85rem;">Responses collected across Sri Lanka</p>
                </div>
                <div style="display: flex; gap: 0.75rem;">
                    <a href="?export=csv" class="btn btn-secondary">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Export CSV
                    </a>
                    <?php if (!empty($sheetUrl)): ?>
                        <a href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                            Open Client Google Sheet ↗
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid-charts">
                <div class="card">
                    <h3 style="font-size: 1rem; margin-bottom: 1rem; color: #fff;">Top Districts</h3>
                    <?php 
                    $topDistricts = array_slice($districtCounts, 0, 5, true);
                    $maxDst = !empty($topDistricts) ? max($topDistricts) : 1;
                    if (empty($topDistricts)): ?>
                        <p style="color: var(--text-muted); font-size: 0.85rem;">No responses recorded yet.</p>
                    <?php else: 
                        foreach ($topDistricts as $dst => $cnt): ?>
                        <div style="margin-bottom: 0.85rem;">
                            <div class="chart-row">
                                <span><?= htmlspecialchars($dst) ?></span>
                                <span style="font-weight: 700;"><?= $cnt ?> (<?= count($responses) ? round(($cnt/count($responses))*100) : 0 ?>%)</span>
                            </div>
                            <div class="bar-container">
                                <div class="bar-fill" style="width: <?= round(($cnt / $maxDst) * 100) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="card">
                    <h3 style="font-size: 1rem; margin-bottom: 1rem; color: #fff;">Budget Preferences</h3>
                    <?php
                    $budgetLabels = [
                        'under_80k'  => 'Under LKR 80,000 (Entry/Commuter)',
                        '80k_160k'   => 'LKR 80k – 160k (Sport Alloy)',
                        '160k_300k'  => 'LKR 160k – 300k (Performance)',
                        'above_300k' => 'Above LKR 300,000 (Elite/Carbon)'
                    ];
                    $maxBgt = !empty($budgetCounts) ? max(max($budgetCounts), 1) : 1;
                    foreach ($budgetLabels as $key => $lbl): 
                        $cnt = $budgetCounts[$key] ?? 0;
                    ?>
                        <div style="margin-bottom: 0.85rem;">
                            <div class="chart-row">
                                <span><?= $lbl ?></span>
                                <span style="font-weight: 700;"><?= $cnt ?></span>
                            </div>
                            <div class="bar-container">
                                <div class="bar-fill" style="width: <?= round(($cnt / $maxBgt) * 100) ?>%; background: linear-gradient(90deg, var(--accent-cyan), var(--accent-green));"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <h3 style="font-size: 1rem; color: #fff;">Recent Survey Responses (<?= count($responses) ?> Total)</h3>
                </div>
                
                <?php if (empty($responses)): ?>
                    <p style="color: var(--text-muted); font-size: 0.875rem; padding: 1.5rem 0; text-align: center;">No responses submitted yet. Test the survey on the landing page!</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Status</th>
                                    <th>District</th>
                                    <th>Budget</th>
                                    <th>Contact / Lead</th>
                                    <th>Top Pain Point</th>
                                    <th>Submitted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice(array_reverse($responses), 0, 15) as $res): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($res['id'] ?? '') ?></code></td>
                                        <td>
                                            <?php 
                                             $st = $res['rider_status'] ?? '';
                                             if ($st === 'active_cyclist') echo '<span class="tag tag-cyclist">Active Cyclist</span>';
                                             elseif ($st === 'occasional_rider') echo '<span class="tag tag-occasional">Occasional</span>';
                                             else echo '<span class="tag tag-aspiring">Aspiring</span>';
                                             ?>
                                        </td>
                                        <td><?= htmlspecialchars($res['district'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars(str_replace('_', ' ', $res['budget_band'] ?? '-')) ?></td>
                                        <td>
                                            <?php if (!empty($res['name']) || !empty($res['email'])): ?>
                                                <div><strong><?= htmlspecialchars($res['name'] ?: 'Anonymous') ?></strong></div>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($res['email'] ?: $res['phone'] ?: '') ?></div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">Anonymous</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="max-width: 220px; font-size: 0.8rem; color: var(--text-muted);">
                                            <?= htmlspecialchars(is_array($res['pain_points'] ?? null) && !empty($res['pain_points']) ? $res['pain_points'][0] : '-') ?>
                                        </td>
                                        <td style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?= htmlspecialchars(substr($res['timestamp'] ?? '', 0, 16)) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid-charts">
                <div class="card">
                    <h3 style="font-size: 1rem; margin-bottom: 0.75rem; color: #fff;">Cloud Spreadsheet Sync</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
                        Submissions are recorded in the primary database and synchronized with your external spreadsheet.
                    </p>
                    <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; margin-bottom: 1rem;">
                        <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?= $webhookConfigured ? 'var(--accent-green)' : 'var(--accent-orange)' ?>;"></span>
                        <span><?= $webhookConfigured ? 'Live Cloud Sync Active' : 'Operating in Standalone Database Mode' ?></span>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);">
                        To configure external cloud syncing or update connection settings, refer to the System Handover Guide.
                    </div>
                </div>

                <div class="card">
                    <h3 style="font-size: 1rem; margin-bottom: 0.75rem; color: #fff;">Access Key Security Tool</h3>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-bottom: 1rem;">
                        Generate an encrypted authentication key to update master administrator credentials securely:
                    </p>
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="generate_hash">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="form-group" style="margin-bottom: 0.75rem;">
                            <input type="password" name="new_plain_password" placeholder="Enter new master password" required minlength="8">
                        </div>
                        <button type="submit" class="btn btn-secondary" style="font-size: 0.8rem;">Generate Security Key</button>
                    </form>
                    <?php if (!empty($generatedHashResult)): ?>
                        <div class="code-box">
                            <strong>Encrypted Key:</strong><br><?= htmlspecialchars($generatedHashResult) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>

    </div>

</body>
</html>
