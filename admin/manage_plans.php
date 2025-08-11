<?php
// =========================================================================
//            Enhanced Error Reporting & Setup
// =========================================================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure this path is correct for your setup
require_once "../db_config.php"; 
session_start();

// =========================================================================
//            Security Check
// =========================================================================
// Redirects to admin_login.html if not logged in as admin or coach
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])) {
    // For AJAX requests, send a JSON error response instead of redirecting
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }
    header("location: admin_login.html");
    exit;
}

// Set character set for the database connection
mysqli_set_charset($link, "utf8mb4");

// =========================================================================
//            AJAX API Endpoint Logic
// =========================================================================
// This block handles all CUD (Create, Update, Delete) operations via AJAX
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    $response = ['success' => false, 'message' => 'An unknown error occurred.'];

    try {
        switch ($action) {
            // --- CREATE ACTION ---
            case 'create':
                $sql = "INSERT INTO membership_plans (plan_name, plan_name_zh, category, price, duration_days, description, description_zh) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "sssdiss", $_POST['plan_name'], $_POST['plan_name_zh'], $_POST['category'], $_POST['price'], $_POST['duration_days'], $_POST['description'], $_POST['description_zh']);
                if (mysqli_stmt_execute($stmt)) {
                    $new_plan_id = mysqli_insert_id($link);
                    $response = ['success' => true, 'message' => 'New plan created successfully!', 'new_plan_id' => $new_plan_id];
                } else {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);
                break;

            // --- UPDATE ACTION ---
            case 'update':
                $sql = "UPDATE membership_plans SET plan_name=?, plan_name_zh=?, category=?, price=?, duration_days=?, description=?, description_zh=? WHERE id=?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "sssdissi", $_POST['plan_name'], $_POST['plan_name_zh'], $_POST['category'], $_POST['price'], $_POST['duration_days'], $_POST['description'], $_POST['description_zh'], $_POST['plan_id']);
                if (mysqli_stmt_execute($stmt)) {
                    $response = ['success' => true, 'message' => 'Plan updated successfully!'];
                } else {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);
                break;

            // --- DELETE ACTION ---
            case 'delete':
                $plan_id = $_POST['plan_id'];
                // First, check if any members are assigned to this plan
                $check_sql = "SELECT COUNT(*) as member_count FROM members WHERE membership_plan_id = ?";
                $check_stmt = mysqli_prepare($link, $check_sql);
                mysqli_stmt_bind_param($check_stmt, "i", $plan_id);
                mysqli_stmt_execute($check_stmt);
                $result = mysqli_stmt_get_result($check_stmt);
                $row = mysqli_fetch_assoc($result);
                mysqli_stmt_close($check_stmt);

                if ($row['member_count'] > 0) {
                     throw new Exception("Cannot delete plan. {$row['member_count']} members are currently assigned to it. Please reassign them first.");
                }

                // If no members are assigned, proceed with deletion
                $sql = "DELETE FROM membership_plans WHERE id = ?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "i", $plan_id);
                if (mysqli_stmt_execute($stmt)) {
                    $response = ['success' => true, 'message' => 'Plan deleted successfully.'];
                } else {
                    throw new Exception(mysqli_stmt_error($stmt));
                }
                mysqli_stmt_close($stmt);
                break;
        }
    } catch (Exception $e) {
        http_response_code(500);
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    mysqli_close($link);
    exit; // Stop script execution after handling AJAX request
}


