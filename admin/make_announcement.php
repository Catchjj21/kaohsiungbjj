<?php
// THE FIX: Include the database config FIRST.
require_once "../db_config.php";

// Now that the settings are loaded, start the session.
session_start();

// Check if the user is logged in and is an admin.
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: admin_login.html");
    exit;
}

$feedback_message = '';
$feedback_type = '';

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate inputs
    $title_en = trim($_POST['title_en']);
    $title_zh = trim($_POST['title_zh']);
    $content_en = trim($_POST['content_en']);
    $content_zh = trim($_POST['content_zh']);
    $target_roles_array = $_POST['target_roles'] ?? [];
    $image_path = null;

    if (empty($title_en) || empty($content_en) || empty($target_roles_array)) {
        $feedback_message = "Error: English Title, English Content, and at least one Target Role are required.";
        $feedback_type = 'error';
    } else {
        // Determine role string for the database
        $target_roles = 'both'; // Default if both are selected
        if (count($target_roles_array) == 1) {
            $target_roles = $target_roles_array[0];
        }

        // Handle the optional file upload
        if (isset($_FILES['announcement_image']) && $_FILES['announcement_image']['error'] == UPLOAD_ERR_OK) {
            $target_dir = "../uploads/announcements/";
            // Ensure the directory exists and is writable
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            
            $file_info = pathinfo($_FILES["announcement_image"]["name"]);
            $file_ext = strtolower($file_info['extension']);
            $new_filename = uniqid('announcement_', true) . '.' . $file_ext;
            $target_file = $target_dir . $new_filename;
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_ext, $allowed_types)) {
                if (move_uploaded_file($_FILES["announcement_image"]["tmp_name"], $target_file)) {
                    // Store the relative path to be used in <img> src attribute
                    $image_path = "uploads/announcements/" . $new_filename;
                } else {
                    $feedback_message = "Sorry, there was an error uploading your file.";
                    $feedback_type = 'error';
                }
            } else {
                $feedback_message = "Sorry, only JPG, JPEG, PNG & GIF files are allowed.";
                $feedback_type = 'error';
            }
        }

        // If there were no file upload errors, proceed to insert into the database
        if ($feedback_type !== 'error') {
            $sql = "INSERT INTO announcements (title_en, title_zh, content_en, content_zh, image_path, target_roles) VALUES (?, ?, ?, ?, ?, ?)";
            if ($stmt = mysqli_prepare($link, $sql)) {
                mysqli_stmt_bind_param($stmt, "ssssss", $title_en, $title_zh, $content_en, $content_zh, $image_path, $target_roles);
                if (mysqli_stmt_execute($stmt)) {
                    $feedback_message = "Announcement created successfully!";
                    $feedback_type = 'success';
                } else {
                    $feedback_message = "Database Error: Could not execute the query. " . mysqli_error($link);
                    $feedback_type = 'error';
                }
                mysqli_stmt_close($stmt);
            } else {
                $feedback_message = "Database Error: Could not prepare the query. " . mysqli_error($link);
                $feedback_type = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Make Announcement - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style> 
        body { font-family: 'Inter', sans-serif; } 
        .feedback-success { background-color: #d1fae5; border-color: #10b981; color: #065f46; }
        .feedback-error { background-color: #fee2e2; border-color: #ef4444; color: #991b1b; }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Header -->
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800">Admin Portal</span>
            <div>
                <a href="admin_dashboard.php" class="text-gray-600 hover:text-purple-600 mr-4">Dashboard</a>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
            </div>
        </div>
    </nav>

    <!-- Page Content -->
    <div class="container mx-auto px-6 py-12">
        <h1 class="text-4xl font-black text-gray-800">Create a New Announcement</h1>
        <p class="mt-2 text-lg text-gray-600">This will be shown as a pop-up to users when they log in.</p>

        <?php if (!empty($feedback_message)): ?>
        <div class="mt-6 p-4 rounded-lg border <?php echo $feedback_type === 'success' ? 'feedback-success' : 'feedback-error'; ?>" role="alert">
            <?php echo htmlspecialchars($feedback_message); ?>
        </div>
        <?php endif; ?>

        <div class="mt-8 bg-white p-8 rounded-2xl shadow-lg">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                
                <!-- Target Roles -->
                <div>
                    <label class="block text-lg font-bold text-gray-800 mb-2">Target Audience <span class="text-red-500">*</span></label>
                    <div class="flex flex-wrap gap-x-6 gap-y-2">
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="target_roles[]" value="member" class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-gray-700">Member</span>
                        </label>
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="target_roles[]" value="coach" class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span class="text-gray-700">Coach</span>
                        </label>
                    </div>
                </div>

                <!-- English Content -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="title_en" class="block text-sm font-medium text-gray-700">Title (English) <span class="text-red-500">*</span></label>
                        <input type="text" name="title_en" id="title_en" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label for="title_zh" class="block text-sm font-medium text-gray-700">Title (Chinese)</label>
                        <input type="text" name="title_zh" id="title_zh" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>
                <div>
                    <label for="content_en" class="block text-sm font-medium text-gray-700">Content (English) <span class="text-red-500">*</span></label>
                    <textarea name="content_en" id="content_en" rows="6" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                </div>
                <div>
                    <label for="content_zh" class="block text-sm font-medium text-gray-700">Content (Chinese)</label>
                    <textarea name="content_zh" id="content_zh" rows="6" class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                </div>

                <!-- Image Upload -->
                <div>
                    <label for="announcement_image" class="block text-sm font-medium text-gray-700">Upload Image (Optional)</label>
                    <input type="file" name="announcement_image" id="announcement_image" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                    <p class="mt-1 text-xs text-gray-500">Allowed formats: JPG, PNG, GIF.</p>
                </div>

                <!-- Submit Button -->
                <div class="text-right">
                    <button type="submit" class="inline-flex justify-center py-3 px-8 border border-transparent shadow-sm text-sm font-bold rounded-lg text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition">
                        Publish Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
