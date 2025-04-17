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

// Fetch user profile
try {
    $profile_stmt = $pdo->prepare("
        SELECT 
            first_name,
            last_name,
            email,
            phone,
            location,
            DATE_FORMAT(created_at, '%M %Y') as member_since
        FROM users 
        WHERE id = :user_id
    ");
    
    $profile_stmt->execute(['user_id' => $recipient_id]);
    $user_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_profile) {
        $_SESSION['error_message'] = "Failed to load user profile.";
        header("Location: logout.php");
        exit();
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $user_profile = [
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'phone' => $_SESSION['phone'] ?? '',
        'location' => $_SESSION['location'] ?? '',
        'member_since' => date('F Y')
    ];
}

// Fetch statistics
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_donations,
            COUNT(CASE WHEN p.status = 'completed' THEN 1 END) as completed_pickups
        FROM donations d
        LEFT JOIN pickups p ON d.id = p.donation_id
        WHERE d.recipient_id = :recipient_id
    ");
    
    $stats_stmt->execute(['recipient_id' => $recipient_id]);
    $donation_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $donation_stats = [
        'total_donations' => 0,
        'completed_pickups' => 0
    ];
}

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
        ");
        
        $result = $update_stmt->execute([
            'recipient_id' => $recipient_id,
            'donation_id' => $donation_id
        ]);
        
        if (!$result || $update_stmt->rowCount() === 0) {
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
                DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 7 DAY),
                DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 1 DAY)
            )
        ");
        
        $pickup_result = $pickup_stmt->execute(['donation_id' => $donation_id]);
        
        if (!$pickup_result) {
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
            --warning: #f59e0b;
            --transition: all 0.3s ease;
            --container-width: 1200px;
            --container-padding: 2rem;
            --card-gap: 1.5rem;
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
            padding-bottom: 2rem;
        }

        .container {
            width: 100%;
            max-width: var(--container-width);
            margin: 0 auto;
            padding: 0 var(--container-padding);
        }

        /* Main Content */
        .main-content {
            margin: 5rem auto 0;
            padding: 0 var(--container-padding);
            max-width: var(--container-width);
            width: 100%;
        }

        .welcome-header {
            margin-bottom: 2.5rem;
            padding: 1rem;
            background: var(--background);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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
            gap: var(--card-gap);
            margin-bottom: 2.5rem;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
            margin: 2.5rem 0 1.5rem;
            padding: 0 1rem;
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
            gap: 1rem;
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
            margin: 1rem auto;
            max-width: var(--container-width);
            width: calc(100% - 4rem);
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

        /* Profile Section */
        .profile-section {
            max-width: var(--container-width);
            margin: 0 auto;
            padding: 0 1rem;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: var(--card-gap);
        }

        .profile-card {
            padding: 1.5rem;
        }

        .profile-card h4 {
            font-size: 1.1rem;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border);
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-info-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0.75rem;
            background: var(--background-alt);
            border-radius: 6px;
            transition: var(--transition);
        }

        .profile-info-item:hover {
            transform: translateX(3px);
        }

        .profile-info-item i {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 4px;
            margin-right: 0.75rem;
            font-size: 0.875rem;
        }

        .profile-info-item .label {
            font-weight: 500;
            color: var(--text);
            width: 80px;
            font-size: 0.9rem;
        }

        .profile-info-item .value {
            color: var(--text-light);
            flex: 1;
            font-size: 0.9rem;
        }

        .profile-actions {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }

        .profile-actions .btn {
            width: 100%;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .stat-item {
            padding: 0.75rem;
            border-radius: 6px;
            background: var(--background-alt);
            margin-bottom: 0.75rem;
        }

        .stat-item label {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-bottom: 0.25rem;
            display: block;
        }

        .stat-item .value {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
        }

        /* Footer */
        .footer {
            margin-top: auto;
            background: var(--background);
            border-top: 1px solid var(--border);
            padding: 3rem 0 1.5rem;
        }

        .footer .container {
            padding: 0 var(--container-padding);
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--card-gap);
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

        /* Responsive Design */
        @media (max-width: 768px) {
            :root {
                --container-padding: 1rem;
                --card-gap: 1rem;
            }

            .main-content {
                margin-top: 4rem;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .welcome-header {
                margin-bottom: 2rem;
            }

            .footer {
                padding: 2rem 0 1rem;
            }
        }

        @media (min-width: 1400px) {
            :root {
                --container-width: 1320px;
            }
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
            height: 4.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .navbar .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            height: 100%;
        }

        .logo {
            font-size: 1.5rem;
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
            font-size: 1rem;
            transition: var(--transition);
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

        .menu-toggle {
            display: none;
            cursor: pointer;
            padding: 0.5rem;
            background: none;
            border: none;
            color: var(--text);
        }

        .menu-toggle i {
            font-size: 1.25rem;
        }

        @media (max-width: 768px) {
            .nav-links {
                display: none;
                position: fixed;
                top: 3.5rem;
                left: 0;
                width: 100%;
                background: var(--background);
                flex-direction: column;
                padding: 0;
                border-bottom: 1px solid var(--border);
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            }

            .nav-links.active {
                display: flex;
            }

            .nav-link {
                width: 100%;
                padding: 1rem 1.5rem;
                border-bottom: 1px solid var(--border);
                border-radius: 0;
            }

            .nav-link:last-child {
                border-bottom: none;
            }

            .menu-toggle {
                display: block;
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
                            <span class="status-badge <?php 
                                if ($donation['pickup_status'] === 'completed') {
                                    echo 'status-completed';
                                } elseif ($donation['donation_status'] === 'in_transit') {
                                    echo 'status-assigned';
                                } else {
                                    echo 'status-pending';
                                }
                            ?>">
                                <?php 
                                    if ($donation['pickup_status'] === 'completed') {
                                        echo 'Completed';
                                    } elseif ($donation['donation_status'] === 'in_transit') {
                                        echo 'In Transit';
                                    } else {
                                        echo ucfirst(htmlspecialchars($donation['pickup_status'] ?? 'pending')); 
                                    }
                                ?>
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
                <div class="profile-grid">
                    <div class="card profile-card">
                        <h4><i class="fas fa-user-circle"></i> Personal Information</h4>
                        <div class="profile-info-item">
                            <i class="fas fa-user"></i>
                            <span class="label">Name</span>
                            <span class="value"><?php echo htmlspecialchars($user_profile['first_name'] . ' ' . $user_profile['last_name']); ?></span>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-envelope"></i>
                            <span class="label">Email</span>
                            <span class="value"><?php echo htmlspecialchars($user_profile['email']); ?></span>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-phone"></i>
                            <span class="label">Phone</span>
                            <span class="value"><?php echo htmlspecialchars($user_profile['phone'] ?? 'Not set'); ?></span>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <span class="label">Location</span>
                            <span class="value"><?php echo htmlspecialchars($user_profile['location'] ?? 'Not set'); ?></span>
                        </div>
                        <div class="profile-info-item">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="label">Member Since</span>
                            <span class="value"><?php echo htmlspecialchars($user_profile['member_since']); ?></span>
                        </div>
                        <div class="profile-actions">
                            <a href="edit_profile.php" class="btn btn-primary">
                                <i class="fas fa-edit"></i>
                                Edit Profile
                            </a>
                        </div>
                    </div>
                    <div class="card">
                        <h4>Activity Overview</h4>
                        <div class="stat-item">
                            <label>Total Accepted Donations</label>
                            <p class="value"><?php echo intval($donation_stats['total_donations']); ?></p>
                        </div>
                        <div class="stat-item">
                            <label>Completed Pickups</label>
                            <p class="value"><?php echo intval($donation_stats['completed_pickups']); ?></p>
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