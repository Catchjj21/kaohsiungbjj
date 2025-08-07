<?php
// Enable error display for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =========================================================================
//                              CORRECTED ORDER
// =========================================================================
// 1. First, require the database configuration file. This file likely contains
//    session_set_cookie_params(), which MUST be called before session_start().
require_once "../db_config.php";

// 2. NOW, start the session after its parameters have been configured.
session_start();
// =========================================================================


// =========================================================================
// SECURITY CHECK - UPDATED
// =========================================================================
// This check ensures that only a logged-in administrator or coach can view this page.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    // UPDATED: Store the requested URL in the session before redirecting
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    
    // If not logged in, redirect to the admin login page
    header("location: ../admin_login.html");
    exit;
}
// =========================================================================


$user_id = $_GET['user_id'] ?? 0;
$user_data = null;
$plans = [];
$message = '';
$message_type = '';

// Validate user ID and fetch user data
if (filter_var($user_id, FILTER_VALIDATE_INT) && $user_id > 0) {
    // Fetch user details
    $sql = "SELECT first_name, last_name, email, dob, member_type FROM users WHERE id = ?";
    if ($stmt = mysqli_prepare($link, $sql)) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);
            if (mysqli_num_rows($result) == 1) {
                $user_data = mysqli_fetch_assoc($result);
            } else {
                $message = "Error: No user found with this ID.";
                $message_type = 'error';
            }
        }
        mysqli_stmt_close($stmt);
    }

    // Fetch membership plans
    $sql_plans = "SELECT id, plan_name, duration_days FROM membership_plans ORDER BY id";
    if ($result_plans = mysqli_query($link, $sql_plans)) {
        while ($row = mysqli_fetch_assoc($result_plans)) {
            $plans[] = $row;
        }
    }
} else {
    $message = "Invalid User ID provided.";
    $message_type = 'error';
}


// Handle form submission to create membership
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_membership'])) {
    $plan_id = $_POST['plan_id'] ?? 0;
    $start_date_str = $_POST['start_date'] ?? '';

    if (filter_var($plan_id, FILTER_VALIDATE_INT) && $plan_id > 0 && !empty($start_date_str)) {
        // Find the selected plan's duration
        $duration_days = 0;
        foreach ($plans as $plan) {
            if ($plan['id'] == $plan_id) {
                $duration_days = $plan['duration_days'];
                break;
            }
        }

        if ($duration_days > 0) {
            $start_date = new DateTime($start_date_str);
            $end_date = clone $start_date;
            $end_date->modify("+" . $duration_days . " days");

            $sql_insert = "INSERT INTO memberships (user_id, plan_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')";
            if ($stmt_insert = mysqli_prepare($link, $sql_insert)) {
                $start_date_formatted = $start_date->format('Y-m-d');
                $end_date_formatted = $end_date->format('Y-m-d');
                mysqli_stmt_bind_param($stmt_insert, "iiss", $user_id, $plan_id, $start_date_formatted, $end_date_formatted);
                if (mysqli_stmt_execute($stmt_insert)) {
                    $message = "Membership successfully assigned to " . htmlspecialchars($user_data['first_name']) . "!";
                    $message_type = 'success';
                } else {
                    $message = "Error: Could not assign membership.";
                    $message_type = 'error';
                }
                mysqli_stmt_close($stmt_insert);
            }
        } else {
            $message = "Invalid plan selected.";
            $message_type = 'error';
        }
    } else {
        $message = "Please select a plan and a start date.";
        $message_type = 'error';
    }
}

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Complete Registration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Admin Header -->
    <nav class="bg-gray-800 text-white shadow-lg">
        <div class="container mx-auto px-6 py-4">
            <h1 class="text-2xl font-bold">Admin Panel</h1>
        </div>
    </nav>

    <div class="container mx-auto p-6">
        <!-- MODIFIED: Flex container for title and button -->
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-3xl font-bold text-gray-800">Complete New Member Registration</h2>
            <!-- ADDED: Back to Dashboard Button -->
            <a href="admin_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition-colors">
                Back to Dashboard
            </a>
        </div>

        <!-- MODIFIED: Added logic to show dashboard link on success -->
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                <p><?php echo htmlspecialchars($message); ?></p>
                <?php if ($message_type === 'success'): ?>
                    <a href="admin_dashboard.php" class="mt-4 inline-block bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700">
                        Return to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($user_data && $message_type !== 'success'): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Member Details -->
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <h3 class="text-2xl font-bold border-b pb-3 mb-4">Member Details</h3>
                    <div class="space-y-3 text-gray-700">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($user_data['email']); ?></p>
                        <p><strong>Date of Birth:</strong> <?php echo htmlspecialchars($user_data['dob']); ?></p>
                        <p><strong>Membership Type:</strong> <span class="capitalize font-semibold"><?php echo htmlspecialchars($user_data['member_type']); ?></span></p>
                    </div>
                </div>

                <!-- Assign Membership Form -->
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <h3 class="text-2xl font-bold border-b pb-3 mb-4">Assign Membership</h3>
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']) . '?user_id=' . $user_id; ?>" method="POST">
                        <input type="hidden" name="assign_membership" value="1">
                        
                        <div class="mb-4">
                            <label for="plan_id" class="block text-sm font-medium text-gray-700 mb-1">Membership Plan</label>
                            <select id="plan_id" name="plan_id" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" required>
                                <option value="" disabled selected>Select a plan...</option>
                                <?php foreach ($plans as $plan): ?>
                                    <option value="<?php echo $plan['id']; ?>">
                                        <?php echo htmlspecialchars($plan['plan_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-6">
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Membership Start Date</label>
                            <input type="date" id="start_date" name="start_date" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500" required>
                        </div>

                        <div>
                            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 transition-colors">
                                Assign Membership Plan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($message_type !== 'success'): ?>
            <div class="bg-white p-6 rounded-lg shadow-md text-center">
                <p class="text-gray-700">Could not load user data. Please check the user ID and try again.</p>
                <a href="admin_dashboard.php" class="mt-4 inline-block text-blue-600 hover:underline">Return to Admin Dashboard</a>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
