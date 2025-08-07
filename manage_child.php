<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set the timezone to ensure correct date operations
date_default_timezone_set('Asia/Taipei');

// Include the database config FIRST.
require_once "db_config.php";

// Start the session to access login state.
session_start();

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'manage_account' => 'Manage Account',
        'logout' => 'Logout',
        'managing_for' => 'You are managing the account for',
        'return_to_portal' => 'Click here to return to the Family Portal',
        'dashboard' => 'Dashboard',
        'book_1_to_1' => 'Book a 1-to-1 Class',
        'check_in' => 'Today\'s Check-In',
        'training_log' => 'Training Log',
        'view_billing' => 'View Billing',
        'belt_level' => 'Belt Level',
        'membership' => 'Membership',
        'expires_on' => 'Expires On',
        'class_credits' => 'Class Credits',
        'timetable' => 'Weekly Timetable',
        'no_classes' => 'No classes scheduled.',
        'bookings' => 'Bookings',
        'cancel' => 'Cancel',
        'on_waitlist' => 'On Waitlist',
        'join_waitlist' => 'Join Waitlist',
        'book_class' => 'Book Class',
        'renew_membership' => 'Renew Membership',
        'billing_title' => 'Membership & Billing',
        'back_to_dashboard' => 'Back to Dashboard',
        'expiry_date' => 'Expiry Date',
        'status' => 'Status',
        'classes_remaining' => 'Classes Remaining',
        'no_history' => 'No membership history found.',
        'active' => 'Active',
        'expired' => 'Expired',
        'confirm_action' => 'Are you sure?',
        'ok' => 'OK',
        'made_payment' => 'Made a Payment',
        'submit_payment' => 'Submit Payment',
        'payment_method' => 'Payment Method',
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'amount_paid' => 'Amount Paid',
        'last_5_digits' => 'Last 5 Digits of Transaction',
        'submit' => 'Submit',
    ],
    'zh' => [
        'manage_account' => '管理帳戶',
        'logout' => '登出',
        'managing_for' => '您正在管理帳戶',
        'return_to_portal' => '點此返回家庭入口網站',
        'dashboard' => '儀表板',
        'book_1_to_1' => '預約一對一課程',
        'check_in' => '今日簽到',
        'training_log' => '訓練日誌',
        'view_billing' => '查看帳務',
        'belt_level' => '腰帶等級',
        'membership' => '會員資格',
        'expires_on' => '到期日',
        'class_credits' => '課程點數',
        'timetable' => '每週時間表',
        'no_classes' => '沒有排定的課程。',
        'bookings' => '預約數',
        'cancel' => '取消預約',
        'on_waitlist' => '候補中',
        'join_waitlist' => '加入候補名單',
        'book_class' => '預約課程',
        'renew_membership' => '續訂會員',
        'billing_title' => '會員與帳務',
        'back_to_dashboard' => '返回儀表板',
        'expiry_date' => '到期日',
        'status' => '狀態',
        'classes_remaining' => '剩餘課程',
        'no_history' => '找不到會員歷史記錄。',
        'active' => '有效',
        'expired' => '已過期',
        'confirm_action' => '您確定嗎？',
        'ok' => '好的',
        'made_payment' => '回報付款',
        'submit_payment' => '提交付款',
        'payment_method' => '付款方式',
        'cash' => '現金',
        'bank_transfer' => '銀行轉帳',
        'amount_paid' => '付款金額',
        'last_5_digits' => '交易末5碼',
        'submit' => '提交',
    ]
];

$lang = $_SESSION['lang'] ?? 'en';


// --- SECURITY CHECK ---
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'parent') {
    header("location: login.html");
    exit;
}

$child_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$child_id) {
    die("Invalid child ID provided.");
}

$parent_id = $_SESSION['id'];
$child_details = null;

$sql_verify_child = "SELECT id, first_name, last_name, email, role, belt_color, profile_picture_url, last_announcement_viewed_id, member_type FROM users WHERE id = ? AND parent_id = ?";
if ($stmt_verify = mysqli_prepare($link, $sql_verify_child)) {
    mysqli_stmt_bind_param($stmt_verify, "ii", $child_id, $parent_id);
    mysqli_stmt_execute($stmt_verify);
    $result_verify = mysqli_stmt_get_result($stmt_verify);
    $child_details = mysqli_fetch_assoc($result_verify);
    mysqli_stmt_close($stmt_verify);
}

