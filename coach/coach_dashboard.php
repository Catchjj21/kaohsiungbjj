<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// THE FIX: Include the database config FIRST.
require_once "../db_config.php";

// Now that the settings are loaded, start the session.
session_start();
 
// Check if the user is logged in and is a coach or admin.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    header("location: ../login.html");
    exit;
}

$user_id = $_SESSION['id'];

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'portal_title' => 'Coach Portal',
        'logout' => 'Logout',
        'welcome' => 'Welcome, ',
        'manage_ops' => 'Manage your coaching operations from here.',
        'my_dashboard' => 'Your Dashboard',
        'class_booking' => 'Class Booking',
        'book_class_button' => 'Book a Class',
        'training_log' => 'Training Log',
        'view_log_button' => 'View Training Log',
        'member_directory' => 'Member Directory',
        'go_to_members' => 'Go to Member Management',
        'view_attendance' => 'View Attendance',
        'view_attendance_button' => 'View Attendance',
        'coach_schedule' => 'Coach Schedule',
        'manage_availability' => 'Manage Availability',
        'weekly_timetable' => 'Weekly Timetable',
        'book_class' => 'Book Class',
        'cancel_booking' => 'Cancel',
        'coach' => 'Coach',
        'bookings' => 'Bookings:',
        'no_classes' => 'No classes scheduled.',
        'renew_membership' => 'Renew Membership',
        'select_a_day' => 'Select a day to view classes',
        'close' => 'Close',
        'are_you_sure_book' => 'Are you sure you want to book this class?',
        'are_you_sure_cancel' => 'Are you sure you want to cancel this booking?',
        'booking_success' => 'Class booked successfully!',
        'cancellation_success' => 'Booking cancelled successfully!',
        'error_title' => 'Error!',
        'error_message' => 'A network error occurred. Please try again.',
        'ok' => 'OK',
        'class_not_full' => 'Class is not full',
        'class_full' => 'Class is full',
        'news' => 'News',
        'events' => 'Events',
        'inbox' => 'Inbox',
        'back_to_dashboard' => 'Back to Dashboard',
        'delete_message' => 'Delete',
        'confirm_delete' => 'Are you sure you want to delete this message?',
        'empty_inbox' => 'Your inbox is empty.',
        'select_message' => 'Select a message to read',
        'technique_of_the_week' => 'Technique of the Week',
        'manage_techniques' => 'Manage Techniques',
        'manage_technique_desc' => 'Manage and post the technique of the week video.'
    ],
    'zh' => [
        'portal_title' => '教練門戶',
        'logout' => '登出',
        'welcome' => '歡迎, ',
        'manage_ops' => '在此處管理您的教練運營。',
        'my_dashboard' => '您的儀表板',
        'class_booking' => '課程預約',
        'book_class_button' => '預約課程',
        'training_log' => '訓練日誌',
        'view_log_button' => '查看訓練日誌',
        'member_directory' => '會員目錄',
        'go_to_members' => '前往會員管理',
        'view_attendance' => '查看出席',
        'view_attendance_button' => '查看出席',
        'coach_schedule' => '教練時間表',
        'manage_availability' => '管理可用性',
        'weekly_timetable' => '每週時間表',
        'book_class' => '預約課程',
        'cancel_booking' => '取消預約',
        'coach' => '教練',
        'bookings' => '預約數：',
        'no_classes' => '沒有排定的課程。',
        'renew_membership' => '續訂會員',
        'select_a_day' => '選擇一天查看課程',
        'close' => '關閉',
        'are_you_sure_book' => '您確定要預約這堂課嗎？',
        'are_you_sure_cancel' => '您確定要取消這個預約嗎？',
        'booking_success' => '課程預約成功！',
        'cancellation_success' => '預約已成功取消！',
        'error_title' => '錯誤！',
        'error_message' => '發生網路錯誤。請稍後再試。',
        'ok' => '好的',
        'class_not_full' => '課程未滿',
        'class_full' => '課程已滿',
        'news' => '最新消息',
        'events' => '活動',
        'inbox' => '收件匣',
        'back_to_dashboard' => '返回儀表板',
        'delete_message' => '刪除',
        'confirm_delete' => '您確定要刪除此訊息嗎？',
        'empty_inbox' => '您的收件匣是空的。',
        'select_message' => '選擇一則訊息閱讀',
        'technique_of_the_week' => '本週技巧',
        'manage_techniques' => '管理技巧',
        'manage_technique_desc' => '管理和發布本週的技巧影片。'
    ]
];
$lang = $_SESSION['lang'] ?? 'en';


