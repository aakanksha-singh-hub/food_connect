<?php
$host = "localhost";    
$port = "5432";         // Default PostgreSQL port
$dbname = "foodshare_db"; // Change this to your actual database name
$user = "postgres";     // Your PostgreSQL username
$password = "1234";  // Your PostgreSQL password

// Create a connection string
$dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

try {
    // Create a PDO connection
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,  // Enable error reporting
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC // Fetch as associative array
    ]);

    // Connection successful message (for debugging, remove in production)
    // echo "Connected to PostgreSQL successfully!";
} catch (PDOException $e) {
    // Handle connection error
    die("Database connection failed: " . $e->getMessage());
}
?>
