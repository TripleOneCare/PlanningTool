<?php
/**
 * TOC Planning Tool configuration.
 * Copy this file to config.php and fill in your database details.
 * config.php is blocked from web access by .htaccess (Apache) and the sample nginx config.
 */
return [
    // MySQL connection. On Hostinger, copy these from hPanel > Databases > MySQL Databases.
    // Hostinger adds your account prefix to both names, e.g. u123456789_tocops. Host stays "localhost".
    'db_dsn'  => 'mysql:host=localhost;dbname=u123456789_tocops;charset=utf8mb4',
    'db_user' => 'u123456789_tocops',
    'db_pass' => 'change-me',

    // Full public address of the board, no trailing slash. Used to build invite links.
    'app_url' => 'https://planning.tripleonecare.online',

    // Local time zone used for "today", overdue and due-soon calculations.
    'timezone' => 'Pacific/Auckland',

    // Set to true only while the site is served over HTTPS (keeps the login cookie HTTPS-only).
    'secure_cookies' => true,

    // How often (seconds) open boards check for changes made by teammates.
    'poll_seconds' => 15,

    // How long an invite / password reset link stays valid (hours).
    'invite_hours' => 72,
];
