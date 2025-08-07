<?php
header('Content-Type: application/json');
// Initialize the session
session_start();

// Check if the user is logged in and is an admin or coach
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
    exit;
}

// Include the database configuration file
require_once "../db_config.php";

// Check if the form was submitted and user_id is present
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['user_id'])){
    $user_id = $_POST['user_id'];

    // Prepare a delete statement
    // Because of 'ON DELETE CASCADE' in the database schema, deleting a user
    // will also automatically delete their associated memberships and bookings.
    $sql = "DELETE FROM users WHERE id = ?";

    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        
        if(mysqli_stmt_execute($stmt)){
            echo json_encode(["status" => "success", "message" => "Member deleted successfully."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Failed to delete member."]);
        }
        mysqli_stmt_close($stmt);
    }
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid request."]);
}

mysqli_close($link);
?>
