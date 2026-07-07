<?php
/**
 * Employee Dashboard File
 * 
 * Main user interface for employees. Supports checking status, punch-in/out,
 * leave applications, policy viewing, profile updates, and the AI Helpdesk panel.
 */

// Start session to access session credentials
session_start();

// Include database settings
include('db.php');

// Redirect unauthorized users to the login screen
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'employee') {
    header("Location: login.php");
    exit();
}

// Get the current logged-in employee ID
$userid = $_SESSION['userid'];
$today = date('Y-m-d');

// 1. Calculate punch status for the active work day
$status_query = mysqli_query($conn, "SELECT * FROM attendance WHERE userid = '$userid' AND date = '$today' ORDER BY id DESC LIMIT 1");
if (mysqli_num_rows($status_query) == 0) {
    $calculated_status = "Yet to punch in";
} else {
    $att_record = mysqli_fetch_assoc($status_query);
    if (is_null($att_record['punch_out'])) {
        $calculated_status = "Present";
    } else {
        $calculated_status = "Away";
    }
}

// Update the user's status flag in the users table
mysqli_query($conn, "UPDATE users SET status = '$calculated_status' WHERE userid = '$userid'");

// Query active employee profile details
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE userid = '$userid'");
$user = mysqli_fetch_assoc($user_query);

$message = "";

// 2. Handle POST Request: Profile Updates
if (isset($_POST['update_profile'])) {
    $name = mysqli_real_escape_string($conn, $_POST['profile_name']);
    $email = mysqli_real_escape_string($conn, $_POST['profile_email']);
    $password = mysqli_real_escape_string($conn, $_POST['profile_password']);
    if (!empty($name) && !empty($email) && !empty($password)) {
        mysqli_query($conn, "UPDATE users SET name = '$name', email = '$email', password = '$password' WHERE userid = '$userid'");
        $_SESSION['name'] = $name;
        $message = "Profile updated successfully.";
    } else {
        $message = "Please fill out all profile fields.";
    }
}

// 3. Handle POST Request: Punch In
if (isset($_POST['punch_in'])) {
    if ($calculated_status === "Yet to punch in" || $calculated_status === "Away") {
        mysqli_query($conn, "INSERT INTO attendance (userid, date, punch_in) VALUES ('$userid', '$today', NOW())");
        mysqli_query($conn, "UPDATE users SET status = 'Present' WHERE userid = '$userid'");
        $message = "Punched in successfully.";
    } else {
        $message = "You are already punched in.";
    }
}

// 4. Handle POST Request: Punch Out & Calculate Weekly Work Hours
if (isset($_POST['punch_out'])) {
    $check_query = mysqli_query($conn, "SELECT * FROM attendance WHERE userid = '$userid' AND date = '$today' AND punch_out IS NULL");
    if (mysqli_num_rows($check_query) > 0) {
        $attendance_record = mysqli_fetch_assoc($check_query);
        $record_id = $attendance_record['id'];
        
        // Update punch-out timestamp and compute shift duration in hours
        mysqli_query($conn, "UPDATE attendance SET punch_out = NOW(), duration = TIMESTAMPDIFF(SECOND, punch_in, NOW()) / 3600.0 WHERE id = $record_id");
        mysqli_query($conn, "UPDATE users SET status = 'Away' WHERE userid = '$userid'");
        
        // Compute total weekly work hours accumulated from Monday to Sunday
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        $hours_query = mysqli_query($conn, "SELECT SUM(duration) as total FROM attendance WHERE userid = '$userid' AND date BETWEEN '$week_start' AND '$week_end'");
        $hours_row = mysqli_fetch_assoc($hours_query);
        $total_weekly_hours = $hours_row['total'] ? round($hours_row['total'], 2) : 0.0;
        mysqli_query($conn, "UPDATE users SET weekly_hours = $total_weekly_hours WHERE userid = '$userid'");
        $message = "Punched out successfully.";
    } else {
        $message = "No active punch in found for today.";
    }
}

