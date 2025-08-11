<?php
// Start the session
session_start();

// Include the database configuration file.
require_once "../db_config.php";

// Check if the user is logged in and has the 'coach' role.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'coach') {
    header("location: ../login.html");
    exit;
}

$coach_id = $_SESSION["id"];
$coach_full_name = $_SESSION["full_name"];
$message = "";
$message_class = "";

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'portal_title' => 'Coach Portal',
        'logout' => 'Logout',
        'set_availability_title' => 'Set Your Availability',
        'set_availability_text' => 'Select the time slots you are available for 1-to-1 classes.',
        'schedule_saved' => 'Your schedule has been successfully saved!',
        'schedule_cleared' => 'Your schedule has been cleared.',
        'confirm_clear_schedule' => 'Are you sure you want to clear your entire schedule?',
        'reset_button' => 'Reset Schedule',
        'save_button' => 'Save Schedule',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
        'pending_requests_title' => 'Pending Booking Requests',
        'no_requests' => 'You have no pending booking requests.',
        'member' => 'Member',
        'date' => 'Date',
        'time' => 'Time',
        'actions' => 'Actions',
        'confirm_button' => 'Confirm',
        'cancel_button' => 'Cancel',
        'request_confirmed' => 'Booking request confirmed!',
        'request_cancelled' => 'Booking request cancelled!',
        'confirm_request_modal_title' => 'Confirm Request',
        'confirm_request_modal_message' => 'Are you sure you want to confirm the booking for {member_name}?',
        'confirm_cancel_modal_title' => 'Cancel Request',
        'confirm_cancel_modal_message' => 'Are you sure you want to cancel the booking for {member_name}?',
        'ok' => 'OK',
        'booked_by' => 'Booked by: ',
        'set_price' => 'Set Price per hour:',
        'twd_symbol' => 'TWD',
        'available' => 'Available',
        'booked' => 'Booked',
        'pending' => 'Pending',
        'class' => 'Class',
        'class_scheduled' => 'Class Scheduled'
    ],
    'zh' => [
        'portal_title' => '教練門戶',
        'logout' => '登出',
        'set_availability_title' => '設置您的可用時間',
        'set_availability_text' => '選擇您可以進行一對一課程的時間段。',
        'schedule_saved' => '您的時間表已成功保存！',
        'schedule_cleared' => '您的時間表已清空。',
        'confirm_clear_schedule' => '您確定要清空您的所有時間表嗎？',
        'reset_button' => '重置時間表',
        'save_button' => '保存時間表',
        'monday' => '星期一',
        'tuesday' => '星期二',
        'wednesday' => '星期三',
        'thursday' => '星期四',
        'friday' => '星期五',
        'saturday' => '星期六',
        'sunday' => '星期日',
        'pending_requests_title' => '待處理預約請求',
        'no_requests' => '您沒有待處理的預約請求。',
        'member' => '會員',
        'date' => '日期',
        'time' => '時間',
        'actions' => '操作',
        'confirm_button' => '確認',
        'cancel_button' => '取消',
        'request_confirmed' => '預約請求已確認！',
        'request_cancelled' => '預約請求已取消！',
        'confirm_request_modal_title' => '確認請求',
        'confirm_request_modal_message' => '您確定要確認 {member_name} 的預約嗎？',
        'confirm_cancel_modal_title' => '取消請求',
        'confirm_cancel_modal_message' => '您確定要取消 {member_name} 的預約嗎？',
        'ok' => '好的',
        'booked_by' => '已預約：',
        'set_price' => '設定每小時費用：',
        'twd_symbol' => '新台幣',
        'available' => '可用',
        'booked' => '已預約',
        'pending' => '待確認',
        'class' => '課程',
        'class_scheduled' => '課程已安排'
    ]
];
// Initialize language - prioritize user selection over session
$lang = $_SESSION['lang'] ?? 'en';

// Check if there's a language preference in the request (from JavaScript)
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'])) {
    $lang = $_GET['lang'];
    $_SESSION['lang'] = $lang;
}
$days_zh = ['Monday' => '星期一', 'Tuesday' => '星期二', 'Wednesday' => '星期三', 'Thursday' => '星期四', 'Friday' => '星期五', 'Saturday' => '星期六', 'Sunday' => '星期日'];

