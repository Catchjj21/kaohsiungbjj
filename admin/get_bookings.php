<?php
// Set the Content-Type header to application/json
header('Content-Type: application/json');

// Start output buffering to prevent "headers already sent" errors
ob_start();

// IMPORTANT: Include the database configuration file BEFORE session_start()
require_once "../db_config.php";

session_start();

// Check if the user is logged in and is an admin or coach.
// This is crucial for securing the API endpoint.
// FIX: Corrected the session variable from "loggedin" to "admin_loggedin"
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

// Check if database connection was successful
if (mysqli_connect_errno()) {
    error_log("Database connection failed: " . mysqli_connect_error());
    echo json_encode(["status" => "error", "message" => "Database connection failed."]);
    exit;
}

// --- Handle GET request for fetching bookings ---
if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET['class_id']) && isset($_GET['date'])) {
    $class_id = $_GET['class_id'];
    $date = $_GET['date'];

    // SQL: Select booking details and member names
    $sql = "SELECT b.id AS booking_id, b.status, u.first_name, u.last_name FROM bookings b JOIN users u ON b.user_id = u.id WHERE b.class_id = ? AND b.booking_date = ?";
    
    $bookings = [];
    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "is", $class_id, $date);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                while($row = mysqli_fetch_assoc($result)){
                    // Concatenate first_name and last_name for display
                    $row['member_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
                    $bookings[] = $row;
                }
                echo json_encode(["status" => "success", "data" => $bookings]);
            } else {
                echo json_encode(["status" => "success", "data" => [], "message" => "No bookings found for this class and date."]);
            }
        } else {
            error_log("Failed to execute query: " . mysqli_error($link));
            echo json_encode(["status" => "error", "message" => "Failed to fetch bookings: " . mysqli_error($link)]);
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log("Failed to prepare statement: " . mysqli_error($link));
        echo json_encode(["status" => "error", "message" => "Failed to prepare fetch bookings statement: " . mysqli_error($link)]);
    }
} 

// --- Handle POST request for cancelling a booking (NEW LOGIC) ---
else if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Decode the JSON payload from the request body
    $data = json_decode(file_get_contents("php://input"), true);

    if (isset($data['action']) && $data['action'] === 'cancel' && isset($data['booking_id'])) {
        $booking_id = $data['booking_id'];

        $sql_delete = "DELETE FROM bookings WHERE id = ?";
        if ($stmt_delete = mysqli_prepare($link, $sql_delete)) {
            mysqli_stmt_bind_param($stmt_delete, "i", $booking_id);
            if (mysqli_stmt_execute($stmt_delete)) {
                echo json_encode(["status" => "success", "message" => "Booking cancelled successfully."]);
            } else {
                error_log("Failed to delete booking: " . mysqli_error($link));
                echo json_encode(["status" => "error", "message" => "Failed to cancel booking."]);
            }
            mysqli_stmt_close($stmt_delete);
        } else {
            error_log("Failed to prepare delete statement: " . mysqli_error($link));
            echo json_encode(["status" => "error", "message" => "Failed to prepare cancellation statement."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid action or missing booking ID for POST request."]);
    }
}
// Handle cases where the request is neither a GET nor a POST
else {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "Invalid request method or missing parameters."]);
}

mysqli_close($link);
?>