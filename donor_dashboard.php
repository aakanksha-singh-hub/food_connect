<?php
session_start();
require __DIR__ . '/database/db_connect.php';

// Check if user is logged in and is a donor
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'donor') {
    header("Location: login.html");
    exit;
}

$donor_id = $_SESSION['user_id'];

// Handle form submission for new donation
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_donation'])) {
    try {
        $food_item = trim($_POST['food_item']);
        $quantity = intval($_POST['quantity']);
        $location = trim($_POST['location']);
        $expiry_date = $_POST['expiry_date'];

        if (empty($food_item) || empty($quantity) || empty($location)) {
            throw new Exception("All fields are required!");
        }

        // Insert new donation
        $stmt = $pdo->prepare("
            INSERT INTO donations (
                donor_id, 
                food_item, 
                quantity, 
                location, 
                expiry_date, 
                status, 
                donation_date
            ) VALUES (
                :donor_id, 
                :food_item, 
                :quantity, 
                :location, 
                :expiry_date, 
                'available', 
                CURRENT_TIMESTAMP
            )
            RETURNING id
        ");

        $result = $stmt->execute([
            'donor_id' => $donor_id,
            'food_item' => $food_item,
            'quantity' => $quantity,
            'location' => $location,
            'expiry_date' => $expiry_date
        ]);

        if ($result) {
            $_SESSION['success_message'] = "Donation added successfully! It will be visible to recipients in your area.";
        } else {
            throw new Exception("Failed to add donation. Please try again.");
        }

        header("Location: donor_dashboard.php");
        exit();

    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $_SESSION['error_message'] = "A database error occurred. Please try again.";
    }
}

// Fetch user profile
try {
    $profile_stmt = $pdo->prepare("
        SELECT 
            first_name,
            last_name,
            email,
            phone,
            location,
            to_char(created_at, 'Month YYYY') as member_since
        FROM users 
        WHERE id = :user_id
    ");
    
    $profile_stmt->execute(['user_id' => $donor_id]);
    $user_profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_profile) {
        $_SESSION['error_message'] = "Failed to load user profile.";
        header("Location: logout.php");
        exit();
    }

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $user_profile = [
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'phone' => $_SESSION['phone'] ?? '',
        'location' => $_SESSION['location'] ?? '',
        'member_since' => date('F Y')
    ];
}

// Fetch donations statistics
try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_donations,
            COUNT(CASE WHEN status = 'available' THEN 1 END) as available_donations,
            COUNT(CASE WHEN status = 'accepted' THEN 1 END) as accepted_donations
        FROM donations 
        WHERE donor_id = :donor_id
    ");
    
    $stats_stmt->execute(['donor_id' => $donor_id]);
    $donation_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $donation_stats = [
        'total_donations' => 0,
        'available_donations' => 0,
        'accepted_donations' => 0
    ];
}