// --- START AJAX HANDLER ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    switch ($action) {
        case 'save_schedule':
            $availability_data = json_decode($_POST['availability'], true);
            $price = floatval($_POST['price'] ?? 0);
            
            $sql_delete = "DELETE FROM coach_availability WHERE coach_id = ?";
            if ($stmt_delete = mysqli_prepare($link, $sql_delete)) {
                mysqli_stmt_bind_param($stmt_delete, "i", $coach_id);
                mysqli_stmt_execute($stmt_delete);
                mysqli_stmt_close($stmt_delete);
            }
            
            if (!empty($availability_data)) {
                $sql_insert = "INSERT INTO coach_availability (coach_id, day_of_week, start_time, end_time, price, is_available) VALUES (?, ?, ?, ?, ?, 1)";
                if ($stmt_insert = mysqli_prepare($link, $sql_insert)) {
                    foreach ($availability_data as $slot) {
                        mysqli_stmt_bind_param($stmt_insert, "isssd", $coach_id, $slot['day'], $slot['start'], $slot['end'], $price);
                        mysqli_stmt_execute($stmt_insert);
                    }
                    mysqli_stmt_close($stmt_insert);
                    echo json_encode(['success' => true, 'message' => $translations[$lang]['schedule_saved']]);
                }
            } else {
                echo json_encode(['success' => true, 'message' => $translations[$lang]['schedule_cleared']]);
            }
            break;

        case 'update_booking_status':
            $booking_id = $_POST['booking_id'] ?? 0;
            $new_status = $_POST['status'] ?? 'pending';
            if ($booking_id && in_array($new_status, ['confirmed', 'cancelled'])) {
                $sql_update = "UPDATE one_to_one_bookings SET status = ? WHERE id = ? AND coach_id = ?";
                if ($stmt_update = mysqli_prepare($link, $sql_update)) {
                    mysqli_stmt_bind_param($stmt_update, "sii", $new_status, $booking_id, $coach_id);
                    if (mysqli_stmt_execute($stmt_update)) {
                         // --- SEND EMAIL NOTIFICATION TO MEMBER ---
                        $member_info_sql = "SELECT first_name, last_name, email FROM one_to_one_bookings b JOIN users u ON b.member_id = u.id WHERE b.id = ?";
                        if ($stmt_member_info = mysqli_prepare($link, $member_info_sql)) {
                            mysqli_stmt_bind_param($stmt_member_info, "i", $booking_id);
                            mysqli_stmt_execute($stmt_member_info);
                            $member_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_member_info));
                            mysqli_stmt_close($stmt_member_info);
                            
                            if ($member_data) {
                                $to = $member_data['email'];
                                $subject = "Your 1-on-1 Booking Request Status Update";
                                $headers = "From: webmaster@yourdomain.com\r\n";
                                $headers .= "MIME-Version: 1.0\r\n";
                                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                                
                                $status_message_en = ($new_status === 'confirmed') ? 'has been confirmed!' : 'has been cancelled.';
                                $status_message_zh = ($new_status === 'confirmed') ? '已確認！' : '已取消。';
                                $status_message = $lang === 'zh' ? $status_message_zh : $status_message_en;
                                
                                $message_body = "
                                    <html>
                                    <head><title>Booking Status Update</title></head>
                                    <body>
                                        <p>Hello {$member_data['first_name']},</p>
                                        <p>Your 1-on-1 class booking with {$coach_full_name} {$status_message}</p>
                                        <p>Thank you,<br>Catch Jiu Jitsu</p>
                                    </body>
                                    </html>
                                ";
                                mail($to, $subject, $message_body, $headers);
                            }
                        }
                         // --- END EMAIL NOTIFICATION ---
                        echo json_encode(['success' => true, 'message' => $new_status === 'confirmed' ? $translations[$lang]['request_confirmed'] : $translations[$lang]['request_cancelled']]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Database error.']);
                    }
                    mysqli_stmt_close($stmt_update);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---


// --- Fetch existing availability for the current coach ---
$existing_availability = [];
// CORRECTION: Also fetch the price for display
$sql_fetch_avail = "SELECT day_of_week, start_time, end_time, price FROM coach_availability WHERE coach_id = ? AND is_available = 1";
$price = 0.00;
if ($stmt_fetch_avail = mysqli_prepare($link, $sql_fetch_avail)) {
    mysqli_stmt_bind_param($stmt_fetch_avail, "i", $coach_id);
    mysqli_stmt_execute($stmt_fetch_avail);
    $result_fetch_avail = mysqli_stmt_get_result($stmt_fetch_avail);
    while ($row = mysqli_fetch_assoc($result_fetch_avail)) {
        $existing_availability[] = $row;
        $price = $row['price']; // Set price from the first row found, assuming all are the same
    }
    mysqli_stmt_close($stmt_fetch_avail);
}

// --- Fetch all booking requests for the current coach ---
$bookings = [];
$sql_bookings = "SELECT b.id, b.booking_date, b.start_time, b.status, u.first_name, u.last_name, u.email
                 FROM one_to_one_bookings b
                 JOIN users u ON b.member_id = u.id
                 WHERE b.coach_id = ?
                 ORDER BY b.booking_date, b.start_time";
if ($stmt_bookings = mysqli_prepare($link, $sql_bookings)) {
    mysqli_stmt_bind_param($stmt_bookings, "i", $coach_id);
    mysqli_stmt_execute($stmt_bookings);
    $result_bookings = mysqli_stmt_get_result($stmt_bookings);
    while ($row = mysqli_fetch_assoc($result_bookings)) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $bookings[] = $row;
    }
    mysqli_stmt_close($stmt_bookings);
}

