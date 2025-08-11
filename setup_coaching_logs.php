<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db_config.php";

// Check if user is admin
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: login.html");
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_tables'])) {
    try {
        // Create coaching_logs table
        $sql_coaching_logs = "CREATE TABLE IF NOT EXISTS coaching_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coach_id INT NOT NULL,
            class_id INT,
            class_date DATE NOT NULL,
            techniques_taught TEXT,
            attendance_count INT DEFAULT 0,
            what_went_well TEXT,
            improvements_needed TEXT,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
            INDEX idx_coach_date (coach_id, class_date),
            INDEX idx_class_date (class_id, class_date)
        )";
        
        if (mysqli_query($link, $sql_coaching_logs)) {
            $message .= "✓ coaching_logs table created successfully.<br>";
        } else {
            $error .= "✗ Error creating coaching_logs table: " . mysqli_error($link) . "<br>";
        }
        
        // Create training_logs table if it doesn't exist
        $sql_training_logs = "CREATE TABLE IF NOT EXISTS training_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            booking_id INT,
            session_date DATE NOT NULL,
            type VARCHAR(50) NOT NULL,
            topic_covered TEXT,
            partners TEXT,
            rating INT NOT NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
            INDEX idx_user_date (user_id, session_date),
            INDEX idx_booking (booking_id)
        )";
        
        if (mysqli_query($link, $sql_training_logs)) {
            $message .= "✓ training_logs table created successfully.<br>";
        } else {
            $error .= "✗ Error creating training_logs table: " . mysqli_error($link) . "<br>";
        }
        
    } catch (Exception $e) {
        $error .= "✗ Database error: " . $e->getMessage() . "<br>";
    }
}

// Check if tables exist
$coaching_logs_exists = false;
$training_logs_exists = false;

$result = mysqli_query($link, "SHOW TABLES LIKE 'coaching_logs'");
if ($result && mysqli_num_rows($result) > 0) {
    $coaching_logs_exists = true;
}

$result = mysqli_query($link, "SHOW TABLES LIKE 'training_logs'");
if ($result && mysqli_num_rows($result) > 0) {
    $training_logs_exists = true;
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup Coaching Logs - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8 max-w-4xl">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-6">Setup Coaching Logs</h1>
            
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-700 mb-4">Database Tables Status:</h2>
                <div class="space-y-2">
                    <div class="flex items-center">
                        <span class="w-4 h-4 rounded-full mr-3 <?php echo $coaching_logs_exists ? 'bg-green-500' : 'bg-red-500'; ?>"></span>
                        <span class="font-medium">coaching_logs table: <?php echo $coaching_logs_exists ? '✓ Exists' : '✗ Missing'; ?></span>
                    </div>
                    <div class="flex items-center">
                        <span class="w-4 h-4 rounded-full mr-3 <?php echo $training_logs_exists ? 'bg-green-500' : 'bg-red-500'; ?>"></span>
                        <span class="font-medium">training_logs table: <?php echo $training_logs_exists ? '✓ Exists' : '✗ Missing'; ?></span>
                    </div>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <strong>Success:</strong><br>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <strong>Error:</strong><br>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$coaching_logs_exists || !$training_logs_exists): ?>
                <form method="POST" class="space-y-4">
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
                        <strong>Note:</strong> This will create the necessary database tables for the coaching log feature.
                    </div>
                    <button type="submit" name="setup_tables" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition">
                        Create Database Tables
                    </button>
                </form>
            <?php else: ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <strong>✓ All tables are set up!</strong> The coaching log feature is ready to use.
                </div>
            <?php endif; ?>
            
            <div class="mt-8 pt-6 border-t">
                <h2 class="text-xl font-semibold text-gray-700 mb-4">Next Steps:</h2>
                <ul class="list-disc list-inside space-y-2 text-gray-600">
                    <li>Visit the <a href="coach/coach_dashboard.php" class="text-blue-600 hover:underline">Coach Dashboard</a> to access the coaching log feature</li>
                    <li>Coaches can now log their classes and track their teaching progress</li>
                    <li>The coaching log includes fields for techniques taught, attendance, what went well, and areas for improvement</li>
                </ul>
            </div>
            
            <div class="mt-6">
                <a href="admin/admin_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-gray-700 transition">
                    ← Back to Admin Dashboard
                </a>
            </div>
        </div>
    </div>
</body>
</html>
