<?php
/**
 * Admin Authentication Helper
 * 
 * This file provides centralized authentication for admin pages.
 * It allows access for users who are either:
 * 1. Logged in through the separate admin login (admin_loggedin session)
 * 2. Already logged in as admin/coach through the regular dashboard
 */

function requireAdminAccess($allowed_roles = ['admin', 'coach']) {
    // Check if the user is logged in and has the required role
    // Allow access if either:
    // 1. They have admin_loggedin session (separate admin login)
    // 2. They are already logged in as admin/coach through regular dashboard
    if((!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true) && 
       (!isset($_SESSION["id"]) || !in_array($_SESSION["role"], $allowed_roles))){
        
        // Redirect to admin login page
        header("location: ../admin_login.html");
        exit;
    }
    
    // If they don't have admin_loggedin but are logged in as admin/coach,
    // set admin_loggedin to true for consistency with other admin functions
    if (!isset($_SESSION["admin_loggedin"]) && isset($_SESSION["id"]) && in_array($_SESSION["role"], $allowed_roles)) {
        $_SESSION["admin_loggedin"] = true;
    }
}

/**
 * Check if current user has admin access
 * Returns true if user can access admin functions, false otherwise
 */
function hasAdminAccess($allowed_roles = ['admin', 'coach']) {
    return (isset($_SESSION["admin_loggedin"]) && $_SESSION["admin_loggedin"] === true) ||
           (isset($_SESSION["id"]) && in_array($_SESSION["role"], $allowed_roles));
}
?>
