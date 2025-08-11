<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database config
require_once "../db_config.php";

// Start the session
session_start();

// Include the admin authentication helper
require_once "admin_auth.php";

// Check if the user has admin access
requireAdminAccess(['admin']);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    switch ($action) {
        case 'update_payment_status':
            $user_id = $_POST['user_id'] ?? 0;
            $membership_id = $_POST['membership_id'] ?? 0;
            $payment_status = $_POST['payment_status'] ?? '';
            $payment_amount = $_POST['payment_amount'] ?? 0;
            $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
            
            if ($user_id && $membership_id && $payment_status) {
                mysqli_begin_transaction($link);
                try {
                    // Update membership payment status
                    $sql_update = "UPDATE memberships SET payment_status = ?, last_payment_date = ?, payment_amount = ? WHERE id = ?";
                    $stmt_update = mysqli_prepare($link, $sql_update);
                    if (!$stmt_update) {
                        throw new Exception("Failed to prepare membership update: " . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($stmt_update, "ssdi", $payment_status, $payment_date, $payment_amount, $membership_id);
                    if (!mysqli_stmt_execute($stmt_update)) {
                        throw new Exception("Failed to update membership: " . mysqli_stmt_error($stmt_update));
                    }
                    mysqli_stmt_close($stmt_update);
                    
                    // Update user payment status
                    $user_status = ($payment_status === 'paid') ? 'active' : 'overdue';
                    $sql_user = "UPDATE users SET payment_status = ?, last_payment_date = ? WHERE id = ?";
                    $stmt_user = mysqli_prepare($link, $sql_user);
                    if (!$stmt_user) {
                        throw new Exception("Failed to prepare user update: " . mysqli_error($link));
                    }
                    mysqli_stmt_bind_param($stmt_user, "ssi", $user_status, $payment_date, $user_id);
                    if (!mysqli_stmt_execute($stmt_user)) {
                        throw new Exception("Failed to update user: " . mysqli_stmt_error($stmt_user));
                    }
                    mysqli_stmt_close($stmt_user);
                    
                    // Record payment history
                    if ($payment_status === 'paid' && $payment_amount > 0) {
                        $sql_history = "INSERT INTO payment_history (user_id, membership_id, payment_amount, payment_date, payment_status) VALUES (?, ?, ?, ?, 'completed')";
                        $stmt_history = mysqli_prepare($link, $sql_history);
                        if (!$stmt_history) {
                            throw new Exception("Failed to prepare payment history insert: " . mysqli_error($link));
                        }
                        mysqli_stmt_bind_param($stmt_history, "iids", $user_id, $membership_id, $payment_amount, $payment_date);
                        if (!mysqli_stmt_execute($stmt_history)) {
                            throw new Exception("Failed to insert payment history: " . mysqli_stmt_error($stmt_history));
                        }
                        mysqli_stmt_close($stmt_history);
                    }
                    
                    mysqli_commit($link);
                    echo json_encode(['success' => true, 'message' => 'Payment status updated successfully']);
                } catch (Exception $e) {
                    mysqli_rollback($link);
                    echo json_encode(['success' => false, 'message' => 'Error updating payment status: ' . $e->getMessage()]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Missing required data']);
            }
            exit;
            
        case 'get_payment_details':
            $user_id = $_POST['user_id'] ?? 0;
            if ($user_id) {
                $sql = "SELECT m.*, u.first_name, u.last_name, u.email FROM memberships m JOIN users u ON m.user_id = u.id WHERE m.user_id = ? ORDER BY m.end_date DESC";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $memberships = mysqli_fetch_all($result, MYSQLI_ASSOC);
                mysqli_stmt_close($stmt);
                
                echo json_encode(['success' => true, 'memberships' => $memberships]);
            } else {
                echo json_encode(['success' => false, 'message' => 'User ID required']);
            }
            exit;
    }
}

// Handle search and filter parameters
$search = $_GET['search'] ?? '';
$member_type_filter = $_GET['member_type'] ?? '';
$payment_status_filter = $_GET['payment_status'] ?? '';

// Build the SQL query with filters
$sql = "
    SELECT 
        u.id,
        u.first_name,
        u.last_name,
        u.email,
        u.member_type,
        u.payment_status as user_payment_status,
        u.last_payment_date as user_last_payment,
        m.membership_type,
        m.end_date,
        m.payment_due_date,
        m.payment_status as membership_payment_status,
        m.last_payment_date as membership_last_payment,
        m.payment_amount,
        m.class_credits
    FROM users u
    LEFT JOIN memberships m ON u.id = m.user_id AND m.end_date = (
        SELECT MAX(end_date) FROM memberships WHERE user_id = u.id
    )
    WHERE u.role = 'member'
";

$params = [];
$types = '';

// Add search filter
if (!empty($search)) {
    $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

// Add member type filter
if (!empty($member_type_filter)) {
    $sql .= " AND u.member_type = ?";
    $params[] = $member_type_filter;
    $types .= 's';
}

// Add payment status filter
if (!empty($payment_status_filter)) {
    $sql .= " AND m.payment_status = ?";
    $params[] = $payment_status_filter;
    $types .= 's';
}

$sql .= " ORDER BY u.last_name, u.first_name";

// Execute the query with parameters
$stmt = mysqli_prepare($link, $sql);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$members = mysqli_fetch_all($result, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);
mysqli_close($link);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Management | Catch Jiu Jitsu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .payment-overdue { background-color: #fef2f2; border-left: 4px solid #ef4444; }
        .payment-pending { background-color: #fffbeb; border-left: 4px solid #f59e0b; }
        .payment-paid { background-color: #f0fdf4; border-left: 4px solid #22c55e; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800">Payment Management</h1>
            <a href="admin_dashboard.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition duration-300 ease-in-out">
                Back to Dashboard
            </a>
        </div>
    </nav>

            <div class="container mx-auto px-6 py-8">
            <div class="bg-white rounded-2xl shadow-lg p-8">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">Member Payment Status</h2>
                </div>
            
            <!-- Search and Filter Form -->
            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               placeholder="Name or email..." 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Member Type</label>
                        <select name="member_type" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Types</option>
                            <option value="adult" <?php echo $member_type_filter === 'adult' ? 'selected' : ''; ?>>Adult</option>
                            <option value="child" <?php echo $member_type_filter === 'child' ? 'selected' : ''; ?>>Child</option>
                            <option value="student" <?php echo $member_type_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
                        <select name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Statuses</option>
                            <option value="paid" <?php echo $payment_status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="pending" <?php echo $payment_status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="overdue" <?php echo $payment_status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2 px-4 rounded-md hover:bg-blue-700 transition">
                            Filter
                        </button>
                    </div>
                </form>
                <?php if (!empty($search) || !empty($member_type_filter) || !empty($payment_status_filter)): ?>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-sm text-gray-600">
                            Showing <?php echo count($members); ?> results
                            <?php if (!empty($search)): ?> for "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
                        </span>
                        <a href="payment_management.php" class="text-sm text-blue-600 hover:text-blue-800">Clear Filters</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Membership</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">End Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Payment</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($members as $member): ?>
                            <?php 
                            $payment_class = '';
                            if ($member['membership_payment_status'] === 'overdue') {
                                $payment_class = 'payment-overdue';
                            } elseif ($member['membership_payment_status'] === 'pending') {
                                $payment_class = 'payment-pending';
                            } elseif ($member['membership_payment_status'] === 'paid') {
                                $payment_class = 'payment-paid';
                            }
                            ?>
                            <tr class="<?php echo $payment_class; ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($member['email']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        if ($member['member_type'] === 'adult') echo 'bg-blue-100 text-blue-800';
                                        elseif ($member['member_type'] === 'child') echo 'bg-green-100 text-green-800';
                                        else echo 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?php echo ucfirst($member['member_type'] ?? 'student'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($member['membership_type'] ?? 'No membership'); ?>
                                    <?php if (isset($member['class_credits'])): ?>
                                        <br><span class="text-xs text-gray-500">Credits: <?php echo $member['class_credits']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $member['end_date'] ? date('M j, Y', strtotime($member['end_date'])) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $member['payment_due_date'] ? date('M j, Y', strtotime($member['payment_due_date'])) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        if ($member['membership_payment_status'] === 'paid') echo 'bg-green-100 text-green-800';
                                        elseif ($member['membership_payment_status'] === 'pending') echo 'bg-yellow-100 text-yellow-800';
                                        else echo 'bg-red-100 text-red-800';
                                        ?>">
                                        <?php echo ucfirst($member['membership_payment_status'] ?? 'unknown'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $member['membership_last_payment'] ? date('M j, Y', strtotime($member['membership_last_payment'])) : 'Never'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button onclick="openPaymentModal(<?php echo $member['id']; ?>, '<?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>')" 
                                            class="text-blue-600 hover:text-blue-900">
                                        Update Payment
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="paymentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4" id="modalTitle">Update Payment Status</h3>
                <form id="paymentForm">
                    <input type="hidden" id="userId" name="user_id">
                    <input type="hidden" id="membershipId" name="membership_id">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Status</label>
                        <select id="paymentStatus" name="payment_status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="pending">Pending</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Amount</label>
                        <input type="number" id="paymentAmount" name="payment_amount" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Payment Date</label>
                        <input type="date" id="paymentDate" name="payment_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closePaymentModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                            Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openPaymentModal(userId, userName) {
            document.getElementById('modalTitle').textContent = `Update Payment - ${userName}`;
            document.getElementById('userId').value = userId;
            document.getElementById('membershipId').value = ''; // Will be set when we get membership details
            document.getElementById('paymentModal').classList.remove('hidden');
            
            // Get membership details
            fetch('payment_management.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=get_payment_details&user_id=${userId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.memberships.length > 0) {
                    const membership = data.memberships[0];
                    document.getElementById('membershipId').value = membership.id;
                    document.getElementById('paymentStatus').value = membership.payment_status || 'pending';
                    document.getElementById('paymentAmount').value = membership.payment_amount || '';
                    if (membership.last_payment_date) {
                        document.getElementById('paymentDate').value = membership.last_payment_date;
                    }
                }
            });
        }
        
        function closePaymentModal() {
            document.getElementById('paymentModal').classList.add('hidden');
        }
        
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_payment_status');
            
            fetch('payment_management.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Payment status updated successfully');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        });
        
        // Add real-time search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            const memberTypeSelect = document.querySelector('select[name="member_type"]');
            const paymentStatusSelect = document.querySelector('select[name="payment_status"]');
            
            // Auto-submit form when filters change
            function autoSubmit() {
                const form = document.querySelector('form');
                form.submit();
            }
            
            // Debounce function for search input
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(autoSubmit, 500);
            });
            
            // Auto-submit on select changes
            memberTypeSelect.addEventListener('change', autoSubmit);
            paymentStatusSelect.addEventListener('change', autoSubmit);
        });
    </script>
</body>
</html>
