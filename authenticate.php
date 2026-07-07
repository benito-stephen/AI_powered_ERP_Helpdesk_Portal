<?php
/**
 * authenticate.php
 * Handles login form submission and starts user session.
 * Passwords are stored and compared as plain text (prototype mode).
 */

session_start();
include("db.php");

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] != "POST") {
    header("Location: login.php");
    exit();
}

// Read and trim inputs
$userid   = trim($_POST['userid']   ?? '');
$password = trim($_POST['password'] ?? '');

// Validate: neither field can be empty
if (empty($userid) || empty($password)) {
    header("Location: login.php?error=" . urlencode("Please enter your User ID and Password."));
    exit();
}

// Fetch user by userid (prepared statement — SQL injection safe)
$stmt = $conn->prepare("SELECT userid, name, role, department, email, status, password FROM users WHERE userid = ? LIMIT 1");
$stmt->bind_param("s", $userid);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    // No user found
    header("Location: login.php?error=" . urlencode("Invalid User ID or Password."));
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

// ── Password check (plain text comparison) ────────────────────────────────
// Passwords are stored as plain text in the database.
// No hashing is performed. The comparison is direct string equality.
if ($password !== $user['password']) {
    header("Location: login.php?error=" . urlencode("Invalid User ID or Password."));
    exit();
}

// ── Login success ─────────────────────────────────────────────────────────
session_regenerate_id(true);

$_SESSION['userid'] = $user['userid'];
$_SESSION['name']   = $user['name'];
$_SESSION['role']   = $user['role'];

// Log the login event
$log = $conn->prepare("INSERT INTO login_logs (userid, login_time) VALUES (?, NOW())");
$log->bind_param("s", $userid);
$log->execute();
$_SESSION['log_id'] = $conn->insert_id;
$log->close();

// Remember Me cookie (3 days)
if (!empty($_POST['remember_me'])) {
    setcookie("remember_user", $userid, time() + (3 * 24 * 60 * 60), "/", "", false, true);
}

// Role-based dashboard redirect
switch ($user['role']) {
    case "employee":
        header("Location: dashboard_e.php");
        break;
    case "Hr_admin":
        header("Location: dashboard_a.php");
        break;
    case "Technical_admin":
        header("Location: dashboard_t.php");
        break;
    default:
        session_destroy();
        header("Location: login.php?error=" . urlencode("Unrecognised role. Contact Tech Admin."));
        break;
}

$conn->close();
exit();
?>
