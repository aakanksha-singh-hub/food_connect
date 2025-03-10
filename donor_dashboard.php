<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'donor') {
    header("Location: login.html");
    exit;
}

// Dummy data for demonstration
$donor_name = "Sunshine Restaurant";
$donor_id = $_SESSION['user_id'] ?? "12345";
$donor_email = "contact@sunshinerestaurant.com";
$donor_address = "123 Main Street, Cityville";
$donor_phone = "(555) 123-4567";

// Dummy statistics
$total_donations = 42;
$meals_provided = 328;
$co2_saved = 187; // in kg
$donation_history = [
    ["id" => "D1001", "date" => "2024-03-08", "items" => "Pasta, Bread, Salad", "quantity" => "5 kg", "status" => "Delivered"],
    ["id" => "D1002", "date" => "2024-03-05", "items" => "Rice, Curry, Vegetables", "quantity" => "8 kg", "status" => "Delivered"],
    ["id" => "D1003", "date" => "2024-03-01", "items" => "Sandwiches, Fruits", "quantity" => "3 kg", "status" => "Delivered"],
    ["id" => "D1004", "date" => "2024-02-25", "items" => "Pizza, Salad", "quantity" => "4 kg", "status" => "Delivered"],
    ["id" => "D1005", "date" => "2024-02-20", "items" => "Desserts, Pastries", "quantity" => "2 kg", "status" => "Delivered"]
];

// Dummy scheduled pickups
$scheduled_pickups = [
    ["id" => "P2001", "date" => "2024-03-15", "time" => "6:00 PM", "items" => "End of day surplus", "notes" => "Please bring containers"],
    ["id" => "P2002", "date" => "2024-03-18", "time" => "7:30 PM", "items" => "Event leftovers", "notes" => "Back entrance pickup"]
];

// Get current page from URL parameter
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

include 'donor_dashboard.html';
?>
