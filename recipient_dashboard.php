<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Check if user is logged in and is a recipient
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'recipient') {
    header("Location: login.html");
    exit;
}

$recipient_id = $_SESSION['user_id'];
$recipient_location = $_SESSION['location']; // Assuming location is stored in session

// Handle accepting a donation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['accept_donation'])) {
    $donation_id = intval($_POST['donation_id']);
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Check if donation is still available
        $check_stmt = $pdo->prepare("SELECT status FROM donations WHERE id = :donation_id AND status = 'available'");
        $check_stmt->execute(['donation_id' => $donation_id]);
        
        if ($check_stmt->fetch()) {
            // Update donation status
            $update_stmt = $pdo->prepare("UPDATE donations SET status = 'accepted', recipient_id = :recipient_id WHERE id = :donation_id");
            $update_stmt->execute([
                'recipient_id' => $recipient_id,
                'donation_id' => $donation_id
            ]);
            
            // Create pickup request
            $pickup_stmt = $pdo->prepare("INSERT INTO pickups (donation_id, status, pickup_date) VALUES (:donation_id, 'pending', NOW())");
            $pickup_stmt->execute(['donation_id' => $donation_id]);
            
            $pdo->commit();
            $_SESSION['success_message'] = "Donation accepted successfully! A volunteer will be assigned for pickup.";
        } else {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Sorry, this donation is no longer available.";
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error_message'] = "An error occurred. Please try again.";
    }
    
    header("Location: recipient_dashboard.php");
    exit();
}

// Fetch available donations in recipient's location
try {
    $stmt = $pdo->prepare("
        SELECT d.*, u.first_name, u.last_name 
        FROM donations d
        JOIN users u ON d.donor_id = u.id
        WHERE d.location = :location 
        AND d.status = 'available'
        ORDER BY d.donation_date DESC
    ");
    $stmt->execute(['location' => $recipient_location]);
    $available_donations = $stmt->fetchAll();
    
    // Fetch accepted donations by this recipient
    $accepted_stmt = $pdo->prepare("
        SELECT d.*, u.first_name, u.last_name, p.status as pickup_status
        FROM donations d
        JOIN users u ON d.donor_id = u.id
        LEFT JOIN pickups p ON d.id = p.donation_id
        WHERE d.recipient_id = :recipient_id
        ORDER BY d.donation_date DESC
    ");
    $accepted_stmt->execute(['recipient_id' => $recipient_id]);
    $accepted_donations = $accepted_stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recipient Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        h2, h3 {
            color: #2d3748;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 600;
        }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .card h4 {
            color: #2d3748;
            margin-bottom: 15px;
            font-size: 1.2em;
        }

        .card p {
            color: #4a5568;
            margin-bottom: 10px;
            font-size: 0.95em;
        }

        .card button {
            width: 100%;
            padding: 12px;
            background: #4299e1;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .card button:hover {
            background: #3182ce;
        }

        .card button:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
        }

        .logout {
            display: inline-block;
            margin-top: 30px;
            padding: 12px 25px;
            background: #fc8181;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .logout:hover {
            background: #f56565;
        }

        @media (max-width: 768px) {
            .container { padding: 20px; }
            .card-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Welcome to Your Dashboard</h2>

        <?php if (isset($_SESSION['success_message'])): ?>
            <p class="message success"><?= htmlspecialchars($_SESSION['success_message']); ?></p>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <p class="message error"><?= htmlspecialchars($_SESSION['error_message']); ?></p>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <h3>Available Donations in Your Area</h3>
        <div class="card-grid">
            <?php if (empty($available_donations)): ?>
                <p style="text-align: center; grid-column: 1/-1;">No available donations in your area at the moment.</p>
            <?php else: ?>
                <?php foreach ($available_donations as $donation): ?>
                    <div class="card">
                        <h4><?= htmlspecialchars($donation['food_item']) ?></h4>
                        <p><strong>Quantity:</strong> <?= htmlspecialchars($donation['quantity']) ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($donation['location']) ?></p>
                        <p><strong>Donor:</strong> <?= htmlspecialchars($donation['first_name'] . ' ' . $donation['last_name']) ?></p>
                        <p><strong>Expiry Date:</strong> <?= htmlspecialchars($donation['expiry_date']) ?></p>
                        <form method="POST">
                            <input type="hidden" name="donation_id" value="<?= $donation['id'] ?>">
                            <button type="submit" name="accept_donation">Accept Donation</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h3>Your Accepted Donations</h3>
        <div class="card-grid">
            <?php if (empty($accepted_donations)): ?>
                <p style="text-align: center; grid-column: 1/-1;">You haven't accepted any donations yet.</p>
            <?php else: ?>
                <?php foreach ($accepted_donations as $donation): ?>
                    <div class="card">
                        <h4><?= htmlspecialchars($donation['food_item']) ?></h4>
                        <p><strong>Quantity:</strong> <?= htmlspecialchars($donation['quantity']) ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($donation['location']) ?></p>
                        <p><strong>Donor:</strong> <?= htmlspecialchars($donation['first_name'] . ' ' . $donation['last_name']) ?></p>
                        <p><strong>Status:</strong> <?= htmlspecialchars($donation['pickup_status'] ?? 'Pending pickup') ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <a href="logout.php" class="logout">Logout</a>
    </div>
</body>
</html> 