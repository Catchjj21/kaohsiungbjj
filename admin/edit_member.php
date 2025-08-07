<?php
// Start output buffering to prevent "headers already sent" errors
ob_start();

// Disable error reporting for cleaner output
ini_set('display_errors', 'Off');
ini_set('display_startup_errors', 'Off');
error_reporting(0);

// THE FIX: Include the database config FIRST.
// This loads the session cookie settings from db_config.php BEFORE the session is started.
require_once "../db_config.php";

// Now that the settings are loaded, start the session.
session_start();

// Check if the user is logged in and is an admin or coach.
// FIX: Changed $_SESSION["loggedin"] to $_SESSION["admin_loggedin"] for consistency.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    header("location: admin_login.html");
    exit;
}

// Check if a member ID is provided in the URL
if(!isset($_GET["id"]) || empty(trim($_GET["id"]))){
    header("location: manage_members.php");
    exit;
}

$member_id = trim($_GET["id"]);
$member = null;
$membership_plans = []; // Initialize array to hold membership plans

// Fetch member details including new fields: phone_number, member_type, dob, line_id, address, chinese_name, and profile_picture_url
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone_number, u.member_type, u.belt_color, u.dob, u.line_id, u.address, u.chinese_name, u.profile_picture_url, u.default_language, u.old_card, u.role, m.id AS membership_id, m.membership_type, m.start_date, m.end_date, m.class_credits FROM users u LEFT JOIN memberships m ON u.id = m.user_id AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = u.id) WHERE u.id = ?";
if($stmt = mysqli_prepare($link, $sql)){
    mysqli_stmt_bind_param($stmt, "i", $member_id); // Bind the member ID as an integer
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if(mysqli_num_rows($result) == 1){
            $member = mysqli_fetch_assoc($result); // Fetch the member's data
            $member['full_name'] = trim($member['first_name'] . ' ' . $member['last_name']);
        } else{
            // If no member found with the given ID, redirect
            header("location: manage_members.php");
            exit;
        }
    } else {
        // Handle SQL execution error
        error_log("Error executing query: " . mysqli_error($link));
        exit;
    }
    mysqli_stmt_close($stmt); // Close the statement for member details
} else {
    // Handle SQL preparation error
    error_log("Error preparing statement: " . mysqli_error($link));
    exit;
}

// Fetch all membership plans from the membership_plans table, sorted by name then duration.
$sql_plans = "SELECT id, plan_name, category, duration_days, price FROM membership_plans ORDER BY plan_name ASC, duration_days ASC";
if ($result_plans = mysqli_query($link, $sql_plans)) {
    while ($row_plan = mysqli_fetch_assoc($result_plans)) {
        $membership_plans[] = $row_plan; // Add each plan to the array
    }
    mysqli_free_result($result_plans); // Free the result set for plans
} else {
    // Handle error if plans cannot be fetched
    error_log("Error fetching membership plans: " . mysqli_error($link));
}

// NEW: Determine the current membership plan ID based on the member's membership_type
$current_membership_plan_id = null;
if (!empty($member['membership_type'])) {
    $sql_get_plan_id = "SELECT id FROM membership_plans WHERE plan_name = ?";
    if ($stmt_plan_id = mysqli_prepare($link, $sql_get_plan_id)) {
        mysqli_stmt_bind_param($stmt_plan_id, "s", $member['membership_type']);
        mysqli_stmt_execute($stmt_plan_id);
        mysqli_stmt_bind_result($stmt_plan_id, $plan_id);
        if (mysqli_stmt_fetch($stmt_plan_id)) {
            $current_membership_plan_id = $plan_id;
        }
        mysqli_stmt_close($stmt_plan_id);
    }
}

mysqli_close($link); // Close the database connection after all data is fetched

