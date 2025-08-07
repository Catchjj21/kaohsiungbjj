<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// IMPORTANT: Ensure db_config.php is included BEFORE session_start()
// if db_config.php contains session_set_cookie_params() or similar session configuration.
require_once "../db_config.php";

session_start();

// Check if the user is logged in and has the correct role (admin or coach)
// FIX: Changed "loggedin" to "admin_loggedin" for consistency across the admin portal.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || ($_SESSION["role"] !== 'admin' && $_SESSION["role"] !== 'coach')){
    header("location: admin_login.html");
    exit;
}

// Fetch all classes and organize them by day and time
// NEW: Added 'age' to the SQL query
$sql_classes = "SELECT c.id, c.name, c.name_zh, c.day_of_week, c.start_time, c.end_time, c.is_active, c.capacity, c.age, u.id AS coach_id, u.first_name AS coach_first_name, u.last_name AS coach_last_name FROM classes c LEFT JOIN users u ON c.coach_id = u.id ORDER BY FIELD(c.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), c.start_time";
$classes_raw = [];
if($result_classes = mysqli_query($link, $sql_classes)){
    while($row = mysqli_fetch_assoc($result_classes)){
        $classes_raw[] = $row;
    }
} else {
    // Log SQL errors for debugging, but don't expose them directly to the user
    error_log("SQL Error fetching classes: " . mysqli_error($link));
}

// Fetch all coaches for the dropdown in the edit modal
$sql_coaches = "SELECT id, first_name, last_name FROM users WHERE role = 'coach' ORDER BY first_name";
$coaches = [];
if($result_coaches = mysqli_query($link, $sql_coaches)){
    while($row = mysqli_fetch_assoc($result_coaches)){
        $coaches[] = $row;
    }
} else {
    // Log SQL errors for debugging
    error_log("SQL Error fetching coaches: " . mysqli_error($link));
}

// Close the database connection
mysqli_close($link);

// Define days of the week and time slots for the grid
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$time_slots = [
    '07:00:00', '08:00:00', '09:00:00', '10:00:00', '11:00:00', '12:00:00',
    '13:00:00', '14:00:00', '15:00:00', '16:00:00', '17:00:00', '18:00:00',
    '19:00:00', '20:00:00', '21:00:00', '22:00:00' // Extended up to 10:00 PM
]; // All 1-hour time slots from 7 AM to 10 PM

// Organize classes into a structured timetable
$timetable = [];
foreach ($days_of_week as $day) {
    $timetable[$day] = [];
    foreach ($time_slots as $slot) {
        $timetable[$day][$slot] = []; // Initialize each slot as an empty array
    }
}

