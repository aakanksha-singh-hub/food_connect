<?php
require __DIR__ . '/database/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Fetch form data
    $firstName = $_POST['firstName'];  // Matches register.html
    $lastName = $_POST['lastName'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirmPassword'];  // Matches register.html
    $role = $_POST['userType'];  // Matches register.html
    $location = $_POST['location'];
    $about = $_POST['about'];

    // Check if passwords match
    if ($password !== $confirm_password) {
        echo "<script>alert('Error: Passwords do not match!'); window.location.href='register.html';</script>";
        exit;
    }

    // Hash the password securely
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    try {
        // Insert user data into the database
        $sql = "INSERT INTO users (first_name, last_name, email, phone, password, user_type, location, about) 
                VALUES (:firstName, :lastName, :email, :phone, :password, :userType, :location, :about)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':firstName' => $firstName,
            ':lastName' => $lastName,
            ':email' => $email,
            ':phone' => $phone,
            ':password' => $hashed_password,
            ':userType' => $role,
            ':location' => $location,
            ':about' => $about
        ]);

        echo "<script>alert('🎉 Registration successful! Redirecting to login...'); window.location.href='login.html';</script>";
    } catch (PDOException $e) {
        die("Registration failed: " . $e->getMessage());
    }
}
?>
