<?php
// Turn on error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set the timezone to ensure correct date operations
date_default_timezone_set('Asia/Taipei');

// Include the database configuration from the current directory.
require_once "./db_config.php";

// Start the session.
session_start();

// --- LANGUAGE HANDLING ---
$translations = [
    'en' => [
        'portal_title' => '1-to-1 Class Booking',
        'logout' => 'Logout',
        'welcome' => 'Welcome, ',
        'booking_for' => 'Booking for',
        'select_coach_prompt' => 'Select a coach to view their availability.',
        'select_coach' => 'Select Coach:',
        'weekly_availability' => 'Weekly Availability for ',
        'no_coach_selected' => 'Please select a coach to view their schedule.',
        'monday' => 'Monday',
        'tuesday' => 'Tuesday',
        'wednesday' => 'Wednesday',
        'thursday' => 'Thursday',
        'friday' => 'Friday',
        'saturday' => 'Saturday',
        'sunday' => 'Sunday',
        'unavailable' => 'Unavailable',
        'available' => 'Available',
        'book_slot_button' => 'Book This Time Slot',
        'book_class' => 'Book Class',
        'booking_success' => 'Booking request sent successfully!',
        'cancellation_success' => 'Booking cancelled successfully!',
        'error_title' => 'Error!',
        'error_message' => 'A network error occurred. Please try again.',
        'ok' => 'OK',
        'booking_modal_title' => 'Confirm Booking',
        'booking_modal_message' => 'You are about to book a private class with {coach_name} on {date} at {time}. Do you wish to proceed? The coach will confirm the booking via email.',
        'confirm_button' => 'Confirm',
        'cancel_button' => 'Cancel',
        'go_back' => 'Go back',
        'schedule_class_btn' => 'Schedule Class',
        'requested' => 'Requested',
        'confirmed' => 'Confirmed',
        'price_per_hour' => 'Price per hour:',
        'twd_symbol' => 'TWD'
    ],
    'zh' => [
        'portal_title' => '一對一課程預約',
        'logout' => '登出',
        'welcome' => '歡迎, ',
        'booking_for' => '預約人',
        'select_coach_prompt' => '選擇一位教練以查看他們的可用時間。',
        'select_coach' => '選擇教練:',
        'weekly_availability' => '每週可用時間表 ',
        'no_coach_selected' => '請選擇一位教練以查看他們的時間表。',
        'monday' => '星期一',
        'tuesday' => '星期二',
        'wednesday' => '星期三',
        'thursday' => '星期四',
        'friday' => '星期五',
        'saturday' => '星期六',
        'sunday' => '星期日',
        'unavailable' => '不可用',
        'available' => '可用',
        'book_slot_button' => '預約此時段',
        'book_class' => '預約課程',
        'booking_success' => '預約請求已成功發送！',
        'cancellation_success' => '預約已成功取消！',
        'error_title' => '錯誤！',
        'error_message' => '發生網路錯誤。請稍後再試。',
        'ok' => '好的',
        'booking_modal_title' => '確認預約',
        'booking_modal_message' => '您即將預約 {coach_name} 於 {date} {time} 的一對一課程。教練將通過電子郵件確認預約。您確定要繼續嗎？',
        'confirm_button' => '確認',
        'cancel_button' => '取消',
        'go_back' => '返回',
        'schedule_class_btn' => '安排課程',
        'requested' => '已申請',
        'confirmed' => '已確認',
        'price_per_hour' => '每小時費用：',
        'twd_symbol' => '新台幣'
    ]
];

// Determine the language
if (isset($_GET['lang']) && array_key_exists($_GET['lang'], $translations)) {
    $_SESSION['lang'] = $_GET['lang'];
} elseif (!isset($_SESSION['lang'])) {
    $browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
    $_SESSION['lang'] = in_array($browser_lang, ['zh']) ? 'zh' : 'en';
}
$lang = $_SESSION['lang'];
$days_zh = ['Monday' => '星期一', 'Tuesday' => '星期二', 'Wednesday' => '星期三', 'Thursday' => '星期四', 'Friday' => '星期五', 'Saturday' => '星期六', 'Sunday' => '星期日'];

// Check if the user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

// --- PARENT/CHILD LOGIC ---
$user_id_for_booking = $_SESSION['id'];
$user_name_for_booking = $_SESSION['full_name'];
$is_parent_managing = false;
$dashboard_link = "dashboard.php";

