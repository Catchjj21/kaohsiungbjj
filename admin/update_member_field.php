<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database config FIRST.
require_once "../db_config.php";
session_start();

// Check if the user is logged in and has permission
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// Check for a valid POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['user_id']) || empty($_POST['field']) || !isset($_POST['value'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$user_id = $_POST['user_id'];
$field = $_POST['field'];
$value = $_POST['value'];

// Whitelist of editable fields to prevent SQL injection
$allowed_user_fields = ['first_name', 'last_name', 'email', 'phone_number', 'member_type', 'belt_color', 'role', 'default_language'];
$allowed_membership_fields = ['membership_type', 'start_date', 'end_date', 'class_credits'];

// Check which table the field belongs to
$table_name = in_array($field, $allowed_user_fields) ? 'users' : (in_array($field, $allowed_membership_fields) ? 'memberships' : null);

if (!$table_name) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid field name.']);
    exit;
}

// Start a transaction for data integrity
mysqli_begin_transaction($link);

try {
    if ($table_name === 'users') {
        $sql = "UPDATE users SET " . mysqli_real_escape_string($link, $field) . " = ? WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            $param_type = 'si';
            mysqli_stmt_bind_param($stmt, $param_type, $value, $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } else {
            throw new mysqli_sql_exception("Error preparing user update statement: " . mysqli_error($link));
        }
    } elseif ($table_name === 'memberships') {
        // Need to find the correct membership ID, as it might be a new one
        $membership_id_sql = "SELECT id FROM memberships WHERE user_id = ? AND end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = ?)";
        if ($stmt_id = mysqli_prepare($link, $membership_id_sql)) {
            mysqli_stmt_bind_param($stmt_id, "ii", $user_id, $user_id);
            mysqli_stmt_execute($stmt_id);
            $result_id = mysqli_stmt_get_result($stmt_id);
            $membership_id = mysqli_fetch_assoc($result_id)['id'] ?? null;
            mysqli_stmt_close($stmt_id);
        }

        if ($membership_id) {
            $sql = "UPDATE memberships SET " . mysqli_real_escape_string($link, $field) . " = ? WHERE id = ?";
            if ($stmt = mysqli_prepare($link, $sql)) {
                $param_type = 'si';
                // For a class_credits field, it's an integer, so adjust the binding type
                if ($field === 'class_credits' && is_numeric($value)) {
                    $param_type = 'ii';
                    $value = (int)$value;
                }
                mysqli_stmt_bind_param($stmt, $param_type, $value, $membership_id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            } else {
                throw new mysqli_sql_exception("Error preparing membership update statement: " . mysqli_error($link));
            }
        } else {
            // No current membership, cannot update.
            throw new Exception("No active membership found to update.");
        }
    }

    // Commit the transaction
    mysqli_commit($link);
    echo json_encode(['success' => true, 'message' => 'Update successful.']);
} catch (mysqli_sql_exception $e) {
    mysqli_rollback($link);
    error_log("SQL Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
} catch (Exception $e) {
    mysqli_rollback($link);
    error_log("Application Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

mysqli_close($link);
?>