// Fetch all donations
try {
    // Available donations
    $available_stmt = $pdo->prepare("
        SELECT 
            d.*,
            to_char(d.donation_date, 'Month DD, YYYY') as formatted_date,
            to_char(d.expiry_date, 'Month DD, YYYY') as formatted_expiry
        FROM donations d
        WHERE d.donor_id = :donor_id 
        AND d.status = 'available'
        ORDER BY d.donation_date DESC
    ");
    $available_stmt->execute(['donor_id' => $donor_id]);
    $available_donations = $available_stmt->fetchAll();

    // Accepted donations with recipient info
    $accepted_stmt = $pdo->prepare("
        SELECT 
            d.*,
            u.first_name as recipient_first_name,
            u.last_name as recipient_last_name,
            p.status as pickup_status,
            to_char(d.donation_date, 'Month DD, YYYY') as formatted_date,
            to_char(d.expiry_date, 'Month DD, YYYY') as formatted_expiry,
            to_char(p.scheduled_time, 'Month DD, YYYY HH24:MI') as pickup_time
        FROM donations d
        LEFT JOIN users u ON d.recipient_id = u.id
        LEFT JOIN pickups p ON d.id = p.donation_id
        WHERE d.donor_id = :donor_id 
        AND d.status = 'accepted'
        ORDER BY d.donation_date DESC
    ");
    $accepted_stmt->execute(['donor_id' => $donor_id]);
    $accepted_donations = $accepted_stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $available_donations = [];
    $accepted_donations = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Dashboard - FoodConnect</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #3b82f6;
            --primary-light: #dbeafe;
            --accent: #f97316;
            --text: #1e293b;
            --text-light: #64748b;
            --background: #ffffff;
            --background-alt: #f8fafc;
            --border: #e2e8f0;
            --error: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", system-ui, sans-serif;
        }

        body {
            line-height: 1.5;
            color: var(--text);
            background: var(--background-alt);
            min-height: 100vh;
        }

        /* Navbar Styles */
        .navbar {
            background: var(--background);
            border-bottom: 1px solid var(--border);
            padding: 1rem 0;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
        }

        .navbar .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            text-decoration: none;
            letter-spacing: -0.5px;
        }

        .nav-links {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        .menu-toggle {
            display: none;
        }

        .nav-link {
            color: var(--text);
            text-decoration: none;
            font-weight: 500;
            padding: 0.75rem 1rem;
            transition: var(--transition);
            position: relative;
        }

        .nav-link:hover {
            color: var(--primary);
        }

        .nav-link.active {
            color: var(--primary);
        }

        .nav-link.active::after {
            content: "";
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--primary);
        }

        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 2rem;
            margin-top: 4rem;
        }

        .welcome-header {
            margin-bottom: 2rem;
        }

        .welcome-header h1 {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .location-info {
            color: var(--text-light);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .card {
            background: var(--background);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .card h4 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .card p {
            color: var(--text);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Form Styles */
        .form-section {
            background: var(--background);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 0.375rem;
            font-size: 0.875rem;
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 0.375rem;
            border: none;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-1px);
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }

        .status-available {
            background: #dcfce7;
            color: #166534;
        }

        .status-accepted {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-completed {
            background: #f0fdf4;
            color: #166534;
        }

        .status-pending {
            background: #fff7ed;
            color: #9a3412;
        }

        /* Messages */
        .message {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Profile Section */
        .profile-section {
            max-width: 1200px;
            margin: 0 auto;
        }

        .profile-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        .stat-item {
            padding: 1rem;
            border-radius: 8px;
            background: var(--background-alt);
            margin-bottom: 1rem;
        }

        .stat-item label {
            display: block;
            color: var(--text-light);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }

        .stat-item .value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .nav-links {
                display: none;
                position: fixed;
                top: 4rem;
                left: 0;
                width: 100%;
                background: var(--background);
                flex-direction: column;
                padding: 0;
                border-bottom: 1px solid var(--border);
            }

            .nav-links.active {
                display: flex;
            }

            .nav-link {
                padding: 1rem;
                width: 100%;
                text-align: center;
                border-bottom: 1px solid var(--border);
            }

            .nav-link:last-child {
                border-bottom: none;
            }

            .nav-link.active::after {
                display: none;
            }

            .nav-link.active {
                background: var(--primary-light);
            }

            .menu-toggle {
                display: block;
                cursor: pointer;
            }

            .menu-toggle i {
                font-size: 1.5rem;
                color: var(--text);
            }

            .main-content {
                padding: 1rem;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <a href="index.html" class="logo">FoodConnect</a>
            <div class="nav-links">
                <a href="#dashboard" class="nav-link active" data-tab="dashboard">Dashboard</a>
                <a href="#donate" class="nav-link" data-tab="donate">Donate Food</a>
                <a href="#available" class="nav-link" data-tab="available">Available Donations</a>
                <a href="#accepted" class="nav-link" data-tab="accepted">Accepted Donations</a>
                <a href="#profile" class="nav-link" data-tab="profile">Profile</a>
                <a href="logout.php" class="nav-link">Logout</a>
            </div>
            <div class="menu-toggle">
                <i class="fas fa-bars"></i>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="welcome-header">
            <h1>Welcome, <?php echo htmlspecialchars($user_profile['first_name'] ?? 'Donor'); ?></h1>
            <div class="location-info">
                <i class="fas fa-map-marker-alt"></i>
                <span><?php echo htmlspecialchars($user_profile['location'] ?? 'Location not set'); ?></span>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="message success">
                <?php 
                echo htmlspecialchars($_SESSION['success_message']);
                unset($_SESSION['success_message']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="message error">
                <?php 
                echo htmlspecialchars($_SESSION['error_message']);
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Dashboard Overview -->
        <div id="dashboard" class="tab-content active">
            <h2 class="section-title">Overview</h2>
            <div class="dashboard-grid">
                <div class="card">
                    <h4>Total Donations</h4>
                    <p class="stat"><?php echo $donation_stats['total_donations']; ?> donations made</p>
                </div>
                <div class="card">
                    <h4>Available Donations</h4>
                    <p class="stat"><?php echo $donation_stats['available_donations']; ?> donations waiting</p>
                </div>
                <div class="card">
                    <h4>Accepted Donations</h4>
                    <p class="stat"><?php echo $donation_stats['accepted_donations']; ?> donations accepted</p>
                </div>
            </div>
        </div>

        <!-- Donate Food Form -->
        <div id="donate" class="tab-content">
            <h2 class="section-title">Donate Food</h2>
            <div class="form-section">
                <form method="POST" action="donor_dashboard.php">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Food Item</label>
                            <input type="text" name="food_item" class="form-control" required 
                                   placeholder="Enter food item name">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Quantity</label>
                            <input type="number" name="quantity" class="form-control" required 
                                   placeholder="Enter quantity">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Location</label>
                            <input type="text" name="location" class="form-control" required 
                                   value="<?php echo htmlspecialchars($user_profile['location'] ?? ''); ?>"
                                   placeholder="Enter pickup location">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" name="submit_donation" class="btn btn-primary">
                        Submit Donation
                    </button>
                </form>
            </div>
        </div>

        <!-- Available Donations -->
        <div id="available" class="tab-content">
            <h2 class="section-title">Available Donations</h2>
            <div class="dashboard-grid">
                <?php foreach ($available_donations as $donation): ?>
                    <div class="card">
                        <h4><?php echo htmlspecialchars($donation['food_item']); ?></h4>
                        <p>
                            <i class="fas fa-box"></i>
                            Quantity: <?php echo htmlspecialchars($donation['quantity']); ?>
                        </p>
                        <p>
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($donation['location']); ?>
                        </p>
                        <p>
                            <i class="fas fa-calendar"></i>
                            Donated: <?php echo htmlspecialchars($donation['formatted_date']); ?>
                        </p>
                        <?php if ($donation['expiry_date']): ?>
                            <p>
                                <i class="fas fa-clock"></i>
                                Expires: <?php echo htmlspecialchars($donation['formatted_expiry']); ?>
                            </p>
                        <?php endif; ?>
                        <p>
                            <i class="fas fa-info-circle"></i>
                            Status: <span class="status-badge status-available">Available</span>
                        </p>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($available_donations)): ?>
                    <div class="card">
                        <p style="text-align: center;">No available donations at the moment.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Accepted Donations -->
        <div id="accepted" class="tab-content">
            <h2 class="section-title">Accepted Donations</h2>
            <div class="dashboard-grid">
                <?php foreach ($accepted_donations as $donation): ?>
                    <div class="card">
                        <h4><?php echo htmlspecialchars($donation['food_item']); ?></h4>
                        <p>
                            <i class="fas fa-user"></i>
                            Recipient: <?php echo htmlspecialchars($donation['recipient_first_name'] . ' ' . $donation['recipient_last_name']); ?>
                        </p>
                        <p>
                            <i class="fas fa-box"></i>
                            Quantity: <?php echo htmlspecialchars($donation['quantity']); ?>
                        </p>
                        <p>
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($donation['location']); ?>
                        </p>
                        <p>
                            <i class="fas fa-calendar"></i>
                            Donated: <?php echo htmlspecialchars($donation['formatted_date']); ?>
                        </p>
                        <?php if ($donation['pickup_time']): ?>
                            <p>
                                <i class="fas fa-clock"></i>
                                Pickup: <?php echo htmlspecialchars($donation['pickup_time']); ?>
                            </p>
                        <?php endif; ?>
                        <p>
                            <i class="fas fa-info-circle"></i>
                            Status: 
                            <span class="status-badge <?php echo $donation['pickup_status'] === 'completed' ? 'status-completed' : 'status-pending'; ?>">
                                <?php echo ucfirst(htmlspecialchars($donation['pickup_status'] ?? 'pending')); ?>
                            </span>
                        </p>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($accepted_donations)): ?>
                    <div class="card">
                        <p style="text-align: center;">No accepted donations yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Profile Section -->
        <div id="profile" class="tab-content">
            <h2 class="section-title">Profile Information</h2>
            <div class="profile-section">
                <div class="profile-grid">
                    <div class="card">
                        <h4>Personal Information</h4>
                        <p>
                            <i class="fas fa-user"></i>
                            <strong>Name:</strong> 
                            <?php echo htmlspecialchars($user_profile['first_name'] . ' ' . $user_profile['last_name']); ?>
                        </p>
                        <p>
                            <i class="fas fa-envelope"></i>
                            <strong>Email:</strong> 
                            <?php echo htmlspecialchars($user_profile['email']); ?>
                        </p>
                        <p>
                            <i class="fas fa-phone"></i>
                            <strong>Phone:</strong> 
                            <?php echo htmlspecialchars($user_profile['phone'] ?? 'Not set'); ?>
                        </p>
                        <p>
                            <i class="fas fa-map-marker-alt"></i>
                            <strong>Location:</strong> 
                            <?php echo htmlspecialchars($user_profile['location'] ?? 'Not set'); ?>
                        </p>
                        <p>
                            <i class="fas fa-calendar-alt"></i>
                            <strong>Member Since:</strong> 
                            <?php echo htmlspecialchars($user_profile['member_since']); ?>
                        </p>
                        <div style="margin-top: 1rem;">
                            <a href="edit_profile.php" class="btn btn-primary">
                                Edit Profile
                            </a>
                        </div>
                    </div>
                    <div class="card">
                        <h4>Donation Statistics</h4>
                        <div class="stat-item">
                            <label>Total Donations</label>
                            <p class="value"><?php echo $donation_stats['total_donations']; ?></p>
                        </div>
                        <div class="stat-item">
                            <label>Available Donations</label>
                            <p class="value"><?php echo $donation_stats['available_donations']; ?></p>
                        </div>
                        <div class="stat-item">
                            <label>Accepted Donations</label>
                            <p class="value"><?php echo $donation_stats['accepted_donations']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching functionality
            const navLinks = document.querySelectorAll('.nav-link[data-tab]');
            const tabContents = document.querySelectorAll('.tab-content');

            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const tabId = this.getAttribute('data-tab');

                    // Update active states
                    navLinks.forEach(nl => nl.classList.remove('active'));
                    this.classList.add('active');

                    // Show selected tab content
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === tabId) {
                            content.classList.add('active');
                            content.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });

                    // Close mobile menu when a link is clicked
                    document.querySelector('.nav-links').classList.remove('active');
                });
            });

            // Handle URL hash on page load
            const hash = window.location.hash.substring(1);
            if (hash) {
                const targetLink = document.querySelector(`.nav-link[data-tab="${hash}"]`);
                if (targetLink) {
                    targetLink.click();
                }
            }

            // Mobile menu toggle
            const menuToggle = document.querySelector('.menu-toggle');
            const navLinksContainer = document.querySelector('.nav-links');

            menuToggle.addEventListener('click', function() {
                navLinksContainer.classList.toggle('active');
            });

            // Close mobile menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.nav-links') && !e.target.closest('.menu-toggle')) {
                    navLinksContainer.classList.remove('active');
                }
            });

            // Handle window resize
            function handleResize() {
                if (window.innerWidth > 768) {
                    navLinksContainer.classList.remove('active');
                }
            }

            window.addEventListener('resize', handleResize);
            handleResize(); // Initial check

            // Set minimum date for expiry date input
            const expiryDateInput = document.querySelector('input[name="expiry_date"]');
            if (expiryDateInput) {
                const today = new Date();
                const tomorrow = new Date(today);
                tomorrow.setDate(tomorrow.getDate() + 1);
                expiryDateInput.min = tomorrow.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>
