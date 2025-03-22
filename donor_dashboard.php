<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Check if user is logged in and is a donor
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'donor') {
    header("Location: login.html");
    exit;
}

$donor_id = $_SESSION['user_id']; // Get logged-in donor ID

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_donation'])) {
    $food_item = trim($_POST['food_item']);
    $quantity = intval($_POST['quantity']);
    $location = trim($_POST['location']);
    $expiry_date = $_POST['expiry_date'];

    if (!empty($food_item) && !empty($quantity) && !empty($location)) {
        try {
            // Insert new donation with initial status as 'available'
            $stmt = $pdo->prepare("INSERT INTO donations (donor_id, food_item, quantity, location, expiry_date, status, donation_date) 
                                 VALUES (:donor_id, :food_item, :quantity, :location, :expiry_date, 'available', NOW())");
            $stmt->execute([
                'donor_id' => $donor_id,
                'food_item' => $food_item,
                'quantity' => $quantity,
                'location' => $location,
                'expiry_date' => $expiry_date
            ]);

            $_SESSION['success_message'] = "Donation added successfully! It will be visible to recipients in your area.";
            
            // Prevent form resubmission issue by redirecting
            header("Location: donor_dashboard.php");
            exit();
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
    } else {
        $_SESSION['error_message'] = "All fields are required!";
    }
}

// Fetch donor's donations
try {
    $stmt = $pdo->prepare("SELECT * FROM donations WHERE donor_id = :donor_id ORDER BY donation_date DESC");
    $stmt->execute(['donor_id' => $donor_id]);
    $donations = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Dashboard</title>
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

        form {
            background: #f8fafc;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            display: grid;
            gap: 15px;
            max-width: 600px;
            margin: 0 auto;
        }

        input {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
        }

        input:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.1);
        }

        button {
            background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%);
            color: white;
            padding: 14px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(66, 153, 225, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            background: white;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #4299e1;
            color: white;
            font-weight: 500;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background: #f7fafc;
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

            table {
                display: block;
                overflow-x: auto;
            }

            form {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Your Donations</h2>

        <?php if (isset($_SESSION['success_message'])): ?>
            <p class="message success"><?= $_SESSION['success_message']; ?></p>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <p class="message error"><?= $_SESSION['error_message']; ?></p>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <h3>Add a Donation</h3>
        <form method="POST">
            <input type="text" name="food_item" placeholder="Food Item" required>
            <input type="number" name="quantity" min="1" placeholder="Quantity" required>
            <input type="text" name="location" placeholder="Pickup Location" required>
            <input type="date" name="expiry_date">
            <button type="submit" name="submit_donation">Submit Donation</button>
        </form>

        <?php if (count($donations) > 0): ?>
            <h3>Previous Donations</h3>
            <table>
                <tr>
                    <th>ID</th>
                    <th>Food Item</th>
                    <th>Quantity</th>
                    <th>Location</th>
                    <th>Expiry Date</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
                <?php foreach ($donations as $donation): ?>
                    <tr>
                        <td><?= htmlspecialchars($donation['id']) ?></td>
                        <td><?= htmlspecialchars($donation['food_item']) ?></td>
                        <td><?= htmlspecialchars($donation['quantity']) ?></td>
                        <td><?= htmlspecialchars($donation['location']) ?></td>
                        <td><?= htmlspecialchars($donation['expiry_date']) ?></td>
                        <td><?= htmlspecialchars($donation['status']) ?></td>
                        <td><?= htmlspecialchars($donation['donation_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
        
        <a href="logout.php" class="logout">Logout</a>
    </div>
</body>
</html>
