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
                SELECT p.id 
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
            
            if ($check_stmt->fetch()) {
                // Update pickup status and assign volunteer
                $update_stmt = $pdo->prepare("
                    UPDATE pickups 
                    SET status = 'assigned', volunteer_id = :volunteer_id 
                    WHERE id = :pickup_id
                ");
                $update_stmt->execute([
                    'volunteer_id' => $volunteer_id,
                    'pickup_id' => $pickup_id
                ]);
                
                $pdo->commit();
                $_SESSION['success_message'] = "Pickup task accepted successfully!";
            } else {
                $pdo->rollBack();
                $_SESSION['error_message'] = "This pickup is no longer available.";
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Dashboard</title>
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

        .button-group {
            display: grid;
            gap: 10px;
            margin-top: 15px;
        }

        .button-group form {
            margin: 0;
        }

        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .accept-btn {
            background: #48bb78;
            color: white;
        }

        .reject-btn {
            background: #f56565;
            color: white;
        }

        .complete-btn {
            background: #4299e1;
            color: white;
        }

        button:hover {
            opacity: 0.9;
            transform: translateY(-2px);
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

        <h3>Available Pickups in Your Area</h3>
        <div class="card-grid">
            <?php if (empty($available_pickups)): ?>
                <p style="text-align: center; grid-column: 1/-1;">No available pickups in your area at the moment.</p>
            <?php else: ?>
                <?php foreach ($available_pickups as $pickup): ?>
                    <div class="card">
                        <h4><?= htmlspecialchars($pickup['food_item']) ?></h4>
                        <p><strong>Quantity:</strong> <?= htmlspecialchars($pickup['quantity']) ?></p>
                        <p><strong>Pickup Location:</strong> <?= htmlspecialchars($pickup['location']) ?></p>
                        <p><strong>Donor:</strong> <?= htmlspecialchars($pickup['donor_name'] . ' ' . $pickup['donor_lastname']) ?></p>
                        <p><strong>Recipient:</strong> <?= htmlspecialchars($pickup['recipient_name'] . ' ' . $pickup['recipient_lastname']) ?></p>
                        <div class="button-group">
                            <form method="POST">
                                <input type="hidden" name="pickup_id" value="<?= $pickup['pickup_id'] ?>">
                                <input type="hidden" name="action" value="accept">
                                <button type="submit" class="accept-btn">Accept Pickup</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h3>Your Assigned Pickups</h3>
        <div class="card-grid">
            <?php if (empty($assigned_pickups)): ?>
                <p style="text-align: center; grid-column: 1/-1;">You don't have any assigned pickups.</p>
            <?php else: ?>
                <?php foreach ($assigned_pickups as $pickup): ?>
                    <div class="card">
                        <h4><?= htmlspecialchars($pickup['food_item']) ?></h4>
                        <p><strong>Quantity:</strong> <?= htmlspecialchars($pickup['quantity']) ?></p>
                        <p><strong>Pickup Location:</strong> <?= htmlspecialchars($pickup['location']) ?></p>
                        <p><strong>Donor:</strong> <?= htmlspecialchars($pickup['donor_name'] . ' ' . $pickup['donor_lastname']) ?></p>
                        <p><strong>Recipient:</strong> <?= htmlspecialchars($pickup['recipient_name'] . ' ' . $pickup['recipient_lastname']) ?></p>
                        <div class="button-group">
                            <form method="POST">
                                <input type="hidden" name="pickup_id" value="<?= $pickup['pickup_id'] ?>">
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" class="complete-btn">Mark as Completed</button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="pickup_id" value="<?= $pickup['pickup_id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="reject-btn">Reject Pickup</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <h3>Completed Pickups</h3>
        <div class="card-grid">
            <?php if (empty($completed_pickups)): ?>
                <p style="text-align: center; grid-column: 1/-1;">You haven't completed any pickups yet.</p>
            <?php else: ?>
                <?php foreach ($completed_pickups as $pickup): ?>
                    <div class="card">
                        <h4><?= htmlspecialchars($pickup['food_item']) ?></h4>
                        <p><strong>Quantity:</strong> <?= htmlspecialchars($pickup['quantity']) ?></p>
                        <p><strong>Location:</strong> <?= htmlspecialchars($pickup['location']) ?></p>
                        <p><strong>Donor:</strong> <?= htmlspecialchars($pickup['donor_name'] . ' ' . $pickup['donor_lastname']) ?></p>
                        <p><strong>Recipient:</strong> <?= htmlspecialchars($pickup['recipient_name'] . ' ' . $pickup['recipient_lastname']) ?></p>
                        <p><strong>Completed on:</strong> <?= htmlspecialchars($pickup['completion_date']) ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <a href="logout.php" class="logout">Logout</a>
    </div>
</body>
</html> 