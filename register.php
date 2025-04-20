<?php
session_start();
require __DIR__ . '/database/db_connect.php';

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate and sanitize input
        $firstName = trim($_POST['firstName']);
        $lastName = trim($_POST['lastName']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $phone = trim($_POST['phone']);
    $password = $_POST['password'];
        $confirmPassword = $_POST['confirmPassword'];
        $userType = trim($_POST['userType']);
        $location = trim($_POST['location']);
        $about = trim($_POST['about']);

        // Validation checks
        if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($password) || empty($userType) || empty($location)) {
            throw new Exception("All fields are required.");
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }

        if (strlen($phone) !== 10 || !ctype_digit($phone)) {
            throw new Exception("Please enter a valid 10-digit phone number.");
        }

        if ($password !== $confirmPassword) {
            throw new Exception("Passwords do not match.");
        }

        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }

        // Check if email already exists
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check_stmt->execute([$email]);
        if ($check_stmt->rowCount() > 0) {
            throw new Exception("This email address is already registered.");
        }

        // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert new user
        $stmt = $pdo->prepare("
            INSERT INTO users (
                first_name, 
                last_name, 
                email, 
                phone, 
                password, 
                user_type, 
                location, 
                about,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $firstName,
            $lastName,
            $email,
            $phone,
            $hashed_password,
            $userType,
            $location,
            $about
        ]);

        $success_message = "Registration successful! Please <a href='login.html'>login</a> to continue.";

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $error_message = "An error occurred during registration. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="register.css" />
    <title>Register - FoodConnect</title>
    <style>
        .error-message {
            background-color: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .success-message {
            background-color: #dcfce7;
            border: 1px solid #10b981;
            color: #166534;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }

        .success-message a {
            color: #047857;
            font-weight: 500;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <a href="index.html" class="logo">FoodConnect</a>
            <div class="nav-links">
                <a href="index.html#home">Home</a>
                <a href="index.html#about">About</a>
                <a href="index.html#features">Features</a>
                <a href="register.html">Register</a>
                <a href="index.html#contact">Contact</a>
            </div>
            <div class="menu-toggle">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="4" x2="20" y1="12" y2="12" />
                    <line x1="4" x2="20" y1="6" y2="6" />
                    <line x1="4" x2="20" y1="18" y2="18" />
                </svg>
            </div>
        </div>
    </nav>

    <div class="page-content">
        <h1>Join FoodConnect</h1>

        <div class="form-container">
            <?php if (!empty($error_message)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success_message)): ?>
                <div class="success-message">
                    <?php echo $success_message; ?>
                </div>
            <?php endif; ?>

            <form action="register.php" method="post">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="firstName">First Name</label>
                        <input type="text" id="firstName" name="firstName" class="form-control" 
                               placeholder="Enter your first name" 
                               value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>" required />
                    </div>

                    <div class="form-group">
                        <label for="lastName">Last Name</label>
                        <input type="text" id="lastName" name="lastName" class="form-control" 
                               placeholder="Enter your last name" 
                               value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>" required />
                    </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               placeholder="Enter your email" 
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required />
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-control" 
                               placeholder="Enter your phone number" pattern="[0-9]{10}" 
                               title="Please enter a valid 10-digit phone number" maxlength="10" 
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required />
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" 
                               placeholder="Create a password" required />
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirm Password</label>
                        <input type="password" id="confirmPassword" name="confirmPassword" class="form-control" 
                               placeholder="Confirm your password" required />
                    </div>

                    <div class="form-group">
                        <label for="userType">I am a</label>
                        <select id="userType" name="userType" class="form-control" required>
                            <option value="" disabled <?php echo !isset($_POST['userType']) ? 'selected' : ''; ?>>Select your role</option>
                            <option value="donor" <?php echo (isset($_POST['userType']) && $_POST['userType'] === 'donor') ? 'selected' : ''; ?>>Food Donor (Restaurant/Business)</option>
                            <option value="recipient" <?php echo (isset($_POST['userType']) && $_POST['userType'] === 'recipient') ? 'selected' : ''; ?>>Food Recipient (Charity/Organization)</option>
                            <option value="volunteer" <?php echo (isset($_POST['userType']) && $_POST['userType'] === 'volunteer') ? 'selected' : ''; ?>>Volunteer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="location">Location</label>
                        <input type="text" id="location" name="location" class="form-control" 
                               placeholder="Enter your city" 
                               value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" required />
                    </div>

                    <div class="form-group full-width">
                        <label for="about">About</label>
                        <textarea id="about" name="about" class="form-control" 
                                  placeholder="Tell us a bit about yourself or your organization"><?php echo htmlspecialchars($_POST['about'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn btn-secondary">Reset</button>
                    <button type="submit" class="btn btn-primary">Register</button>
                </div>

                <p class="login-link">
                    Already have an account? <a href="login.html">Log in</a>
                </p>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const menuToggle = document.querySelector(".menu-toggle");
            const navLinks = document.querySelector(".nav-links");

            menuToggle.addEventListener("click", function () {
                navLinks.classList.toggle("active");
            });

            const links = document.querySelectorAll(".nav-links a");
            links.forEach((link) => {
                link.addEventListener("click", function () {
                    navLinks.classList.remove("active");
                });
            });

            // Password match validation
            const form = document.querySelector('form');
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirmPassword');

            form.addEventListener('submit', function(e) {
                if (password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                }
            });
        });
    </script>
</body>
</html>
