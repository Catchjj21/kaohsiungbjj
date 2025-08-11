<?php
// Start the session
session_start();

// Include the database configuration file.
require_once "../db_config.php";

$message = "";
$message_class = "text-red-600"; // Default to error style

if (isset($_GET['id']) && isset($_GET['token']) && isset($_GET['action'])) {
    $booking_id = $_GET['id'];
    $token = $_GET['token'];
    $action = $_GET['action'];

    // Validate action
    if (!in_array($action, ['confirm', 'cancel'])) {
        $message = "Invalid action.";
    } else {
        // Find the booking and verify the token
        $sql = "SELECT confirmation_token, status FROM one_to_one_bookings WHERE id = ?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            mysqli_stmt_bind_param($stmt, "i", $booking_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $booking = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            if ($booking && $booking['confirmation_token'] === $token) {
                // Token is valid, now update the status
                $new_status = ($action === 'confirm') ? 'confirmed' : 'cancelled';
                
                $sql_update = "UPDATE one_to_one_bookings SET status = ? WHERE id = ?";
                if ($stmt_update = mysqli_prepare($link, $sql_update)) {
                    mysqli_stmt_bind_param($stmt_update, "si", $new_status, $booking_id);
                    if (mysqli_stmt_execute($stmt_update)) {
                        $message = "Booking has been successfully " . htmlspecialchars($new_status) . ".";
                        $message_class = "text-green-600";
                    } else {
                        $message = "An error occurred while updating the booking status.";
                    }
                    mysqli_stmt_close($stmt_update);
                } else {
                    $message = "Database error.";
                }
            } else {
                $message = "Invalid booking ID or token.";
            }
        }
    }
} else {
    $message = "Missing required parameters.";
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Booking Action</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <div class="flex items-center justify-center min-h-screen">
        <div class="bg-white p-8 rounded-2xl shadow-lg text-center max-w-sm w-full">
            <h1 class="text-3xl font-bold <?php echo $message_class; ?>"><?php echo htmlspecialchars($message); ?></h1>
            <a href="coach_schedule.php" class="mt-6 inline-block bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition">
                Back to Schedule
            </a>
        </div>
    </div>
</body>
</html>