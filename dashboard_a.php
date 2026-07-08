<?php
/**
 * HR Admin Dashboard File
 * 
 * Renders the HR administrator environment. Provides management controls for leaves approval,
 * employee profiles, attendance logs, policy documents, and AI review workflows.
 */

// Start session to access session credentials
session_start();

// Include database settings
include('db.php');

// Redirect unauthorized users to the login screen
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'Hr_admin') {
    header("Location: login.php");
    exit();
}

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
mysqli_query($conn, "UPDATE users SET status = '$calculated_status' WHERE userid = '$userid'");

// Query active HR admin profile details
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE userid = '$userid'");
$user = mysqli_fetch_assoc($user_query);

$message = "";
if (isset($_SESSION['upload_message'])) {
    $message = $_SESSION['upload_message'];
    unset($_SESSION['upload_message']);
}

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
        mysqli_query($conn, "UPDATE attendance SET punch_out = NOW(), duration = TIMESTAMPDIFF(SECOND, punch_in, NOW()) / 3600.0 WHERE id = $record_id");
        mysqli_query($conn, "UPDATE users SET status = 'Away' WHERE userid = '$userid'");
        
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_end = date('Y-m-d', strtotime('sunday this week'));
        $hours_query = mysqli_query($conn, "SELECT SUM(duration) as total FROM attendance WHERE userid = '$userid' AND date BETWEEN '$week_start' AND '$week_end'");
        $hours_row = mysqli_fetch_assoc($hours_query);
        $total_weekly_hours = $hours_row['total'] ? round($hours_row['total'], 2) : 0.0;
        mysqli_query($conn, "UPDATE users SET weekly_hours = $total_weekly_hours WHERE userid = '$userid'");
        $message = "Punched out successfully.";
    } else {
        $message = "No active punch in found.";
    }
}

// 5. Handle POST Request: Leave Application for HR Self
if (isset($_POST['apply_leave'])) {
    $leave_type = mysqli_real_escape_string($conn, $_POST['leave_type']);
    $from_date = mysqli_real_escape_string($conn, $_POST['from_date']);
    $to_date = mysqli_real_escape_string($conn, $_POST['to_date']);
    $reason = mysqli_real_escape_string($conn, $_POST['reason']);
    if (!empty($leave_type) && !empty($from_date) && !empty($to_date) && !empty($reason)) {
        mysqli_query($conn, "INSERT INTO leaves (userid, leave_type, from_date, to_date, reason, status) VALUES ('$userid', '$leave_type', '$from_date', '$to_date', '$reason', 'Pending')");
        $message = "Your leave request has been submitted.";
    }
}

// 6. Handle POST Request: Approve Employee Leave Request
if (isset($_POST['approve_leave'])) {
    $leave_id = intval($_POST['leave_id']);
    $leave_info = mysqli_query($conn, "SELECT * FROM leaves WHERE id = $leave_id");
    if ($l_row = mysqli_fetch_assoc($leave_info)) {
        $emp_id = $l_row['userid'];
        $days = (strtotime($l_row['to_date']) - strtotime($l_row['from_date'])) / 86400 + 1;
        mysqli_query($conn, "UPDATE leaves SET status = 'Approved' WHERE id = $leave_id");
        mysqli_query($conn, "UPDATE users SET leaves_left = GREATEST(0, leaves_left - $days) WHERE userid = '$emp_id'");
        $message = "Leave ID $leave_id approved and leave days subtracted.";
    }
}

// 7. Handle POST Request: Reject Employee Leave Request
if (isset($_POST['reject_leave'])) {
    $leave_id = intval($_POST['leave_id']);
    mysqli_query($conn, "UPDATE leaves SET status = 'Rejected' WHERE id = $leave_id");
    $message = "Leave ID $leave_id rejected.";
}

// 8. Handle POST Request: Register New Employee Account
if (isset($_POST['create_employee'])) {
    $new_id = mysqli_real_escape_string($conn, $_POST['emp_id']);
    $new_name = mysqli_real_escape_string($conn, $_POST['emp_name']);
    $new_pass = mysqli_real_escape_string($conn, $_POST['emp_pass']);
    $new_email = mysqli_real_escape_string($conn, $_POST['emp_email']);
    $new_role = mysqli_real_escape_string($conn, $_POST['emp_role']);
    $new_dept = mysqli_real_escape_string($conn, $_POST['emp_dept']);
    
    if (!empty($new_id) && !empty($new_name) && !empty($new_pass) && !empty($new_email)) {
        $check_exists = mysqli_query($conn, "SELECT * FROM users WHERE userid = '$new_id'");
        if (mysqli_num_rows($check_exists) == 0) {
            mysqli_query($conn, "INSERT INTO users (userid, name, password, email, role, department, leaves_left, weekly_hours) VALUES ('$new_id', '$new_name', '$new_pass', '$new_email', '$new_role', '$new_dept', 15, 0.0)");
            $message = "Employee '$new_name' created successfully.";
        } else {
            $message = "Employee ID '$new_id' already exists.";
        }
    }
}