if ($child_details) {
    $child_details['full_name'] = $child_details['first_name'] . ' ' . $child_details['last_name'];
} else {
    die("Unauthorized access or invalid child ID.");
}

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $user_id_for_action = $child_id;
    $action = $_POST['action'];
    header('Content-Type: application/json');

    switch ($action) {
        case 'book_class':
            $class_id = $_POST['class_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';

            $check_sql = "SELECT m.end_date, m.class_credits, m.membership_type FROM memberships m WHERE m.user_id = ? AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = ?)";
            $stmt_check = mysqli_prepare($link, $check_sql);
            mysqli_stmt_bind_param($stmt_check, "ii", $user_id_for_action, $user_id_for_action);
            mysqli_stmt_execute($stmt_check);
            $member_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
            mysqli_stmt_close($stmt_check);

            $is_valid = false;
            if ($member_info && strtotime($member_info['end_date']) >= strtotime(date('Y-m-d'))) {
                if (strpos($member_info['membership_type'], 'Class') !== false) {
                    if ($member_info['class_credits'] > 0) $is_valid = true;
                } else {
                    $is_valid = true;
                }
            }

            if (!$is_valid) {
                echo json_encode(['success' => false, 'message' => $translations[$lang]['renew_membership']]);
                exit;
            }

            $capacity_sql = "SELECT c.capacity, COUNT(b.id) as current_bookings FROM classes c LEFT JOIN bookings b ON c.id = b.class_id AND b.booking_date = ? WHERE c.id = ? GROUP BY c.id";
            $stmt_cap = mysqli_prepare($link, $capacity_sql);
            mysqli_stmt_bind_param($stmt_cap, "si", $booking_date, $class_id);
            mysqli_stmt_execute($stmt_cap);
            $class_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cap));
            mysqli_stmt_close($stmt_cap);

            if ($class_info && $class_info['current_bookings'] >= $class_info['capacity']) {
                echo json_encode(['success' => false, 'message' => 'This class is full. Please join the waitlist.']);
                exit;
            }

            mysqli_begin_transaction($link);
            try {
                $insert_sql = "INSERT INTO bookings (user_id, class_id, booking_date, status) VALUES (?, ?, ?, 'booked')";
                $stmt_insert = mysqli_prepare($link, $insert_sql);
                mysqli_stmt_bind_param($stmt_insert, "iis", $user_id_for_action, $class_id, $booking_date);
                mysqli_stmt_execute($stmt_insert);
                mysqli_stmt_close($stmt_insert);

                if (strpos($member_info['membership_type'], 'Class') !== false) {
                    $update_sql = "UPDATE memberships SET class_credits = class_credits - 1 WHERE user_id = ? AND end_date = ?";
                    $stmt_update = mysqli_prepare($link, $update_sql);
                    mysqli_stmt_bind_param($stmt_update, "is", $user_id_for_action, $member_info['end_date']);
                    mysqli_stmt_execute($stmt_update);
                    mysqli_stmt_close($stmt_update);
                }

                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Class booked successfully for ' . htmlspecialchars($child_details['first_name']) . '!']);
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($link);
                error_log("Booking failed for child {$child_id}: " . $exception->getMessage());
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
                mysqli_stmt_bind_param($stmt_check, "ii", $user_id_for_action, $user_id_for_action);
                mysqli_stmt_execute($stmt_check);
                $member_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_check));
                mysqli_stmt_close($stmt_check);

                $delete_sql = "DELETE FROM bookings WHERE user_id = ? AND class_id = ? AND booking_date = ?";
                $stmt_delete = mysqli_prepare($link, $delete_sql);
                mysqli_stmt_bind_param($stmt_delete, "iis", $user_id_for_action, $class_id, $booking_date);
                mysqli_stmt_execute($stmt_delete);
                $affected_rows = mysqli_stmt_affected_rows($stmt_delete);
                mysqli_stmt_close($stmt_delete);

                if ($affected_rows > 0 && $member_info && strpos($member_info['membership_type'], 'Class') !== false) {
                    $update_sql = "UPDATE memberships SET class_credits = class_credits + 1 WHERE user_id = ? AND end_date = ?";
                    $stmt_update = mysqli_prepare($link, $update_sql);
                    mysqli_stmt_bind_param($stmt_update, "is", $user_id_for_action, $member_info['end_date']);
                    mysqli_stmt_execute($stmt_update);
                    mysqli_stmt_close($stmt_update);
                }
                
                $waitlist_sql = "SELECT user_id FROM waitlist WHERE class_id = ? AND booking_date = ? ORDER BY request_date ASC LIMIT 1";
                $stmt_waitlist = mysqli_prepare($link, $waitlist_sql);
                mysqli_stmt_bind_param($stmt_waitlist, "is", $class_id, $booking_date);
                mysqli_stmt_execute($stmt_waitlist);
                $waitlist_user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_waitlist));
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
                }

                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully!']);
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($link);
                error_log("Cancellation failed for child {$child_id}: " . $exception->getMessage());
                echo json_encode(['success' => false, 'message' => 'An error occurred during cancellation.']);
            }
            break;
        
        case 'join_waitlist':
            $class_id = $_POST['class_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';

            $waitlist_check_sql = "SELECT COUNT(*) FROM waitlist WHERE user_id = ? AND class_id = ? AND booking_date = ?";
            $stmt_waitlist_check = mysqli_prepare($link, $waitlist_check_sql);
            mysqli_stmt_bind_param($stmt_waitlist_check, "iis", $user_id_for_action, $class_id, $booking_date);
            mysqli_stmt_execute($stmt_waitlist_check);
            $waitlist_count = mysqli_fetch_row(mysqli_stmt_get_result($stmt_waitlist_check))[0];
            mysqli_stmt_close($stmt_waitlist_check);

            if ($waitlist_count > 0) {
                echo json_encode(['success' => false, 'message' => 'You are already on the waitlist for this class.']);
                exit;
            }
            
            $waitlist_sql = "INSERT INTO waitlist (user_id, class_id, booking_date) VALUES (?, ?, ?)";
            if ($stmt = mysqli_prepare($link, $waitlist_sql)) {
                mysqli_stmt_bind_param($stmt, "iis", $user_id_for_action, $class_id, $booking_date);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                echo json_encode(['success' => true, 'message' => 'Added to the waitlist successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
            break;

        case 'submit_payment':
            $payment_method = $_POST['payment_method'] ?? 'N/A';
            $amount = $_POST['amount'] ?? '0';
            $last_five = $_POST['last_five'] ?? '';
            
            $member_name = $child_details['full_name'];
            $member_email = $child_details['email'];
            $profile_pic_path = $child_details['profile_picture_url'] ?? null;

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
                <p>Payment submitted for member: <strong>{$member_name}</strong> (ID: {$user_id_for_action})</p>
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
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// --- Fetch Dashboard Data for the Child ---
$member_age_group = (stripos($child_details['member_type'], 'kid') !== false) ? 'Kid' : 'Adult';
$membership_sql = "SELECT membership_type, end_date, class_credits FROM memberships WHERE user_id = ? ORDER BY end_date DESC LIMIT 1";
$child_membership = null;
if($stmt_mem = mysqli_prepare($link, $membership_sql)) {
    mysqli_stmt_bind_param($stmt_mem, "i", $child_id);
    mysqli_stmt_execute($stmt_mem);
    $child_membership = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_mem));
    mysqli_stmt_close($stmt_mem);
}
$is_membership_valid = false;
if ($child_membership && strtotime($child_membership['end_date']) >= strtotime(date('Y-m-d'))) {
    if (strpos($child_membership['membership_type'], 'Class') !== false) {
        if ($child_membership['class_credits'] > 0) $is_membership_valid = true;
    } else {
        $is_membership_valid = true;
    }
}
$billing_history = [];
$billing_sql = "SELECT membership_type, end_date, class_credits FROM memberships WHERE user_id = ? ORDER BY end_date DESC";
if ($stmt_billing = mysqli_prepare($link, $billing_sql)) {
    mysqli_stmt_bind_param($stmt_billing, "i", $child_id);
    mysqli_stmt_execute($stmt_billing);
    $result_billing = mysqli_stmt_get_result($stmt_billing);
    $billing_history = mysqli_fetch_all($result_billing, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt_billing);
}
$upcoming_dates = [];
$start_of_week_dt = new DateTime('monday this week');
for ($i = 0; $i < 7; $i++) {
    $date = (clone $start_of_week_dt)->modify('+' . $i . ' days');
    $day_name = $date->format('l');
    $upcoming_dates[$day_name] = $date->format('Y-m-d');
}
$schedule_sql = "SELECT c.id AS class_id, c.name, c.name_zh, c.day_of_week, c.start_time, c.end_time, c.capacity, u.first_name AS coach_first_name, u.last_name AS coach_last_name, b_user.id AS user_booking_id, w_user.id AS user_waitlist_id, (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND booking_date = ?) AS total_bookings_count FROM classes c LEFT JOIN users u ON c.coach_id = u.id LEFT JOIN bookings b_user ON c.id = b_user.class_id AND b_user.user_id = ? AND b_user.booking_date = ? LEFT JOIN waitlist w_user ON c.id = w_user.class_id AND w_user.user_id = ? AND w_user.booking_date = ? WHERE c.is_active = 1 AND c.age = ? AND c.day_of_week = ? ORDER BY c.start_time";
$schedule = ['Monday' => [], 'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [], 'Saturday' => [], 'Sunday' => []];
foreach ($schedule as $day => &$classes_for_day) {
    $booking_date_for_day = $upcoming_dates[$day];
    if ($stmt_schedule = mysqli_prepare($link, $schedule_sql)) {
        mysqli_stmt_bind_param($stmt_schedule, "sississ", $booking_date_for_day, $child_id, $booking_date_for_day, $child_id, $booking_date_for_day, $member_age_group, $day);
        if (mysqli_stmt_execute($stmt_schedule)) {
            $result = mysqli_stmt_get_result($stmt_schedule);
            while ($row = mysqli_fetch_assoc($result)) {
                $row['coach_name'] = trim($row['coach_first_name'] . ' ' . $row['coach_last_name']);
                $classes_for_day[] = $row;
            }
        }
        mysqli_stmt_close($stmt_schedule);
    }
}
unset($classes_for_day);
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$days_zh = ['Monday' => '星期一', 'Tuesday' => '星期二', 'Wednesday' => '星期三', 'Thursday' => '星期四', 'Friday' => '星期五', 'Saturday' => '星期六', 'Sunday' => '星期日'];
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $translations['en']['manage_account'] . ' - ' . htmlspecialchars($child_details['full_name']); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .timetable-container::-webkit-scrollbar { width: 8px; height: 8px; }
        .timetable-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .timetable-container::-webkit-scrollbar-thumb { background: #888; border-radius: 10px; }
        .timetable-container::-webkit-scrollbar-thumb:hover { background: #555; }
        .modal { transition: opacity 0.25s ease; }
        .payment-btn-selected { background-color: #2563eb; color: white; }
        @media (max-width: 767px) {
            #billing-table thead { display: none; }
            #billing-table tbody, #billing-table tr, #billing-table td { display: block; width: 100%; }
            #billing-table tr { margin-bottom: 1rem; border: 1px solid #ddd; border-radius: 0.5rem; padding: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
            #billing-table td { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
            #billing-table td:last-child { border-bottom: none; }
            #billing-table td::before { content: attr(data-label); font-weight: bold; padding-right: 1rem; }
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <a href="#" class="flex items-center space-x-2">
                <img src="logo.png" alt="Catch Jiu Jitsu Logo" class="h-12 w-12" onerror="this.onerror=null;this.src='https://placehold.co/48x48/e0e0e0/333333?text=Logo';">
                <span class="font-bold text-xl text-gray-800 hidden sm:block lang" data-lang-en="Manage Account" data-lang-zh="管理帳戶">Manage Account</span>
            </a>
            <div class="flex items-center space-x-4">
                 <div id="lang-switcher-desktop" class="pl-4 flex items-center space-x-2 text-sm border-l border-gray-300">
                    <button id="lang-en" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Logout" data-lang-zh="登出">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 sm:px-6 py-12">
        
        <div class="bg-blue-100 border-t-4 border-blue-500 rounded-b text-blue-900 px-4 py-3 shadow-md mb-8" role="alert">
            <div class="flex">
                <div class="py-1"><svg class="fill-current h-6 w-6 text-blue-500 mr-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path d="M2.93 17.07A10 10 0 1 1 17.07 2.93 10 10 0 0 1 2.93 17.07zm12.73-1.41A8 8 0 1 0 4.34 4.34a8 8 0 0 0 11.32 11.32zM9 11V9h2v6H9v-4zm0-6h2v2H9V5z"/></svg></div>
                <div>
                    <p class="font-bold"><span class="lang" data-lang-en="You are managing the account for" data-lang-zh="您正在管理帳戶">You are managing the account for</span> <?php echo htmlspecialchars($child_details['full_name']); ?>.</p>
                    <a href="parents_dashboard.php" class="text-sm font-semibold hover:underline lang" data-lang-en="Click here to return to the Family Portal" data-lang-zh="點此返回家庭入口網站">Click here to return to the Family Portal</a>
                </div>
            </div>
        </div>

        <div id="main-dashboard-content">
            <div class="flex items-center gap-6 flex-wrap mb-8">
                <img src="<?php echo !empty($child_details['profile_picture_url']) ? htmlspecialchars($child_details['profile_picture_url']) : 'https://placehold.co/100x100/e2e8f0/333333?text=Pic'; ?>" alt="Profile Picture" class="w-24 h-24 rounded-full object-cover border-4 border-white shadow-md">
                <div>
                    <h1 class="text-4xl font-black text-gray-800">
                        <?php echo htmlspecialchars($child_details['full_name']); ?>
                    </h1>
                    <p class="text-lg text-gray-600 lang" data-lang-en="Dashboard" data-lang-zh="儀表板">Dashboard</p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mb-8">
                <a href="book_1to1.php?id=<?php echo $child_id; ?>" class="bg-indigo-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-indigo-700 transition text-sm lang" data-lang-en="Book a 1-to-1 Class" data-lang-zh="預約一對一課程">Book a 1-to-1 Class</a>
                <a href="member_check_in.php?id=<?php echo $child_id; ?>" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700 transition text-sm lang" data-lang-en="Today's Check-In" data-lang-zh="今日簽到">Today's Check-In</a>
                <a href="training_log.php?id=<?php echo $child_id; ?>" class="bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition text-sm lang" data-lang-en="Training Log" data-lang-zh="訓練日誌">Training Log</a>
                <button id="view-billing-btn" class="bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition text-sm lang" data-lang-en="View Billing" data-lang-zh="查看帳務">View Billing</button>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-white p-4 rounded-xl shadow-md text-center">
                    <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Belt Level" data-lang-zh="腰帶等級">Belt Level</p>
                    <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo htmlspecialchars($child_details['belt_color'] ?? 'N/A'); ?></p>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-md text-center">
                    <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Membership" data-lang-zh="會員資格">Membership</p>
                    <p class="text-xl font-bold text-gray-800 mt-1"><?php echo htmlspecialchars($child_membership['membership_type'] ?? 'N/A'); ?></p>
                </div>
                <div class="bg-white p-4 rounded-xl shadow-md text-center">
                    <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Expires On" data-lang-zh="到期日">Expires On</p>
                    <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo isset($child_membership['end_date']) ? date('M d, Y', strtotime($child_membership['end_date'])) : 'N/A'; ?></p>
                </div>
                 <div class="bg-white p-4 rounded-xl shadow-md text-center">
                    <p class="text-sm font-semibold text-gray-500 lang" data-lang-en="Class Credits" data-lang-zh="課程點數">Class Credits</p>
                    <p class="text-2xl font-bold text-gray-800 mt-1"><?php echo $child_membership['class_credits'] ?? 'N/A'; ?></p>
                </div>
            </div>

            <div id="booking-section" class="mt-12">
                <h2 class="text-3xl font-bold text-gray-800 border-b-4 border-blue-500 pb-2 inline-block lang" data-lang-en="Weekly Timetable" data-lang-zh="每週時間表">Weekly Timetable</h2>
                
                <!-- Mobile Timetable -->
                <div class="md:hidden mt-4">
                    <div class="border-b border-gray-200">
                        <nav id="mobile-tabs" class="-mb-px flex space-x-2 overflow-x-auto" aria-label="Tabs">
                            <?php foreach ($days_of_week as $day): ?>
                                <button class="day-tab whitespace-nowrap py-3 px-4 border-b-2 font-medium text-sm" data-day="<?php echo $day; ?>">
                                    <span class="lang" data-lang-en="<?php echo $day; ?>" data-lang-zh="<?php echo $days_zh[$day]; ?>"><?php echo $day; ?></span>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <div class="mt-4">
                        <?php foreach ($days_of_week as $day): ?>
                            <div id="day-panel-<?php echo $day; ?>" class="day-panel hidden">
                                <div class="space-y-3">
                                    <?php if (empty($schedule[$day])): ?>
                                        <p class="text-center text-gray-500 text-sm p-4 lang" data-lang-en="No classes scheduled." data-lang-zh="沒有排定的課程。">No classes scheduled.</p>
                                    <?php else: ?>
                                        <?php foreach ($schedule[$day] as $class): ?>
                                            <?php
                                            $is_full = $class['total_bookings_count'] >= $class['capacity'];
                                            $is_on_waitlist = !empty($class['user_waitlist_id']);
                                            ?>
                                            <div class="bg-white p-3 rounded-lg text-sm shadow-md border-l-4 <?php echo $class['user_booking_id'] ? 'border-green-500' : ($is_on_waitlist ? 'border-yellow-500' : 'border-blue-500'); ?>">
                                                <p class="font-bold text-gray-800 lang" data-lang-en="<?php echo htmlspecialchars($class['name']); ?>" data-lang-zh="<?php echo htmlspecialchars($class['name_zh']); ?>"><?php echo htmlspecialchars($class['name']); ?></p>
                                                <p class="text-gray-700"><?php echo date("g:i A", strtotime($class['start_time'])); ?></p>
                                                <p class="text-xs mt-1 font-bold"><span class="lang" data-lang-en="Bookings" data-lang-zh="預約數">Bookings</span>: <?php echo htmlspecialchars($class['total_bookings_count']); ?> / <?php echo htmlspecialchars($class['capacity']); ?></p>
                                                <div class="mt-3">
                                                    <?php if ($class['user_booking_id']): ?>
                                                        <button class="w-full bg-red-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-red-600 transition cancel-btn lang" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>" data-lang-en="Cancel" data-lang-zh="取消預約">Cancel</button>
                                                    <?php elseif ($is_on_waitlist): ?>
                                                        <button class="w-full bg-yellow-500 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed lang" disabled data-lang-en="On Waitlist" data-lang-zh="候補中">On Waitlist</button>
                                                    <?php elseif ($is_full): ?>
                                                        <button class="w-full bg-gray-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-gray-600 transition waitlist-btn lang" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>" data-lang-en="Join Waitlist" data-lang-zh="加入候補名單">Join Waitlist</button>
                                                    <?php elseif ($is_membership_valid): ?>
                                                        <button class="w-full bg-blue-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-blue-600 transition book-btn lang" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>" data-lang-en="Book Class" data-lang-zh="預約課程">Book Class</button>
                                                    <?php else: ?>
                                                        <button class="w-full bg-gray-400 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed lang" disabled data-lang-en="Renew Membership" data-lang-zh="續訂會員">Renew Membership</button>
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
                        <?php foreach ($days_of_week as $day): ?>
                            <div class="p-2 bg-gray-50 rounded-lg">
                                <div class="font-bold text-center text-lg p-2 border-b-2 border-gray-200">
                                    <span class="lang" data-lang-en="<?php echo $day; ?>" data-lang-zh="<?php echo $days_zh[$day]; ?>"><?php echo $day; ?></span>
                                    <span class="block text-xs text-gray-500"><?php echo date("m/d", strtotime($upcoming_dates[$day])); ?></span>
                                </div>
                                <div class="space-y-2 mt-2">
                                    <?php if (empty($schedule[$day])): ?>
                                        <p class="text-center text-gray-500 text-sm p-4 lang" data-lang-en="No classes scheduled." data-lang-zh="沒有排定的課程。">No classes scheduled.</p>
                                    <?php else: ?>
                                        <?php foreach ($schedule[$day] as $class): ?>
                                            <?php
                                            $is_full = $class['total_bookings_count'] >= $class['capacity'];
                                            $is_on_waitlist = !empty($class['user_waitlist_id']);
                                            ?>
                                            <div class="bg-white p-3 rounded-lg text-sm shadow-md border-l-4 <?php echo $class['user_booking_id'] ? 'border-green-500' : ($is_on_waitlist ? 'border-yellow-500' : 'border-blue-500'); ?>">
                                                <p class="font-bold text-gray-800 lang" data-lang-en="<?php echo htmlspecialchars($class['name']); ?>" data-lang-zh="<?php echo htmlspecialchars($class['name_zh']); ?>"><?php echo htmlspecialchars($class['name']); ?></p>
                                                <p class="text-gray-700"><?php echo date("g:i A", strtotime($class['start_time'])); ?></p>
                                                <p class="text-xs mt-1 font-bold"><span class="lang" data-lang-en="Bookings" data-lang-zh="預約數">Bookings</span>: <?php echo htmlspecialchars($class['total_bookings_count']); ?> / <?php echo htmlspecialchars($class['capacity']); ?></p>
                                                <div class="mt-3">
                                                    <?php if ($class['user_booking_id']): ?>
                                                        <button class="w-full bg-red-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-red-600 transition cancel-btn lang" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>" data-lang-en="Cancel" data-lang-zh="取消預約">Cancel</button>
                                                    <?php elseif ($is_on_waitlist): ?>
                                                        <button class="w-full bg-yellow-500 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed lang" disabled data-lang-en="On Waitlist" data-lang-zh="候補中">On Waitlist</button>
                                                    <?php elseif ($is_full): ?>
                                                        <button class="w-full bg-gray-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-gray-600 transition waitlist-btn lang" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>" data-lang-en="Join Waitlist" data-lang-zh="加入候補名單">Join Waitlist</button>
                                                    <?php elseif ($is_membership_valid): ?>
                                                        <button class="w-full bg-blue-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-blue-600 transition book-btn lang" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>" data-lang-en="Book Class" data-lang-zh="預約課程">Book Class</button>
                                                    <?php else: ?>
                                                        <button class="w-full bg-gray-400 text-white text-xs font-bold py-2 px-2 rounded cursor-not-allowed lang" disabled data-lang-en="Renew Membership" data-lang-zh="續訂會員">Renew Membership</button>
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
        
        <div id="billing-view-section" class="mt-12 hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-3xl font-bold text-gray-800 lang" data-lang-en="Membership & Billing" data-lang-zh="會員與帳務">Membership & Billing</h2>
                <button id="back-to-dashboard-btn" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition lang" data-lang-en="Back to Dashboard" data-lang-zh="返回儀表板">Back to Dashboard</button>
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
                                <tr><td colspan="4" class="text-center py-4 lang" data-lang-en="No membership history found." data-lang-zh="找不到會員歷史記錄。">No membership history found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($billing_history as $item): ?>
                                    <?php
                                    $is_class_pass = (stripos($item['membership_type'], 'Class') !== false);
                                    $expiry_timestamp = strtotime($item['end_date']);
                                    $status_en = ($expiry_timestamp >= strtotime(date('Y-m-d'))) ? 'Active' : 'Expired';
                                    $status_zh = ($status_en === 'Active') ? '有效' : '已過期';
                                    $status_class = ($status_en === 'Active') ? 'text-green-600' : 'text-red-600';
                                    ?>
                                    <tr>
                                        <td data-label="<?php echo $translations[$lang]['membership']; ?>"><?php echo htmlspecialchars($item['membership_type']); ?></td>
                                        <td data-label="<?php echo $translations[$lang]['expiry_date']; ?>"><?php echo date("m/d/Y", $expiry_timestamp); ?></td>
                                        <td data-label="<?php echo $translations[$lang]['status']; ?>" class="font-bold <?php echo $status_class; ?>"><span class="lang" data-lang-en="<?php echo $status_en; ?>" data-lang-zh="<?php echo $status_zh; ?>"><?php echo $status_en; ?></span></td>
                                        <td data-label="<?php echo $translations[$lang]['classes_remaining']; ?>">
                                            <?php echo $is_class_pass ? htmlspecialchars($item['class_credits']) : '<span class="text-gray-400">N/A</span>'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div id="status-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div id="status-modal-content" class="text-center py-4"></div>
            <div class="text-center">
                <button id="close-status-modal" class="bg-blue-500 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-600 transition lang" data-lang-en="OK" data-lang-zh="好的">OK</button>
            </div>
        </div>
    </div>

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

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const statusModal = document.getElementById('status-modal');
        const closeStatusModalBtn = document.getElementById('close-status-modal');
        const bookingSection = document.getElementById('booking-section');
        const mainDashboardContent = document.getElementById('main-dashboard-content');
        const billingViewSection = document.getElementById('billing-view-section');
        const viewBillingBtn = document.getElementById('view-billing-btn');
        const backToDashboardBtn = document.getElementById('back-to-dashboard-btn');
        
        const madePaymentBtn = document.getElementById('made-payment-btn');
        const paymentModal = document.getElementById('payment-modal');
        const closePaymentModalBtn = document.getElementById('close-payment-modal');
        const paymentForm = document.getElementById('payment-form');
        const paymentMethodBtns = document.getElementById('payment-method-btns');
        const paymentMethodInput = document.getElementById('payment_method_input');
        const lastFiveContainer = document.getElementById('last-five-container');
        
        let currentLang = localStorage.getItem('childDashboardLang') || 'en';
        const translations = <?php echo json_encode($translations); ?>;

        function updateLanguage() {
            document.querySelectorAll('.lang').forEach(el => {
                const text = el.getAttribute('data-lang-' + currentLang);
                if (text) {
                    el.textContent = text;
                }
            });
            
            const btnEn = document.getElementById('lang-en');
            const btnZh = document.getElementById('lang-zh');

            btnEn.classList.toggle('font-bold', currentLang === 'en');
            btnEn.classList.toggle('text-blue-600', currentLang === 'en');
            btnEn.classList.toggle('text-gray-500', currentLang !== 'en');
            btnEn.classList.toggle('font-normal', currentLang !== 'en');
            
            btnZh.classList.toggle('font-bold', currentLang === 'zh');
            btnZh.classList.toggle('text-blue-600', currentLang === 'zh');
            btnZh.classList.toggle('text-gray-500', currentLang !== 'zh');
            btnZh.classList.toggle('font-normal', currentLang !== 'zh');
        }

        function setLanguage(lang) {
            currentLang = lang;
            localStorage.setItem('childDashboardLang', lang);
            updateLanguage();
        }

        document.getElementById('lang-en').addEventListener('click', () => setLanguage('en'));
        document.getElementById('lang-zh').addEventListener('click', () => setLanguage('zh'));

        function showStatus(message, isSuccess) {
            const content = document.getElementById('status-modal-content');
            content.innerHTML = `<p class="text-lg font-medium ${isSuccess ? 'text-green-600' : 'text-red-600'}">${message}</p>`;
            statusModal.classList.remove('hidden');
        }

        closeStatusModalBtn.addEventListener('click', () => {
            statusModal.classList.add('hidden');
        });

        viewBillingBtn.addEventListener('click', () => {
            mainDashboardContent.classList.add('hidden');
            billingViewSection.classList.remove('hidden');
        });

        backToDashboardBtn.addEventListener('click', () => {
            billingViewSection.classList.add('hidden');
            mainDashboardContent.classList.remove('hidden');
        });

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
                
                const url = `${window.location.pathname}?id=<?php echo $child_id; ?>`;
                fetch(url, {
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

        if (bookingSection) {
            bookingSection.addEventListener('click', function(e) {
                const target = e.target.closest('.book-btn, .cancel-btn, .waitlist-btn');
                if (!target) return;

                if (window.confirm(translations[currentLang]['confirm_action'])) {
                    const isBooking = target.classList.contains('book-btn');
                    const isWaitlist = target.classList.contains('waitlist-btn');
                    const action = isBooking ? 'book_class' : (isWaitlist ? 'join_waitlist' : 'cancel_class');
                    const classId = target.dataset.classId;
                    const bookingDate = target.dataset.bookingDate;

                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('class_id', classId);
                    formData.append('booking_date', bookingDate);
                    
                    const url = `${window.location.pathname}?id=<?php echo $child_id; ?>`;

                    fetch(url, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        showStatus(data.message, data.success);
                        if (data.success) {
                            setTimeout(() => { window.location.reload(); }, 2000);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showStatus('A network error occurred. Please try again.', false);
                    });
                }
            });
        }
        
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
        let todayTabExists = Array.from(dayTabs).some(tab => tab.dataset.day === today);
        if (!todayTabExists && dayTabs.length > 0) {
            defaultDay = dayTabs[0].dataset.day;
        }

        if (defaultDay) {
            activateTab(defaultDay);
        }

        // Initial language load
        updateLanguage();
    });
    </script>
</body>
</html>
