<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Check if user is logged in and is a volunteer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'volunteer') {
    header("Location: login.html");
    exit;
}

$volunteer_id = $_SESSION['user_id'];

// Ensure location is set in session
if (!isset($_SESSION['location'])) {
    try {
        $stmt = $pdo->prepare("SELECT location FROM users WHERE id = :volunteer_id");
        $stmt->execute(['volunteer_id' => $volunteer_id]);
        $user = $stmt->fetch();
        $_SESSION['location'] = $user['location'] ?? '';
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}
$user_location = $_SESSION['location'];

// Fetch assigned pickup tasks
try {
    $stmt = $pdo->prepare("SELECT p.id AS pickup_id, d.food_item, d.quantity, d.location, d.donation_date, p.status, u.first_name, u.last_name, p.volunteer_id 
                           FROM pickups p 
                           JOIN donations d ON p.donation_id = d.id 
                           JOIN users u ON d.donor_id = u.id 
                           WHERE (p.volunteer_id = :volunteer_id AND p.status IN ('scheduled', 'pending'))
                              OR (p.volunteer_id IS NULL AND p.status = 'pending' AND d.location = :location)
                           ORDER BY d.donation_date DESC");
    $stmt->execute(['volunteer_id' => $volunteer_id, 'location' => $user_location]);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Handle pickup actions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['complete_pickup'])) {
        $pickup_id = $_POST['pickup_id'];
        try {
            $pdo->beginTransaction();
            
            // Update pickup status
            $update_stmt = $pdo->prepare("UPDATE pickups SET status = 'completed' WHERE id = :pickup_id AND volunteer_id = :volunteer_id");
            $update_stmt->execute(['pickup_id' => $pickup_id, 'volunteer_id' => $volunteer_id]);
            
            // Update donation status
            $update_donation_stmt = $pdo->prepare("UPDATE donations SET status = 'fulfilled' WHERE id = (SELECT donation_id FROM pickups WHERE id = :pickup_id)");
            $update_donation_stmt->execute(['pickup_id' => $pickup_id]);
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Pickup task marked as completed!";
            header("Location: volunteer_dashboard.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            die("Database error: " . $e->getMessage());
        }
    } elseif (isset($_POST['accept_pickup'])) {
        $pickup_id = $_POST['pickup_id'];
        try {
            $accept_stmt = $pdo->prepare("UPDATE pickups SET volunteer_id = :volunteer_id, status = 'scheduled' WHERE id = :pickup_id AND volunteer_id IS NULL");
            $accept_stmt->execute(['pickup_id' => $pickup_id, 'volunteer_id' => $volunteer_id]);
            $_SESSION['success_message'] = "Pickup task accepted!";
            header("Location: volunteer_dashboard.php");
            exit();
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
    } elseif (isset($_POST['reject_pickup'])) {
        $pickup_id = $_POST['pickup_id'];
        try {
            $reject_stmt = $pdo->prepare("UPDATE pickups SET volunteer_id = NULL, status = 'pending' WHERE id = :pickup_id AND volunteer_id = :volunteer_id");
            $reject_stmt->execute(['pickup_id' => $pickup_id, 'volunteer_id' => $volunteer_id]);
            $_SESSION['success_message'] = "Pickup task rejected!";
            header("Location: volunteer_dashboard.php");
            exit();
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
    }
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
            max-width: 1000px;
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

        h2 {
            font-size: 32px;
            margin-bottom: 30px;
        }

        h3 {
            font-size: 24px;
            margin-top: 40px;
        }

        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: 500;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .task-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .task-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
        }

        .task-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .task-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .task-title {
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
        }

        .task-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-scheduled {
            background: #cce5ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .task-details {
            margin-bottom: 20px;
        }

        .task-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: #4a5568;
        }

        .task-actions {
            display: flex;
            gap: 12px;
        }

        button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        button[name="accept_pickup"] {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
        }

        button[name="complete_pickup"] {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
        }

        button[name="reject_pickup"] {
            background: linear-gradient(135deg, #fc8181 0%, #f56565 100%);
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        button:active {
            transform: translateY(0);
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
            text-align: center;
        }

        .logout:hover {
            background: #f56565;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(252, 129, 129, 0.4);
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .task-grid {
                grid-template-columns: 1fr;
            }

            .task-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Pickup Tasks</h2>

        <?php if (isset($_SESSION['success_message'])): ?>
            <p class="message success"><?= $_SESSION['success_message']; ?></p>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <p class="message error"><?= $_SESSION['error_message']; ?></p>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (!empty($tasks)): ?>
            <div class="task-grid">
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card">
                        <div class="task-header">
                            <h3 class="task-title"><?= htmlspecialchars($task['food_item']) ?></h3>
                            <span class="task-status status-<?= strtolower($task['status']) ?>">
                                <?= htmlspecialchars($task['status']) ?>
                            </span>
                        </div>
                        <div class="task-details">
                            <div class="task-detail">
                                <strong>Quantity:</strong> <?= htmlspecialchars($task['quantity']) ?>
                            </div>
                            <div class="task-detail">
                                <strong>Location:</strong> <?= htmlspecialchars($task['location']) ?>
                            </div>
                            <div class="task-detail">
                                <strong>Donor:</strong> <?= htmlspecialchars($task['first_name'] . ' ' . $task['last_name']) ?>
                            </div>
                        </div>
                        <div class="task-actions">
                            <?php if ($task['status'] === 'pending' && $task['volunteer_id'] === null): ?>
                                <form method="POST" style="width: 100%;">
                                    <input type="hidden" name="pickup_id" value="<?= $task['pickup_id'] ?>">
                                    <button type="submit" name="accept_pickup">Accept</button>
                                </form>
                            <?php elseif ($task['status'] !== 'completed' && $task['volunteer_id'] == $volunteer_id): ?>
                                <form method="POST" style="width: 100%; display: flex; gap: 12px;">
                                    <input type="hidden" name="pickup_id" value="<?= $task['pickup_id'] ?>">
                                    <button type="submit" name="complete_pickup">Complete</button>
                                    <button type="submit" name="reject_pickup">Reject</button>
                                </form>
                            <?php else: ?>
                                <span class="task-status status-completed">Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="task-card" style="text-align: center; padding: 40px;">
                <h3 style="color: #4a5568; font-size: 18px;">No pickup tasks available at the moment.</h3>
            </div>
        <?php endif; ?>
        
        <a href="logout.php" class="logout">Logout</a>
    </div>
</body>
</html>
