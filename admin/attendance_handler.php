<?php
// Initialize the session
session_start();

// Check if the user is logged in and is an admin or coach
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    http_response_code(401); // Unauthorized
    echo json_encode(["status" => "error", "message" => "Not authorized."]);
    exit;
}

// Include the database configuration file
require_once "../db_config.php";

// Get the posted data
$data = json_decode(file_get_contents("php://input"), true);
$booking_id = $data['booking_id'] ?? null;

if (!$booking_id) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "Booking ID is missing."]);
    exit;
}

// Prepare an update statement to change the status to 'attended'
$sql = "UPDATE bookings SET status = 'attended' WHERE id = ?";

if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $booking_id);
    if(mysqli_stmt_execute($stmt)){
        echo json_encode(["status" => "success", "message" => "Attendance marked successfully."]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Could not update attendance."]);
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($link);
?>
