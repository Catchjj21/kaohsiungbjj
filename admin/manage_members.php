<?php
// Include the database config
require_once "../db_config.php";

// Start the session
session_start();

// Include the admin authentication helper
require_once "admin_auth.php";

// Check if the user has admin access
requireAdminAccess(['admin', 'coach']);

// Get current filters and search term
$filter = $_GET['filter'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$order_by = $_GET['order_by'] ?? 'last_name';
$sort_direction = $_GET['sort_direction'] ?? 'ASC';

// Determine the URL for sorting links
$base_url = 'manage_members.php?filter=' . urlencode($filter) . '&search=' . urlencode($search_query);

// Base SQL query - now selecting all user roles initially
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone_number, u.belt_color, u.profile_picture_url, u.member_type, u.role, u.default_language, u.old_card, m.membership_type, m.end_date FROM users u LEFT JOIN memberships m ON u.id = m.user_id AND m.end_date = (SELECT MAX(end_date) FROM memberships WHERE user_id = u.id)";

// Initialize an array for WHERE clauses
$where_clauses = [];
$param_types = '';
$param_values = [];

// Apply filter conditions
if ($filter === 'active') {
    $where_clauses[] = "m.end_date >= CURDATE()";
} elseif ($filter === 'expired') {
    $where_clauses[] = "(m.end_date < CURDATE() OR m.id IS NULL)";
} elseif ($filter === 'adult') {
    $where_clauses[] = "u.member_type = 'adult'";
} elseif ($filter === 'kid') {
    $where_clauses[] = "u.member_type = 'child'";
} elseif ($filter === 'member') { // Explicit filter for members
    $where_clauses[] = "u.role = 'member'";
} elseif ($filter === 'staff') { // New: Filter for coaches and admins
    $where_clauses[] = "(u.role = 'coach' OR u.role = 'admin')";
}

// Apply search condition
if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    $where_clauses[] = "(u.first_name LIKE ? OR u.last_name LIKE ?)";
    $param_types .= "ss";
    $param_values[] = $search_term;
    $param_values[] = $search_term;
}

// Construct the final SQL query with WHERE clauses
if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(" AND ", $where_clauses);
}

// Order by clause
$sql .= " ORDER BY " . mysqli_real_escape_string($link, $order_by) . " " . mysqli_real_escape_string($link, $sort_direction);


