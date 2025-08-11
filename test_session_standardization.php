<?php
/**
 * Test Script for Session Standardization
 * 
 * This script tests the new standardized session management system
 * to ensure all login sessions follow the same format.
 */

// Include the session manager
require_once "session_manager.php";

echo "<h1>Session Standardization Test</h1>\n";

// Test 1: Check if session manager functions exist
echo "<h2>Test 1: Function Availability</h2>\n";
$functions_to_test = [
    'createStandardizedSession',
    'isLoggedIn',
    'getRedirectUrl',
    'destroySession',
    'updateSessionActivity',
    'isSessionExpired',
    'getCurrentUser'
];

foreach ($functions_to_test as $function) {
    if (function_exists($function)) {
        echo "✓ Function '{$function}' exists<br>\n";
    } else {
        echo "✗ Function '{$function}' does not exist<br>\n";
    }
}

// Test 2: Test session creation
echo "<h2>Test 2: Session Creation</h2>\n";

// Simulate a regular user login
$test_user_id = 1;
$test_first_name = "John";
$test_last_name = "Doe";
$test_email = "john.doe@example.com";
$test_role = "member";

try {
    $session_data = createStandardizedSession($test_user_id, $test_first_name, $test_last_name, $test_email, $test_role, false);
    echo "✓ Regular user session created successfully<br>\n";
    echo "Session data: <pre>" . print_r($session_data, true) . "</pre>\n";
} catch (Exception $e) {
    echo "✗ Error creating regular user session: " . $e->getMessage() . "<br>\n";
}

// Test 3: Test admin session creation
echo "<h2>Test 3: Admin Session Creation</h2>\n";

try {
    $admin_session_data = createStandardizedSession($test_user_id, $test_first_name, $test_last_name, $test_email, "admin", true);
    echo "✓ Admin session created successfully<br>\n";
    echo "Admin session data: <pre>" . print_r($admin_session_data, true) . "</pre>\n";
} catch (Exception $e) {
    echo "✗ Error creating admin session: " . $e->getMessage() . "<br>\n";
}

// Test 4: Test session validation
echo "<h2>Test 4: Session Validation</h2>\n";

if (isLoggedIn()) {
    echo "✓ Session validation working - user is logged in<br>\n";
} else {
    echo "✗ Session validation failed - user should be logged in<br>\n";
}

if (isLoggedIn(['admin'])) {
    echo "✓ Admin role validation working<br>\n";
} else {
    echo "✗ Admin role validation failed<br>\n";
}

if (isLoggedIn(['member'])) {
    echo "✓ Member role validation working<br>\n";
} else {
    echo "✗ Member role validation failed<br>\n";
}

// Test 5: Test redirect URLs
echo "<h2>Test 5: Redirect URL Generation</h2>\n";

$roles_to_test = ['member', 'parent', 'coach', 'admin'];

foreach ($roles_to_test as $role) {
    $redirect_url = getRedirectUrl($role, false);
    echo "Role '{$role}' redirects to: {$redirect_url}<br>\n";
}

// Test admin redirect
$admin_redirect = getRedirectUrl('admin', true);
echo "Admin login redirects to: {$admin_redirect}<br>\n";

// Test 6: Test current user functions
echo "<h2>Test 6: Current User Functions</h2>\n";

$current_user = getCurrentUser();
if ($current_user) {
    echo "✓ Current user data retrieved successfully<br>\n";
    echo "Current user: <pre>" . print_r($current_user, true) . "</pre>\n";
} else {
    echo "✗ Failed to get current user data<br>\n";
}

// Test 7: Test session expiry
echo "<h2>Test 7: Session Expiry Check</h2>\n";

if (isSessionExpired()) {
    echo "✗ Session has expired (this shouldn't happen for a fresh session)<br>\n";
} else {
    echo "✓ Session is still valid<br>\n";
}

// Test 8: Test session destruction
echo "<h2>Test 8: Session Destruction</h2>\n";

if (destroySession()) {
    echo "✓ Session destroyed successfully<br>\n";
} else {
    echo "✗ Failed to destroy session<br>\n";
}

// Verify session is destroyed
if (isLoggedIn()) {
    echo "✗ Session still exists after destruction<br>\n";
} else {
    echo "✓ Session properly destroyed<br>\n";
}

echo "<h2>Test Summary</h2>\n";
echo "All session standardization tests completed. Check the results above to ensure all functions are working correctly.<br>\n";
echo "If you see any ✗ marks, there may be issues that need to be addressed.<br>\n";

// Include session middleware test
echo "<h2>Session Middleware Test</h2>\n";

// Test if session middleware functions exist
$middleware_functions = [
    'requireLogin',
    'requireAdminAccess',
    'requireCoachAccess',
    'requireParentAccess',
    'getCurrentUserId',
    'getCurrentUserRole',
    'hasRole',
    'hasAnyRole',
    'getCurrentUserName',
    'getCurrentUserEmail',
    'validateSessionForAjax',
    'getSessionData'
];

foreach ($middleware_functions as $function) {
    if (function_exists($function)) {
        echo "✓ Middleware function '{$function}' exists<br>\n";
    } else {
        echo "✗ Middleware function '{$function}' does not exist<br>\n";
    }
}

echo "<br><strong>Session Standardization Test Complete!</strong><br>\n";
echo "If all tests pass, your session standardization is working correctly.<br>\n";
?>
