<?php
/**
 * Authentication Handler File
 * 
 * Processes POST requests containing login credentials, verifies them against 
 * the database, logs user sessions, sets up session variables, configures 
 * autologin cookies, and redirects the user to their designated dashboard.
 */

// Start a session to initialize/store logged-in user data
session_start();

// Include database connection settings
include("db.php");

// Retrieve POST login inputs
$userid = $_POST['userid'];
$password = $_POST['password'];

// Define query to look up users matching the provided User ID and Password
$sql = "SELECT * FROM users
        WHERE userid='$userid'
        AND password='$password'";

$result = mysqli_query($conn,$sql);

// Check if exactly one matching user was found in the database
if(mysqli_num_rows($result)==1)
{
    // Fetch user details as an associative array
    $user = mysqli_fetch_assoc($result);
    $userid = $user['userid'];

    // Insert a new login log entry with the current timestamp
    mysqli_query(
        $conn,
        "INSERT INTO login_logs(userid, login_time)
         VALUES('$userid', NOW())"
    );

    // Store the generated login log ID in the session to track logout time later
    $_SESSION['log_id'] = mysqli_insert_id($conn);

    // Save key user info in session variables for accessibility throughout the app
    $_SESSION['userid'] = $user['userid'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];

    // If 'remember_me' was checked, set a cookie to persist the User ID for 3 days
    if (isset($_POST['remember_me'])) {
        setcookie("remember_user", $user['userid'], time() + 259200, "/"); 
    }

    // Redirect the user based on their specific administrative or employee role
    if($user['role']=="employee")
    {
        header("Location: dashboard_e.php");
    }
    else if($user['role']=="Hr_admin")
    {
        header("Location: dashboard_a.php");
    }
    else if($user['role']=="Technical_admin")
    {
        header("Location: dashboard_t.php");
    }
}
else
{
    // Display error message if credentials do not match any user record
    echo "Invalid User ID or Password";
}

?>


