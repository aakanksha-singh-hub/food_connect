<?php
require __DIR__ . '/database/db_connect.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $firstName = $_POST['firstName'];
    $lastName = $_POST['lastName'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirmPassword'];
    $userType = $_POST['userType'];
    $location = $_POST['location'];
    $about = $_POST['about'];

    // Validate password confirmation
    if ($password !== $confirmPassword) {
        die("Error: Passwords do not match!");
    }

    // Hash the password before storing it
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

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
            ':password' => $hashedPassword,
            ':userType' => $userType,
            ':location' => $location,
            ':about' => $about
        ]);

        echo "🎉 Registration successful!";
    } catch (PDOException $e) {
        die("Registration failed: " . $e->getMessage());
    }
}
?>
