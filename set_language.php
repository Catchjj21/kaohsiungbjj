<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lang'])) {
    $lang = $_POST['lang'];

    // Validate language
    if (in_array($lang, ['en', 'zh'])) {
        $_SESSION['lang'] = $lang;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid language']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>
