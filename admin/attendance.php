<?php
// Initialize the session
session_start();
 
// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    header("location: ../admin_login.html");
    exit;
}

// Include the database configuration file
require_once "../db_config.php";

// Get the selected date from the form submission
$selected_date = $_POST['booking_date'] ?? date('Y-m-d');

// Fetch all bookings for the selected date
$sql = "SELECT 
            b.id AS booking_id,
            b.status,
            c.name AS class_name,
            c.start_time,
            u.full_name AS member_name
        FROM bookings b
        JOIN users u ON b.user_id = u.id
        JOIN classes c ON b.class_id = c.id
        WHERE b.booking_date = ?
        ORDER BY c.start_time, u.full_name";

$bookings = [];
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "s", $selected_date);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        while($row = mysqli_fetch_assoc($result)){
            $bookings[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
}
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Class Attendance - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="admin_dashboard.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Admin Dashboard</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">Class Attendance</h1>
        <p class="mt-2 text-lg text-gray-600">Showing all bookings for: <strong><?php echo date("F j, Y", strtotime($selected_date)); ?></strong></p>

        <div class="mt-8 bg-white p-6 rounded-2xl shadow-lg overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b-2">
                        <th class="py-3 px-4">Class</th>
                        <th class="py-3 px-4">Time</th>
                        <th class="py-3 px-4">Member</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-10 text-gray-500">No bookings found for this date.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bookings as $booking): ?>
                            <tr class="border-b">
                                <td class="py-3 px-4"><?php echo htmlspecialchars($booking['class_name']); ?></td>
                                <td class="py-3 px-4"><?php echo date("g:i A", strtotime($booking['start_time'])); ?></td>
                                <td class="py-3 px-4"><?php echo htmlspecialchars($booking['member_name']); ?></td>
                                <td class="py-3 px-4">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $booking['status'] === 'attended' ? 'bg-green-200 text-green-800' : 'bg-blue-200 text-blue-800'; ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if ($booking['status'] === 'booked'): ?>
                                        <button class="mark-attended-btn bg-green-500 text-white text-xs font-bold py-1 px-3 rounded hover:bg-green-600" data-booking-id="<?php echo $booking['booking_id']; ?>">
                                            Mark Attended
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.mark-attended-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const bookingId = this.dataset.bookingId;
                
                try {
                    const response = await fetch('attendance_handler.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ booking_id: bookingId })
                    });
                    const result = await response.json();
                    if (response.ok) {
                        // Reload the page to show the updated status
                        window.location.reload();
                    } else {
                        alert(result.message || 'An error occurred.');
                    }
                } catch (error) {
                    alert('A network error occurred.');
                }
            });
        });
    });
    </script>
</body>
</html>
