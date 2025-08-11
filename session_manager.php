<?php
/**
 * Centralized Session Management
 * 
 * This file provides standardized session management functions
 * to ensure all login sessions follow the same format across the application.
 */

// Include database configuration
require_once "db_config.php";

/**
 * Standardized session variables that should be set for all users
 */
define('SESSION_VARS', [
    'loggedin' => true,
    'id' => null,
    'full_name' => null,
    'email' => null,
    'role' => null,
    'login_time' => null,
    'last_activity' => null
]);

/**
 * Create a standardized session for any user type
 * 
 * @param int $user_id User ID from database
 * @param string $first_name User's first name
 * @param string $last_name User's last name
 * @param string $email User's email
 * @param string $role User's role (member, parent, coach, admin)
 * @param bool $is_admin_login Whether this is an admin login (affects redirect logic)
 * @return array Session data that was set
 */
function createStandardizedSession($user_id, $first_name, $last_name, $email, $role, $is_admin_login = false) {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Clear any existing session data
    session_unset();
    
    // Set standardized session variables
    $_SESSION["loggedin"] = true;
    $_SESSION["id"] = $user_id;
    $_SESSION["full_name"] = trim($first_name . " " . $last_name);
    $_SESSION["email"] = $email;
    $_SESSION["role"] = $role;
    $_SESSION["login_time"] = time();
    $_SESSION["last_activity"] = time();
    
    // For admin logins, also set the admin-specific flag for backward compatibility
    if ($is_admin_login) {
        $_SESSION["admin_loggedin"] = true;
    }
    
    return [
        'loggedin' => $_SESSION["loggedin"],
        'id' => $_SESSION["id"],
        'full_name' => $_SESSION["full_name"],
        'email' => $_SESSION["email"],
        'role' => $_SESSION["role"],
        'login_time' => $_SESSION["login_time"],
        'last_activity' => $_SESSION["last_activity"]
    ];
}

/**
 * Validate if a user is logged in with standardized session
 * 
 * @param array $allowed_roles Array of roles that are allowed (optional)
 * @return bool True if user is logged in and has valid session
 */
function isLoggedIn($allowed_roles = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Check if basic session variables exist
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        return false;
    }
    
    // Check if required session variables are set
    $required_vars = ['id', 'full_name', 'email', 'role'];
    foreach ($required_vars as $var) {
        if (!isset($_SESSION[$var]) || empty($_SESSION[$var])) {
            return false;
        }
    }
    
    // Check role if specified
    if ($allowed_roles !== null && !in_array($_SESSION["role"], $allowed_roles)) {
        return false;
    }
    
    // Update last activity
    $_SESSION["last_activity"] = time();
    
    return true;
}

/**
 * Get the appropriate redirect URL based on user role
 * 
 * @param string $role User's role
 * @param bool $is_admin_login Whether this was an admin login
 * @return string Redirect URL
 */
function getRedirectUrl($role, $is_admin_login = false) {
    if ($is_admin_login) {
        return "admin/admin_dashboard.php";
    }
    
    switch ($role) {
        case 'parent':
            return "parents_dashboard.php";
        case 'coach':
            return "coach/coach_dashboard.php";
        case 'admin':
        case 'member':
        default:
            return "dashboard.php";
    }
}

/**
 * Destroy session and clear all session data
 * 
 * @return bool True if session was destroyed successfully
 */
function destroySession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Unset all session variables
    $_SESSION = array();
    
    // Destroy the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    return session_destroy();
}

/**
 * Update session activity timestamp
 */
function updateSessionActivity() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
        $_SESSION["last_activity"] = time();
    }
}

/**
 * Check if session has expired (optional security feature)
 * 
 * @param int $timeout_seconds Session timeout in seconds (default: 8 hours)
 * @return bool True if session has expired
 */
function isSessionExpired($timeout_seconds = 28800) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION["last_activity"])) {
        return true;
    }
    
    return (time() - $_SESSION["last_activity"]) > $timeout_seconds;
}

/**
 * Get current user information from session
 * 
 * @return array|null User information or null if not logged in
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return [
        'id' => $_SESSION["id"],
        'full_name' => $_SESSION["full_name"],
        'email' => $_SESSION["email"],
        'role' => $_SESSION["role"],
        'login_time' => $_SESSION["login_time"],
        'last_activity' => $_SESSION["last_activity"]
    ];
}
?>
