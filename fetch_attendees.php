<?php
session_start();
header('Content-Type: application/json');

// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if user is logged in and has appropriate role
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    http_response_code(403); // Forbidden
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

require_once "/home/virtual/vps-d397de/f/fc63b9c1c0/public_html/db_config.php"; // Adjust path as needed

$input = json_decode(file_get_contents('php://input'), true);
$class_id = $input['class_id'] ?? null;
$class_date = $input['class_date'] ?? null;

if (empty($class_id) || empty($class_date)) {
    echo json_encode(['success' => false, 'message' => 'Class ID or date is missing.']);
    exit;
}

$attendees = [];
$count = 0; // Initialize a counter for attendees

// Fetch attendees for the specific class and date, including profile_picture_url
// IMPORTANT: 'u.email' has been removed from the SELECT statement to hide email addresses.
// 'u.profile_picture_url' has been added to retrieve profile pictures.
$sql_attendees = "SELECT u.first_name, u.last_name, u.profile_picture_url, b.booking_date FROM bookings b JOIN users u ON b.user_id = u.id WHERE b.class_id = ? AND b.booking_date = ? ORDER BY u.last_name, u.first_name";

if ($stmt_attendees = mysqli_prepare($link, $sql_attendees)) {
    mysqli_stmt_bind_param($stmt_attendees, "is", $class_id, $class_date);
    if (mysqli_stmt_execute($stmt_attendees)) {
        $result_attendees = mysqli_stmt_get_result($stmt_attendees);
        while ($row = mysqli_fetch_assoc($result_attendees)) {
            $count++; // Increment the counter for each attendee

            // Create an array with only the desired information: full name, profile picture URL, and the count.
            // The email address is intentionally excluded here.
            $attendee_info = [
                'count' => $count, // The sequential number for this attendee
                'full_name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'profile_picture_url' => $row['profile_picture_url'] // Profile picture URL from the database
            ];
            $attendees[] = $attendee_info;
        }
        // Return the success status, the list of attendees, and the total count of attendees.
        echo json_encode(['success' => true, 'attendees' => $attendees, 'total_attendees' => $count]);
    } else {
        // Log and return an error if SQL execution fails
        error_log("SQL Execution Error fetching attendees: " . mysqli_error($link));
        echo json_encode(['success' => false, 'message' => 'Database error: Could not fetch attendees.']);
    }
    mysqli_stmt_close($stmt_attendees);
} else {
    // Log and return an error if SQL statement preparation fails
    error_log("SQL Prepare Error fetching attendees: " . mysqli_error($link));
    echo json_encode(['success' => false, 'message' => 'Database error: Could not prepare statement.']);
}

mysqli_close($link);
?>