// =========================================================================
//            Initial Page Load: Fetch All Plans
// =========================================================================
// This part runs only on the initial page load to get all the data.
// Filtering will be handled client-side by JavaScript for a faster experience.
$plans = [];
$sql_fetch = "SELECT id, plan_name, plan_name_zh, category, price, duration_days, description, description_zh FROM membership_plans ORDER BY category, price";
if ($result = mysqli_query($link, $sql_fetch)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $plans[] = $row;
    }
    mysqli_free_result($result);
} else {
    // Handle error in fetching initial data
    $initial_error = "Error fetching plans: " . mysqli_error($link);
}
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Membership Plans</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4f46e5; /* Indigo 600 */
            --primary-hover: #4338ca; /* Indigo 700 */
            --danger-color: #dc2626; /* Red 600 */
            --danger-hover: #b91c1c; /* Red 700 */
            --success-color: #16a34a; /* Green 600 */
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #f3f4f6; /* Gray 100 */
        }
        .modal { 
            transition: opacity 0.3s ease, visibility 0.3s ease; 
            visibility: hidden;
            opacity: 0;
        }
        .modal.is-open { 
            visibility: visible;
            opacity: 1;
        }
        .modal-content { 
            transition: transform 0.3s ease; 
            transform: translateY(20px) scale(0.95);
        }
        .modal.is-open .modal-content {
            transform: translateY(0) scale(1);
        }
        .filter-btn.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
        }
        .plan-card {
            transition: transform 0.2s ease-out, box-shadow 0.2s ease-out;
        }
        .plan-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
        }
        /* Toast Notification */
        .toast {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 1000;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            color: white;
            font-weight: 600;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            transform: translateX(120%);
            transition: transform 0.4s ease-in-out;
        }
        .toast.show {
            transform: translateX(0);
        }
    </style>
