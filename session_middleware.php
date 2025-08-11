<?php
/**
 * Session Middleware
 * 
 * This file provides standardized session validation and authentication
 * that can be included in any page to ensure consistent session checking.
 */

// Include the session manager
require_once "session_manager.php";

/**
 * Require user to be logged in
 * Redirects to login page if not logged in
 * 
 * @param array $allowed_roles Array of roles that are allowed (optional)
 * @param string $redirect_url URL to redirect to if not logged in (optional)
 */
function requireLogin($allowed_roles = null, $redirect_url = null) {
    if (!isLoggedIn($allowed_roles)) {
        // Check if session has expired
        if (isSessionExpired()) {
            destroySession();
        }
        
        // Set default redirect URL if not provided
        if ($redirect_url === null) {
            $redirect_url = "login.html";
        }
        
        header("location: " . $redirect_url);
        exit;
    }
    
    // Update session activity
    updateSessionActivity();
}

/**
 * Require admin access (admin or coach role)
 * Redirects to admin login if not authorized
 */
function requireAdminAccess() {
    requireLogin(['admin', 'coach'], "admin_login.html");
}

/**
 * Require coach access (coach role only)
 * Redirects to login if not authorized
 */
function requireCoachAccess() {
    requireLogin(['coach'], "login.html");
}

/**
 * Require parent access (parent role only)
 * Redirects to login if not authorized
 */
function requireParentAccess() {
    requireLogin(['parent'], "login.html");
}

/**
 * Get current user ID safely
 * Returns null if not logged in
 * 
 * @return int|null User ID or null if not logged in
 */
function getCurrentUserId() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return $_SESSION["id"];
}

/**
 * Get current user role safely
 * Returns null if not logged in
 * 
 * @return string|null User role or null if not logged in
 */
function getCurrentUserRole() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return $_SESSION["role"];
}

/**
 * Check if current user has a specific role
 * 
 * @param string $role Role to check
 * @return bool True if user has the specified role
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }
    
    return $_SESSION["role"] === $role;
}

/**
 * Check if current user has any of the specified roles
 * 
 * @param array $roles Array of roles to check
 * @return bool True if user has any of the specified roles
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    return in_array($_SESSION["role"], $roles);
}

/**
 * Get user's full name safely
 * Returns empty string if not logged in
 * 
 * @return string User's full name or empty string
 */
function getCurrentUserName() {
    if (!isLoggedIn()) {
        return "";
    }
    
    return $_SESSION["full_name"];
}

/**
 * Get user's email safely
 * Returns empty string if not logged in
 * 
 * @return string User's email or empty string
 */
function getCurrentUserEmail() {
    if (!isLoggedIn()) {
        return "";
    }
    
    return $_SESSION["email"];
}

/**
 * Validate session and return user data as JSON for AJAX requests
 * 
 * @param array $allowed_roles Array of roles that are allowed (optional)
 * @return void Exits with JSON response
 */
function validateSessionForAjax($allowed_roles = null) {
    header('Content-Type: application/json');
    
    if (!isLoggedIn($allowed_roles)) {
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    
    // Update session activity
    updateSessionActivity();
}

/**
 * Get session data as array for debugging or logging
 * 
 * @return array Session data
 */
function getSessionData() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return [
        'loggedin' => $_SESSION["loggedin"] ?? false,
        'id' => $_SESSION["id"] ?? null,
        'full_name' => $_SESSION["full_name"] ?? null,
        'email' => $_SESSION["email"] ?? null,
        'role' => $_SESSION["role"] ?? null,
        'login_time' => $_SESSION["login_time"] ?? null,
        'last_activity' => $_SESSION["last_activity"] ?? null,
        'admin_loggedin' => $_SESSION["admin_loggedin"] ?? false
    ];
}
?>
