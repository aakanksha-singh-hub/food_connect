<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'recipient') {
    header("Location: login.html");
    exit;
}

// Dummy data for demonstration
$recipient_name = "Hope Shelter";
$recipient_id = $_SESSION['user_id'] ?? "R12345";
$recipient_email = "contact@hopeshelter.org";
$recipient_phone = "(555) 123-4567";
$recipient_address = "456 Charity Lane, Helpville";

// Dummy statistics
$total_meals_received = 1250;
$upcoming_deliveries = 3;
$people_served = 450;

// Dummy upcoming deliveries
$upcoming_deliveries_list = [
    ["id" => "D1001", "date" => "2024-03-15", "time" => "2:00 PM", "items" => "Assorted meals", "donor" => "Sunshine Restaurant"],
    ["id" => "D1002", "date" => "2024-03-18", "time" => "10:00 AM", "items" => "Fresh produce", "donor" => "Local Farm Co-op"],
    ["id" => "D1003", "date" => "2024-03-20", "time" => "3:30 PM", "items" => "Baked goods", "donor" => "City Bakery"]
];

// Dummy delivery history
$delivery_history = [
    ["id" => "D1000", "date" => "2024-03-10", "items" => "Mixed meals", "quantity" => "50 meals", "donor" => "Community Kitchen"],
    ["id" => "D999", "date" => "2024-03-05", "items" => "Canned goods", "quantity" => "100 cans", "donor" => "Food Bank"],
    ["id" => "D998", "date" => "2024-03-01", "items" => "Fresh fruits", "quantity" => "200 lbs", "donor" => "Green Grocer"]
];

// Get current page from URL parameter
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Include the view file
include 'recipient_dashboard.html';
?>

