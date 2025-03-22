<?php
session_start();
require __DIR__ . '/database/db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'volunteer') {
    header("Location: login.html");
    exit();
}

$volunteer_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM pickups WHERE volunteer_id = ?");
$stmt->execute([$volunteer_id]);
$pickups = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Volunteer Dashboard</title>
</head>
<body>
    <h2>Pickup Tasks</h2>
    <?php foreach ($pickups as $pickup): ?>
        <p>Pickup from Donor ID: <?= htmlspecialchars($pickup['donor_id']) ?> | Status: <?= htmlspecialchars($pickup['status']) ?></p>
    <?php endforeach; ?>
    <a href="logout.php">Logout</a>
</body>
</html>
