<?php
// PostgreSQL connection
$conn = pg_connect("host=localhost dbname=foodshare_db user=postgres password=1234");

if (!$conn) {
    die("Connection failed: " . pg_last_error());
}

// Get statistics
$total_users = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM users"), 0, 0);
$total_donations = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM donations"), 0, 0);
$total_pickups = pg_fetch_result(pg_query($conn, "SELECT COUNT(*) FROM pickups"), 0, 0);

// Get donation status counts
$donation_status = pg_query($conn, "
    SELECT status, COUNT(*) as count 
    FROM donations 
    GROUP BY status
");

// Get pickup status counts
$pickup_status = pg_query($conn, "
    SELECT status, COUNT(*) as count 
    FROM pickups 
    GROUP BY status
");

// Get location-wise donations
$location_donations = pg_query($conn, "
    SELECT location, COUNT(*) as count 
    FROM donations 
    GROUP BY location
");

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Food Connect</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #2c3e50;
        }
        .stat-label {
            color: #7f8c8d;
            margin-top: 5px;
        }
        .tables-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-top: 30px;
        }
        .table-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .data-table th, .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .status-pill {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.9em;
        }
        .status-pending { background: #ffeeba; color: #856404; }
        .status-completed { background: #c3e6cb; color: #155724; }
        .status-scheduled { background: #b8daff; color: #004085; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h1>Food Connect Admin Dashboard</h1>
        
        <div class="stats-container">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_donations; ?></div>
                <div class="stat-label">Total Donations</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_pickups; ?></div>
                <div class="stat-label">Total Pickups</div>
            </div>
        </div>

        <div class="tables-container">
            <div class="table-card">
                <h2>Recent Donations</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Donor</th>
                            <th>Food Item</th>
                            <th>Quantity</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Donation Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $recent_donations = pg_query($conn, "
                            SELECT d.*, u.first_name, u.last_name 
                            FROM donations d 
                            JOIN users u ON d.donor_id = u.id 
                            ORDER BY d.donation_date DESC 
                            LIMIT 5
                        ");
                        while ($donation = pg_fetch_assoc($recent_donations)) {
                            $status_class = 'status-' . strtolower($donation['status']);
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($donation['first_name'] . ' ' . $donation['last_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($donation['food_item']) . "</td>";
                            echo "<td>" . htmlspecialchars($donation['quantity']) . "</td>";
                            echo "<td>" . htmlspecialchars($donation['location']) . "</td>";
                            echo "<td><span class='status-pill " . $status_class . "'>" . htmlspecialchars($donation['status']) . "</span></td>";
                            echo "<td>" . date('Y-m-d H:i', strtotime($donation['donation_date'])) . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card">
                <h2>Recent Pickups</h2>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Volunteer</th>
                            <th>Donation ID</th>
                            <th>Pickup Date</th>
                            <th>Status</th>
                            <th>Completion Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $recent_pickups = pg_query($conn, "
                            SELECT p.*, u.first_name, u.last_name 
                            FROM pickups p 
                            JOIN users u ON p.volunteer_id = u.id 
                            ORDER BY p.pickup_date DESC 
                            LIMIT 5
                        ");
                        while ($pickup = pg_fetch_assoc($recent_pickups)) {
                            $status_class = 'status-' . strtolower($pickup['status']);
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($pickup['first_name'] . ' ' . $pickup['last_name']) . "</td>";
                            echo "<td>" . htmlspecialchars($pickup['donation_id']) . "</td>";
                            echo "<td>" . date('Y-m-d H:i', strtotime($pickup['pickup_date'])) . "</td>";
                            echo "<td><span class='status-pill " . $status_class . "'>" . htmlspecialchars($pickup['status']) . "</span></td>";
                            echo "<td>" . ($pickup['completion_date'] ? date('Y-m-d H:i', strtotime($pickup['completion_date'])) : '-') . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
<?php
pg_close($conn);
?> 