// --- Fetch all classes for the current coach to prevent double bookings ---
$classes = [];
$sql_classes = "SELECT c.id, c.name, c.name_zh, c.day_of_week, c.start_time, c.end_time, c.is_active, c.capacity, c.age
                FROM classes c 
                WHERE c.coach_id = ? AND c.is_active = 1
                ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), c.start_time";
if ($stmt_classes = mysqli_prepare($link, $sql_classes)) {
    mysqli_stmt_bind_param($stmt_classes, "i", $coach_id);
    mysqli_stmt_execute($stmt_classes);
    $result_classes = mysqli_stmt_get_result($stmt_classes);
    while ($row = mysqli_fetch_assoc($result_classes)) {
        $classes[] = $row;
    }
    mysqli_stmt_close($stmt_classes);
}

mysqli_close($link);

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$time_slots = [];
for ($i = 8; $i < 22; $i++) {
    $start_hour = str_pad($i, 2, '0', STR_PAD_LEFT);
    $end_hour = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
    $time_slots[] = [
        'start' => "{$start_hour}:00:00",
        'end' => "{$end_hour}:00:00",
        'label' => "{$start_hour}:00 - {$end_hour}:00"
    ];
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title>Coach Schedule - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .calendar-grid {
            grid-template-columns: repeat(8, minmax(0, 1fr));
        }
        .time-slot {
            height: 50px;
            transition: all 0.2s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 0.5rem;
            text-align: center;
            font-size: 0.75rem;
            font-weight: bold;
            color: white;
            padding: 0.5rem;
        }
        .time-slot.available {
            background-color: #d1fae5; /* green-100 */
            border-color: #34d399; /* green-400 */
            color: #10b981;
        }
        .time-slot.available:hover {
            background-color: #a7f3d0; /* green-200 */
        }
        .time-slot.pending {
            background-color: #f59e0b; /* amber-500 */
            color: #fff;
        }
        .time-slot.confirmed {
            background-color: #1e40af; /* blue-800 */
            color: #fff;
        }
        .time-slot.class {
            background-color: #7c3aed; /* violet-600 */
            color: #fff;
        }
        .time-slot.default {
            background-color: #f3f4f6; /* gray-100 */
        }
        .time-label {
            font-size: 0.875rem; /* text-sm */
            font-weight: 500;
            color: #6b7280; /* text-gray-500 */
        }
        .time-slot-inner {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800"><?php echo $translations[$lang]['portal_title']; ?></span>
            <div class="flex items-center space-x-4">
                <!-- Language Switcher -->
                <div class="flex items-center space-x-2 text-sm border-l pl-4">
                    <button id="lang-en" class="font-bold text-blue-600">EN</button>
                    <span class="text-gray-300">|</span>
                    <button id="lang-zh" class="font-normal text-gray-500 hover:text-blue-600">中文</button>
                </div>
                <a href="../coach/coach_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition lang" data-lang-en="Back to Dashboard" data-lang-zh="返回儀表板">
                    Back to Dashboard
                </a>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition lang" data-lang-en="Logout" data-lang-zh="登出">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800"><?php echo $translations[$lang]['set_availability_title']; ?></h1>
        <p class="mt-2 text-lg text-gray-600"><?php echo $translations[$lang]['set_availability_text']; ?></p>

        <?php if (!empty($message)): ?>
            <div class="mt-4 p-4 text-sm rounded-lg <?php echo $message_class; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

                 <div class="bg-white p-8 rounded-2xl shadow-lg mt-8">
             <h2 class="text-2xl font-bold text-gray-800 mb-4"><?php echo $translations[$lang]['set_availability_title']; ?></h2>
             <p class="text-gray-600 mb-4"><?php echo $translations[$lang]['set_availability_text']; ?></p>
             


            <form id="schedule-form" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <input type="hidden" name="action" value="save_schedule">
                <input type="hidden" name="availability" id="availability_input">

                <div class="mb-6 flex items-center space-x-4">
                    <label for="price_input" class="text-lg font-medium text-gray-700"><?php echo $translations[$lang]['set_price']; ?></label>
                    <div class="relative rounded-md shadow-sm">
                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <span class="text-gray-500 sm:text-sm">TWD</span>
                        </div>
                        <input type="number" name="price" id="price_input" value="<?php echo htmlspecialchars($price); ?>" min="0" step="100" class="block w-full rounded-md border-gray-300 pl-12 pr-2 py-2 text-lg text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                    </div>
                </div>

                <div class="grid calendar-grid gap-1">
                    <!-- Day Headers -->
                    <div class="p-2 text-sm font-bold text-gray-600"></div>
                    <?php foreach ($days as $day): ?>
                        <div class="p-2 text-center text-sm font-bold text-gray-600">
                             <?php echo $translations[$lang][strtolower($day)]; ?>
                        </div>
                    <?php endforeach; ?>

                    <!-- Time Slots -->
                    <?php foreach ($time_slots as $slot): ?>
                        <div class="p-2 text-sm text-gray-500 font-semibold time-label flex items-center justify-end pr-2 border-r border-gray-200">
                            <?php echo $slot['label']; ?>
                        </div>
                        <?php foreach ($days as $day): ?>
                            <?php
                                $is_available = false;
                                foreach ($existing_availability as $avail_slot) {
                                    if ($avail_slot['day_of_week'] === $day && $avail_slot['start_time'] === $slot['start'] && $avail_slot['end_time'] === $slot['end']) {
                                        $is_available = true;
                                        break;
                                    }
                                }
                                
                                $booking_info = null;
                                foreach ($bookings as $booking) {
                                    $booking_day_of_week = date('l', strtotime($booking['booking_date']));
                                    if ($booking_day_of_week === $day && $booking['start_time'] === $slot['start']) {
                                        $booking_info = $booking;
                                        break;
                                    }
                                }

                                // Check if there's a class at this time slot
                                $class_info = null;
                                foreach ($classes as $class) {
                                    if ($class['day_of_week'] === $day && $class['start_time'] === $slot['start']) {
                                        $class_info = $class;
                                        break;
                                    }
                                }

                                $slot_class = 'default';
                                $slot_content = '';
                                $is_clickable = true;

                                if ($booking_info) {
                                    $slot_class = $booking_info['status'];
                                    $slot_content = $booking_info['full_name'];
                                    $is_clickable = false;
                                } elseif ($class_info) {
                                    $slot_class = 'class';
                                    $slot_content = $lang === 'zh' ? $class_info['name_zh'] : $class_info['name'];
                                    $is_clickable = false;
                                } elseif ($is_available) {
                                    $slot_class = 'available';
                                    $slot_content = $translations[$lang]['available'];
                                    $is_clickable = true;
                                }
                            ?>
                            <div class="time-slot border border-gray-200 <?php echo $slot_class; ?>"
                                 data-day="<?php echo $day; ?>"
                                 data-start="<?php echo $slot['start']; ?>"
                                 data-end="<?php echo $slot['end']; ?>"
                                 data-is-clickable="<?php echo $is_clickable ? 'true' : 'false'; ?>">
                                 <?php if ($slot_content): ?>
                                     <span class="text-xs <?php echo $is_clickable ? 'text-gray-700' : ''; ?> <?php echo ($slot_class === 'available' || $slot_class === 'class') ? 'lang' : ''; ?>" <?php echo ($slot_class === 'available') ? 'data-lang-en="' . $translations['en']['available'] . '" data-lang-zh="' . $translations['zh']['available'] . '"' : ''; ?> <?php echo ($slot_class === 'class' && $class_info) ? 'data-lang-en="' . htmlspecialchars($class_info['name']) . '" data-lang-zh="' . htmlspecialchars($class_info['name_zh']) . '"' : ''; ?>>
                                         <?php echo $slot_content; ?>
                                     </span>
                                 <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>

                <!-- Legend -->
                <div class="mt-6 flex flex-wrap gap-4 text-sm">
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-green-100 border border-green-400 rounded"></div>
                        <span class="lang" data-lang-en="Available for 1-on-1" data-lang-zh="可進行一對一">Available for 1-on-1</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-violet-600 rounded"></div>
                        <span class="lang" data-lang-en="Class Scheduled" data-lang-zh="課程已安排">Class Scheduled</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-blue-800 rounded"></div>
                        <span class="lang" data-lang-en="1-on-1 Confirmed" data-lang-zh="一對一已確認">1-on-1 Confirmed</span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <div class="w-4 h-4 bg-amber-500 rounded"></div>
                        <span class="lang" data-lang-en="1-on-1 Pending" data-lang-zh="一對一待確認">1-on-1 Pending</span>
                    </div>
                </div>

                <div class="mt-8 flex justify-end space-x-4">
                    <button type="button" id="reset-schedule" class="bg-gray-300 text-gray-800 font-bold py-2.5 px-6 rounded-lg hover:bg-gray-400 transition lang" data-lang-en="<?php echo $translations['en']['reset_button']; ?>" data-lang-zh="<?php echo $translations['zh']['reset_button']; ?>">
                        <?php echo $translations[$lang]['reset_button']; ?>
                    </button>
                    <button type="submit" id="save-schedule" class="bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition lang" data-lang-en="<?php echo $translations['en']['save_button']; ?>" data-lang-zh="<?php echo $translations['zh']['save_button']; ?>">
                        <?php echo $translations[$lang]['save_button']; ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Pending Requests Section -->
        <div class="bg-white p-8 rounded-2xl shadow-lg mt-12">
            <h2 class="text-2xl font-bold text-gray-800 lang" data-lang-en="<?php echo $translations['en']['pending_requests_title']; ?>" data-lang-zh="<?php echo $translations['zh']['pending_requests_title']; ?>"><?php echo $translations[$lang]['pending_requests_title']; ?></h2>
            <div class="mt-4">
                <?php 
                $pending_requests = array_filter($bookings, function($booking) {
                    return $booking['status'] === 'pending';
                });
                if (empty($pending_requests)): ?>
                    <p class="text-gray-600 lang" data-lang-en="<?php echo $translations['en']['no_requests']; ?>" data-lang-zh="<?php echo $translations['zh']['no_requests']; ?>"><?php echo $translations[$lang]['no_requests']; ?></p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="<?php echo $translations['en']['member']; ?>" data-lang-zh="<?php echo $translations['zh']['member']; ?>"><?php echo $translations[$lang]['member']; ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="<?php echo $translations['en']['date']; ?>" data-lang-zh="<?php echo $translations['zh']['date']; ?>"><?php echo $translations[$lang]['date']; ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="<?php echo $translations['en']['time']; ?>" data-lang-zh="<?php echo $translations['zh']['time']; ?>"><?php echo $translations[$lang]['time']; ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lang" data-lang-en="<?php echo $translations['en']['actions']; ?>" data-lang-zh="<?php echo $translations['zh']['actions']; ?>"><?php echo $translations[$lang]['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($pending_requests as $request): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['full_name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($request['booking_date']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date("g:i A", strtotime($request['start_time'])); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <button class="text-green-600 hover:text-green-800 font-semibold mr-4 confirm-request-btn lang" data-lang-en="<?php echo $translations['en']['confirm_button']; ?>" data-lang-zh="<?php echo $translations['zh']['confirm_button']; ?>"
                                                    data-id="<?php echo htmlspecialchars($request['id']); ?>"
                                                    data-member-name="<?php echo htmlspecialchars($request['full_name']); ?>">
                                                <?php echo $translations[$lang]['confirm_button']; ?>
                                            </button>
                                            <button class="text-red-600 hover:text-red-800 font-semibold cancel-request-btn lang" data-lang-en="<?php echo $translations['en']['cancel_button']; ?>" data-lang-zh="<?php echo $translations['zh']['cancel_button']; ?>"
                                                    data-id="<?php echo htmlspecialchars($request['id']); ?>"
                                                    data-member-name="<?php echo htmlspecialchars($request['full_name']); ?>">
                                                <?php echo $translations[$lang]['cancel_button']; ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="action-confirm-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <p id="modal-title" class="text-2xl font-bold"></p>
                <button id="close-action-confirm-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            <div class="mt-4">
                <p id="modal-message" class="text-gray-700"></p>
                <div class="mt-6 flex justify-end space-x-4">
                    <button id="modal-action-btn" class="text-white font-bold py-2 px-6 rounded-lg transition">
                    </button>
                    <button id="modal-cancel-btn" class="bg-gray-300 text-gray-800 font-bold py-2 px-6 rounded-lg hover:bg-gray-400 transition lang" data-lang-en="<?php echo $translations['en']['cancel_button']; ?>" data-lang-zh="<?php echo $translations['zh']['cancel_button']; ?>">
                        <?php echo $translations[$lang]['cancel_button']; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Message Modal -->
    <div id="status-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div id="status-modal-content" class="text-center py-4">
            </div>
            <div class="text-center">
                <button id="close-status-modal" class="bg-blue-500 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-600 transition lang" data-lang-en="<?php echo $translations['en']['ok']; ?>" data-lang-zh="<?php echo $translations['zh']['ok']; ?>">
                    <?php echo $translations[$lang]['ok']; ?>
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const timeSlots = document.querySelectorAll('.time-slot');
            const availabilityInput = document.getElementById('availability_input');
            const resetButton = document.getElementById('reset_button');
            const actionConfirmModal = document.getElementById('action-confirm-modal');
            const modalTitle = document.getElementById('modal-title');
            const modalMessage = document.getElementById('modal-message');
            const modalActionBtn = document.getElementById('modal-action-btn');
            const closeActionConfirmModalBtn = document.getElementById('close-action-confirm-modal');
            const modalCancelBtn = document.getElementById('modal-cancel-btn');

            const statusModal = document.getElementById('status-modal');
            const closeStatusModalBtn = document.getElementById('close-status-modal');
            const priceInput = document.getElementById('price_input');
            const scheduleForm = document.getElementById('schedule-form');

            const translations = <?php echo json_encode($translations); ?>;
            const lang = '<?php echo $lang; ?>';
            
            let currentActionRequest = {};

            function updateAvailabilityInput() {
                const selectedSlots = [];
                document.querySelectorAll('.time-slot.available').forEach(slot => {
                    selectedSlots.push({
                        day: slot.getAttribute('data-day'),
                        start: slot.getAttribute('data-start'),
                        end: slot.getAttribute('data-end')
                    });
                });
                availabilityInput.value = JSON.stringify(selectedSlots);
            }

            timeSlots.forEach(slot => {
                slot.addEventListener('click', function() {
                    // Only allow toggling if the slot is not booked
                    if (this.dataset.isClickable === 'true') {
                        this.classList.toggle('available');
                        updateAvailabilityInput();
                    }
                });
            });

            resetButton.addEventListener('click', function() {
                if (confirm(translations[lang]['confirm_clear_schedule'])) {
                    timeSlots.forEach(slot => {
                        if (slot.dataset.isClickable === 'true') {
                            slot.classList.remove('available');
                        }
                    });
                    updateAvailabilityInput();
                }
            });
            
            // --- PENDING REQUESTS LOGIC ---
            document.querySelectorAll('.confirm-request-btn, .cancel-request-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const isConfirm = this.classList.contains('confirm-request-btn');
                    const bookingId = this.dataset.id;
                    const memberName = this.dataset.memberName;

                    modalTitle.textContent = isConfirm ? translations[lang]['confirm_request_modal_title'] : translations[lang]['confirm_cancel_modal_title'];
                    modalMessage.textContent = isConfirm ? translations[lang]['confirm_request_modal_message'].replace('{member_name}', memberName) : translations[lang]['confirm_cancel_modal_message'].replace('{member_name}', memberName);
                    
                    modalActionBtn.textContent = isConfirm ? translations[lang]['confirm_button'] : translations[lang]['cancel_button'];
                    modalActionBtn.className = `text-white font-bold py-2 px-6 rounded-lg transition ${isConfirm ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'}`;

                    currentActionRequest = {
                        id: bookingId,
                        status: isConfirm ? 'confirmed' : 'cancelled'
                    };

                    actionConfirmModal.classList.remove('hidden');
                });
            });

            modalActionBtn.addEventListener('click', function() {
                if (!currentActionRequest.id) return;
                
                const formData = new FormData();
                formData.append('action', 'update_booking_status');
                formData.append('booking_id', currentActionRequest.id);
                formData.append('status', currentActionRequest.status);

                fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    actionConfirmModal.classList.add('hidden');
                    showStatus(data.message, data.success);
                    if (data.success) {
                        setTimeout(() => {
                            window.location.reload();
                        }, 2000);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    actionConfirmModal.classList.add('hidden');
                    showStatus('A network error occurred.', false);
                });
            });
            
            closeActionConfirmModalBtn.addEventListener('click', () => {
                actionConfirmModal.classList.add('hidden');
            });
            modalCancelBtn.addEventListener('click', () => {
                actionConfirmModal.classList.add('hidden');
            });

            // --- PRICE INPUT & FORM SUBMISSION LOGIC ---
            priceInput.addEventListener('keypress', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    scheduleForm.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            });

            scheduleForm.addEventListener('submit', function(event) {
                event.preventDefault();
                
                const formData = new FormData(this);
                
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
                    showStatus('A network error occurred.', false);
                });
            });

            // --- GENERIC MODAL FUNCTIONS ---
            function showStatus(message, isSuccess) {
                const content = document.getElementById('status-modal-content');
                content.innerHTML = `<p class="text-lg font-medium ${isSuccess ? 'text-green-600' : 'text-red-600'}">${message}</p>`;
                statusModal.classList.remove('hidden');
            }

            closeStatusModalBtn.addEventListener('click', () => {
                statusModal.classList.add('hidden');
            });

            // Initial population of the hidden input
            updateAvailabilityInput();

            // --- LANGUAGE SWITCHING FUNCTIONALITY ---
            const translations = <?php echo json_encode($translations); ?>;
            let currentLang = localStorage.getItem('coachLang') || '<?php echo $lang; ?>';

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
                
                // Save language preference to server and reload page
                fetch('set_language.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `lang=${lang}`
                }).then(() => {
                    // Reload the page to apply the new language
                    window.location.reload();
                }).catch(error => {
                    console.error('Error saving language preference:', error);
                    // Still reload even if there's an error
                    window.location.reload();
                });
            }

            document.getElementById('lang-en').addEventListener('click', () => setLanguage('en'));
            document.getElementById('lang-zh').addEventListener('click', () => setLanguage('zh'));

            // Language translations for JavaScript
            const jsTranslations = {
                'en': {
                    'portal_title': '<?php echo $translations['en']['portal_title']; ?>',
                    'logout': '<?php echo $translations['en']['logout']; ?>',
                    'set_availability_title': '<?php echo $translations['en']['set_availability_title']; ?>',
                    'set_availability_text': '<?php echo $translations['en']['set_availability_text']; ?>',
                    'schedule_saved': '<?php echo $translations['en']['schedule_saved']; ?>',
                    'schedule_cleared': '<?php echo $translations['en']['schedule_cleared']; ?>',
                    'confirm_clear_schedule': '<?php echo $translations['en']['confirm_clear_schedule']; ?>',
                    'reset_button': '<?php echo $translations['en']['reset_button']; ?>',
                    'save_button': '<?php echo $translations['en']['save_button']; ?>',
                    'monday': '<?php echo $translations['en']['monday']; ?>',
                    'tuesday': '<?php echo $translations['en']['tuesday']; ?>',
                    'wednesday': '<?php echo $translations['en']['wednesday']; ?>',
                    'thursday': '<?php echo $translations['en']['thursday']; ?>',
                    'friday': '<?php echo $translations['en']['friday']; ?>',
                    'saturday': '<?php echo $translations['en']['saturday']; ?>',
                    'sunday': '<?php echo $translations['en']['sunday']; ?>',
                    'pending_requests_title': '<?php echo $translations['en']['pending_requests_title']; ?>',
                    'no_requests': '<?php echo $translations['en']['no_requests']; ?>',
                    'member': '<?php echo $translations['en']['member']; ?>',
                    'date': '<?php echo $translations['en']['date']; ?>',
                    'time': '<?php echo $translations['en']['time']; ?>',
                    'actions': '<?php echo $translations['en']['actions']; ?>',
                    'confirm_button': '<?php echo $translations['en']['confirm_button']; ?>',
                    'cancel_button': '<?php echo $translations['en']['cancel_button']; ?>',
                    'request_confirmed': '<?php echo $translations['en']['request_confirmed']; ?>',
                    'request_cancelled': '<?php echo $translations['en']['request_cancelled']; ?>',
                    'confirm_request_modal_title': '<?php echo $translations['en']['confirm_request_modal_title']; ?>',
                    'confirm_request_modal_message': '<?php echo $translations['en']['confirm_request_modal_message']; ?>',
                    'confirm_cancel_modal_title': '<?php echo $translations['en']['confirm_cancel_modal_title']; ?>',
                    'confirm_cancel_modal_message': '<?php echo $translations['en']['confirm_cancel_modal_message']; ?>',
                    'ok': '<?php echo $translations['en']['ok']; ?>',
                    'booked_by': '<?php echo $translations['en']['booked_by']; ?>',
                    'set_price': '<?php echo $translations['en']['set_price']; ?>',
                    'twd_symbol': '<?php echo $translations['en']['twd_symbol']; ?>',
                                         'available': '<?php echo $translations['en']['available']; ?>',
                     'booked': '<?php echo $translations['en']['booked']; ?>',
                     'pending': '<?php echo $translations['en']['pending']; ?>',
                     'class': '<?php echo $translations['en']['class']; ?>',
                     'class_scheduled': '<?php echo $translations['en']['class_scheduled']; ?>'
                },
                'zh': {
                    'portal_title': '<?php echo $translations['zh']['portal_title']; ?>',
                    'logout': '<?php echo $translations['zh']['logout']; ?>',
                    'set_availability_title': '<?php echo $translations['zh']['set_availability_title']; ?>',
                    'set_availability_text': '<?php echo $translations['zh']['set_availability_text']; ?>',
                    'schedule_saved': '<?php echo $translations['zh']['schedule_saved']; ?>',
                    'schedule_cleared': '<?php echo $translations['zh']['schedule_cleared']; ?>',
                    'confirm_clear_schedule': '<?php echo $translations['zh']['confirm_clear_schedule']; ?>',
                    'reset_button': '<?php echo $translations['zh']['reset_button']; ?>',
                    'save_button': '<?php echo $translations['zh']['save_button']; ?>',
                    'monday': '<?php echo $translations['zh']['monday']; ?>',
                    'tuesday': '<?php echo $translations['zh']['tuesday']; ?>',
                    'wednesday': '<?php echo $translations['zh']['wednesday']; ?>',
                    'thursday': '<?php echo $translations['zh']['thursday']; ?>',
                    'friday': '<?php echo $translations['zh']['friday']; ?>',
                    'saturday': '<?php echo $translations['zh']['saturday']; ?>',
                    'sunday': '<?php echo $translations['zh']['sunday']; ?>',
                    'pending_requests_title': '<?php echo $translations['zh']['pending_requests_title']; ?>',
                    'no_requests': '<?php echo $translations['zh']['no_requests']; ?>',
                    'member': '<?php echo $translations['zh']['member']; ?>',
                    'date': '<?php echo $translations['zh']['date']; ?>',
                    'time': '<?php echo $translations['zh']['time']; ?>',
                    'actions': '<?php echo $translations['zh']['actions']; ?>',
                    'confirm_button': '<?php echo $translations['zh']['confirm_button']; ?>',
                    'cancel_button': '<?php echo $translations['zh']['cancel_button']; ?>',
                    'request_confirmed': '<?php echo $translations['zh']['request_confirmed']; ?>',
                    'request_cancelled': '<?php echo $translations['zh']['request_cancelled']; ?>',
                    'confirm_request_modal_title': '<?php echo $translations['zh']['confirm_request_modal_title']; ?>',
                    'confirm_request_modal_message': '<?php echo $translations['zh']['confirm_request_modal_message']; ?>',
                    'confirm_cancel_modal_title': '<?php echo $translations['zh']['confirm_cancel_modal_title']; ?>',
                    'confirm_cancel_modal_message': '<?php echo $translations['zh']['confirm_cancel_modal_message']; ?>',
                    'ok': '<?php echo $translations['zh']['ok']; ?>',
                    'booked_by': '<?php echo $translations['zh']['booked_by']; ?>',
                    'set_price': '<?php echo $translations['zh']['set_price']; ?>',
                    'twd_symbol': '<?php echo $translations['zh']['twd_symbol']; ?>',
                                         'available': '<?php echo $translations['zh']['available']; ?>',
                     'booked': '<?php echo $translations['zh']['booked']; ?>',
                     'pending': '<?php echo $translations['zh']['pending']; ?>',
                     'class': '<?php echo $translations['zh']['class']; ?>',
                     'class_scheduled': '<?php echo $translations['zh']['class_scheduled']; ?>'
                }
            };

                        // Initialize language on page load
            updateLanguage();
        });
    </script>
</body>
</html>
