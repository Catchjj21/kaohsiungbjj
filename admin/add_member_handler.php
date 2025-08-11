<?php
// Start output buffering to prevent unwanted output from breaking the JSON response
ob_start();

session_start();

// Disable error display for a cleaner JSON response
// You can temporarily enable this for debugging by setting it to 'On'
ini_set('display_errors', 'Off');
error_reporting(E_ALL);

// Check if user is logged in and has appropriate role
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// Adjust path as needed for your db_config.php
require_once "../db_config.php";
header('Content-Type: application/json');

// Ensure the database connection is valid before proceeding
if (!isset($link) || !$link) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone_number = trim($_POST['phone_number'] ?? '');
    $member_type = trim($_POST['member_type'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $default_language = trim($_POST['default_language'] ?? 'en');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($member_type) || empty($role)) {
        throw new Exception("All required fields must be filled.");
    }
    
    // Check if a user with this email already exists
    $sql_check_email = "SELECT id FROM users WHERE email = ?";
    if ($stmt_check = mysqli_prepare($link, $sql_check_email)) {
        mysqli_stmt_bind_param($stmt_check, "s", $email);
        mysqli_stmt_execute($stmt_check);
        mysqli_stmt_store_result($stmt_check);
        if (mysqli_stmt_num_rows($stmt_check) > 0) {
            throw new Exception("A user with this email already exists.");
        }
        mysqli_stmt_close($stmt_check);
    }

    // Hash the password securely
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $profile_picture_url = "https://placehold.co/100x100/e2e8f0/333333?text=Pic"; // Default placeholder image

    // Get the next available old_card number for members
    $next_card_number = 1;
    if ($role === 'member') {
        $sql_get_next_card = "SELECT MAX(CAST(old_card AS UNSIGNED)) as max_card FROM users WHERE role = 'member' AND old_card IS NOT NULL AND old_card != '' AND old_card REGEXP '^[0-9]+$'";
        $result_next_card = mysqli_query($link, $sql_get_next_card);
        $row_next_card = mysqli_fetch_assoc($result_next_card);
        $next_card_number = ($row_next_card['max_card'] ?: 0) + 1;
    }

    // Prepare and execute the SQL insert statement
    // UPDATED: Changed 'password' to 'password_hash' and added old_card
    $sql = "INSERT INTO users (first_name, last_name, email, password_hash, phone_number, member_type, role, default_language, profile_picture_url, old_card) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    if ($stmt = mysqli_prepare($link, $sql)) {
        // UPDATED: Corresponding change to bind_param and added old_card
        mysqli_stmt_bind_param($stmt, "ssssssssss", $first_name, $last_name, $email, $hashed_password, $phone_number, $member_type, $role, $default_language, $profile_picture_url, $next_card_number);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception('Error adding member: ' . mysqli_error($link));
        }

        ob_end_clean();
        echo json_encode(['success' => true, 'message' => 'Member added successfully!']);
        mysqli_stmt_close($stmt);

    } else {
        throw new Exception('Error preparing statement: ' . mysqli_error($link));
    }

} catch (Exception $e) {
    ob_end_clean();
    error_log('Error in add_member_handler.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An internal server error occurred: ' . $e->getMessage()]);

} finally {
    if (isset($link) && is_object($link) && mysqli_ping($link)) {
        mysqli_close($link);
    }
}
?>
