<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// From /admin/, go up one level to /public_html/ to find db_config.php
require_once "../db_config.php"; 
session_start();

// --- Page Security: ADMIN ONLY ---
// FIX: Changed "loggedin" to "admin_loggedin" for consistency across the admin portal.
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    header("location: ../login.html");
    exit;
}

// --- AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $user_id = $_SESSION['id'];
    $action = $_POST['action'];

    switch ($action) {
        case 'add_or_update_event':
            $event_id = !empty($_POST['event_id']) ? $_POST['event_id'] : null;
            $title = $_POST['title'] ?? '';
            $title_zh = $_POST['title_zh'] ?? '';
            $description = $_POST['description'] ?? '';
            $description_zh = $_POST['description_zh'] ?? '';
            $event_date = $_POST['event_date'] ?? '';
            $event_time = $_POST['event_time'] ?? '';
            $price = $_POST['price'] ?? 0;
            $capacity = $_POST['capacity'] ?? 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $image_url = $_POST['existing_image_url'] ?? null;

            // Handle file upload
            if (isset($_FILES['image_url']) && $_FILES['image_url']['error'] == 0) {
                $target_dir = "../uploads/"; // Go up to public_html, then into uploads
                $image_name = time() . '_' . basename($_FILES["image_url"]["name"]);
                $target_file = $target_dir . $image_name;
                
                $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
                if (in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif'])) {
                    if (move_uploaded_file($_FILES["image_url"]["tmp_name"], $target_file)) {
                        $image_url = $image_name; // Store only the filename
                    }
                }
            }

            if ($event_id) { // Update existing event
                $sql = "UPDATE events SET title=?, title_zh=?, description=?, description_zh=?, event_date=?, event_time=?, price=?, capacity=?, image_url=?, is_active=? WHERE id=?";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "ssssssdissi", $title, $title_zh, $description, $description_zh, $event_date, $event_time, $price, $capacity, $image_url, $is_active, $event_id);
            } else { // Insert new event
                $sql = "INSERT INTO events (title, title_zh, description, description_zh, event_date, event_time, price, capacity, image_url, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($link, $sql);
                mysqli_stmt_bind_param($stmt, "ssssssdisi", $title, $title_zh, $description, $description_zh, $event_date, $event_time, $price, $capacity, $image_url, $is_active);
            }

            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Event saved successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_stmt_error($stmt)]);
            }
            mysqli_stmt_close($stmt);
            break;

        case 'get_event_details':
            $event_id = $_POST['event_id'] ?? 0;
            $sql = "SELECT * FROM events WHERE id = ?";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $event_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $event = mysqli_fetch_assoc($result);
            echo json_encode(['success' => true, 'event' => $event]);
            break;

        case 'delete_event':
            $event_id = $_POST['event_id'] ?? 0;
            mysqli_begin_transaction($link);
            try {
                $sql_regs = "DELETE FROM event_registrations WHERE event_id = ?";
                $stmt_regs = mysqli_prepare($link, $sql_regs);
                mysqli_stmt_bind_param($stmt_regs, "i", $event_id);
                mysqli_stmt_execute($stmt_regs);
                mysqli_stmt_close($stmt_regs);

                $sql_event = "DELETE FROM events WHERE id = ?";
                $stmt_event = mysqli_prepare($link, $sql_event);
                mysqli_stmt_bind_param($stmt_event, "i", $event_id);
                mysqli_stmt_execute($stmt_event);
                mysqli_stmt_close($stmt_event);

                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Event and all associated registrations have been deleted.']);
            } catch (mysqli_sql_exception $exception) {
                mysqli_rollback($link);
                echo json_encode(['success' => false, 'message' => 'Failed to delete event.']);
            }
            break;

        case 'get_registrations':
            $event_id = $_POST['event_id'] ?? 0;
            if (empty($event_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid Event ID.']);
                exit;
            }
            $sql = "SELECT er.id as registration_id, er.payment_status, u.first_name, u.last_name, u.profile_picture_url
                    FROM event_registrations er
                    JOIN users u ON er.user_id = u.id
                    WHERE er.event_id = ?
                    ORDER BY u.first_name";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $event_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $registrations = mysqli_fetch_all($result, MYSQLI_ASSOC);
            echo json_encode(['success' => true, 'registrations' => $registrations]);
            break;

        case 'update_payment_status':
            $registration_id = $_POST['registration_id'] ?? 0;
            if (empty($registration_id)) {
                echo json_encode(['success' => false, 'message' => 'Invalid Registration ID.']);
                exit;
            }
            $sql = "UPDATE event_registrations SET payment_status = 'paid' WHERE id = ?";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, "i", $registration_id);
            if (mysqli_stmt_execute($stmt)) {
                echo json_encode(['success' => true, 'message' => 'Payment status updated.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update payment status.']);
            }
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// Fetch all events for display on page load
$events_sql = "SELECT e.*, (SELECT COUNT(*) FROM event_registrations WHERE event_id = e.id) as registration_count FROM events e ORDER BY e.event_date DESC";
$all_events = mysqli_fetch_all(mysqli_query($link, $events_sql), MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Seminars & Events - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .form-label { font-weight: 600; color: #374151; }
        .form-input {
            width: 100%; padding: 0.75rem; border-radius: 0.5rem;
            border: 1px solid #d1d5db; background-color: #f9fafb;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-input:focus {
            outline: none; border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
        }
        .modal { transition: opacity 0.25s ease; }
    </style>
</head>
<body class="text-gray-800">
    <div class="container mx-auto px-4 sm:px-6 py-12">
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Manage Seminars & Events</h1>
                <p class="text-gray-500 mt-1">Add, edit, or remove events from this panel.</p>
            </div>
            <a href="admin_dashboard.php" class="bg-gray-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-gray-700 transition">
                &larr; Back to Dashboard
            </a>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Form Column -->
            <div class="lg:col-span-1">
                <div class="bg-white p-6 rounded-xl shadow-md sticky top-8">
                    <h2 id="form-title" class="text-2xl font-bold mb-6">Add New Event</h2>
                    <form id="event-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_or_update_event">
                        <input type="hidden" name="event_id" id="event_id_input">
                        <input type="hidden" name="existing_image_url" id="existing_image_url_input">
                        
                        <div class="space-y-4">
                            <div>
                                <label for="title" class="form-label">Title (EN)</label>
                                <input type="text" name="title" id="title" class="form-input" required>
                            </div>
                            <div>
                                <label for="title_zh" class="form-label">Title (ZH)</label>
                                <input type="text" name="title_zh" id="title_zh" class="form-input">
                            </div>
                            <div>
                                <label for="description" class="form-label">Description (EN)</label>
                                <textarea name="description" id="description" rows="3" class="form-input"></textarea>
                            </div>
                             <div>
                                <label for="description_zh" class="form-label">Description (ZH)</label>
                                <textarea name="description_zh" id="description_zh" rows="3" class="form-input"></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="event_date" class="form-label">Date</label>
                                    <input type="date" name="event_date" id="event_date" class="form-input" required>
                                </div>
                                <div>
                                    <label for="event_time" class="form-label">Time</label>
                                    <input type="time" name="event_time" id="event_time" class="form-input" required>
                                </div>
                            </div>
                             <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="price" class="form-label">Price</label>
                                    <input type="number" name="price" id="price" class="form-input" step="0.01" required>
                                </div>
                                <div>
                                    <label for="capacity" class="form-label">Capacity</label>
                                    <input type="number" name="capacity" id="capacity" class="form-input" required>
                                </div>
                            </div>
                            <div>
                                <label for="image_url" class="form-label">Event Image</label>
                                <input type="file" name="image_url" id="image_url" class="form-input" accept="image/png, image/jpeg, image/gif">
                                <img id="image-preview" src="" alt="Image Preview" class="mt-2 rounded-lg w-full hidden">
                            </div>
                             <div class="flex items-center">
                                <input type="checkbox" name="is_active" id="is_active" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500" checked>
                                <label for="is_active" class="ml-2 block text-sm font-medium text-gray-700">Event is Active</label>
                            </div>
                        </div>
                        <div class="mt-6 flex items-center gap-4">
                            <button type="submit" class="w-full bg-blue-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-blue-700 transition">Save Event</button>
                            <button type="button" id="clear-form-btn" class="w-full bg-gray-200 text-gray-700 font-bold py-3 px-4 rounded-lg hover:bg-gray-300 transition">Clear</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- List Column -->
            <div class="lg:col-span-2">
                <div id="events-list" class="space-y-4">
                    <?php foreach ($all_events as $event): ?>
                        <div class="bg-white p-4 rounded-lg shadow-sm flex items-center gap-4">
                            <img src="../uploads/<?php echo htmlspecialchars($event['image_url'] ?? ''); ?>" alt="Event" class="w-20 h-20 rounded-md object-cover" onerror="this.src='https://placehold.co/80x80/e2e8f0/475569?text=Event';">
                            <div class="flex-grow">
                                <div class="flex items-center gap-2">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $event['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $event['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                    <h3 class="font-bold text-lg"><?php echo htmlspecialchars($event['title']); ?></h3>
                                </div>
                                <p class="text-sm text-gray-500"><?php echo date("D, M j, Y", strtotime($event['event_date'])); ?> at <?php echo date("g:i A", strtotime($event['event_time'])); ?></p>
                                <p class="text-sm font-semibold text-blue-600 mt-1">Registrations: <?php echo $event['registration_count']; ?> / <?php echo $event['capacity']; ?></p>
                            </div>
                            <div class="flex-shrink-0 flex gap-2">
                                <button class="view-regs-btn bg-blue-100 text-blue-700 font-bold p-2 rounded-lg hover:bg-blue-200" data-event-id="<?php echo $event['id']; ?>" data-event-title="<?php echo htmlspecialchars($event['title']); ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </button>
                                <button class="edit-btn bg-gray-200 text-gray-700 font-bold p-2 rounded-lg hover:bg-gray-300" data-event-id="<?php echo $event['id']; ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                </button>
                                <button class="delete-btn bg-red-100 text-red-700 font-bold p-2 rounded-lg hover:bg-red-200" data-event-id="<?php echo $event['id']; ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Registrations Modal -->
    <div id="registrations-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 h-full w-full hidden modal overflow-y-auto pt-10">
        <div class="relative mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-xl bg-white mb-10">
            <div class="flex justify-between items-center pb-3 border-b">
                <h2 id="registrations-modal-title" class="text-2xl font-bold">Registrations</h2>
                <button id="close-regs-modal" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
            </div>
            <div id="registrations-list" class="mt-4 space-y-3 max-h-[60vh] overflow-y-auto">
                <!-- Registrations will be loaded here -->
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const eventForm = document.getElementById('event-form');
        const formTitle = document.getElementById('form-title');
        const eventIdInput = document.getElementById('event_id_input');
        const clearFormBtn = document.getElementById('clear-form-btn');
        const eventsList = document.getElementById('events-list');
        const imagePreview = document.getElementById('image-preview');
        const imageUrlInput = document.getElementById('image_url');
        const existingImageUrlInput = document.getElementById('existing_image_url_input');
        const regsModal = document.getElementById('registrations-modal');
        const closeRegsModalBtn = document.getElementById('close-regs-modal');
        const regsListContainer = document.getElementById('registrations-list');
        const regsModalTitle = document.getElementById('registrations-modal-title');

        function resetForm() {
            eventForm.reset();
            eventIdInput.value = '';
            existingImageUrlInput.value = '';
            formTitle.textContent = 'Add New Event';
            imagePreview.classList.add('hidden');
        }

        clearFormBtn.addEventListener('click', resetForm);

        imageUrlInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    imagePreview.classList.remove('hidden');
                }
                reader.readAsDataURL(this.files[0]);
            }
        });

        eventsList.addEventListener('click', async function(e) {
            const editBtn = e.target.closest('.edit-btn');
            const deleteBtn = e.target.closest('.delete-btn');
            const viewBtn = e.target.closest('.view-regs-btn');

            if (editBtn) {
                const eventId = editBtn.dataset.eventId;
                const formData = new FormData();
                formData.append('action', 'get_event_details');
                formData.append('event_id', eventId);

                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success && result.event) {
                    const event = result.event;
                    formTitle.textContent = 'Edit Event';
                    eventIdInput.value = event.id;
                    document.getElementById('title').value = event.title;
                    document.getElementById('title_zh').value = event.title_zh;
                    document.getElementById('description').value = event.description;
                    document.getElementById('description_zh').value = event.description_zh;
                    document.getElementById('event_date').value = event.event_date;
                    document.getElementById('event_time').value = event.event_time;
                    document.getElementById('price').value = event.price;
                    document.getElementById('capacity').value = event.capacity;
                    document.getElementById('is_active').checked = event.is_active == 1;
                    
                    if (event.image_url) {
                        imagePreview.src = `../uploads/${event.image_url}`;
                        imagePreview.classList.remove('hidden');
                        existingImageUrlInput.value = event.image_url;
                    } else {
                        imagePreview.classList.add('hidden');
                        existingImageUrlInput.value = '';
                    }
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            }

            if (deleteBtn) {
                const eventId = deleteBtn.dataset.eventId;
                if (confirm('Are you sure you want to delete this event? This will also remove all registrations and cannot be undone.')) {
                    const formData = new FormData();
                    formData.append('action', 'delete_event');
                    formData.append('event_id', eventId);
                    const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                    const result = await response.json();
                    alert(result.message);
                    if (result.success) {
                        window.location.reload();
                    }
                }
            }

            if (viewBtn) {
                const eventId = viewBtn.dataset.eventId;
                regsModalTitle.textContent = `Registrations for: ${viewBtn.dataset.eventTitle}`;
                regsListContainer.innerHTML = '<p>Loading participants...</p>';
                regsModal.classList.remove('hidden');

                const formData = new FormData();
                formData.append('action', 'get_registrations');
                formData.append('event_id', eventId);

                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    renderRegistrations(result.registrations);
                } else {
                    regsListContainer.innerHTML = `<p class="text-red-500">${result.message}</p>`;
                }
            }
        });

        function renderRegistrations(registrations) {
            regsListContainer.innerHTML = '';
            if (registrations.length === 0) {
                regsListContainer.innerHTML = '<p>No members have registered for this event yet.</p>';
                return;
            }
            registrations.forEach(reg => {
                const profilePic = reg.profile_picture_url ? `../uploads/${reg.profile_picture_url.replace('uploads/','')}` : 'https://placehold.co/40x40/e2e8f0/475569?text=:)';
                const isPaid = reg.payment_status === 'paid';
                const item = document.createElement('div');
                item.className = 'flex items-center justify-between p-3 bg-gray-50 rounded-lg';
                item.innerHTML = `
                    <div class="flex items-center gap-3">
                        <img src="${profilePic}" class="w-10 h-10 rounded-full object-cover">
                        <span class="font-semibold">${reg.first_name} ${reg.last_name}</span>
                    </div>
                    <button class="mark-paid-btn text-sm font-bold py-1 px-3 rounded-full ${isPaid ? 'bg-green-200 text-green-800 cursor-not-allowed' : 'bg-blue-200 text-blue-800 hover:bg-blue-300'}" 
                                data-registration-id="${reg.registration_id}" ${isPaid ? 'disabled' : ''}>
                        ${isPaid ? 'Paid' : 'Mark as Paid'}
                    </button>
                `;
                regsListContainer.appendChild(item);
            });
        }

        closeRegsModalBtn.addEventListener('click', () => regsModal.classList.add('hidden'));

        regsListContainer.addEventListener('click', async function(e) {
            const paidBtn = e.target.closest('.mark-paid-btn');
            if (paidBtn && !paidBtn.disabled) {
                const regId = paidBtn.dataset.registrationId;
                paidBtn.disabled = true;
                paidBtn.textContent = 'Updating...';

                const formData = new FormData();
                formData.append('action', 'update_payment_status');
                formData.append('registration_id', regId);

                const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
                const result = await response.json();

                if (result.success) {
                    paidBtn.textContent = 'Paid';
                    paidBtn.classList.remove('bg-blue-200', 'text-blue-800', 'hover:bg-blue-300');
                    paidBtn.classList.add('bg-green-200', 'text-green-800', 'cursor-not-allowed');
                } else {
                    alert(result.message);
                    paidBtn.disabled = false;
                    paidBtn.textContent = 'Mark as Paid';
                }
            }
        });

        eventForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const imageFile = imageUrlInput.files[0];
            if (imageFile && imageFile.size > 5 * 1024 * 1024) { // 5 MB limit
                alert('The selected image is too large. Please choose a file smaller than 5MB.');
                return;
            }

            const formData = new FormData(eventForm);
            const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
            const result = await response.json();
            
            alert(result.message);
            if (result.success) {
                window.location.reload();
            }
        });
    });
    </script>
</body>
</html>
