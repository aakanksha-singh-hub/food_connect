<?php
// Start session for user authentication
session_start();

// Check if user is logged in and is a volunteer
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'volunteer') {
    // Redirect to login page if not logged in or not a volunteer
    header("Location: login.html");
    exit;
}

// Dummy volunteer data (in a real application, this would come from a database)
$volunteer = [
    'id' => $_SESSION['user_id'] ?? 'V12345',
    'name' => 'Jane Doe',
    'email' => 'jane.doe@example.com',
    'phone' => '(555) 987-6543',
    'address' => '123 Volunteer St, Helptown',
    'join_date' => '2023-09-15',
    'skills' => ['Driving', 'Food Handling', 'Customer Service'],
    'preferences' => [
        'days' => ['monday', 'wednesday', 'saturday'],
        'times' => ['afternoon', 'evening'],
        'tasks' => ['pickup', 'delivery', 'sorting']
    ]
];

// Volunteer statistics
$stats = [
    'total_deliveries' => 28,
    'hours_volunteered' => 56,
    'people_helped' => 210,
    'upcoming_tasks' => 4
];

// Upcoming tasks for the volunteer
$upcoming_tasks = [
    [
        'id' => 'T1001',
        'date' => '2024-03-15',
        'time' => '2:00 PM',
        'task' => 'Food Pickup',
        'location' => 'Sunshine Restaurant',
        'address' => '456 Main St, Downtown',
        'duration' => '1.5 hours',
        'contact' => 'John Smith',
        'contact_phone' => '(555) 123-4567',
        'notes' => 'Park in the back, use service entrance'
    ],
    [
        'id' => 'T1002',
        'date' => '2024-03-18',
        'time' => '10:00 AM',
        'task' => 'Food Distribution',
        'location' => 'Community Center',
        'address' => '789 Park Ave, Eastside',
        'duration' => '3 hours',
        'contact' => 'Mary Johnson',
        'contact_phone' => '(555) 987-6543',
        'notes' => 'Bring your volunteer ID badge'
    ]
];

// Available tasks that the volunteer can sign up for
$available_tasks = [
    [
        'id' => 'T1003',
        'date' => '2024-03-20',
        'time' => '1:00 PM',
        'task' => 'Food Sorting',
        'location' => 'Food Bank Warehouse',
        'address' => '101 Industrial Blvd, Westside',
        'duration' => '2 hours',
        'spots_available' => 5,
        'skills_required' => ['Food Handling']
    ],
    [
        'id' => 'T1004',
        'date' => '2024-03-22',
        'time' => '9:00 AM',
        'task' => 'Delivery Driver',
        'location' => 'Distribution Center',
        'address' => '202 Commerce St, Northside',
        'duration' => '3 hours',
        'spots_available' => 3,
        'skills_required' => ['Driving']
    ],
    [
        'id' => 'T1005',
        'date' => '2024-03-25',
        'time' => '4:00 PM',
        'task' => 'Food Pickup',
        'location' => 'Local Grocery',
        'address' => '303 Market St, Southside',
        'duration' => '1.5 hours',
        'spots_available' => 2,
        'skills_required' => ['Food Handling', 'Driving']
    ]
];

// Task history
$task_history = [
    [
        'id' => 'T1000',
        'date' => '2024-03-10',
        'task' => 'Food Delivery',
        'location' => 'Hope Shelter',
        'status' => 'Completed',
        'hours' => 2.5
    ],
    [
        'id' => 'T999',
        'date' => '2024-03-05',
        'task' => 'Food Sorting',
        'location' => 'FoodConnect Warehouse',
        'status' => 'Completed',
        'hours' => 3.0
    ],
    [
        'id' => 'T998',
        'date' => '2024-03-01',
        'task' => 'Food Pickup',
        'location' => 'Local Bakery',
        'status' => 'Completed',
        'hours' => 1.5
    ]
];

// Training modules
$training_modules = [
    [
        'id' => 'TM001',
        'title' => 'Food Safety Basics',
        'description' => 'Learn the fundamentals of food safety and handling.',
        'duration' => '45 minutes',
        'status' => 'Completed',
        'completion_date' => '2023-10-05'
    ],
    [
        'id' => 'TM002',
        'title' => 'Volunteer Orientation',
        'description' => 'Introduction to FoodConnect and volunteer responsibilities.',
        'duration' => '60 minutes',
        'status' => 'Completed',
        'completion_date' => '2023-09-20'
    ],
    [
        'id' => 'TM003',
        'title' => 'Delivery Driver Training',
        'description' => 'Guidelines and best practices for food delivery.',
        'duration' => '30 minutes',
        'status' => 'Not Started',
        'completion_date' => null
    ]
];

// Process form submissions
$form_success = null;
$form_error = null;

// Handle availability form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'availability') {
    // Get form data
    $days = $_POST['days'] ?? [];
    $times = $_POST['times'] ?? [];
    $task_preferences = $_POST['task-preferences'] ?? [];
    $max_hours = $_POST['max-hours'] ?? 10;
    $notes = $_POST['notes'] ?? '';
    
    // Validate form data
    if (empty($days)) {
        $form_error = "Please select at least one available day.";
    } elseif (empty($times)) {
        $form_error = "Please select at least one time slot.";
    } elseif (empty($task_preferences)) {
        $form_error = "Please select at least one task preference.";
    } elseif ($max_hours < 1 || $max_hours > 40) {
        $form_error = "Maximum hours must be between 1 and 40.";
    } else {
        // In a real application, you would update the database here
        
        // Update the volunteer preferences in the session
        $volunteer['preferences']['days'] = $days;
        $volunteer['preferences']['times'] = $times;
        $volunteer['preferences']['tasks'] = $task_preferences;
        
        // Set success message
        $form_success = "Your availability has been updated successfully!";
    }
}

// Handle task signup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'task_signup') {
    $task_id = $_POST['task_id'] ?? '';
    
    // Find the task in available tasks
    $task_found = false;
    foreach ($available_tasks as $key => $task) {
        if ($task['id'] === $task_id) {
            $task_found = true;
            
            // In a real application, you would update the database here
            
            // For demonstration, move the task from available to upcoming
            $upcoming_tasks[] = $task;
            unset($available_tasks[$key]);
            
            // Set success message
            $form_success = "You have successfully signed up for the task!";
            break;
        }
    }
    
    if (!$task_found) {
        $form_error = "Task not found or no longer available.";
    }
}

// Handle check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'check_in') {
    $task_id = $_POST['task_id'] ?? '';
    
    // Find the task in upcoming tasks
    $task_found = false;
    foreach ($upcoming_tasks as $key => $task) {
        if ($task['id'] === $task_id) {
            $task_found = true;
            
            // In a real application, you would update the database here
            
            // Set success message
            $form_success = "You have successfully checked in for the task!";
            break;
        }
    }
    
    if (!$task_found) {
        $form_error = "Task not found or check-in not available.";
    }
}

// Get current page from URL parameter for navigation
$current_page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Include the view file (HTML template)
include 'volunteer_dashboard.html';
?>

