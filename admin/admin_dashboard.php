<?php
// THE FIX: Include the database config FIRST.
require_once "../db_config.php";

// Now that the settings are loaded, start the session.
session_start();
 
// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    // FIX: Corrected the relative path to the login page.
    header("location: ../admin_login.html");
    exit;
}

$user_id = $_SESSION['id'];
// --- Fetch Stats for the Dashboard ---
$total_members_sql = "SELECT COUNT(id) AS total_members FROM users WHERE role = 'member'";
$total_members_result = mysqli_query($link, $total_members_sql);
$total_members = mysqli_fetch_assoc($total_members_result)['total_members'];

$active_memberships_sql = "SELECT COUNT(DISTINCT user_id) AS active_memberships FROM memberships WHERE end_date >= CURDATE()";
$active_memberships_result = mysqli_query($link, $active_memberships_sql);
$active_memberships = mysqli_fetch_assoc($active_memberships_result)['active_memberships'];

$today_bookings_sql = "SELECT COUNT(id) AS today_bookings FROM bookings WHERE booking_date = CURDATE()";
$today_bookings_result = mysqli_query($link, $today_bookings_sql);
$today_bookings = mysqli_fetch_assoc($today_bookings_result)['today_bookings'];

$active_adults_sql = "SELECT COUNT(DISTINCT u.id) AS active_adults FROM users u JOIN memberships m ON u.id = m.user_id WHERE u.member_type = 'Adult' AND m.end_date >= CURDATE()";
$active_adults_result = mysqli_query($link, $active_adults_sql);
$active_adults = mysqli_fetch_assoc($active_adults_result)['active_adults'];

$active_kids_sql = "SELECT COUNT(DISTINCT u.id) AS active_kids FROM users u JOIN memberships m ON u.id = m.user_id WHERE u.member_type = 'Kid' AND m.end_date >= CURDATE()";
$active_kids_result = mysqli_query($link, $active_kids_sql);
$active_kids = mysqli_fetch_assoc($active_kids_result)['active_kids'];

$start_of_week = date('Y-m-d', strtotime('monday this week'));
$end_of_week = date('Y-m-d', strtotime('sunday this week'));
$week_bookings_sql = "SELECT COUNT(id) AS week_bookings FROM bookings WHERE booking_date BETWEEN ? AND ?";
$stmt_week = mysqli_prepare($link, $week_bookings_sql);
mysqli_stmt_bind_param($stmt_week, "ss", $start_of_week, $end_of_week);
mysqli_stmt_execute($stmt_week);
$week_bookings_result = mysqli_stmt_get_result($stmt_week);
$week_bookings = mysqli_fetch_assoc($week_bookings_result)['week_bookings'];
mysqli_stmt_close($stmt_week);

// --- Fetch Unread Message Count for the Badge ---
$unread_count = 0;
$sql_unread = "SELECT COUNT(*) as count FROM message_recipients WHERE recipient_id = ? AND is_read = 0";
if ($stmt_unread = mysqli_prepare($link, $sql_unread)) {
    mysqli_stmt_bind_param($stmt_unread, "i", $user_id);
    mysqli_stmt_execute($stmt_unread);
    $unread_count = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_unread))['count'] ?? 0;
    mysqli_stmt_close($stmt_unread);
}

