<?php
/**
 * Veloce Cycles - Protected Server Configuration
 * 
 * IMPORTANT SECURITY NOTICE:
 * - This file resides outside public web access or is protected via .htaccess.
 * - NEVER commit production secrets or passwords to public repositories.
 * - Admin password is ONLY stored as a one-way bcrypt hash.
 * - Google Sheets webhook URL is kept strictly server-side and never exposed to the client browser.
 */

return [
    // One-way Bcrypt password hash for admin authentication.
    'admin_password_hash' => '$2y$12$pmVB7Wv8phS/rnC/arTCU.knO1vTLBA1KGcUMiL.eQ34y0ql67wra',

    // Client's Google Apps Script Webhook URL for Google Sheets syncing.
    // Executed entirely server-side via cURL.
    'google_sheets_webhook_url' => 'https://script.google.com/macros/s/AKfycbzZR6N1uZTOOmZhyOVMpLVsKFcYS-U9D3hY1f1H7WorTzdqm6LqSnOqLoaF8j3V8lWX/exec',

    // Optional direct client Google Sheet view URL (accessible only by logged-in admin)
    'client_google_sheet_url' => getenv('VELOCE_CLIENT_SHEET_URL') ?: '',

    // Local data storage file paths (stored securely in private/)
    'data_storage_path'    => __DIR__ . '/survey_responses.json',
    'counter_storage_path' => __DIR__ . '/response_counter.json',

    // Rate limiting: Max survey submissions allowed per IP per hour
    'rate_limit_max_submissions' => 15,
    'rate_limit_window_seconds'  => 3600,

    // Initial base count offset (for marketing validation / social proof starter)
    'initial_counter_seed' => 124,

    // Application environment: 'production' or 'development'
    'environment' => 'production',
];
