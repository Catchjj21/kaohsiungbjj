<?php
// Include the database config FIRST.
require_once "../db_config.php";

// Now that the settings are loaded, start the session.
session_start();

// Check if the user is logged in and is an admin or coach.
if(!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || !in_array($_SESSION["role"], ['admin', 'coach'])){
    // Match dashboard logic: redirect to ../admin_login.html
    header("location: ../admin_login.html");
    exit;
}

$user_id = $_SESSION['id'];

// --- START AJAX HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    switch ($action) {
        case 'upload_image':
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
                $filename = $_FILES['image']['name'];
                $filetype = $_FILES['image']['type'];
                $filesize = $_FILES['image']['size'];

                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                if (!array_key_exists($ext, $allowed) || !in_array($filetype, $allowed)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
                    exit;
                }

                if ($filesize > 5 * 1024 * 1024) { // 5 MB limit
                    echo json_encode(['success' => false, 'message' => 'File size is too large.']);
                    exit;
                }

                $upload_dir = '../uploads/message_images/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = uniqid('msg_img_', true) . '.' . $ext;
                $destination = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    // Return URL relative to the main project directory, not the admin folder
                    echo json_encode(['success' => true, 'url' => 'uploads/message_images/' . $new_filename]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'No file uploaded or an error occurred.']);
            }
            break;

        case 'send_new_message':
            $subject = $_POST['subject'] ?? '';
            $body = $_POST['body'] ?? '';
            $recipient_roles = $_POST['recipient_roles'] ?? [];
            $recipient_ids = $_POST['recipient_ids'] ?? [];

            if (empty($subject) || empty($body) || (empty($recipient_roles) && empty($recipient_ids))) {
                echo json_encode(['success' => false, 'message' => 'Subject, message, and at least one recipient are required.']);
                exit;
            }

            $final_recipient_ids = $recipient_ids;
            if (!empty($recipient_roles)) {
                $placeholders = implode(',', array_fill(0, count($recipient_roles), '?'));
                $sql_roles = "SELECT id FROM users WHERE role IN ($placeholders)";
                $stmt_roles = mysqli_prepare($link, $sql_roles);
                mysqli_stmt_bind_param($stmt_roles, str_repeat('s', count($recipient_roles)), ...$recipient_roles);
                mysqli_stmt_execute($stmt_roles);
                $result = mysqli_stmt_get_result($stmt_roles);
                while ($row = mysqli_fetch_assoc($result)) {
                    $final_recipient_ids[] = $row['id'];
                }
                mysqli_stmt_close($stmt_roles);
            }

            $unique_recipient_ids = array_unique($final_recipient_ids);
            
            if (empty($unique_recipient_ids)) {
                echo json_encode(['success' => false, 'message' => 'No valid recipients found.']);
                exit;
            }

            mysqli_begin_transaction($link);
            try {
                // Insert the message, thread_id is NULL for now
                $sql_insert_msg = "INSERT INTO messages (sender_id, subject, body) VALUES (?, ?, ?)";
                $stmt_insert_msg = mysqli_prepare($link, $sql_insert_msg);
                mysqli_stmt_bind_param($stmt_insert_msg, "iss", $user_id, $subject, $body);
                mysqli_stmt_execute($stmt_insert_msg);
                $new_message_id = mysqli_insert_id($link);
                mysqli_stmt_close($stmt_insert_msg);

                // Update the message to set its thread_id to its own id, starting a new thread
                $sql_update_thread = "UPDATE messages SET thread_id = ? WHERE id = ?";
                $stmt_update_thread = mysqli_prepare($link, $sql_update_thread);
                mysqli_stmt_bind_param($stmt_update_thread, "ii", $new_message_id, $new_message_id);
                mysqli_stmt_execute($stmt_update_thread);
                mysqli_stmt_close($stmt_update_thread);

                // Insert recipients
                $sql_insert_recipient = "INSERT INTO message_recipients (message_id, recipient_id) VALUES (?, ?)";
                $stmt_insert_recipient = mysqli_prepare($link, $sql_insert_recipient);
                foreach ($unique_recipient_ids as $recipient_id) {
                    // Don't send a message to oneself in a new thread
                    if ($recipient_id != $user_id) {
                        mysqli_stmt_bind_param($stmt_insert_recipient, "ii", $new_message_id, $recipient_id);
                        mysqli_stmt_execute($stmt_insert_recipient);
                    }
                }
                mysqli_stmt_close($stmt_insert_recipient);

                mysqli_commit($link);
                echo json_encode(['success' => true, 'message' => 'Message sent successfully!']);
            } catch (Exception $e) {
                mysqli_rollback($link);
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            break;

        case 'reply_message':
            $original_recipient_id = $_POST['original_recipient_id'] ?? 0;
            $reply_body = $_POST['body'] ?? '';

            if (empty($reply_body) || $original_recipient_id == 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
                exit;
            }

            mysqli_begin_transaction($link);
            try {
                // Get info about the original message to determine the recipient and thread
                $sql_original = "SELECT m.sender_id, m.subject, COALESCE(m.thread_id, m.id) as thread_id
                                 FROM message_recipients mr
                                 JOIN messages m ON mr.message_id = m.id
                                 WHERE mr.id = ? AND mr.recipient_id = ?";
                $stmt_original = mysqli_prepare($link, $sql_original);
                mysqli_stmt_bind_param($stmt_original, "ii", $original_recipient_id, $user_id);
                mysqli_stmt_execute($stmt_original);
                $original_message = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_original));
                mysqli_stmt_close($stmt_original);

                if (!$original_message) {
                    throw new Exception("Original message not found or permission denied.");
                }

                $recipient_user_id = $original_message['sender_id'];
                $thread_id = $original_message['thread_id'];
                $new_subject = (strpos($original_message['subject'], 'Re: ') === 0) ? $original_message['subject'] : 'Re: ' . $original_message['subject'];

                // Insert new message
                $sql_insert_msg = "INSERT INTO messages (sender_id, subject, body, thread_id) VALUES (?, ?, ?, ?)";
                $stmt_insert_msg = mysqli_prepare($link, $sql_insert_msg);
                mysqli_stmt_bind_param($stmt_insert_msg, "issi", $user_id, $new_subject, $reply_body, $thread_id);
                mysqli_stmt_execute($stmt_insert_msg);
                $new_message_id = mysqli_insert_id($link);
                mysqli_stmt_close($stmt_insert_msg);

                // Create recipient record
                $sql_insert_recipient = "INSERT INTO message_recipients (message_id, recipient_id) VALUES (?, ?)";
                $stmt_insert_recipient = mysqli_prepare($link, $sql_insert_recipient);
                mysqli_stmt_bind_param($stmt_insert_recipient, "ii", $new_message_id, $recipient_user_id);
                mysqli_stmt_execute($stmt_insert_recipient);
                mysqli_stmt_close($stmt_insert_recipient);

                mysqli_commit($link);

                // Fetch the new message to send back to client
                $sql_new_msg = "SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) as sender_name, u.profile_picture_url FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.id = ?";
                $stmt_new = mysqli_prepare($link, $sql_new_msg);
                mysqli_stmt_bind_param($stmt_new, "i", $new_message_id);
                mysqli_stmt_execute($stmt_new);
                $new_message_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_new));
                mysqli_stmt_close($stmt_new);

                echo json_encode(['success' => true, 'message' => 'Reply sent!', 'newMessage' => $new_message_data]);

            } catch (Exception $e) {
                mysqli_rollback($link);
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            break;

        case 'get_message_thread':
            $recipient_id = $_POST['recipient_id'] ?? 0;
            $sql_thread_id = "SELECT COALESCE(m.thread_id, m.id) as thread_id FROM message_recipients mr JOIN messages m ON mr.message_id = m.id WHERE mr.id = ? AND mr.recipient_id = ?";
            $stmt_thread_id = mysqli_prepare($link, $sql_thread_id);
            mysqli_stmt_bind_param($stmt_thread_id, "ii", $recipient_id, $user_id);
            mysqli_stmt_execute($stmt_thread_id);
            $thread_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_thread_id));
            mysqli_stmt_close($stmt_thread_id);

            if (!$thread_info) {
                echo json_encode(['success' => false, 'message' => 'Thread not found.']);
                exit;
            }
            $thread_id = $thread_info['thread_id'];

            $sql_thread = "SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) as sender_name, u.profile_picture_url FROM messages m JOIN users u ON m.sender_id = u.id WHERE (m.id = ? OR m.thread_id = ?) ORDER BY m.created_at ASC";
            $stmt_thread = mysqli_prepare($link, $sql_thread);
            mysqli_stmt_bind_param($stmt_thread, "ii", $thread_id, $thread_id);
            mysqli_stmt_execute($stmt_thread);
            $thread_messages = mysqli_fetch_all(mysqli_stmt_get_result($stmt_thread), MYSQLI_ASSOC);
            mysqli_stmt_close($stmt_thread);

            echo json_encode(['success' => true, 'thread' => $thread_messages]);
            break;

        case 'mark_as_read':
            $recipient_id = $_POST['recipient_id'] ?? 0;
            if ($recipient_id > 0) {
                $sql_thread_info = "SELECT COALESCE(m.thread_id, m.id) as thread_id FROM message_recipients mr JOIN messages m ON mr.message_id = m.id WHERE mr.id = ? AND mr.recipient_id = ?";
                $stmt_info = mysqli_prepare($link, $sql_thread_info);
                mysqli_stmt_bind_param($stmt_info, "ii", $recipient_id, $user_id);
                mysqli_stmt_execute($stmt_info);
                $thread_info = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_info));
                mysqli_stmt_close($stmt_info);

                if ($thread_info) {
                    $thread_id = $thread_info['thread_id'];
                    $sql_update = "UPDATE message_recipients mr JOIN messages m ON mr.message_id = m.id SET mr.is_read = 1, mr.read_at = NOW() WHERE mr.recipient_id = ? AND (m.id = ? OR m.thread_id = ?) AND mr.is_read = 0";
                    if ($stmt_update = mysqli_prepare($link, $sql_update)) {
                        mysqli_stmt_bind_param($stmt_update, "iii", $user_id, $thread_id, $thread_id);
                        mysqli_stmt_execute($stmt_update);
                        $affected_rows = mysqli_stmt_affected_rows($stmt_update);
                        mysqli_stmt_close($stmt_update);
                        echo json_encode(['success' => true, 'marked_read_count' => $affected_rows]);
                    }
                }
            }
            break;
    }
    mysqli_close($link);
    exit;
}
// --- END AJAX HANDLER ---

