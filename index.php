<?php
/**
 * Index / Entry Router File
 * 
 * Checks if a user session exists and redirects them to the appropriate dashboard
 * based on their user role. If no session exists, redirects to the login page.
 */

// Start the session to access session variables
session_start();

// Check if the user is already logged in
if (isset($_SESSION['userid'])) {
    // Redirect to the appropriate dashboard depending on the user's role
    if ($_SESSION['role'] == "employee") {
        header("Location: dashboard_e.php");
        exit();
    } else if ($_SESSION['role'] == "Hr_admin") {
        header("Location: dashboard_a.php");
        exit();
    } else if ($_SESSION['role'] == "Technical_admin") {
        header("Location: dashboard_t.php");
        exit();
    } else {
        // Fallback: if role is unrecognized, destroy session and redirect to logout
        header("Location: logout.php");
        exit();
    }
} else {
    // If not logged in, redirect to the login page
    header("Location: login.php");
    exit();
}
?>
