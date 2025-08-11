<?php
/*
 * Database Configuration File
 *
 * MAMP Localhost Configuration
 * Default MAMP settings:
 * - Host: localhost
 * - Username: root
 * - Password: root
 * - Port: 8889 (if using default MAMP port)
 */

// =========================================================================
// THE FIX: Ensure all sessions are valid for the entire website ('/')
// This prevents separate sessions from being created for the /admin/ directory.
// Only set cookie parameters if session hasn't started yet.
// =========================================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(0, '/');
}
// =========================================================================

// ** MySQL settings for MAMP localhost ** //
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // MAMP default username
define('DB_PASSWORD', 'root'); // MAMP default password
define('DB_NAME', 'kaohsiungbjj'); // Your database name

/* Attempt to connect to MySQL database */
$link = mysqli_connect(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if($link === false){
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// ** ADD THIS LINE **
// Set the character set to utf8mb4 to support Chinese characters properly.
mysqli_set_charset($link, "utf8mb4");

?>
