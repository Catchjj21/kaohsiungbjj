<?php
// Turn on error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database configuration.
require_once "../db_config.php";

// Start the session.
session_start();

// Check if the user is logged in and is an admin or coach.
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    header("location: admin_login.html");
    exit;
}

$message = "";
$message_class = "";

// Handle POST request to link or unlink a user.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['unlink_user_id'])) {
        // Handle unlinking a user from a family.
        $unlink_user_id = trim($_POST['unlink_user_id']);
        $sql_unlink = "UPDATE users SET parent_id = NULL WHERE id = ?";
        if ($stmt_unlink = mysqli_prepare($link, $sql_unlink)) {
            mysqli_stmt_bind_param($stmt_unlink, "i", $unlink_user_id);
            if (mysqli_stmt_execute($stmt_unlink)) {
                $message = "User successfully unlinked from family.";
                $message_class = "text-green-700 bg-green-100";
            } else {
                $message = "Error unlinking user: " . mysqli_error($link);
                $message_class = "text-red-700 bg-red-100";
            }
            mysqli_stmt_close($stmt_unlink);
        }
    } elseif (isset($_POST['link_user_id']) && isset($_POST['parent_id'])) {
        // Handle linking a user to a family.
        $link_user_id = trim($_POST['link_user_id']);
        $parent_id = trim($_POST['parent_id']);

        if ($link_user_id === $parent_id) {
            $message = "Error: A user cannot be their own parent.";
            $message_class = "text-red-700 bg-red-100";
        } else {
            $sql_update = "UPDATE users SET parent_id = ? WHERE id = ?";
            if ($stmt_update = mysqli_prepare($link, $sql_update)) {
                mysqli_stmt_bind_param($stmt_update, "ii", $parent_id, $link_user_id);
                if (mysqli_stmt_execute($stmt_update)) {
                    $message = "User successfully linked to parent account.";
                    $message_class = "text-green-700 bg-green-100";
                } else {
                    $message = "Error linking user: " . mysqli_error($link);
                    $message_class = "text-red-700 bg-red-100";
                }
                mysqli_stmt_close($stmt_update);
            }
        }
    }
}

// Fetch all users and group them by family.
$families = [];
$unlinked_users = [];
$users = [];
$sql_fetch = "SELECT id, first_name, last_name, email, member_type, role, parent_id, profile_picture_url FROM users ORDER BY first_name";
$result_fetch = mysqli_query($link, $sql_fetch);

if (mysqli_num_rows($result_fetch) > 0) {
    while ($row = mysqli_fetch_assoc($result_fetch)) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        if ($row['role'] === 'parent') {
            $families[$row['id']] = $row;
            $families[$row['id']]['children'] = [];
        } elseif (!isset($row['parent_id'])) {
            $unlinked_users[] = $row;
        }
    }
    // Rewind result pointer to re-fetch children
    mysqli_data_seek($result_fetch, 0);
    while ($row = mysqli_fetch_assoc($result_fetch)) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        if ($row['parent_id'] !== NULL && isset($families[$row['parent_id']])) {
            $families[$row['parent_id']]['children'][] = $row;
        }
    }
}

// The search lists also need a complete list of users to work
mysqli_data_seek($result_fetch, 0);
$users_for_search = [];
if (mysqli_num_rows($result_fetch) > 0) {
    while ($row = mysqli_fetch_assoc($result_fetch)) {
        $row['full_name'] = $row['first_name'] . ' ' . $row['last_name'];
        $users_for_search[] = $row;
    }
}
$users = $users_for_search;

mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Family Linking - Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="icon" href="/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; }
        .user-list-container { max-height: 200px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 0.5rem; }
        .user-list-item { cursor: pointer; }
        .user-list-item.selected { background-color: #e0f2fe; }
        .profile-picture {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e5e7eb;
        }
        .family-card-img {
             width: 60px;
             height: 60px;
             object-fit: cover;
             border-radius: 50%;
        }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="admin_dashboard.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Admin Dashboard</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <div>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">Manage Family Linking</h1>
        <p class="mt-2 text-lg text-gray-600">Link child accounts to their parent's account.</p>

        <?php if (!empty($message)): ?>
            <div class="mt-4 p-4 text-sm rounded-lg <?php echo $message_class; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Linking Form -->
        <div class="bg-white p-8 rounded-2xl shadow-lg mt-8">
            <h2 class="text-2xl font-bold text-gray-800">Link Accounts</h2>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="mt-4 space-y-4">
                
                <!-- Child Account Search -->
                <div>
                    <label for="child_search" class="block text-sm font-medium text-gray-700">Select Child Account:</label>
                    <input type="text" id="child_search" placeholder="Search by name or email" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                    <input type="hidden" id="link_user_id" name="link_user_id" required>
                    <div id="child_list_container" class="user-list-container mt-2 bg-white">
                        <ul id="child_list" class="divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                                <?php if ($user['role'] !== 'parent'): ?>
                                    <li class="p-2 user-list-item hover:bg-gray-50" data-id="<?php echo $user['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower($user['full_name'] . ' ' . $user['email'])); ?>">
                                        <div class="flex items-center space-x-3">
                                            <img src="../<?php echo htmlspecialchars($user['profile_picture_url']) ?: '../https://placehold.co/40x40/E5E7EB/A9A9A9?text=👤'; ?>" alt="Profile Picture" class="profile-picture">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user['email']); ?></p>
                                            </div>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <!-- Parent Account Search -->
                <div>
                    <label for="parent_search" class="block text-sm font-medium text-gray-700">Select Parent Account:</label>
                    <input type="text" id="parent_search" placeholder="Search by name or email" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm rounded-md">
                    <input type="hidden" id="parent_id" name="parent_id" required>
                    <div id="parent_list_container" class="user-list-container mt-2 bg-white">
                        <ul id="parent_list" class="divide-y divide-gray-200">
                            <?php foreach ($users as $user): ?>
                                <?php if ($user['role'] === 'parent'): ?>
                                    <li class="p-2 user-list-item hover:bg-gray-50" data-id="<?php echo $user['id']; ?>" data-search="<?php echo htmlspecialchars(strtolower($user['full_name'] . ' ' . $user['email'])); ?>">
                                        <div class="flex items-center space-x-3">
                                            <img src="../<?php echo htmlspecialchars($user['profile_picture_url']) ?: '../https://placehold.co/40x40/E5E7EB/A9A9A9?text=👤'; ?>" alt="Profile Picture" class="profile-picture">
                                            <div>
                                                <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($user['email']); ?></p>
                                            </div>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div>
                    <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition">
                        Link Accounts
                    </button>
                </div>
            </form>
        </div>

        <!-- Current Families Section -->
        <div class="bg-white p-8 rounded-2xl shadow-lg mt-8">
            <h2 class="text-2xl font-bold text-gray-800">Current Families</h2>
            <div class="mt-4 space-y-6">
                <?php if (empty($families)): ?>
                    <p class="text-gray-600">No families are currently linked.</p>
                <?php else: ?>
                    <?php foreach ($families as $parent): ?>
                        <div class="bg-gray-50 p-6 rounded-xl border border-gray-200">
                            <div class="flex items-center space-x-4">
                                <img src="../<?php echo htmlspecialchars($parent['profile_picture_url']) ?: '../https://placehold.co/60x60/A9A9A9/FFFFFF?text=👤'; ?>" alt="Parent Profile" class="family-card-img">
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Parent: <?php echo htmlspecialchars($parent['full_name']); ?></h3>
                                    <p class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($parent['id']); ?> | Email: <?php echo htmlspecialchars($parent['email']); ?></p>
                                </div>
                            </div>
                            <div class="mt-4 ml-6 space-y-2">
                                <h4 class="text-lg font-semibold text-gray-700">Children:</h4>
                                <?php if (empty($parent['children'])): ?>
                                    <p class="text-gray-500 text-sm">No children are linked to this parent.</p>
                                <?php else: ?>
                                    <?php foreach ($parent['children'] as $child): ?>
                                        <div class="flex items-center justify-between p-2 bg-white rounded-lg shadow-sm">
                                            <div class="flex items-center space-x-3">
                                                 <img src="../<?php echo htmlspecialchars($child['profile_picture_url']) ?: '../https://placehold.co/40x40/E5E7EB/A9A9A9?text=👤'; ?>" alt="Child Profile" class="profile-picture">
                                                 <div>
                                                     <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($child['full_name']); ?></p>
                                                     <p class="text-xs text-gray-500">ID: <?php echo htmlspecialchars($child['id']); ?> | Role: <?php echo htmlspecialchars($child['role']); ?></p>
                                                 </div>
                                            </div>
                                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                                                <input type="hidden" name="unlink_user_id" value="<?php echo htmlspecialchars($child['id']); ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-800 font-semibold text-sm">Unlink</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        function setupSearch(searchInputId, listId, hiddenInputId) {
            const searchInput = document.getElementById(searchInputId);
            const userList = document.getElementById(listId);
            const hiddenInput = document.getElementById(hiddenInputId);
            const listItems = userList.getElementsByTagName('li');

            searchInput.addEventListener('keyup', function() {
                const filter = searchInput.value.toLowerCase();
                for (let i = 0; i < listItems.length; i++) {
                    const searchData = listItems[i].getAttribute('data-search');
                    if (searchData.includes(filter)) {
                        listItems[i].style.display = "";
                    } else {
                        listItems[i].style.display = "none";
                    }
                }
            });

            userList.addEventListener('click', function(e) {
                const target = e.target.closest('li');
                if (target) {
                    // Remove selected class from all items
                    for (let i = 0; i < listItems.length; i++) {
                        listItems[i].classList.remove('selected');
                    }
                    // Add selected class to the clicked item
                    target.classList.add('selected');

                    // Set the hidden input value
                    hiddenInput.value = target.getAttribute('data-id');
                    
                    // Display the selected user in the search box
                    searchInput.value = target.querySelector('p:first-child').textContent;
                    
                    // Hide the list after selection
                    for (let i = 0; i < listItems.length; i++) {
                        listItems[i].style.display = "none";
                    }
                }
            });

            // Show list when search input is focused
            searchInput.addEventListener('focus', function() {
                 for (let i = 0; i < listItems.length; i++) {
                     listItems[i].style.display = "";
                 }
                 const filter = searchInput.value.toLowerCase();
                 for (let i = 0; i < listItems.length; i++) {
                     const searchData = listItems[i].getAttribute('data-search');
                     if (searchData.includes(filter)) {
                         listItems[i].style.display = "";
                     } else {
                         listItems[i].style.display = "none";
                     }
                 }
            });
            
            // Hide list when clicking outside
            document.addEventListener('click', function(e) {
                if (!document.getElementById(searchInputId).contains(e.target) && !document.getElementById(listId).contains(e.target)) {
                    for (let i = 0; i < listItems.length; i++) {
                        listItems[i].style.display = "none";
                    }
                }
            });

            // Hide the list initially
            for (let i = 0; i < listItems.length; i++) {
                listItems[i].style.display = "none";
            }
        }

        // Setup search for both child and parent selectors
        setupSearch('child_search', 'child_list', 'link_user_id');
        setupSearch('parent_search', 'parent_list', 'parent_id');
    </script>
</body>
</html>
