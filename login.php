<?php
session_start();  // Start session for user authentication
require __DIR__ . '/database/db_connect.php';  // Database connection

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    try {
        // Fetch user details
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // Verify password
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true); // Prevent session fixation attacks
            
            // Store user data in session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_type'] = $user['user_type'];

            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            // Show alert and redirect back
            echo "<script>alert('Invalid email or password!'); window.location.href='login.html';</script>";
            exit();
        }
    } catch (PDOException $e) {
        // Log database errors instead of showing them to users
        error_log("Database error: " . $e->getMessage());
        echo "<script>alert('Something went wrong. Please try again later.'); window.location.href='login.html';</script>";
        exit();
    }
}
?>
