<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'recipient') {
    header("Location: login.html");
    exit;
}
echo "<h1>Welcome, Recipient!</h1>";
echo "<p>Here, you can request food and track your requests.</p>";
?>
