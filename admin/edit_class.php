<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    header("location: ../admin_login.html");
    exit;
}
require_once "../db_config.php";
$class = [
    'id' => '', 'name' => '', 'name_zh' => '', 'day_of_week' => 'Monday', 'start_time' => '', 
    'end_time' => '', 'coach_id' => '', 'capacity' => 15, 'is_active' => 1
];
$page_title = "Add New Class";
$is_editing = false;
if(isset($_GET["id"]) && !empty(trim($_GET["id"]))){
    $is_editing = true;
    $page_title = "Edit Class";
    $class_id = trim($_GET["id"]);
    $sql = "SELECT * FROM classes WHERE id = ?";
    if($stmt = mysqli_prepare($link, $sql)){
        mysqli_stmt_bind_param($stmt, "i", $class_id);
        if(mysqli_stmt_execute($stmt)){
            $result = mysqli_stmt_get_result($stmt);
            if(mysqli_num_rows($result) == 1){
                $class = mysqli_fetch_assoc($result);
            } else {
                header("location: manage_classes.php");
                exit;
            }
        } else {
            echo "Error executing class fetch query: " . mysqli_error($link);
            exit;
        }
        mysqli_stmt_close($stmt);
    } else {
        echo "Error preparing class fetch statement: " . mysqli_error($link);
        exit;
    }
}
// Fetch all coaches to populate the dropdown - MODIFIED to use first_name and last_name
$coaches_sql = "SELECT id, first_name, last_name FROM users WHERE role = 'coach' OR role = 'admin' ORDER BY last_name, first_name";
$coaches_result = mysqli_query($link, $coaches_sql);
$coaches = [];
if ($coaches_result) {
    while($coach_row = mysqli_fetch_assoc($coaches_result)) {
        // Concatenate first_name and last_name for display
        $coach_row['full_name'] = trim($coach_row['first_name'] . ' ' . $coach_row['last_name']);
        $coaches[] = $coach_row;
    }
} else {
    echo "Error fetching coaches: " . mysqli_error($link);
}
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($page_title); ?> - Admin Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family:Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> body { font-family: 'Inter', sans-serif; } </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="manage_classes.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Class List</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800"><?php echo htmlspecialchars($page_title); ?></h1>
        <div class="mt-8 bg-white p-8 rounded-2xl shadow-lg max-w-4xl mx-auto">
            <form action="class_handler.php" method="POST" class="space-y-6">
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($class['id']); ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Class Name (English)</label>
                        <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($class['name']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                    </div>
                    <div>
                        <label for="name_zh" class="block text-sm font-medium text-gray-700">Class Name (Chinese)</label>
                        <input type="text" name="name_zh" id="name_zh" value="<?php echo htmlspecialchars($class['name_zh']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="day_of_week" class="block text-sm font-medium text-gray-700">Day of the Week</label>
                        <select id="day_of_week" name="day_of_week" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <?php $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            foreach ($days as $day) {
                                $selected = ($class['day_of_week'] == $day) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($day) . "' $selected>" . htmlspecialchars($day) . "</option>";
                            } ?>
                        </select>
                    </div>
                    <div>
                        <label for="start_time" class="block text-sm font-medium text-gray-700">Start Time</label>
                        <input type="time" name="start_time" id="start_time" value="<?php echo htmlspecialchars($class['start_time']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                    </div>
                    <div>
                        <label for="end_time" class="block text-sm font-medium text-gray-700">End Time</label>
                        <input type="time" name="end_time" id="end_time" value="<?php echo htmlspecialchars($class['end_time']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label for="coach_id" class="block text-sm font-medium text-gray-700">Coach</label>
                        <select id="coach_id" name="coach_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">-- Select a Coach --</option>
                            <?php foreach ($coaches as $coach) {
                                $selected = ($class['coach_id'] == $coach['id']) ? 'selected' : '';
                                // Use 'full_name' created dynamically for display
                                echo "<option value='" . htmlspecialchars($coach['id']) . "' $selected>" . htmlspecialchars($coach['full_name']) . "</option>";
                            } ?>
                        </select>
                    </div>
                    <div>
                        <label for="capacity" class="block text-sm font-medium text-gray-700">Capacity</label>
                        <input type="number" name="capacity" id="capacity" value="<?php echo htmlspecialchars($class['capacity']); ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm" required>
                    </div>
                    <div>
                        <label for="is_active" class="block text-sm font-medium text-gray-700">Status</label>
                        <select id="is_active" name="is_active" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="1" <?php echo ($class['is_active'] == 1) ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo ($class['is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="pt-5">
                    <div class="flex justify-end">
                        <a href="manage_classes.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</a>
                        <button type="submit" name="action" value="save" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">Save Class</button>
                        <?php if ($is_editing): ?>
                        <button type="submit" name="action" value="delete" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700" onclick="return confirm('Are you sure you want to permanently delete this class? This cannot be undone.')">Delete Class</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
