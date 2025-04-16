<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Check if user is logged in and is a recipient
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'recipient') {
    header("Location: login.html");
    exit;
}

$recipient_id = $_SESSION['user_id'];

// Debug logging
error_log("Fetching profile for user ID: " . $recipient_id);

// Fetch complete user profile information
try {
    // First, fetch basic user information
    $basic_profile_stmt = $pdo->prepare("
        SELECT 
            id,
            first_name,
            last_name,
            email,
            phone,
            location,
            user_type,
            to_char(created_at, 'Month YYYY') as member_since
        FROM users 
        WHERE id = :user_id
    ");
    
    $basic_profile_stmt->execute(['user_id' => $recipient_id]);
    $user_profile = $basic_profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_profile) {
        error_log("User profile not found for ID: " . $recipient_id);
        header("Location: logout.php");
        exit;
    }

    // Now fetch statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT d.id) as total_donations,
            COUNT(DISTINCT CASE WHEN p.status = 'completed' THEN p.id END) as completed_pickups
        FROM users u
        LEFT JOIN donations d ON d.recipient_id = u.id
        LEFT JOIN pickups p ON p.donation_id = d.id
        WHERE u.id = :user_id
    ");
    
    $stats_stmt->execute(['user_id' => $recipient_id]);
    $user_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Merge stats with profile
    $user_profile = array_merge($user_profile, $user_stats);

    // Debug logging
    error_log("User profile fetched successfully: " . print_r($user_profile, true));

    // Store essential information in session
    $_SESSION['first_name'] = $user_profile['first_name'];
    $_SESSION['last_name'] = $user_profile['last_name'];
    $_SESSION['email'] = $user_profile['email'];
    $_SESSION['phone'] = $user_profile['phone'];
    $_SESSION['location'] = $user_profile['location'];
    $_SESSION['created_at'] = $user_profile['member_since'];

    } catch (PDOException $e) {
    error_log("Database error while fetching user profile: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    
    // Set default values if query fails
    $user_profile = [
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'phone' => $_SESSION['phone'] ?? '',
        'location' => $_SESSION['location'] ?? '',
        'member_since' => $_SESSION['created_at'] ?? date('F Y'),
        'user_type' => 'recipient',
        'total_donations' => 0,
        'completed_pickups' => 0
    ];
}

// Fetch recipient location for donations query
$recipient_location = $user_profile['location'] ?? $_SESSION['location'] ?? '';

if (empty($recipient_location)) {
    $_SESSION['error_message'] = "Your location is not set. Please update your profile.";
}

// Debug logging
error_log("Using location: " . $recipient_location);

// Fetch available donations in recipient's location
try {
    $stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.food_item,
            d.quantity,
            d.location,
            d.donation_date,
            d.expiry_date,
            u.first_name,
            u.last_name
        FROM donations d
        JOIN users u ON d.donor_id = u.id
        WHERE d.location = :location 
        AND d.status = 'available'
        AND (d.expiry_date IS NULL OR d.expiry_date > NOW())
        ORDER BY d.donation_date DESC
    ");
    $stmt->execute(['location' => $recipient_location]);
    $available_donations = $stmt->fetchAll();
    
    // Fetch accepted donations by this recipient with pickup status
    $accepted_stmt = $pdo->prepare("
        SELECT 
            d.id,
            d.food_item,
            d.quantity,
            d.location,
            d.donation_date,
            d.status as donation_status,
            u.first_name,
            u.last_name,
            p.status as pickup_status,
            p.pickup_date,
            p.completion_date
        FROM donations d
        JOIN users u ON d.donor_id = u.id
        LEFT JOIN pickups p ON d.id = p.donation_id
        WHERE d.recipient_id = :recipient_id
        ORDER BY 
            CASE 
                WHEN p.status = 'pending' THEN 1
                WHEN p.status = 'assigned' THEN 2
                ELSE 3
            END,
            d.donation_date DESC
    ");
    $accepted_stmt->execute(['recipient_id' => $recipient_id]);
    $accepted_donations = $accepted_stmt->fetchAll();

    // Debug logging
    error_log("Found " . count($available_donations) . " available donations");
    error_log("Found " . count($accepted_donations) . " accepted donations");

} catch (PDOException $e) {
    error_log("Error fetching donations: " . $e->getMessage());
    error_log("SQL State: " . $e->getCode());
    $available_donations = [];
    $accepted_donations = [];
}

// Handle accepting a donation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accept_donation'])) {
    $donation_id = intval($_POST['donation_id']);
    
    // Debug logging
    error_log("Attempting to accept donation ID: " . $donation_id . " by recipient ID: " . $recipient_id);
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        error_log("Transaction started");
        
        // First, verify the donation exists and is available
        $check_stmt = $pdo->prepare("
            SELECT d.id, d.status, d.location, d.recipient_id
            FROM donations d
            WHERE d.id = :donation_id
        ");
        $check_stmt->execute(['donation_id' => $donation_id]);
        $donation = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Donation check result: " . print_r($donation, true));
        
        if (!$donation) {
            throw new Exception("Donation not found");
        }
        
        if ($donation['status'] !== 'available') {
            throw new Exception("Donation is not available (status: " . $donation['status'] . ")");
        }
        
        if ($donation['recipient_id'] !== null) {
            throw new Exception("Donation already has a recipient");
        }
        
        // Verify recipient's location matches donation location
        if ($donation['location'] !== $recipient_location) {
            throw new Exception("Location mismatch - Donation: " . $donation['location'] . ", Recipient: " . $recipient_location);
        }
        
        error_log("All validation checks passed, proceeding with update");
        
        // Update donation status
        $update_stmt = $pdo->prepare("
            UPDATE donations 
            SET status = 'accepted', 
                recipient_id = :recipient_id
            WHERE id = :donation_id 
            AND status = 'available' 
            AND recipient_id IS NULL
            RETURNING id, status
        ");
        
        $update_result = $update_stmt->execute([
            'recipient_id' => $recipient_id,
            'donation_id' => $donation_id
        ]);
        
        $updated_donation = $update_stmt->fetch();
        error_log("Update result: " . ($updated_donation ? "Success" : "Failed"));
        
        if (!$updated_donation) {
            throw new Exception("Failed to update donation status");
        }
        
        // Create pickup request
        $pickup_stmt = $pdo->prepare("
            INSERT INTO pickups (
                donation_id, 
                status, 
                pickup_date,
                completion_date,
                scheduled_time
            ) VALUES (
                :donation_id, 
                'pending', 
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP + INTERVAL '7 days',
                CURRENT_TIMESTAMP + INTERVAL '1 day'
            )
            RETURNING id, status
        ");
        
        $pickup_result = $pickup_stmt->execute(['donation_id' => $donation_id]);
        $created_pickup = $pickup_stmt->fetch();
        error_log("Pickup creation result: " . ($created_pickup ? "Success" : "Failed"));
        
        if (!$created_pickup) {
            throw new Exception("Failed to create pickup request");
        }
        
        $pdo->commit();
        error_log("Transaction committed successfully");
        $_SESSION['success_message'] = "Donation accepted successfully! A volunteer will be assigned for pickup. Default pickup time has been set to tomorrow, but a volunteer may contact you to arrange a different time.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error accepting donation: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        $_SESSION['error_message'] = "An error occurred: " . $e->getMessage();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Database error while accepting donation: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        error_log("Stack trace: " . $e->getTraceAsString());
        $_SESSION['error_message'] = "A database error occurred. Please try again.";
    }
    
    header("Location: recipient_dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipient Dashboard - FoodConnect</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-light: #dbeafe;
            --accent: #f97316;
            --text: #1e293b;
            --text-light: #64748b;
            --background: #ffffff;
            --background-alt: #f8fafc;
            --border: #e2e8f0;
            --error: #ef4444;
            --success: #10b981;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", system-ui, sans-serif;
        }

        body {
            line-height: 1.5;
            color: var(--text);
            background: var(--background-alt);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Navbar Styles */
        .navbar {
            background: var(--background);
            border-bottom: 1px solid var(--border);
            padding: 0.5rem 0;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
            height: 3.5rem;
        }

        .navbar .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
        }

        .logo {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .nav-links {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            margin-left: auto;
        }

        .nav-link {
            color: var(--text);
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            transition: var(--transition);
            position: relative;
            border-radius: 6px;
        }

        .nav-link:hover {
            color: var(--primary);
            background: var(--primary-light);
        }

        .nav-link.active {
            color: var(--primary);
            background: var(--primary-light);
        }

        .nav-link.active::after {
            display: none;
        }

        .menu-toggle {
            display: none;
            cursor: pointer;
        }

        /* Dashboard Layout */
        .main-content {
            margin-left: 0;
            padding: 2rem;
            margin-top: 3.5rem;
        }

        .welcome-header {
            margin-bottom: 2rem;
        }

        .welcome-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .location-info {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary);
        }

        .card {
            background: var(--background);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .card h4 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card p {
            margin-bottom: 0.75rem;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card p i {
            color: var(--primary);
            width: 1.25rem;
            text-align: center;
        }

        .card p strong {
            font-weight: 500;
            margin-right: 0.25rem;
        }

        .card-footer {
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .w-100 {
            width: 100%;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-size: 0.9375rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fff7ed;
            color: #9a3412;
        }

        .status-assigned {
            background: #dbeafe;
            color: #1e40af;
        }

        .message {
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9375rem;
            font-weight: 500;
        }

        .success { 
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .error { 
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Profile Section Styles */
        .profile-section {
            max-width: 1200px;
            margin: 0 auto;
        }

        .row {
            display: flex;
            flex-wrap: wrap;
            margin: -0.75rem;
        }

        .col-md-8 {
            flex: 0 0 66.666667%;
            max-width: 66.666667%;
            padding: 0.75rem;
        }

        .col-md-6 {
            flex: 0 0 50%;
            max-width: 50%;
            padding: 0.75rem;
        }

        .col-md-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
            padding: 0.75rem;
        }

        .card {
            background: var(--background);
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: var(--transition);
            height: 100%;
        }

        .card.shadow-sm {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .card-body {
            padding: 1.5rem;
        }

        .card-title {
            color: var(--text);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .mb-4 {
            margin-bottom: 1.5rem;
        }

        .mb-3 {
            margin-bottom: 1rem;
        }

        .mb-0 {
            margin-bottom: 0;
        }

        .me-2 {
            margin-right: 0.5rem;
        }

        .text-muted {
            color: var(--text-light);
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.375rem;
            display: block;
        }

        /* Profile Information Display */
        .profile-info-item {
            padding: 0.75rem;
            border-radius: 8px;
            transition: var(--transition);
        }

        .profile-info-item:hover {
            background: var(--background-alt);
        }

        .profile-info-item p {
            display: flex;
            align-items: center;
            font-size: 1rem;
            color: var(--text);
            margin: 0;
        }

        .profile-info-item i {
            color: var(--primary);
            width: 1.5rem;
            font-size: 1rem;
        }

        /* Statistics Card */
        .stat-item {
            padding: 1rem;
            border-radius: 8px;
            background: var(--background-alt);
            margin-bottom: 1rem;
            transition: var(--transition);
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .stat-item:last-child {
            margin-bottom: 0;
        }

        .stat-item label {
            display: block;
            margin-bottom: 0.5rem;
        }

        .stat-item .h4 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
        }

        .stat-item i {
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .col-md-8, .col-md-6, .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }
            
            .profile-section {
                padding: 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
        }

        @media (max-width: 640px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .card {
                margin-bottom: 1rem;
            }
        }

        /* Footer */
        .footer {
            background: var(--background);
            border-top: 1px solid var(--border);
            padding: 3rem 0 1.5rem;
            margin-top: auto;
        }

        .footer .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h3 {
            color: var(--text);
            font-size: 1.125rem;
            margin-bottom: 1rem;
        }

        .footer-section p,
        .footer-section a {
            color: var(--text-light);
            font-size: 0.9375rem;
            margin-bottom: 0.5rem;
            text-decoration: none;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section a:hover {
            color: var(--primary);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            color: var(--text-light);
            font-size: 0.875rem;
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
                position: fixed;
                top: 4rem;
                left: 0;
                width: 100%;
                background: var(--background);
                flex-direction: column;
                padding: 0;
                border-bottom: 1px solid var(--border);
            }

            .nav-links.active {
                display: flex;
            }

            .nav-link {
                padding: 1rem;
                width: 100%;
                text-align: center;
                border-bottom: 1px solid var(--border);
            }

            .nav-link:last-child {
                border-bottom: none;
            }

            .nav-link.active::after {
                display: none;
            }

            .nav-link.active {
                background: var(--primary-light);
            }

            .menu-toggle {
                display: block;
                cursor: pointer;
            }

            .menu-toggle i {
                font-size: 1.5rem;
                color: var(--text);
            }

            .main-content {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
    <div class="container">
            <a href="index.html" class="logo">FoodConnect</a>
            <div class="nav-links">
                <a href="#dashboard" class="nav-link active" data-tab="dashboard">Dashboard</a>
                <a href="#available" class="nav-link" data-tab="available">Available Donations</a>
                <a href="#accepted" class="nav-link" data-tab="accepted">Accepted Donations</a>
                <a href="#profile" class="nav-link" data-tab="profile">Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
            <div class="menu-toggle">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <div class="welcome-header">
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Recipient'); ?></h1>
            <div class="location-info">
                <i class="fas fa-map-marker-alt"></i>
                <span><?php echo htmlspecialchars($_SESSION['location'] ?? 'Location'); ?></span>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="message success">
                <?php 
                echo htmlspecialchars($_SESSION['success_message']);
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message error">
                <?php 
                echo htmlspecialchars($_SESSION['error_message']);
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Overview Tab -->
        <div id="dashboard" class="tab-content active">
            <h2 class="section-title">
                <i class="fas fa-chart-pie"></i>
                Overview
            </h2>
            <div class="dashboard-grid">
                <div class="card">
                    <h4>
                        <i class="fas fa-box-open"></i>
                        Available Donations
                    </h4>
                    <p class="stat"><?php echo count($available_donations); ?> donations in your area</p>
                </div>
                <div class="card">
                    <h4>
                        <i class="fas fa-check-circle"></i>
                        Accepted Donations
                    </h4>
                    <p class="stat"><?php echo count($accepted_donations); ?> donations accepted</p>
                </div>
            </div>
        </div>

        <!-- Available Donations Tab -->
        <div id="available" class="tab-content">
            <h2 class="section-title">
                <i class="fas fa-box-open"></i>
                Available Donations
            </h2>
            <div class="dashboard-grid">
                <?php foreach ($available_donations as $donation): ?>
                    <div class="card">
                        <h4>
                            <i class="fas fa-gift"></i>
                            <?php echo htmlspecialchars($donation['food_item']); ?>
                        </h4>
                        <p>
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($donation['first_name'] . ' ' . $donation['last_name']); ?>
                        </p>
                        <p>
                            <i class="fas fa-clock"></i>
                            <?php echo htmlspecialchars(date('F j, Y', strtotime($donation['donation_date']))); ?>
                        </p>
                        <p>
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($donation['location']); ?>
                        </p>
                        <div class="card-footer">
                        <form method="POST">
                                <input type="hidden" name="donation_id" value="<?php echo $donation['id']; ?>">
                                <button type="submit" name="accept_donation" class="btn btn-primary">
                                    <i class="fas fa-hand-holding-heart"></i>
                                    Accept Donation
                                </button>
                        </form>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($available_donations)): ?>
                    <div class="card">
                        <p style="text-align: center;">No available donations in your area at the moment.</p>
                    </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Accepted Donations Tab -->
        <div id="accepted" class="tab-content">
            <h2 class="section-title">
                <i class="fas fa-check-circle"></i>
                Your Accepted Donations
            </h2>
            <div class="dashboard-grid">
                <?php foreach ($accepted_donations as $donation): ?>
                    <div class="card">
                        <h4>
                            <i class="fas fa-gift"></i>
                            <?php echo htmlspecialchars($donation['food_item']); ?>
                        </h4>
                        <p>
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($donation['first_name'] . ' ' . $donation['last_name']); ?>
                        </p>
                        <p>
                            <i class="fas fa-clock"></i>
                            <?php echo htmlspecialchars(date('F j, Y', strtotime($donation['donation_date']))); ?>
                        </p>
                        <p>
                            <i class="fas fa-info-circle"></i>
                            Status: 
                            <span class="status-badge <?php echo $donation['pickup_status'] === 'completed' ? 'status-completed' : 'status-pending'; ?>">
                                <?php echo ucfirst(htmlspecialchars($donation['pickup_status'] ?? 'pending')); ?>
                            </span>
                        </p>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($accepted_donations)): ?>
                    <div class="card">
                        <p style="text-align: center;">You haven't accepted any donations yet.</p>
                    </div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Profile Tab -->
        <div id="profile" class="tab-content">
            <h2 class="section-title">
                <i class="fas fa-user"></i>
                Profile Information
            </h2>
            <div class="profile-section">
                <div class="row">
                    <div class="col-md-8">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title">Personal Information</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="profile-info-item">
                                            <label class="text-muted">Full Name</label>
                                            <p>
                                                <i class="fas fa-user me-2"></i>
                                                <?php echo htmlspecialchars($user_profile['first_name'] . ' ' . $user_profile['last_name']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="profile-info-item">
                                            <label class="text-muted">Email Address</label>
                                            <p>
                                                <i class="fas fa-envelope me-2"></i>
                                                <?php echo htmlspecialchars($user_profile['email']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="profile-info-item">
                                            <label class="text-muted">Phone Number</label>
                                            <p>
                                                <i class="fas fa-phone me-2"></i>
                                                <?php echo htmlspecialchars($user_profile['phone'] ?? 'Not set'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="profile-info-item">
                                            <label class="text-muted">Location</label>
                                            <p>
                                                <i class="fas fa-map-marker-alt me-2"></i>
                                                <?php echo htmlspecialchars($user_profile['location'] ?? 'Not set'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="profile-info-item">
                                            <label class="text-muted">Member Since</label>
                                            <p>
                                                <i class="fas fa-calendar-alt me-2"></i>
                                                <?php echo htmlspecialchars($user_profile['member_since']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="profile-info-item">
                                            <label class="text-muted">Account Type</label>
                                            <p>
                                                <i class="fas fa-user-tag me-2"></i>
                                                <?php echo ucfirst(htmlspecialchars($user_profile['user_type'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h5 class="card-title">Activity Overview</h5>
                                <div class="stat-item">
                                    <label class="text-muted">Total Accepted Donations</label>
                                    <p class="h4">
                                        <i class="fas fa-gift me-2"></i>
                                        <?php echo intval($user_profile['total_donations']); ?>
                                    </p>
                                </div>
                                <div class="stat-item">
                                    <label class="text-muted">Completed Pickups</label>
                                    <p class="h4">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <?php echo intval($user_profile['completed_pickups']); ?>
                                    </p>
                                </div>
                                <div class="mt-4">
                                    <a href="edit_profile.php" class="btn btn-primary w-100">
                                        <i class="fas fa-edit me-2"></i>
                                        Edit Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>About FoodConnect</h3>
                    <p>Connecting food donors with those in need, reducing waste and helping communities.</p>
                </div>
                <div class="footer-section">
                    <h3>Quick Links</h3>
                    <ul>
                        <li><a href="index.html">Home</a></li>
                        <li><a href="index.html#about">About</a></li>
                        <li><a href="index.html#features">Features</a></li>
                        <li><a href="index.html#contact">Contact</a></li>
                    </ul>
                </div>
                <div class="footer-section">
                    <h3>Contact Us</h3>
                    <p>Email: info@foodconnect.com</p>
                    <p>Phone: +91 9999999999</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; 2025 FoodConnect. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching functionality
            const navLinks = document.querySelectorAll('.nav-link[data-tab]');
            const tabContents = document.querySelectorAll('.tab-content');

            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const tabId = this.getAttribute('data-tab');

                    // Update active states
                    navLinks.forEach(nl => nl.classList.remove('active'));
                    this.classList.add('active');

                    // Show selected tab content
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === tabId) {
                            content.classList.add('active');
                            // Scroll to the section
                            content.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });

                    // Close mobile menu when a link is clicked
                    document.querySelector('.nav-links').classList.remove('active');
                });
            });

            // Handle URL hash on page load
            const hash = window.location.hash.substring(1);
            if (hash) {
                const targetLink = document.querySelector(`.nav-link[data-tab="${hash}"]`);
                if (targetLink) {
                    targetLink.click();
                }
            }

            // Mobile menu toggle
            const menuToggle = document.querySelector('.menu-toggle');
            const navLinksContainer = document.querySelector('.nav-links');

            menuToggle.addEventListener('click', function() {
                navLinksContainer.classList.toggle('active');
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.nav-links') && !e.target.closest('.menu-toggle')) {
                    navLinksContainer.classList.remove('active');
                }
            });

            // Handle window resize
            function handleResize() {
                if (window.innerWidth > 768) {
                    navLinksContainer.classList.remove('active');
                }
            }

            window.addEventListener('resize', handleResize);
            handleResize(); // Initial check
        });
    </script>
</body>
</html> 