// 9. Handle POST Request: Submit New Policy Document to Knowledge Base
if (isset($_POST['submit_document'])) {
    $title = mysqli_real_escape_string($conn, $_POST['doc_title']);
    $file_path = mysqli_real_escape_string($conn, $_POST['doc_file_path']);
    if (!empty($title) && !empty($file_path)) {
        mysqli_query($conn, "INSERT INTO knowledge_base (title, file_path, status, uploaded_by) VALUES ('$title', '$file_path', 'Submitted', '$userid')");
        $message = "Document '$title' submitted for Tech Admin review.";
    }
}

// 10. Handle POST Request: Edit Existing Knowledge Base Policy Document
if (isset($_POST['edit_document'])) {
    $doc_id = intval($_POST['doc_id']);
    $new_title = mysqli_real_escape_string($conn, $_POST['new_title']);
    $new_file_path = mysqli_real_escape_string($conn, $_POST['new_file_path']);
    mysqli_query($conn, "UPDATE knowledge_base SET title = '$new_title', file_path = '$new_file_path', status = 'Submitted' WHERE id = $doc_id");
    $message = "Document updated and submitted for Tech Admin approval.";
}

// 11. Handle POST Request: Submit Edited AI Response for Auditing Review
if (isset($_POST['submit_ai_review'])) {
    $log_id = intval($_POST['log_id']);
    $edited_resp = mysqli_real_escape_string($conn, $_POST['edited_response']);
    $notes = mysqli_real_escape_string($conn, $_POST['review_notes']);
    mysqli_query($conn, "UPDATE ai_logs SET edited_response = '$edited_resp', review_notes = '$notes', review_status = 'Edited_by_HR' WHERE id = $log_id");
    $message = "AI response review submitted to Tech Admin for approval.";
}

// 12. Handle POST Request: Delete AI Log Entry
if (isset($_POST['delete_ai_log'])) {
    $log_id = intval($_POST['log_id']);
    mysqli_query($conn, "DELETE FROM ai_logs WHERE id = $log_id");
    $message = "AI log entry #$log_id has been deleted.";
}

// Fetch aggregate and metric details for rendering the main overview panel stats
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE userid = '$userid'");
$user = mysqli_fetch_assoc($user_query);

$total_employees_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM users");
$total_employees = mysqli_fetch_assoc($total_employees_res)['count'];

$present_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM users WHERE status = 'Present'");
$present_count = mysqli_fetch_assoc($present_res)['count'];

$pending_leaves_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM leaves WHERE status = 'Pending'");
$pending_leaves_count = mysqli_fetch_assoc($pending_leaves_res)['count'];

$late_arrivals_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM attendance WHERE date = '$today' AND TIME(punch_in) > '10:15:00'");
$late_arrivals_count = mysqli_fetch_assoc($late_arrivals_res)['count'];

$on_leave_res = mysqli_query($conn, "SELECT COUNT(DISTINCT userid) as count FROM leaves WHERE status = 'Approved' AND '$today' BETWEEN from_date AND to_date");
$on_leave_count = mysqli_fetch_assoc($on_leave_res)['count'];

$absent_count = max(0, $total_employees - $present_count - $on_leave_count);

// Load queries for data list panels
$all_employees = mysqli_query($conn, "SELECT * FROM users ORDER BY userid ASC");
$all_leaves = mysqli_query($conn, "SELECT l.*, u.name, u.role FROM leaves l JOIN users u ON l.userid = u.userid ORDER BY l.id DESC");
$all_attendance = mysqli_query($conn, "SELECT a.*, u.name FROM attendance a JOIN users u ON a.userid = u.userid ORDER BY a.date DESC, a.punch_in DESC");
$kb_documents = mysqli_query($conn, "SELECT * FROM knowledge_base ORDER BY id DESC");

// Fetch weekly attendance details for HR admin self-audit
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$my_attendance = mysqli_query($conn, "SELECT * FROM attendance WHERE userid = '$userid' AND date BETWEEN '$week_start' AND '$week_end' ORDER BY date DESC");
$completed_hours = round($user['weekly_hours'], 2);
$required_hours = 40.0;
$remaining_hours = max(0, $required_hours - $completed_hours);
$overdue_hours = ($completed_hours > $required_hours) ? round($completed_hours - $required_hours, 2) : 0.0;

