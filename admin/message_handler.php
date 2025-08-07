<?php
// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include the database configuration BEFORE starting the session.
require_once "../db_config.php";
session_start();

// --- SECURITY CHECK: Only admins can perform these actions ---
if (!isset($_SESSION["admin_loggedin"]) || $_SESSION["admin_loggedin"] !== true || $_SESSION["role"] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden: You do not have permission.']);
    exit;
}

// --- MAIN LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // --- ACTION: Handle image uploads from the rich text editor ---
    if ($action === 'upload_image') {
        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
            $upload_dir = '../uploads/message_images/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_info = pathinfo($_FILES['image']['name']);
            $file_ext = strtolower($file_info['extension']);
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];

            if (in_array($file_ext, $allowed_exts)) {
                $unique_filename = uniqid('msg_img_', true) . '.' . $file_ext;
                $destination = $upload_dir . $unique_filename;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                    // Return the URL relative to the site root
                    $image_url = 'uploads/message_images/' . $unique_filename;
                    echo json_encode(['success' => true, 'url' => $image_url]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No image uploaded or an error occurred.']);
        }
        exit;
    }

    // --- ACTION: Handle the main message form submission ---
    if ($action === 'send_message') {
        $sender_id = $_SESSION['id'];
        $subject = trim($_POST['subject'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $recipient_roles = $_POST['recipient_roles'] ?? [];
        $recipient_ids_individual = $_POST['recipient_ids'] ?? [];

        // CORRECTED VALIDATION: Check for subject, body, and EITHER roles OR individual IDs.
        if (empty($subject) || empty($body) || (empty($recipient_roles) && empty($recipient_ids_individual))) {
            echo json_encode(['success' => false, 'message' => 'Subject, message body, and at least one recipient are required.']);
            exit;
        }

        mysqli_begin_transaction($link);
        try {
            $sql_insert_message = "INSERT INTO messages (sender_id, subject, body) VALUES (?, ?, ?)";
            $stmt_message = mysqli_prepare($link, $sql_insert_message);
            mysqli_stmt_bind_param($stmt_message, "iss", $sender_id, $subject, $body);
            mysqli_stmt_execute($stmt_message);
            
            $message_id = mysqli_insert_id($link);
            mysqli_stmt_close($stmt_message);

            $all_recipient_ids = is_array($recipient_ids_individual) ? $recipient_ids_individual : [];

            if (!empty($recipient_roles)) {
                $placeholders = implode(',', array_fill(0, count($recipient_roles), '?'));
                $sql_get_recipients = "SELECT id FROM users WHERE role IN ($placeholders)";
                $stmt_recipients = mysqli_prepare($link, $sql_get_recipients);
                mysqli_stmt_bind_param($stmt_recipients, str_repeat('s', count($recipient_roles)), ...$recipient_roles);
                mysqli_stmt_execute($stmt_recipients);
                $result = mysqli_stmt_get_result($stmt_recipients);
                
                while ($row = mysqli_fetch_assoc($result)) {
                    $all_recipient_ids[] = $row['id'];
                }
                mysqli_stmt_close($stmt_recipients);
            }

            $unique_recipient_ids = array_unique(array_map('intval', $all_recipient_ids));

            if (empty($unique_recipient_ids)) {
                throw new Exception("No valid recipients found.");
            }

            $sql_insert_recipient = "INSERT INTO message_recipients (message_id, recipient_id) VALUES (?, ?)";
            $stmt_recipient = mysqli_prepare($link, $sql_insert_recipient);
            
            foreach ($unique_recipient_ids as $recipient_id) {
                mysqli_stmt_bind_param($stmt_recipient, "ii", $message_id, $recipient_id);
                mysqli_stmt_execute($stmt_recipient);
            }
            mysqli_stmt_close($stmt_recipient);

            mysqli_commit($link);
            echo json_encode(['success' => true, 'message' => 'Message sent successfully to ' . count($unique_recipient_ids) . ' users.']);

        } catch (Exception $e) {
            mysqli_rollback($link);
            error_log("Message sending failed: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'An error occurred while sending the message.']);
        }
    }

    mysqli_close($link);
    exit;
}
?>