// 5. Handle POST Request: Leave Application
if (isset($_POST['apply_leave'])) {
    $leave_type = mysqli_real_escape_string($conn, $_POST['leave_type']);
    $from_date = mysqli_real_escape_string($conn, $_POST['from_date']);
    $to_date = mysqli_real_escape_string($conn, $_POST['to_date']);
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    
    if (!empty($leave_type) && !empty($from_date) && !empty($to_date) && !empty($reason)) {
        mysqli_query($conn, "INSERT INTO leaves (userid, leave_type, from_date, to_date, reason, status) VALUES ('$userid', '$leave_type', '$from_date', '$to_date', '$reason', 'Pending')");
        $message = "Leave applied successfully and is pending approval.";
    } else {
        $message = "Please fill in all leave fields.";
    }
}

// Refresh updated profile variables
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE userid = '$userid'");
$user = mysqli_fetch_assoc($user_query);

// Fetch weekly attendance logs and leave history logs
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$attendance_query = mysqli_query($conn, "SELECT * FROM attendance WHERE userid = '$userid' AND date BETWEEN '$week_start' AND '$week_end' ORDER BY date DESC");

$leave_query = mysqli_query($conn, "SELECT * FROM leaves WHERE userid = '$userid' ORDER BY id DESC");

// Set work hour parameters
$required_hours = 40.0;
$completed_hours = round($user['weekly_hours'], 2);
$total_days = mysqli_fetch_assoc(
mysqli_query(
$conn,
"SELECT COUNT(*) as total
FROM attendance
WHERE userid='$userid'")
)['total'];

$present_days = mysqli_fetch_assoc(
mysqli_query(
$conn,
"SELECT COUNT(*) as total
FROM attendance
WHERE userid='$userid'
AND duration > 0")
)['total'];

$attendance_percentage =
$total_days
?
round(($present_days/$total_days)*100,2)
:
0;
$remaining_hours = max(0, $required_hours - $completed_hours);
$overdue_hours = ($completed_hours > $required_hours) ? round($completed_hours - $required_hours, 2) : 0.0;

// Flag weekend closure check warning if user hasn't met the minimum required hours
$weekend_closed = false;
$day_of_week = date('N'); 
if (($day_of_week >= 6) && ($completed_hours < $required_hours)) {
    $weekend_closed = true;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Dashboard - ERP Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Base layout for body, defining sans-serif fonts, background, and disabling overflow-x */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            background: #f8f9fa;
            color: #333;
            overflow-x: hidden;
        }
 
        /* Left sidebar navigation style with gradient purple background */
        .sidebar {
            width: 250px;
            height: 100vh;
            background: linear-gradient(180deg, #5f2397, #3b1163);
            color: white;
            position: fixed;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
 
        /* Header element inside sidebar */
        .sidebar h3 {
            text-align: center;
            padding: 20px;
            margin: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-weight: 600;
        }
 
        /* Sidebar navigation list */
        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 20px 0;
        }
 
        /* Individual sidebar list items */
        .sidebar ul li {
            padding: 15px 25px;
            cursor: pointer;
            transition: background 0.3s, padding-left 0.3s;
            font-size: 15px;
        }
 
        /* Hover effect on sidebar items */
        .sidebar ul li:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 30px;
        }
 
        /* Selection indicator highlight for active navigation tab */
        .sidebar ul li.active-tab {
            background: rgba(255, 255, 255, 0.15);
            border-left: 4px solid #fff;
        }
 
        /* Main dashboard container layout, offset by the fixed sidebar width */
        .main {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-right 0.3s ease;
        }
 
        /* Topbar header style containing application title and user profile */
        .topbar {
            background: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }
 
        /* Topbar heading title styling */
        .topbar h2 {
            margin: 0;
            font-size: 20px;
            color: #5f2397;
        }
 
        /* Circular visual avatar for the user profile dropdown trigger */
        .profile {
            width: 40px;
            height: 40px;
            background: #8e44ad;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
 
        /* Red action logout button */
        .logout-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: background 0.3s;
        }
 
        /* Hover state transition for logout button */
        .logout-btn:hover {
            background: #c0392b;
        }
 
        /* Core wrapper for rendering inner dynamic content modules */
        .content {
            padding: 30px;
            flex: 1;
        }
 
        /* Card component containing standard section information blocks */
        .section {
            display: none;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
 
        /* Utility class to display currently active section */
        .active {
            display: block;
        }
 
        /* Responsive grid layout for info overview cards */
        .overview-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }


        .info-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            background: #fdfcff;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .info-card h4 {
            margin: 0 0 10px 0;
            color: #777;
            font-size: 14px;
            text-transform: uppercase;
        }

        .info-card p {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
            color: #5f2397;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        table, th, td {
            border: 1px solid #e9ecef;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
        }

        th {
            background-color: #f3f0f7;
            color: #5f2397;
        }

        input, select, textarea {
            width: 100%;
            padding: 10px;
            margin-top: 8px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }

        .btn-primary {
            background: #5f2397;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background 0.3s;
        }

        .btn-primary:hover {
            background: #4a1a75;
        }

        .btn-punch-in {
            background: #2ecc71;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            margin-right: 10px;
        }

        .btn-punch-out {
            background: #e67e22;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }

        .floating-icon {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #8e44ad, #5f2397);
            color: white;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 20px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transition: transform 0.3s;
            z-index: 1000;
        }

        .floating-icon:hover {
            transform: scale(1.1);
        }

        .alert-info {
            background-color: #d1ecf1;
            border-color: #bee5eb;
            color: #0c5460;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffeeba;
            color: #856404;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-badge.pending { background: #ffeeba; color: #856404; }
        .status-badge.approved { background: #d4edda; color: #155724; }
        .status-badge.rejected { background: #f8d7da; color: #721c24; }

        /* Integrated AI Chat Drawer */
        #aiChatDrawer {
            position: fixed;
            top: 0;
            right: -400px;
            width: 400px;
            height: 100vh;
            background: white;
            box-shadow: -5px 0 15px rgba(0,0,0,0.1);
            transition: right 0.3s ease;
            z-index: 9999;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: linear-gradient(135deg, #5f2397, #8e44ad);
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .chat-container {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            background: #fdfcff;
        }

        .message {
            max-width: 80%;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .message.user {
            background-color: #8e44ad;
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 2px;
        }

        .message.ai {
            background-color: white;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 2px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border: 1px solid #e1dbe9;
        }

        .chat-input-area {
            background-color: white;
            padding: 15px;
            display: flex;
            gap: 10px;
            border-top: 1px solid #e1dbe9;
        }

        .chat-input-area input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            outline: none;
        }

        .chat-input-area button {
            background-color: #8e44ad;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <ul>
            <li id="tab-overview" class="active-tab" onclick="display('overview')">Dashboard</li>
            <li id="tab-attendance" onclick="display('attendance')">Attendance</li>
            <li id="tab-leaves" onclick="display('leaves')">Leave Management</li>
            <li id="tab-policies" onclick="display('policies')">Policies</li>
        </ul>
    </div>

    <div class="main" id="mainContent">
        <div class="topbar">
            <h2>Employee Portal</h2>
            <div style="display:flex; align-items:center; gap:15px;">
                <span style="font-weight: 500; color: #555;">Welcome, <?php echo htmlspecialchars($user['name']); ?> (ID: <?php echo htmlspecialchars($user['userid']); ?>)</span>
                <div class="profile" style="cursor: pointer;" onclick="display('profile')">
                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                </div>
                <a href="logout.php"><button class="logout-btn">Logout</button></a>
            </div>
        </div>

        <div class="content">
            <?php if (!empty($message)): ?>
                <div class="alert-info"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <?php if ($weekend_closed): ?>
                <div class="alert-warning">
                    <strong>Weekend System Close Action:</strong> Your weekly working hours (<?php echo $completed_hours; ?> hrs) are below the required <?php echo $required_hours; ?> hours. This shortfall is automatically reported to HR. The attendance system will freeze at the end of the week.
                </div>
            <?php endif; ?>

            
            <div id="overview" class="section active">
                <h3>Dashboard</h3>
                <div class="overview-cards">
                    <div class="info-card">
                        <h4>Employee Name</h4>
                        <p><?php echo htmlspecialchars($user['name']); ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Department</h4>
                        <p><?php echo htmlspecialchars($user['department'] ?: 'N/A'); ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Current Status</h4>
                        <p><?php echo htmlspecialchars($user['status']); ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Leaves Remaining</h4>
                        <p><?php echo htmlspecialchars($user['leaves_left']); ?> Days</p>
                    </div>
                    <div class="info-card">
                        <h4>Weekly Work Hours</h4>
                        <p><?php echo $completed_hours; ?> / 40.0</p>
                    </div>
                </div>
            </div>

            
            <div id="attendance" class="section">
                <h3>Attendance</h3>
                
                <div style="margin-bottom: 30px; background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px;">
                    <h4>Punch Operations</h4>
                    <form method="POST" style="display: inline-block;">
                        <button type="submit" name="punch_in" class="btn-punch-in">Punch In</button>
                    </form>
                    <form method="POST" style="display: inline-block;">
                        <button type="submit" name="punch_out" class="btn-punch-out">Punch Out</button>
                    </form>
                    <div style="margin-top: 15px; font-size: 14px; color: #666;">
                        Your current status is: <strong><?php echo htmlspecialchars($user['status']); ?></strong>
                    </div>
                </div>

                <div class="overview-cards" style="margin-bottom: 30px;">
                    <div class="info-card">
                        <h4>Completed Hours (This Week)</h4>
                        <p><?php echo $completed_hours; ?> hrs</p>
                    </div>
                    <div class="info-card">
                        <h4>Remaining Hours</h4>
                        <p><?php echo $remaining_hours; ?> hrs</p>
                    </div>
                    <div class="info-card">
                        <h4>Overdue Hours</h4>
                        <p><?php echo $overdue_hours; ?> hrs</p>
                    </div>
                </div>

                <h4>Weekly Punch Log</h4>
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Punch In</th>
                        <th>Punch Out</th>
                        <th>Duration (Hours)</th>
                    </tr>
                    <?php if (mysqli_num_rows($attendance_query) == 0): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #777;">No attendance records found for this week.</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($record = mysqli_fetch_assoc($attendance_query)): ?>
                            <tr>
                                <td><?php echo date('d-m-Y', strtotime($record['date'])); ?></td>
                                <td><?php echo $record['punch_in'] ? date('h:i A', strtotime($record['punch_in'])) : 'N/A'; ?></td>
                                <td><?php echo $record['punch_out'] ? date('h:i A', strtotime($record['punch_out'])) : 'Active'; ?></td>
                                <td><?php echo round($record['duration'], 2); ?> hrs</td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="leaves" class="section">
                <h3>Leave Management</h3>
                
                <div style="background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 30px;">
                    <h4 style="margin-top: 0;">Apply for Leave</h4>
                    <form method="POST">
                        <label>Leave Type</label>
                        <select name="leave_type" required>
                            <option value="Casual Leave">Casual Leave</option>
                            <option value="Sick Leave">Sick Leave</option>
                            <option value="Maternity Leave">Maternity Leave</option>
                            <option value="Unpaid Leave">Unpaid Leave</option>
                            <option value="Work From Home">Work From Home</option>
                        </select>

                        <label>From Date</label>
                        <input type="date" name="from_date" required>

                        <label>To Date</label>
                        <input type="date" name="to_date" required>

                        <label>Reason</label>
                        <textarea name="reason" rows="4" placeholder="Specify your reason..." required></textarea>

                        <button type="submit" name="apply_leave" class="btn-primary">Apply Leave</button>
                    </form>
                </div>

                <h3>Leave History & Status</h3>
                <table>
                    <tr>
                        <th>Leave Type</th>
                        <th>From Date</th>
                        <th>To Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                    <?php if (mysqli_num_rows($leave_query) == 0): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #777;">No leave history found.</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($leave = mysqli_fetch_assoc($leave_query)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($leave['from_date'])); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($leave['to_date'])); ?></td>
                                <td><?php echo htmlspecialchars($leave['reason']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo strtolower($leave['status']); ?>">
                                        <?php echo htmlspecialchars($leave['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="policies" class="section">
                <h3>Company Policies & Guidelines</h3>
                <div class="overview-cards">
                    <?php 
                    $active_docs = mysqli_query($conn, "SELECT * FROM knowledge_base WHERE status = 'Accepted' ORDER BY id DESC");
                    if (mysqli_num_rows($active_docs) == 0): ?>
                        <div style="grid-column: 1 / -1; text-align: center; color: #777; padding: 20px;">
                            No policy documents have been published yet.
                        </div>
                    <?php else: ?>
                        <?php while ($doc = mysqli_fetch_assoc($active_docs)): ?>
                            <div class="info-card">
                                <h4><?php echo htmlspecialchars($doc['title']); ?></h4>
                                <p style="font-size: 14px; font-weight: normal; color: #666; margin-top: 10px;">
                                    File: <code><?php echo htmlspecialchars($doc['file_path']); ?></code>
                                </p>
                                <a href="view_document.php?kb_id=<?php echo $doc['id']; ?>" target="_blank" style="display:inline-block; margin-top: 15px; color:#5f2397; font-weight:bold; text-decoration:none;">Open PDF Document</a>
                            </div>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </div>
            </div>

            
            <div id="profile" class="section">
                <h3>My Profile</h3>
                <p>Manage your personal details here.</p>
                <div style="background: #fdfcff; padding: 25px; border: 1px solid #e9ecef; border-radius: 8px; max-width: 600px;">
                    <form method="POST">
                        <label>User ID</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['userid']); ?>" readonly style="background-color:#f1f1f1; cursor:not-allowed;">

                        <label>Role</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['role']); ?>" readonly style="background-color:#f1f1f1; cursor:not-allowed;">

                        <label>Department</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['department'] ?: 'N/A'); ?>" readonly style="background-color:#f1f1f1; cursor:not-allowed;">

                        <label>Full Name</label>
                        <input type="text" name="profile_name" value="<?php echo htmlspecialchars($user['name']); ?>" required>

                        <label>Email Address</label>
                        <input type="email" name="profile_email" value="<?php echo htmlspecialchars($user['email']); ?>" required>

                        <label>Password</label>
                        <div style="position:relative;">
                            <input type="password" id="profilePass" name="profile_password" value="<?php echo htmlspecialchars($user['password']); ?>" required style="padding-right:40px;">
                            <span onclick="togglePassVisibility()" style="position:absolute; right:10px; top:12px; cursor:pointer; font-size:14px; user-select:none;">&#128065;</span>
                        </div>

                        <button type="submit" name="update_profile" class="btn-primary" style="margin-top: 15px;">Update Profile</button>
                    </form>
                </div>
            </div>

            <script>
                // Switches between different sidebar navigation modules and highlights the active menu item
                function display(sectionId) {
                    const sections = document.querySelectorAll('.section');
                    sections.forEach(sec => sec.classList.remove('active'));
                    
                    const activeSection = document.getElementById(sectionId);
                    if (activeSection) {
                        activeSection.classList.add('active');
                    }
                    
                    const tabs = document.querySelectorAll('.sidebar ul li');
                    tabs.forEach(tab => tab.classList.remove('active-tab'));
                    
                    const activeTab = document.getElementById('tab-' + sectionId);
                    if (activeTab) {
                        activeTab.classList.add('active-tab');
                    }
                }
                
                // Toggles password input visibility between password and visible text format
                function togglePassVisibility() {
                    const passInput = document.getElementById('profilePass');
                    if (passInput.type === 'password') {
                        passInput.type = 'text';
                    } else {
                        passInput.type = 'password';
                    }
                }
            </script>
            
            </div>

        </div>
    </div>

    
    <div class="floating-icon" onclick="toggleAIChat()">
        AI
    </div>

    
    <div id="aiChatDrawer">
        <div class="chat-header">
            <h3 style="margin: 0;">AI Helpdesk Assistant</h3>
            <span onclick="toggleAIChat()" style="cursor: pointer; font-size: 24px; font-weight: bold;">&times;</span>
        </div>
        
        <div style="padding: 10px 15px; background: #f3f0f7; border-bottom: 1px solid #e1dbe9; display:flex; align-items:center; gap:8px;">
            <label style="font-size:12px; font-weight:600; color:#5f2397; white-space:nowrap;">&#128269; Answer Scope:</label>
            <select id="pageFilterSelect" style="flex:1; padding:5px 8px; border:1px solid #ccc; border-radius:4px; font-size:12px; background:white; color:#333;" onchange="onFilterChange()">
                <option value="General">&#127759; General (All Topics)</option>
                <option value="Dashboard">&#128200; Dashboard</option>
                <option value="Attendance">&#128337; Attendance</option>
                <option value="Leave Management">&#128197; Leave Management</option>
                <option value="Policies">&#128218; Policies</option>
            </select>
        </div>
        <div id="filterBadge" style="display:none; background:#eaf0fb; color:#2c5f9e; font-size:11px; font-weight:bold; padding:5px 15px; border-bottom:1px solid #d0ddf7;">&#128274; Filtered: <span id="filterBadgeText"></span> — AI will only answer within this scope.</div>
        <div class="chat-container" id="chatContainer">
            
        </div>
        <div class="chat-input-area">
            <input type="text" id="chatInput" placeholder="Type your message..." onkeypress="handleChatKey(event)">
            <button onclick="sendChatMessage()">Send</button>
        </div>
    </div>

    <script>
        // Shows or hides the slide-out AI Helpdesk Chat Drawer panel
        function toggleAIChat() {
            const drawer = document.getElementById('aiChatDrawer');
            if (drawer.style.right === '0px') {
                drawer.style.right = '-400px';
            } else {
                drawer.style.right = '0px';
            }
        }

        // Toggles a visual banner indicator when an active subpage filter scope is selected in dropdown
        function onFilterChange() {
            const select = document.getElementById('pageFilterSelect');
            const badge = document.getElementById('filterBadge');
            const badgeText = document.getElementById('filterBadgeText');
            if (select.value === 'General') {
                badge.style.display = 'none';
            } else {
                badge.style.display = 'block';
                badgeText.textContent = select.value;
            }
        }

        let isSending = false;

        // Triggers the send operation when Enter is pressed in the chat input textbox
        function handleChatKey(e) {
            if (e.key === 'Enter') {
                sendChatMessage();
            }
        }

        // Asynchronously submits a user's question, displaying loader states and rendering AI responses
        function sendChatMessage() {
            if (isSending) return;
            
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            if (!message) return;
            
            if (message.length > 500) {
                alert("Message exceeds the 500 character limit. Please shorten your message.");
                return;
            }
            
            const pageFilter = document.getElementById('pageFilterSelect').value;
            const container = document.getElementById('chatContainer');
            
            // Append user message
            const userDiv = document.createElement('div');
            userDiv.className = 'message user';
            userDiv.textContent = message;
            container.appendChild(userDiv);
            container.scrollTop = container.scrollHeight;
            
            input.value = '';
            
            // Append typing indicator
            const typingIndicator = document.createElement('div');
            typingIndicator.className = 'message ai';
            typingIndicator.style.fontStyle = 'italic';
            typingIndicator.id = 'typingIndicatorTemp';
            typingIndicator.textContent = 'AI is typing...';
            container.appendChild(typingIndicator);
            container.scrollTop = container.scrollHeight;
            
            isSending = true;
            
            fetch('chatbot_backend.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    message: message,
                    page_filter: pageFilter
                })
            })
            .then(res => res.json())
            .then(data => {
                const temp = document.getElementById('typingIndicatorTemp');
                if (temp) temp.remove();
                
                const aiDiv = document.createElement('div');
                aiDiv.className = 'message ai';
                aiDiv.textContent = data.response || (data.error ? "Error: " + data.error : "No response.");
                container.appendChild(aiDiv);
                container.scrollTop = container.scrollHeight;
            })
            .catch(err => {
                const temp = document.getElementById('typingIndicatorTemp');
                if (temp) temp.remove();
                
                const aiDiv = document.createElement('div');
                aiDiv.className = 'message ai';
                aiDiv.textContent = "Request failed. Please try again.";
                container.appendChild(aiDiv);
                container.scrollTop = container.scrollHeight;
            })
            .finally(() => {
                isSending = false;
            });
        }
    </script>


</body>
</html>
