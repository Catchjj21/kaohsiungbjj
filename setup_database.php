<?php
// Database Setup Script
// This script will create the database and all tables

// First, connect without specifying a database
$temp_link = mysqli_connect('localhost', 'root', 'root');

if (!$temp_link) {
    die("Connection failed: " . mysqli_connect_error());
}

echo "<h2>Setting up Kaohsiung BJJ Database...</h2>";

// Create the database
$create_db_sql = "CREATE DATABASE IF NOT EXISTS kaohsiungbjj CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if (mysqli_query($temp_link, $create_db_sql)) {
    echo "<p style='color: green;'>✓ Database 'kaohsiungbjj' created successfully</p>";
} else {
    echo "<p style='color: red;'>✗ Error creating database: " . mysqli_error($temp_link) . "</p>";
    mysqli_close($temp_link);
    die();
}

// Close the temporary connection
mysqli_close($temp_link);

// Now use the regular db_config.php which will connect to the specific database
require_once 'db_config.php';

// Read the SQL file
$sql_file = 'database_setup.sql';
if (!file_exists($sql_file)) {
    die("Error: database_setup.sql file not found!");
}

$sql_content = file_get_contents($sql_file);

// Remove the CREATE DATABASE and USE statements since we already created the database
$sql_content = preg_replace('/CREATE DATABASE.*?;/s', '', $sql_content);
$sql_content = preg_replace('/USE kaohsiungbjj;/', '', $sql_content);

// Split the SQL into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql_content)));

$success_count = 0;
$error_count = 0;

foreach ($statements as $statement) {
    if (empty($statement) || strpos($statement, '--') === 0) continue;
    
    try {
        if (mysqli_query($link, $statement)) {
            $success_count++;
            echo "<p style='color: green;'>✓ Success: " . substr($statement, 0, 50) . "...</p>";
        } else {
            $error_count++;
            echo "<p style='color: red;'>✗ Error: " . mysqli_error($link) . "</p>";
        }
    } catch (Exception $e) {
        $error_count++;
        echo "<p style='color: red;'>✗ Exception: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>Setup Complete!</h3>";
echo "<p>Successful operations: $success_count</p>";
echo "<p>Errors: $error_count</p>";

if ($error_count == 0) {
    echo "<p style='color: green; font-weight: bold;'>Database setup completed successfully!</p>";
    echo "<p>Default admin login:</p>";
    echo "<ul>";
    echo "<li>Email: admin@kaohsiungbjj.com</li>";
    echo "<li>Password: admin123</li>";
    echo "</ul>";
    echo "<p><a href='login.html'>Go to Login Page</a></p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>Database setup completed with errors. Please check the errors above.</p>";
}

mysqli_close($link);
?>
