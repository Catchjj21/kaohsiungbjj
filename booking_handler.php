<?php
// Initialize the session
session_start();

// --- NEW: Set the timezone to Taiwan ---
date_default_timezone_set('Asia/Taipei');

// Check if the user is logged in, if not, return an error
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401); // Unauthorized
    echo json_encode(["status" => "error", "message" => "You must be logged in to book classes."]);
    exit;
}

// Include the database configuration file
require_once "db_config.php";

// Get the user ID from the session
$user_id = $_SESSION["id"];

// Get the posted data
$data = json_decode(file_get_contents("php://input"), true);

// Get language from the JSON data sent by the dashboard
$lang = $data['lang'] ?? 'en';

// --- MEMBERSHIP VALIDATION (MODIFIED) ---
$sql_check_membership = "SELECT id as membership_id, membership_type, end_date, class_credits FROM memberships WHERE user_id = ? ORDER BY end_date DESC LIMIT 1";
$is_membership_valid = false;
$membership_details = null;

if($stmt_membership = mysqli_prepare($link, $sql_check_membership)){
    mysqli_stmt_bind_param($stmt_membership, "i", $user_id);
    if(mysqli_stmt_execute($stmt_membership)){
        $result = mysqli_stmt_get_result($stmt_membership);
        if(mysqli_num_rows($result) == 1){
            $membership_details = mysqli_fetch_assoc($result);
            // First, check if the membership is within its valid date range.
            if(strtotime($membership_details['end_date']) >= strtotime(date('Y-m-d'))){
                // Check if the membership type is a credit-based pass (e.g., "4 Class Pass").
                // We assume passes that require credits will have "Class" in their name.
                if (isset($membership_details['membership_type']) && strpos($membership_details['membership_type'], 'Class') !== false) {
                    // If it's a class pass, it's only valid if there are credits remaining.
                    if ($membership_details['class_credits'] > 0) {
                        $is_membership_valid = true;
                    }
                } else {
                    // For any other type of membership (e.g., "All-Inclusive Pass", monthly unlimited),
                    // it's valid as long as the date is okay.
                    $is_membership_valid = true;
                }
            }
        }
    }
    mysqli_stmt_close($stmt_membership);
}


$class_id = $data['class_id'] ?? null;
$booking_date_str = $data['booking_date'] ?? null;
$action = $data['action'] ?? null;

if (!$class_id || !$booking_date_str || !$action) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "Missing required data."]);
    exit;
}

// Convert string date to date object to prevent SQL injection
$booking_date = date('Y-m-d', strtotime($booking_date_str));

// --- Prevent booking/cancelling classes in the past ---
$sql_get_class_time = "SELECT start_time FROM classes WHERE id = ?";
if ($stmt_time = mysqli_prepare($link, $sql_get_class_time)) {
    mysqli_stmt_bind_param($stmt_time, "i", $class_id);
    if (mysqli_stmt_execute($stmt_time)) {
        $result_time = mysqli_stmt_get_result($stmt_time);
        $class_time_row = mysqli_fetch_assoc($result_time);
        
        if ($class_time_row) {
            $class_time = $class_time_row['start_time'];
            // Combine the booking date and class start time
            $class_datetime_str = $booking_date . ' ' . $class_time;
            $class_timestamp = strtotime($class_datetime_str);
            $current_timestamp = time();

            if ($class_timestamp < $current_timestamp) {
                $message = "This class has already started and can no longer be booked or cancelled.";
                if ($lang === 'zh') {
                    $message = "此課程已經開始，無法再預約或取消。";
                }
                http_response_code(403); // Forbidden
                echo json_encode(["status" => "error", "message" => $message]);
                exit;
            }
        }
    }
    mysqli_stmt_close($stmt_time);
}


if ($action === 'book') {
    // --- BOOK A CLASS ---
    if(!$is_membership_valid){
        $message = "Your membership is not active or you have no class credits remaining. Please renew to book classes.";
        if ($lang === 'zh') $message = "您的會員資格無效或剩餘課程點數不足。請續約以預約課程。";
        http_response_code(403); // Forbidden
        echo json_encode(["status" => "error", "message" => $message]);
        exit;
    }

    // (Class capacity check remains the same)
    // ...

    // --- Book the class and decrement credits if necessary ---
    $sql_book = "INSERT INTO bookings (user_id, class_id, booking_date, status) VALUES (?, ?, ?, 'booked')";
    if($stmt_book = mysqli_prepare($link, $sql_book)){
        mysqli_stmt_bind_param($stmt_book, "iis", $user_id, $class_id, $booking_date);
        if(mysqli_stmt_execute($stmt_book)){
            // If it's a class pass, decrement the credits
            if (isset($membership_details['membership_type']) && strpos($membership_details['membership_type'], 'Class') !== false) {
                $sql_decrement = "UPDATE memberships SET class_credits = class_credits - 1 WHERE id = ?";
                $stmt_decrement = mysqli_prepare($link, $sql_decrement);
                mysqli_stmt_bind_param($stmt_decrement, "i", $membership_details['membership_id']);
                mysqli_stmt_execute($stmt_decrement);
                mysqli_stmt_close($stmt_decrement);
            }
            echo json_encode(["status" => "success", "message" => "Class booked successfully."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Could not book the class."]);
        }
        mysqli_stmt_close($stmt_book);
    }

} elseif ($action === 'cancel') {
    // --- CANCEL A BOOKING ---
    
    // Check if the user is on a class pass to refund the credit.
    if (isset($membership_details['membership_type']) && strpos($membership_details['membership_type'], 'Class') !== false) {
        // Prepare an update statement to increment the class_credits
        $sql_increment = "UPDATE memberships SET class_credits = class_credits + 1 WHERE id = ?";
        if($stmt_increment = mysqli_prepare($link, $sql_increment)){
            mysqli_stmt_bind_param($stmt_increment, "i", $membership_details['membership_id']);
            mysqli_stmt_execute($stmt_increment);
            mysqli_stmt_close($stmt_increment);
        }
    }

    // Proceed with deleting the booking record
    $sql = "DELETE FROM bookings WHERE user_id = ? AND class_id = ? AND booking_date = ?";
    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "iis", $user_id, $class_id, $booking_date);
        if(mysqli_stmt_execute($stmt)){
            echo json_encode(["status" => "success", "message" => "Booking cancelled."]);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Could not cancel the booking."]);
        }
        mysqli_stmt_close($stmt);
    }
}

// Close connection
mysqli_close($link);
?>