// --- START AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    switch ($action) {
        case 'book_class':
            $class_id = $_POST['class_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';
            $check_sql = "SELECT m.end_date, m.class_credits, m.membership_type FROM memberships m WHERE m.user_id = ? AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = ?)";
            $stmt_check = mysqli_prepare($link, $check_sql);
            mysqli_stmt_bind_param($stmt_check, "ii", $user_id, $user_id);
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
                echo json_encode(['success' => false, 'message' => 'Your membership is expired or you have no class credits.']);
                exit;
            }
            $capacity_sql = "SELECT c.capacity, COUNT(b.id) as current_bookings FROM classes c LEFT JOIN bookings b ON c.id = b.class_id AND b.booking_date = ? WHERE c.id = ? GROUP BY c.id";
            $stmt_cap = mysqli_prepare($link, $capacity_sql);
            mysqli_stmt_bind_param($stmt_cap, "si", $booking_date, $class_id);
            mysqli_stmt_execute($stmt_cap);
            $class_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_cap));
            mysqli_stmt_close($stmt_cap);
            if ($class_info && $class_info['current_bookings'] >= $class_info['capacity']) {
                echo json_encode(['success' => false, 'message' => 'This class is already full.']);
                exit;
            }
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
                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully!']);
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($link);
                error_log("Cancellation failed: " . $exception->getMessage());
                echo json_encode(['success' => false, 'message' => 'An error occurred during cancellation.']);
            }
            break;

        case 'mark_as_read':
            $recipient_id = $_POST['recipient_id'] ?? 0;
            if ($recipient_id > 0) {
                $sql_update = "UPDATE message_recipients SET is_read = 1, read_at = NOW() WHERE id = ? AND recipient_id = ?";
                if ($stmt_update = mysqli_prepare($link, $sql_update)) {
                    mysqli_stmt_bind_param($stmt_update, "ii", $recipient_id, $user_id);
                    mysqli_stmt_execute($stmt_update);
                    mysqli_stmt_close($stmt_update);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error.']);
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

// --- Fetch Coach's Inbox Messages ---
$messages = [];
$unread_count = 0;
$sql_messages = "SELECT 
                    mr.id as recipient_id, 
                    m.id as message_id, 
                    m.subject, 
                    m.body, 
                    m.created_at, 
                    CONCAT(u.first_name, ' ', u.last_name) as sender_name,
                    mr.is_read
                 FROM message_recipients mr
                 JOIN messages m ON mr.message_id = m.id
                 JOIN users u ON m.sender_id = u.id
                 WHERE mr.recipient_id = ?
                 ORDER BY m.created_at DESC";

if ($stmt_messages = mysqli_prepare($link, $sql_messages)) {
    mysqli_stmt_bind_param($stmt_messages, "i", $user_id);
    mysqli_stmt_execute($stmt_messages);
    $result_messages = mysqli_stmt_get_result($stmt_messages);
    while ($row = mysqli_fetch_assoc($result_messages)) {
        $messages[] = $row;
        if (!$row['is_read']) {
            $unread_count++;
        }
    }
    mysqli_stmt_close($stmt_messages);
}

// --- Fetch Class Schedule for Booking Section ---
$schedule = ['Monday' => [], 'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [], 'Saturday' => [], 'Sunday' => []];
$member_details_sql = "SELECT member_type FROM users WHERE id = ?";
$member_age_group = 'Adult';
if ($stmt_member = mysqli_prepare($link, $member_details_sql)) {
    mysqli_stmt_bind_param($stmt_member, "i", $_SESSION['id']);
    mysqli_stmt_execute($stmt_member);
    $result_member = mysqli_stmt_get_result($stmt_member);
    $member_details = mysqli_fetch_assoc($result_member);
    if ($member_details && isset($member_details['member_type']) && stripos($member_details['member_type'], 'kid') !== false) {
        $member_age_group = 'Kid';
    }
    mysqli_stmt_close($stmt_member);
}
$upcoming_dates = [];
$start_of_week_dt = new DateTime('monday this week');
for ($i = 0; $i < 7; $i++) {
    $date = (clone $start_of_week_dt)->modify('+' . $i . ' days');
    $day_name = $date->format('l');
    $upcoming_dates[$day_name] = $date->format('Y-m-d');
}
$schedule_sql = "SELECT c.id AS class_id, c.name, c.name_zh, c.day_of_week, c.start_time, c.end_time, c.capacity, u.first_name AS coach_first_name, u.last_name AS coach_last_name, b_user.id AS user_booking_id, (SELECT COUNT(*) FROM bookings WHERE class_id = c.id AND booking_date = ?) AS total_bookings_count FROM classes c LEFT JOIN users u ON c.coach_id = u.id LEFT JOIN bookings b_user ON c.id = b_user.class_id AND b_user.user_id = ? AND b_user.booking_date = ? WHERE c.is_active = 1 AND c.age = ? AND c.day_of_week = ? ORDER BY c.start_time";
foreach ($schedule as $day => &$classes_for_day) {
    $booking_date_for_day = $upcoming_dates[$day];
    if ($stmt_schedule = mysqli_prepare($link, $schedule_sql)) {
        mysqli_stmt_bind_param($stmt_schedule, "sisss", $booking_date_for_day, $_SESSION['id'], $booking_date_for_day, $member_age_group, $day);
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
$days_zh = ['Monday' => '星期一', 'Tuesday' => '星期二', 'Wednesday' => '星期三', 'Thursday' => '星期四', 'Friday' => '星期五', 'Saturday' => '星期六', 'Sunday' => '星期日'];
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

mysqli_close($link);
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Coach Dashboard - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .tab-active { background-color: #3b82f6; color: white; border-color: #3b82f6; }
        .timetable-container::-webkit-scrollbar { width: 8px; height: 8px; }
        .timetable-container::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        .timetable-container::-webkit-scrollbar-thumb { background: #888; border-radius: 10px; }
        .timetable-container::-webkit-scrollbar-thumb:hover { background: #555; }
        .class-item { cursor: pointer; }
        .class-item.bg-blue-100 { border-left: 4px solid #3b82f6; }
        .class-item.bg-gray-100 { border-left: 4px solid #d1d5db; }
        .mobile-menu-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 40; display: none; }
        .mobile-menu { position: fixed; top: 0; right: 0; width: 70%; height: 100%; background-color: white; z-index: 50; transform: translateX(100%); transition: transform 0.3s ease-out; padding: 1.5rem; box-shadow: -2px 0 5px rgba(0,0,0,0.2); display: flex; flex-direction: column; align-items: flex-start; }
        .mobile-menu.open { transform: translateX(0); }
        .mobile-menu-item { width: 100%; padding: 0.75rem 0; border-bottom: 1px solid #eee; text-align: left; }
        .mobile-menu-item:last-child { border-bottom: none; }
        .prose img { max-width: 100%; height: auto; border-radius: 0.5rem; }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800 lang" data-lang-en="Coach Portal" data-lang-zh="教練門戶">Coach Portal</span>
            <div class="flex items-center space-x-6">
                <a href="../news.php" class="text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="News" data-lang-zh="最新消息">News</a>
                <a href="../events.php" class="text-gray-700 hover:text-blue-600 font-medium lang" data-lang-en="Events" data-lang-zh="活動">Events</a>
                <button id="inbox-btn" class="relative text-gray-700 hover:text-blue-600 font-medium">
                    <span class="lang" data-lang-en="Inbox" data-lang-zh="收件匣">Inbox</span>
                    <?php if ($unread_count > 0): ?>
                    <span class="absolute -top-2 -right-3 flex h-5 w-5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                        <span id="unread-badge" class="relative inline-flex rounded-full h-5 w-5 bg-red-500 text-white text-xs justify-center items-center"><?php echo $unread_count; ?></span>
                    </span>
                    <?php endif; ?>
                </button>
                <div id="lang-switcher-desktop" class="flex items-center space-x-2 text-sm border-l pl-4">
                    <button id="lang-en" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Logout" data-lang-zh="登出">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <div id="main-dashboard">
            <h1 class="text-4xl font-black text-gray-800"><span class="lang" data-lang-en="Welcome, " data-lang-zh="歡迎, ">Welcome, </span><?php echo htmlspecialchars($_SESSION["full_name"]); ?>!</h1>
            <p class="mt-2 text-lg text-gray-600 lang" data-lang-en="Manage your coaching operations from here." data-lang-zh="在此處管理您的教練運營。">Manage your coaching operations from here.</p>
            
            <!-- Management Sections -->
            <div class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="Class Booking" data-lang-zh="課程預約">Class Booking</h2>
                    <p class="text-gray-600 mt-1">Book or cancel your spot in a class.</p>
                    <button id="open-booking-modal-btn" class="mt-4 inline-block bg-green-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-green-700 transition lang" data-lang-en="Book a Class" data-lang-zh="預約課程">
                        Book a Class
                    </button>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="Training Log" data-lang-zh="訓練日誌">Training Log</h2>
                    <p class="text-gray-600 mt-1">View past class attendance and member history.</p>
                    <a href="../training_log.php" class="mt-4 inline-block bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="View Training Log" data-lang-zh="查看訓練日誌">
                        View Training Log
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="Member Directory" data-lang-zh="會員目錄">Member Directory</h2>
                    <p class="text-gray-600 mt-1">View and update member information.</p>
                    <a href="member_directory.php" class="mt-4 inline-block bg-purple-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Go to Member Management" data-lang-zh="前往會員管理">
                        Go to Member Management
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="View Attendance" data-lang-zh="查看出席">View Attendance</h2>
                    <p class="text-gray-600 mt-1">View weekly class attendance for your classes.</p>
                    <a href="weekly_view.php" class="mt-4 inline-block bg-yellow-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-yellow-600 transition lang" data-lang-en="View Attendance" data-lang-zh="查看出席">
                        View Attendance
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="Coach Schedule" data-lang-zh="教練時間表">Coach Schedule</h2>
                    <p class="text-gray-600 mt-1">Set your weekly availability for 1-to-1 classes.</p>
                    <a href="coach_schedule.php" class="mt-4 inline-block bg-red-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-red-600 transition lang" data-lang-en="Manage Availability" data-lang-zh="管理可用性">
                        Manage Availability
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="Technique of the Week" data-lang-zh="本週技巧">Technique of the Week</h2>
                    <p class="text-gray-600 mt-1 lang" data-lang-en="Manage and post the technique of the week video." data-lang-zh="管理和發布本週的技巧影片。">Manage and post the technique of the week video.</p>
                    <a href="manage_technique.php" class="mt-4 inline-block bg-cyan-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-cyan-600 transition lang" data-lang-en="Manage Techniques" data-lang-zh="管理技巧">
                        Manage Techniques
                    </a>
                </div>
            </div>
        </div>

        <!-- NEW: Inbox Section -->
        <div id="inbox-section" class="mt-8 hidden">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-3xl font-bold text-gray-800 lang" data-lang-en="Inbox" data-lang-zh="收件匣">Inbox</h2>
                <button id="back-to-dashboard-btn" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <span class="lang" data-lang-en="&larr; Back to Dashboard" data-lang-zh="&larr; 返回儀表板">&larr; Back to Dashboard</span>
                </button>
            </div>
            <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
                <div class="flex h-[70vh]">
                    <!-- Message List -->
                    <div class="w-full md:w-1/3 border-r border-gray-200 flex flex-col">
                        <div id="message-list" class="overflow-y-auto flex-grow">
                            <?php if (empty($messages)): ?>
                                <p class="p-4 text-center text-gray-500 lang" data-lang-en="Your inbox is empty." data-lang-zh="您的收件匣是空的。">Your inbox is empty.</p>
                            <?php else: ?>
                                <?php foreach ($messages as $message): ?>
                                    <div class="message-item p-4 border-b hover:bg-gray-50 cursor-pointer <?php echo !$message['is_read'] ? 'bg-blue-50' : ''; ?>"
                                         data-recipient-id="<?php echo $message['recipient_id']; ?>"
                                         data-is-read="<?php echo $message['is_read']; ?>">
                                        <div class="flex justify-between items-start">
                                            <div class="flex-grow overflow-hidden">
                                                <p class="text-sm <?php echo !$message['is_read'] ? 'font-bold text-gray-900' : 'font-medium text-gray-600'; ?>"><?php echo htmlspecialchars($message['sender_name']); ?></p>
                                                <p class="truncate <?php echo !$message['is_read'] ? 'font-semibold text-gray-800' : 'text-gray-500'; ?>"><?php echo htmlspecialchars($message['subject']); ?></p>
                                            </div>
                                            <span class="text-xs text-gray-400 flex-shrink-0 ml-2"><?php echo date('M d', strtotime($message['created_at'])); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Message Viewer -->
                    <div id="message-viewer" class="w-2/3 p-6 hidden md:flex flex-col">
                        <div class="flex-grow flex items-center justify-center">
                            <p class="text-gray-400 lang" data-lang-en="Select a message to read" data-lang-zh="選擇一則訊息閱讀">Select a message to read</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Booking Modal -->
    <div id="booking-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <p class="text-2xl font-bold lang" data-lang-en="Weekly Timetable" data-lang-zh="每週時間表">Weekly Timetable</p>
                <button id="close-booking-modal-btn" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            
            <div class="mt-4">
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
                                            <div class="bg-gray-50 p-3 rounded-lg text-sm shadow-md border-l-4 <?php echo $class['user_booking_id'] ? 'border-green-500' : 'border-blue-500'; ?>">
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
                                                    <?php else: ?>
                                                        <button class="w-full bg-blue-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-blue-600 transition book-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Book Class" data-lang-zh="預約課程">Book Class</span>
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
                                            <div class="bg-white p-3 rounded-lg text-sm shadow-md border-l-4 <?php echo $class['user_booking_id'] ? 'border-green-500' : 'border-blue-500'; ?>">
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
                                                    <?php else: ?>
                                                        <button class="w-full bg-blue-500 text-white text-xs font-bold py-2 px-2 rounded hover:bg-blue-600 transition book-btn" data-class-id="<?php echo $class['class_id']; ?>" data-booking-date="<?php echo $upcoming_dates[$day]; ?>">
                                                            <span class="lang" data-lang-en="Book Class" data-lang-zh="預約課程">Book Class</span>
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
    </div>
    
    <!-- Status Message Modal -->
    <div id="status-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden modal">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div id="status-modal-content" class="text-center py-4">
            </div>
            <div class="text-center">
                <button id="close-status-modal" class="bg-blue-500 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-600 transition lang" data-lang-en="OK" data-lang-zh="好的">OK</button>
            </div>
        </div>
    </div>


    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const openBookingModalBtn = document.getElementById('open-booking-modal-btn');
            const bookingModal = document.getElementById('booking-modal');
            const closeBookingModalBtn = document.getElementById('close-booking-modal-btn');
            const bookingSection = document.getElementById('booking-modal');
            const statusModal = document.getElementById('status-modal');
            const closeStatusModalBtn = document.getElementById('close-status-modal');
            const inboxBtn = document.getElementById('inbox-btn');
            const inboxSection = document.getElementById('inbox-section');
            const mainDashboard = document.getElementById('main-dashboard');
            const messageList = document.getElementById('message-list');
            const messageViewer = document.getElementById('message-viewer');
            const unreadBadge = document.getElementById('unread-badge');
            const backToDashboardBtn = document.getElementById('back-to-dashboard-btn');
            let messages = <?php echo json_encode($messages); ?>;

            const translations = <?php echo json_encode($translations); ?>;
            let currentLang = localStorage.getItem('coachLang') || 'en';

            function updateLanguage() {
                document.querySelectorAll('.lang').forEach(el => {
                    const text = el.getAttribute('data-lang-' + currentLang);
                    if (text) el.textContent = text;
                });
                document.getElementById('lang-en').classList.toggle('font-bold', currentLang === 'en');
                document.getElementById('lang-en').classList.toggle('text-blue-600', currentLang === 'en');
                document.getElementById('lang-zh').classList.toggle('font-bold', currentLang === 'zh');
                document.getElementById('lang-zh').classList.toggle('text-blue-600', currentLang === 'zh');
            }

            function setLanguage(lang) {
                currentLang = lang;
                localStorage.setItem('coachLang', lang);
                updateLanguage();
            }

            document.getElementById('lang-en').addEventListener('click', () => setLanguage('en'));
            document.getElementById('lang-zh').addEventListener('click', () => setLanguage('zh'));
            
            openBookingModalBtn.addEventListener('click', () => bookingModal.classList.remove('hidden'));
            closeBookingModalBtn.addEventListener('click', () => bookingModal.classList.add('hidden'));
            closeStatusModalBtn.addEventListener('click', () => statusModal.classList.add('hidden'));

            function showStatus(message, isSuccess) {
                const content = document.getElementById('status-modal-content');
                content.innerHTML = `<p class="text-lg font-medium ${isSuccess ? 'text-green-600' : 'text-red-600'}">${message}</p>`;
                statusModal.classList.remove('hidden');
            }

            bookingSection.addEventListener('click', function(e) {
                const target = e.target.closest('.book-btn, .cancel-btn');
                if (!target) return;

                const isBooking = target.classList.contains('book-btn');
                const action = isBooking ? 'book_class' : 'cancel_class';
                const classId = target.dataset.classId;
                const bookingDate = target.dataset.bookingDate;
                
                const enMsg = isBooking ? 'Are you sure you want to book this class?' : 'Are you sure you want to cancel this booking?';
                const zhMsg = isBooking ? '您確定要預約這堂課嗎？' : '您確定要取消這個預約嗎？';
                const confirmationMessage = currentLang === 'zh' ? zhMsg : enMsg;

                if (confirm(confirmationMessage)) {
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
            
            inboxBtn.addEventListener('click', () => {
                mainDashboard.classList.add('hidden');
                inboxSection.classList.remove('hidden');
            });

            backToDashboardBtn.addEventListener('click', () => {
                inboxSection.classList.add('hidden');
                mainDashboard.classList.remove('hidden');
            });

            messageList.addEventListener('click', (e) => {
                const item = e.target.closest('.message-item');
                if (!item) return;

                const recipientId = item.dataset.recipientId;
                const isRead = item.dataset.isRead === '1';
                
                const messageData = messages.find(m => m.recipient_id == recipientId);

                if (messageData) {
                    const formattedDate = new Date(messageData.created_at).toLocaleString('en-US', { dateStyle: 'long', timeStyle: 'short' });
                    messageViewer.innerHTML = `
                        <div class="flex-shrink-0 pb-4 border-b flex justify-between items-center">
                            <div>
                                <h3 class="text-2xl font-bold text-gray-900">${messageData.subject}</h3>
                                <p class="text-sm text-gray-500 mt-1">From: <strong>${messageData.sender_name}</strong> on ${formattedDate}</p>
                            </div>
                            <button class="delete-message-btn text-red-500 hover:text-red-700 font-semibold text-sm" data-recipient-id="${recipientId}">
                                <span class="lang" data-lang-en="Delete" data-lang-zh="刪除">Delete</span>
                            </button>
                        </div>
                        <div class="flex-grow overflow-y-auto mt-4 prose max-w-none">
                            ${messageData.body}
                        </div>
                    `;
                    updateLanguage(); // Update text for the delete button

                    if (!isRead) {
                        item.classList.remove('bg-blue-50');
                        item.querySelector('p:first-child').classList.remove('font-bold', 'text-gray-900');
                        item.querySelector('p:first-child').classList.add('font-medium', 'text-gray-600');
                        item.querySelector('p:last-child').classList.remove('font-semibold', 'text-gray-800');
                        item.querySelector('p:last-child').classList.add('text-gray-500');
                        item.dataset.isRead = '1';

                        const formData = new FormData();
                        formData.append('action', 'mark_as_read');
                        formData.append('recipient_id', recipientId);

                        fetch(window.location.pathname, {
                            method: 'POST',
                            body: formData
                        }).then(res => res.json()).then(data => {
                            if (data.success && unreadBadge) {
                                let currentCount = parseInt(unreadBadge.textContent);
                                currentCount--;
                                unreadBadge.textContent = currentCount;
                                if (currentCount <= 0) {
                                    unreadBadge.parentElement.classList.add('hidden');
                                }
                            }
                        });
                    }
                }
            });

            messageViewer.addEventListener('click', async (e) => {
                const deleteBtn = e.target.closest('.delete-message-btn');
                if (!deleteBtn) return;

                const recipientId = deleteBtn.dataset.recipientId;
                const confirmMsg = translations[currentLang]['confirm_delete'];

                if (confirm(confirmMsg)) {
                    const formData = new FormData();
                    formData.append('action', 'delete_message');
                    formData.append('recipient_id', recipientId);

                    const result = await fetch(window.location.pathname, {
                        method: 'POST',
                        body: formData
                    }).then(res => res.json());

                    if (result.success) {
                        // Remove from UI
                        const itemToRemove = messageList.querySelector(`.message-item[data-recipient-id="${recipientId}"]`);
                        if (itemToRemove) itemToRemove.remove();
                        
                        // Remove from local cache
                        messages = messages.filter(m => m.recipient_id != recipientId);

                        messageViewer.innerHTML = `<div class="flex-grow flex items-center justify-center"><p class="text-gray-400 lang" data-lang-en="Select a message to read" data-lang-zh="選擇一則訊息閱讀">Select a message to read</p></div>`;
                        updateLanguage();
                    } else {
                        alert(result.message);
                    }
                }
            });

            updateLanguage();
        });
    </script>
</body>
</html>
