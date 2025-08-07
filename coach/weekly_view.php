<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    header("location: admin_login.html");
    exit;
}

// Set the timezone to ensure correct date operations
date_default_timezone_set('Asia/Taipei');

// Include the database configuration file
require_once "../db_config.php";

// --- Week Navigation Logic ---
$week_offset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$today = new DateTime();
if ($week_offset !== 0) {
    $today->modify(($week_offset > 0 ? '+' : '') . $week_offset . ' weeks');
}
$start_of_week = (clone $today)->modify('monday this week');
$end_of_week = (clone $today)->modify('sunday this week');


// --- Fetch all classes and their booking counts for the selected week ---
$sql = "SELECT 
            c.id AS class_id,
            c.name,
            c.day_of_week,
            c.start_time,
            COUNT(b.id) AS booking_count
        FROM classes c
        LEFT JOIN bookings b ON c.id = b.class_id AND b.booking_date BETWEEN ? AND ?
        GROUP BY c.id, c.name, c.day_of_week, c.start_time
        ORDER BY c.start_time";

$schedule = [];
$total_weekly_bookings = 0;
if($stmt = mysqli_prepare($link, $sql)){
    $start_date_str = $start_of_week->format('Y-m-d');
    $end_date_str = $end_of_week->format('Y-m-d');
    mysqli_stmt_bind_param($stmt, "ss", $start_date_str, $end_date_str);
    
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $schedule[$row['day_of_week']][] = $row;
            $total_weekly_bookings += $row['booking_count'];
        }
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($link);

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Weekly Booking View - Coach Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .modal { transition: opacity 0.25s ease; }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="coach_dashboard.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Coach Dashboard</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <div class="md:flex justify-between items-center">
            <div>
                <h1 class="text-4xl font-black text-gray-800">Weekly Booking View</h1>
                <p class="mt-2 text-lg text-gray-600">
                    Showing schedule for: <strong><?php echo $start_of_week->format('M j, Y'); ?> &ndash; <?php echo $end_of_week->format('M j, Y'); ?></strong>
                </p>
            </div>
            <div class="mt-4 md:mt-0 flex items-center space-x-2">
                <a href="?week=<?php echo $week_offset - 1; ?>" class="bg-white text-gray-700 font-bold py-2 px-4 rounded-lg hover:bg-gray-100 transition shadow-sm">&larr; Previous Week</a>
                <a href="?week=0" class="bg-white text-gray-700 font-bold py-2 px-4 rounded-lg hover:bg-gray-100 transition shadow-sm">This Week</a>
                <a href="?week=<?php echo $week_offset + 1; ?>" class="bg-white text-gray-700 font-bold py-2 px-4 rounded-lg hover:bg-gray-100 transition shadow-sm">Next Week &rarr;</a>
            </div>
        </div>
        
        <!-- Weekly Stats -->
        <div class="mt-6 bg-blue-500 text-white p-6 rounded-2xl shadow-lg">
            <p class="text-lg font-semibold">Total Bookings This Week</p>
            <p class="text-5xl font-black"><?php echo $total_weekly_bookings; ?></p>
        </div>

        <div class="mt-8 bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="grid grid-cols-7">
                <!-- Day Headers -->
                <?php foreach ($days_of_week as $day): ?>
                    <div class="text-center font-bold p-4 border-b border-r"><?php echo $day; ?></div>
                <?php endforeach; ?>
                
                <!-- Schedule Grid -->
                <?php foreach ($days_of_week as $day): ?>
                    <div class="border-r p-2 space-y-2 min-h-[60vh]">
                        <?php if (isset($schedule[$day])): ?>
                            <?php foreach ($schedule[$day] as $class): ?>
                                <button class="w-full text-left p-3 rounded-lg shadow-sm class-item <?php echo $class['booking_count'] > 0 ? 'bg-blue-100 hover:bg-blue-200' : 'bg-gray-100 text-gray-500'; ?>" 
                                        data-class-id="<?php echo $class['class_id']; ?>" 
                                        data-class-name="<?php echo htmlspecialchars($class['name']); ?>"
                                        data-day-of-week="<?php echo $day; ?>"
                                        data-week-offset="<?php echo $week_offset; ?>">
                                    <p class="font-semibold text-sm"><?php echo htmlspecialchars($class['name']); ?></p>
                                    <p class="text-xs"><?php echo date("g:i A", strtotime($class['start_time'])); ?></p>
                                    <p class="text-xs mt-1 font-bold">Bookings: <?php echo $class['booking_count']; ?></p>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Bookings Modal -->
    <div id="bookings-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-75 p-4 hidden">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 id="modal-title" class="text-lg font-bold text-gray-900">Bookings</h3>
                <button id="close-modal-btn" class="text-gray-500 hover:text-gray-800 text-2xl">&times;</button>
            </div>
            <div id="modal-body" class="p-6 overflow-y-auto">
                <p class="text-center text-gray-500">Loading bookings...</p>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('bookings-modal');
        const modalTitle = document.getElementById('modal-title');
        const modalBody = document.getElementById('modal-body');
        const closeModalBtn = document.getElementById('close-modal-btn');

        document.querySelectorAll('.class-item').forEach(item => {
            item.addEventListener('click', async function() {
                const classId = this.dataset.classId;
                const className = this.dataset.className;
                const dayOfWeek = this.dataset.dayOfWeek;
                const weekOffset = parseInt(this.dataset.weekOffset);
                
                // Calculate the specific date for the class clicked
                const today = new Date();
                const dayIndex = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'].indexOf(dayOfWeek);
                // Adjust for week offset
                today.setDate(today.getDate() + (weekOffset * 7));
                // Find monday of that week
                const monday = new Date(today);
                monday.setDate(monday.getDate() - (monday.getDay() + 6) % 7);
                // Find the target date
                const classDate = new Date(monday);
                classDate.setDate(monday.getDate() + (dayIndex === 0 ? 6 : dayIndex - 1));
                
                const classDateString = classDate.toISOString().split('T')[0];

                modalTitle.textContent = `Bookings for ${className} on ${dayOfWeek} (${classDate.toLocaleDateString()})`;
                modalBody.innerHTML = '<p class="text-center text-gray-500">Loading bookings...</p>';
                modal.classList.remove('hidden');

                try {
                    const response = await fetch(`get_bookings.php?class_id=${classId}&date=${classDateString}`);
                    const bookings = await response.json();
                    
                    if (bookings.status === 'success') {
                        renderBookings(bookings.data);
                    } else {
                        modalBody.innerHTML = `<p class="text-center text-red-500">${bookings.message}</p>`;
                    }
                } catch (error) {
                    modalBody.innerHTML = '<p class="text-center text-red-500">Failed to load bookings.</p>';
                }
            });
        });

        function renderBookings(bookings) {
            if (bookings.length === 0) {
                modalBody.innerHTML = '<p class="text-center text-gray-500">No bookings for this class on the selected date.</p>';
                return;
            }

            let tableHtml = `<table class="w-full text-left">
                                <thead><tr class="border-b"><th class="py-2">Member</th><th class="py-2">Status</th></tr></thead>
                                <tbody>`;
            bookings.forEach(booking => {
                tableHtml += `<tr class="border-b">
                                <td class="py-2 flex items-center">
                                    <img src="${booking.profile_picture_url}" class="w-8 h-8 rounded-full mr-3 object-cover" onerror="this.onerror=null;this.src='https://placehold.co/32x32/e2e8f0/333333?text=Pic';">
                                    ${booking.member_name}
                                </td>
                                <td><span class="px-2 py-1 text-xs font-semibold rounded-full ${booking.status === 'attended' ? 'bg-green-200 text-green-800' : 'bg-blue-200 text-blue-800'}">${booking.status}</span></td>
                               </tr>`;
            });
            tableHtml += `</tbody></table>`;
            modalBody.innerHTML = tableHtml;
        }

        const closeModal = () => modal.classList.add('hidden');
        closeModalBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    });
    </script>
</body>
</html>
