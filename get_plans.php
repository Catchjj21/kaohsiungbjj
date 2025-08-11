<?php
// File: get_plans.php

// Include your database configuration file
require 'db_config.php';

// Set the content type to JSON and specify UTF-8 charset for Chinese characters
header('Content-Type: application/json; charset=utf-8');

// SQL query to select all necessary columns from the membership_plans table, sorted by price ascending.
$sql = "SELECT plan_name, category, price, description, description_zh, plan_name_zh FROM membership_plans ORDER BY price ASC";
$result = $link->query($sql); // Assumes $link is the database connection variable from db_config.php

$plans = [];
if ($result && $result->num_rows > 0) {
    // Loop through each row of the result set
    while($row = $result->fetch_assoc()) {
        // Add the row directly to the plans array.
        // The JavaScript will handle the grouping based on the 'plan_name'.
        $plans[] = $row;
    }
}

// Close the database connection
$link->close(); // Assumes $link is the database connection variable

// Encode the plans array into a JSON string and output it
echo json_encode($plans);
?>
