<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'volunteer') {
    header("Location: login.html");
    exit;
}
echo "<h1>Welcome, Volunteer!</h1>";
echo "<p>Here, you can manage pickups and deliveries.</p>";
?>
