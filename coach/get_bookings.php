<?php
// Set the content type to JSON
header('Content-Type: application/json');

// Start the session to verify login status
session_start();

// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    echo json_encode(['status' => 'error', 'message' => 'Authentication required.']);
    exit;
}

// Include the database configuration file
require_once "../db_config.php";

// Get parameters from the request
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);

if (!$class_id || !$date) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters.']);
    exit;
}

// --- Fetch Bookings ---
$sql = "SELECT 
            b.id AS booking_id,
            CONCAT(u.first_name, ' ', u.last_name) AS member_name,
            u.profile_picture_url,
            CASE WHEN b.attended = 1 THEN 'attended' ELSE 'booked' END AS status
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        WHERE b.class_id = ? AND b.booking_date = ?
        ORDER BY u.first_name, u.last_name";

$bookings = [];
if ($stmt = mysqli_prepare($link, $sql)) {
    mysqli_stmt_bind_param($stmt, "is", $class_id, $date);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $profile_pic = 'https://placehold.co/100x100/e2e8f0/333333?text=Pic'; // Default placeholder
            
            if (!empty($row['profile_picture_url'])) {
                // CORRECTED: The path from the DB is relative to the root.
                // This script is in the 'coach' directory, so we go up one level.
                $correct_path = '../' . $row['profile_picture_url'];

                if (file_exists($correct_path)) {
                    $profile_pic = $correct_path;
                }
            }
            $row['profile_picture_url'] = $profile_pic;
            $bookings[] = $row;
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to execute query.']);
        mysqli_stmt_close($stmt);
        mysqli_close($link);
        exit;
    }
    mysqli_stmt_close($stmt);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Database prepare statement failed.']);
    mysqli_close($link);
    exit;
}

mysqli_close($link);
echo json_encode(['status' => 'success', 'data' => $bookings]);
?>