// Get unique categories for tab filtering
$categories = array_unique(array_column($membership_plans, 'category'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Member - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        .belt-white { background-color: #f0f0f0; }
        .belt-blue { background-color: #3b82f6; color: white; }
        .belt-grey { background-color: #9ca3af; color: white; }
        .belt-purple { background-color: #8b5cf6; color: white; }
        .belt-brown { background-color: #a16207; color: white; }
        .belt-black { background-color: #1f2937; color: white; }
        /* Cropper.js container styles */
        .cropper-container {
            max-width: 100%;
        }
        .membership-card-selected {
            border-color: #3b82f6;
            box-shadow: 0 4px 14px 0 rgba(59, 130, 246, 0.3);
            transform: scale(1.02);
            position: relative;
        }
        .membership-card-selected::after {
            content: "✓";
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            width: 1.5rem;
            height: 1.5rem;
            background-color: #3b82f6;
            color: white;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="manage_members.php" class="text-sm text-blue-600 hover:underline flex items-center">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Back to Member List
                </a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition duration-200 ease-in-out">Logout</a>
        </div>
    </nav>

    <div class="container mx-auto px-6 py-12 flex-grow">
        <h1 class="text-4xl font-black text-gray-800">Edit Member</h1>
        <p class="mt-2 text-lg text-gray-600">Updating information for <strong><?php echo htmlspecialchars($member['full_name']); ?></strong></p>

        <div class="mt-8 bg-white p-8 rounded-2xl shadow-lg max-w-5xl mx-auto">
            <form action="edit_member_handler.php" method="POST" class="space-y-8" enctype="multipart/form-data">
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($member['id']); ?>">
                <input type="hidden" id="cropped_image_data" name="cropped_image_data">
                
                <!-- Profile Picture and Basic Info -->
                <div class="flex flex-col md:flex-row items-center md:items-start gap-8 border-b pb-8 border-gray-200">
                    <div class="flex-shrink-0 relative group">
                        <img id="profile_picture_preview" 
                                src="<?php echo htmlspecialchars($member['profile_picture_url'] ? '../' . $member['profile_picture_url'] : 'https://placehold.co/150x150/e0e0e0/ffffff?text=No+Image'); ?>" 
                                alt="Profile Picture" 
                                class="w-32 h-32 rounded-full object-cover shadow-md border-4 border-gray-200 cursor-pointer"
                                onclick="document.getElementById('profile_picture_upload').click();">
                        <input type="file" name="profile_picture_upload" id="profile_picture_upload" class="hidden" accept="image/*">
                        
                        <div class="absolute inset-0 w-32 h-32 bg-gray-900 bg-opacity-50 rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 cursor-pointer" onclick="document.getElementById('profile_picture_upload').click();">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.842-1.683A2 2 0 0110.536 4h2.928a2 2 0 011.664.89l.842 1.683A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </div>
                    </div>

                    <div class="flex-grow text-center md:text-left">
                        <h2 class="text-2xl font-bold text-gray-800 mb-2">Personal Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                                <input type="text" name="first_name" id="first_name" value="<?php echo htmlspecialchars($member['first_name']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                                <input type="text" name="last_name" id="last_name" value="<?php echo htmlspecialchars($member['last_name']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="chinese_name" class="block text-sm font-medium text-gray-700">Chinese Name</label>
                                <input type="text" name="chinese_name" id="chinese_name" value="<?php echo htmlspecialchars($member['chinese_name'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($member['email']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="phone_number" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="tel" name="phone_number" id="phone_number" value="<?php echo htmlspecialchars($member['phone_number'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="line_id" class="block text-sm font-medium text-gray-700">LINE ID</label>
                                <input type="text" name="line_id" id="line_id" value="<?php echo htmlspecialchars($member['line_id'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="dob" class="block text-sm font-medium text-gray-700">Date of Birth</label>
                                <input type="date" name="dob" id="dob" value="<?php echo htmlspecialchars($member['dob'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div class="md:col-span-2">
                                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                                <input type="text" name="address" id="address" value="<?php echo htmlspecialchars($member['address'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                            </div>
                            <div>
                                <label for="old_card" class="block text-sm font-medium text-gray-700">QR Code / Member ID</label>
                                <input type="text" name="old_card" id="old_card" value="<?php echo htmlspecialchars($member['old_card'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                                <p class="mt-2 text-xs text-gray-500">The ID for check-in using a physical card or QR code scanner.</p>
                            </div>
                            <div>
                                <label for="member_type" class="block text-sm font-medium text-gray-700">Member Type</label>
                                <select id="member_type" name="member_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                                    <?php
                                    $member_types = ['Adult', 'Kid'];
                                    foreach ($member_types as $type) {
                                        $selected = ($member['member_type'] == $type) ? 'selected' : '';
                                        echo "<option value='$type' $selected>" . htmlspecialchars($type) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="role" class="block text-sm font-medium text-gray-700">Role</label>
                                <select id="role" name="role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                                    <?php
                                    $roles = ['member', 'coach', 'admin', 'parent'];
                                    foreach ($roles as $role_option) {
                                        $selected = ($member['role'] == $role_option) ? 'selected' : '';
                                        echo "<option value='$role_option' $selected>" . htmlspecialchars(ucfirst($role_option)) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="belt_color" class="block text-sm font-medium text-gray-700">Belt Color</label>
                                <select id="belt_color" name="belt_color" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                                    <?php
                                    $belts = ['White Belt', 'Blue Belt','Grey Belt', 'Purple Belt', 'Brown Belt', 'Black Belt'];
                                    foreach ($belts as $belt) {
                                        $selected = ($member['belt_color'] == $belt) ? 'selected' : '';
                                        echo "<option value='$belt' $selected>$belt</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="default_language" class="block text-sm font-medium text-gray-700">Default Language</label>
                                <select id="default_language" name="default_language" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm p-2">
                                    <option value="en" <?php echo ($member['default_language'] == 'en') ? 'selected' : ''; ?>>English</option>
                                    <option value="zh" <?php echo ($member['default_language'] == 'zh') ? 'selected' : ''; ?>>Chinese</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Membership Details Section (New Module) -->
                <div class="space-y-6">
                    <h2 class="text-2xl font-bold text-gray-800">Membership Details</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="current_plan_display" class="block text-sm font-medium text-gray-700">Current Plan</label>
                            <div id="current_plan_display" class="mt-1 flex items-center justify-between rounded-md border border-gray-300 shadow-sm p-2 bg-gray-50">
                                <span><?php echo htmlspecialchars($member['membership_type'] ?? 'No Plan Selected'); ?></span>
                                <button type="button" id="edit-membership-btn" class="text-blue-600 hover:text-blue-800 font-medium">Edit</button>
                            </div>
                            <!-- FIX: The membership_id is correctly set here from the memberships table -->
                            <input type="hidden" name="membership_id" id="membership_id" value="<?php echo htmlspecialchars($member['membership_id'] ?? ''); ?>">
                            <!-- FIX: We now use the plan ID from the membership_plans table -->
                            <input type="hidden" name="membership_plan_id" id="membership_plan_id" value="<?php echo htmlspecialchars($current_membership_plan_id ?? ''); ?>">
                        </div>
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700">Membership Start Date</label>
                            <input type="date" name="start_date" id="start_date" value="<?php echo htmlspecialchars($member['start_date'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700">Membership Expiry Date</label>
                            <input type="date" name="end_date" id="end_date" value="<?php echo htmlspecialchars($member['end_date'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                        </div>
                        <div id="class-credits-field" class="md:col-span-2" style="display: <?php echo ($member['membership_type'] == '4 Class Pass' || $member['membership_type'] == '10 Class Pass') ? 'block' : 'none'; ?>;">
                            <label for="class_credits" class="block text-sm font-medium text-gray-700">Class Credits</label>
                            <input type="number" name="class_credits" id="class_credits" value="<?php echo htmlspecialchars($member['class_credits'] ?? ''); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2">
                            <p class="mt-2 text-xs text-gray-500">Only for class passes. Leave blank for unlimited memberships.</p>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="pt-5 border-t border-gray-200 mt-8">
                    <div class="flex justify-end space-x-3">
                        <a href="manage_members.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200 ease-in-out">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200 ease-in-out">
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cropper Modal -->
    <div id="cropper-modal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                Crop Profile Picture
                            </h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500">
                                    Please crop and adjust your image to fit the circular profile picture.
                                </p>
                                <div class="mt-4 max-h-96">
                                    <img id="image-to-crop" class="max-w-full max-h-full block">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" id="crop-button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Crop & Save
                    </button>
                    <button type="button" id="cancel-crop-button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Membership Modal (NEW) -->
    <div id="membership-modal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-5xl sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 class="text-2xl font-bold text-gray-800" id="modal-title">Select Membership Plan</h3>
                            <p class="mt-2 text-sm text-gray-500">Choose a plan for the member. You can filter by category below.</p>
                            
                            <div class="mt-6">
                                <div class="flex flex-wrap gap-2 md:gap-4 border-b border-gray-200">
                                    <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t-lg transition-colors duration-200 ease-in-out border-b-2 text-gray-500 hover:text-gray-700 border-transparent" data-category="all">All Plans</button>
                                    <?php foreach($categories as $category): ?>
                                        <button type="button" class="tab-btn px-4 py-2 text-sm font-medium rounded-t-lg transition-colors duration-200 ease-in-out border-b-2 text-gray-500 hover:text-gray-700 border-transparent" data-category="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars(ucfirst($category)); ?></button>
                                    <?php endforeach; ?>
                                </div>
                                <div id="plans-container" class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 max-h-96 overflow-y-auto">
                                    <?php foreach($membership_plans as $plan): ?>
                                        <div class="membership-card p-6 border-2 rounded-xl shadow-md cursor-pointer transition-all duration-200 ease-in-out hover:shadow-lg hover:border-blue-300" 
                                            data-plan-id="<?php echo htmlspecialchars($plan['id']); ?>" 
                                            data-plan-name="<?php echo htmlspecialchars($plan['plan_name']); ?>"
                                            data-duration-days="<?php echo htmlspecialchars($plan['duration_days']); ?>"
                                            data-category="<?php echo htmlspecialchars($plan['category']); ?>">
                                            <h3 class="text-xl font-bold text-gray-800"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                                            <p class="text-gray-500 text-sm mt-1"><?php echo htmlspecialchars($plan['duration_days']); ?> days, <?php echo htmlspecialchars($plan['category']); ?></p>
                                            <p class="text-2xl font-black text-blue-600 mt-4">$<?php echo htmlspecialchars($plan['price']); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" id="select-plan-button" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Select Plan
                    </button>
                    <button type="button" id="cancel-plan-button" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>


    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Get all membership plans from PHP and store them as a JavaScript object
            const membershipPlans = <?php echo json_encode($membership_plans); ?>;
            
            // Get references to DOM elements
            const membershipIdInput = document.getElementById('membership_id');
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            const classCreditsField = document.getElementById('class-credits-field');
            const membershipPlanIdInput = document.getElementById('membership_plan_id');
            const plansContainer = document.getElementById('plans-container');
            const tabButtons = document.querySelectorAll('.tab-btn');
            const currentPlanDisplay = document.getElementById('current_plan_display');
            const editMembershipBtn = document.getElementById('edit-membership-btn');
            const membershipModal = document.getElementById('membership-modal');
            const cancelPlanButton = document.getElementById('cancel-plan-button');
            const selectPlanButton = document.getElementById('select-plan-button');

            // Cropper.js logic
            const profilePictureUpload = document.getElementById('profile_picture_upload');
            const profilePicturePreview = document.getElementById('profile_picture_preview');
            const cropperModal = document.getElementById('cropper-modal');
            const imageToCrop = document.getElementById('image-to-crop');
            const cropButton = document.getElementById('crop-button');
            const cancelCropButton = document.getElementById('cancel-crop-button');
            const croppedImageDataInput = document.getElementById('cropped_image_data');

            let cropper;

            function formatDate(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }

            // Function to update end date and class credits based on selected plan
            function updateEndDate() {
                const selectedPlanId = membershipPlanIdInput.value;
                const selectedPlan = membershipPlans.find(p => p.id == selectedPlanId);
                
                // Do not update dates or credits if no plan is selected or if the plan ID is invalid
                if (!selectedPlan) {
                    classCreditsField.style.display = 'none';
                    return;
                }

                // If a plan is selected, update UI elements
                const isClassPass = selectedPlan.plan_name.includes('Class Pass');
                classCreditsField.style.display = isClassPass ? 'block' : 'none';

                if (isClassPass) {
                    // Pre-fill class credits for new selections only, otherwise respect saved value
                    const currentClassCredits = document.getElementById('class_credits').value;
                    if (!currentClassCredits || isNaN(currentClassCredits)) {
                        const credits = parseInt(selectedPlan.plan_name.split(' ')[0]);
                        document.getElementById('class_credits').value = credits;
                    }
                } else {
                    document.getElementById('class_credits').value = '';
                }

                const startDateValue = startDateInput.value;
                let startDate;
                if (startDateValue) {
                    startDate = new Date(startDateValue);
                } else {
                    startDate = new Date();
                    startDateInput.value = formatDate(startDate);
                }

                const durationDays = parseInt(selectedPlan.duration_days);
                const endDate = new Date(startDate);
                endDate.setDate(startDate.getDate() + durationDays);
                endDateInput.value = formatDate(endDate);
            }

            // Logic to handle the Membership Modal
            editMembershipBtn.addEventListener('click', () => {
                membershipModal.classList.remove('hidden');

                // Highlight the currently selected card
                document.querySelectorAll('.membership-card').forEach(card => card.classList.remove('membership-card-selected'));
                const selectedPlanId = membershipPlanIdInput.value;
                if (selectedPlanId) {
                    const currentPlanCard = document.querySelector(`.membership-card[data-plan-id="${selectedPlanId}"]`);
                    if (currentPlanCard) {
                        currentPlanCard.classList.add('membership-card-selected');
                    }
                }

                // Show the correct category tab
                const currentPlan = membershipPlans.find(p => p.id == selectedPlanId);
                const initialCategory = currentPlan ? currentPlan.category : 'all';
                const initialTab = document.querySelector(`.tab-btn[data-category="${initialCategory}"]`);
                if (initialTab) {
                    tabButtons.forEach(btn => {
                        btn.classList.remove('border-blue-500', 'text-blue-600');
                        btn.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700');
                    });
                    initialTab.classList.add('border-blue-500', 'text-blue-600');
                    initialTab.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700');
                    document.querySelectorAll('.membership-card').forEach(card => {
                        card.style.display = (initialCategory === 'all' || card.dataset.category === initialCategory) ? 'block' : 'none';
                    });
                }
            });

            cancelPlanButton.addEventListener('click', () => {
                membershipModal.classList.add('hidden');
            });

            selectPlanButton.addEventListener('click', () => {
                const selectedPlanCard = document.querySelector('.membership-card-selected');
                if (selectedPlanCard) {
                    const selectedPlan = membershipPlans.find(p => p.id == selectedPlanCard.dataset.planId);
                    if (selectedPlan) {
                        currentPlanDisplay.querySelector('span').textContent = selectedPlan.plan_name;
                        membershipPlanIdInput.value = selectedPlan.id;
                        updateEndDate();
                    }
                }
                membershipModal.classList.add('hidden');
            });

            plansContainer.addEventListener('click', (e) => {
                const card = e.target.closest('.membership-card');
                if (!card) return;

                document.querySelectorAll('.membership-card').forEach(c => c.classList.remove('membership-card-selected'));
                card.classList.add('membership-card-selected');
            });

            tabButtons.forEach(tab => {
                tab.addEventListener('click', () => {
                    tabButtons.forEach(btn => {
                        btn.classList.remove('border-blue-500', 'text-blue-600');
                        btn.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700');
                    });
                    tab.classList.add('border-blue-500', 'text-blue-600');
                    tab.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700');
                    
                    const category = tab.dataset.category;
                    document.querySelectorAll('.membership-card').forEach(card => {
                        card.style.display = (category === 'all' || card.dataset.category === category) ? 'block' : 'none';
                    });
                });
            });

            // Initial setup for current plan display and date update on page load.
            // It now correctly uses the value from the hidden input `membership_plan_id`.
            const initialPlan = membershipPlans.find(p => p.id == membershipPlanIdInput.value);
            if (initialPlan) {
                currentPlanDisplay.querySelector('span').textContent = initialPlan.plan_name;
                // Only call updateEndDate if a membership exists to prevent overwriting dates
                if (membershipIdInput.value) {
                    updateEndDate();
                }
            } else {
                currentPlanDisplay.querySelector('span').textContent = 'No Plan Selected';
            }

            // Event listeners for date fields
            startDateInput.addEventListener('change', updateEndDate);
            // Re-run updateEndDate if the membership plan is manually changed in the modal
            membershipPlanIdInput.addEventListener('change', updateEndDate);


            profilePictureUpload.addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        imageToCrop.src = event.target.result;
                        cropperModal.classList.remove('hidden');

                        if (cropper) {
                            cropper.destroy();
                        }
                        cropper = new Cropper(imageToCrop, {
                            aspectRatio: 1,
                            viewMode: 1,
                            autoCropArea: 0.8,
                            rounded: true,
                        });
                    };
                    reader.readAsDataURL(file);
                }
            });

            cropButton.addEventListener('click', function() {
                if (cropper) {
                    const croppedCanvas = cropper.getCroppedCanvas({ width: 256, height: 256 });
                    const croppedImageDataURL = croppedCanvas.toDataURL('image/jpeg');
                    profilePicturePreview.src = croppedImageDataURL;
                    croppedImageDataInput.value = croppedImageDataURL;
                    cropperModal.classList.add('hidden');
                    cropper.destroy();
                }
            });

            cancelCropButton.addEventListener('click', function() {
                cropperModal.classList.add('hidden');
                if (cropper) {
                    cropper.destroy();
                }
                profilePictureUpload.value = null;
            });
        });
    </script>
</body>
</html>
