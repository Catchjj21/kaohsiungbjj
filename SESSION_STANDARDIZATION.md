# Session Standardization Documentation

## Overview

This document explains the standardized session management system implemented to ensure all login sessions follow the same format across the application.

## Files Created/Modified

### New Files:
1. **`session_manager.php`** - Core session management functions
2. **`session_middleware.php`** - Session validation and authentication middleware
3. **`SESSION_STANDARDIZATION.md`** - This documentation file

### Modified Files:
1. **`login_handler.php`** - Updated to use standardized session creation
2. **`admin_login_handler.php`** - Updated to use standardized session creation
3. **`logout.php`** - Updated to use standardized session destruction
4. **`dashboard.php`** - Updated to use session middleware (example)

## Standardized Session Variables

All login sessions now use the following standardized variables:

```php
$_SESSION["loggedin"] = true;           // Boolean indicating login status
$_SESSION["id"] = $user_id;             // User ID from database
$_SESSION["full_name"] = $full_name;    // User's full name
$_SESSION["email"] = $email;            // User's email address
$_SESSION["role"] = $role;              // User's role (member, parent, coach, admin)
$_SESSION["login_time"] = $timestamp;   // Unix timestamp of login time
$_SESSION["last_activity"] = $timestamp; // Unix timestamp of last activity
```

For admin logins, an additional variable is set for backward compatibility:
```php
$_SESSION["admin_loggedin"] = true;     // Boolean for admin login status
```

## Core Functions

### Session Manager (`session_manager.php`)

#### `createStandardizedSession($user_id, $first_name, $last_name, $email, $role, $is_admin_login = false)`
Creates a standardized session for any user type.

**Parameters:**
- `$user_id` (int): User ID from database
- `$first_name` (string): User's first name
- `$last_name` (string): User's last name
- `$email` (string): User's email
- `$role` (string): User's role
- `$is_admin_login` (bool): Whether this is an admin login

**Returns:** Array of session data that was set

#### `isLoggedIn($allowed_roles = null)`
Validates if a user is logged in with standardized session.

**Parameters:**
- `$allowed_roles` (array, optional): Array of roles that are allowed

**Returns:** Boolean indicating login status

#### `getRedirectUrl($role, $is_admin_login = false)`
Gets the appropriate redirect URL based on user role.

**Parameters:**
- `$role` (string): User's role
- `$is_admin_login` (bool): Whether this was an admin login

**Returns:** String URL to redirect to

#### `destroySession()`
Destroys session and clears all session data.

**Returns:** Boolean indicating success

### Session Middleware (`session_middleware.php`)

#### `requireLogin($allowed_roles = null, $redirect_url = null)`
Requires user to be logged in, redirects to login page if not.

**Parameters:**
- `$allowed_roles` (array, optional): Array of roles that are allowed
- `$redirect_url` (string, optional): URL to redirect to if not logged in

#### `requireAdminAccess()`
Requires admin access (admin or coach role).

#### `requireCoachAccess()`
Requires coach access (coach role only).

#### `requireParentAccess()`
Requires parent access (parent role only).

#### `getCurrentUserId()`
Gets current user ID safely.

**Returns:** User ID or null if not logged in

#### `getCurrentUserRole()`
Gets current user role safely.

**Returns:** User role or null if not logged in

#### `hasRole($role)`
Checks if current user has a specific role.

**Parameters:**
- `$role` (string): Role to check

**Returns:** Boolean indicating if user has the role

#### `hasAnyRole($roles)`
Checks if current user has any of the specified roles.

**Parameters:**
- `$roles` (array): Array of roles to check

**Returns:** Boolean indicating if user has any of the roles

#### `validateSessionForAjax($allowed_roles = null)`
Validates session and returns user data as JSON for AJAX requests.

**Parameters:**
- `$allowed_roles` (array, optional): Array of roles that are allowed

## Usage Examples

### In Login Handlers

```php
// Include session manager
require_once "session_manager.php";

// After successful authentication
$session_data = createStandardizedSession($id, $first_name, $last_name, $email_from_db, $role, false);
$redirect_url = getRedirectUrl($role, false);
header("location: " . $redirect_url);
exit;
```

### In Protected Pages

```php
// Include session middleware
require_once "session_middleware.php";

// Require login for all users
requireLogin();

// Or require specific roles
requireAdminAccess();
requireCoachAccess();
requireParentAccess();

// Get current user information
$user_id = getCurrentUserId();
$user_name = getCurrentUserName();
$user_role = getCurrentUserRole();
```

### In AJAX Handlers

```php
// Include session middleware
require_once "session_middleware.php";

// Validate session for AJAX requests
validateSessionForAjax();

// Or validate for specific roles
validateSessionForAjax(['admin', 'coach']);
```

### Checking User Roles

```php
// Include session middleware
require_once "session_middleware.php";

if (hasRole('admin')) {
    // Admin-specific functionality
}

if (hasAnyRole(['admin', 'coach'])) {
    // Admin or coach functionality
}
```

## Migration Guide

### For Existing Files

1. **Replace manual session checks:**
   ```php
   // Old way
   if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
       header("location: login.html");
       exit;
   }
   
   // New way
   require_once "session_middleware.php";
   requireLogin();
   ```

2. **Replace session variable access:**
   ```php
   // Old way
   $user_id = $_SESSION['id'];
   $user_name = $_SESSION['full_name'];
   
   // New way
   $user_id = getCurrentUserId();
   $user_name = getCurrentUserName();
   ```

3. **Replace role checks:**
   ```php
   // Old way
   if ($_SESSION["role"] === 'admin') {
       // Admin functionality
   }
   
   // New way
   if (hasRole('admin')) {
       // Admin functionality
   }
   ```

### For AJAX Handlers

```php
// Old way
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

// New way
require_once "session_middleware.php";
validateSessionForAjax();
```

## Benefits

1. **Consistency:** All sessions follow the same format
2. **Security:** Centralized session validation and management
3. **Maintainability:** Easy to update session logic in one place
4. **Backward Compatibility:** Admin sessions still work with existing code
5. **Session Expiry:** Built-in session timeout functionality
6. **Activity Tracking:** Automatic session activity updates

## Security Features

- Session timeout (default: 8 hours)
- Automatic session activity updates
- Secure session destruction
- Role-based access control
- Session validation for AJAX requests

## Testing

To test the new session system:

1. Login as different user types (member, parent, coach, admin)
2. Verify session variables are set correctly
3. Test session validation on protected pages
4. Test session timeout functionality
5. Verify logout works correctly

## Troubleshooting

### Common Issues

1. **Session not persisting:** Check `db_config.php` session cookie settings
2. **Role-based access issues:** Verify user role in database
3. **AJAX authentication errors:** Ensure `validateSessionForAjax()` is called
4. **Redirect loops:** Check redirect URLs in `getRedirectUrl()` function

### Debug Functions

```php
// Get session data for debugging
$session_data = getSessionData();
print_r($session_data);

// Check if session is expired
if (isSessionExpired()) {
    echo "Session has expired";
}
```