$members = [];
if($stmt = mysqli_prepare($link, $sql)){
    if (!empty($param_values)) {
        // Use call_user_func_array to bind parameters dynamically
        mysqli_stmt_bind_param($stmt, $param_types, ...$param_values);
    }
    if(mysqli_stmt_execute($stmt)){
        $result = mysqli_stmt_get_result($stmt);
        if(mysqli_num_rows($result) > 0){
            while($row = mysqli_fetch_assoc($result)){
                $row['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
                $members[] = $row;
            }
        }
    } else {
        error_log("SQL Execution Error: " . mysqli_error($link)); // Log error instead of echoing directly
    }
    mysqli_stmt_close($stmt);
} else {
    error_log("SQL Prepare Error: " . mysqli_error($link)); // Log error instead of echoing directly
}
mysqli_close($link);

// Options for dropdowns in editable cells
$member_type_options = ['adult', 'child'];
$role_options = ['member', 'coach', 'admin']; // Options for user roles
$membership_type_options = [
    'None',
    'All-Inclusive Pass',
    'Jiu Jitsu Only Pass',
    '4 Class Pass',
    '10 Class Pass',
    'Monthly',
    'Annual',
    'Trial',
    'Family'
];
$belt_color_options = ['White', 'Blue', 'Purple', 'Brown', 'Black']; // Example options, adjust as needed
$language_options = ['en', 'zh'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Members - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        #cropper-container { width: 100%; height: 400px; }
        .editable-cell { cursor: pointer; }
        .editable-cell:hover { background-color: #f0f4f8; } /* Light hover effect */
        .editing-input, .editing-select {
            width: 100%;
            border: 1px solid #cbd5e0; /* border-gray-300 */
            border-radius: 0.375rem; /* rounded-md */
            padding: 0.5rem 0.75rem; /* py-2 px-3 */
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* shadow-sm */
            outline: none;
            font-size: 0.875rem; /* sm:text-sm */
        }
        .editing-input:focus, .editing-select:focus {
            border-color: #3b82f6; /* border-blue-500 */
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.5); /* ring-blue-500 */
        }
        /* Custom Notification Styles */
        #notification-container {
            z-index: 1000; /* Ensure it's above other elements */
        }
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
    
    <!-- Error/Success Messages -->
    <?php if (isset($_GET['error'])): ?>
        <div class="container mx-auto px-6 py-4">
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <strong class="font-bold">Error!</strong>
                <span class="block sm:inline">
                    <?php 
                    $error = $_GET['error'];
                    switch($error) {
                        case 'user_update_failed':
                            echo 'Failed to update user information.';
                            break;
                        case 'membership_update_failed':
                            echo 'Failed to update membership information.';
                            break;
                        case 'membership_insert_failed':
                            echo 'Failed to insert membership information.';
                            break;
                        case 'membership_delete_failed':
                            echo 'Failed to delete membership information.';
                            break;
                        case 'image_data_invalid':
                            echo 'Invalid image data provided.';
                            break;
                        case 'image_decode_failed':
                            echo 'Failed to process image data.';
                            break;
                        case 'image_save_failed':
                            echo 'Failed to save profile picture.';
                            break;
                        case 'missing_required_fields':
                            echo 'Missing required fields in the form.';
                            break;
                        default:
                            echo 'An error occurred while processing your request.';
                    }
                    ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="container mx-auto px-6 py-12">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-4xl font-black text-gray-800">Manage Members</h1>
                <p class="mt-2 text-lg text-gray-600">View and update member information.</p>
            </div>
            <button id="add-member-btn" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700 transition">+ Add Member</button>
        </div>
        <!-- Filter Buttons and Search Bar -->
        <div class="mt-6 flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4">
            <div class="flex flex-wrap gap-2">
                <a href="manage_members.php?filter=all<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'all') ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">All Users</a> <!-- Changed to All Users -->
                <a href="manage_members.php?filter=member<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'member') ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">Members</a> <!-- New: Explicit Members filter -->
                <a href="manage_members.php?filter=active<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'active') ? 'bg-green-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">Active</a>
                <a href="manage_members.php?filter=expired<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'expired') ? 'bg-red-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">Expired</a>
                <a href="manage_members.php?filter=adult<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'adult') ? 'bg-purple-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">Adults</a>
                <a href="manage_members.php?filter=kid<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'kid') ? 'bg-yellow-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">Kids</a>
                <a href="manage_members.php?filter=staff<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) : ''; ?>" class="px-4 py-2 text-sm font-medium rounded-md <?php echo ($filter === 'staff') ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'; ?>">Coaches/Admins</a> <!-- New: Staff filter -->
            </div>

            <!-- Search Bar Form -->
            <form action="manage_members.php" method="GET" class="flex items-center w-full sm:w-auto sm:min-w-[250px]">
                <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <input type="text" name="search" placeholder="Search by name..." value="<?php echo htmlspecialchars($search_query); ?>" class="flex-grow px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                <button type="submit" class="ml-2 px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Search</button>
                <?php if (!empty($search_query)): ?>
                    <a href="manage_members.php?filter=<?php echo htmlspecialchars($filter); ?>" class="ml-2 px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="mt-4 bg-white p-6 rounded-2xl shadow-lg overflow-x-auto">
            <table class="w-full text-left table-auto" id="membersTable">
                <thead>
                    <tr class="border-b-2">
                        <?php
                        // Helper function to generate sorting links
                        function getSortLink($column, $current_order_by, $current_sort_direction, $base_url) {
                            $new_direction = ($current_order_by === $column && $current_sort_direction === 'ASC') ? 'DESC' : 'ASC';
                            $arrow = ($current_order_by === $column) ? ($current_sort_direction === 'ASC' ? ' &uarr;' : ' &darr;') : '';
                            return '<a href="' . $base_url . '&order_by=' . urlencode($column) . '&sort_direction=' . $new_direction . '" class="flex items-center">' . $column . $arrow . '</a>';
                        }
                        $current_sort_url = $base_url . '&order_by=' . urlencode($order_by) . '&sort_direction=' . urlencode($sort_direction);
                        ?>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[150px] font-bold"><?php echo getSortLink('last_name', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[200px] font-bold"><?php echo getSortLink('email', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[100px] font-bold"><?php echo getSortLink('member_type', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[120px] font-bold"><?php echo getSortLink('role', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[120px] font-bold"><?php echo getSortLink('membership_type', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[120px] font-bold"><?php echo getSortLink('end_date', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[120px] font-bold"><?php echo getSortLink('belt_color', $order_by, $sort_direction, $base_url); ?></th>
                        <th class="py-3 px-4 whitespace-nowrap min-w-[180px]">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members)): ?>
                        <tr><td colspan="8" class="text-center py-10 text-gray-500">No users found for this filter/search.</td></tr>
                    <?php else: ?>
                        <?php foreach ($members as $member): ?>
                            <tr class="border-b hover:bg-gray-50" data-user-id="<?php echo $member['id']; ?>">
                                <td class="py-3 px-4 font-semibold">
                                    <div class="flex items-center space-x-3">
                                        <img src="<?php echo !empty($member['profile_picture_url']) ? '../' . htmlspecialchars($member['profile_picture_url']) : 'https://placehold.co/40x40/e2e8f0/333333?text=Pic'; ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover">
                                        <div>
                                            <span class="editable-cell" data-field="first_name" data-original-value="<?php echo htmlspecialchars($member['first_name']); ?>"><?php echo htmlspecialchars($member['first_name']); ?></span>
                                            <span class="ml-1 editable-cell" data-field="last_name" data-original-value="<?php echo htmlspecialchars($member['last_name']); ?>"><?php echo htmlspecialchars($member['last_name']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 editable-cell" data-field="email" data-original-value="<?php echo htmlspecialchars($member['email']); ?>"><?php echo htmlspecialchars($member['email']); ?></td>
                                <td class="py-3 px-4 editable-cell" data-field="member_type" data-original-value="<?php echo htmlspecialchars($member['member_type'] ?? ''); ?>"><?php echo htmlspecialchars($member['member_type'] ?? 'N/A'); ?></td>
                                <td class="py-3 px-4 editable-cell" data-field="role" data-original-value="<?php echo htmlspecialchars($member['role'] ?? ''); ?>"><?php echo htmlspecialchars($member['role'] ?? 'N/A'); ?></td>
                                <td class="py-3 px-4 editable-cell" data-field="membership_type" data-original-value="<?php echo htmlspecialchars($member['membership_type'] ?? ''); ?>"><?php echo htmlspecialchars($member['membership_type'] ?? 'N/A'); ?></td>
                                <td class="py-3 px-4 editable-cell <?php echo (isset($member['end_date']) && strtotime($member['end_date']) < time()) ? 'text-red-500 font-bold' : ''; ?>" data-field="end_date" data-original-value="<?php echo isset($member['end_date']) ? $member['end_date'] : ''; ?>"><?php echo isset($member['end_date']) ? date("m/d/Y", strtotime($member['end_date'])) : 'N/A'; ?></td>
                                <td class="py-3 px-4 editable-cell" data-field="belt_color" data-original-value="<?php echo htmlspecialchars($member['belt_color'] ?? ''); ?>"><?php echo htmlspecialchars($member['belt_color'] ?? 'N/A'); ?></td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center space-x-2">
                                        <a href="edit_member.php?id=<?php echo $member['id'] . '&return_url=' . urlencode($_SERVER['REQUEST_URI']); ?>" class="bg-blue-500 text-white text-xs font-bold py-1 px-3 rounded hover:bg-blue-600">Edit</a>
                                        <button class="delete-member-btn bg-red-500 text-white text-xs font-bold py-1 px-3 rounded hover:bg-red-600" data-user-id="<?php echo $member['id']; ?>" data-user-name="<?php echo htmlspecialchars($member['full_name']); ?>">Delete</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div id="upload-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-75 p-4 hidden"></div>
    <div id="delete-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-75 p-4 hidden">
        <div class="bg-white rounded-lg shadow-2xl w-full max-w-sm">
            <div class="p-6 text-center">
                <svg class="w-16 h-16 mx-auto text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <h3 class="mt-2 text-lg font-bold text-gray-900">Delete Member</h3>
                <p class="mt-2 text-sm text-gray-600">Are you sure you want to permanently delete <strong id="delete-member-name"></strong>? This action cannot be undone.</p>
                <div class="mt-6 flex justify-center space-x-4">
                    <button id="close-delete-modal" class="bg-gray-300 text-gray-800 font-bold py-2 px-6 rounded-lg hover:bg-gray-400">Cancel</button>
                    <button id="confirm-delete-btn" class="bg-red-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-red-700">Yes, Delete</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Member Modal (NEW) -->
    <div id="add-member-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col">
            <header class="flex items-center justify-between p-4 border-b">
                <h3 class="text-2xl font-bold">Add New Member</h3>
                <button id="close-add-member-modal" class="text-gray-500 hover:text-gray-800 text-3xl">&times;</button>
            </header>
            <div class="p-6 overflow-auto">
                <form id="add-member-form" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="add-first-name" class="block text-sm font-medium text-gray-700">First Name</label>
                            <input type="text" id="add-first-name" name="first_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="add-last-name" class="block text-sm font-medium text-gray-700">Last Name</label>
                            <input type="text" id="add-last-name" name="last_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                    </div>
                    <div>
                        <label for="add-email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" id="add-email" name="email" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                    </div>
                    <div>
                        <label for="add-password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" id="add-password" name="password" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                    </div>
                    <div>
                        <label for="add-phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                        <input type="tel" id="add-phone" name="phone_number" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="add-member-type" class="block text-sm font-medium text-gray-700">Member Type</label>
                            <select id="add-member-type" name="member_type" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <?php foreach ($member_type_options as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="add-role" class="block text-sm font-medium text-gray-700">Role</label>
                            <select id="add-role" name="role" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                                <?php foreach ($role_options as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars(ucfirst($option)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label for="add-language" class="block text-sm font-medium text-gray-700">Default Language</label>
                        <select id="add-language" name="default_language" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm">
                            <option value="en">English</option>
                            <option value="zh">中文</option>
                        </select>
                    </div>
                    <div class="mt-6 text-right">
                        <button type="submit" class="bg-green-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-green-700 transition">
                            Add Member
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div id="notification-container" class="fixed top-4 right-4 z-[1000] space-y-2"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <script>
        // PHP variables for JavaScript
        const memberTypeOptions = <?php echo json_encode($member_type_options); ?>;
        const roleOptions = <?php echo json_encode($role_options); ?>; // New: Role options
        const membershipTypeOptions = <?php echo json_encode($membership_type_options); ?>;
        const beltColorOptions = <?php echo json_encode($belt_color_options); ?>;
        const languageOptions = ['en', 'zh'];

        document.addEventListener('DOMContentLoaded', function() {
            // --- Add Member Modal Logic (NEW) ---
            const addMemberBtn = document.getElementById('add-member-btn');
            const addMemberModal = document.getElementById('add-member-modal');
            const closeAddMemberModalBtn = document.getElementById('close-add-member-modal');
            const addMemberForm = document.getElementById('add-member-form');

            if (addMemberBtn) {
                addMemberBtn.addEventListener('click', () => {
                    addMemberModal.classList.remove('hidden');
                });
            }

            if (closeAddMemberModalBtn) {
                closeAddMemberModalBtn.addEventListener('click', () => {
                    addMemberModal.classList.add('hidden');
                });
            }

            if (addMemberForm) {
                addMemberForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);
                    
                    try {
                        const response = await fetch('add_member_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        
                        if (result.success) {
                            showNotification(result.message, 'success');
                            addMemberModal.classList.add('hidden');
                            setTimeout(() => window.location.reload(), 1500);
                        } else {
                            showNotification(result.message || 'Failed to add member.', 'error');
                        }
                    } catch (error) {
                        console.error('Error adding member:', error);
                        showNotification('A network error occurred. Please try again.', 'error');
                    }
                });
            }
            
            // --- Delete Modal Logic (Existing) ---
            const deleteModal = document.getElementById('delete-modal');
            const deleteMemberName = document.getElementById('delete-member-name');
            const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
            const closeDeleteModalBtn = document.getElementById('close-delete-modal');
            let userIdToDelete;

            document.querySelectorAll('.delete-member-btn').forEach(button => {
                button.addEventListener('click', function() {
                    userIdToDelete = this.dataset.userId;
                    deleteMemberName.textContent = this.dataset.userName;
                    deleteModal.classList.remove('hidden');
                });
            });

            const closeDeleteModal = () => deleteModal.classList.add('hidden');
            closeDeleteModalBtn.addEventListener('click', closeDeleteModal);
            deleteModal.addEventListener('click', (e) => {
                if (e.target === deleteModal) {
                    closeDeleteModal();
                }
            });

            confirmDeleteBtn.addEventListener('click', async function() {
                try {
                    const formData = new FormData();
                    formData.append('user_id', userIdToDelete);
                    const response = await fetch('delete_member_handler.php', {
                        method: 'POST',
                        body: formData,
                    });
                    const result = await response.json();
                    if (response.ok) {
                        // Preserve sorting and filtering state on reload
                        window.location.href = window.location.href;
                    } else {
                        showNotification(result.message || 'Failed to delete member.', 'error');
                    }
                } catch (error) {
                    console.error('Error during member deletion:', error);
                    showNotification('An error occurred during deletion.', 'error');
                }
            });
            // --- In-place Editing Logic ---
            let activeCell = null;

            document.addEventListener('dblclick', function(e) {
                const cell = e.target.closest('.editable-cell');
                if (!cell) {
                    if (activeCell) saveCellValue(activeCell);
                    return;
                }

                if (activeCell && activeCell !== cell) {
                    saveCellValue(activeCell);
                }
                if (activeCell === cell) return;

                activeCell = cell;
                const originalValue = activeCell.dataset.originalValue;
                const field = activeCell.dataset.field;
                const currentText = activeCell.textContent.trim();
                activeCell.innerHTML = '';

                let inputElement;
                switch (field) {
                    case 'member_type':
                        inputElement = document.createElement('select');
                        memberTypeOptions.forEach(option => {
                            const opt = document.createElement('option');
                            opt.value = option;
                            opt.textContent = option;
                            if (option === originalValue) opt.selected = true;
                            inputElement.appendChild(opt);
                        });
                        break;
                    case 'role':
                        inputElement = document.createElement('select');
                        roleOptions.forEach(option => {
                            const opt = document.createElement('option');
                            opt.value = option;
                            opt.textContent = option.charAt(0).toUpperCase() + option.slice(1);
                            if (option === originalValue) opt.selected = true;
                            inputElement.appendChild(opt);
                        });
                        break;
                    case 'membership_type':
                        inputElement = document.createElement('select');
                        const defaultMemOption = document.createElement('option');
                        defaultMemOption.value = '';
                        defaultMemOption.textContent = 'Select Membership';
                        inputElement.appendChild(defaultMemOption);
                        membershipTypeOptions.forEach(option => {
                            const opt = document.createElement('option');
                            opt.value = option;
                            opt.textContent = option;
                            if (option === originalValue) opt.selected = true;
                            inputElement.appendChild(opt);
                        });
                        break;
                    case 'belt_color':
                        inputElement = document.createElement('select');
                        const defaultBeltOption = document.createElement('option');
                        defaultBeltOption.value = '';
                        defaultBeltOption.textContent = 'Select Belt';
                        inputElement.appendChild(defaultBeltOption);
                        beltColorOptions.forEach(option => {
                            const opt = document.createElement('option');
                            opt.value = option;
                            opt.textContent = option;
                            if (option === originalValue) opt.selected = true;
                            inputElement.appendChild(opt);
                        });
                        break;
                    case 'end_date':
                        inputElement = document.createElement('input');
                        inputElement.type = 'date';
                        inputElement.value = originalValue;
                        break;
                    case 'default_language': // NEW: In-place edit for language
                        inputElement = document.createElement('select');
                        languageOptions.forEach(option => {
                            const opt = document.createElement('option');
                            opt.value = option;
                            opt.textContent = option.toUpperCase();
                            if (option === originalValue) opt.selected = true;
                            inputElement.appendChild(opt);
                        });
                        break;
                    default:
                        inputElement = document.createElement('input');
                        inputElement.type = 'text';
                        inputElement.value = originalValue;
                }

                inputElement.classList.add('editing-input');
                if (inputElement.tagName === 'SELECT') {
                    inputElement.classList.remove('editing-input');
                    inputElement.classList.add('editing-select');
                }
                activeCell.appendChild(inputElement);
                inputElement.focus();
                inputElement.addEventListener('blur', () => saveCellValue(activeCell));
                inputElement.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        saveCellValue(activeCell);
                    } else if (e.key === 'Escape') {
                        revertCellValue(activeCell, originalValue);
                    }
                });
            });
            async function saveCellValue(cell) {
                if (!cell || !cell.querySelector('input, select')) return;
                const inputElement = cell.querySelector('input, select');
                const newValue = inputElement.value;
                const originalValue = cell.dataset.originalValue;
                const field = cell.dataset.field;
                const userId = cell.closest('tr').dataset.userId;

                if (newValue === originalValue) {
                    revertCellValue(cell, originalValue);
                    return;
                }
                cell.innerHTML = '<span class="text-gray-500 italic">Saving...</span>';
                try {
                    const formData = new FormData();
                    formData.append('user_id', userId);
                    formData.append('field', field);
                    formData.append('value', newValue);
                    const response = await fetch('update_member_field.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (response.ok && result.success) {
                        cell.dataset.originalValue = newValue;
                        if (field === 'end_date') {
                            const date = new Date(newValue);
                            const userTimezoneOffset = date.getTimezoneOffset() * 60000;
                            const adjustedDate = new Date(date.getTime() + userTimezoneOffset);
                            cell.textContent = adjustedDate.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
                            if (adjustedDate < new Date()) {
                                cell.classList.add('text-red-500', 'font-bold');
                            } else {
                                cell.classList.remove('text-red-500', 'font-bold');
                            }
                        } else {
                               cell.textContent = newValue || 'N/A';
                        }
                        showNotification('Member updated successfully!', 'success');
                    } else {
                        revertCellValue(cell, originalValue);
                        showNotification(result.message || 'Failed to update member.', 'error');
                    }
                } catch (error) {
                    console.error('Error updating member:', error);
                    revertCellValue(cell, originalValue);
                    showNotification('An error occurred during update.', 'error');
                } finally {
                    activeCell = null;
                }
            }
            function revertCellValue(cell, originalValue) {
                if (!cell) return;
                if (cell.dataset.field === 'end_date') {
                    if (originalValue) {
                        const date = new Date(originalValue);
                        const userTimezoneOffset = date.getTimezoneOffset() * 60000;
                        const adjustedDate = new Date(date.getTime() + userTimezoneOffset);
                        cell.textContent = adjustedDate.toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
                        if (adjustedDate < new Date()) {
                            cell.classList.add('text-red-500', 'font-bold');
                        } else {
                            cell.classList.remove('text-red-500', 'font-bold');
                        }
                    } else {
                        cell.textContent = 'N/A';
                    }
                } else {
                    cell.textContent = originalValue || 'N/A';
                }
                activeCell = null;
            }
            // --- Custom Notification/Message Box (Existing) ---
            function showNotification(message, type = 'info') {
                let container = document.getElementById('notification-container');
                if (!container) {
                    container = document.createElement('div');
                    container.id = 'notification-container';
                    container.className = 'fixed top-4 right-4 z-[1000] space-y-2';
                    document.body.appendChild(container);
                }
                const notification = document.createElement('div');
                notification.className = `p-4 rounded-lg shadow-lg text-white flex items-center transition-transform duration-300 transform translate-x-full`;
                const bgColor = type === 'success' ? 'bg-green-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
                notification.classList.add(bgColor);
                notification.innerHTML = `<span>${message}</span>`;
                container.appendChild(notification);
                setTimeout(() => notification.classList.remove('translate-x-full'), 100);
                setTimeout(() => {
                    notification.classList.add('translate-x-full');
                    notification.addEventListener('transitionend', () => notification.remove());
                }, 3000);
            }
            // Store current page state in a cookie
            document.querySelectorAll('a').forEach(link => {
                if (link.href.includes('edit_member.php')) {
                    link.addEventListener('click', (e) => {
                        document.cookie = "manage_members_state=" + encodeURIComponent(window.location.search) + "; path=/";
                    });
                }
            });
            window.addEventListener('load', () => {
                const state = document.cookie.split('; ').find(row => row.startsWith('manage_members_state='));
                if (state) {
                    const params = new URLSearchParams(state.split('=')[1]);
                    if (params.get('id') && params.get('return_url')) {
                        const returnUrl = decodeURIComponent(params.get('return_url'));
                        window.history.replaceState({}, '', returnUrl);
                        document.cookie = "manage_members_state=; Max-Age=0; path=/";
                    }
                }
            });
        });
    </script>
</body>
</html>
