<?php
/**
 * Logout File
 * 
 * Logs the logout event in the database, destroys the session,
 * clears any autologin cookies, and redirects the user to the login screen.
 */

// Start session to access session variables
session_start();

// Include database connection configuration
include("db.php");

// Update the user's logout time in the login_logs table if the log ID is set in the session
if(isset($_SESSION['log_id']))
{
    $logid = $_SESSION['log_id'];

    mysqli_query(
        $conn,
        "UPDATE login_logs
         SET logout_time = NOW()
         WHERE id = '$logid'"
    );
}

// Destroy all session data
session_destroy();

// Clear the "remember_user" cookie if it exists
if (isset($_COOKIE['remember_user'])) {
    setcookie("remember_user", "", time() - 3600, "/");
}

// Redirect the user to the login page
header("Location: login.php");
exit();

?>