// --- Fetch Admin's Inbox Conversations ---
$messages = [];
$thread_ids = [];
$sql_threads = "SELECT DISTINCT COALESCE(thread_id, id) as thread_id FROM messages m WHERE sender_id = ? OR EXISTS (SELECT 1 FROM message_recipients mr WHERE mr.message_id = m.id AND mr.recipient_id = ?)";
if($stmt_threads = mysqli_prepare($link, $sql_threads)){
    mysqli_stmt_bind_param($stmt_threads, "ii", $user_id, $user_id);
    mysqli_stmt_execute($stmt_threads);
    $result_threads = mysqli_stmt_get_result($stmt_threads);
    while($row = mysqli_fetch_assoc($result_threads)){ $thread_ids[] = $row['thread_id']; }
    mysqli_stmt_close($stmt_threads);
}

if (!empty($thread_ids)) {
    $sql_latest_msg = "
        SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) as sender_name,
            (SELECT mr.id FROM message_recipients mr WHERE mr.message_id = m.id AND mr.recipient_id = ? LIMIT 1) as recipient_id,
            (SELECT COUNT(*) FROM message_recipients mr_u JOIN messages m_u ON mr_u.message_id = m_u.id WHERE mr_u.recipient_id = ? AND mr_u.is_read = 0 AND COALESCE(m_u.thread_id, m_u.id) = ?) as unread_in_thread
        FROM messages m JOIN users u ON m.sender_id = u.id
        WHERE (m.id = ? OR m.thread_id = ?) ORDER BY m.created_at DESC LIMIT 1";
    if($stmt_latest = mysqli_prepare($link, $sql_latest_msg)){
        foreach($thread_ids as $tid){
            mysqli_stmt_bind_param($stmt_latest, "iiiii", $user_id, $user_id, $tid, $tid, $tid);
            mysqli_stmt_execute($stmt_latest);
            $result_latest = mysqli_stmt_get_result($stmt_latest);
            if($row = mysqli_fetch_assoc($result_latest)){
                if(is_null($row['recipient_id'])){
                    $sql_find_recipient_id = "SELECT mr.id FROM message_recipients mr JOIN messages m ON mr.message_id = m.id WHERE mr.recipient_id = ? AND (m.id = ? OR m.thread_id = ?) LIMIT 1";
                    $stmt_find_id = mysqli_prepare($link, $sql_find_recipient_id);
                    mysqli_stmt_bind_param($stmt_find_id, "iii", $user_id, $tid, $tid);
                    mysqli_stmt_execute($stmt_find_id);
                    $row['recipient_id'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt_find_id))['id'] ?? 0;
                    mysqli_stmt_close($stmt_find_id);
                }
                $messages[] = $row;
            }
        }
        mysqli_stmt_close($stmt_latest);
    }
}
usort($messages, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
$unread_count = array_sum(array_column($messages, 'unread_in_thread'));

// --- Fetch all potential message recipients for compose modal ---
$all_recipients = [];
$sql_recipients = "SELECT id, first_name, last_name, role FROM users WHERE role IN ('member', 'coach', 'parent') ORDER BY first_name, last_name";
if ($result_recipients = mysqli_query($link, $sql_recipients)) {
    while ($row = mysqli_fetch_assoc($result_recipients)) {
        $all_recipients[] = $row;
    }
}

mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messaging - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <!-- Quill.js styles -->
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <!-- Tom Select styles -->
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/css/tom-select.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        #message-viewer-content { display: flex; flex-direction: column; height: 100%; }
        .prose img { max-width: 100%; height: auto; border-radius: 0.5rem; }
        .ts-control { border-radius: 0.375rem !important; border: 1px solid #d1d5db !important; padding: 0.5rem 0.75rem !important; }
        .ts-dropdown { border-radius: 0.375rem !important; }
        .ql-editor { min-height: 150px; }
    </style>
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-md">
        <div class="container mx-auto px-6 py-4 flex justify-between items-center">
            <span class="font-bold text-xl text-gray-800">Admin Portal</span>
            <div>
                <a href="admin_dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium mr-4">Dashboard</a>
                <a href="../logout.php" class="bg-purple-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-purple-700 transition">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 sm:px-6 py-12">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Messaging</h1>
            <button id="compose-btn" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" /></svg>
                Compose Message
            </button>
        </div>
        <div class="bg-white rounded-2xl shadow-lg overflow-hidden">
            <div class="flex h-[75vh]">
                <div id="message-list-container" class="w-full md:w-1/3 border-r border-gray-200 flex flex-col">
                    <div id="message-list" class="overflow-y-auto flex-grow">
                        <?php if (empty($messages)): ?>
                            <p class="p-4 text-center text-gray-500">Your inbox is empty.</p>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message-item p-4 border-b hover:bg-gray-50 cursor-pointer <?php echo $message['unread_in_thread'] > 0 ? 'bg-blue-50' : ''; ?>"
                                     data-recipient-id="<?php echo $message['recipient_id']; ?>"
                                     data-has-unread="<?php echo $message['unread_in_thread'] > 0 ? '1' : '0'; ?>">
                                    <div class="flex justify-between items-start">
                                        <div class="flex-grow overflow-hidden">
                                            <p class="text-sm <?php echo $message['unread_in_thread'] > 0 ? 'font-bold text-gray-900' : 'font-medium text-gray-600'; ?>"><?php echo htmlspecialchars($message['sender_name']); ?></p>
                                            <p class="truncate <?php echo $message['unread_in_thread'] > 0 ? 'font-semibold text-gray-800' : 'text-gray-500'; ?>"><?php echo htmlspecialchars($message['subject']); ?></p>
                                        </div>
                                        <span class="text-xs text-gray-400 flex-shrink-0 ml-2"><?php echo date('M d', strtotime($message['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="message-viewer" class="w-full md:w-2/3 hidden md:flex flex-col">
                    <div id="viewer-header" class="p-4 border-b md:hidden flex items-center gap-4">
                         <button id="back-to-list-btn" class="p-2 text-gray-600 hover:bg-gray-100 rounded-full">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                         </button>
                         <h3 class="font-bold text-lg">Conversation</h3>
                    </div>
                    <div id="message-viewer-placeholder" class="flex-grow flex items-center justify-center">
                        <p class="text-gray-400">Select a conversation to read</p>
                    </div>
                    <div id="message-viewer-content" class="hidden h-full"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Compose Modal -->
    <div id="compose-modal" class="fixed inset-0 bg-gray-600 bg-opacity-75 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-10 mx-auto p-5 border w-full max-w-3xl shadow-lg rounded-md bg-white">
            <form id="compose-form">
                <div class="flex justify-between items-center pb-3 border-b">
                    <p class="text-2xl font-bold">New Message</p>
                    <button id="close-compose-modal" type="button" class="text-gray-700 text-3xl leading-none hover:text-black">&times;</button>
                </div>
                <div class="mt-4 space-y-4">
                    <div>
                        <label for="subject" class="block text-sm font-medium text-gray-700">Subject</label>
                        <input type="text" name="subject" id="subject" required class="mt-1 block w-full px-3 py-2 bg-white border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Message</label>
                        <div id="compose-editor" class="mt-1 bg-white border border-gray-300 rounded-md"></div>
                        <input type="hidden" name="body" id="compose-body-hidden">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Send To:</label>
                        <div class="mt-2 space-y-2">
                            <p class="text-xs text-gray-500">Select entire groups...</p>
                            <div class="flex flex-wrap gap-x-6 gap-y-2">
                                <label class="inline-flex items-center"><input type="checkbox" name="recipient_roles[]" value="member" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"> <span class="ml-2">All Members</span></label>
                                <label class="inline-flex items-center"><input type="checkbox" name="recipient_roles[]" value="coach" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"> <span class="ml-2">All Coaches</span></label>
                                <label class="inline-flex items-center"><input type="checkbox" name="recipient_roles[]" value="parent" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"> <span class="ml-2">All Parents</span></label>
                            </div>
                        </div>
                        <div class="mt-4">
                            <p class="text-xs text-gray-500">...or add specific individuals.</p>
                            <select id="individual-recipients" name="recipient_ids[]" multiple>
                                <?php foreach($all_recipients as $recipient): ?>
                                    <option value="<?php echo $recipient['id']; ?>">
                                        <?php echo htmlspecialchars($recipient['first_name'] . ' ' . $recipient['last_name'] . ' (' . ucfirst($recipient['role']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mt-6 text-right">
                    <button type="submit" class="bg-indigo-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-indigo-700 transition">Send Message</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Quill.js & Tom Select scripts -->
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const messageListContainer = document.getElementById('message-list-container');
        const messageViewer = document.getElementById('message-viewer');
        const messageList = document.getElementById('message-list');
        const backToListBtn = document.getElementById('back-to-list-btn');
        const messageViewerPlaceholder = document.getElementById('message-viewer-placeholder');
        const messageViewerContent = document.getElementById('message-viewer-content');
        const composeBtn = document.getElementById('compose-btn');
        const composeModal = document.getElementById('compose-modal');
        const closeComposeModalBtn = document.getElementById('close-compose-modal');
        const composeForm = document.getElementById('compose-form');
        const currentUserId = <?php echo $_SESSION['id']; ?>;
        
        let replyQuill = null; // To hold the instance of the reply editor

        const quillToolbarOptions = [
            [{ 'header': [1, 2, 3, false] }],
            ['bold', 'italic', 'underline'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            ['link', 'image'],
            ['clean']
        ];

        // --- Reusable Image Handler for Quill ---
        function imageHandler() {
            const input = document.createElement('input');
            input.setAttribute('type', 'file');
            input.setAttribute('accept', 'image/*');
            input.click();
            input.onchange = async () => {
                const file = input.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('action', 'upload_image');
                formData.append('image', file);

                // Get the quill instance that triggered this handler
                const quillInstance = this.quill;

                try {
                    const response = await fetch('admin_messaging.php', { method: 'POST', body: formData });
                    const result = await response.json();
                    
                    if (result.success && result.url) {
                        const range = quillInstance.getSelection(true);
                        // Prepend '../' because the URL is relative to the root, but the editor is in /admin/
                        quillInstance.insertEmbed(range.index, 'image', '../' + result.url);
                    } else {
                        alert('Image upload failed: ' + result.message);
                    }
                } catch (error) {
                    alert('Network error during image upload.');
                }
            };
        }

        // --- Compose Modal Logic ---
        const composeQuill = new Quill('#compose-editor', { 
            theme: 'snow',
            modules: { toolbar: quillToolbarOptions }
        });
        composeQuill.getModule('toolbar').addHandler('image', imageHandler);

        const tomSelect = new TomSelect('#individual-recipients', {
            plugins: ['remove_button'],
            placeholder: 'Search for a user...'
        });

        composeBtn.addEventListener('click', () => composeModal.classList.remove('hidden'));
        closeComposeModalBtn.addEventListener('click', () => composeModal.classList.add('hidden'));

        composeForm.addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('compose-body-hidden').value = composeQuill.root.innerHTML;
            const formData = new FormData(composeForm);
            formData.append('action', 'send_new_message');
            
            const submitButton = composeForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Sending...';

            fetch('admin_messaging.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    composeModal.classList.add('hidden');
                    alert(data.message);
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            }).catch(() => alert('An error occurred.'))
            .finally(() => {
                submitButton.disabled = false;
                submitButton.textContent = 'Send Message';
            });
        });

        // --- Inbox Viewing Logic ---
        messageList.addEventListener('click', (e) => {
            const item = e.target.closest('.message-item');
            if (!item) return;

            const recipientId = item.dataset.recipientId;
            const hasUnread = item.dataset.hasUnread === '1';

            if (window.innerWidth < 768) {
                messageListContainer.classList.add('hidden');
                messageViewer.classList.remove('hidden');
                messageViewer.classList.add('flex');
            }

            const formData = new FormData();
            formData.append('action', 'get_message_thread');
            formData.append('recipient_id', recipientId);

            fetch('admin_messaging.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    renderThread(data.thread, recipientId);
                    if (hasUnread) markThreadAsRead(item, recipientId);
                } else { alert(data.message); }
            });
        });

        backToListBtn.addEventListener('click', () => {
            messageViewer.classList.add('hidden');
            messageViewer.classList.remove('flex');
            messageListContainer.classList.remove('hidden');
        });

        function renderThread(threadMessages, originalRecipientId) {
            let threadHtml = '<div class="flex-grow overflow-y-auto p-4 space-y-4">';
            threadMessages.forEach(msg => {
                threadHtml += createMessageHtml(msg);
            });
            threadHtml += '</div>';
            threadHtml += `
                <div class="p-4 border-t bg-gray-50 flex-shrink-0">
                    <form id="reply-form">
                        <input type="hidden" name="original_recipient_id" value="${originalRecipientId}">
                        <div id="reply-editor" class="bg-white border border-gray-300 rounded-md"></div>
                        <input type="hidden" name="body" id="reply-body-hidden">
                        <div class="text-right mt-2">
                            <button type="submit" class="bg-blue-600 text-white font-bold py-2 px-6 rounded-lg hover:bg-blue-700 transition">Send Reply</button>
                        </div>
                    </form>
                </div>`;

            messageViewerContent.innerHTML = threadHtml;
            messageViewerPlaceholder.classList.add('hidden');
            messageViewerContent.classList.remove('hidden', 'flex');
            messageViewerContent.classList.add('flex');
            
            // Initialize Quill for the new reply editor
            replyQuill = new Quill('#reply-editor', {
                theme: 'snow',
                modules: { toolbar: quillToolbarOptions }
            });
            replyQuill.getModule('toolbar').addHandler('image', imageHandler);
            
            const threadContainer = messageViewerContent.querySelector('.overflow-y-auto');
            if(threadContainer) threadContainer.scrollTop = threadContainer.scrollHeight;

            document.getElementById('reply-form').addEventListener('submit', handleReplySubmit);
        }

        function createMessageHtml(msg) {
            const isCurrentUser = msg.sender_id == currentUserId;
            const profilePic = msg.profile_picture_url ? '../' + msg.profile_picture_url : `https://placehold.co/40x40/e2e8f0/333333?text=${msg.sender_name.charAt(0)}`;
            const formattedDate = new Date(msg.created_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true });
            return `
                <div class="flex items-start gap-3 ${isCurrentUser ? 'flex-row-reverse' : ''}">
                    <img src="${profilePic}" class="w-10 h-10 rounded-full object-cover">
                    <div class="max-w-xs md:max-w-md">
                        <div class="p-3 rounded-lg ${isCurrentUser ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800'}">
                            <div class="prose prose-sm max-w-none ${isCurrentUser ? 'text-white' : 'text-gray-800'}">${msg.body}</div>
                        </div>
                        <div class="text-xs text-gray-400 mt-1 px-1 ${isCurrentUser ? 'text-right' : ''}">${msg.sender_name}, ${formattedDate}</div>
                    </div>
                </div>`;
        }

        function handleReplySubmit(e) {
            e.preventDefault();
            const form = e.target;
            document.getElementById('reply-body-hidden').value = replyQuill.root.innerHTML;

            const formData = new FormData(form);
            formData.append('action', 'reply_message');
            
            const submitButton = form.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.textContent = 'Sending...';

            fetch('admin_messaging.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                submitButton.disabled = false;
                submitButton.textContent = 'Send Reply';
                if (data.success && data.newMessage) {
                    const threadContainer = messageViewerContent.querySelector('.overflow-y-auto');
                    threadContainer.insertAdjacentHTML('beforeend', createMessageHtml(data.newMessage));
                    threadContainer.scrollTop = threadContainer.scrollHeight;
                    replyQuill.setContents([]); // Clear the reply editor
                } else {
                    alert(data.message || 'An error occurred.');
                }
            }).catch(() => {
                 alert('An error occurred.');
                 submitButton.disabled = false;
                 submitButton.textContent = 'Send Reply';
            });
        }

        function markThreadAsRead(item, recipientId) {
            const formData = new FormData();
            formData.append('action', 'mark_as_read');
            formData.append('recipient_id', recipientId);

            fetch('admin_messaging.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.marked_read_count > 0) {
                    item.dataset.hasUnread = '0';
                    item.classList.remove('bg-blue-50');
                    item.querySelector('p:first-child').classList.remove('font-bold', 'text-gray-900');
                    item.querySelector('p:first-child').classList.add('font-medium', 'text-gray-600');
                    item.querySelector('p:last-child').classList.remove('font-semibold', 'text-gray-800');
                    item.querySelector('p:last-child').classList.add('text-gray-500');
                }
            });
        }
    });
    </script>
</body>
</html>
