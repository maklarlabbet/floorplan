<?php
/**
 * Central configuration.
 * Fill in your own values below before uploading to cPanel.
 */

// ---- Database (cPanel: create these in MySQL Databases panel) ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_cpanel_db_name');
define('DB_USER', 'your_cpanel_db_user');
define('DB_PASS', 'your_cpanel_db_password');

// ---- Anthropic API ----
// Get a key at https://console.anthropic.com/
define('ANTHROPIC_API_KEY', 'sk-ant-xxxxxxxxxxxxxxxxxxxxxxxxxxxx');
define('ANTHROPIC_MODEL', 'claude-sonnet-5');
define('ANTHROPIC_API_URL', 'https://api.anthropic.com/v1/messages');

// ---- App paths ----
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('UPLOAD_URL_BASE', 'uploads'); // relative URL from site root

// ---- Misc ----
define('MAX_UPLOAD_BYTES', 8 * 1024 * 1024); // 8MB
date_default_timezone_set('UTC');

session_start();
