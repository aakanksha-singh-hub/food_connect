# Food Connect - Food Donation Management System

## Project Overview
Food Connect is a web-based platform that connects food donors with volunteers and recipients to facilitate food donation and distribution. The system helps reduce food waste and ensures that surplus food reaches those in need.

## Features
- **User Management**
  - Multiple user types: Donors, Volunteers, and Recipients
  - Secure user authentication and profile management
  - Location-based user tracking

- **Donation Management**
  - Donors can create food donation listings
  - Specify food type, quantity, and expiry date
  - Real-time donation status tracking
  - Location-based donation mapping

- **Pickup System**
  - Volunteers can view and accept pickup requests
  - Real-time pickup status updates
  - Completion tracking for successful deliveries
  - Route optimization suggestions

- **Admin Dashboard**
  - Overview of system statistics
  - Monitor donations and pickups
  - Track user activities
  - View location-wise distribution

## Technology Stack
- **Backend**: PHP
- **Database**: PostgreSQL
- **Frontend**: HTML, CSS
- **Server**: Apache (XAMPP)

## Database Structure
- **users**: Stores user information (donors, volunteers, recipients)
- **donations**: Manages donation listings and their status
- **pickups**: Tracks pickup assignments and delivery status

## Installation & Setup
1. Install XAMPP and PostgreSQL on your system
2. Clone the repository to your htdocs folder:
   ```
   C:\xampp\htdocs\food_connect\
   ```
3. Set up PostgreSQL database:
   - Create a new database
   - Create a dedicated database user with limited permissions
   - Import the provided SQL schema
   - Copy `config.example.php` to `config.php` and update with your database credentials

4. Configure PHP PostgreSQL extension:
   - Enable pgsql extension in php.ini
   - Restart Apache server

5. Access the application:
   ```
   http://localhost/food_connect/
   ```


## User Types and Permissions
1. **Donors**
   - Create donation listings
   - Track donation status
   - View pickup confirmations

2. **Volunteers**
   - View available pickups
   - Accept pickup assignments
   - Update delivery status

3. **Recipients**
   - View available donations
   - Request food items
   - Track delivery status

4. **Admin**
   - Access dashboard
   - Monitor all activities
   - Generate reports

## Security Features
- Password hashing
- Input validation
- Session management
- SQL injection prevention
- Secure configuration management
- Database access control
- Limited user permissions




