<?php
/**
 * Login Screen File
 * 
 * Renders the login UI. If the user has a valid active session or an autologin
 * remember_me cookie, they are automatically logged in and routed to their dashboard.
 */

// Start session to access session variables
session_start();

// Include database connection settings
include("db.php");

// 1. Session-based auto-login check
if(isset($_SESSION['userid']))
{
    // Redirect to the dashboard appropriate for the user's role
    if($_SESSION['role']=="employee")
    {
        header("Location: dashboard_e.php");
        exit();
    }

    if($_SESSION['role']=="Hr_admin")
    {
        header("Location: dashboard_a.php");
        exit();
    }

    if($_SESSION['role']=="Technical_admin")
    {
        header("Location: dashboard_t.php");
        exit();
    }
}
// 2. Cookie-based auto-login check (Remember Me feature)
else if (isset($_COOKIE['remember_user'])) {
    $cookie_userid = mysqli_real_escape_string($conn, $_COOKIE['remember_user']);
    $sql = "SELECT * FROM users WHERE userid='$cookie_userid'";
    $result = mysqli_query($conn, $sql);

    // If a valid matching user is found via cookie, log them in automatically
    if (mysqli_num_rows($result) == 1) {
        $user = mysqli_fetch_assoc($result);
        
        // Log the session login event in the database
        mysqli_query($conn, "INSERT INTO login_logs(userid, login_time) VALUES('{$user['userid']}', NOW())");
        $_SESSION['log_id'] = mysqli_insert_id($conn);
        
        // Populate session info
        $_SESSION['userid'] = $user['userid'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        
        // Redirect to their designated dashboard
        if($user['role']=="employee")
        {
            header("Location: dashboard_e.php");
            exit();
        }
        else if($user['role']=="Hr_admin")
        {
            header("Location: dashboard_a.php");
            exit();
        }
        else if($user['role']=="Technical_admin")
        {
            header("Location: dashboard_t.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>ERP Portal Login</title>
    <!-- Viewport configuration for mobile responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Base page styling with a smooth gradient background */
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        /* Container with a floating animation effect */
        .login-container {
            position: relative;
            animation: float 6s ease-in-out infinite;
        }

        /* Centered login form box */
        .login-box {
            width: 350px;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            box-sizing: border-box;
        }

        /* Subtle keyframe animation for the floating effect */
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }

        /* Form title style */
        h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #4a1a75;
            font-weight: 700;
        }

        /* Form input label styles */
        label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }

        /* Input text and password fields styling */
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 15px;
            margin-top: 8px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            outline: none;
            font-size: 14px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }

        /* Focus border transition for inputs */
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: #764ba2;
        }

        /* Remember Me & Forgot Password wrapper layout */
        .options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        /* Remember me checkbox style */
        .options label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 13px;
            color: #777;
        }

        /* Forgot password link style */
        .options a {
            text-decoration: none;
            color: #764ba2;
            font-size: 13px;
            font-weight: 600;
        }

        /* Login button default styles with gradient background */
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(118, 75, 162, 0.3);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        /* Hover animation for submit button */
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 7px 20px rgba(118, 75, 162, 0.4);
        }

        /* Generic message response box style */
        #message {
            text-align: center;
            margin-top: 15px;
            font-weight: 600;
            font-size: 14px;
        }
    </style>
</head>
<body>

    <!-- Main Outer Wrapper -->
    <div class="login-container">
        <!-- Login Form Wrapper Card -->
        <div class="login-box">
            <h2>ERP Portal Login</h2>
            <!-- Login Form Posting to Authenticate Endpoint -->
            <form action="authenticate.php" method="post">
                <!-- User ID Field -->
                <label>User ID</label>
                <input type="text" id="userid" name="userid" placeholder="Enter your User ID" required>
                
                <!-- Password Field -->
                <label>Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>

                <!-- Keep Logged In Option and Reset Password Link -->
                <div class="options">
                    <label>
                        <input type="checkbox" name="remember_me" value="1"> Remember Me
                    </label>
                    <a href="#">Forgot Password?</a>
                </div>

                <!-- Submission Button -->
                <button type="submit">Login</button>
            </form>
            <!-- Error or feedback text output placeholder -->
            <?php if (!empty($_GET['error'])): ?>
                <p id="message" style="color: #e74c3c;">
                    <?php echo htmlspecialchars($_GET['error']); ?>
                </p>
            <?php else: ?>
                <p id="message"></p>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>