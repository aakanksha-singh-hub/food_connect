<?php
$host = "localhost"; // or your PostgreSQL server address
$dbname = "foodshare_db"; // Your database name
$user = "postgres"; // PostgreSQL username
$password = "1234";

try {
    $pdo = new PDO("pgsql:host=$host;dbname=$dbname", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
