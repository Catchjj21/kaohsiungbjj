<?php
/*
 * Database Configuration File
 *
 * Replace the placeholder values with your actual database credentials.
 * You can find these details in your 20i control panel under "MySQL Databases".
 */

// =========================================================================
// THE FIX: Ensure all sessions are valid for the entire website ('/')
// This prevents separate sessions from being created for the /admin/ directory.
// =========================================================================
session_set_cookie_params(0, '/');
// =========================================================================

// ** MySQL settings - You can get this info from your web host ** //
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'kaohsiungbjj-353030309e34'); // Replace with your DB username
define('DB_PASSWORD', 'wy2iyi6chy'); // Replace with your DB password
define('DB_NAME', 'kaohsiungbjj-353030309e34');

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