mysqli_close($link);
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800">Admin Portal</span>
            <div>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <div id="main-dashboard">
            <h1 class="text-4xl font-black text-gray-800">Welcome, <?php echo htmlspecialchars($_SESSION["full_name"]); ?>!</h1>
            <p class="mt-2 text-lg text-gray-600">Manage your gym's operations from here.</p>

            <!-- Stats Section -->
            <div class="mt-8">
                <h2 class="text-2xl font-bold text-gray-800">At a Glance</h2>
                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- Stat Cards -->
                    <div class="bg-white p-6 rounded-2xl shadow-lg flex items-center">
                        <div class="bg-blue-100 p-3 rounded-full"><svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg></div>
                        <div class="ml-4"><p class="text-sm text-gray-600">Total Members</p><p class="text-2xl font-bold text-gray-900"><?php echo $total_members; ?></p></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-lg flex items-center">
                        <div class="bg-green-100 p-3 rounded-full"><svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div>
                        <div class="ml-4"><p class="text-sm text-gray-600">Active Memberships</p><p class="text-2xl font-bold text-gray-900"><?php echo $active_memberships; ?></p></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-lg flex items-center">
                        <div class="bg-yellow-100 p-3 rounded-full"><svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></div>
                        <div class="ml-4"><p class="text-sm text-gray-600">Today's Bookings</p><p class="text-2xl font-bold text-gray-900"><?php echo $today_bookings; ?></p></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-lg flex items-center">
                        <div class="bg-indigo-100 p-3 rounded-full"><svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg></div>
                        <div class="ml-4"><p class="text-sm text-gray-600">Active Adults</p><p class="text-2xl font-bold text-gray-900"><?php echo $active_adults; ?></p></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-lg flex items-center">
                        <div class="bg-pink-100 p-3 rounded-full"><svg class="w-6 h-6 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v1.586m-6.253 6.253H7.586m1.586 6.253v-1.586m6.253-6.253h-1.586M12 12a2.344 2.344 0 01-2.344-2.344A2.344 2.344 0 0112 7.312a2.344 2.344 0 012.344 2.344A2.344 2.344 0 0112 12zm0 0a2.344 2.344 0 00-2.344 2.344A2.344 2.344 0 0012 16.688a2.344 2.344 0 002.344-2.344A2.344 2.344 0 0012 12z"></path></svg></div>
                        <div class="ml-4"><p class="text-sm text-gray-600">Active Kids</p><p class="text-2xl font-bold text-gray-900"><?php echo $active_kids; ?></p></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-lg flex items-center">
                        <div class="bg-red-100 p-3 rounded-full"><svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></div>
                        <div class="ml-4"><p class="text-sm text-gray-600">This Week's Bookings</p><p class="text-2xl font-bold text-gray-900"><?php echo $week_bookings; ?></p></div>
                    </div>
                </div>
            </div>

            <!-- Management Sections -->
            <div class="mt-8 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="bg-white p-8 rounded-2xl shadow-lg relative">
                    <h2 class="text-2xl font-bold text-gray-800">Messaging</h2>
                    <p class="text-gray-600 mt-1">View inbox and send messages to members.</p>
                    <a href="admin_messaging.php" class="mt-4 inline-block bg-cyan-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-cyan-600 transition">
                        Open Messaging
                    </a>
                    <?php if ($unread_count > 0): ?>
                        <span class="absolute top-4 right-4 flex h-6 w-6">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-6 w-6 bg-red-500 text-white text-xs justify-center items-center font-bold"><?php echo $unread_count; ?></span>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Bookings</h2>
                    <p class="text-gray-600 mt-1">View the weekly schedule to manage attendance.</p>
                    <a href="weekly_view.php" class="mt-4 inline-block bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition">
                        View Weekly Schedule
                    </a>
                </div>
                <!-- New Full Schedule Module -->
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Full Schedule</h2>
                    <p class="text-gray-600 mt-1">View and manage the complete schedule of all classes.</p>
                    <a href="admin_schedule_view.php" class="mt-4 inline-block bg-fuchsia-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-fuchsia-700 transition">
                        View Full Schedule
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Members</h2>
                    <p class="text-gray-600 mt-1">View and update member information.</p>
                    <a href="manage_members.php" class="mt-4 inline-block bg-green-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-green-700 transition">
                        Go to Member Management
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Classes</h2>
                    <p class="text-gray-600 mt-1">Add, edit, or deactivate classes from the schedule.</p>
                    <a href="manage_classes.php" class="mt-4 inline-block bg-indigo-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-indigo-700 transition">
                        Go to Class Management
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Plans</h2>
                    <p class="text-gray-600 mt-1">Add, edit, or update membership plans.</p>
                    <a href="manage_plans.php" class="mt-4 inline-block bg-yellow-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-yellow-600 transition">
                        Go to Plan Management
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Site Content</h2>
                    <p class="text-gray-600 mt-1">Create and manage news, announcements, and events for your website.</p>
                    <a href="manage_content.php" class="mt-4 inline-block bg-purple-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-purple-700 transition">
                        Go to Content Management
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Member Check-In</h2>
                    <p class="text-gray-600 mt-1">Check members in quickly using a QR code scanner.</p>
                    <a href="check_in.php" class="mt-4 inline-block bg-green-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-green-600 transition">
                        Go to Check-In Portal
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Events</h2>
                    <p class="text-gray-600 mt-1">Add, edit, and publish special events.</p>
                    <a href="manage_events.php" class="mt-4 inline-block bg-teal-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-teal-600 transition">
                        Go to Event Management
                    </a>
                </div>
                <div class="bg-white p-8 rounded-2xl shadow-lg">
                    <h2 class="text-2xl font-bold text-gray-800">Manage Family Linking</h2>
                    <p class="text-gray-600 mt-1">Link child accounts to their parent's account.</p>
                    <a href="manage_family_linking.php" class="mt-4 inline-block bg-orange-500 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-orange-600 transition">
                        Go to Family Linking
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
