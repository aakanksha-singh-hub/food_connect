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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Dashboard - FoodConnect</title>
    <link rel="stylesheet" href="donor_dashboard.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h2 class="logo">
                    <a href="index.html">FoodConnect</a></h2>
                <button class="close-sidebar" id="closeSidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
            
            <nav class="sidebar-nav">
                <a href="?page=dashboard" class="<?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    Dashboard
                </a>
                <a href="?page=donations" class="<?php echo $current_page == 'donations' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 5H8a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2Z"></path><path d="M4 12V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1"></path></svg>
                    Donations
                </a>
                <a href="?page=schedule" class="<?php echo $current_page == 'schedule' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    Schedule Pickup
                </a>
                <a href="?page=history" class="<?php echo $current_page == 'history' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                    History
                </a>
                <a href="?page=profile" class="<?php echo $current_page == 'profile' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    Profile
                </a>
                <a href="?page=settings" class="<?php echo $current_page == 'settings' ? 'active' : ''; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    Settings
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <a href="login.html" class="logout-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    Log Out
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Navbar -->
            <header class="top-nav">
                <button class="menu-toggle" id="menuToggle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                
                <div class="search-bar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" placeholder="Search...">
                </div>
                
                <div class="user-menu">
                    <div class="notifications">
                        <button class="notification-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path></svg>
                            <span class="notification-badge">3</span>
                        </button>
                    </div>
                    
                    <div class="user-profile">
                        <span class="user-name"><?php echo $donor_name; ?></span>
                        <div class="avatar">
                            <?php echo strtoupper(substr($donor_name, 0, 1)); ?>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <?php if ($current_page == 'dashboard'): ?>
                    <div class="page-header">
                        <h1>Dashboard</h1>
                        <p>Welcome back, <?php echo $donor_name; ?>!</p>
                    </div>
                    
                    <!-- Stats Cards -->
                    <div class="stats-cards">
                        <div class="stat-card">
                            <div class="stat-icon green">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $total_donations; ?></h3>
                                <p>Total Donations</p>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon orange">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $meals_provided; ?></h3>
                                <p>Meals Provided</p>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon blue">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 22a8 8 0 0 1 10.9-7.6"></path><path d="M4 16a4 4 0 0 1 7.3-2.3"></path><path d="M22 16.5c0 1.1-.9 2-2 2h-5.5l-2.8 3.5c-.6.8-1.9.3-1.9-.7V14a2 2 0 0 1 2-2H20a2 2 0 0 1 2 2v2.5z"></path><path d="M9 9h.01"></path><path d="M15 9h.01"></path><path d="M12 4C6.5 4 2 7.1 2 11c0 1.4.7 2.7 1.8 3.8"></path><path d="M17.8 14.8A7.8 7.8 0 0 0 20 11c0-1.1-.3-2.2-.9-3.1"></path></svg>
                            </div>
                            <div class="stat-info">
                                <h3><?php echo $co2_saved; ?> kg</h3>
                                <p>CO₂ Emissions Saved</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Activity Section -->
                    <div class="recent-activity">
                        <div class="section-header">
                            <h2>Recent Donations</h2>
                            <a href="?page=history" class="view-all">View All</a>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Date</th>
                                        <th>Items</th>
                                        <th>Quantity</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach(array_slice($donation_history, 0, 3) as $donation): ?>
                                    <tr>
                                        <td><?php echo $donation['id']; ?></td>
                                        <td><?php echo $donation['date']; ?></td>
                                        <td><?php echo $donation['items']; ?></td>
                                        <td><?php echo $donation['quantity']; ?></td>
                                        <td>
                                            <span class="status-badge delivered">
                                                <?php echo $donation['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Upcoming Pickups Section -->
                    <div class="upcoming-pickups">
                        <div class="section-header">
                            <h2>Upcoming Pickups</h2>
                            <a href="?page=schedule" class="view-all">Schedule New</a>
                        </div>
                        
                        <div class="cards-container">
                            <?php foreach($scheduled_pickups as $pickup): ?>
                            <div class="pickup-card">
                                <div class="pickup-date">
                                    <div class="date-badge">
                                        <span class="month"><?php echo date('M', strtotime($pickup['date'])); ?></span>
                                        <span class="day"><?php echo date('d', strtotime($pickup['date'])); ?></span>
                                    </div>
                                    <span class="time"><?php echo $pickup['time']; ?></span>
                                </div>
                                <div class="pickup-details">
                                    <h3>Pickup #<?php echo $pickup['id']; ?></h3>
                                    <p><strong>Items:</strong> <?php echo $pickup['items']; ?></p>
                                    <p><strong>Notes:</strong> <?php echo $pickup['notes']; ?></p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php elseif ($current_page == 'donations'): ?>
                    <div class="page-header">
                        <h1>Donate Food</h1>
                        <p>List surplus food items for donation</p>
                    </div>
                    
                    <div class="form-container">
                        <form class="donation-form">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="food-type">Food Category</label>
                                    <select id="food-type" class="form-control" required>
                                        <option value="" disabled selected>Select category</option>
                                        <option value="prepared-meals">Prepared Meals</option>
                                        <option value="produce">Produce</option>
                                        <option value="bakery">Bakery Items</option>
                                        <option value="dairy">Dairy Products</option>
                                        <option value="canned">Canned Goods</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="quantity">Quantity (kg)</label>
                                    <input type="number" id="quantity" class="form-control" min="0.1" step="0.1" required>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="food-description">Description</label>
                                    <textarea id="food-description" class="form-control" rows="3" placeholder="Describe the food items (e.g., 10 portions of pasta, 5 loaves of bread)" required></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="expiry-date">Best Before</label>
                                    <input type="date" id="expiry-date" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="pickup-time">Available for Pickup</label>
                                    <input type="time" id="pickup-time" class="form-control" required>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="pickup-instructions">Pickup Instructions</label>
                                    <textarea id="pickup-instructions" class="form-control" rows="2" placeholder="Any special instructions for pickup"></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="storage-info">Storage Requirements</label>
                                    <select id="storage-info" class="form-control">
                                        <option value="" disabled selected>Select requirement</option>
                                        <option value="refrigerated">Refrigeration Required</option>
                                        <option value="frozen">Freezing Required</option>
                                        <option value="room-temp">Room Temperature</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="allergens">Allergens</label>
                                    <select id="allergens" class="form-control" multiple>
                                        <option value="nuts">Nuts</option>
                                        <option value="dairy">Dairy</option>
                                        <option value="gluten">Gluten</option>
                                        <option value="eggs">Eggs</option>
                                        <option value="soy">Soy</option>
                                        <option value="seafood">Seafood</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-footer">
                                <button type="submit" class="btn btn-primary">Submit Donation</button>
                                <button type="reset" class="btn btn-secondary">Reset</button>
                            </div>
                        </form>
                    </div>

                <?php elseif ($current_page == 'schedule'): ?>
                    <div class="page-header">
                        <h1>Schedule Pickup</h1>
                        <p>Set a recurring schedule for food pickups</p>
                    </div>
                    
                    <div class="form-container">
                        <form class="schedule-form">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="pickup-date">Pickup Date</label>
                                    <input type="date" id="pickup-date" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="pickup-time-schedule">Pickup Time</label>
                                    <input type="time" id="pickup-time-schedule" class="form-control" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="frequency">Frequency</label>
                                    <select id="frequency" class="form-control">
                                        <option value="one-time">One-time Pickup</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="estimated-quantity">Estimated Quantity (kg)</label>
                                    <input type="number" id="estimated-quantity" class="form-control" min="0.1" step="0.1" required>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="food-items">Expected Food Items</label>
                                    <textarea id="food-items" class="form-control" rows="2" placeholder="Brief description of the expected food items"></textarea>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="pickup-notes">Special Instructions</label>
                                    <textarea id="pickup-notes" class="form-control" rows="2" placeholder="Any special instructions for pickup"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-footer">
                                <button type="submit" class="btn btn-primary">Schedule Pickup</button>
                                <button type="reset" class="btn btn-secondary">Reset</button>
                            </div>
                        </form>
                    </div>

                <?php elseif ($current_page == 'history'): ?>
                    <div class="page-header">
                        <h1>Donation History</h1>
                        <p>View all your past donations</p>
                    </div>
                    
                    <div class="filters">
                        <div class="filter-item">
                            <label for="date-filter">Date Range</label>
                            <select id="date-filter" class="form-control">
                                <option value="all">All Time</option>
                                <option value="last-week">Last Week</option>
                                <option value="last-month">Last Month</option>
                                <option value="last-3-months">Last 3 Months</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label for="status-filter">Status</label>
                            <select id="status-filter" class="form-control">
                                <option value="all">All Statuses</option>
                                <option value="pending">Pending</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="picked-up">Picked Up</option>
                                <option value="delivered">Delivered</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Quantity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($donation_history as $donation): ?>
                                <tr>
                                    <td><?php echo $donation['id']; ?></td>
                                    <td><?php echo $donation['date']; ?></td>
                                    <td><?php echo $donation['items']; ?></td>
                                    <td><?php echo $donation['quantity']; ?></td>
                                    <td>
                                        <span class="status-badge delivered">
                                            <?php echo $donation['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <button class="action-btn view-btn" title="View Details">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                            </button>
                                            <button class="action-btn print-btn" title="Print Receipt">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($current_page == 'profile'): ?>
                    <div class="page-header">
                        <h1>Business Profile</h1>
                        <p>Manage your restaurant or business information</p>
                    </div>
                    
                    <div class="profile-container">
                        <div class="profile-card">
                            <div class="profile-header">
                                <div class="profile-avatar">
                                    <?php echo strtoupper(substr($donor_name, 0, 1)); ?>
                                </div>
                                <div class="profile-title">
                                    <h2><?php echo $donor_name; ?></h2>
                                    <p>Food Donor</p>
                                </div>
                            </div>
                            
                            <div class="profile-info">
                                <div class="info-group">
                                    <span class="info-label">Email</span>
                                    <span class="info-value"><?php echo $donor_email; ?></span>
                                </div>
                                <div class="info-group">
                                    <span class="info-label">Phone</span>
                                    <span class="info-value"><?php echo $donor_phone; ?></span>
                                </div>
                                <div class="info-group">
                                    <span class="info-label">Address</span>
                                    <span class="info-value"><?php echo $donor_address; ?></span>
                                </div>
                                <div class="info-group">
                                    <span class="info-label">Member Since</span>
                                    <span class="info-value">January 15, 2024</span>
                                </div>
                            </div>
                            
                            <div class="profile-actions">
                                <button class="btn btn-primary">Edit Profile</button>
                            </div>
                        </div>
                        
                        <div class="profile-sections">
                            <div class="profile-section">
                                <h3>Business Details</h3>
                                <form class="profile-form">
                                    <div class="form-group">
                                        <label for="business-name">Business Name</label>
                                        <input type="text" id="business-name" class="form-control" value="<?php echo $donor_name; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="business-type">Business Type</label>
                                        <select id="business-type" class="form-control">
                                            <option selected>Restaurant</option>
                                            <option>Catering</option>
                                            <option>Hotel</option>
                                            <option>Bakery</option>
                                            <option>Cafe</option>
                                            <option>Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="business-description">About Your Business</label>
                                        <textarea id="business-description" class="form-control" rows="3">Family-owned restaurant specializing in Italian cuisine. We've been serving the community since 2010.</textarea>
                                    </div>
                                    <div class="form-group">
                                        <label for="business-license">Business License Number</label>
                                        <input type="text" id="business-license" class="form-control" value="LIC-12345678">
                                    </div>
                                    <div class="form-footer">
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                            
                            <div class="profile-section">
                                <h3>Contact Information</h3>
                                <form class="profile-form">
                                    <div class="form-group">
                                        <label for="contact-name">Contact Person</label>
                                        <input type="text" id="contact-name" class="form-control" value="John Smith">
                                    </div>
                                    <div class="form-group">
                                        <label for="contact-email">Email</label>
                                        <input type="email" id="contact-email" class="form-control" value="<?php echo $donor_email; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="contact-phone">Phone</label>
                                        <input type="tel" id="contact-phone" class="form-control" value="<?php echo $donor_phone; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="contact-address">Address</label>
                                        <input type="text" id="contact-address" class="form-control" value="<?php echo $donor_address; ?>">
                                    </div>
                                    <div class="form-footer">
                                        <button type="submit" class="btn btn-primary">Update Contact</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php elseif ($current_page == 'settings'): ?>
                    <div class="page-header">
                        <h1>Settings</h1>
                        <p>Manage your account preferences</p>
                    </div>
                    
                    <div class="settings-container">
                        <div class="settings-sidebar">
                            <div class="settings-nav">
                                <a href="#account" class="active">Account Settings</a>
                                <a href="#notifications">Notifications</a>
                                <a href="#privacy">Privacy & Security</a>
                                <a href="#preferences">Preferences</a>
                            </div>
                        </div>
                        
                        <div class="settings-content">
                            <div id="account" class="settings-section">
                                <h3>Account Settings</h3>
                                <form class="settings-form">
                                    <div class="form-group">
                                        <label for="account-email">Email Address</label>
                                        <input type="email" id="account-email" class="form-control" value="<?php echo $donor_email; ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="current-password">Current Password</label>
                                        <input type="password" id="current-password" class="form-control" placeholder="Enter current password">
                                    </div>
                                    <div class="form-group">
                                        <label for="new-password">New Password</label>
                                        <input type="password" id="new-password" class="form-control" placeholder="Enter new password">
                                    </div>
                                    <div class="form-group">
                                        <label for="confirm-password">Confirm New Password</label>
                                        <input type="password" id="confirm-password" class="form-control" placeholder="Confirm new password">
                                    </div>
                                    <div class="form-footer">
                                        <button type="submit" class="btn btn-primary">Update Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            const closeSidebar = document.getElementById('closeSidebar');
            
            menuToggle.addEventListener('click', function() {
                sidebar.classList.add('active');
            });
            
            closeSidebar.addEventListener('click', function() {
                sidebar.classList.remove('active');
            });
            
            // Close sidebar when clicking outside
            document.addEventListener('click', function(event) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>

