<?php
// Include the PHP QR Code library
require_once 'phpqrcode/qrlib.php';

// Get the card number from the request
$card_number = $_GET['card'] ?? '';

if (empty($card_number)) {
    die('No card number provided');
}

// Set the content type to PNG image
header('Content-Type: image/png');

// Generate QR code directly to output
// This creates a scannable QR code that can be read by QR code readers
QRcode::png($card_number, false, QR_ECLEVEL_L, 10, 2);
?>
