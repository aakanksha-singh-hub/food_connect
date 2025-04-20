<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

switch ($_SESSION['user_type']) {
    case 'donor':
        header("Location: donor_dashboard.php");
        exit();
    case 'recipient':
        header("Location: recipient_dashboard.php");
        exit();
    case 'volunteer':
        header("Location: volunteer_dashboard.php");
        exit();
    default:
        header("Location: index.html");
        exit();
}
?>
