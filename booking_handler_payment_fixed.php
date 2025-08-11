<?php
// Initialize the session
session_start();

// Set the timezone to Taiwan
date_default_timezone_set('Asia/Taipei');

// Check if the user is logged in
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "You must be logged in to book classes."]);
    exit;
}

require_once "db_config.php";
$user_id = $_SESSION["id"];
$data = json_decode(file_get_contents("php://input"), true);
$lang = $data['lang'] ?? 'en';

// --- ENHANCED MEMBERSHIP & PAYMENT VALIDATION ---
$sql_check_membership = "
    SELECT 
        m.id as membership_id, 
        m.membership_type, 
        m.end_date, 
        m.class_credits,
        m.payment_due_date,
        m.payment_status as membership_payment_status,
        u.payment_status as user_payment_status
    FROM memberships m
    JOIN users u ON m.user_id = u.id
    WHERE m.user_id = ? 
    ORDER BY m.end_date DESC 
    LIMIT 1
";

$is_membership_valid = false;
$membership_details = null;
$payment_blocked = false;
$payment_message = '';

if($stmt_membership = mysqli_prepare($link, $sql_check_membership)){
    mysqli_stmt_bind_param($stmt_membership, "i", $user_id);
    if(mysqli_stmt_execute($stmt_membership)){
        $result = mysqli_stmt_get_result($stmt_membership);
        if(mysqli_num_rows($result) == 1){
            $membership_details = mysqli_fetch_assoc($result);
            
            // Check if membership is within valid date range
            if(strtotime($membership_details['end_date']) >= strtotime(date('Y-m-d'))){
                
                // Check payment status
                $current_date = date('Y-m-d');
                $due_date = $membership_details['payment_due_date'];
                
                // Payment validation logic
                if ($due_date && strtotime($due_date) < strtotime($current_date)) {
                    // Payment is overdue
                    if ($membership_details['membership_payment_status'] !== 'paid') {
                        $payment_blocked = true;
                        $payment_message = $lang === 'zh' ? 
                            "您的付款已逾期。請先付款以繼續預約課程。" : 
                            "Your payment is overdue. Please make payment to continue booking classes.";
                    }
                }
                
                // User payment status check
                if ($membership_details['user_payment_status'] === 'suspended') {
                    $payment_blocked = true;
                    $payment_message = $lang === 'zh' ? 
                        "您的帳戶已被暫停。請聯繫管理員。" : 
                        "Your account has been suspended. Please contact administration.";
                }
                
                // If payment is not blocked, check membership type
                if (!$payment_blocked) {
                    if (isset($membership_details['membership_type']) && strpos($membership_details['membership_type'], 'Class') !== false) {
                        // Class pass - check credits
                        if ($membership_details['class_credits'] > 0) {
                            $is_membership_valid = true;
                        }
                    } else {
                        // Unlimited membership - valid if payment is not blocked
                        $is_membership_valid = true;
                    }
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
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required data."]);
    exit;
}

$booking_date = date('Y-m-d', strtotime($booking_date_str));

// Prevent booking classes in the past
$sql_get_class_time = "SELECT start_time FROM classes WHERE id = ?";
if ($stmt_time = mysqli_prepare($link, $sql_get_class_time)) {
    mysqli_stmt_bind_param($stmt_time, "i", $class_id);
    if (mysqli_stmt_execute($stmt_time)) {
        $result_time = mysqli_stmt_get_result($stmt_time);
        $class_time_row = mysqli_fetch_assoc($result_time);
        
        if ($class_time_row) {
            $class_time = $class_time_row['start_time'];
            $class_datetime_str = $booking_date . ' ' . $class_time;
            $class_timestamp = strtotime($class_datetime_str);
            $current_timestamp = time();

            if ($class_timestamp < $current_timestamp) {
                $message = $lang === 'zh' ? 
                    "此課程已經開始，無法再預約或取消。" : 
                    "This class has already started and can no longer be booked or cancelled.";
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => $message]);
                exit;
            }
        }
    }
    mysqli_stmt_close($stmt_time);
}

if ($action === 'book') {
    // Check payment status first
    if ($payment_blocked) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => $payment_message]);
        exit;
    }
    
    // Check membership validity
    if(!$is_membership_valid){
        $message = $lang === 'zh' ? 
            "您的會員資格無效或剩餘課程點數不足。請續約以預約課程。" : 
            "Your membership is not active or you have no class credits remaining. Please renew to book classes.";
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => $message]);
        exit;
    }

    // Check class capacity
    $capacity_sql = "SELECT c.capacity, COUNT(b.id) as current_bookings FROM classes c LEFT JOIN bookings b ON c.id = b.class_id AND b.booking_date = ? WHERE c.id = ? GROUP BY c.id";
    if ($stmt_cap = mysqli_prepare($link, $capacity_sql)) {
        mysqli_stmt_bind_param($stmt_cap, "si", $booking_date, $class_id);
        mysqli_stmt_execute($stmt_cap);
        $class_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cap));
        mysqli_stmt_close($stmt_cap);
        
        if ($class_info && $class_info['current_bookings'] >= $class_info['capacity']) {
            $message = $lang === 'zh' ? 
                "此課程已滿。請加入候補名單。" : 
                "This class is full. Please join the waitlist.";
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => $message]);
            exit;
        }
    }

    // Book the class
    $sql_book = "INSERT INTO bookings (user_id, class_id, booking_date, status) VALUES (?, ?, ?, 'booked')";
    if($stmt_book = mysqli_prepare($link, $sql_book)){
        mysqli_stmt_bind_param($stmt_book, "iis", $user_id, $class_id, $booking_date);
        if(mysqli_stmt_execute($stmt_book)){
            // Decrement credits if it's a class pass
            if (isset($membership_details['membership_type']) && strpos($membership_details['membership_type'], 'Class') !== false) {
                $sql_decrement = "UPDATE memberships SET class_credits = class_credits - 1 WHERE id = ?";
                $stmt_decrement = mysqli_prepare($link, $sql_decrement);
                mysqli_stmt_bind_param($stmt_decrement, "i", $membership_details['membership_id']);
                mysqli_stmt_execute($stmt_decrement);
                mysqli_stmt_close($stmt_decrement);
            }
            
            $message = $lang === 'zh' ? 
                "課程預約成功！" : 
                "Class booked successfully!";
            echo json_encode(["status" => "success", "message" => $message]);
        } else {
            http_response_code(500);
            $message = $lang === 'zh' ? 
                "無法預約課程。" : 
                "Could not book the class.";
            echo json_encode(["status" => "error", "message" => $message]);
        }
        mysqli_stmt_close($stmt_book);
    }
} elseif ($action === 'cancel') {
    // Cancel booking logic (existing code)
    $sql_cancel = "DELETE FROM bookings WHERE user_id = ? AND class_id = ? AND booking_date = ?";
    if($stmt_cancel = mysqli_prepare($link, $sql_cancel)){
        mysqli_stmt_bind_param($stmt_cancel, "iis", $user_id, $class_id, $booking_date);
        if(mysqli_stmt_execute($stmt_cancel)){
            // Refund credits if it's a class pass
            if (isset($membership_details['membership_type']) && strpos($membership_details['membership_type'], 'Class') !== false) {
                $sql_increment = "UPDATE memberships SET class_credits = class_credits + 1 WHERE id = ?";
                $stmt_increment = mysqli_prepare($link, $sql_increment);
                mysqli_stmt_bind_param($stmt_increment, "i", $membership_details['membership_id']);
                mysqli_stmt_execute($stmt_increment);
                mysqli_stmt_close($stmt_increment);
            }
            
            $message = $lang === 'zh' ? 
                "課程已取消。" : 
                "Class cancelled successfully.";
            echo json_encode(["status" => "success", "message" => $message]);
        } else {
            http_response_code(500);
            $message = $lang === 'zh' ? 
                "無法取消課程。" : 
                "Could not cancel the class.";
            echo json_encode(["status" => "error", "message" => $message]);
        }
        mysqli_stmt_close($stmt_cancel);
    }
} else {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid action."]);
}

mysqli_close($link);
?>