if (isset($_SESSION['role']) && $_SESSION['role'] === 'parent' && isset($_GET['id'])) {
    $child_id_from_url = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($child_id_from_url) {
        $parent_id = $_SESSION['id'];
        $sql_verify = "SELECT id, first_name, last_name FROM users WHERE id = ? AND parent_id = ?";
        if ($stmt_verify = mysqli_prepare($link, $sql_verify)) {
            mysqli_stmt_bind_param($stmt_verify, "ii", $child_id_from_url, $parent_id);
            mysqli_stmt_execute($stmt_verify);
            $child_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_verify));
            mysqli_stmt_close($stmt_verify);
            if ($child_data) {
                $user_id_for_booking = $child_id_from_url;
                $user_name_for_booking = $child_data['first_name'] . ' ' . $child_data['last_name'];
                $is_parent_managing = true;
                $dashboard_link = "manage_child.php?id=" . $child_id_from_url;
            }
        }
    }
} elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'parent') {
    $dashboard_link = "parents_dashboard.php";
}


// --- START AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Re-run logic inside AJAX to ensure correct user ID is used
    $ajax_user_id = $_SESSION['id'];
    $ajax_user_name = $_SESSION['full_name'];
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'parent' && isset($_GET['id'])) {
        $child_id_from_url = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if ($child_id_from_url) {
            $parent_id = $_SESSION['id'];
            $sql_verify = "SELECT id, first_name, last_name FROM users WHERE id = ? AND parent_id = ?";
            if ($stmt_verify = mysqli_prepare($link, $sql_verify)) {
                mysqli_stmt_bind_param($stmt_verify, "ii", $child_id_from_url, $parent_id);
                mysqli_stmt_execute($stmt_verify);
                $child_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_verify));
                mysqli_stmt_close($stmt_verify);
                if ($child_data) {
                    $ajax_user_id = $child_id_from_url;
                    $ajax_user_name = $child_data['first_name'] . ' ' . $child_data['last_name'];
                }
            }
        }
    }

    switch ($action) {
        case 'book_1to1':
            $coach_id = $_POST['coach_id'] ?? 0;
            $booking_date = $_POST['booking_date'] ?? '';
            $start_time = $_POST['start_time'] ?? '';

            $sql_insert = "INSERT INTO one_to_one_bookings (member_id, coach_id, booking_date, start_time, status) VALUES (?, ?, ?, ?, 'pending')";
            if ($stmt_insert = mysqli_prepare($link, $sql_insert)) {
                mysqli_stmt_bind_param($stmt_insert, "iiss", $ajax_user_id, $coach_id, $booking_date, $start_time);
                if (mysqli_stmt_execute($stmt_insert)) {
                    $coach_info_sql = "SELECT first_name, last_name, email FROM users WHERE id = ?";
                    if ($stmt_coach_info = mysqli_prepare($link, $coach_info_sql)) {
                        mysqli_stmt_bind_param($stmt_coach_info, "i", $coach_id);
                        mysqli_stmt_execute($stmt_coach_info);
                        $coach_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_coach_info));
                        mysqli_stmt_close($stmt_coach_info);
                        if ($coach_data) {
                            $to = $coach_data['email'];
                            $subject = "New 1-on-1 Booking Request from {$ajax_user_name}";
                            $headers = "From: webmaster@yourdomain.com\r\n";
                            $headers .= "MIME-Version: 1.0\r\n";
                            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                            $message_body = "<html><body><p>Hello {$coach_data['first_name']},</p><p>A new 1-on-1 class has been requested:</p><ul><li><strong>Member:</strong> {$ajax_user_name}</li><li><strong>Date:</strong> {$booking_date}</li><li><strong>Time:</strong> {$start_time}</li></ul><p>Please log in to your coach dashboard to confirm or cancel the request.</p></body></html>";
                            mail($to, $subject, $message_body, $headers);
                        }
                    }
                    echo json_encode(['success' => true, 'message' => $translations[$lang]['booking_success']]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'An error occurred during booking.']);
                }
                mysqli_stmt_close($stmt_insert);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error.']);
            }
            break;

        case 'fetch_availability':
            $coach_id = $_POST['coach_id'] ?? null;
            if ($coach_id) {
                $availability = [];
                $sql_availability = "
                    SELECT 
                        ca.day_of_week, ca.start_time, ca.end_time,
                        b.status, b.member_id
                    FROM coach_availability ca
                    LEFT JOIN one_to_one_bookings b ON ca.coach_id = b.coach_id AND ca.day_of_week = DAYNAME(b.booking_date) AND ca.start_time = b.start_time
                    WHERE ca.coach_id = ?
                ";
                if ($stmt_avail = mysqli_prepare($link, $sql_availability)) {
                    mysqli_stmt_bind_param($stmt_avail, "i", $coach_id);
                    mysqli_stmt_execute($stmt_avail);
                    $result_avail = mysqli_stmt_get_result($stmt_avail);
                    while ($row = mysqli_fetch_assoc($result_avail)) {
                        $availability[] = $row;
                    }
                    mysqli_stmt_close($stmt_avail);
                    echo json_encode(['success' => true, 'availability' => $availability]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No coach ID provided.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// --- Fetch all coaches from the database who have availability set ---
$coaches = [];
$sql_coaches = "SELECT u.id, u.first_name, u.last_name, u.profile_picture_url, u.belt_color, ca.price FROM users u JOIN coach_availability ca ON u.id = ca.coach_id WHERE u.role = 'coach' GROUP BY u.id ORDER BY u.first_name";
$result_coaches = mysqli_query($link, $sql_coaches);
if ($result_coaches) {
    while ($row = mysqli_fetch_assoc($result_coaches)) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $coaches[] = $row;
    }
}
mysqli_close($link);

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
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

$current_week_dates = [];
$start_of_week_dt = new DateTime('monday this week');
for ($i = 0; $i < 7; $i++) {
    $date = (clone $start_of_week_dt)->modify('+' . $i . ' days');
    $current_week_dates[$date->format('l')] = $date->format('Y-m-d');
}

?>

<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <title><?php echo $translations[$lang]['portal_title']; ?> - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .calendar-grid { grid-template-columns: repeat(8, minmax(0, 1fr)); }
        .time-slot { height: 50px; transition: all 0.2s ease-in-out; display: flex; align-items: center; justify-content: center; border-radius: 0.5rem; text-align: center; font-size: 0.75rem; font-weight: bold; color: white; padding: 0.5rem; }
        .time-slot.available { background-color: #34d399; cursor: pointer; }
        .time-slot.available:hover { background-color: #10b981; }
        .time-slot.requested { background-color: #f59e0b; cursor: not-allowed; }
        .time-slot.confirmed { background-color: #1e40af; cursor: not-allowed; }
        .time-slot.unavailable { background-color: #d1d5db; cursor: not-allowed; }
        .profile-card-img { width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 4px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800"><?php echo $translations[$lang]['portal_title']; ?></span>
            <div class="flex items-center space-x-4">
                <div class="text-gray-600">
                    <a href="?lang=en" class="hover:text-blue-600 <?php echo $lang === 'en' ? 'font-bold' : ''; ?>">EN</a> |
                    <a href="?lang=zh" class="hover:text-blue-600 <?php echo $lang === 'zh' ? 'font-bold' : ''; ?>">中</a>
                </div>
                <a href="<?php echo $dashboard_link; ?>" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                    <?php echo $translations[$lang]['go_back']; ?>
                </a>
                <a href="logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">
                    <?php echo $translations[$lang]['logout']; ?>
                </a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">
            <?php 
                if ($is_parent_managing) {
                    echo $translations[$lang]['booking_for'] . ' ' . htmlspecialchars($user_name_for_booking);
                } else {
                    echo $translations[$lang]['welcome'] . htmlspecialchars($_SESSION["full_name"]); 
                }
            ?>!
        </h1>
        <p class="mt-2 text-lg text-gray-600"><?php echo $translations[$lang]['select_coach_prompt']; ?></p>

        <div class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php if (empty($coaches)): ?>
                <p class="text-gray-600 col-span-full">No coaches are currently available for 1-to-1 booking.</p>
            <?php else: ?>
                <?php foreach ($coaches as $coach): ?>
                    <div class="bg-white p-8 rounded-2xl shadow-lg flex flex-col items-center text-center">
                        <img src="<?php echo htmlspecialchars($coach['profile_picture_url']) ?: 'https://placehold.co/120x120/E5E7EB/A9A9A9?text=👤'; ?>" alt="<?php echo htmlspecialchars($coach['full_name']); ?> Profile" class="profile-card-img">
                        <h2 class="text-2xl font-bold text-gray-800 mt-4"><?php echo htmlspecialchars($coach['full_name']); ?></h2>
                        <p class="text-gray-600 mt-1"><?php echo htmlspecialchars($coach['belt_color']); ?></p>
                        <p class="text-lg font-bold text-gray-800 mt-2">
                             <?php echo $translations[$lang]['price_per_hour']; ?>
                             <?php echo htmlspecialchars($coach['price'] ?? 'N/A'); ?>
                             <span class="text-sm text-gray-600"><?php echo $translations[$lang]['twd_symbol']; ?></span>
                        </p>
                        <button class="mt-4 w-full bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition schedule-class-btn"
                                data-coach-id="<?php echo htmlspecialchars($coach['id']); ?>"
                                data-coach-name="<?php echo htmlspecialchars($coach['full_name']); ?>">
                            <?php echo $translations[$lang]['schedule_class_btn']; ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Booking Modal -->
    <div id="booking-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-12 mx-auto p-5 border w-full max-w-4xl shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <p class="text-2xl font-bold">
                    <?php echo $translations[$lang]['weekly_availability']; ?> 
                    <span id="booking-modal-coach-name"></span>
                </p>
                <button id="close-booking-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            
            <div class="mt-4">
                <!-- Availability calendar will be injected here by JavaScript -->
            </div>
        </div>
    </div>

    <!-- Booking Confirmation Modal -->
    <div id="confirm-booking-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <p class="text-2xl font-bold"><?php echo $translations[$lang]['booking_modal_title']; ?></p>
                <button id="close-confirm-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            <div class="mt-4">
                <p id="confirm-modal-message" class="text-gray-700"></p>
                <div class="mt-6 flex justify-end space-x-4">
                    <button id="confirm-booking-btn" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition">
                        <?php echo $translations[$lang]['confirm_button']; ?>
                    </button>
                    <button id="cancel-booking-btn" class="bg-gray-300 text-gray-800 font-bold py-2 px-6 rounded-lg hover:bg-gray-400 transition">
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
                <button id="close-status-modal" class="bg-blue-500 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-600 transition">
                    <?php echo $translations[$lang]['ok']; ?>
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const scheduleClassBtns = document.querySelectorAll('.schedule-class-btn');
            const bookingModal = document.getElementById('booking-modal');
            const closeBookingModalBtn = document.getElementById('close-booking-modal');
            const bookingModalCoachName = document.getElementById('booking-modal-coach-name');
            const bookingModalContent = bookingModal.querySelector('.mt-4');

            const confirmBookingModal = document.getElementById('confirm-booking-modal');
            const closeConfirmModalBtn = document.getElementById('close-confirm-modal');
            const confirmBookingBtn = document.getElementById('confirm-booking-btn');
            const cancelBookingBtn = document.getElementById('cancel-booking-btn');
            const confirmModalMessage = document.getElementById('confirm-modal-message');

            const statusModal = document.getElementById('status-modal');
            const closeStatusModalBtn = document.getElementById('close-status-modal');
            
            const translations = <?php echo json_encode($translations); ?>;
            const lang = '<?php echo $lang; ?>';
            const daysOfWeek = <?php echo json_encode($days_of_week); ?>;
            const timeSlots = <?php echo json_encode($time_slots); ?>;
            const currentWeekDates = <?php echo json_encode($current_week_dates); ?>;
            const currentUserId = <?php echo json_encode($user_id_for_booking); ?>;
            
            let currentBooking = {};

            // --- AJAX FUNCTIONS ---
            function fetchAvailability(coachId) {
                const formData = new FormData();
                formData.append('action', 'fetch_availability');
                formData.append('coach_id', coachId);

                return fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json());
            }

            function bookClass(bookingData) {
                const formData = new FormData();
                formData.append('action', 'book_1to1');
                formData.append('coach_id', bookingData.coachId);
                formData.append('booking_date', bookingData.date);
                formData.append('start_time', bookingData.startTime);

                const url = new URL(window.location.href);
                const fetchUrl = url.search ? `${window.location.pathname}${url.search}` : window.location.pathname;

                return fetch(fetchUrl, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json());
            }

            // --- EVENT HANDLERS ---
            scheduleClassBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const coachId = this.dataset.coachId;
                    const coachName = this.dataset.coachName;
                    bookingModalCoachName.textContent = coachName;

                    fetchAvailability(coachId).then(data => {
                        if (data.success) {
                            renderCalendar(data.availability, coachId, coachName);
                            bookingModal.classList.remove('hidden');
                        } else {
                            showStatus(data.message, false);
                        }
                    });
                });
            });

            closeBookingModalBtn.addEventListener('click', () => {
                bookingModal.classList.add('hidden');
            });
            closeConfirmModalBtn.addEventListener('click', () => {
                confirmBookingModal.classList.add('hidden');
            });
            cancelBookingBtn.addEventListener('click', () => {
                confirmBookingModal.classList.add('hidden');
            });

            confirmBookingBtn.addEventListener('click', () => {
                bookClass(currentBooking).then(data => {
                    confirmBookingModal.classList.add('hidden');
                    showStatus(data.message, data.success);
                    if (data.success) {
                        setTimeout(() => { window.location.reload(); }, 2000);
                    }
                }).catch(error => {
                    confirmBookingModal.classList.add('hidden');
                    showStatus(translations[lang]['error_message'], false);
                });
            });

            closeStatusModalBtn.addEventListener('click', () => {
                statusModal.classList.add('hidden');
            });

            // --- RENDER FUNCTIONS ---
            function renderCalendar(availability, coachId, coachName) {
                const daysInWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                let calendarHtml = `
                    <div class="grid calendar-grid gap-1 mt-4">
                        <div class="p-2 text-sm font-bold text-gray-600"></div>
                        ${daysInWeek.map(day => `<div class="p-2 text-center text-sm font-bold text-gray-600">${translations[lang][day.toLowerCase()]}</div>`).join('')}
                        
                        ${timeSlots.map(slot => `
                            <div class="p-2 text-sm text-gray-500 font-semibold flex items-center justify-end pr-2 border-r border-gray-200">
                                ${slot.label}
                            </div>
                            ${daysInWeek.map(day => {
                                const slotData = availability.find(avail_slot => avail_slot.day_of_week === day && avail_slot.start_time === slot.start);
                                
                                let slotClass = 'unavailable';
                                let slotText = translations[lang]['unavailable'];
                                let isClickable = false;

                                if (slotData) {
                                    if (slotData.status === 'pending' && slotData.member_id == currentUserId) {
                                        slotClass = 'requested';
                                        slotText = translations[lang]['requested'];
                                    } else if (slotData.status === 'confirmed') {
                                        slotClass = 'confirmed';
                                        slotText = translations[lang]['confirmed'];
                                    } else if (slotData.status === 'pending') {
                                        slotClass = 'unavailable';
                                        slotText = translations[lang]['unavailable'];
                                    } else {
                                        slotClass = 'available';
                                        slotText = translations[lang]['available'];
                                        isClickable = true;
                                    }
                                }
                                
                                return `
                                    <div class="time-slot border border-gray-200 ${slotClass}"
                                         data-day="${day}"
                                         data-date="${currentWeekDates[day]}"
                                         data-start="${slot.start}"
                                         data-coach-id="${coachId}"
                                         data-coach-name="${coachName}"
                                         data-is-clickable="${isClickable}">
                                        ${slotText}
                                    </div>
                                `;
                            }).join('')}
                        `).join('')}
                    </div>
                `;
                bookingModalContent.innerHTML = calendarHtml;
                setupCalendarListeners();
            }

            function setupCalendarListeners() {
                document.querySelectorAll('.time-slot.available').forEach(slot => {
                    slot.addEventListener('click', function() {
                        currentBooking = {
                            coachId: this.dataset.coachId,
                            coachName: this.dataset.coachName,
                            date: this.dataset.date,
                            startTime: this.dataset.start
                        };
                        const dateDisplay = new Date(currentBooking.date).toLocaleDateString(lang === 'zh' ? 'zh-TW' : 'en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                        
                        const timeDisplay = new Date(`1970-01-01T${currentBooking.startTime}`).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
                        
                        const message = translations[lang]['booking_modal_message']
                            .replace('{coach_name}', currentBooking.coachName)
                            .replace('{date}', dateDisplay)
                            .replace('{time}', timeDisplay);
                        confirmModalMessage.textContent = message;
                        confirmBookingModal.classList.remove('hidden');
                    });
                });
            }

            function showStatus(message, isSuccess) {
                const content = document.getElementById('status-modal-content');
                content.innerHTML = `<p class="text-lg font-medium ${isSuccess ? 'text-green-600' : 'text-red-600'}">${message}</p>`;
                statusModal.classList.remove('hidden');
            }
        });
    </script>
</body>
</html>