foreach ($classes_raw as $class) {
    $day = $class['day_of_week'];
    $start_time = date('H:i:s', strtotime($class['start_time'])); // Normalize time to HH:MM:SS
    
    // Find the closest time slot for the class
    $closest_slot = null;
    foreach ($time_slots as $slot) {
        if ($start_time >= $slot) {
            $closest_slot = $slot;
        } else {
            // If the current slot is greater than start_time, the previous slot was the closest
            // Unless it's the very first slot and start_time is earlier.
            break;
        }
    }
    
    // If a class starts before the first defined time slot, assign it to the first slot.
    // Otherwise, assign it to the closest_slot found.
    if ($closest_slot && isset($timetable[$day][$closest_slot])) {
        $timetable[$day][$closest_slot][] = $class;
    } elseif (count($time_slots) > 0 && $start_time < $time_slots[0]) {
        // If class starts before the first defined slot, put it in the first slot
        $timetable[$day][$time_slots[0]][] = $class;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Classes - Admin Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .modal { transition: opacity 0.25s ease; }
        /* Custom grid styles for timetable */
        .timetable-grid {
            display: grid;
            grid-template-columns: 80px repeat(7, minmax(120px, 1fr)); /* Time column + 7 days */
            gap: 4px;
        }
        .grid-header-cell, .grid-time-cell, .grid-class-cell {
            padding: 8px;
            text-align: center;
            border-radius: 8px; /* Rounded corners for grid cells */
        }
        .grid-header-cell {
            background-color: #e2e8f0; /* gray-200 */
            font-weight: 600;
        }
        .grid-time-cell {
            background-color: #f0f4f8; /* gray-100 */
            font-weight: 500;
            text-align: right;
            padding-right: 12px;
        }
        .grid-class-cell {
            background-color: #ffffff;
            min-height: 80px; /* Ensure cells have a minimum height */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
        }
        .class-card {
            width: 100%;
            padding: 4px;
            margin-bottom: 4px;
            border-radius: 6px;
            font-size: 0.75rem; /* text-xs */
            line-height: 1rem;
            cursor: pointer;
            transition: transform 0.1s ease-in-out;
            border: 1px solid transparent;
        }
        .class-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .class-card.active {
            background-color: #d1fae5; /* green-100 */
            border-color: #34d399; /* green-400 */
        }
        .class-card.inactive {
            background-color: #fee2e2; /* red-100 */
            border-color: #ef4444; /* red-500 */
            opacity: 0.7;
        }
        .class-card .class-name {
            font-weight: 600;
            color: #1f2937; /* gray-800 */
        }
        .class-card .class-time, .class-card .coach-name {
            color: #4b5563; /* gray-600 */
        }
        .add-class-slot {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 80px;
            background-color: #f9fafb; /* gray-50 */
            border: 1px dashed #d1d5db; /* gray-300 */
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        .add-class-slot:hover {
            background-color: #eff6ff; /* blue-50 */
            border-color: #93c5fd; /* blue-300 */
        }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <div>
                <a href="admin_dashboard.php" class="text-sm text-blue-600 hover:underline">&larr; Back to Admin Dashboard</a>
                <span class="font-bold text-xl text-gray-800 ml-4">Admin Portal</span>
            </div>
            <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
        </div>
    </nav>
    <div class="container mx-auto px-6 py-12">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-4xl font-black text-gray-800">Manage Classes</h1>
                <p class="mt-2 text-lg text-gray-600">Add, edit, or deactivate classes.</p>
            </div>
            <button id="add-new-class-btn" class="bg-green-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-green-700 transition">
                + Add New Class
            </button>
        </div>

        <div class="mt-8 bg-white p-6 rounded-2xl shadow-lg overflow-x-auto">
            <div class="timetable-grid">
                <!-- Top-left empty corner -->
                <div class="grid-header-cell"></div>
                <!-- Day Headers -->
                <?php foreach ($days_of_week as $day): ?>
                    <div class="grid-header-cell"><?php echo htmlspecialchars($day); ?></div>
                <?php endforeach; ?>

                <?php foreach ($time_slots as $slot): ?>
                    <!-- Time Slot Header -->
                    <div class="grid-time-cell"><?php echo date('H:i', strtotime($slot)); ?></div>
                    <?php foreach ($days_of_week as $day): ?>
                        <div class="grid-class-cell">
                            <?php if (!empty($timetable[$day][$slot])): ?>
                                <?php foreach ($timetable[$day][$slot] as $class): ?>
                                    <div class="class-card <?php echo $class['is_active'] ? 'active' : 'inactive'; ?>"
                                        data-class-id="<?php echo $class['id']; ?>"
                                        data-class-name-en="<?php echo htmlspecialchars($class['name']); ?>"
                                        data-class-name-zh="<?php echo htmlspecialchars($class['name_zh']); ?>"
                                        data-day-of-week="<?php echo htmlspecialchars($class['day_of_week']); ?>"
                                        data-start-time="<?php echo date('H:i', strtotime($class['start_time'])); ?>"
                                        data-end-time="<?php echo date('H:i', strtotime($class['end_time'])); ?>"
                                        data-coach-id="<?php echo htmlspecialchars($class['coach_id']); ?>"
                                        data-is-active="<?php echo $class['is_active'] ? '1' : '0'; ?>"
                                        data-capacity="<?php echo htmlspecialchars($class['capacity']); ?>"
                                        data-age="<?php echo htmlspecialchars($class['age']); ?>">
                                        <div class="class-name"><?php echo htmlspecialchars($class['name']); ?></div>
                                        <div class="class-time"><?php echo date('H:i', strtotime($class['start_time'])) . ' - ' . date('H:i', strtotime($class['end_time'])); ?></div>
                                        <div class="coach-name"><?php echo htmlspecialchars($class['coach_first_name'] . ' ' . $class['coach_last_name']); ?></div>
                                        <div class="class-status text-xs font-semibold mt-1">
                                            <?php echo $class['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="add-class-slot"
                                    data-day-of-week="<?php echo htmlspecialchars($day); ?>"
                                    data-start-time="<?php echo date('H:i', strtotime($slot)); ?>">
                                    <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Add/Edit Class Modal -->
    <div id="class-modal" class="modal fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 p-4 hidden">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] flex flex-col">
            <header class="flex items-center justify-between p-4 border-b">
                <h3 id="modal-title" class="text-2xl font-bold">Add New Class</h3>
                <button id="close-class-modal" class="text-gray-500 hover:text-gray-800 text-3xl">&times;</button>
            </header>
            <div class="p-6 overflow-auto">
                <form id="class-form" action="manage_classes_handler.php" method="POST" class="space-y-4">
                    <input type="hidden" id="class-id" name="class_id" value="">
                    <input type="hidden" id="form-action" name="action" value="add"> <!-- 'add' or 'edit' -->

                    <div>
                        <label for="class-name-en" class="block mb-2 text-sm font-medium text-gray-900">Class Name (English)</label>
                        <input type="text" id="class-name-en" name="name_en" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                    </div>
                    <div>
                        <label for="class-name-zh" class="block mb-2 text-sm font-medium text-gray-900">Class Name (Chinese)</label>
                        <input type="text" id="class-name-zh" name="name_zh" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                    </div>
                    <div>
                        <label for="day-of-week" class="block mb-2 text-sm font-medium text-gray-900">Day of Week</label>
                        <select id="day-of-week" name="day_of_week" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                            <?php foreach ($days_of_week as $day): ?>
                                <option value="<?php echo htmlspecialchars($day); ?>"><?php echo htmlspecialchars($day); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="start-time" class="block mb-2 text-sm font-medium text-gray-900">Start Time</label>
                            <input type="time" id="start-time" name="start_time" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                        </div>
                        <div>
                            <label for="end-time" class="block mb-2 text-sm font-medium text-gray-900">End Time</label>
                            <input type="time" id="end-time" name="end_time" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                        </div>
                    </div>
                    <div>
                        <label for="coach-id" class="block mb-2 text-sm font-medium text-gray-900">Coach</label>
                        <select id="coach-id" name="coach_id" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5">
                            <option value="">Select Coach</option>
                            <?php foreach ($coaches as $coach): ?>
                                <option value="<?php echo htmlspecialchars($coach['id']); ?>"><?php echo htmlspecialchars(trim($coach['first_name'] . ' ' . $coach['last_name'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="capacity" class="block mb-2 text-sm font-medium text-gray-900">Class Capacity</label>
                        <input type="number" id="capacity" name="capacity" min="1" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                    </div>
                    <div>
                        <label for="age" class="block mb-2 text-sm font-medium text-gray-900">Age Group</label>
                        <select id="age" name="age" class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5" required>
                            <option value="Adult">Adult</option>
                            <option value="Kid">Kid</option>
                        </select>
                    </div>
                    <div class="flex items-center">
                        <input type="checkbox" id="is-active" name="is_active" value="1" class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-300 rounded focus:ring-blue-500">
                        <label for="is-active" class="ml-2 text-sm font-medium text-gray-900">Active Class</label>
                    </div>

                    <div class="flex justify-between space-x-2 mt-6">
                        <button type="submit" class="w-full bg-blue-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-blue-700 transition">
                            Save Class
                        </button>
                        <button type="button" id="delete-class-btn" class="w-full bg-red-600 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-red-700 transition hidden">
                            Delete Class
                        </button>
                    </div>
                </form>
                <div id="class-form-status" class="hidden mt-4 text-center">
                    <p id="class-status-message" class="text-lg font-semibold"></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const classModal = document.getElementById('class-modal');
            const addClassBtn = document.getElementById('add-new-class-btn');
            const closeClassModalBtn = document.getElementById('close-class-modal');
            const classForm = document.getElementById('class-form');
            const modalTitle = document.getElementById('modal-title');
            const classIdInput = document.getElementById('class-id');
            const formActionInput = document.getElementById('form-action');
            const classNameEnInput = document.getElementById('class-name-en');
            const classNameZhInput = document.getElementById('class-name-zh');
            const dayOfWeekInput = document.getElementById('day-of-week');
            const startTimeInput = document.getElementById('start-time');
            const endTimeInput = document.getElementById('end-time');
            const coachIdInput = document.getElementById('coach-id');
            const capacityInput = document.getElementById('capacity');
            const ageInput = document.getElementById('age'); // NEW: Age input
            const isActiveInput = document.getElementById('is-active');
            const deleteClassBtn = document.getElementById('delete-class-btn');
            const classFormStatus = document.getElementById('class-form-status');
            const classStatusMessage = document.getElementById('class-status-message');

            // Open modal for adding new class
            if (addClassBtn) {
                addClassBtn.addEventListener('click', function() {
                    modalTitle.textContent = 'Add New Class';
                    formActionInput.value = 'add';
                    classIdInput.value = '';
                    classForm.reset(); // Clear all fields
                    isActiveInput.checked = true; // Default to active
                    deleteClassBtn.classList.add('hidden'); // Hide delete button for new class
                    classFormStatus.classList.add('hidden'); // Hide status message
                    classForm.classList.remove('hidden'); // Show form
                    classModal.classList.remove('hidden');
                });
            }

            // Open modal for editing existing class
            document.querySelectorAll('.class-card').forEach(card => {
                card.addEventListener('click', function() {
                    modalTitle.textContent = 'Edit Class';
                    formActionInput.value = 'edit';
                    classIdInput.value = this.dataset.classId;
                    classNameEnInput.value = this.dataset.classNameEn;
                    classNameZhInput.value = this.dataset.classNameZh;
                    dayOfWeekInput.value = this.dataset.dayOfWeek;
                    startTimeInput.value = this.dataset.startTime;
                    endTimeInput.value = this.dataset.endTime;
                    coachIdInput.value = this.dataset.coachId;
                    capacityInput.value = this.dataset.capacity;
                    ageInput.value = this.dataset.age; // NEW: Populate age field
                    isActiveInput.checked = (this.dataset.isActive === '1');
                    deleteClassBtn.classList.remove('hidden'); // Show delete button for existing class
                    classFormStatus.classList.add('hidden'); // Hide status message
                    classForm.classList.remove('hidden'); // Show form
                    classModal.classList.remove('hidden');
                });
            });

            // Open modal for adding class in an empty slot
            document.querySelectorAll('.add-class-slot').forEach(slot => {
                slot.addEventListener('click', function() {
                    modalTitle.textContent = 'Add New Class';
                    formActionInput.value = 'add';
                    classIdInput.value = '';
                    classForm.reset(); // Clear all fields
                    dayOfWeekInput.value = this.dataset.dayOfWeek; // Pre-fill day
                    startTimeInput.value = this.dataset.startTime; // Pre-fill start time
                    isActiveInput.checked = true; // Default to active
                    deleteClassBtn.classList.add('hidden'); // Hide delete button
                    classFormStatus.classList.add('hidden'); // Hide status message
                    classForm.classList.remove('hidden'); // Show form
                    classModal.classList.remove('hidden');
                });
            });


            // Close modal
            if (closeClassModalBtn) {
                closeClassModalBtn.addEventListener('click', function() {
                    classModal.classList.add('hidden');
                });
            }
            if (classModal) {
                classModal.addEventListener('click', function(e) {
                    if (e.target === classModal) {
                        classModal.classList.add('hidden');
                    }
                });
            }

            // Handle form submission (Add/Edit)
            if (classForm) {
                classForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const formData = new FormData(classForm);

                    try {
                        const response = await fetch('manage_classes_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();

                        classFormStatus.classList.remove('hidden');
                        if (result.success) {
                            classStatusMessage.textContent = result.message;
                            classStatusMessage.classList.remove('text-red-700');
                            classStatusMessage.classList.add('text-green-700');
                            classForm.classList.add('hidden'); // Hide form on success
                            setTimeout(() => {
                                classModal.classList.add('hidden');
                                window.location.reload(); // Reload page to show updated grid
                            }, 1000);
                        } else {
                            classStatusMessage.textContent = result.message || 'An error occurred.';
                            classStatusMessage.classList.remove('text-green-700');
                            classStatusMessage.classList.add('text-red-700');
                        }
                    } catch (error) {
                        console.error('Error submitting class form:', error);
                        classFormStatus.classList.remove('hidden');
                        classStatusMessage.textContent = 'A network error occurred. Please try again.';
                        classStatusMessage.classList.remove('text-green-700');
                        classStatusMessage.classList.add('text-red-700');
                    }
                });
            }

            // Handle Delete Class
            if (deleteClassBtn) {
                deleteClassBtn.addEventListener('click', async function() {
                    // Replaced alert/confirm with a custom modal or message box in a real application
                    // For this example, keeping confirm() as per original, but note it's not ideal for iframes.
                    if (!confirm('Are you sure you want to delete this class? This action cannot be undone.')) {
                        return;
                    }

                    const classId = classIdInput.value;
                    const formData = new FormData();
                    formData.append('class_id', classId);
                    formData.append('action', 'delete');

                    try {
                        const response = await fetch('manage_classes_handler.php', {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();

                        classFormStatus.classList.remove('hidden');
                        if (result.success) {
                            classStatusMessage.textContent = result.message;
                            classStatusMessage.classList.remove('text-red-700');
                            classStatusMessage.classList.add('text-green-700');
                            classForm.classList.add('hidden'); // Hide form on success
                            setTimeout(() => {
                                classModal.classList.add('hidden');
                                window.location.reload(); // Reload page
                            }, 1000);
                        } else {
                            classStatusMessage.textContent = result.message || 'Failed to delete class.';
                            classStatusMessage.classList.remove('text-green-700');
                            classStatusMessage.classList.add('text-red-700');
                        }
                    } catch (error) {
                        console.error('Error deleting class:', error);
                        classFormStatus.classList.remove('hidden');
                        classStatusMessage.textContent = 'A network error occurred during deletion.';
                        classStatusMessage.classList.remove('text-green-700');
                        classStatusMessage.classList.add('text-red-700');
                    }
                });
            }
        });
    </script>
</body>
</html>
