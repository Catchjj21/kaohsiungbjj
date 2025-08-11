<?php
// Enable error display for debugging - REMOVE THESE LINES ONCE DEPLOYED TO PRODUCTION
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    header("location: admin_login.html"); // Redirect to admin login if not authorized
    exit;
}

require_once "/home/virtual/vps-d397de/f/fc63b9c1c0/public_html/db_config.php"; // Adjust path as needed

// Get the selected date, default to today
$selected_date = $_GET['date'] ?? date('Y-m-d');

// Validate date format
if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $selected_date)) {
    $selected_date = date('Y-m-d'); // Fallback to today if invalid
}

// Fetch classes for the selected date
$classes_for_day = [];
$sql_classes_for_day = "SELECT c.id AS class_id, c.name, c.name_zh, c.start_time, c.end_time, u.first_name AS coach_first_name, u.last_name AS coach_last_name FROM classes c LEFT JOIN users u ON c.coach_id = u.id WHERE c.day_of_week = DAYNAME(?) AND c.is_active = 1 ORDER BY c.start_time";

if ($stmt_classes = mysqli_prepare($link, $sql_classes_for_day)) {
    mysqli_stmt_bind_param($stmt_classes, "s", $selected_date); // DAYNAME() expects a date string
    if (mysqli_stmt_execute($stmt_classes)) {
        $result_classes = mysqli_stmt_get_result($stmt_classes);
        while ($row = mysqli_fetch_assoc($result_classes)) {
            $row['coach_name'] = trim($row['coach_first_name'] . ' ' . $row['coach_last_name']);
            $classes_for_day[] = $row;
        }
    } else {
        error_log("SQL Execution Error fetching classes for date: " . mysqli_error($link));
    }
    mysqli_stmt_close($stmt_classes);
} else {
    error_log("SQL Prepare Error fetching classes for date: " . mysqli_error($link));
}

mysqli_close($link);

