<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Check if user is logged in and is a volunteer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'volunteer') {
    header("Location: login.html");
    exit;
}

$volunteer_id = $_SESSION['user_id'];
$volunteer_location = $_SESSION['location']; // Assuming location is stored in session

// Handle accepting or rejecting pickups
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pickup_id = intval($_POST['pickup_id']);
    $action = $_POST['action'];
    
    try {
        $pdo->beginTransaction();
        
        if ($action === 'accept') {
            // Check if pickup is still available
            $check_stmt = $pdo->prepare("
                SELECT p.id, d.id as donation_id, d.status as donation_status
                FROM pickups p
                JOIN donations d ON p.donation_id = d.id
                WHERE p.id = :pickup_id 
                AND p.status = 'pending'
                AND d.location = :location
            ");
            $check_stmt->execute([
                'pickup_id' => $pickup_id,
                'location' => $volunteer_location
            ]);
            
            $pickup = $check_stmt->fetch();
            if ($pickup) {
                try {
                // Update pickup status and assign volunteer
                    $update_pickup_stmt = $pdo->prepare("
                    UPDATE pickups 
                        SET status = 'pending', 
                            volunteer_id = :volunteer_id,
                            pickup_date = CURRENT_TIMESTAMP
                    WHERE id = :pickup_id
                        RETURNING id
                ");
                    $result = $update_pickup_stmt->execute([
                    'volunteer_id' => $volunteer_id,
                    'pickup_id' => $pickup_id
                    ]);

                    if ($result) {
                        // Update donation status to delivered
                        $update_donation_stmt = $pdo->prepare("
                            UPDATE donations 
                            SET status = 'delivered' 
                            WHERE id = :donation_id
                        ");
                        $update_donation_stmt->execute([
                            'donation_id' => $pickup['donation_id']
                ]);
                
                $pdo->commit();
                        $_SESSION['success_message'] = "Pickup task accepted successfully! The donation is now marked for delivery.";
                    } else {
                        throw new PDOException("Failed to update pickup status");
                    }
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $_SESSION['error_message'] = "Error accepting pickup: " . $e->getMessage();
                }
            } else {
                $pdo->rollBack();
                $_SESSION['error_message'] = "This pickup is no longer available or has already been assigned.";
            }
        } elseif ($action === 'reject') {
            // Update pickup status to pending and remove volunteer
            $update_stmt = $pdo->prepare("
                UPDATE pickups 
                SET status = 'pending', volunteer_id = NULL 
                WHERE id = :pickup_id AND volunteer_id = :volunteer_id
            ");
            $update_stmt->execute([
                'pickup_id' => $pickup_id,
                'volunteer_id' => $volunteer_id
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Pickup task rejected.";
        } elseif ($action === 'complete') {
            // Mark pickup as completed
            $update_stmt = $pdo->prepare("
                WITH updated_pickup AS (
                    UPDATE pickups
                    SET status = 'completed',
                        completion_date = NOW()
                    WHERE id = :pickup_id 
                    AND volunteer_id = :volunteer_id
                    RETURNING donation_id
                )
                UPDATE donations
                SET status = 'delivered'
                WHERE id IN (SELECT donation_id FROM updated_pickup)
            ");
            $update_stmt->execute([
                'pickup_id' => $pickup_id,
                'volunteer_id' => $volunteer_id
            ]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Pickup task completed successfully!";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "An error occurred. Please try again.";
    }
    
    header("Location: volunteer_dashboard.php");
    exit();
}

// Fetch available pickups in volunteer's location
try {
    $available_stmt = $pdo->prepare("
        SELECT p.id as pickup_id, d.*, u.first_name as donor_name, u.last_name as donor_lastname,
               r.first_name as recipient_name, r.last_name as recipient_lastname
        FROM pickups p
        JOIN donations d ON p.donation_id = d.id
        JOIN users u ON d.donor_id = u.id
        JOIN users r ON d.recipient_id = r.id
        WHERE d.location = :location 
        AND p.status = 'pending'
        AND p.volunteer_id IS NULL
        ORDER BY d.donation_date DESC
    ");
    $available_stmt->execute(['location' => $volunteer_location]);
    $available_pickups = $available_stmt->fetchAll();
    
    // Fetch assigned pickups for this volunteer
    $assigned_stmt = $pdo->prepare("
        SELECT p.id as pickup_id, d.*, u.first_name as donor_name, u.last_name as donor_lastname,
               r.first_name as recipient_name, r.last_name as recipient_lastname,
               p.status as pickup_status
        FROM pickups p
        JOIN donations d ON p.donation_id = d.id
        JOIN users u ON d.donor_id = u.id
        JOIN users r ON d.recipient_id = r.id
        WHERE p.volunteer_id = :volunteer_id
        AND p.status != 'completed'
        ORDER BY d.donation_date DESC
    ");
    $assigned_stmt->execute(['volunteer_id' => $volunteer_id]);
    $assigned_pickups = $assigned_stmt->fetchAll();
    
    // Fetch completed pickups
    $completed_stmt = $pdo->prepare("
        SELECT p.id as pickup_id, d.*, u.first_name as donor_name, u.last_name as donor_lastname,
               r.first_name as recipient_name, r.last_name as recipient_lastname,
               p.completion_date
        FROM pickups p
        JOIN donations d ON p.donation_id = d.id
        JOIN users u ON d.donor_id = u.id
        JOIN users r ON d.recipient_id = r.id
        WHERE p.volunteer_id = :volunteer_id
        AND p.status = 'completed'
        ORDER BY p.completion_date DESC
    ");
    $completed_stmt->execute(['volunteer_id' => $volunteer_id]);
    $completed_pickups = $completed_stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Fetch user profile
try {
    $profile_stmt = $pdo->prepare("
        SELECT 
            first_name,
            last_name,
            email,
            phone,
            location,
            to_char(created_at, 'Month YYYY') as member_since,
            user_type
        FROM users 
        WHERE id = :user_id
    ");
    
    $profile_stmt->execute(['user_id' => $volunteer_id]);
    $user_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_profile) {
        $_SESSION['error_message'] = "Failed to load user profile.";
        header("Location: logout.php");
        exit();
    }

    // Fetch volunteer statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN status = 'completed' THEN id END) as completed_pickups,
            COUNT(DISTINCT CASE WHEN status = 'pending' AND volunteer_id = :volunteer_id THEN id END) as active_pickups
        FROM pickups 
        WHERE volunteer_id = :volunteer_id
    ");
    
    $stats_stmt->execute(['volunteer_id' => $volunteer_id]);
    $volunteer_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Merge stats with profile
    $user_profile = array_merge($user_profile, $volunteer_stats);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $user_profile = [
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'phone' => $_SESSION['phone'] ?? '',
        'location' => $_SESSION['location'] ?? '',
        'member_since' => date('F Y'),
        'completed_pickups' => 0,
        'active_pickups' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Dashboard - FoodConnect</title>
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

        /* Main Content */
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

        .card {
            background: var(--background);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: var(--transition);
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
            color: var(--text);
            margin-bottom: 0.75rem;
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

        .button-group {
            display: grid;
            gap: 0.75rem;
            margin-top: 1.5rem;
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
            text-decoration: none;
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

        .accept-btn {
            background: var(--success);
            color: white;
        }

        .reject-btn {
            background: var(--error);
            color: white;
        }

        .complete-btn {
            background: var(--primary);
            color: white;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Messages */
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

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-pending {
            background: #fff7ed;
            color: #9a3412;
        }

        .status-assigned {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
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

        .menu-toggle {
            display: none;
        }

        .menu-toggle i {
            font-size: 1.5rem;
            color: var(--text);
        }

        /* Profile Tab */
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

        .card.shadow-sm {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .card-title {
            color: var(--text);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .profile-info-item {
            padding: 0.75rem;
            border-radius: 8px;
            transition: var(--transition);
        }

        .profile-info-item:hover {
            background: var(--background-alt);
        }

        .profile-info-item label {
            color: var(--text-light);
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.375rem;
            display: block;
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
            color: var(--text-light);
            font-size: 0.875rem;
            font-weight: 500;
        }

        .stat-item .h4 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
            margin: 0;
            display: flex;
            align-items: center;
        }

        .mt-4 {
            margin-top: 1.5rem;
        }

        .w-100 {
            width: 100%;
        }

        .me-2 {
            margin-right: 0.5rem;
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
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
    <div class="container">
            <a href="index.html" class="logo">FoodConnect</a>
            <div class="nav-links">
                <a href="#dashboard" class="nav-link active" data-tab="dashboard">Dashboard</a>
                <a href="#available" class="nav-link" data-tab="available">Available Pickups</a>
                <a href="#assigned" class="nav-link" data-tab="assigned">Assigned Pickups</a>
                <a href="#completed" class="nav-link" data-tab="completed">Completed Pickups</a>
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
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Volunteer'); ?></h1>
            <div class="location-info">
                <i class="fas fa-map-marker-alt"></i>
                <span><?php echo htmlspecialchars($_SESSION['location'] ?? 'Location not set'); ?></span>
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

        <!-- Dashboard Overview -->
        <div id="dashboard" class="tab-content active">
            <h2 class="section-title">
                <i class="fas fa-chart-pie"></i>
                Overview
            </h2>
            <div class="dashboard-grid">
                <div class="card">
                    <h4>
                        <i class="fas fa-box-open"></i>
                        Available Pickups
                    </h4>
                    <p class="stat"><?php echo count($available_pickups); ?> pickups in your area</p>
                </div>
                <div class="card">
                    <h4>
                        <i class="fas fa-clock"></i>
                        Assigned Pickups
                    </h4>
                    <p class="stat"><?php echo count($assigned_pickups); ?> pickups assigned to you</p>
                </div>
                <div class="card">
                    <h4>
                        <i class="fas fa-check-circle"></i>
                        Completed Pickups
                    </h4>
                    <p class="stat"><?php echo count($completed_pickups); ?> pickups completed</p>
                </div>
            </div>
        </div>

        <!-- Available Pickups -->
        <div id="available" class="tab-content">
            <h2 class="section-title">
                <i class="fas fa-box-open"></i>
                Available Pickups
            </h2>
            <div class="dashboard-grid">
            <?php if (empty($available_pickups)): ?>
                    <div class="card">
                        <p style="text-align: center;">No available pickups in your area at the moment.</p>
                    </div>
            <?php else: ?>
                <?php foreach ($available_pickups as $pickup): ?>
                    <div class="card">
                            <h4>
                                <i class="fas fa-box"></i>
                                <?php echo htmlspecialchars($pickup['food_item']); ?>
                            </h4>
                            <p>
                                <i class="fas fa-box"></i>
                                <strong>Quantity:</strong> <?php echo htmlspecialchars($pickup['quantity']); ?>
                            </p>
                            <p>
                                <i class="fas fa-map-marker-alt"></i>
                                <strong>Location:</strong> <?php echo htmlspecialchars($pickup['location']); ?>
                            </p>
                            <p>
                                <i class="fas fa-user"></i>
                                <strong>Donor:</strong> <?php echo htmlspecialchars($pickup['donor_name'] . ' ' . $pickup['donor_lastname']); ?>
                            </p>
                            <p>
                                <i class="fas fa-user"></i>
                                <strong>Recipient:</strong> <?php echo htmlspecialchars($pickup['recipient_name'] . ' ' . $pickup['recipient_lastname']); ?>
                            </p>
                        <div class="button-group">
                            <form method="POST">
                                    <input type="hidden" name="pickup_id" value="<?php echo $pickup['pickup_id']; ?>">
                                <input type="hidden" name="action" value="accept">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-check"></i>
                                        Accept Pickup
                                    </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- Assigned Pickups -->
        <div id="assigned" class="tab-content">
            <h2 class="section-title">
                <i class="fas fa-clock"></i>
                Assigned Pickups
            </h2>
            <div class="dashboard-grid">
            <?php if (empty($assigned_pickups)): ?>
                    <div class="card">
                        <p style="text-align: center;">You don't have any assigned pickups.</p>
                    </div>
            <?php else: ?>
                <?php foreach ($assigned_pickups as $pickup): ?>
                    <div class="card">
                            <h4>
                                <i class="fas fa-box"></i>
                                <?php echo htmlspecialchars($pickup['food_item']); ?>
                            </h4>
                            <p>
                                <i class="fas fa-box"></i>
                                <strong>Quantity:</strong> <?php echo htmlspecialchars($pickup['quantity']); ?>
                            </p>
                            <p>
                                <i class="fas fa-map-marker-alt"></i>
                                <strong>Location:</strong> <?php echo htmlspecialchars($pickup['location']); ?>
                            </p>
                            <p>
                                <i class="fas fa-user"></i>
                                <strong>Donor:</strong> <?php echo htmlspecialchars($pickup['donor_name'] . ' ' . $pickup['donor_lastname']); ?>
                            </p>
                            <p>
                                <i class="fas fa-user"></i>
                                <strong>Recipient:</strong> <?php echo htmlspecialchars($pickup['recipient_name'] . ' ' . $pickup['recipient_lastname']); ?>
                            </p>
                        <div class="button-group">
                            <form method="POST">
                                    <input type="hidden" name="pickup_id" value="<?php echo $pickup['pickup_id']; ?>">
                                <input type="hidden" name="action" value="complete">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-check-circle"></i>
                                        Mark as Completed
                                    </button>
                            </form>
                            <form method="POST">
                                    <input type="hidden" name="pickup_id" value="<?php echo $pickup['pickup_id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-times"></i>
                                        Reject Pickup
                                    </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- Completed Pickups -->
        <div id="completed" class="tab-content">
            <h2 class="section-title">
                <i class="fas fa-check-circle"></i>
                Completed Pickups
            </h2>
            <div class="dashboard-grid">
            <?php if (empty($completed_pickups)): ?>
                    <div class="card">
                        <p style="text-align: center;">You haven't completed any pickups yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($completed_pickups as $pickup): ?>
                        <div class="card">
                            <h4>
                                <i class="fas fa-box"></i>
                                <?php echo htmlspecialchars($pickup['food_item']); ?>
                            </h4>
                            <p>
                                <i class="fas fa-box"></i>
                                <strong>Quantity:</strong> <?php echo htmlspecialchars($pickup['quantity']); ?>
                            </p>
                            <p>
                                <i class="fas fa-map-marker-alt"></i>
                                <strong>Location:</strong> <?php echo htmlspecialchars($pickup['location']); ?>
                            </p>
                            <p>
                                <i class="fas fa-user"></i>
                                <strong>Donor:</strong> <?php echo htmlspecialchars($pickup['donor_name'] . ' ' . $pickup['donor_lastname']); ?>
                            </p>
                            <p>
                                <i class="fas fa-user"></i>
                                <strong>Recipient:</strong> <?php echo htmlspecialchars($pickup['recipient_name'] . ' ' . $pickup['recipient_lastname']); ?>
                            </p>
                            <p>
                                <i class="fas fa-calendar-check"></i>
                                <strong>Completed on:</strong> <?php echo htmlspecialchars($pickup['completion_date']); ?>
                            </p>
                            <span class="status-badge status-completed">Completed</span>
                    </div>
                <?php endforeach; ?>
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
                                                <?php echo ucfirst(htmlspecialchars($user_profile['user_type'] ?? 'volunteer')); ?>
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
                                    <label class="text-muted">Completed Pickups</label>
                                    <p class="h4">
                                        <i class="fas fa-check-circle me-2"></i>
                                        <?php echo intval($user_profile['completed_pickups']); ?>
                                    </p>
                                </div>
                                <div class="stat-item">
                                    <label class="text-muted">Active Pickups</label>
                                    <p class="h4">
                                        <i class="fas fa-clock me-2"></i>
                                        <?php echo intval($user_profile['active_pickups']); ?>
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