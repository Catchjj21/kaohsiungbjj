<?php
// File: get_classes.php

// Include your database configuration file
require 'db_config.php';

// Set the content type to JSON and specify UTF-8 charset for Chinese characters
header('Content-Type: application/json; charset=utf-8');

// SQL query to select only active classes from the classes table
$sql = "SELECT name, name_zh, day_of_week, start_time, end_time, is_active, age FROM classes WHERE is_active = 1";
$result = $link->query($sql); // CHANGED: from $conn to $link

$classes = [];
if ($result && $result->num_rows > 0) {
    // Loop through each row of the result set
    while($row = $result->fetch_assoc()) {
        // Format the start and end times to show only hours and minutes (e.g., 18:30)
        $row['start_time'] = date('H:i', strtotime($row['start_time']));
        $row['end_time'] = date('H:i', strtotime($row['end_time']));
        // Add the formatted row to the classes array
        $classes[] = $row;
    }
}

// Close the database connection
$link->close(); // CHANGED: from $conn to $link

// Encode the classes array into a JSON string and output it
echo json_encode($classes);
?>