// Define Chinese names for days of the week for language display.
$days_zh = ['Monday' => '星期一', 'Tuesday' => '星期二', 'Wednesday' => '星期三', 'Thursday' => '星期四', 'Friday' => '星期五', 'Saturday' => '星期六', 'Sunday' => '星期日'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Class Attendance - Catch Jiu Jitsu</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        .timetable-card {
            background-color: #ffffff;
            border-left: 4px solid;
            border-radius: 0.5rem; /* rounded-lg */
            padding: 1rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06); /* shadow-md */
        }
        .timetable-card.blue { border-color: #3b82f6; } /* blue-500 */
        .timetable-card.green { border-color: #22c55e; } /* green-500 */
        .timetable-card.purple { border-color: #a855f7; } /* purple-500 */
        .timetable-card.yellow { border-color: #eab308; } /* yellow-500 */
        .timetable-card.red { border-color: #ef4444; } /* red-500 */
        /* New style for profile pictures */
        .profile-pic {
            width: 32px; /* Small size */
            height: 32px;
            border-radius: 50%; /* Circular */
            object-fit: cover; /* Ensure image covers the area */
            margin-right: 8px; /* Space between pic and name */
            border: 1px solid #e2e8f0; /* Light border */
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="dashboard.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Dashboard</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>

    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">Class Attendance</h1>
        <p class="mt-2 text-lg text-gray-600">View attendance for scheduled classes.</p>

        <!-- Date Picker -->
        <div class="mt-6 flex items-center space-x-4">
            <label for="attendance-date" class="text-gray-700 font-medium">Select Date:</label>
            <input type="date" id="attendance-date" value="<?php echo htmlspecialchars($selected_date); ?>"
                   class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
        </div>

        <!-- Classes for Selected Date -->
        <div class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($classes_for_day)): ?>
                <div class="md:col-span-full text-center py-10 text-gray-500 bg-white rounded-2xl shadow-lg">
                    No classes scheduled for <?php echo date("F j, Y", strtotime($selected_date)); ?>.
                </div>
            <?php else: ?>
                <?php foreach ($classes_for_day as $class): ?>
                    <div class="timetable-card <?php 
                        // Assign a color based on class name or a rotating color
                        $class_name_lower = strtolower($class['name']);
                        if (strpos($class_name_lower, 'bjj') !== false) { echo 'blue'; }
                        else if (strpos($class_name_lower, 'wrestling') !== false) { echo 'yellow'; }
                        else if (strpos($class_name_lower, 'judo') !== false) { echo 'green'; }
                        else if (strpos($class_name_lower, 'no-gi') !== false) { echo 'purple'; }
                        else if (strpos($class_name_lower, 'comp') !== false) { echo 'red'; }
                        else { echo 'blue'; } // Default color
                    ?>">
                        <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($class['name']); ?> (<?php echo htmlspecialchars($class['name_zh']); ?>)</h3>
                        <p class="text-gray-700 text-sm mt-1">
                            <?php echo date("g:i A", strtotime($class['start_time'])) . " - " . date("g:i A", strtotime($class['end_time'])); ?>
                        </p>
                        <p class="text-gray-600 text-xs mt-1">Coach: <?php echo htmlspecialchars($class['coach_name'] ?? 'N/A'); ?></p>
                        <button class="view-attendees-btn mt-4 bg-blue-500 text-white text-xs font-bold py-2 px-3 rounded hover:bg-blue-600 transition"
                                data-class-id="<?php echo $class['class_id']; ?>"
                                data-class-name-en="<?php echo htmlspecialchars($class['name']); ?>"
                                data-class-name-zh="<?php echo htmlspecialchars($class['name_zh']); ?>"
                                data-class-date="<?php echo htmlspecialchars($selected_date); ?>">
                            View Attendees
                        </button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Attendees Modal -->
    <div id="attendees-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] flex flex-col">
            <header class="flex items-center justify-between p-4 border-b">
                <h3 id="attendees-modal-title" class="text-2xl font-bold">Attendees for Class</h3>
                <button id="close-attendees-modal" class="text-gray-500 hover:text-gray-800 text-3xl">&times;</button>
            </header>
            <div class="p-6 overflow-auto">
                <p id="attendees-class-info" class="text-gray-700 text-sm mb-4"></p>
                <ul id="attendees-list" class="space-y-2">
                    <!-- Attendees will be loaded here via JavaScript -->
                </ul>
                <p id="no-attendees-message" class="text-gray-500 text-sm hidden">No attendees for this class yet.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const attendanceDateInput = document.getElementById('attendance-date');
            const attendeesModal = document.getElementById('attendees-modal');
            const closeAttendeesModalBtn = document.getElementById('close-attendees-modal');
            const attendeesModalTitle = document.getElementById('attendees-modal-title');
            const attendeesClassInfo = document.getElementById('attendees-class-info');
            const attendeesList = document.getElementById('attendees-list');
            const noAttendeesMessage = document.getElementById('no-attendees-message');

            // Handle date change
            if (attendanceDateInput) {
                attendanceDateInput.addEventListener('change', function() {
                    window.location.href = `coach_attendance.php?date=${this.value}`;
                });
            }

            // Handle View Attendees button click
            document.querySelectorAll('.view-attendees-btn').forEach(button => {
                button.addEventListener('click', async function() {
                    const classId = this.dataset.classId;
                    const classNameEn = this.dataset.classNameEn;
                    const classNameZh = this.dataset.classNameZh;
                    const classDate = this.dataset.classDate;

                    attendeesModalTitle.textContent = `Attendees for ${classNameEn}`; // Default title
                    attendeesClassInfo.textContent = `Date: ${new Date(classDate).toLocaleDateString()} | Class: ${classNameEn} (${classNameZh})`;
                    attendeesList.innerHTML = '<li class="text-gray-500">Loading attendees...</li>';
                    noAttendeesMessage.classList.add('hidden');
                    attendeesModal.classList.remove('hidden');

                    try {
                        const response = await fetch('fetch_attendees.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({ class_id: classId, class_date: classDate })
                        });
                        const result = await response.json();

                        attendeesList.innerHTML = ''; // Clear loading message

                        if (result.success && result.attendees.length > 0) {
                            result.attendees.forEach(attendee => {
                                const listItem = document.createElement('li');
                                listItem.className = 'bg-gray-50 p-2 rounded-md flex items-center'; // Adjusted for profile pic alignment

                                // Create profile picture element if URL exists
                                const profilePicHtml = attendee.profile_picture_url
                                    ? `<img src="${attendee.profile_picture_url}" alt="${attendee.full_name}" class="profile-pic">`
                                    : ''; // No image if URL is empty or null

                                // Display count, profile pic (if available), and full name
                                listItem.innerHTML = `
                                    <span class="font-medium text-gray-800 mr-2">${attendee.count}.</span>
                                    ${profilePicHtml}
                                    <span class="text-gray-800">${attendee.full_name}</span>
                                `;
                                attendeesList.appendChild(listItem);
                            });
                        } else {
                            noAttendeesMessage.classList.remove('hidden');
                        }
                    } catch (error) {
                        console.error('Error fetching attendees:', error);
                        attendeesList.innerHTML = '<li class="text-red-500">Failed to load attendees.</li>';
                        noAttendeesMessage.classList.add('hidden');
                    }
                });
            });

            // Close Attendees Modal
            if (closeAttendeesModalBtn) {
                closeAttendeesModalBtn.addEventListener('click', function() {
                    attendeesModal.classList.add('hidden');
                });
            }
            if (attendeesModal) {
                attendeesModal.addEventListener('click', function(e) {
                    if (e.target === attendeesModal) {
                        attendeesModal.classList.add('hidden');
                    }
                });
            }
        });
    </script>
</body>
</html>
