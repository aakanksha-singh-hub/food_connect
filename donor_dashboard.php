<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'donor') {
    header("Location: login.html");
    exit;
}
echo "<h1>Welcome, Donor!</h1>";
echo "<p>Here, you can donate food and track donations.</p>";
?>
