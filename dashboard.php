<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database config FIRST. This is important if it contains session settings.
require_once "db_config.php";

// Start the session once at the beginning of the script.
session_start();

// --- START AJAX HANDLER ---
// This block handles asynchronous requests from the JavaScript on the page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    // The session is already started, so we can directly access session variables.
    // Ensure user is logged in for these actions
    if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }

    $user_id = $_SESSION['id'];
    $action = $_POST['action'];

    header('Content-Type: application/json');

    switch ($action) {
        case 'book_class':
            $class_id = $_POST['class_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';

            // --- Server-side validation ---
            // 1. Check if membership is valid
            $check_sql = "SELECT m.end_date, m.class_credits, m.membership_type FROM memberships m WHERE m.user_id = ? AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = ?)";
            $stmt_check = mysqli_prepare($link, $check_sql);
            mysqli_stmt_bind_param($stmt_check, "ii", $user_id, $user_id);
            mysqli_stmt_execute($stmt_check);
            $member_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
            mysqli_stmt_close($stmt_check);

            $is_valid = false;
            if ($member_info && strtotime($member_info['end_date']) >= strtotime(date('Y-m-d'))) {
                if (strpos($member_info['membership_type'], 'Class') !== false) { // It's a class pass
                    if ($member_info['class_credits'] > 0) $is_valid = true;
                } else { // It's an unlimited membership
                    $is_valid = true;
                }
            }

            if (!$is_valid) {
                echo json_encode(['success' => false, 'message' => 'Your membership is expired or you have no class credits.']);
                exit;
            }

            // 2. Check class capacity
            $capacity_sql = "SELECT c.capacity, COUNT(b.id) as current_bookings FROM classes c LEFT JOIN bookings b ON c.id = b.class_id AND b.booking_date = ? WHERE c.id = ? GROUP BY c.id";
            $stmt_cap = mysqli_prepare($link, $capacity_sql);
            mysqli_stmt_bind_param($stmt_cap, "si", $booking_date, $class_id);
            mysqli_stmt_execute($stmt_cap);
            $class_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cap));
            mysqli_stmt_close($stmt_cap);

            // Check if the class is full
            if ($class_info && $class_info['current_bookings'] >= $class_info['capacity']) {
                $waitlist_check_sql = "SELECT COUNT(*) FROM waitlist WHERE user_id = ? AND class_id = ? AND booking_date = ?";
                $stmt_waitlist_check = mysqli_prepare($link, $waitlist_check_sql);
                mysqli_stmt_bind_param($stmt_waitlist_check, "iis", $user_id, $class_id, $booking_date);
                mysqli_stmt_execute($stmt_waitlist_check);
                $waitlist_count = mysqli_fetch_row(mysqli_stmt_get_result($stmt_waitlist_check))[0];
                mysqli_stmt_close($stmt_waitlist_check);

                if ($waitlist_count > 0) {
                    echo json_encode(['success' => false, 'message' => 'You are already on the waitlist for this class.']);
                } else {
                    $waitlist_sql = "INSERT INTO waitlist (user_id, class_id, booking_date) VALUES (?, ?, ?)";
                    $stmt_waitlist = mysqli_prepare($link, $waitlist_sql);
                    mysqli_stmt_bind_param($stmt_waitlist, "iis", $user_id, $class_id, $booking_date);
                    mysqli_stmt_execute($stmt_waitlist);
                    mysqli_stmt_close($stmt_waitlist);
                    echo json_encode(['success' => true, 'message' => 'Class is full, you have been added to the waitlist.']);
                }
                exit;
            }

            // --- Perform booking (only if space is available) ---
            mysqli_begin_transaction($link);
            try {
                $insert_sql = "INSERT INTO bookings (user_id, class_id, booking_date, status) VALUES (?, ?, ?, 'booked')";
                $stmt_insert = mysqli_prepare($link, $insert_sql);
                mysqli_stmt_bind_param($stmt_insert, "iis", $user_id, $class_id, $booking_date);
                mysqli_stmt_execute($stmt_insert);
                mysqli_stmt_close($stmt_insert);

                if (strpos($member_info['membership_type'], 'Class') !== false) {
                    $update_sql = "UPDATE memberships SET class_credits = class_credits - 1 WHERE user_id = ? AND end_date = ?";
                    $stmt_update = mysqli_prepare($link, $update_sql);
                    mysqli_stmt_bind_param($stmt_update, "is", $user_id, $member_info['end_date']);
                    mysqli_stmt_execute($stmt_update);
                    mysqli_stmt_close($stmt_update);
                }

                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Class booked successfully!']);
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($link);
                error_log("Booking failed: " . $exception->getMessage());
                echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
            }
            break;

        case 'cancel_class':
            $class_id = $_POST['class_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';

            mysqli_begin_transaction($link);
            try {
                $check_sql = "SELECT m.end_date, m.membership_type FROM memberships m WHERE m.user_id = ? AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = ?)";
                $stmt_check = mysqli_prepare($link, $check_sql);
                mysqli_stmt_bind_param($stmt_check, "ii", $user_id, $user_id);
                mysqli_stmt_execute($stmt_check);
                $member_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
                mysqli_stmt_close($stmt_check);

                $delete_sql = "DELETE FROM bookings WHERE user_id = ? AND class_id = ? AND booking_date = ?";
                $stmt_delete = mysqli_prepare($link, $delete_sql);
                mysqli_stmt_bind_param($stmt_delete, "iis", $user_id, $class_id, $booking_date);
                mysqli_stmt_execute($stmt_delete);
                $affected_rows = mysqli_stmt_affected_rows($stmt_delete);
                mysqli_stmt_close($stmt_delete);

                if ($affected_rows > 0 && $member_info && strpos($member_info['membership_type'], 'Class') !== false) {
                    $update_sql = "UPDATE memberships SET class_credits = class_credits + 1 WHERE user_id = ? AND end_date = ?";
                    $stmt_update = mysqli_prepare($link, $update_sql);
                    mysqli_stmt_bind_param($stmt_update, "is", $user_id, $member_info['end_date']);
                    mysqli_stmt_execute($stmt_update);
                    mysqli_stmt_close($stmt_update);
                }

                $waitlist_sql = "SELECT user_id FROM waitlist WHERE class_id = ? AND booking_date = ? ORDER BY request_date ASC LIMIT 1";
                $stmt_waitlist = mysqli_prepare($link, $waitlist_sql);
                mysqli_stmt_bind_param($stmt_waitlist, "is", $class_id, $booking_date);
                mysqli_stmt_execute($stmt_waitlist);
                $waitlist_user_result = mysqli_stmt_get_result($stmt_waitlist);
                $waitlist_user = mysqli_fetch_assoc($waitlist_user_result);
                mysqli_stmt_close($stmt_waitlist);

                if ($waitlist_user) {
                    $waitlist_user_id = $waitlist_user['user_id'];
                    $auto_book_sql = "INSERT INTO bookings (user_id, class_id, booking_date, status) VALUES (?, ?, ?, 'booked')";
                    $stmt_auto_book = mysqli_prepare($link, $auto_book_sql);
                    mysqli_stmt_bind_param($stmt_auto_book, "iis", $waitlist_user_id, $class_id, $booking_date);
                    mysqli_stmt_execute($stmt_auto_book);
                    mysqli_stmt_close($stmt_auto_book);

                    $remove_waitlist_sql = "DELETE FROM waitlist WHERE user_id = ? AND class_id = ? AND booking_date = ?";
                    $stmt_remove_waitlist = mysqli_prepare($link, $remove_waitlist_sql);
                    mysqli_stmt_bind_param($stmt_remove_waitlist, "iis", $waitlist_user_id, $class_id, $booking_date);
                    mysqli_stmt_execute($stmt_remove_waitlist);
                    mysqli_stmt_close($stmt_remove_waitlist);

                    error_log("Waitlisted user {$waitlist_user_id} was auto-booked for class {$class_id} on {$booking_date}.");
                }

                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully!']);
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($link);
                error_log("Cancellation failed: " . $exception->getMessage());
                echo json_encode(['success' => false, 'message' => 'An error occurred during cancellation.']);
            }
            break;

        case 'join_waitlist':
            $class_id = $_POST['class_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';

            $waitlist_check_sql = "SELECT COUNT(*) FROM waitlist WHERE user_id = ? AND class_id = ? AND booking_date = ?";
            $stmt_waitlist_check = mysqli_prepare($link, $waitlist_check_sql);
            mysqli_stmt_bind_param($stmt_waitlist_check, "iis", $user_id, $class_id, $booking_date);
            mysqli_stmt_execute($stmt_waitlist_check);
            $waitlist_count = mysqli_fetch_row(mysqli_stmt_get_result($stmt_waitlist_check))[0];
            mysqli_stmt_close($stmt_waitlist_check);

            if ($waitlist_count > 0) {
                echo json_encode(['success' => false, 'message' => 'You are already on the waitlist for this class.']);
                exit;
            }

            $waitlist_sql = "INSERT INTO waitlist (user_id, class_id, booking_date) VALUES (?, ?, ?)";
            if ($stmt = mysqli_prepare($link, $waitlist_sql)) {
                mysqli_stmt_bind_param($stmt, "iis", $user_id, $class_id, $booking_date);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                echo json_encode(['success' => true, 'message' => 'You have been added to the waitlist for this class.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
            exit;

        case 'submit_payment':
            $payment_method = $_POST['payment_method'] ?? 'N/A';
            $amount = $_POST['amount'] ?? '0';
            $last_five = $_POST['last_five'] ?? '';
            $member_name = $_SESSION['full_name'];
            $member_email = $_SESSION['email'];

            $stmt_pic = mysqli_prepare($link, "SELECT profile_picture_url FROM users WHERE id = ?");
            mysqli_stmt_bind_param($stmt_pic, "i", $user_id);
            mysqli_stmt_execute($stmt_pic);
            $result_pic = mysqli_stmt_get_result($stmt_pic);
            $user_data = mysqli_fetch_assoc($result_pic);
            $profile_pic_path = $user_data['profile_picture_url'] ?? null;
            mysqli_stmt_close($stmt_pic);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domain_name = $_SERVER['HTTP_HOST'];
            $base_url = rtrim($protocol . $domain_name, '/');
            $logo_url = $base_url . '/logo.png';

            $profile_pic_url = 'https://placehold.co/80x80/e2e8f0/333333?text=Pic';
            if ($profile_pic_path) {
                if (preg_match('/^https?:\/\//', $profile_pic_path)) {
                    $profile_pic_url = $profile_pic_path;
                } else {
                    $profile_pic_url = $base_url . '/' . ltrim($profile_pic_path, '/');
                }
            }

            $to = 'catchjiujitsu@gmail.com';
            $subject = 'New Payment Submission from ' . $member_name;
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= 'From: <webmaster@yourdomain.com>' . "\r\n";

            $message = "
            <html><body>
                <p>Payment submitted for member: <strong>{$member_name}</strong></p>
                <p>Email: {$member_email}</p>
                <p>Method: {$payment_method}</p>
                <p>Amount: {$amount}</p>";
            if ($payment_method === 'Bank Transfer' && !empty($last_five)) {
                $message .= "<p>Last 5 Digits: {$last_five}</p>";
            }
            $message .= "</body></html>";

            if (mail($to, $subject, $message, $headers)) {
                echo json_encode(['success' => true, 'message' => 'Your payment submission has been sent!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'There was an error sending your payment submission.']);
            }
            break;

        case 'reply_message':
            $original_recipient_id = $_POST['original_recipient_id'] ?? 0;
            $reply_body = $_POST['reply_body'] ?? '';

            if (empty($reply_body) || $original_recipient_id == 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
                exit;
            }

            mysqli_begin_transaction($link);
            try {
                // 1. Get info about the original message from the recipient record
                $sql_original = "SELECT m.id as message_id, m.sender_id, m.subject, m.thread_id
                                 FROM message_recipients mr
                                 JOIN messages m ON mr.message_id = m.id
                                 WHERE mr.id = ? AND mr.recipient_id = ?";
                $stmt_original = mysqli_prepare($link, $sql_original);
                mysqli_stmt_bind_param($stmt_original, "ii", $original_recipient_id, $user_id);
                mysqli_stmt_execute($stmt_original);
                $original_message = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_original));
                mysqli_stmt_close($stmt_original);

                if (!$original_message) {
                    throw new Exception("Original message not found or permission denied.");
                }

                $recipient_user_id = $original_message['sender_id'];
                $original_message_id = $original_message['message_id'];
                $original_subject = $original_message['subject'];
                // Use existing thread_id or create a new one from the original message id
                $thread_id = $original_message['thread_id'] ?? $original_message_id; 

                // 2. Create the new reply message
                $new_subject = (strpos($original_subject, 'Re: ') === 0) ? $original_subject : 'Re: ' . $original_subject;

                $sql_insert_msg = "INSERT INTO messages (sender_id, subject, body, thread_id) VALUES (?, ?, ?, ?)";
                $stmt_insert_msg = mysqli_prepare($link, $sql_insert_msg);
                mysqli_stmt_bind_param($stmt_insert_msg, "issi", $user_id, $new_subject, $reply_body, $thread_id);
                mysqli_stmt_execute($stmt_insert_msg);
                $new_message_id = mysqli_insert_id($link);
                mysqli_stmt_close($stmt_insert_msg);

                // 3. Create the recipient record for the new message
                $sql_insert_recipient = "INSERT INTO message_recipients (message_id, recipient_id) VALUES (?, ?)";
                $stmt_insert_recipient = mysqli_prepare($link, $sql_insert_recipient);
                mysqli_stmt_bind_param($stmt_insert_recipient, "ii", $new_message_id, $recipient_user_id);
                mysqli_stmt_execute($stmt_insert_recipient);
                mysqli_stmt_close($stmt_insert_recipient);

                mysqli_commit($link);

                // Fetch the newly created message to send back to the client
                $sql_new_msg = "SELECT
                                    m.id, m.subject, m.body, m.created_at, m.sender_id,
                                    CONCAT(u.first_name, ' ', u.last_name) as sender_name,
                                    u.profile_picture_url
                                FROM messages m
                                JOIN users u ON m.sender_id = u.id
                                WHERE m.id = ?";
                $stmt_new_msg = mysqli_prepare($link, $sql_new_msg);
                mysqli_stmt_bind_param($stmt_new_msg, "i", $new_message_id);
                mysqli_stmt_execute($stmt_new_msg);
                $new_message_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_new_msg));
                mysqli_stmt_close($stmt_new_msg);

                echo json_encode(['success' => true, 'message' => 'Reply sent successfully!', 'newMessage' => $new_message_data]);

            } catch (Exception $e) {
                mysqli_rollback($link);
                error_log("Reply failed: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'An error occurred while sending the reply.']);
            }
            break;

        case 'get_message_thread':
            $recipient_id = $_POST['recipient_id'] ?? 0;

            // First, find the thread_id from the clicked message_recipient record
            $sql_thread_id = "SELECT COALESCE(m.thread_id, m.id) as thread_id FROM message_recipients mr JOIN messages m ON mr.message_id = m.id WHERE mr.id = ? AND mr.recipient_id = ?";
            $stmt_thread_id = mysqli_prepare($link, $sql_thread_id);
            mysqli_stmt_bind_param($stmt_thread_id, "ii", $recipient_id, $user_id);
            mysqli_stmt_execute($stmt_thread_id);
            $result_thread_id = mysqli_stmt_get_result($stmt_thread_id);
            $thread_info = mysqli_fetch_assoc($result_thread_id);
            mysqli_stmt_close($stmt_thread_id);

            if (!$thread_info) {
                echo json_encode(['success' => false, 'message' => 'Message thread not found.']);
                exit;
            }

            $thread_id = $thread_info['thread_id'];

            // Now fetch all messages in that thread that the user is involved in
            $sql_thread = "SELECT
                                m.id, m.subject, m.body, m.created_at, m.sender_id,
                                CONCAT(u.first_name, ' ', u.last_name) as sender_name,
                                u.profile_picture_url
                           FROM messages m
                           JOIN users u ON m.sender_id = u.id
                           WHERE (m.id = ? OR m.thread_id = ?)
                           AND (m.sender_id = ? OR EXISTS (SELECT 1 FROM message_recipients mr WHERE mr.message_id = m.id AND mr.recipient_id = ?))
                           ORDER BY m.created_at ASC";

            $stmt_thread = mysqli_prepare($link, $sql_thread);
            mysqli_stmt_bind_param($stmt_thread, "iiii", $thread_id, $thread_id, $user_id, $user_id);
            mysqli_stmt_execute($stmt_thread);
            $result_thread = mysqli_stmt_get_result($stmt_thread);
            $thread_messages = mysqli_fetch_all($result_thread, MYSQLI_ASSOC);
            mysqli_stmt_close($stmt_thread);

            echo json_encode(['success' => true, 'thread' => $thread_messages]);
            break;

        case 'mark_as_read':
            // This action will now mark a whole thread as read
            $recipient_id = $_POST['recipient_id'] ?? 0; // The recipient_id of the message that was clicked to open the thread.
            if ($recipient_id > 0) {
                // Find the thread this message belongs to
                $sql_thread_info = "SELECT COALESCE(m.thread_id, m.id) as thread_id
                                    FROM message_recipients mr
                                    JOIN messages m ON mr.message_id = m.id
                                    WHERE mr.id = ? AND mr.recipient_id = ?";
                $stmt_info = mysqli_prepare($link, $sql_thread_info);
                mysqli_stmt_bind_param($stmt_info, "ii", $recipient_id, $user_id);
                mysqli_stmt_execute($stmt_info);
                $thread_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_info));
                mysqli_stmt_close($stmt_info);

                if ($thread_info) {
                    $thread_id = $thread_info['thread_id'];
                    // Mark all messages in this thread for this user as read
                    $sql_update = "UPDATE message_recipients mr
                                   JOIN messages m ON mr.message_id = m.id
                                   SET mr.is_read = 1, mr.read_at = NOW()
                                   WHERE mr.recipient_id = ? AND (m.id = ? OR m.thread_id = ?) AND mr.is_read = 0";
                    if ($stmt_update = mysqli_prepare($link, $sql_update)) {
                        mysqli_stmt_bind_param($stmt_update, "iii", $user_id, $thread_id, $thread_id);
                        mysqli_stmt_execute($stmt_update);
                        $affected_rows = mysqli_stmt_affected_rows($stmt_update);
                        mysqli_stmt_close($stmt_update);
                        echo json_encode(['success' => true, 'marked_read_count' => $affected_rows]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Database update error.']);
                    }
                } else {
                     echo json_encode(['success' => false, 'message' => 'Thread not found.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid message ID.']);
            }
            break;

        case 'delete_message':
            $recipient_id = $_POST['recipient_id'] ?? 0;
            if ($recipient_id > 0) {
                $sql_delete = "DELETE FROM message_recipients WHERE id = ? AND recipient_id = ?";
                if ($stmt_delete = mysqli_prepare($link, $sql_delete)) {
                    mysqli_stmt_bind_param($stmt_delete, "ii", $recipient_id, $user_id);
                    mysqli_stmt_execute($stmt_delete);
                    if (mysqli_stmt_affected_rows($stmt_delete) > 0) {
                        echo json_encode(['success' => true, 'message' => 'Message deleted.']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Could not delete message or permission denied.']);
                    }
                    mysqli_stmt_close($stmt_delete);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid message ID.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// --- ROLE-BASED REDIRECTION & ACCESS CONTROL ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.html");
    exit;
}
$user_role = $_SESSION["role"];
if ($user_role === 'coach') {
    header("location: coach/coach_dashboard.php");
    exit;
}
if ($user_role === 'parent') {
    header("location: parents_dashboard.php");
    exit;
}
if (!in_array($user_role, ['admin', 'member'])) {
    header("location: login.html");
    exit;
}

$user_id = $_SESSION["id"];

// --- Fetch User's Inbox Messages (Conversations) ---
$messages = [];
$thread_ids = [];
// 1. Get all unique thread IDs the user is a part of.
$sql_threads = "SELECT DISTINCT COALESCE(thread_id, id) as thread_id FROM messages m
                WHERE sender_id = ? OR EXISTS (SELECT 1 FROM message_recipients mr WHERE mr.message_id = m.id AND mr.recipient_id = ?)";
if($stmt_threads = mysqli_prepare($link, $sql_threads)){
    mysqli_stmt_bind_param($stmt_threads, "ii", $user_id, $user_id);
    mysqli_stmt_execute($stmt_threads);
    $result_threads = mysqli_stmt_get_result($stmt_threads);
    while($row = mysqli_fetch_assoc($result_threads)){
        $thread_ids[] = $row['thread_id'];
    }
    mysqli_stmt_close($stmt_threads);
}

// 2. For each thread, get the latest message and check for unread messages within that thread.
if (!empty($thread_ids)) {
    $sql_latest_msg = "
        SELECT
            m.id as message_id,
            m.subject,
            m.body,
            m.created_at,
            CONCAT(u.first_name, ' ', u.last_name) as sender_name,
            (SELECT mr.id FROM message_recipients mr WHERE mr.message_id = m.id AND mr.recipient_id = ? LIMIT 1) as recipient_id,
            (SELECT COUNT(*) FROM message_recipients mr_u JOIN messages m_u ON mr_u.message_id = m_u.id WHERE mr_u.recipient_id = ? AND mr_u.is_read = 0 AND COALESCE(m_u.thread_id, m_u.id) = ?) as unread_in_thread
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE (m.id = ? OR m.thread_id = ?)
        ORDER BY m.created_at DESC
        LIMIT 1
    ";
    if($stmt_latest = mysqli_prepare($link, $sql_latest_msg)){
        foreach($thread_ids as $tid){
            mysqli_stmt_bind_param($stmt_latest, "iiiii", $user_id, $user_id, $tid, $tid, $tid);
            mysqli_stmt_execute($stmt_latest);
            $result_latest = mysqli_stmt_get_result($stmt_latest);
            if($row = mysqli_fetch_assoc($result_latest)){
                // Ensure the user is actually part of this conversation to prevent viewing others' messages
                $sql_check_involvement = "SELECT 1 FROM messages WHERE (id=? OR thread_id=?) AND (sender_id=? OR EXISTS (SELECT 1 FROM message_recipients WHERE message_id=messages.id AND recipient_id=?)) LIMIT 1";
                $stmt_check = mysqli_prepare($link, $sql_check_involvement);
                mysqli_stmt_bind_param($stmt_check, "iiii", $tid, $tid, $user_id, $user_id);
                mysqli_stmt_execute($stmt_check);
                if(mysqli_fetch_row(mysqli_stmt_get_result($stmt_check))){
                    // If the user was the sender of the latest message, recipient_id will be null. We need to find one.
                    if(is_null($row['recipient_id'])){
                        $sql_find_recipient_id = "SELECT mr.id FROM message_recipients mr JOIN messages m ON mr.message_id = m.id WHERE mr.recipient_id = ? AND (m.id = ? OR m.thread_id = ?) LIMIT 1";
                        $stmt_find_id = mysqli_prepare($link, $sql_find_recipient_id);
                        mysqli_stmt_bind_param($stmt_find_id, "iii", $user_id, $tid, $tid);
                        mysqli_stmt_execute($stmt_find_id);
                        $row['recipient_id'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_find_id))['id'] ?? 0;
                        mysqli_stmt_close($stmt_find_id);
                    }
                    $messages[] = $row;
                }
                mysqli_stmt_close($stmt_check);
            }
        }
        mysqli_stmt_close($stmt_latest);
    }
}
// Sort messages by date descending
usort($messages, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});


// Calculate total unread count for the badge
$unread_count = 0;
$sql_unread_total = "SELECT COUNT(*) as total_unread FROM message_recipients WHERE recipient_id = ? AND is_read = 0";
if($stmt_unread = mysqli_prepare($link, $sql_unread_total)) {
    mysqli_stmt_bind_param($stmt_unread, "i", $user_id);
    mysqli_stmt_execute($stmt_unread);
    $unread_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_unread))['total_unread'] ?? 0;
    mysqli_stmt_close($stmt_unread);
}


// --- Fetch Member Details ---
$member_details_sql = "SELECT u.belt_color, u.profile_picture_url, u.member_type, m.membership_type, m.end_date, m.class_credits FROM users u LEFT JOIN memberships m ON u.id = m.user_id AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = u.id) WHERE u.id = ?";
$member_details = null;
if ($stmt_member = mysqli_prepare($link, $member_details_sql)) {
    mysqli_stmt_bind_param($stmt_member, "i", $user_id);
    if (mysqli_stmt_execute($stmt_member)) {
        $result_member = mysqli_stmt_get_result($stmt_member);
        $member_details = mysqli_fetch_assoc($result_member);
    } else {
        error_log("SQL Execution Error fetching member details: " . mysqli_error($link));
    }
    mysqli_stmt_close($stmt_member);
} else {
    error_log("SQL Prepare Error fetching member details: " . mysqli_error($link));
}

// Determine the member's age group for class filtering using users.member_type
$member_age_group = 'Adult'; // Default to 'Adult'
if ($member_details && isset($member_details['member_type'])) {
    if (stripos($member_details['member_type'], 'kid') !== false) {
        $member_age_group = 'Kid';
    }
}

// LOGIC FOR MEMBER STATS
$is_class_pass_member = false;
$show_member_stats = false;
$classes_this_week = 0;
$classes_this_month = 0;
$classes_this_year = 0;

if ($member_details && isset($member_details['membership_type'])) {
    if (strpos($member_details['membership_type'], '4 Class') !== false || strpos($member_details['membership_type'], '10 Class') !== false) {
        $is_class_pass_member = true;
    } else { // This is the block for unlimited memberships
        $show_member_stats = true;

        $now = new DateTime(); // Get the current date and time
        $start_of_week = date('Y-m-d', strtotime('monday this week'));
        $end_of_week = date('Y-m-d', strtotime('sunday this week'));

        // Query for classes completed this week (Monday to Sunday)
        $sql_week = "SELECT COUNT(b.id) as count FROM bookings b WHERE b.user_id = ? AND b.booking_date BETWEEN ? AND ? AND b.attended = 1";
        if ($stmt = mysqli_prepare($link, $sql_week)) {
            mysqli_stmt_bind_param($stmt, "iss", $user_id, $start_of_week, $end_of_week);
            mysqli_stmt_execute($stmt);
            $classes_this_week = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;
            mysqli_stmt_close($stmt);
        }

        $start_of_rolling_month = date('Y-m-d', strtotime('-30 days'));
        $today = date('Y-m-d');
        // Query for classes completed in the last 30 days
        $sql_month = "SELECT COUNT(b.id) as count FROM bookings b WHERE b.user_id = ? AND b.booking_date BETWEEN ? AND ? AND b.attended = 1";
        if ($stmt = mysqli_prepare($link, $sql_month)) {
            mysqli_stmt_bind_param($stmt, "iss", $user_id, $start_of_rolling_month, $today);
            mysqli_stmt_execute($stmt);
            $classes_this_month = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;
            mysqli_stmt_close($stmt);
        }

        $start_of_year = date('Y-01-01');
        // Query for classes completed this year
        $sql_year = "SELECT COUNT(b.id) as count FROM bookings b WHERE b.user_id = ? AND b.booking_date >= ? AND b.attended = 1";
        if ($stmt = mysqli_prepare($link, $sql_year)) {
            mysqli_stmt_bind_param($stmt, "is", $user_id, $start_of_year);
            mysqli_stmt_execute($stmt);
            $classes_this_year = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['count'] ?? 0;
            mysqli_stmt_close($stmt);
        }
    }
}

// Corrected the logic for checking membership validity
$is_membership_valid = false;
if (isset($member_details['end_date']) && strtotime($member_details['end_date']) >= strtotime(date('Y-m-d'))) {
    if (isset($member_details['membership_type']) && strpos($member_details['membership_type'], 'Class') !== false) {
        if (isset($member_details['class_credits']) && $member_details['class_credits'] > 0) {
            $is_membership_valid = true;
        }
    } else {
        $is_membership_valid = true;
    }
}

// Initialize upcoming dates for a fixed calendar week (Mon-Sun)
$upcoming_dates = [];
$start_of_week_dt = new DateTime('monday this week');
for ($i = 0; $i < 7; $i++) {
    $date = (clone $start_of_week_dt)->modify('+' . $i . ' days');
    $day_name = $date->format('l');
    $upcoming_dates[$day_name] = $date->format('Y-m-d');
}

// --- Fetch Class Schedule for Booking Section ---
$schedule_sql = "SELECT c.id AS class_id, c.name, c.name_zh, c.day_of_week, c.start_time, c.end_time, c.capacity, u.first_name AS coach_first_name, u.last_name AS coach_last_name, b_user.id AS user_booking_id, w_user.id AS user_waitlist_id, (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND booking_date = ?) AS total_bookings_count FROM classes c LEFT JOIN users u ON c.coach_id = u.id LEFT JOIN bookings b_user ON c.id = b_user.class_id AND b_user.user_id = ? AND b_user.booking_date = ? LEFT JOIN waitlist w_user ON c.id = w_user.class_id AND w_user.user_id = ? AND w_user.booking_date = ? WHERE c.is_active = 1 AND c.age = ? AND c.day_of_week = ? ORDER BY c.start_time";

$schedule = ['Monday' => [], 'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [], 'Saturday' => [], 'Sunday' => []];

foreach ($schedule as $day => &$classes_for_day) {
    $booking_date_for_day = $upcoming_dates[$day];
    if ($stmt_schedule = mysqli_prepare($link, $schedule_sql)) {
        mysqli_stmt_bind_param($stmt_schedule, "sississ", $booking_date_for_day, $user_id, $booking_date_for_day, $user_id, $booking_date_for_day, $member_age_group, $day);
        if (mysqli_stmt_execute($stmt_schedule)) {
            $result = mysqli_stmt_get_result($stmt_schedule);
            while ($row = mysqli_fetch_assoc($result)) {
                $row['coach_name'] = trim($row['coach_first_name'] . ' ' . $row['coach_last_name']);
                $classes_for_day[] = $row;
            }
        } else {
            error_log("SQL Execution Error fetching schedule for {$day}: " . mysqli_error($link));
        }
        mysqli_stmt_close($stmt_schedule);
    } else {
        error_log("SQL Prepare Error fetching schedule for {$day}: " . mysqli_error($link));
    }
}
unset($classes_for_day);

// --- Fetch Billing History for the new section ---
$billing_history = [];
$billing_sql = "SELECT membership_type, end_date, class_credits FROM memberships WHERE user_id = ? ORDER BY end_date DESC";
if ($stmt_billing = mysqli_prepare($link, $billing_sql)) {
    mysqli_stmt_bind_param($stmt_billing, "i", $user_id);
    if (mysqli_stmt_execute($stmt_billing)) {
        $result_billing = mysqli_stmt_get_result($stmt_billing);
        $billing_history = mysqli_fetch_all($result_billing, MYSQLI_ASSOC);
    } else {
        error_log("SQL Error fetching billing history: " . mysqli_error($link));
    }
    mysqli_stmt_close($stmt_billing);
} else {
    error_log("SQL Prepare Error fetching billing history: " . mysqli_error($link));
}


$days_zh = ['Monday' => '星期一', 'Tuesday' => '星期二', 'Wednesday' => '星期三', 'Thursday' => '星期四', 'Friday' => '星期五', 'Saturday' => '星期六', 'Sunday' => '星期日'];
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];


mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Member Dashboard - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        .tab-active { background-color: #3b82f6; color: white; border-color: #3b82f6; }
        #cropper-container { width: 100%; height: 400px; }
        .timetable-container::-webkit-scrollbar { width: 8px; height: 8px; }
        .timetable-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .timetable-container::-webkit-scrollbar-thumb { background: #888; border-radius: 10px; }
        .timetable-container::-webkit-scrollbar-thumb:hover { background: #555; }
        .profile-header-container { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
        .profile-pic-wrapper { position: relative; flex-shrink: 0; }
        .edit-pic-btn { position: absolute; bottom: 0; right: 0; }
        .welcome-heading { flex-grow: 1; word-break: break-word; }
        .class-item { cursor: pointer; }
        .class-item.bg-blue-100 { border-left: 4px solid #3b82f6; }
        .class-item.bg-gray-100 { border-left: 4px solid #d1d5db; }
        @media (max-width: 767px) {
            #billing-table thead { display: none; }
            #billing-table tbody, #billing-table tr, #billing-table td { display: block; width: 100%; }
            #billing-table tr { margin-bottom: 1rem; border: 1px solid #ddd; border-radius: 0.5rem; padding: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
            #billing-table td { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
            #billing-table td:last-child { border-bottom: none; }
            #billing-table td::before { content: attr(data-label); font-weight: bold; padding-right: 1rem; }
        }
        .mobile-menu-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 40; display: none; }
        .mobile-menu { position: fixed; top: 0; right: 0; width: 70%; height: 100%; background-color: white; z-index: 50; transform: translateX(100%); transition: transform 0.3s ease-out; padding: 1.5rem; box-shadow: -2px 0 5px rgba(0,0,0,0.2); display: flex; flex-direction: column; align-items: flex-start; }
        .mobile-menu.open { transform: translateX(0); }
        .mobile-menu-item { width: 100%; padding: 0.75rem 0; border-bottom: 1px solid #eee; text-align: left; }
        .mobile-menu-item:last-child { border-bottom: none; }
        .payment-btn-selected { background-color: #2563eb; color: white; }
        .post-content img { max-width: 100%; height: auto; margin: 16px 0; border-radius: 8px; }
        .post-content h1, .post-content h2, .post-content h3 { font-weight: bold; margin-bottom: 8px; }
        .prose img { max-width: 100%; height: auto; border-radius: 0.5rem; }
        /* Add styles for the new message thread view */
        #message-viewer-content { display: flex; flex-direction: column; height: 100%; }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <a href="#" class="flex items-center space-x-2">
                <img src="logo.png" alt="Catch Jiu Jitsu Logo" class="h-12 w-12" onerror="this.onerror=null;this.src='https://placehold.co/48x48/e0e0e0/333333?text=Logo';">
                <span class="font-bold text-xl text-gray-800 hidden sm:block lang" data-lang-en="Catch Jiu Jitsu Portal" data-lang-zh="Catch 柔術入口網站">Catch Jiu Jitsu Portal</span>
            </a>
            <div class="flex items-center space-x-4">
                <div class="hidden sm:flex items-center space-x-4">
                    <a href="technique_of_the_week.php" class="text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="Instructional" data-lang-zh="教學">Instructional</a>
                    <a href="news.php" class="text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="News" data-lang-zh="最新消息">News</a>
                    <a href="events.php" class="text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="Events" data-lang-zh="活動">Events</a>
                    <button id="inbox-btn" class="relative text-gray-700 hover:text-blue-600 font-medium">
                        <span class="lang" data-lang-en="Inbox" data-lang-zh="收件匣">Inbox</span>
                        <?php if ($unread_count > 0): ?>
                        <span class="absolute -top-2 -right-3 flex h-5 w-5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span id="unread-badge" class="relative inline-flex rounded-full h-5 w-5 bg-red-500 text-white text-xs justify-center items-center"><?php echo $unread_count; ?></span>
                        </span>
                        <?php endif; ?>
                    </button>
                    <?php if (isset($_SESSION["role"]) && ($_SESSION["role"] === 'admin')): ?>
                        <a href="admin/admin_dashboard.php" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="Admin Dashboard" data-lang-zh="管理員儀表板">Admin Dashboard</a>
                    <?php endif; ?>
                    <a href="logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Logout" data-lang-zh="登出">Logout</a>
                    <div id="lang-switcher-desktop" class="pl-4 flex items-center space-x-2 text-sm border-l border-gray-300">
                        <button id="lang-en-desktop" class="font-bold text-blue-600">EN</button>
                        <span class="text-gray-300">|</span>
                        <button id="lang-zh-desktop" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                    </div>
                </div>
                <button id="mobile-menu-button" class="sm:hidden p-2 rounded-md text-gray-500 hover:text-gray-800 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                </button>
            </div>
        </div>
    </nav>
    <div id="mobile-menu-overlay" class="mobile-menu-overlay hidden"></div>
    <div id="mobile-menu" class="mobile-menu hidden">
        <button id="close-mobile-menu" class="self-end text-gray-500 hover:text-gray-800 text-3xl mb-4">&times;</button>
        <a href="technique_of_the_week.php" class="mobile-menu-item text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="Instructional" data-lang-zh="教學">Instructional</a>
        <a href="news.php" class="mobile-menu-item text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="News" data-lang-zh="最新消息">News</a>
        <a href="events.php" class="mobile-menu-item text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="Events" data-lang-zh="活動">Events</a>
        <button id="inbox-btn-mobile" class="mobile-menu-item relative text-gray-700 hover:text-blue-600 font-medium w-full text-left">
            <span class="lang" data-lang-en="Inbox" data-lang-zh="收件匣">Inbox</span>
            <?php if ($unread_count > 0): ?>
            <span class="absolute top-1/2 -translate-y-1/2 right-4 flex h-5 w-5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span id="unread-badge-mobile" class="relative inline-flex rounded-full h-5 w-5 bg-red-500 text-white text-xs justify-center items-center"><?php echo $unread_count; ?></span>
            </span>
            <?php endif; ?>
        </button>
        <?php if (isset($_SESSION["role"]) && ($_SESSION["role"] === 'admin')): ?>
            <a href="admin/admin_dashboard.php" class="mobile-menu-item bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="Admin Dashboard" data-lang-zh="管理員儀表板">Admin Dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="mobile-menu-item bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Logout" data-lang-zh="登出">Logout</a>
        <div id="lang-switcher-mobile" class="mobile-menu-item flex items-center space-x-2 text-sm">
            <button id="lang-en-mobile" class="font-bold text-blue-600">EN</button>
            <span class="text-gray-300">|</span>
            <button id="lang-zh-mobile" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
        </div>
    </div>
    <div class="container mx-auto px-4 sm:px-6 py-12">
        <div id="main-dashboard-content">
            <div class="profile-header-container">
                <div class="profile-pic-wrapper">
                    <img id="profile-pic-display" src="<?php echo !empty($member_details['profile_picture_url']) ? htmlspecialchars($member_details['profile_picture_url']) : 'https://placehold.co/100x100/e2e8f0/333333?text=Pic'; ?>" alt="Profile Picture" class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-md profile-pic-display">
                    <button id="edit-pic-btn" class="absolute bottom-0 right: 0; bg-blue-600 text-white rounded-full p-1.5 hover:bg-blue-700 shadow-md edit-pic-btn">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                    </button>
                </div>
                <h1 class="text-4xl font-black text-gray-800 welcome-heading">
                    <span class="lang" data-lang-en="Welcome, " data-lang-zh="歡迎, ">Welcome, </span><?php echo htmlspecialchars($_SESSION["full_name"]); ?>!
                </h1>
            </div>

            <div class="mt-8">
                <!-- Mobile-specific layout for action buttons -->
                <div class="md:hidden">
                    <div class="grid grid-cols-2 gap-4">
                        <a href="book_1to1.php" class="bg-indigo-600 text-white font-bold py-3 px-2 rounded-lg hover:bg-indigo-700 transition text-center text-sm">
                            <span class="lang" data-lang-en="1 to 1" data-lang-zh="一對一">1 to 1</span>
                        </a>
                        <a href="member_check_in.php" class="bg-green-600 text-white font-bold py-3 px-2 rounded-lg hover:bg-green-700 transition text-center text-sm">
                            <span class="lang" data-lang-en="Check In" data-lang-zh="簽到">Check In</span>
                        </a>
                        <a href="training_log.php" class="bg-blue-600 text-white font-bold py-3 px-2 rounded-lg hover:bg-blue-700 transition text-center text-sm">
                            <span class="lang" data-lang-en="Log" data-lang-zh="日誌">Log</span>
                        </a>
                        <button id="view-billing-btn" class="bg-gray-200 text-gray-800 font-bold py-3 px-2 rounded-lg hover:bg-gray-300 transition text-center text-sm">
                            <span class="lang" data-lang-en="Billing" data-lang-zh="帳務">Billing</span>
                        </button>
                    </div>
                </div>

                <!-- Desktop/Tablet layout for action buttons -->
                <div class="hidden md:flex justify-between items-center mb-4">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="Your Dashboard" data-lang-zh="您的儀表板">Your Dashboard</h2>
                    <div class="flex flex-wrap gap-2">
                        <a href="book_1to1.php" class="bg-indigo-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-indigo-700 transition text-sm">
                            <span class="lang" data-lang-en="Book a 1-to-1 Class" data-lang-zh="預約一對一課程">Book a 1-to-1 Class</span>
                        </a>
                        <a href="member_check_in.php" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700 transition text-sm">
                            <span class="lang" data-lang-en="Today's Check-In" data-lang-zh="今日簽到">Today's Check-In</span>
                        </a>
                        <a href="training_log.php" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition text-sm">
                            <span class="lang" data-lang-en="Training Log" data-lang-zh="訓練日誌">Training Log</span>
                        </a>
                        <button id="view-billing-btn-desktop" class="bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition text-sm">
                            <span class="lang" data-lang-en="View Billing" data-lang-zh="查看帳務">View Billing</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-white p-4 rounded-xl shadow-md text-center">
                        <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Belt Level" data-lang-zh="腰帶等級">Belt Level</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo htmlspecialchars($member_details['belt_color'] ?? 'N/A'); ?></p>
                    </div>
                    <?php if ($is_class_pass_member): ?>
                        <div class="bg-white p-4 rounded-xl shadow-md text-center col-span-1 md:col-span-1">
                            <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Remaining Classes" data-lang-zh="剩餘課程">Remaining Classes</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $member_details['class_credits'] ?? 0; ?></p>
                        </div>
                    <?php else: ?>
                        <div class="bg-white p-4 rounded-xl shadow-md text-center">
                            <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Classes This Week" data-lang-zh="本週課程">Classes This Week</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $classes_this_week; ?></p>
                        </div>
                        <div class="bg-white p-4 rounded-xl shadow-md text-center">
                            <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Classes Last 30 Days" data-lang-zh="過去30天課程">Classes Last 30 Days</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $classes_this_month; ?></p>
                        </div>
                        <div class="bg-white p-4 rounded-xl shadow-md text-center">
                            <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Classes This Year" data-lang-zh="今年課程">Classes This Year</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $classes_this_year; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="booking-section" class="mt-12">
                <h2 class="text-3xl font-bold text-gray-800 border-b-4 border-blue-500 pb-2 inline-block lang" data-lang-en="Weekly Timetable" data-lang-zh="每週時間表">Weekly Timetable</h2>
                <!-- Mobile Timetable -->
                <div class="md:hidden mt-4">
                    <div class="border-b border-gray-200">
                        <nav id="mobile-tabs" class="-mb-px flex space-x-2 overflow-x-auto" aria-label="Tabs">
                            <?php foreach ($schedule as $day => $classes): ?>
                                <button class="day-tab whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm" data-day="<?php echo $day; ?>">
                                    <span class="lang" data-lang-en="<?php echo $day; ?>" data-lang-zh="<?php echo $days_zh[$day]; ?>"><?php echo $day; ?></span>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <div class="mt-4">
                        <?php foreach ($schedule as $day => $classes): ?>
                            <div id="day-panel-<?php echo $day; ?>" class="day-panel hidden">
                                <div class="space-y-3">
                                    <?php if (empty($classes)): ?>
                                        <p class="text-center text-gray-500 text-sm p-4 lang" data-lang-en="No classes scheduled." data-lang-zh="沒有排定的課程。">No classes scheduled.</p>
                                    <?php else: ?>
                                        <?php foreach ($classes as $class): ?>
                                            <?php
                                            $is_full = $class['total_bookings_count'] >= $class['capacity'];
                                            $is_on_waitlist = !empty($class['user_waitlist_id']);
                                            ?>
                                            <div class="bg-gray-50 p-3 rounded-lg text-sm shadow-md border-l-4 <?php echo $class['user_booking_id'] ? 'border-green-500' : ($is_on_waitlist ? 'border-yellow-500' : 'border-blue-500'); ?>">
                                                <p class="font-bold text-gray-800 lang" data-lang-en="<?php echo htmlspecialchars($class['name']); ?>" data-lang-zh="<?php echo htmlspecialchars($class['name_zh']); ?>"><?php echo htmlspecialchars($class['name']); ?></p>
                                                <p class="text-gray-700"><?php echo date("g:i A", strtotime($class['start_time'])) . " - " . date("g:i A", strtotime($class['end_time'])); ?></p>
                                                <?php if (!empty($class['coach_name'])): ?>
                                                    <p class="text-gray-600 text-xs mt-1"><span class="lang" data-lang-en="Coach" data-lang-zh="教練">Coach</span>: <?php echo htmlspecialchars($class['coach_name']); ?></p>
                                                <?php endif; ?>
                                                <p class="text-xs mt-1 font-bold"><span class="lang" data-lang-en="Bookings: " data-lang-zh="預約數：">Bookings: </span><?php echo htmlspecialchars($class['total_bookings_count']); ?> / <?php echo htmlspecialchars($class['capacity']); ?></p>
                                                <div class="mt-3">
                                                    <?php if ($class['user_booking_id']): ?>
                                                        <button class="w-full bg-red-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-red-600 transition cancel-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Cancel" data-lang-zh="取消預約">Cancel</span>
                                                        </button>
                                                    <?php elseif ($is_on_waitlist): ?>
                                                        <button class="w-full bg-yellow-500 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed" disabled>
                                                            <span class="lang" data-lang-en="On Waitlist" data-lang-zh="候補中">On Waitlist</span>
                                                        </button>
                                                    <?php elseif ($is_full): ?>
                                                        <button class="w-full bg-gray-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-gray-600 transition waitlist-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Join Waitlist" data-lang-zh="加入候補名單">Join Waitlist</span>
                                                        </button>
                                                    <?php elseif ($is_membership_valid): ?>
                                                        <button class="w-full bg-blue-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-blue-600 transition book-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Book Class" data-lang-zh="預約課程">Book Class</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="w-full bg-gray-400 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed" disabled>
                                                            <span class="lang" data-lang-en="Renew Membership" data-lang-zh="續訂會員">Renew Membership</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <!-- Desktop Timetable -->
                <div class="mt-6 hidden md:block timetable-container overflow-x-auto">
                    <div class="grid grid-cols-7 gap-2">
                        <?php foreach ($schedule as $day => $classes): ?>
                            <div class="p-2 bg-gray-50 rounded-lg">
                                <div class="font-bold text-center text-lg p-2 border-b-2 border-gray-200">
                                    <span class="lang" data-lang-en="<?php echo $day; ?>" data-lang-zh="<?php echo $days_zh[$day]; ?>"><?php echo $day; ?></span>
                                    <span class="block text-xs text-gray-500"><?php echo date("m/d", strtotime($upcoming_dates[$day])); ?></span>
                                </div>
                                <div class="space-y-2 mt-2">
                                    <?php if (empty($classes)): ?>
                                        <p class="text-center text-gray-500 text-sm p-4 lang" data-lang-en="No classes scheduled." data-lang-zh="沒有排定的課程。">No classes scheduled.</p>
                                    <?php else: ?>
                                        <?php foreach ($classes as $class): ?>
                                            <?php
                                            $is_full = $class['total_bookings_count'] >= $class['capacity'];
                                            $is_on_waitlist = !empty($class['user_waitlist_id']);
                                            ?>
                                            <div class="bg-white p-3 rounded-lg text-sm shadow-md border-l-4 <?php echo $class['user_booking_id'] ? 'border-green-500' : ($is_on_waitlist ? 'border-yellow-500' : 'border-blue-500'); ?>">
                                                <p class="font-bold text-gray-800 lang" data-lang-en="<?php echo htmlspecialchars($class['name']); ?>" data-lang-zh="<?php echo htmlspecialchars($class['name_zh']); ?>"><?php echo htmlspecialchars($class['name']); ?></p>
                                                <p class="text-gray-700"><?php echo date("g:i A", strtotime($class['start_time'])) . " - " . date("g:i A", strtotime($class['end_time'])); ?></p>
                                                <?php if (!empty($class['coach_name'])): ?>
                                                    <p class="text-gray-600 text-xs mt-1"><span class="lang" data-lang-en="Coach" data-lang-zh="教練">Coach</span>: <?php echo htmlspecialchars($class['coach_name']); ?></p>
                                                <?php endif; ?>
                                                <p class="text-xs mt-1 font-bold"><span class="lang" data-lang-en="Bookings: " data-lang-zh="預約數：">Bookings: </span><?php echo htmlspecialchars($class['total_bookings_count']); ?> / <?php echo htmlspecialchars($class['capacity']); ?></p>
                                                <div class="mt-3">
                                                    <?php if ($class['user_booking_id']): ?>
                                                        <button class="w-full bg-red-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-red-600 transition cancel-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Cancel" data-lang-zh="取消預約">Cancel</span>
                                                        </button>
                                                    <?php elseif ($is_on_waitlist): ?>
                                                        <button class="w-full bg-yellow-500 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed" disabled>
                                                            <span class="lang" data-lang-en="On Waitlist" data-lang-zh="候補中">On Waitlist</span>
                                                        </button>
                                                    <?php elseif ($is_full): ?>
                                                        <button class="w-full bg-gray-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-gray-600 transition waitlist-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Join Waitlist" data-lang-zh="加入候補名單">Join Waitlist</span>
                                                        </button>
                                                    <?php elseif ($is_membership_valid): ?>
                                                        <button class="w-full bg-blue-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-blue-600 transition book-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Book Class" data-lang-zh="預約課程">Book Class</span>
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="w-full bg-gray-400 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed" disabled>
                                                            <span class="lang" data-lang-en="Renew Membership" data-lang-zh="續訂會員">Renew Membership</span>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Billing/Membership Section -->
        <div id="billing-view-section" class="mt-12 hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-3xl font-bold text-gray-800 lang" data-lang-en="Membership & Billing" data-lang-zh="會員與帳務">Membership & Billing</h2>
                <button id="back-to-dashboard-btn" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <span class="lang" data-lang-en="Back to Dashboard" data-lang-zh="返回儀表板">Back to Dashboard</span>
                </button>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-lg">
                <div class="flex justify-end mb-4">
                    <button id="made-payment-btn" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition">
                        <span class="lang" data-lang-en="Made a Payment" data-lang-zh="回報付款">Made a Payment</span>
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table id="billing-table" class="min-w-full bg-white">
                        <thead>
                            <tr>
                                <th class="text-left py-3 px-4 uppercase font-semibold text-sm lang" data-lang-en="Membership" data-lang-zh="會員資格">Membership</th>
                                <th class="text-left py-3 px-4 uppercase font-semibold text-sm lang" data-lang-en="Expiry Date" data-lang-zh="到期日">Expiry Date</th>
                                <th class="text-left py-3 px-4 uppercase font-semibold text-sm lang" data-lang-en="Status" data-lang-zh="狀態">Status</th>
                                <th class="text-left py-3 px-4 uppercase font-semibold text-sm lang" data-lang-en="Classes Remaining" data-lang-zh="剩餘課程">Classes Remaining</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-700">
                            <?php if (empty($billing_history)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 lang" data-lang-en="No membership history found." data-lang-zh="找不到會員歷史記錄。">No membership history found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($billing_history as $item): ?>
                                    <?php
                                    $is_class_pass = (stripos($item['membership_type'], '4 Class') !== false || stripos($item['membership_type'], '10 Class') !== false);
                                    $expiry_timestamp = strtotime($item['end_date']);
                                    $today_timestamp = strtotime(date('Y-m-d'));
                                    $status = ($expiry_timestamp >= $today_timestamp) ? 'Active' : 'Expired';
                                    $status_zh = ($expiry_timestamp >= $today_timestamp) ? '有效' : '已過期';
                                    $status_class = ($expiry_timestamp >= $today_timestamp) ? 'text-green-600' : 'text-red-600';
                                    ?>
                                    <tr>
                                        <td data-label="Membership"><?php echo htmlspecialchars($item['membership_type']); ?></td>
                                        <td data-label="Expiry Date"><?php echo date("m/d/Y", $expiry_timestamp); ?></td>
                                        <td data-label="Status" class="font-bold <?php echo $status_class; ?>">
                                            <span class="lang" data-lang-en="<?php echo $status; ?>" data-lang-zh="<?php echo $status_zh; ?>"><?php echo $status; ?></span>
                                        </td>
                                        <td data-label="Classes Remaining">
                                            <?php if ($is_class_pass): ?>
                                                <?php echo htmlspecialchars($item['class_credits']); ?>
                                            <?php else: ?>
                                                <span class="text-gray-400">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- NEW: Inbox Section -->
        <div id="inbox-section" class="mt-8 hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-3xl font-bold text-gray-800 lang" data-lang-en="Inbox" data-lang-zh="收件匣">Inbox</h2>
                <button id="back-to-dashboard-btn-inbox" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <span class="lang" data-lang-en="&larr; Back to Dashboard" data-lang-zh="&larr; 返回儀表板">&larr; Back to Dashboard</span>
                </button>
            </div>
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="flex h-[70vh]">
                    <!-- Message List -->
                    <div id="message-list-container" class="w-full md:w-1/3 border-r border-gray-200 flex flex-col">
                        <div id="message-list" class="overflow-y-auto flex-grow">
                            <?php if (empty($messages)): ?>
                                <p class="p-4 text-center text-gray-500 lang" data-lang-en="Your inbox is empty." data-lang-zh="您的收件匣是空的。">Your inbox is empty.</p>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <div class="message-item p-4 border-b hover:bg-gray-50 cursor-pointer <?php echo $message['unread_in_thread'] > 0 ? 'bg-blue-50' : ''; ?>"
                                         data-recipient-id="<?php echo $message['recipient_id']; ?>"
                                         data-has-unread="<?php echo $message['unread_in_thread'] > 0 ? '1' : '0'; ?>">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-grow overflow-hidden">
                                                <p class="text-sm <?php echo $message['unread_in_thread'] > 0 ? 'font-bold text-gray-900' : 'font-medium text-gray-600'; ?>"><?php echo htmlspecialchars($message['sender_name']); ?></p>
                                                <p class="truncate <?php echo $message['unread_in_thread'] > 0 ? 'font-semibold text-gray-800' : 'text-gray-500'; ?>"><?php echo htmlspecialchars($message['subject']); ?></p>
                                            </div>
                                            <span class="text-xs text-gray-400 flex-shrink-0 ml-2"><?php echo date('M d', strtotime($message['created_at'])); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Message Viewer -->
                    <div id="message-viewer" class="w-full md:w-2/3 hidden md:flex flex-col">
                        <!-- Viewer Header (for mobile back button) -->
                        <div id="viewer-header" class="p-4 border-b md:hidden flex items-center gap-4">
                             <button id="back-to-list-btn" class="p-2 text-gray-600 hover:bg-gray-100 rounded-full">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                             </button>
                             <h3 class="font-bold text-lg lang" data-lang-en="Conversation" data-lang-zh="對話">Conversation</h3>
                        </div>
                        <div id="message-viewer-placeholder" class="flex-grow flex items-center justify-center">
                            <p class="text-gray-400 lang" data-lang-en="Select a message to read" data-lang-zh="選擇一則訊息閱讀">Select a message to read</p>
                        </div>
                        <div id="message-viewer-content" class="hidden h-full">
                           <!-- Thread content will be injected here by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals (Payment, Status) -->
    <div id="payment-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <p class="text-2xl font-bold lang" data-lang-en="Submit Payment" data-lang-zh="提交付款">Submit Payment</p>
                <button id="close-payment-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            <div class="mt-4">
                <form id="payment-form">
                    <div>
                        <label class="font-bold lang" data-lang-en="Payment Method" data-lang-zh="付款方式">Payment Method</label>
                        <div class="flex space-x-2 mt-2" id="payment-method-btns">
                            <button type="button" data-value="Line Pay" class="payment-btn flex-1 bg-gray-200 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition">Line Pay</button>
                            <button type="button" data-value="Cash" class="payment-btn flex-1 bg-gray-200 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition lang" data-lang-en="Cash" data-lang-zh="現金">Cash</button>
                            <button type="button" data-value="Bank Transfer" class="payment-btn flex-1 bg-gray-200 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition lang" data-lang-en="Bank Transfer" data-lang-zh="銀行轉帳">Bank Transfer</button>
                        </div>
                        <input type="hidden" name="payment_method" id="payment_method_input">
                    </div>
                    <div class="mt-4">
                        <label for="amount" class="font-bold lang" data-lang-en="Amount Paid" data-lang-zh="付款金額">Amount Paid</label>
                        <input type="number" name="amount" id="amount" class="w-full p-2 border rounded mt-2" required>
                    </div>
                    <div id="last-five-container" class="mt-4 hidden">
                        <label for="last_five" class="font-bold lang" data-lang-en="Last 5 Digits of Transaction" data-lang-zh="交易末5碼">Last 5 Digits of Transaction</label>
                        <input type="text" name="last_five" id="last_five" class="w-full p-2 border rounded mt-2" maxlength="5">
                    </div>
                    <div class="mt-6 text-right">
                        <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="Submit" data-lang-zh="提交">Submit</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="status-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div id="status-modal-content" class="text-center py-4">
            </div>
            <div class="text-center">
                <button id="close-status-modal" class="bg-blue-500 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-600 transition lang" data-lang-en="OK" data-lang-zh="好的">OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Element Selectors ---
        const statusModal = document.getElementById('status-modal');
        const closeStatusModalBtn = document.getElementById('close-status-modal');
        const bookingSection = document.getElementById('booking-section');
        const viewBillingBtn = document.getElementById('view-billing-btn');
        const viewBillingBtnDesktop = document.getElementById('view-billing-btn-desktop');
        const billingViewSection = document.getElementById('billing-view-section');
        const mainDashboardContent = document.getElementById('main-dashboard-content');
        const backToDashboardBtn = document.getElementById('back-to-dashboard-btn');
        const madePaymentBtn = document.getElementById('made-payment-btn');
        const paymentModal = document.getElementById('payment-modal');
        const closePaymentModalBtn = document.getElementById('close-payment-modal');
        const paymentForm = document.getElementById('payment-form');
        const paymentMethodBtns = document.getElementById('payment-method-btns');
        const paymentMethodInput = document.getElementById('payment_method_input');
        const lastFiveContainer = document.getElementById('last-five-container');
        const inboxBtn = document.getElementById('inbox-btn');
        const inboxBtnMobile = document.getElementById('inbox-btn-mobile');
        const inboxSection = document.getElementById('inbox-section');
        const backToDashboardBtnInbox = document.getElementById('back-to-dashboard-btn-inbox');
        const messageListContainer = document.getElementById('message-list-container');
        const messageList = document.getElementById('message-list');
        const messageViewer = document.getElementById('message-viewer');
        const messageViewerPlaceholder = document.getElementById('message-viewer-placeholder');
        const messageViewerContent = document.getElementById('message-viewer-content');
        const backToListBtn = document.getElementById('back-to-list-btn');
        const unreadBadge = document.getElementById('unread-badge');
        const unreadBadgeMobile = document.getElementById('unread-badge-mobile');
        const currentUserId = <?php echo $_SESSION['id']; ?>;

        // --- LANGUAGE SWITCHER LOGIC ---
        let currentLang = localStorage.getItem('preferredLang') || 'en';

        function updateLanguage() {
            const langElements = document.querySelectorAll('.lang');
            langElements.forEach(el => {
                const text = el.getAttribute('data-lang-' + currentLang);
                if (text) {
                    el.textContent = text;
                }
            });

            const allEnBtns = document.querySelectorAll('#lang-en-desktop, #lang-en-mobile');
            const allZhBtns = document.querySelectorAll('#lang-zh-desktop, #lang-zh-mobile');

            allEnBtns.forEach(btn => {
                btn.classList.toggle('font-bold', currentLang === 'en');
                btn.classList.toggle('text-blue-600', currentLang === 'en');
                btn.classList.toggle('text-gray-500', currentLang !== 'en');
                btn.classList.toggle('font-normal', currentLang !== 'en');
            });

            allZhBtns.forEach(btn => {
                btn.classList.toggle('font-bold', currentLang === 'zh');
                btn.classList.toggle('text-blue-600', currentLang === 'zh');
                btn.classList.toggle('text-gray-500', currentLang !== 'zh');
                btn.classList.toggle('font-normal', currentLang !== 'zh');
            });
        }

        function setLanguage(lang) {
            currentLang = lang;
            localStorage.setItem('preferredLang', lang);
            updateLanguage();
        }

        document.querySelectorAll('#lang-en-desktop, #lang-en-mobile').forEach(btn => btn.addEventListener('click', () => setLanguage('en')));
        document.querySelectorAll('#lang-zh-desktop, #lang-zh-mobile').forEach(btn => btn.addEventListener('click', () => setLanguage('zh')));

        // --- UI Toggling for Different Views ---
        if (viewBillingBtn) {
            viewBillingBtn.addEventListener('click', () => {
                mainDashboardContent.classList.add('hidden');
                billingViewSection.classList.remove('hidden');
                updateLanguage();
            });
        }
        if (viewBillingBtnDesktop) {
             viewBillingBtnDesktop.addEventListener('click', () => {
                mainDashboardContent.classList.add('hidden');
                billingViewSection.classList.remove('hidden');
                updateLanguage();
            });
        }

        if (backToDashboardBtn) {
            backToDashboardBtn.addEventListener('click', () => {
                billingViewSection.classList.add('hidden');
                mainDashboardContent.classList.remove('hidden');
            });
        }

        // --- Payment Modal Logic ---
        if (madePaymentBtn) {
            madePaymentBtn.addEventListener('click', () => {
                paymentModal.classList.remove('hidden');
            });
        }
        if (closePaymentModalBtn) {
            closePaymentModalBtn.addEventListener('click', () => {
                paymentModal.classList.add('hidden');
            });
        }
        if (paymentMethodBtns) {
            paymentMethodBtns.addEventListener('click', (e) => {
                if (e.target.tagName === 'BUTTON') {
                    const value = e.target.dataset.value;
                    paymentMethodInput.value = value;

                    document.querySelectorAll('.payment-btn').forEach(btn => btn.classList.remove('payment-btn-selected'));
                    e.target.classList.add('payment-btn-selected');

                    if (value === 'Bank Transfer') {
                        lastFiveContainer.classList.remove('hidden');
                        document.getElementById('last_five').required = true;
                    } else {
                        lastFiveContainer.classList.add('hidden');
                        document.getElementById('last_five').required = false;
                    }
                }
            });
        }
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!paymentMethodInput.value) {
                    showStatus('Please select a payment method.', false);
                    return;
                }
                const formData = new FormData(this);
                formData.append('action', 'submit_payment');

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    paymentModal.classList.add('hidden');
                    showStatus(data.message, data.success);
                    if (data.success) {
                        paymentForm.reset();
                        document.querySelectorAll('.payment-btn').forEach(btn => btn.classList.remove('payment-btn-selected'));
                        lastFiveContainer.classList.add('hidden');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    paymentModal.classList.add('hidden');
                    showStatus('A network error occurred. Please try again.', false);
                });
            });
        }


        // --- MODAL & NOTIFICATION LOGIC ---
        function showStatus(message, isSuccess) {
            const content = document.getElementById('status-modal-content');
            content.innerHTML = `<p class="text-lg font-medium ${isSuccess ? 'text-green-600' : 'text-red-600'}">${message}</p>`;
            statusModal.classList.remove('hidden');
        }

        closeStatusModalBtn.addEventListener('click', () => {
            statusModal.classList.add('hidden');
        });

        // --- BOOKING/CANCELLATION/WAITLIST LOGIC ---
        if (bookingSection) {
            bookingSection.addEventListener('click', function(e) {
                const target = e.target.closest('.book-btn, .cancel-btn, .waitlist-btn');
                if (!target) return;

                const isBooking = target.classList.contains('book-btn');
                const isWaitlist = target.classList.contains('waitlist-btn');
                const action = isBooking ? 'book_class' : (isWaitlist ? 'join_waitlist' : 'cancel_class');
                const classId = target.dataset.classId;
                const bookingDate = target.dataset.bookingDate;

                const enMsg = isBooking ? 'Are you sure you want to book this class?' : (isWaitlist ? 'Are you sure you want to join the waitlist for this class?' : 'Are you sure you want to cancel this booking?');
                const zhMsg = isBooking ? '您確定要預約這堂課嗎？' : (isWaitlist ? '您確定要加入此課程的候補名單嗎？' : '您確定要取消這個預約嗎？');
                const confirmationMessage = currentLang === 'zh' ? zhMsg : enMsg;

                if (window.confirm(confirmationMessage)) {
                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('class_id', classId);
                    formData.append('booking_date', bookingDate);

                    fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        showStatus(data.message, data.success);
                        if (data.success) {
                            setTimeout(() => {
                                window.location.reload();
                            }, 2000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showStatus('A network error occurred. Please try again.', false);
                    });
                }
            });
        }

        // --- MOBILE MENU ---
        const mobileMenuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const mobileMenuOverlay = document.getElementById('mobile-menu-overlay');
        const closeMobileMenuButton = document.getElementById('close-mobile-menu');

        function toggleMenu() {
            mobileMenu.classList.toggle('open');
            mobileMenu.classList.toggle('hidden');
            mobileMenuOverlay.classList.toggle('hidden');
        }

        mobileMenuButton.addEventListener('click', toggleMenu);
        closeMobileMenuButton.addEventListener('click', toggleMenu);
        mobileMenuOverlay.addEventListener('click', toggleMenu);


        // --- MOBILE TIMETABLE TABS ---
        const dayTabs = document.querySelectorAll('.day-tab');
        const dayPanels = document.querySelectorAll('.day-panel');
        const today = new Date().toLocaleDateString('en-US', { weekday: 'long' });

        function activateTab(day) {
            dayTabs.forEach(tab => {
                const isSelected = tab.dataset.day === day;
                tab.classList.toggle('border-blue-500', isSelected);
                tab.classList.toggle('text-blue-600', isSelected);
                tab.classList.toggle('border-transparent', !isSelected);
                tab.classList.toggle('text-gray-500', !isSelected);
            });
            dayPanels.forEach(panel => {
                panel.classList.toggle('hidden', panel.id !== `day-panel-${day}`);
            });
        }

        dayTabs.forEach(tab => {
            tab.addEventListener('click', () => {
                activateTab(tab.dataset.day);
            });
        });

        let defaultDay = today;
        if (!document.querySelector(`.day-tab[data-day="${today}"]`)) {
            defaultDay = dayTabs.length > 0 ? dayTabs[0].dataset.day : null;
        }
        if (defaultDay) {
            activateTab(defaultDay);
        }

        // --- INBOX LOGIC ---
        function showInbox() {
            mainDashboardContent.classList.add('hidden');
            inboxSection.classList.remove('hidden');
        }
        inboxBtn.addEventListener('click', showInbox);
        inboxBtnMobile.addEventListener('click', () => {
            toggleMenu(); // Close mobile menu first
            showInbox();
        });

        backToDashboardBtnInbox.addEventListener('click', () => {
            inboxSection.classList.add('hidden');
            mainDashboardContent.classList.remove('hidden');
            // Go back to placeholder
            messageViewerContent.classList.add('hidden');
            messageViewerPlaceholder.classList.remove('hidden');
        });

        backToListBtn.addEventListener('click', () => {
            messageViewer.classList.add('hidden');
            messageViewer.classList.remove('flex');
            messageListContainer.classList.remove('hidden');
        });
        
        function appendMessageToThread(msg) {
            const threadContainer = messageViewerContent.querySelector('.overflow-y-auto');
            if (!threadContainer) return;

            const isCurrentUser = msg.sender_id == currentUserId;
            const profilePic = msg.profile_picture_url ? msg.profile_picture_url : `https://placehold.co/40x40/e2e8f0/333333?text=${msg.sender_name.charAt(0)}`;
            const formattedDate = new Date(msg.created_at).toLocaleString(currentLang === 'zh' ? 'zh-TW' : 'en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });

            const messageHtml = `
                <div class="flex items-start gap-3 ${isCurrentUser ? 'flex-row-reverse' : ''}">
                    <img src="${profilePic}" class="w-10 h-10 rounded-full object-cover">
                    <div class="max-w-xs md:max-w-md">
                        <div class="p-3 rounded-lg ${isCurrentUser ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800'}">
                            <div class="prose prose-sm max-w-none ${isCurrentUser ? 'text-white' : 'text-gray-800'}">${msg.body}</div>
                        </div>
                        <div class="text-xs text-gray-400 mt-1 px-1 ${isCurrentUser ? 'text-right' : ''}">${msg.sender_name}, ${formattedDate}</div>
                    </div>
                </div>
            `;
            threadContainer.insertAdjacentHTML('beforeend', messageHtml);
            // Scroll to bottom
            threadContainer.scrollTop = threadContainer.scrollHeight;
        }

        function renderThread(threadMessages, originalRecipientId) {
            let threadHtml = '<div class="flex-grow overflow-y-auto p-4 space-y-4">';
            threadMessages.forEach(msg => {
                const isCurrentUser = msg.sender_id == currentUserId;
                const profilePic = msg.profile_picture_url ? msg.profile_picture_url : `https://placehold.co/40x40/e2e8f0/333333?text=${msg.sender_name.charAt(0)}`;
                const formattedDate = new Date(msg.created_at).toLocaleString(currentLang === 'zh' ? 'zh-TW' : 'en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });

                threadHtml += `
                    <div class="flex items-start gap-3 ${isCurrentUser ? 'flex-row-reverse' : ''}">
                        <img src="${profilePic}" class="w-10 h-10 rounded-full object-cover">
                        <div class="max-w-xs md:max-w-md">
                            <div class="p-3 rounded-lg ${isCurrentUser ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800'}">
                                <div class="prose prose-sm max-w-none ${isCurrentUser ? 'text-white' : 'text-gray-800'}">${msg.body}</div>
                            </div>
                            <div class="text-xs text-gray-400 mt-1 px-1 ${isCurrentUser ? 'text-right' : ''}">${msg.sender_name}, ${formattedDate}</div>
                        </div>
                    </div>
                `;
            });
            threadHtml += '</div>';

            // Add reply form
            threadHtml += `
                <div class="p-4 border-t bg-gray-50 flex-shrink-0">
                    <form id="reply-form">
                        <input type="hidden" name="original_recipient_id" value="${originalRecipientId}">
                        <textarea name="reply_body" class="w-full p-2 border rounded-md" rows="3" placeholder="${currentLang === 'zh' ? '輸入您的回覆...' : 'Type your reply...'}" required></textarea>
                        <div class="text-right mt-2">
                            <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="Send Reply" data-lang-zh="發送回覆">Send Reply</button>
                        </div>
                    </form>
                </div>
            `;
            messageViewerContent.innerHTML = threadHtml;
            messageViewerPlaceholder.classList.add('hidden');
            messageViewerContent.classList.remove('hidden');
            messageViewerContent.classList.add('flex');
            
            // Scroll to bottom of thread on render
            const threadContainer = messageViewerContent.querySelector('.overflow-y-auto');
            if(threadContainer) {
                threadContainer.scrollTop = threadContainer.scrollHeight;
            }

            // Add event listener for the new form
            document.getElementById('reply-form').addEventListener('submit', handleReplySubmit);
            updateLanguage();
        }

        function handleReplySubmit(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            formData.append('action', 'reply_message');
            const replyTextarea = form.querySelector('textarea[name="reply_body"]');

            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = currentLang === 'zh' ? '發送中...' : 'Sending...';

            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                submitButton.disabled = false;
                updateLanguage(); // Reset button text
                if (data.success && data.newMessage) {
                    appendMessageToThread(data.newMessage);
                    replyTextarea.value = ''; // Clear textarea
                } else {
                    alert(data.message || 'An error occurred.');
                }
            }).catch(err => {
                 alert('An error occurred.');
                 submitButton.disabled = false;
                 updateLanguage(); // Reset button text
            });
        }
        
        function markThreadAsRead(recipientId) {
            const formData = new FormData();
            formData.append('action', 'mark_as_read');
            formData.append('recipient_id', recipientId);

            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.marked_read_count > 0) {
                    // Update the main unread badge
                    let currentCount = parseInt(unreadBadge.textContent);
                    let newCount = currentCount - data.marked_read_count;
                    if (newCount <= 0) {
                        if(unreadBadge) unreadBadge.parentElement.classList.add('hidden');
                        if(unreadBadgeMobile) unreadBadgeMobile.parentElement.classList.add('hidden');
                    } else {
                        if(unreadBadge) unreadBadge.textContent = newCount;
                        if(unreadBadgeMobile) unreadBadgeMobile.textContent = newCount;
                    }
                    // Update the visual indicator on the message list item
                    const item = messageList.querySelector(`.message-item[data-recipient-id="${recipientId}"]`);
                    if (item) {
                        item.dataset.hasUnread = '0';
                        item.classList.remove('bg-blue-50');
                        item.querySelector('p:first-child').classList.remove('font-bold', 'text-gray-900');
                        item.querySelector('p:first-child').classList.add('font-medium', 'text-gray-600');
                        item.querySelector('p:last-child').classList.remove('font-semibold', 'text-gray-800');
                        item.querySelector('p:last-child').classList.add('text-gray-500');
                    }
                }
            });
        }

        messageList.addEventListener('click', (e) => {
            const item = e.target.closest('.message-item');
            if (!item) return;

            const recipientId = item.dataset.recipientId;
            const hasUnread = item.dataset.hasUnread === '1';

            // On mobile, hide list and show viewer
            if (window.innerWidth < 768) {
                messageListContainer.classList.add('hidden');
                messageViewer.classList.remove('hidden');
                messageViewer.classList.add('flex');
            }

            // Fetch the entire thread
            const formData = new FormData();
            formData.append('action', 'get_message_thread');
            formData.append('recipient_id', recipientId);

            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderThread(data.thread, recipientId);
                    if (hasUnread) {
                        markThreadAsRead(recipientId);
                    }
                } else {
                    alert(data.message);
                }
            });
        });

        // Initial language setup on page load
        updateLanguage();
    });
    </script>
</body>
</html>
