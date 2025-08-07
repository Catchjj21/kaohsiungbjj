<?php
// =========================================================================
//            Enhanced Error Reporting
// =========================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// =========================================================================

require_once "../db_config.php"; // Ensure this path is correct for your setup
session_start();

// Security check
// Redirects to admin_login.html if not logged in as admin or coach
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    header("location: admin_login.html");
    exit;
}

// Set character set for the database connection to support UTF-8 characters
mysqli_set_charset($link, "utf8mb4");

$message = ''; // Variable to store success or error messages
$message_type = ''; // Variable to store message type (success/error)

// Handle form submissions for creating or updating membership plans
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && in_array($_POST['action'], ['create', 'update'])) {
    // Sanitize and retrieve form data
    $plan_id = $_POST['plan_id'] ?? 0;
    $plan_name = trim($_POST['plan_name']);
    $plan_name_zh = trim($_POST['plan_name_zh']);
    $category = trim($_POST['category']);
    $price = trim($_POST['price']);
    $duration_days = trim($_POST['duration_days']);
    $description = trim($_POST['description']);
    $description_zh = trim($_POST['description_zh']);

    // --- UPDATE ACTION ---
    if ($_POST['action'] == 'update' && !empty($plan_id)) {
        $sql = "UPDATE membership_plans SET plan_name=?, plan_name_zh=?, category=?, price=?, duration_days=?, description=?, description_zh=? WHERE id=?";
        if ($stmt = mysqli_prepare($link, $sql)) {
            // FIX: Changed "sssdisis" to "sssdissi" to correctly bind description_zh and id as strings and integer respectively.
            // s: plan_name, s: plan_name_zh, s: category, d: price, i: duration_days, s: description, s: description_zh, i: id
            mysqli_stmt_bind_param($stmt, "sssdissi", $plan_name, $plan_name_zh, $category, $price, $duration_days, $description, $description_zh, $plan_id);
            if (mysqli_stmt_execute($stmt)) {
                $message = "Plan updated successfully!";
                $message_type = 'success';
            } else {
                $message = "Error updating plan: " . mysqli_stmt_error($stmt);
                $message_type = 'error';
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = "Error preparing update statement: " . mysqli_error($link);
            $message_type = 'error';
        }
    }
    // --- CREATE ACTION ---
    elseif ($_POST['action'] == 'create') {
        $sql = "INSERT INTO membership_plans (plan_name, plan_name_zh, category, price, duration_days, description, description_zh) VALUES (?, ?, ?, ?, ?, ?, ?)";
        if ($stmt = mysqli_prepare($link, $sql)) {
            // FIX: Changed "sssdisis" to "sssdiss" to match the 7 placeholders in the INSERT query
            // s: plan_name, s: plan_name_zh, s: category, d: price, i: duration_days, s: description, s: description_zh
            mysqli_stmt_bind_param($stmt, "sssdiss", $plan_name, $plan_name_zh, $category, $price, $duration_days, $description, $description_zh);
            if (mysqli_stmt_execute($stmt)) {
                $message = "New plan created successfully!";
                $message_type = 'success';
            } else {
                $message = "Error creating plan: " . mysqli_stmt_error($stmt);
                $message_type = 'error';
            }
            mysqli_stmt_close($stmt);
        } else {
            $message = "Error preparing create statement: " . mysqli_error($link);
            $message_type = 'error';
        }
    }
}


// --- Fetching plans with new filters ---
$title_filter = $_GET['title_filter'] ?? '';
$category_filter = $_GET['categoryf_ilter'] ?? ''; // Typo here, should be 'category_filter'

$plans = [];
$sql_fetch = "SELECT * FROM membership_plans";
$where_clauses = [];
$param_types = '';
$param_values = [];

if (!empty($title_filter)) {
    // Search in both plan_name and plan_name_zh
    $where_clauses[] = "(plan_name LIKE ? OR plan_name_zh LIKE ?)";
    $param_types .= 'ss';
    $filter_term = '%' . $title_filter . '%';
    $param_values[] = $filter_term;
    $param_values[] = $filter_term;
}
if (!empty($category_filter)) {
    $where_clauses[] = "category = ?";
    $param_types .= 's';
    $param_values[] = $category_filter;
}

if (!empty($where_clauses)) {
    $sql_fetch .= " WHERE " . implode(" AND ", $where_clauses);
}

$sql_fetch .= " ORDER BY category, price";

if ($stmt_fetch = mysqli_prepare($link, $sql_fetch)) {
    if (!empty($param_values)) {
        mysqli_stmt_bind_param($stmt_fetch, $param_types, ...$param_values);
    }
    if (mysqli_stmt_execute($stmt_fetch)) {
        $result = mysqli_stmt_get_result($stmt_fetch);
        while ($row = mysqli_fetch_assoc($result)) {
            $plans[] = $row;
        }
        mysqli_free_result($result);
    } else {
        $message = "Error fetching plans: " . mysqli_stmt_error($stmt_fetch);
        $message_type = 'error';
    }
    mysqli_stmt_close($stmt_fetch);
} else {
    $message = "Error preparing fetch statement: " . mysqli_error($link);
    $message_type = 'error';
}


// Close the database connection
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Membership Plans - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        .modal-active { overflow: hidden; }
        .modal-content { transition: transform 0.25s ease; }
        .filter-btn-active { background-color: #2563eb; color: white; }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800">Admin Portal</span>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-4xl font-black text-gray-800">Manage Membership Plans</h1>
            <div>
                <a href="admin_dashboard.php" class="text-white bg-gray-600 hover:bg-gray-700 font-bold py-2.5 px-6 rounded-lg transition mr-2">Back to Dashboard</a>
                <button id="addPlanBtn" class="text-white bg-green-600 hover:bg-green-700 font-bold py-2.5 px-6 rounded-lg transition">Add New Plan</button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($message): ?>
        <div class="mb-4 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>

        <!-- Filter Buttons -->
        <div class="mb-6 flex flex-col sm:flex-row gap-4">
            <!-- Membership Type Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold text-gray-700">Type:</span>
                <button data-filter-group="title_filter" data-filter-value="" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">All</button>
                <button data-filter-group="title_filter" data-filter-value="All-inclusive" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">All-inclusive</button>
                <button data-filter-group="title_filter" data-filter-value="BJJ" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">BJJ</button>
                <button data-filter-group="title_filter" data-filter-value="No Gi" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">No Gi Only</button>
            </div>
             <!-- Category Filters -->
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-semibold text-gray-700">Category:</span>
                <button data-filter-group="category_filter" data-filter-value="" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">All</button>
                <button data-filter-group="category_filter" data-filter-value="Adult" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">Adult</button>
                <button data-filter-group="category_filter" data-filter-value="Kid" class="filter-btn px-4 py-2 text-sm font-medium rounded-md bg-white text-gray-700 hover:bg-gray-100">Kid</button>
            </div>
        </div>


        <!-- Plans Table -->
        <div class="bg-white p-8 rounded-2xl shadow-lg overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b-2 border-gray-200">
                        <th class="p-3">Plan Name</th>
                        <th class="p-3">Chinese Name</th>
                        <th class="p-3">Category</th>
                        <th class="p-3">Price</th>
                        <th class="p-3">Duration (Days)</th>
                        <th class="p-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="6" class="p-3 text-center text-gray-500">No membership plans found for the selected filters.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $plan): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="p-3"><?php echo htmlspecialchars($plan['plan_name'] ?? ''); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($plan['plan_name_zh'] ?? ''); ?></td>
                            <td class="p-3 capitalize"><?php echo htmlspecialchars($plan['category'] ?? ''); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($plan['price'] ?? ''); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($plan['duration_days'] ?? ''); ?></td>
                            <td class="p-3">
                                <button class="editBtn bg-blue-500 text-white px-4 py-1 rounded-md hover:bg-blue-600"
                                    data-id="<?php echo $plan['id']; ?>"
                                    data-plan_name="<?php echo htmlspecialchars($plan['plan_name'] ?? ''); ?>"
                                    data-plan_name_zh="<?php echo htmlspecialchars($plan['plan_name_zh'] ?? ''); ?>"
                                    data-category="<?php echo htmlspecialchars($plan['category'] ?? ''); ?>"
                                    data-price="<?php echo htmlspecialchars($plan['price'] ?? ''); ?>"
                                    data-duration_days="<?php echo htmlspecialchars($plan['duration_days'] ?? ''); ?>"
                                    data-description="<?php echo htmlspecialchars($plan['description'] ?? ''); ?>"
                                    data-description_zh="<?php echo htmlspecialchars($plan['description_zh'] ?? ''); ?>">
                                    Edit
                                </button>
                                <button class="deleteBtn bg-red-500 text-white px-4 py-1 rounded-md hover:bg-red-600"
                                    data-id="<?php echo $plan['id']; ?>"
                                    data-plan_name="<?php echo htmlspecialchars($plan['plan_name'] ?? ''); ?>">
                                    Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal for Add/Edit Plan -->
    <div id="planModal" class="modal fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 hidden">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        <div class="modal-content bg-white w-11/12 md:max-w-2xl mx-auto rounded-lg shadow-lg z-50 overflow-y-auto">
            <div class="py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3">
                    <p id="modalTitle" class="text-2xl font-bold">Add New Plan</p>
                    <button class="closeModalBtn cursor-pointer z-50"><svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg></button>
                </div>
                <form id="planForm" action="manage_plans.php" method="POST" class="space-y-4">
                    <input type="hidden" id="action" name="action" value="create">
                    <input type="hidden" id="plan_id" name="plan_id" value="">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="plan_name" class="block text-sm font-medium text-gray-700">Plan Name (English)</label>
                            <input type="text" name="plan_name" id="plan_name" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                        </div>
                        <div>
                            <label for="plan_name_zh" class="block text-sm font-medium text-gray-700">Plan Name (Chinese)</label>
                            <input type="text" name="plan_name_zh" id="plan_name_zh" class="mt-1 block w-full p-2 border border-gray-300 rounded-md">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
                            <select name="category" id="category" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                                <option value="Kid">Kid</option>
                                <option value="Adult">Adult</option>
                            </select>
                        </div>
                        <div>
                            <label for="price" class="block text-sm font-medium text-gray-700">Price</label>
                            <input type="number" step="0.01" name="price" id="price" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                        </div>
                        <div>
                            <label for="duration_days" class="block text-sm font-medium text-gray-700">Duration (in days)</label>
                            <input type="number" name="duration_days" id="duration_days" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required>
                        </div>
                    </div>
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">Description (English)</label>
                        <textarea name="description" id="description" rows="3" class="mt-1 block w-full p-2 border border-gray-300 rounded-md"></textarea>
                    </div>
                    <div>
                        <label for="description_zh" class="block text-sm font-medium text-gray-700">Description (Chinese)</label>
                        <textarea name="description_zh" id="description_zh" rows="3" class="mt-1 block w-full p-2 border border-gray-300 rounded-md"></textarea>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button type="button" class="cancelModalBtn px-4 bg-gray-200 p-3 rounded-lg text-black hover:bg-gray-300 mr-2">Cancel</button>
                        <button type="submit" class="px-4 bg-blue-600 p-3 rounded-lg text-white hover:bg-blue-700">Save Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal for Delete Confirmation -->
    <div id="deleteModal" class="modal fixed w-full h-full top-0 left-0 flex items-center justify-center z-50 hidden">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        <div class="modal-content bg-white w-11/12 md:max-w-lg mx-auto rounded-lg shadow-lg z-50 overflow-y-auto">
            <div class="py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold text-red-600">Confirm Deletion</p>
                    <button class="closeModalBtn cursor-pointer z-50"><svg class="fill-current text-black" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"><path d="M14.53 4.53l-1.06-1.06L9 7.94 4.53 3.47 3.47 4.53 7.94 9l-4.47 4.47 1.06 1.06L9 10.06l4.47 4.47 1.06-1.06L10.06 9z"></path></svg></button>
                </div>
                <div id="deleteModalBody" class="my-5">
                    <!-- Content will be injected by JavaScript -->
                </div>
                <form id="deleteForm" method="POST">
                    <input type="hidden" id="delete_plan_id" name="plan_id">
                    <input type="hidden" name="action" value="delete_plan">
                    <div id="reassignSection" class="hidden">
                        <label for="new_plan_id" class="block text-sm font-medium text-gray-700">Reassign members to:</label>
                        <select id="new_plan_id" name="new_plan_id" class="mt-1 block w-full p-2 border border-gray-300 rounded-md" required></select>
                    </div>
                    <div class="flex justify-end pt-4">
                        <button type="button" class="cancelModalBtn px-4 bg-gray-200 p-3 rounded-lg text-black hover:bg-gray-300 mr-2">Cancel</button>
                        <button type="submit" id="confirmDeleteBtn" class="px-4 bg-red-600 p-3 rounded-lg text-white hover:bg-red-700">Delete Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // --- SCRIPT FOR FILTER BUTTONS ---
            const currentParams = new URLSearchParams(window.location.search);

            function updateFilterButtonsUI() {
                document.querySelectorAll('.filter-btn').forEach(btn => {
                    const group = btn.dataset.filterGroup;
                    const value = btn.dataset.filterValue;
                    if (currentParams.get(group) === value || (!currentParams.has(group) && value === '')) {
                            btn.classList.add('filter-btn-active');
                    } else {
                            btn.classList.remove('filter-btn-active');
                    }
                });
            }
            
            document.querySelectorAll('.filter-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const group = this.dataset.filterGroup;
                    const value = this.dataset.filterValue;

                    if (value === '') {
                        currentParams.delete(group);
                    } else {
                        currentParams.set(group, value);
                    }
                    
                    window.location.search = currentParams.toString();
                });
            });

            updateFilterButtonsUI();


            // --- SCRIPT FOR ADD/EDIT MODAL ---
            const planModal = document.getElementById('planModal');
            const addPlanBtn = document.getElementById('addPlanBtn');
            const editBtns = document.querySelectorAll('.editBtn');
            const planForm = document.getElementById('planForm');

            function openPlanModal() { planModal.classList.remove('hidden'); document.body.classList.add('modal-active'); }
            function closePlanModal() { planModal.classList.add('hidden'); document.body.classList.remove('modal-active'); planForm.reset(); }

            addPlanBtn.addEventListener('click', () => {
                planModal.querySelector('#modalTitle').textContent = 'Add New Plan';
                planForm.querySelector('#action').value = 'create';
                planForm.querySelector('#plan_id').value = '';
                planForm.reset();
                openPlanModal();
            });

            editBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    planModal.querySelector('#modalTitle').textContent = 'Edit Plan';
                    planForm.querySelector('#action').value = 'update';
                    planForm.querySelector('#plan_id').value = btn.dataset.id;
                    planForm.querySelector('#plan_name').value = btn.dataset.plan_name;
                    planForm.querySelector('#plan_name_zh').value = btn.dataset.plan_name_zh;
                    planForm.querySelector('#category').value = btn.dataset.category;
                    planForm.querySelector('#price').value = btn.dataset.price;
                    planForm.querySelector('#duration_days').value = btn.dataset.duration_days;
                    planForm.querySelector('#description').value = btn.dataset.description;
                    planForm.querySelector('#description_zh').value = btn.dataset.description_zh;
                    openPlanModal();
                });
            });

            // --- SCRIPT FOR DELETE MODAL ---
            const deleteModal = document.getElementById('deleteModal');
            const deleteBtns = document.querySelectorAll('.deleteBtn');
            const deleteModalBody = document.getElementById('deleteModalBody');
            const deleteForm = document.getElementById('deleteForm');
            const reassignSection = document.getElementById('reassignSection');
            const newPlanSelect = document.getElementById('new_plan_id');

            function openDeleteModal() { deleteModal.classList.remove('hidden'); document.body.classList.add('modal-active'); }
            function closeDeleteModal() { deleteModal.classList.add('hidden'); document.body.classList.remove('modal-active'); }

            deleteBtns.forEach(btn => {
                btn.addEventListener('click', async () => {
                    const planId = btn.dataset.id;
                    const planName = btn.dataset.plan_name;
                    document.getElementById('delete_plan_id').value = planId;

                    const formData = new FormData();
                    formData.append('action', 'check_dependencies');
                    formData.append('plan_id', planId);

                    try {
                        const response = await fetch('ajax_handler_plans.php', { method: 'POST', body: formData });
                        const data = await response.json();

                        if (data.success) {
                            deleteModalBody.innerHTML = `<p>${data.message}</p>`;
                            if (data.has_dependencies) {
                                reassignSection.classList.remove('hidden');
                                newPlanSelect.innerHTML = '<option value="" disabled selected>Select a new plan...</option>';
                                data.other_plans.forEach(plan => {
                                    const option = document.createElement('option');
                                    option.value = plan.id;
                                    option.textContent = `${plan.plan_name} (${plan.category})`;
                                    newPlanSelect.appendChild(option);
                                });
                                newPlanSelect.required = true;
                            } else {
                                reassignSection.classList.add('hidden');
                                newPlanSelect.required = false;
                            }
                        } else {
                            deleteModalBody.textContent = 'Error: ' + data.message;
                            reassignSection.classList.add('hidden');
                        }
                    } catch (error) {
                        deleteModalBody.textContent = 'An error occurred while checking for dependencies.';
                        console.error('Fetch error:', error);
                    }
                    openDeleteModal();
                });
            });
            
            deleteForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(deleteForm);
                formData.set('action', 'delete_plan');
                
                try {
                    const response = await fetch('ajax_handler_plans.php', { method: 'POST', body: formData });
                    const data = await response.json();

                    if (data.success) {
                        showMessageBox('Plan deleted successfully!', 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        showMessageBox('Error: ' + data.message, 'error');
                    }
                } catch (error) {
                    showMessageBox('An error occurred during deletion.', 'error');
                    console.error('Fetch error:', error);
                }
            });

            // Universal close buttons for all modals
            document.querySelectorAll('.closeModalBtn, .cancelModalBtn, .modal-overlay').forEach(el => {
                el.addEventListener('click', () => {
                    closePlanModal();
                    closeDeleteModal();
                });
            });

            function showMessageBox(message, type = 'info') {
                const messageBox = document.createElement('div');
                messageBox.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-[1000] text-white ${type === 'success' ? 'bg-green-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500')}`;
                messageBox.textContent = message;
                document.body.appendChild(messageBox);

                setTimeout(() => {
                    messageBox.remove();
                }, 3000);
            }
        });
    </script>
</body>
</html>
