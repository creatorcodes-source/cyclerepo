<?php
/**
 * Veloce Cycles - Sample Configuration Template
 * 
 * Copy this file to `config.php` and adjust configuration.
 */

return [
    // Generate your own hash using: php -r "echo password_hash('YourSecretPassword', PASSWORD_BCRYPT);"
    'admin_password_hash' => '$2y$12$Q537GCDIxDX79sFuekV76u4n4mV6PmN7L0cdGDm5X/fGo4w8.cFJG',

    // Deployed Google Apps Script Web App URL
    'google_sheets_webhook_url' => '',

    // Optional direct client Google Sheet view URL
    'client_google_sheet_url' => '',

    // Storage paths
    'data_storage_path'    => __DIR__ . '/survey_responses.json',
    'counter_storage_path' => __DIR__ . '/response_counter.json',

    'rate_limit_max_submissions' => 15,
    'rate_limit_window_seconds'  => 3600,
    'initial_counter_seed'       => 124,
    'environment'                => 'production',
];