$weekend_closed = false;
$day_of_week = date('N');
if (($day_of_week >= 6) && ($completed_hours < $required_hours)) {
    $weekend_closed = true;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>HR Portal Dashboard - ERP Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            background: #f8f9fa;
            color: #333;
            overflow-x: hidden;
        }

        .sidebar {
            width: 250px;
            height: 100vh;
            background: linear-gradient(180deg, #5f2397, #2c0b4d);
            color: white;
            position: fixed;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar h3 {
            text-align: center;
            padding: 20px;
            margin: 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            font-weight: 600;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
            margin: 20px 0;
        }

        .sidebar ul li {
            padding: 12px 25px;
            cursor: pointer;
            transition: background 0.3s, padding-left 0.3s;
            font-size: 14px;
        }

        .sidebar ul li:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 30px;
        }

        .sidebar ul li.active-tab {
            background: rgba(255, 255, 255, 0.15);
            border-left: 4px solid #fff;
        }

        .main {
            flex: 1;
            margin-left: 250px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-right 0.3s ease;
        }

        .topbar {
            background: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e9ecef;
        }

        .topbar h2 {
            margin: 0;
            font-size: 20px;
            color: #5f2397;
        }

        .profile {
            width: 40px;
            height: 40px;
            background: #8e44ad;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 40px;
            font-weight: bold;
        }

        .logout-btn {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        .content {
            padding: 30px;
            flex: 1;
        }

        .section {
            display: none;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .active {
            display: block;
        }

        .overview-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .info-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            background: #fdfcff;
            text-align: center;
        }

        .info-card h4 {
            margin: 0 0 5px 0;
            color: #777;
            font-size: 13px;
        }

        .info-card p {
            margin: 0;
            font-size: 20px;
            font-weight: bold;
            color: #5f2397;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }

        table, th, td {
            border: 1px solid #e9ecef;
        }

        th, td {
            padding: 10px 12px;
            text-align: left;
        }

        th {
            background-color: #f3f0f7;
            color: #5f2397;
        }

        input, select, textarea {
            width: 100%;
            padding: 8px 10px;
            margin-top: 6px;
            margin-bottom: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }

        .btn-primary {
            background: #5f2397;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-approve {
            background: #2ecc71;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-reject {
            background: #e74c3c;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 3px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-punch-in {
            background: #2ecc71;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            margin-right: 10px;
        }

        .btn-punch-out {
            background: #e67e22;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
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
            color: #0c5460;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            padding: 12px 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .status-badge {
            padding: 3px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .status-badge.pending { background: #ffeeba; color: #856404; }
        .status-badge.approved { background: #d4edda; color: #155724; }
        .status-badge.rejected { background: #f8d7da; color: #721c24; }
        .status-badge.submitted { background: #d1ecf1; color: #0c5460; }
        .status-badge.draft { background: #e2e8f0; color: #4a5568; }
        .status-badge.original { background: #e2e8f0; color: #4a5568; }
        .status-badge.editedbyhr { background: #fff3cd; color: #856404; }
        .status-badge.approvedbytech { background: #d4edda; color: #155724; }
        .status-badge.accepted { background: #d4edda; color: #155724; }

        /* AI Management filter tabs */
        .ai-filter-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .ai-filter-tab {
            padding: 6px 16px;
            border-radius: 20px;
            border: 2px solid #e9ecef;
            background: white;
            color: #555;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .ai-filter-tab:hover { border-color: #8e44ad; color: #5f2397; }
        .ai-filter-tab.active { background: #5f2397; color: white; border-color: #5f2397; }

        .ai-stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .ai-stat-card {
            background: #fdfcff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 14px 16px;
            text-align: center;
        }
        .ai-stat-card .stat-val {
            font-size: 26px;
            font-weight: 800;
            color: #5f2397;
        }
        .ai-stat-card .stat-lbl {
            font-size: 11px;
            color: #888;
            margin-top: 2px;
        }
        .ai-stat-card.danger .stat-val { color: #e74c3c; }
        .ai-stat-card.warning .stat-val { color: #e67e22; }
        .ai-stat-card.success .stat-val { color: #27ae60; }

        .ai-search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            align-items: center;
        }
        .ai-search-bar input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 13px;
            margin: 0;
        }
        .truncate-text {
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: block;
            cursor: default;
        }

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

        /* Modal styling for editing KB */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.4);
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }

        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            width: 400px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <ul>
            <li id="tab-overview" class="active-tab" onclick="display('overview')">Dashboard</li>
            <li id="tab-attendance" onclick="display('attendance')">Attendance</li>
            <li id="tab-leaves" onclick="display('leaves')">Leave Management</li>
            <li id="tab-employees" onclick="display('employees')">Manage Employees</li>
            <li id="tab-knowledge" onclick="display('knowledge')">Knowledge Base</li>
            <li id="tab-aimgmt" onclick="display('aimgmt')">Manage AI</li>
        </ul>
    </div>

    <div class="main" id="mainContent">
        <div class="topbar">
            <h2>Dashboard</h2>
            <div style="display:flex; align-items:center; gap:15px;">
                <span style="font-weight:500;">Welcome, <?php echo htmlspecialchars($user['name']); ?> (ID: <?php echo htmlspecialchars($user['userid']); ?>)</span>
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
                    <strong>Weekend System Close Action:</strong> Your weekly hours (<?php echo $completed_hours; ?> hrs) are below the required <?php echo $required_hours; ?> hours.
                </div>
            <?php endif; ?>

            
            <div id="overview" class="section active">
                <h3>Dashboard</h3>
                
                
                <div class="overview-cards" style="margin-bottom: 30px;">
                    <div class="info-card">
                        <h4>HR Manager Name</h4>
                        <p><?php echo htmlspecialchars($user['name']); ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Department</h4>
                        <p><?php echo htmlspecialchars($user['department']); ?></p>
                    </div>
                    <div class="info-card">
                        <h4>My Status</h4>
                        <p><?php echo htmlspecialchars($user['status']); ?></p>
                    </div>
                    <div class="info-card">
                        <h4>My Leaves Left</h4>
                        <p><?php echo htmlspecialchars($user['leaves_left']); ?> Days</p>
                    </div>
                    <div class="info-card">
                        <h4>My Weekly Hours</h4>
                        <p><?php echo $completed_hours; ?> / 40.0</p>
                    </div>
                </div>

                <h3>System Overview</h3>
                <div class="overview-cards">
                    <div class="info-card">
                        <h4>Current Working Employees</h4>
                        <p><?php echo $present_count; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Total Employees</h4>
                        <p><?php echo $total_employees; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Pending Leave Approvals</h4>
                        <p><?php echo $pending_leaves_count; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Present Today</h4>
                        <p><?php echo $present_count; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Late Arrivals Today</h4>
                        <p><?php echo $late_arrivals_count; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>On Approved Leave</h4>
                        <p><?php echo $on_leave_count; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Absent Today</h4>
                        <p style="color:#e74c3c;"><?php echo $absent_count; ?></p>
                    </div>
                </div>
            </div>

            
            <div id="attendance" class="section">
                <h3>Attendance</h3>
                
                <div style="margin-bottom: 20px; background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px;">
                    <h4>My Punch Operations</h4>
                    <form method="POST" style="display: inline-block;">
                        <button type="submit" name="punch_in" class="btn-punch-in">Punch In</button>
                    </form>
                    <form method="POST" style="display: inline-block;">
                        <button type="submit" name="punch_out" class="btn-punch-out">Punch Out</button>
                    </form>
                    <div style="margin-top: 10px; font-size: 14px;">
                        My Status: <strong><?php echo htmlspecialchars($user['status']); ?></strong>
                    </div>
                </div>

                <div class="overview-cards" style="margin-bottom: 30px;">
                    <div class="info-card">
                        <h4>My Hours Worked (This Week)</h4>
                        <p><?php echo $completed_hours; ?> hrs</p>
                    </div>
                    <div class="info-card">
                        <h4>My Remaining Hours</h4>
                        <p><?php echo $remaining_hours; ?> hrs</p>
                    </div>
                </div>

                <h4 style="margin-top: 30px;">My Weekly Punch Log</h4>
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Punch In</th>
                        <th>Punch Out</th>
                        <th>Duration</th>
                    </tr>
                    <?php if (mysqli_num_rows($my_attendance) == 0): ?>
                        <tr><td colspan="4" style="text-align:center;">No personal records found.</td></tr>
                    <?php else: ?>
                        <?php while ($row = mysqli_fetch_assoc($my_attendance)): ?>
                            <tr>
                                <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                <td><?php echo $row['punch_in'] ? date('h:i A', strtotime($row['punch_in'])) : 'N/A'; ?></td>
                                <td><?php echo $row['punch_out'] ? date('h:i A', strtotime($row['punch_out'])) : 'Active'; ?></td>
                                <td><?php echo round($row['duration'], 2); ?> hrs</td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>

                <h4 style="margin-top: 40px; color: #5f2397;">All Employees' Attendance Logs</h4>
                <table>
                    <tr>
                        <th>Employee Name</th>
                        <th>Date</th>
                        <th>Punch In</th>
                        <th>Punch Out</th>
                        <th>Duration (Hours)</th>
                    </tr>
                    <?php if (mysqli_num_rows($all_attendance) == 0): ?>
                        <tr><td colspan="5" style="text-align:center;">No logs found.</td></tr>
                    <?php else: ?>
                        <?php while ($row = mysqli_fetch_assoc($all_attendance)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo date('d-m-Y', strtotime($row['date'])); ?></td>
                                <td><?php echo $row['punch_in'] ? date('h:i A', strtotime($row['punch_in'])) : 'N/A'; ?></td>
                                <td><?php echo $row['punch_out'] ? date('h:i A', strtotime($row['punch_out'])) : 'Active'; ?></td>
                                <td><?php echo round($row['duration'], 2); ?> hrs</td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="leaves" class="section">
                <h3>Leave Management</h3>
                
                <div style="background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 30px;">
                    <h4 style="margin-top:0;">Apply Leave (For Yourself)</h4>
                    <form method="POST">
                        <label>Leave Type</label>
                        <select name="leave_type" required>
                            <option value="Casual Leave">Casual Leave</option>
                            <option value="Sick Leave">Sick Leave</option>
                            <option value="Unpaid Leave">Unpaid Leave</option>
                        </select>

                        <label>From Date</label>
                        <input type="date" name="from_date" required>

                        <label>To Date</label>
                        <input type="date" name="to_date" required>

                        <label>Reason</label>
                        <textarea name="reason" rows="3" required></textarea>

                        <button type="submit" name="apply_leave" class="btn-primary">Submit Leave</button>
                    </form>
                </div>

                <h4>My Leave History</h4>
                <table style="margin-bottom: 40px;">
                    <tr>
                        <th>Type</th>
                        <th>Dates</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                    <?php 
                    $my_leaves = mysqli_query($conn, "SELECT * FROM leaves WHERE userid = '$userid' ORDER BY id DESC");
                    if (mysqli_num_rows($my_leaves) == 0): ?>
                        <tr><td colspan="4" style="text-align:center;">No leave applications.</td></tr>
                    <?php else: ?>
                        <?php while ($l = mysqli_fetch_assoc($my_leaves)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($l['leave_type']); ?></td>
                                <td><?php echo $l['from_date'] . " to " . $l['to_date']; ?></td>
                                <td><?php echo htmlspecialchars($l['reason']); ?></td>
                                <td><span class="status-badge <?php echo strtolower($l['status']); ?>"><?php echo $l['status']; ?></span></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>

                <h4 style="color: #5f2397;">Current Pending & Past Leave Approvals</h4>
                <table>
                    <tr>
                        <th>Employee ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Leave Type</th>
                        <th>Duration</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    <?php if (mysqli_num_rows($all_leaves) == 0): ?>
                        <tr><td colspan="8" style="text-align:center;">No employee leave requests.</td></tr>
                    <?php else: ?>
                        <?php while ($leave = mysqli_fetch_assoc($all_leaves)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($leave['userid']); ?></td>
                                <td><?php echo htmlspecialchars($leave['name']); ?></td>
                                <td><?php echo htmlspecialchars($leave['role']); ?></td>
                                <td><?php echo htmlspecialchars($leave['leave_type']); ?></td>
                                <td><?php echo $leave['from_date'] . " to " . $leave['to_date']; ?></td>
                                <td><?php echo htmlspecialchars($leave['reason']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo strtolower($leave['status']); ?>">
                                        <?php echo htmlspecialchars($leave['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($leave['status'] === 'Pending'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">
                                            <button type="submit" name="approve_leave" class="btn-approve">Approve</button>
                                        </form>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="leave_id" value="<?php echo $leave['id']; ?>">
                                            <button type="submit" name="reject_leave" class="btn-reject">Reject</button>
                                        </form>
                                    <?php else: ?>
                                        Reviewed
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="employees" class="section">
                <h3>Create New Employee</h3>
                <form method="POST" style="background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 30px;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label>Employee ID (User ID)</label>
                            <input type="text" name="emp_id" placeholder="e.g. 1005" required>
                        </div>
                        <div>
                            <label>Full Name</label>
                            <input type="text" name="emp_name" placeholder="e.g. John Doe" required>
                        </div>
                        <div>
                            <label>Password</label>
                            <input type="password" name="emp_pass" placeholder="Password" required>
                        </div>
                        <div>
                            <label>Email Address</label>
                            <input type="email" name="emp_email" placeholder="e.g. john@company.com" required>
                        </div>
                        <div>
                            <label>Role</label>
                            <select name="emp_role">
                                <option value="employee">Employee</option>
                                <option value="Hr_admin">HR Admin</option>
                                <option value="Technical_admin">Technical Admin</option>
                            </select>
                        </div>
                        <div>
                            <label>Department</label>
                            <input type="text" name="emp_dept" placeholder="e.g. IT, HR, Sales">
                        </div>
                    </div>
                    <button type="submit" name="create_employee" class="btn-primary" style="margin-top: 10px;">Register Employee</button>
                </form>

                <h3>All Registered Users</h3>
                <div style="margin:20px 0;">
    <input
        type="text"
        id="employeeSearch"
        placeholder="Search Employee by ID, Name, Role..."
        style="width:350px; padding:10px;">
    </div>
                <table id="employeeTable">
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Department</th>
                        <th>Leaves Remaining</th>
                        <th>Weekly Hours</th>
                        <th>Status</th>
                    </tr>
                    <?php 
                    
                    $all_employees = mysqli_query($conn, "SELECT * FROM users ORDER BY userid ASC");
                    while ($emp = mysqli_fetch_assoc($all_employees)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($emp['userid']); ?></td>
                            <td><?php echo htmlspecialchars($emp['name']); ?></td>
                            <td><?php echo htmlspecialchars($emp['email']); ?></td>
                            <td><?php echo htmlspecialchars($emp['role']); ?></td>
                            <td><?php echo htmlspecialchars($emp['department'] ?: 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($emp['leaves_left']); ?></td>
                            <td><?php echo round($emp['weekly_hours'], 2); ?> hrs</td>
                            <td><?php echo htmlspecialchars($emp['status']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                </table>
            </div>

            
            <div id="knowledge" class="section">
                <h3>Knowledge Base Management</h3>
                <p>Submit and Edit documents and policies for AI assistant integration. Technical Admin approval is required to activate documents.</p>
                
                <form action="upload.php" method="POST" enctype="multipart/form-data" style="background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 30px;">
                    <h4 style="margin-top:0;">Submit Policy Document</h4>
                    <label>Document Title</label>
                    <input type="text" name="doc_title" placeholder="e.g. Leave Policy 2026" required>
                    
                    <label>Select Document File (.pdf, .docx, .txt)</label>
                    <input type="file" name="document" accept=".pdf,.docx,.txt" required>
                    
                    <button type="submit" name="submit_document" class="btn-primary">Submit Document</button>
                </form>

                <h4>Knowledge Documents</h4>
                <table>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>File Path</th>
                        <th>Status</th>
                        <th>Submitted By</th>
                        <th>Document</th>
                        <th>Action</th>
                    </tr>
                    <?php if (mysqli_num_rows($kb_documents) == 0): ?>
                        <tr><td colspan="7" style="text-align:center;">No documents uploaded yet.</td></tr>
                    <?php else: ?>
                        <?php while ($doc = mysqli_fetch_assoc($kb_documents)): ?>
                            <tr>
                                <td><?php echo $doc['id']; ?></td>
                                <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                <td><code><?php echo htmlspecialchars($doc['file_path']); ?></code></td>
                                <td>
                                    <span class="status-badge <?php echo strtolower($doc['status']); ?>">
                                        <?php echo htmlspecialchars($doc['status']); ?>
                                    </span>
                                    <?php if (!empty($doc['rag_document_id'])): ?>
                                        <div class="rag-status" data-rag-id="<?php echo htmlspecialchars($doc['rag_document_id']); ?>" style="font-size:11px; color:#555; margin-top:5px; font-style:italic;">
                                            RAG: checking...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($doc['uploaded_by']); ?></td>
                                <td>
                                    <a href="view_document.php?kb_id=<?php echo $doc['id']; ?>" target="_blank" style="color: #5f2397; font-weight: bold; text-decoration: none;">Open PDF</a>
                                </td>
                                <td>
                                    <button class="btn-approve" onclick='openEditModal(<?php echo json_encode($doc); ?>)'>Edit</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="aimgmt" class="section">
                <h3>&#129302; Manage AI &amp; Queries</h3>
                <p>Review AI response logs, track hallucinations, and manually correct responses. Low-accuracy or hallucinated queries can be edited and submitted to Tech Admin for final approval.</p>

                <?php
                // Aggregate AI stats for this section
                $ai_total_res    = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs");
                $ai_total        = mysqli_fetch_assoc($ai_total_res)['c'];
                $ai_flagged_res  = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE performance_score < 80 OR hallucination_detected = 1");
                $ai_flagged      = mysqli_fetch_assoc($ai_flagged_res)['c'];
                $ai_edited_res   = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE review_status = 'Edited_by_HR'");
                $ai_edited       = mysqli_fetch_assoc($ai_edited_res)['c'];
                $ai_approved_res = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE review_status = 'Approved_by_Tech'");
                $ai_approved     = mysqli_fetch_assoc($ai_approved_res)['c'];
                $ai_halluc_res   = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE hallucination_detected = 1");
                $ai_halluc       = mysqli_fetch_assoc($ai_halluc_res)['c'];
                ?>

                <!-- Stats Cards -->
                <div class="ai-stats-row">
                    <div class="ai-stat-card">
                        <div class="stat-val"><?php echo $ai_total; ?></div>
                        <div class="stat-lbl">Total Queries</div>
                    </div>
                    <div class="ai-stat-card danger">
                        <div class="stat-val"><?php echo $ai_flagged; ?></div>
                        <div class="stat-lbl">Flagged / Low Accuracy</div>
                    </div>
                    <div class="ai-stat-card danger">
                        <div class="stat-val"><?php echo $ai_halluc; ?></div>
                        <div class="stat-lbl">Hallucinations</div>
                    </div>
                    <div class="ai-stat-card warning">
                        <div class="stat-val"><?php echo $ai_edited; ?></div>
                        <div class="stat-lbl">Pending Tech Review</div>
                    </div>
                    <div class="ai-stat-card success">
                        <div class="stat-val"><?php echo $ai_approved; ?></div>
                        <div class="stat-lbl">Approved by Tech</div>
                    </div>
                </div>

                <!-- Search + Filter Bar -->
                <div class="ai-search-bar">
                    <input type="text" id="aiLogSearch" placeholder="&#128269; Search by query, user, or response..." oninput="filterAILogs()">
                </div>
                <div class="ai-filter-tabs">
                    <button class="ai-filter-tab active" onclick="setAIFilter('all', this)">All (<?php echo $ai_total; ?>)</button>
                    <button class="ai-filter-tab" onclick="setAIFilter('flagged', this)">&#128680; Flagged (<?php echo $ai_flagged; ?>)</button>
                    <button class="ai-filter-tab" onclick="setAIFilter('editedbyhr', this)">&#9999; Edited by HR (<?php echo $ai_edited; ?>)</button>
                    <button class="ai-filter-tab" onclick="setAIFilter('approvedbytech', this)">&#10003; Approved (<?php echo $ai_approved; ?>)</button>
                    <button class="ai-filter-tab" onclick="setAIFilter('original', this)">Original</button>
                </div>

                <table id="aiLogsTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Query</th>
                            <th>AI Response</th>
                            <th>Accuracy</th>
                            <th>Hallucination</th>
                            <th>Review Status</th>
                            <th>Edited Response</th>
                            <th>Review Notes</th>
                            <th>Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $ai_logs_hr = mysqli_query($conn, "SELECT * FROM ai_logs ORDER BY id DESC");
                    if (mysqli_num_rows($ai_logs_hr) == 0): ?>
                        <tr><td colspan="11" style="text-align:center; padding:20px; color:#888;">&#128203; No AI queries logged yet. Queries will appear here once employees use the AI chatbot.</td></tr>
                    <?php else:
                        while ($log = mysqli_fetch_assoc($ai_logs_hr)):
                            $is_flagged = ($log['performance_score'] < 80 || $log['hallucination_detected'] == 1);
                            $status_cls = strtolower(str_replace('_', '', $log['review_status']));
                            $row_data_status = $status_cls;
                            $row_data_flagged = $is_flagged ? 'flagged' : 'ok';
                            $row_bg = $is_flagged ? "background:#fff5f5;" : "";
                    ?>
                        <tr data-status="<?php echo $row_data_status; ?>" data-flagged="<?php echo $row_data_flagged; ?>" style="<?php echo $row_bg; ?>">
                            <td><strong>#<?php echo $log['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($log['userid']); ?></td>
                            <td>
                                <span class="truncate-text" title="<?php echo htmlspecialchars($log['query']); ?>">
                                    <?php echo htmlspecialchars($log['query']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="truncate-text" title="<?php echo htmlspecialchars($log['response']); ?>">
                                    <?php echo htmlspecialchars($log['response']); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight:bold; color:<?php echo ($log['performance_score'] < 80) ? '#e74c3c' : '#27ae60'; ?>">
                                    <?php echo $log['performance_score']; ?>%
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($log['hallucination_detected']): ?>
                                    <span style="color:#e74c3c; font-weight:bold;">&#9888; YES</span>
                                <?php else: ?>
                                    <span style="color:#27ae60;">NO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $status_cls; ?>">
                                    <?php echo htmlspecialchars($log['review_status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log['edited_response']): ?>
                                    <span class="truncate-text" style="color:#2980b9;" title="<?php echo htmlspecialchars($log['edited_response']); ?>">
                                        <em><?php echo htmlspecialchars($log['edited_response']); ?></em>
                                    </span>
                                <?php else: ?>
                                    <span style="color:#aaa; font-size:12px;">&#8212;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="truncate-text" style="font-size:12px; color:#666;" title="<?php echo htmlspecialchars($log['review_notes'] ?: ''); ?>">
                                    <?php echo $log['review_notes'] ? htmlspecialchars($log['review_notes']) : '<span style="color:#aaa;">&#8212;</span>'; ?>
                                </span>
                            </td>
                            <td style="white-space:nowrap; font-size:11px; color:#888;">
                                <?php echo date('d M y, H:i', strtotime($log['timestamp'])); ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <div style="display:flex; flex-direction:column; gap:5px;">
                                    <?php if ($log['review_status'] !== 'Approved_by_Tech'): ?>
                                        <button class="btn-approve" style="font-size:11px; padding:4px 8px;" onclick='openAIEditModal(<?php echo json_encode($log); ?>)'>&#9999; Edit &amp; Submit</button>
                                    <?php else: ?>
                                        <button class="btn-approve" style="font-size:11px; padding:4px 8px; opacity:0.6;" onclick='openAIEditModal(<?php echo json_encode($log); ?>)'>&#128065; View</button>
                                    <?php endif; ?>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete AI log #<?php echo $log['id']; ?>? This cannot be undone.');">
                                        <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                        <button type="submit" name="delete_ai_log" class="btn-reject" style="font-size:11px; padding:4px 8px; width:100%;">&#128465; Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
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
                
                function togglePassVisibility() {
                    const passInput = document.getElementById('profilePass');
                    if (passInput.type === 'password') {
                        passInput.type = 'text';
                    } else {
                        passInput.type = 'password';
                    }
                }
                function searchEmployees(){

    let input=document.getElementById("employeeSearch");
    let filter=input.value.toLowerCase();

    let table=document.getElementById("employeeTable");

    let rows=table.getElementsByTagName("tr");

    for(let i=1;i<rows.length;i++){

        let text=rows[i].textContent.toLowerCase();

        rows[i].style.display=
            text.includes(filter) ? "" : "none";
    }
}

                function openEditModal(doc) {
                    document.getElementById('edit_doc_id').value = doc.id;
                    document.getElementById('edit_doc_title').value = doc.title;
                    document.getElementById('edit_doc_file_path').value = doc.file_path;
                    document.getElementById('editModal').style.display = 'flex';
                }
                function closeEditModal() {
                    document.getElementById('editModal').style.display = 'none';
                }

                function openAIEditModal(log) {
                    document.getElementById('edit_log_id').value = log.id;
                    document.getElementById('review_query_text').textContent = log.query;
                    document.getElementById('review_original_text').textContent = log.response;
                    document.getElementById('edit_edited_response').value = log.edited_response || log.response;
                    document.getElementById('edit_review_notes').value = log.review_notes || '';
                    document.getElementById('aiEditModal').style.display = 'flex';
                }
                function closeAIEditModal() {
                    document.getElementById('aiEditModal').style.display = 'none';
                }

                // AI Log filter tab state
                let currentAIFilter = 'all';

                function setAIFilter(filter, btn) {
                    currentAIFilter = filter;
                    document.querySelectorAll('.ai-filter-tab').forEach(t => t.classList.remove('active'));
                    btn.classList.add('active');
                    applyAIFilters();
                }

                function filterAILogs() {
                    applyAIFilters();
                }

                function applyAIFilters() {
                    const searchVal = (document.getElementById('aiLogSearch')?.value || '').toLowerCase();
                    const rows = document.querySelectorAll('#aiLogsTable tbody tr');
                    rows.forEach(row => {
                        const status = row.getAttribute('data-status') || '';
                        const flagged = row.getAttribute('data-flagged') || '';
                        const text = row.textContent.toLowerCase();

                        // Status filter
                        let statusMatch = false;
                        if (currentAIFilter === 'all') statusMatch = true;
                        else if (currentAIFilter === 'flagged') statusMatch = (flagged === 'flagged');
                        else statusMatch = (status === currentAIFilter);

                        // Search filter
                        const searchMatch = !searchVal || text.includes(searchVal);

                        row.style.display = (statusMatch && searchMatch) ? '' : 'none';
                    });
                }
            </script>
            
            </div>

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
                <option value="Manage Employees">&#128101; Manage Employees</option>
                <option value="Knowledge Base">&#128218; Knowledge Base</option>
                <option value="Manage AI">&#129302; Manage AI</option>
            </select>
        </div>
        <div id="filterBadge" style="display:none; background:#eaf0fb; color:#2c5f9e; font-size:11px; font-weight:bold; padding:5px 15px; border-bottom:1px solid #d0ddf7;">&#128274; Filtered: <span id="filterBadgeText"></span> &mdash; AI will only answer within this scope.</div>
        <div class="chat-container" id="chatContainer">
            
        </div>
        <div class="chat-input-area">
            <input type="text" id="chatInput" placeholder="Type your message..." onkeypress="handleChatKey(event)">
            <button onclick="sendChatMessage()">Send</button>
        </div>
    </div>

    
    <div class="modal" id="editModal">
        <div class="modal-content">
            <h3 style="margin-top:0;">Edit Document</h3>
            <form action="upload.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="edit_doc_id" name="doc_id">
                <label>Document Title</label>
                <input type="text" id="edit_doc_title" name="doc_title" required>
                <label>Upload Replacement Document File (.pdf, .docx, .txt)</label>
                <input type="file" name="document" accept=".pdf,.docx,.txt" required>
                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                    <button type="button" onclick="closeEditModal()" style="background:#ccc; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" name="edit_document" class="btn-primary" style="padding:8px 15px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    
    <div class="modal" id="aiEditModal">
        <div class="modal-content" style="width:500px;">
            <h3 style="margin-top:0;">Edit & Review AI Response</h3>
            <form method="POST">
                <input type="hidden" id="edit_log_id" name="log_id">
                <label>User Query</label>
                <p id="review_query_text" style="background:#f3f0f7; padding:10px; border-radius:4px; font-size:13px; font-weight:bold;"></p>
                
                <label>Original AI Response</label>
                <p id="review_original_text" style="background:#fdfcff; padding:10px; border-radius:4px; font-size:12px; border:1px solid #eee; max-height:100px; overflow-y:auto;"></p>
                
                <label>Manually Corrected Response</label>
                <textarea id="edit_edited_response" name="edited_response" rows="4" placeholder="Type the correct response to show to the AI model..." required></textarea>
                
                <label>Review Notes (unsolvable reason, misinterpretation, etc.)</label>
                <textarea id="edit_review_notes" name="review_notes" rows="2" placeholder="e.g. AI hallucinated, policy changed, etc."></textarea>
                
                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                    <button type="button" onclick="closeAIEditModal()" style="background:#ccc; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" name="submit_ai_review" class="btn-primary" style="padding:8px 15px;">Submit Review</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleAIChat() {
            const drawer = document.getElementById('aiChatDrawer');
            if (drawer.style.right === '0px') {
                drawer.style.right = '-400px';
            } else {
                drawer.style.right = '0px';
            }
        }

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

        function handleChatKey(e) {
            if (e.key === 'Enter') {
                sendChatMessage();
            }
        }

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

        // RAG Status Polling
        document.addEventListener('DOMContentLoaded', () => {
            const ragElements = document.querySelectorAll('.rag-status');
            
            function pollStatus(element) {
                const docId = element.getAttribute('data-rag-id');
                if (!docId) return;
                
                fetch(`rag_status.php?doc_id=${docId}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.status) {
                            let label = data.status_label || data.status;
                            if (data.status === 'ready') {
                                label = `Ready (${data.chunk_count} chunks)`;
                                element.style.color = '#2ecc71';
                                element.style.fontWeight = 'bold';
                            } else if (data.status === 'error') {
                                label = `Error: ${data.error_message || 'Pipeline failed'}`;
                                element.style.color = '#e74c3c';
                                element.style.fontWeight = 'bold';
                            } else {
                                element.style.color = '#e67e22';
                                // Keep polling if not finished or in error
                                setTimeout(() => pollStatus(element), 5000);
                            }
                            element.textContent = `RAG: ${label}`;
                        }
                    })
                    .catch(err => {
                        console.error('RAG poll error:', err);
                        element.textContent = 'RAG: offline';
                    });
            }
            
            ragElements.forEach(pollStatus);
        });
    </script>

</body>
</html>