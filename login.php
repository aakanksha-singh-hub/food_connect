<?php
session_start();  // Start the session to store user data
require __DIR__ . '/database/db_connect.php';  // Connect to the database

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    try {
        // Check if user exists in the database
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            // Store user session data
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_type'] = $user['user_type'];

            // Redirect based on user role
            switch ($user['user_type']) {
                case 'donor':
                    header("Location: donor_dashboard.php");
                    break;
                case 'recipient':
                    header("Location: recipient_dashboard.php");
                    break;
                case 'volunteer':
                    header("Location: volunteer_dashboard.php");
                    break;
                default:
                    header("Location: index.html"); // Redirect to home if no valid role
            }
            exit;
        } else {
            echo "<script>alert('Invalid email or password!'); window.location.href='login.html';</script>";
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}
?>
