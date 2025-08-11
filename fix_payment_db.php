<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db_config.php";

echo "<h1>Payment Database Fix</h1>";
echo "<p>This script will add all missing payment fields to your database.</p>";

// Check if user is admin
session_start();
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    echo "<p style='color: red;'>❌ You must be logged in as admin to run this script.</p>";
    echo "<p><a href='admin/admin_login.html'>Login as Admin</a></p>";
    exit;
}

echo "<h2>Step 1: Checking Current Database Structure</h2>";

// Check if payment fields exist in memberships table
$sql_check_memberships = "DESCRIBE memberships";
$result_memberships = mysqli_query($link, $sql_check_memberships);
$memberships_fields = [];
while ($row = mysqli_fetch_assoc($result_memberships)) {
    $memberships_fields[] = $row['Field'];
}

echo "<h3>Memberships Table Fields:</h3>";
echo "<ul>";
foreach ($memberships_fields as $field) {
    echo "<li>$field</li>";
}
echo "</ul>";

// Check if payment fields exist in users table
$sql_check_users = "DESCRIBE users";
$result_users = mysqli_query($link, $sql_check_users);
$users_fields = [];
while ($row = mysqli_fetch_assoc($result_users)) {
    $users_fields[] = $row['Field'];
}

echo "<h3>Users Table Fields:</h3>";
echo "<ul>";
foreach ($users_fields as $field) {
    echo "<li>$field</li>";
}
echo "</ul>";

// Check if payment_history table exists
$sql_check_history = "SHOW TABLES LIKE 'payment_history'";
$result_history = mysqli_query($link, $sql_check_history);
$history_exists = mysqli_num_rows($result_history) > 0;

echo "<h3>Payment History Table:</h3>";
echo $history_exists ? "✅ Exists" : "❌ Does not exist";

echo "<h2>Step 2: Adding Missing Fields</h2>";

$missing_fields = [];

// Check memberships table
if (!in_array('payment_due_date', $memberships_fields)) {
    $missing_fields[] = "ALTER TABLE memberships ADD COLUMN payment_due_date DATE NULL AFTER end_date";
}
if (!in_array('payment_status', $memberships_fields)) {
    $missing_fields[] = "ALTER TABLE memberships ADD COLUMN payment_status ENUM('paid', 'pending', 'overdue') DEFAULT 'pending' AFTER payment_due_date";
}
if (!in_array('last_payment_date', $memberships_fields)) {
    $missing_fields[] = "ALTER TABLE memberships ADD COLUMN last_payment_date DATE NULL AFTER payment_status";
}
if (!in_array('payment_amount', $memberships_fields)) {
    $missing_fields[] = "ALTER TABLE memberships ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER last_payment_date";
}

// Check users table
if (!in_array('payment_status', $users_fields)) {
    $missing_fields[] = "ALTER TABLE users ADD COLUMN payment_status ENUM('active', 'suspended', 'overdue') DEFAULT 'active' AFTER is_verified";
}
if (!in_array('last_payment_date', $users_fields)) {
    $missing_fields[] = "ALTER TABLE users ADD COLUMN last_payment_date DATE NULL AFTER payment_status";
}

// Execute missing field additions
if (!empty($missing_fields)) {
    echo "<h3>Adding Missing Fields:</h3>";
    foreach ($missing_fields as $sql) {
        echo "<p>Executing: $sql</p>";
        if (mysqli_query($link, $sql)) {
            echo "✅ Success<br>";
        } else {
            echo "❌ Error: " . mysqli_error($link) . "<br>";
        }
    }
} else {
    echo "<p>✅ All payment fields already exist</p>";
}

// Create payment_history table if it doesn't exist
if (!$history_exists) {
    echo "<h3>Creating Payment History Table:</h3>";
    $sql_create_history = "CREATE TABLE payment_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        membership_id INT NULL,
        payment_amount DECIMAL(10,2) NOT NULL,
        payment_date DATE NOT NULL,
        payment_method VARCHAR(50),
        payment_status ENUM('completed', 'pending', 'failed') DEFAULT 'completed',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL
    )";
    
    if (mysqli_query($link, $sql_create_history)) {
        echo "✅ Payment history table created successfully<br>";
    } else {
        echo "❌ Error creating payment history table: " . mysqli_error($link) . "<br>";
    }
}

// Create indexes for better performance
echo "<h3>Creating Indexes:</h3>";
$indexes = [
    "CREATE INDEX idx_memberships_payment_status ON memberships(payment_status, payment_due_date)",
    "CREATE INDEX idx_users_payment_status ON users(payment_status)",
    "CREATE INDEX idx_payment_history_user_date ON payment_history(user_id, payment_date)"
];

foreach ($indexes as $sql) {
    if (mysqli_query($link, $sql)) {
        echo "✅ Index created successfully<br>";
    } else {
        echo "⚠️ Index may already exist: " . mysqli_error($link) . "<br>";
    }
}

// Set default values for existing records
echo "<h3>Setting Default Values:</h3>";
$defaults = [
    "UPDATE memberships SET payment_status = 'pending' WHERE payment_status IS NULL",
    "UPDATE users SET payment_status = 'active' WHERE payment_status IS NULL"
];

foreach ($defaults as $sql) {
    if (mysqli_query($link, $sql)) {
        echo "✅ Default values set successfully<br>";
    } else {
        echo "⚠️ Error setting defaults: " . mysqli_error($link) . "<br>";
    }
}

mysqli_close($link);
echo "<h2>✅ Database setup complete!</h2>";
echo "<p><a href='admin/payment_management.php'>Go to Payment Management</a></p>";
echo "<p><a href='admin/admin_dashboard.php'>Return to Admin Dashboard</a></p>";
?>
