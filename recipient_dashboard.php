<?php
session_start();
require __DIR__ . '/database/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'recipient') {
    header("Location: login.html");
    exit();
}

$recipient_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM donations WHERE recipient_id = ?");
$stmt->execute([$recipient_id]);
$donations = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Recipient Dashboard</title>
</head>
<body>
    <h2>Food You Will Receive</h2>
    <?php foreach ($donations as $donation): ?>
        <p>Food: <?= htmlspecialchars($donation['food_item']) ?> | Status: <?= htmlspecialchars($donation['status']) ?></p>
    <?php endforeach; ?>
    <a href="logout.php">Logout</a>
</body>
</html>