</head>
<body class="antialiased text-gray-800">

    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-600"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg>
                    <h1 class="text-xl font-bold text-gray-900">Admin Portal</h1>
                </div>
                <div class="flex items-center space-x-4">
                     <a href="admin_dashboard.php" class="text-sm font-semibold text-gray-600 hover:text-indigo-600 transition-colors">Dashboard</a>
                     <a href="../logout.php" class="text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 px-4 py-2 rounded-md transition-colors">Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="flex flex-col md:flex-row justify-between md:items-center mb-8">
            <h2 class="text-3xl font-extrabold text-gray-900 tracking-tight">Membership Plans</h2>
            <p class="mt-2 md:mt-0 text-lg text-gray-500">Manage, create, and edit member packages.</p>
        </div>

        <?php if (isset($initial_error)): ?>
        <div class="mb-6 p-4 rounded-lg bg-red-100 text-red-800 border border-red-200">
            <strong>Error:</strong> <?php echo htmlspecialchars($initial_error); ?>
        </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white p-4 rounded-lg shadow-sm mb-8">
            <div class="flex flex-col sm:flex-row gap-6">
                <!-- Category Filters -->
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="font-semibold text-gray-700 text-sm">Category:</span>
                    <button data-filter-group="category" data-filter-value="all" class="filter-btn active px-3 py-1.5 text-sm font-medium rounded-md hover:bg-indigo-500 hover:text-white transition-all">All</button>
                    <button data-filter-group="category" data-filter-value="Adult" class="filter-btn px-3 py-1.5 text-sm font-medium rounded-md text-gray-600 bg-gray-100 hover:bg-indigo-500 hover:text-white transition-all">Adult</button>
                    <button data-filter-group="category" data-filter-value="Kid" class="filter-btn px-3 py-1.5 text-sm font-medium rounded-md text-gray-600 bg-gray-100 hover:bg-indigo-500 hover:text-white transition-all">Kid</button>
                </div>
            </div>
        </div>

        <!-- Plans Grid -->
        <div id="plansGrid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Plan cards will be injected here by JavaScript -->
        </div>
        <div id="noResultsMessage" class="hidden text-center py-16">
             <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto text-gray-400 mb-4"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
            <h3 class="text-xl font-semibold text-gray-700">No Plans Found</h3>
            <p class="text-gray-500 mt-1">No membership plans match the current filters.</p>
        </div>
    </main>

    <!-- Floating Add Button -->
    <button id="addPlanBtn" title="Add New Plan" class="fixed bottom-8 right-8 bg-indigo-600 hover:bg-indigo-700 text-white rounded-full p-4 shadow-lg transition-transform hover:scale-110 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
    </button>

    <!-- Add/Edit Plan Modal -->
    <div id="planModal" class="modal fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-overlay absolute inset-0 bg-black bg-opacity-60" data-close-modal></div>
        <div class="modal-content bg-white w-full max-w-2xl rounded-lg shadow-xl z-50 transform transition-all">
            <form id="planForm" class="flex flex-col h-full">
                <div class="flex justify-between items-center p-5 border-b border-gray-200">
                    <h3 id="modalTitle" class="text-xl font-bold text-gray-900">Add New Plan</h3>
                    <button type="button" data-close-modal class="text-gray-400 hover:text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                
                <div class="p-6 space-y-6 overflow-y-auto" style="max-height: 70vh;">
                    <input type="hidden" id="action" name="action" value="create">
                    <input type="hidden" id="plan_id" name="plan_id" value="">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="plan_name" class="block text-sm font-medium text-gray-700 mb-1">Plan Name (English) <span class="text-red-500">*</span></label>
                            <input type="text" name="plan_name" id="plan_name" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div>
                            <label for="plan_name_zh" class="block text-sm font-medium text-gray-700 mb-1">Plan Name (Chinese)</label>
                            <input type="text" name="plan_name_zh" id="plan_name_zh" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category <span class="text-red-500">*</span></label>
                            <select name="category" id="category" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                                <option value="Adult">Adult</option>
                                <option value="Kid">Kid</option>
                            </select>
                        </div>
                        <div>
                            <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Price <span class="text-red-500">*</span></label>
                            <input type="number" step="0.01" name="price" id="price" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                        <div>
                            <label for="duration_days" class="block text-sm font-medium text-gray-700 mb-1">Duration (days) <span class="text-red-500">*</span></label>
                            <input type="number" name="duration_days" id="duration_days" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description (English)</label>
                        <textarea name="description" id="description" rows="3" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                    </div>
                    <div>
                        <label for="description_zh" class="block text-sm font-medium text-gray-700 mb-1">Description (Chinese)</label>
                        <textarea name="description_zh" id="description_zh" rows="3" class="block w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                    </div>
                </div>

                <div class="flex justify-end items-center p-5 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                    <button type="button" data-close-modal class="px-5 py-2.5 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50 mr-3">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white rounded-md text-sm font-semibold hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">Save Plan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="modal-overlay absolute inset-0 bg-black bg-opacity-60" data-close-modal></div>
        <div class="modal-content bg-white w-full max-w-md rounded-lg shadow-xl z-50">
            <div class="p-6 text-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-4 text-red-500"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <h3 class="mb-2 text-xl font-bold text-gray-800">Delete Plan?</h3>
                <p class="text-gray-500">Are you sure you want to delete the plan "<strong id="deletePlanName"></strong>"? This action cannot be undone.</p>
            </div>
            <div class="flex justify-center items-center p-6 bg-gray-50 rounded-b-lg space-x-4">
                <button type="button" data-close-modal class="px-6 py-2.5 bg-white border border-gray-300 rounded-md text-sm font-semibold text-gray-700 hover:bg-gray-50">Cancel</button>
                <button type="button" id="confirmDeleteBtn" class="px-6 py-2.5 bg-red-600 text-white rounded-md text-sm font-semibold hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">Delete</button>
            </div>
        </div>
    </div>


    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // =====================================================================
        //            STATE MANAGEMENT & INITIAL DATA
        // =====================================================================
        const allPlans = <?php echo json_encode($plans); ?>;
        let currentFilters = {
            category: 'all'
        };

        // =====================================================================
        //            ELEMENT SELECTORS
        // =====================================================================
        const plansGrid = document.getElementById('plansGrid');
        const noResultsMessage = document.getElementById('noResultsMessage');
        const planModal = document.getElementById('planModal');
        const deleteModal = document.getElementById('deleteModal');
        const planForm = document.getElementById('planForm');
        const modalTitle = document.getElementById('modalTitle');

        // =====================================================================
        //            UTILITY & HELPER FUNCTIONS
        // =====================================================================

        // Sanitize HTML to prevent XSS
        const sanitize = (str) => {
            const temp = document.createElement('div');
            temp.textContent = str;
            return temp.innerHTML;
        };

        // Show a toast notification
        const showToast = (message, type = 'success') => {
            const toast = document.createElement('div');
            const bgColor = type === 'success' ? 'bg-green-600' : 'bg-red-600';
            toast.className = `toast ${bgColor}`;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            // Animate in
            setTimeout(() => toast.classList.add('show'), 10);
            
            // Animate out and remove
            setTimeout(() => {
                toast.classList.remove('show');
                toast.addEventListener('transitionend', () => toast.remove());
            }, 3000);
        };

        // =====================================================================
        //            RENDERING LOGIC
        // =====================================================================

        // Create HTML for a single plan card
        const createPlanCardHTML = (plan) => {
            const planData = Object.keys(plan).reduce((acc, key) => {
                acc[key] = sanitize(String(plan[key] || ''));
                return acc;
            }, {});

            return `
                <div class="plan-card bg-white rounded-lg shadow-md overflow-hidden flex flex-col" data-plan-id="${planData.id}" data-category="${planData.category}">
                    <div class="p-5 flex-grow">
                        <div class="flex justify-between items-start">
                            <span class="inline-block bg-indigo-100 text-indigo-800 text-xs font-semibold px-2.5 py-0.5 rounded-full mb-2">${planData.category}</span>
                            <div class="relative" data-dropdown-container>
                                <button class="p-1.5 text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-full" data-dropdown-button>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"></circle><circle cx="12" cy="5" r="1"></circle><circle cx="12" cy="19" r="1"></circle></svg>
                                </button>
                                <div class="absolute right-0 mt-2 w-36 bg-white rounded-md shadow-lg py-1 z-20 hidden" data-dropdown-menu>
                                    <a href="#" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" data-action="edit">Edit Plan</a>
                                    <a href="#" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100" data-action="delete">Delete Plan</a>
                                </div>
                            </div>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 truncate" title="${planData.plan_name}">${planData.plan_name}</h3>
                        <p class="text-sm text-gray-500 mb-4 truncate" title="${planData.plan_name_zh}">${planData.plan_name_zh || '&nbsp;'}</p>
                        <p class="text-gray-800 font-extrabold text-3xl mb-1">
                            $${planData.price}
                        </p>
                        <p class="text-gray-500 text-sm">for ${planData.duration_days} days</p>
                    </div>
                    <div class="bg-gray-50 px-5 py-3 border-t border-gray-100">
                        <p class="text-xs text-gray-600 truncate" title="${planData.description}">${planData.description || 'No description provided.'}</p>
                    </div>
                </div>
            `;
        };

        // Render plans based on current filters
        const renderPlans = () => {
            const filteredPlans = allPlans.filter(plan => {
                const categoryMatch = currentFilters.category === 'all' || plan.category === currentFilters.category;
                return categoryMatch;
            });

            plansGrid.innerHTML = '';
            if (filteredPlans.length > 0) {
                filteredPlans.forEach(plan => {
                    const cardHTML = createPlanCardHTML(plan);
                    plansGrid.insertAdjacentHTML('beforeend', cardHTML);
                });
                noResultsMessage.classList.add('hidden');
            } else {
                noResultsMessage.classList.remove('hidden');
            }
        };

        // =====================================================================
        //            MODAL HANDLING
        // =====================================================================

        const openModal = (modal) => modal.classList.add('is-open');
        const closeModal = (modal) => modal.classList.remove('is-open');

        // Open "Add Plan" modal
        document.getElementById('addPlanBtn').addEventListener('click', () => {
            planForm.reset();
            modalTitle.textContent = 'Add New Plan';
            planForm.action.value = 'create';
            planForm.plan_id.value = '';
            openModal(planModal);
        });

        // Open "Edit Plan" modal
        const openEditModal = (planId) => {
            const plan = allPlans.find(p => p.id == planId);
            if (!plan) return;
            
            planForm.reset();
            modalTitle.textContent = 'Edit Plan';
            planForm.action.value = 'update';
            planForm.plan_id.value = plan.id;
            planForm.plan_name.value = plan.plan_name;
            planForm.plan_name_zh.value = plan.plan_name_zh;
            planForm.category.value = plan.category;
            planForm.price.value = plan.price;
            planForm.duration_days.value = plan.duration_days;
            planForm.description.value = plan.description;
            planForm.description_zh.value = plan.description_zh;
            openModal(planModal);
        };
        
        // Open "Delete Plan" modal
        const openDeleteModal = (planId) => {
            const plan = allPlans.find(p => p.id == planId);
            if (!plan) return;

            document.getElementById('deletePlanName').textContent = plan.plan_name;
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            // Clone and replace to remove old event listeners
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            newConfirmBtn.addEventListener('click', () => handleDelete(planId));
            openModal(deleteModal);
        };

        // Generic close modal listeners
        document.querySelectorAll('[data-close-modal]').forEach(el => {
            el.addEventListener('click', () => {
                closeModal(planModal);
                closeModal(deleteModal);
            });
        });

        // =====================================================================
        //            EVENT LISTENERS & HANDLERS
        // =====================================================================

        // Filter button handler
        document.querySelectorAll('.filter-btn').forEach(button => {
            button.addEventListener('click', function() {
                const group = this.dataset.filterGroup;
                const value = this.dataset.filterValue;

                currentFilters[group] = value;
                
                document.querySelectorAll(`.filter-btn[data-filter-group="${group}"]`).forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');

                renderPlans();
            });
        });

        // Dropdown menu handler (for edit/delete)
        document.addEventListener('click', e => {
            const dropdownButton = e.target.closest('[data-dropdown-button]');
            
            // Close all other dropdowns
            document.querySelectorAll('[data-dropdown-menu]').forEach(menu => {
                if (!menu.closest('[data-dropdown-container]').contains(dropdownButton)) {
                     menu.classList.add('hidden');
                }
            });

            if (dropdownButton) {
                const menu = dropdownButton.nextElementSibling;
                menu.classList.toggle('hidden');
            }
        });

        // Card action (edit/delete) handler using event delegation
        plansGrid.addEventListener('click', e => {
            e.preventDefault();
            const actionEl = e.target.closest('[data-action]');
            if (!actionEl) return;

            const action = actionEl.dataset.action;
            const card = actionEl.closest('.plan-card');
            const planId = card.dataset.planId;

            if (action === 'edit') {
                openEditModal(planId);
            } else if (action === 'delete') {
                openDeleteModal(planId);
            }
        });

        // Form submission handler
        planForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(planForm);
            const action = formData.get('action');
            const url = '<?php echo basename($_SERVER["PHP_SELF"]); ?>';

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'An unexpected server error occurred.' }));
                    throw new Error(errorData.message || 'Failed to save plan.');
                }

                const result = await response.json();

                if (result.success) {
                    showToast(result.message, 'success');
                    // Update local data and re-render
                    if (action === 'create') {
                        const newPlan = Object.fromEntries(formData.entries());
                        newPlan.id = result.new_plan_id;
                        allPlans.push(newPlan);
                    } else if (action === 'update') {
                        const planIndex = allPlans.findIndex(p => p.id == formData.get('plan_id'));
                        if (planIndex > -1) {
                            allPlans[planIndex] = { ...allPlans[planIndex], ...Object.fromEntries(formData.entries()) };
                        }
                    }
                    renderPlans();
                    closeModal(planModal);
                } else {
                    throw new Error(result.message);
                }
            } catch (error) {
                showToast(error.message, 'error');
            }
        });

        // Actual delete logic handler
        const handleDelete = async (planId) => {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('plan_id', planId);
            const url = '<?php echo basename($_SERVER["PHP_SELF"]); ?>';

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'An unexpected server error occurred.' }));
                    throw new Error(errorData.message || 'Failed to delete plan.');
                }
                
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    // Remove from local data and re-render
                    const planIndex = allPlans.findIndex(p => p.id == planId);
                    if (planIndex > -1) {
                        allPlans.splice(planIndex, 1);
                    }
                    renderPlans();
                    closeModal(deleteModal);
                } else {
                     throw new Error(result.message);
                }
            } catch (error) {
                showToast(error.message, 'error');
            }
        };

        // =====================================================================
        //            INITIALIZATION
        // =====================================================================
        renderPlans();
    });
    </script>
</body>
</html>
