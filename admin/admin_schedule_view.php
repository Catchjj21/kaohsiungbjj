<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../db_config.php";
session_start();

if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    // Match dashboard logic: redirect to ../admin_login.html
    header("location: ../admin_login.html");
    exit;
}

// --- DATA FETCHING ---

// 1. Fetch all active group classes
$sql_group_classes = "SELECT 
                        c.name, 
                        c.day_of_week, 
                        c.start_time, 
                        c.end_time,
                        CONCAT(u.first_name, ' ', u.last_name) as coach_name
                      FROM classes c 
                      LEFT JOIN users u ON c.coach_id = u.id 
                      WHERE c.is_active = 1";
$group_classes_raw = [];
if($result_group = mysqli_query($link, $sql_group_classes)){
    while($row = mysqli_fetch_assoc($result_group)){
        $group_classes_raw[] = $row;
    }
} else {
    error_log("SQL Error fetching group classes: " . mysqli_error($link));
}

// 2. Fetch all pending and confirmed 1-to-1 bookings for the current week
$start_of_week = date('Y-m-d', strtotime('monday this week'));
$end_of_week = date('Y-m-d', strtotime('sunday this week'));

$sql_1to1_bookings = "SELECT 
                        b.booking_date,
                        b.start_time,
                        b.status,
                        CONCAT(coach.first_name, ' ', coach.last_name) as coach_name,
                        CONCAT(member.first_name, ' ', member.last_name) as member_name
                      FROM one_to_one_bookings b
                      JOIN users coach ON b.coach_id = coach.id
                      JOIN users member ON b.member_id = member.id
                      WHERE b.booking_date BETWEEN ? AND ? AND b.status IN ('pending', 'confirmed')";
$bookings_1to1_raw = [];
if($stmt_1to1 = mysqli_prepare($link, $sql_1to1_bookings)){
    mysqli_stmt_bind_param($stmt_1to1, "ss", $start_of_week, $end_of_week);
    mysqli_stmt_execute($stmt_1to1);
    $result_1to1 = mysqli_stmt_get_result($stmt_1to1);
    while($row = mysqli_fetch_assoc($result_1to1)){
        $bookings_1to1_raw[] = $row;
    }
    mysqli_stmt_close($stmt_1to1);
} else {
    error_log("SQL Error fetching 1-to-1 bookings: " . mysqli_error($link));
}

mysqli_close($link);

// --- DATA PROCESSING ---

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$time_slots = [];
for ($i = 7; $i < 22; $i++) { // 7 AM to 10 PM
    $time_slots[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00:00';
}

// Initialize timetable structure
$timetable = [];
foreach ($days_of_week as $day) {
    foreach ($time_slots as $slot) {
        $timetable[$day][$slot] = [];
    }
}

// Populate with group classes
foreach ($group_classes_raw as $class) {
    $day = $class['day_of_week'];
    $start_time_H = date('H', strtotime($class['start_time']));
    $slot = $start_time_H . ':00:00';

    if (isset($timetable[$day][$slot])) {
        $timetable[$day][$slot][] = [
            'type' => 'group',
            'name' => $class['name'],
            'start_time' => date('H:i', strtotime($class['start_time'])),
            'end_time' => date('H:i', strtotime($class['end_time'])),
            'coach_name' => $class['coach_name'] ?? 'N/A'
        ];
    }
}

// Populate with 1-to-1 bookings
foreach ($bookings_1to1_raw as $booking) {
    $day = date('l', strtotime($booking['booking_date']));
    $start_time_H = date('H', strtotime($booking['start_time']));
    $slot = $start_time_H . ':00:00';

    if (isset($timetable[$day][$slot])) {
        $timetable[$day][$slot][] = [
            'type' => '1to1',
            'status' => $booking['status'], // 'pending' or 'confirmed'
            'name' => '1-to-1 Class',
            'start_time' => date('H:i', strtotime($booking['start_time'])),
            'end_time' => date('H:i', strtotime($booking['start_time'] . ' +1 hour')),
            'coach_name' => $booking['coach_name'],
            'member_name' => $booking['member_name']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Full Weekly Schedule - Admin Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .timetable-grid {
            display: grid;
            grid-template-columns: 80px repeat(7, minmax(120px, 1fr));
            gap: 4px;
        }
        .grid-header-cell, .grid-time-cell, .grid-class-cell {
            padding: 8px;
            text-align: center;
            border-radius: 8px;
        }
        .grid-header-cell { background-color: #e2e8f0; font-weight: 600; }
        .grid-time-cell { background-color: #f0f4f8; font-weight: 500; text-align: right; padding-right: 12px; }
        .grid-class-cell { background-color: #f9fafb; min-height: 80px; }
        .class-card {
            width: 100%;
            padding: 6px;
            margin-bottom: 4px;
            border-radius: 6px;
            font-size: 0.75rem;
            line-height: 1.1;
            border: 1px solid transparent;
        }
        .class-card-group { background-color: #dbeafe; border-color: #93c5fd; }
        .class-card-1to1-pending { background-color: #fef3c7; border-color: #fcd34d; }
        .class-card-1to1-confirmed { background-color: #d1fae5; border-color: #6ee7b7; }
        .class-name { font-weight: 600; color: #1f2937; }
        .class-details { color: #4b5563; }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="admin_dashboard.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Admin Dashboard</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">Full Weekly Schedule</h1>
        <p class="mt-2 text-lg text-gray-600">Combined view of all group classes and 1-to-1 bookings.</p>

        <div class="mt-8 bg-white p-6 rounded-2xl shadow-lg overflow-x-auto">
            <div class="timetable-grid">
                <div class="grid-header-cell"></div> <!-- Top-left empty corner -->
                <?php foreach ($days_of_week as $day): ?>
                    <div class="grid-header-cell"><?php echo htmlspecialchars($day); ?></div>
                <?php endforeach; ?>

                <?php foreach ($time_slots as $slot): ?>
                    <div class="grid-time-cell"><?php echo date('H:i', strtotime($slot)); ?></div>
                    <?php foreach ($days_of_week as $day): ?>
                        <div class="grid-class-cell">
                            <?php if (!empty($timetable[$day][$slot])): ?>
                                <?php foreach ($timetable[$day][$slot] as $class): ?>
                                    <?php
                                        $card_class = 'class-card-group'; // Default for group classes
                                        if ($class['type'] === '1to1') {
                                            $card_class = $class['status'] === 'pending' ? 'class-card-1to1-pending' : 'class-card-1to1-confirmed';
                                        }
                                    ?>
                                    <div class="class-card <?php echo $card_class; ?>">
                                        <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>
                                        <div class="class-details">
                                            <?php echo $class['start_time'] . ' - ' . $class['end_time']; ?><br>
                                            <strong>Coach:</strong> <?php echo htmlspecialchars($class['coach_name']); ?>
                                            <?php if ($class['type'] === '1to1'): ?>
                                                <br><strong>Member:</strong> <?php echo htmlspecialchars($class['member_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
