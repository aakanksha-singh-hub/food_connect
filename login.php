<?php
session_start();  // Start session for user authentication
require __DIR__ . '/database/db_connect.php';  // Database connection

error_log("Login.php accessed");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    error_log("POST request received");
    
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    error_log("Email received: " . $email);
    error_log("Password length: " . strlen($password));

    try {
        // Debug log
        error_log("Attempting database query");
        
        // Fetch user details
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        // Debug log
        if ($user) {
            error_log("User found in database");
            error_log("User ID: " . $user['id']);
            error_log("User type: " . $user['user_type']);
            error_log("Stored password hash length: " . strlen($user['password']));
            error_log("Has password: " . (!empty($user['password']) ? 'Yes' : 'No'));
            
            // Test password verification
            $verify_result = password_verify($password, $user['password']);
            error_log("Password verification result: " . ($verify_result ? 'true' : 'false'));
            
        } else {
            error_log("No user found with email: " . $email);
        }

        // Verify password
        if ($user && password_verify($password, $user['password'])) {
            error_log("Password verified successfully");
            session_regenerate_id(true); // Prevent session fixation attacks
            
            // Store user data in session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['location'] = $user['location'];
            $_SESSION['first_name'] = $user['first_name'];
            $_SESSION['last_name'] = $user['last_name'];
            
            error_log("Session data set. User ID: " . $_SESSION['user_id']);
            error_log("Redirecting to dashboard");
            
            // Redirect to dashboard
            header("Location: dashboard.php");
            exit();
        } else {
            if ($user) {
                error_log("Password verification failed for user: " . $email);
            }
            // Show alert and redirect back
            echo "<script>alert('Invalid email or password!'); window.location.href='login.html';</script>";
            exit();
        }
    } catch (PDOException $e) {
        // Log database errors instead of showing them to users
        error_log("Database error during login: " . $e->getMessage());
        error_log("SQL State: " . $e->getCode());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo "<script>alert('Something went wrong. Please try again later.'); window.location.href='login.html';</script>";
        exit();
    }
} else {
    error_log("Non-POST request to login.php");
    header("Location: login.html");
    exit();
}
?>
