<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Set PDO attributes for better error handling
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);

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
        // Ensure we're not in a transaction
        while ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        // Start new transaction
        $pdo->beginTransaction();
        error_log("Starting new transaction for action: " . $action);

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
                        SET status = 'assigned', 
                            volunteer_id = :volunteer_id,
                            pickup_date = CURRENT_TIMESTAMP
                    WHERE id = :pickup_id
                ");
                    $result = $update_pickup_stmt->execute([
                    'volunteer_id' => $volunteer_id,
                    'pickup_id' => $pickup_id
                    ]);

                    if ($result && $update_pickup_stmt->rowCount() > 0) {
                        // Update donation status to in_transit
                        $update_donation_stmt = $pdo->prepare("
                            UPDATE donations 
                            SET status = 'in_transit' 
                            WHERE id = :donation_id
                        ");
                        $update_donation_stmt->execute([
                            'donation_id' => $pickup['donation_id']
                        ]);
                
                        $pdo->commit();
                        $_SESSION['success_message'] = "Pickup task accepted successfully! The donation is now in transit.";
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
            
            $_SESSION['success_message'] = "Pickup task rejected.";
        } elseif ($action === 'complete') {
            // First, get the donation_id and current status
            $get_donation_stmt = $pdo->prepare("
                SELECT p.donation_id, d.status as donation_status, p.status as pickup_status
                FROM pickups p
                JOIN donations d ON p.donation_id = d.id
                WHERE p.id = :pickup_id 
                AND p.volunteer_id = :volunteer_id
                FOR UPDATE
            ");
            $get_donation_stmt->execute([
                'pickup_id' => $pickup_id,
                'volunteer_id' => $volunteer_id
            ]);
            
            $pickup_data = $get_donation_stmt->fetch(PDO::FETCH_ASSOC);
            error_log("Pickup data: " . print_r($pickup_data, true));
            
            if (!$pickup_data) {
                throw new Exception("Pickup not found");
            }

            if ($pickup_data['pickup_status'] === 'completed') {
                throw new Exception("This pickup is already marked as completed");
            }

            if ($pickup_data['donation_status'] === 'delivered') {
                throw new Exception("This donation is already marked as delivered");
            }

            // Update pickup status
            error_log("Updating pickup status for pickup_id: " . $pickup_id);
            $update_pickup_stmt = $pdo->prepare("
                UPDATE pickups
                SET status = 'completed',
                    completion_date = NOW()
                WHERE id = :pickup_id 
                AND volunteer_id = :volunteer_id
                AND status != 'completed'
            ");
            
            if (!$update_pickup_stmt->execute([
                'pickup_id' => $pickup_id,
                'volunteer_id' => $volunteer_id
            ])) {
                throw new Exception("Failed to execute pickup status update");
            }

            if ($update_pickup_stmt->rowCount() === 0) {
                throw new Exception("Failed to update pickup status - no rows affected");
            }
            error_log("Successfully updated pickup status");

            // Update donation status
            error_log("Updating donation status for donation_id: " . $pickup_data['donation_id']);
            $update_donation_stmt = $pdo->prepare("
                UPDATE donations
                SET status = 'delivered'
                WHERE id = :donation_id
                AND status != 'delivered'
            ");
            
            if (!$update_donation_stmt->execute([
                'donation_id' => $pickup_data['donation_id']
            ])) {
                throw new Exception("Failed to execute donation status update");
            }

            if ($update_donation_stmt->rowCount() === 0) {
                throw new Exception("Could not update donation status - donation may be in an invalid state");
            }

            error_log("Successfully updated donation status");
            $_SESSION['success_message'] = "Pickup task completed successfully!";
        }
        
        // Commit the transaction
        if ($pdo->inTransaction()) {
            $pdo->commit();
            error_log("Transaction committed successfully");
        }
        
    } catch (Exception $e) {
        error_log("Error occurred: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        // Rollback if we're in a transaction
        if ($pdo->inTransaction()) {
            error_log("Rolling back transaction");
            $pdo->rollBack();
        }
        
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
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
               p.status as pickup_status, d.status as donation_status
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
               p.completion_date, p.status as pickup_status, d.status as donation_status
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
            DATE_FORMAT(created_at, '%M %Y') as member_since
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

// Fetch volunteer statistics
try {
    // Get total pickups
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM pickups WHERE volunteer_id = ?");
    $total_stmt->execute([$volunteer_id]);
    $total_pickups = $total_stmt->fetchColumn();

    // Get completed pickups
    $completed_stmt = $pdo->prepare("SELECT COUNT(*) FROM pickups WHERE volunteer_id = ? AND status = 'completed'");
    $completed_stmt->execute([$volunteer_id]);
    $completed_pickups_count = $completed_stmt->fetchColumn();

    // Get active (assigned) pickups
    $active_stmt = $pdo->prepare("SELECT COUNT(*) FROM pickups WHERE volunteer_id = ? AND status != 'completed'");
    $active_stmt->execute([$volunteer_id]);
    $active_pickups_count = $active_stmt->fetchColumn();

    // Debug logging
    error_log("Volunteer ID: " . $volunteer_id);
    error_log("Total pickups: " . $total_pickups);
    error_log("Completed pickups: " . $completed_pickups_count);
    error_log("Active pickups: " . $active_pickups_count);

    // Double check assigned pickups count directly
    $check_stmt = $pdo->prepare("
        SELECT p.id, p.status, d.status as donation_status 
        FROM pickups p 
        JOIN donations d ON p.donation_id = d.id 
        WHERE p.volunteer_id = ? AND p.status != 'completed'
    ");
    $check_stmt->execute([$volunteer_id]);
    $active_details = $check_stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Active pickups details: " . print_r($active_details, true));

    $volunteer_stats = [
        'total_pickups' => $total_pickups,
        'completed_pickups' => $completed_pickups_count,
        'active_pickups' => $active_pickups_count
    ];

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $volunteer_stats = [
        'total_pickups' => 0,
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
            display: flex;
            align-items: center;
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
            background: var(--success);
            color: white;
            width: 100%;
            margin-bottom: 0.5rem;
        }

        .complete-btn:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .reject-btn {
            background: var(--error);
            color: white;
            width: 100%;
        }

        .reject-btn:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Messages */
        .message {
            max-width: var(--container-width);
            width: calc(100% - 4rem);
            padding: 1rem 1.5rem;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 0.95rem;
            line-height: 1.5;
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
        }

        @media (min-width: 1400px) {
            :root {
                --container-width: 1320px;
            }
        }

        /* Profile Section Styles */
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
            padding: 1rem;
            border-radius: 8px;
            background: var(--background-alt);
            margin-bottom: 1rem;
            border: 1px solid var(--border);
            transition: var(--transition);
        }

        .stat-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .stat-item label {
            font-size: 0.875rem;
            color: var(--text-light);
            margin-bottom: 0.5rem;
            display: block;
            font-weight: 500;
        }

        .stat-item .value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
            margin: 0;
        }

        .stat-item i {
            color: var(--primary);
            margin-right: 0.5rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--background);
            padding: 2rem;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .modal-content h3 {
            margin-bottom: 1rem;
            color: var(--text);
            font-size: 1.25rem;
            font-weight: 600;
        }

        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        .btn-secondary {
            background: var(--background-alt);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-left: 1rem;
            gap: 0.375rem;
        }

        /* Add footer styles */
        .footer {
            margin-top: 1rem;
            background: var(--background);
            border-top: 1px solid var(--border);
            padding: 3rem 0 1.5rem;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }

        .footer-section h3 {
            margin-bottom: 15px;
            font-size: 1.2rem;
        }

        .footer-section ul {
            list-style: none;
            padding: 0;
        }

        .footer-section ul li {
            margin-bottom: 8px;
        }

        .footer-section ul li a {
            color: #333;
            text-decoration: none;
            transition: color 0.3s;
        }

        .footer-section ul li a:hover {
            color: #4CAF50;
        }

        .footer-bottom {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(63, 63, 63, 0.1);
        }

        .footer-bottom p {
            margin: 0;
            font-size: 0.9rem;
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
            <h1>
                Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Volunteer'); ?>
                <span class="role-badge">
                    <i class="fas fa-user-tag"></i>
                    Volunteer
                </span>
            </h1>
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
                            <p>
                                <i class="fas fa-info-circle"></i>
                                <strong>Status:</strong> 
                                <?php if ($pickup['donation_status'] === 'delivered'): ?>
                                    <span class="status-badge status-completed">Delivered</span>
                                <?php elseif ($pickup['donation_status'] === 'in_transit'): ?>
                                    <span class="status-badge status-assigned">In Transit</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending"><?php echo ucfirst(htmlspecialchars($pickup['donation_status'])); ?></span>
                                <?php endif; ?>
                            </p>
                        <div class="button-group">
                            <?php if ($pickup['donation_status'] !== 'delivered'): ?>
                                <button type="button" class="btn complete-pickup-btn" data-pickup-id="<?php echo $pickup['pickup_id']; ?>">
                                    <i class="fas fa-check-circle"></i>
                                    Mark as Completed
                                </button>
                            <?php endif; ?>
                            <form method="POST">
                                <input type="hidden" name="pickup_id" value="<?php echo $pickup['pickup_id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="btn reject-btn">
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
                            <p>
                                <i class="fas fa-info-circle"></i>
                                <strong>Status:</strong>
                                <span class="status-badge status-completed">Completed</span>
                            </p>
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
                        <h4><i class="fas fa-chart-line"></i> Activity Overview</h4>
                        <div class="stat-item">
                            <label><i class="fas fa-box"></i>Total Pickups</label>
                            <p class="value"><?php echo intval($volunteer_stats['total_pickups']); ?></p>
                        </div>
                        <div class="stat-item">
                            <label><i class="fas fa-check-circle"></i>Completed Pickups</label>
                            <p class="value"><?php echo intval($volunteer_stats['completed_pickups']); ?></p>
                        </div>
                        <div class="stat-item">
                            <label><i class="fas fa-clock"></i>Active Pickups</label>
                            <p class="value"><?php echo intval($volunteer_stats['active_pickups']); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Completion Modal -->
    <div id="completionModal" class="modal">
        <div class="modal-content">
            <h3>Complete Pickup</h3>
            <p>Are you sure you want to mark this pickup as completed?</p>
            <form id="completionForm" method="POST">
                <input type="hidden" name="pickup_id" id="completionPickupId">
                <input type="hidden" name="action" value="complete">
                <div class="button-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i>
                        Confirm Completion
                    </button>
                    <button type="button" class="btn btn-secondary close-modal">
                        <i class="fas fa-times"></i>
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h3>About FoodConnect</h3>
                    Connecting food donors with those in need, reducing waste and helping communities.
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
                    <ul>
                        <li><a href="mailto:info@foodconnect.org">Email: info@foodconnect.org</a></li>
                        <li><a href="tel:+919999999999">Phone: +91 9999999999</a></li>
                    </ul>
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

            // Modal functionality
            const modal = document.getElementById('completionModal');
            const completionForm = document.getElementById('completionForm');
            const completionPickupId = document.getElementById('completionPickupId');
            
            // Open modal when clicking complete button
            document.querySelectorAll('.complete-pickup-btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const pickupId = this.getAttribute('data-pickup-id');
                    completionPickupId.value = pickupId;
                    modal.classList.add('active');
                });
            });

            // Close modal when clicking cancel or outside
            document.querySelectorAll('.close-modal').forEach(button => {
                button.addEventListener('click', () => {
                    modal.classList.remove('active');
                });
            });

            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });

            // Handle form submission
            completionForm.addEventListener('submit', function(e) {
                // Form will submit normally, modal will close on page reload
                modal.classList.remove('active');
            });
        });
    </script>
</body>
</html> 