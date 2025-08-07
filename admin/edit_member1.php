<?php
ini_set('display_errors', 1); // Enable error display for debugging
ini_set('display_startup_errors', 1); // Show startup errors
error_reporting(E_ALL); // Report all errors

// Initialize the session
session_start();
 
// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    header("location: admin_login.html");
    exit;
}

// Include the database configuration file
require_once "../db_config.php";

// Check if an ID was passed
if(!isset($_GET["id"]) || empty(trim($_GET["id"]))){
    header("location: manage_members.php");
    exit;
}

$member_id = trim($_GET["id"]);

// Fetch member data from the database, now including class_credits
// MODIFIED: Selecting first_name, last_name, belt_color, and membership_type
$sql = "SELECT 
            u.id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            u.belt_color,           -- Corrected to belt_color
            u.profile_picture_url,
            m.id AS membership_id,
            m.membership_type,      -- Corrected to membership_type
            m.start_date,
            m.end_date,
            m.class_credits
        FROM users u
        LEFT JOIN memberships m ON u.id = m.user_id 
        AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = u.id)
        WHERE u.id = ?";

$member = null;
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $member_id);
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if(mysqli_num_rows($result) == 1){
            $member = mysqli_fetch_assoc($result);
            // Dynamically create a 'full_name' key for display convenience
            $member['full_name'] = trim($member['first_name'] . ' ' . $member['last_name']);
        } else{
            // No member found with that ID
            header("location: manage_members.php");
            exit;
        }
    } else {
        // Output SQL execution error if it fails
        echo "Error executing query: " . mysqli_error($link);
        exit;
    }
    mysqli_stmt_close($stmt);
} else {
    // Output SQL prepare error if it fails
    echo "Error preparing statement: " . mysqli_error($link);
    exit;
}
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Member - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family:Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">

        <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="manage_members.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Member List</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>

        <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">Edit Member</h1>
        <p class="mt-2 text-lg text-gray-600">Updating information for <strong><?php echo htmlspecialchars($member['full_name']); ?></strong></p>

        <div class="mt-8 bg-white p-8 rounded-2xl shadow-lg max-w-4xl mx-auto">
            <form action="edit_member_handler.php" method="POST" class="space-y-6">
                                <input type="hidden" name="user_id" value="<?php echo $member['id']; ?>">
                <input type="hidden" name="membership_id" value="<?php echo $member['membership_id'] ?? ''; ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                        <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($member['first_name']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                        <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($member['last_name']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($member['email']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                    <div>
                        <label for="belt_color" class="block text-sm font-medium text-gray-700">Belt Color</label>
                        <select id="belt_color" name="belt_color" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <?php 
                            $belts = ['White Belt', 'Blue Belt', 'Purple Belt', 'Brown Belt', 'Black Belt'];
                            foreach ($belts as $belt) {
                                // Use belt_color from fetched member data
                                $selected = ($member['belt_color'] == $belt) ? 'selected' : '';
                                echo "<option value='$belt' $selected>$belt</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label for="profile_picture_url" class="block text-sm font-medium text-gray-700">Profile Picture URL</label>
                        <input type="text" name="profile_picture_url" id="profile_picture_url" value="<?php echo htmlspecialchars($member['profile_picture_url']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                    </div>
                </div>

                <hr class="my-6">

                <h2 class="text-xl font-bold text-gray-800">Membership Details</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="membership_type" class="block text-sm font-medium text-gray-700">Membership Type</label>
                        <select id="membership_type" name="membership_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
                            <?php 
                            $types = ['None', 'All-Inclusive Pass', 'Jiu Jitsu Only Pass', '4 Class Pass', '10 Class Pass'];
                            foreach ($types as $type) {
                                // Use membership_type from fetched member data
                                $selected = ($member['membership_type'] == $type) ? 'selected' : '';
                                echo "<option value='$type' $selected>$type</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label for="start_date" class="block text-sm font-medium text-gray-700">Membership Start Date</label>
                        <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($member['start_date']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                    <div>
                        <label for="end_date" class="block text-sm font-medium text-gray-700">Membership Expiry Date</label>
                        <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($member['end_date']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                </div>
                                <div id="class-credits-field">
                    <label for="class_credits" class="block text-sm font-medium text-gray-700">Class Credits</label>
                    <input type="number" name="class_credits" id="class_credits" value="<?php echo htmlspecialchars($member['class_credits'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    <p class="mt-2 text-xs text-gray-500">Only for class passes. Leave blank for unlimited memberships.</p>
                </div>

                <div class="pt-5">
                    <div class="flex justify-end">
                        <a href="manage_members.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                        <button type="submit" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>