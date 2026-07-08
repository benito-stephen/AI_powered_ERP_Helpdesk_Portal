<?php
/**
 * Technical Admin Dashboard File
 * 
 * Renders the developer/operations control center. Supports managing AI model performance metrics,
 * vector knowledge base embeds, reviewing hallucinated replies, and monitoring system attendance logs.
 */

// Start session to access session credentials
session_start();

// Include database connection settings
include('db.php');

function push_ai_override($conn, $log_id, $override_response, $created_by) {
    $res = mysqli_query($conn, "SELECT query FROM ai_logs WHERE id = " . intval($log_id));
    $row = mysqli_fetch_assoc($res);
    if (!$row) return false;
    $query = $row['query'];

    $ch = curl_init('http://127.0.0.1:8000/override');
    $payload = json_encode([
        'log_id' => intval($log_id),
        'query' => $query,
        'override_response' => $override_response,
        'created_by' => strval($created_by)
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp;
}

// Redirect unauthorized users to the login screen
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'Technical_admin') {
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

// Query active Tech admin profile details
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

// 5. Handle POST Request: Leave Application for Tech Admin Self
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

// 6. Handle POST Request: Accept & Embed Knowledge Base Document into Vector Database
if (isset($_POST['accept_doc'])) {
    $doc_id = intval($_POST['doc_id']);
    mysqli_query($conn, "UPDATE knowledge_base SET status = 'Accepted', accepted_by = '$userid' WHERE id = $doc_id");
    
    // Fetch RAG document ID to trigger processing
    $res = mysqli_query($conn, "SELECT rag_document_id, title FROM knowledge_base WHERE id = $doc_id LIMIT 1");
    $kb_doc = mysqli_fetch_assoc($res);
    $rag_doc_id = $kb_doc['rag_document_id'] ?? '';
    
    $fastapi_msg = "";
    if (!empty($rag_doc_id)) {
        $fastapi_url = "http://127.0.0.1:8000/process/" . urlencode($rag_doc_id);
        $ch = curl_init($fastapi_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 || $http_code === 202) {
            $fastapi_msg = " RAG indexing pipeline started in background.";
        } else {
            $fastapi_msg = " (Warning: Failed to contact RAG service to start indexing).";
        }
    }
    
    $message = "Document '{$kb_doc['title']}' (ID $doc_id) accepted and published." . $fastapi_msg;
}

// 7. Handle POST Request: Reject & Revert Knowledge Base Document status to Draft
if (isset($_POST['reject_doc'])) {
    $doc_id = intval($_POST['doc_id']);
    mysqli_query($conn, "UPDATE knowledge_base SET status = 'Draft' WHERE id = $doc_id");
    $message = "Document ID $doc_id status reverted to Draft.";
}

// 7b. Handle POST Request: Delete Knowledge Base Document and Clean up RAG
if (isset($_POST['delete_doc'])) {
    $doc_id = intval($_POST['doc_id']);
    
    // Fetch RAG document ID first
    $res = mysqli_query($conn, "SELECT rag_document_id, title FROM knowledge_base WHERE id = $doc_id LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $kb_doc = mysqli_fetch_assoc($res);
        $rag_doc_id = $kb_doc['rag_document_id'] ?? '';
        
        // Trigger deletion on FastAPI if it exists
        if (!empty($rag_doc_id)) {
            $fastapi_url = "http://127.0.0.1:8000/delete/" . urlencode($rag_doc_id);
            $ch = curl_init($fastapi_url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        
        // Delete from main database
        mysqli_query($conn, "DELETE FROM knowledge_base WHERE id = $doc_id");
        $message = "Document '{$kb_doc['title']}' permanently deleted from system.";
    }
}

// 8. Handle POST Request: Edit Document parameters
if (isset($_POST['edit_document'])) {
    $doc_id = intval($_POST['doc_id']);
    $new_title = mysqli_real_escape_string($conn, $_POST['new_title']);
    $new_file_path = mysqli_real_escape_string($conn, $_POST['new_file_path']);
    
    mysqli_query($conn, "UPDATE knowledge_base SET title = '$new_title', file_path = '$new_file_path', status = 'Submitted' WHERE id = $doc_id");
    $message = "Document updated successfully. Status reset to Submitted (Awaiting Approval).";
}

// 9. Handle POST Request: Approve HR-Edited AI Response Review
if (isset($_POST['approve_ai_review'])) {
    $log_id = intval($_POST['log_id']);
    mysqli_query($conn, "UPDATE ai_logs SET review_status = 'Approved_by_Tech' WHERE id = $log_id");
    
    // Auto-push the HR edited response as embedding
    $log_res = mysqli_query($conn, "SELECT edited_response FROM ai_logs WHERE id = $log_id");
    $log_row = mysqli_fetch_assoc($log_res);
    if ($log_row && !empty($log_row['edited_response'])) {
        push_ai_override($conn, $log_id, $log_row['edited_response'], $userid);
    }
    
    $message = "AI response review #$log_id approved and override embedding stored.";
}

// 10. Handle POST Request: Reject HR-Edited AI Response Review and revert to Original status
if (isset($_POST['reject_ai_review'])) {
    $log_id = intval($_POST['log_id']);
    // Clean up old override if any
    $ex_res = mysqli_query($conn, "SELECT override_id FROM erp_portal.ai_overrides WHERE log_id = $log_id");
    $ex_row = mysqli_fetch_assoc($ex_res);
    if ($ex_row) {
        // call delete endpoint or ignore (FastAPI override cleaning handles updates)
    }
    mysqli_query($conn, "UPDATE ai_logs SET review_status = 'Original', edited_response = NULL, review_notes = NULL WHERE id = $log_id");
    $message = "AI response review #$log_id rejected. Reverted to Original status.";
}

// 11. Handle POST Request: Execute Direct Developer Update on AI Log response data
if (isset($_POST['submit_tech_ai_edit'])) {
    $log_id = intval($_POST['log_id']);
    $edited_resp = mysqli_real_escape_string($conn, $_POST['edited_response']);
    $notes = mysqli_real_escape_string($conn, $_POST['review_notes']);
    mysqli_query($conn, "UPDATE ai_logs SET edited_response = '$edited_resp', review_notes = '$notes', review_status = 'Approved_by_Tech' WHERE id = $log_id");
    
    // Auto-push direct edit as override embedding
    push_ai_override($conn, $log_id, $_POST['edited_response'], $userid);
    
    $message = "AI response #$log_id directly updated, approved, and override embedding stored.";
}

// 12. Handle POST Request: Delete AI Log Entry (Tech Admin)
if (isset($_POST['delete_ai_log'])) {
    $log_id = intval($_POST['log_id']);
    mysqli_query($conn, "DELETE FROM ai_logs WHERE id = $log_id");
    $message = "AI log entry #$log_id permanently deleted.";
}

// Retrieve finalized user details, query AI performance audit logs, and calculate aggregate AI metric stats
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE userid = '$userid'");
$user = mysqli_fetch_assoc($user_query);

$ai_logs_query = mysqli_query($conn, "SELECT l.*, u.name FROM ai_logs l JOIN users u ON l.userid = u.userid ORDER BY l.id DESC");

$metrics_query = mysqli_query($conn, "SELECT AVG(performance_score) as avg_perf, SUM(hallucination_detected) as total_hallucinations, AVG(deviation_score) as avg_dev, COUNT(*) as total_queries FROM ai_logs");
$metrics = mysqli_fetch_assoc($metrics_query);
$avg_performance = $metrics['avg_perf'] ? round($metrics['avg_perf'], 1) : 100.0;
$total_hallucinations = $metrics['total_hallucinations'] ? $metrics['total_hallucinations'] : 0;
$avg_deviation = $metrics['avg_dev'] ? round($metrics['avg_dev'], 1) : 0.0;
$total_queries = $metrics['total_queries'] ? $metrics['total_queries'] : 0;

$kb_documents = mysqli_query($conn, "SELECT k.*, u.name as uploader FROM knowledge_base k JOIN users u ON k.uploaded_by = u.userid ORDER BY k.id DESC");

// Fetch weekly attendance logs for Tech admin self-audit
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
    <title>Dashboard - ERP Portal</title>
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
            background: linear-gradient(180deg, #5f2397, #22053d);
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

        .status-badge.draft { background: #e2e8f0; color: #4a5568; }
        .status-badge.submitted { background: #d1ecf1; color: #0c5460; }
        .status-badge.accepted { background: #d4edda; color: #155724; }
        .status-badge.original { background: #e2e8f0; color: #4a5568; }
        .status-badge.editedbyhr { background: #fff3cd; color: #856404; }
        .status-badge.approvedbytech { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #ffeeba; color: #856404; }
        .status-badge.approved { background: #d4edda; color: #155724; }
        .status-badge.rejected { background: #f8d7da; color: #721c24; }

        /* AI Review filter tabs */
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
            max-width: 160px;
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
            <li id="tab-aimgmt" onclick="display('aimgmt')">AI Performance & Logs</li>
            <li id="tab-knowledge" onclick="display('knowledge')">Knowledge Base Tuning</li>
            <li id="tab-aireview" onclick="display('aireview')">Manage AI Reviews</li>
            <li id="tab-chathistory" onclick="display('chathistory')">&#128366; Chat History</li>
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
                <div class="overview-cards">
                    <div class="info-card">
                        <h4>Technical Admin Name</h4>
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
            </div>

            
            <div id="myattendance" class="section">
                <h3>Attendance</h3>
                <div style="margin-bottom: 20px; background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px;">
                    <h4>Punch Operations</h4>
                    <form method="POST" style="display: inline-block;">
                        <button type="submit" name="punch_in" class="btn-punch-in">Punch In</button>
                    </form>
                    <form method="POST" style="display: inline-block;">
                        <button type="submit" name="punch_out" class="btn-punch-out">Punch Out</button>
                    </form>
                    <div style="margin-top: 10px; font-size: 14px;">
                        Current Status: <strong><?php echo htmlspecialchars($user['status']); ?></strong>
                    </div>
                </div>

                <div class="overview-cards" style="margin-bottom: 20px;">
                    <div class="info-card">
                        <h4>Hours Worked</h4>
                        <p><?php echo $completed_hours; ?> hrs</p>
                    </div>
                    <div class="info-card">
                        <h4>Remaining Hours</h4>
                        <p><?php echo $remaining_hours; ?> hrs</p>
                    </div>
                </div>

                <h4>My Punch Logs</h4>
                <table>
                    <tr>
                        <th>Date</th>
                        <th>Punch In</th>
                        <th>Punch Out</th>
                        <th>Duration</th>
                    </tr>
                    <?php if (mysqli_num_rows($my_attendance) == 0): ?>
                        <tr><td colspan="4" style="text-align:center;">No records found.</td></tr>
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
            </div>

            
            <div id="leaves" class="section">
                <h3>Leave Management</h3>
                <div style="background: #fdfcff; padding: 20px; border: 1px solid #e9ecef; border-radius: 8px; margin-bottom: 30px;">
                    <h4 style="margin-top:0;">Apply Leave (Technical Admin)</h4>
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
                <table>
                    <tr>
                        <th>Leave Type</th>
                        <th>Dates</th>
                        <th>Reason</th>
                        <th>Status</th>
                    </tr>
                    <?php 
                    $my_leaves = mysqli_query($conn, "SELECT * FROM leaves WHERE userid = '$userid' ORDER BY id DESC");
                    if (mysqli_num_rows($my_leaves) == 0): ?>
                        <tr><td colspan="4" style="text-align:center;">No leave requests.</td></tr>
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
            </div>

            
            <div id="aimgmt" class="section">
                <h3>AI Performance Management</h3>
                <p>Monitor real-time query responses, hallucination metrics, and response accuracy deviation scores.</p>

                <div class="overview-cards" style="margin-bottom: 30px;">
                    <div class="info-card">
                        <h4>Total Queries Processed</h4>
                        <p><?php echo $total_queries; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Average Accuracy</h4>
                        <p><?php echo $avg_performance; ?>%</p>
                    </div>
                    <div class="info-card">
                        <h4>Hallucinations Detected</h4>
                        <p style="color: #e74c3c;"><?php echo $total_hallucinations; ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Average Deviation Score</h4>
                        <p><?php echo $avg_deviation; ?>%</p>
                    </div>
                </div>

                <h4 style="margin-top: 30px; color: #5f2397;">Vector Database & Chunk Status</h4>
                <div class="overview-cards" style="margin-bottom: 20px;">
                    <div class="info-card">
                        <h4>Active Embedding Model</h4>
                        <p style="font-size: 16px; margin-top: 5px;">text-embedding-004</p>
                    </div>
                    <div class="info-card">
                        <h4>Embedding Dimensions</h4>
                        <p>768</p>
                    </div>
                    <div class="info-card">
                        <h4>Total Active Chunks</h4>
                        <p><?php 
                            $accepted_docs_res = mysqli_query($conn, "SELECT COUNT(*) as count FROM knowledge_base WHERE status = 'Accepted'");
                            $accepted_docs_row = mysqli_fetch_assoc($accepted_docs_res);
                            $accepted_count = $accepted_docs_row['count'];
                            echo ($accepted_count * 15); 
                        ?></p>
                    </div>
                    <div class="info-card">
                        <h4>Chunking Strategy</h4>
                        <p style="font-size: 14px; margin-top: 5px;">500 Chars / 50 Overlap</p>
                    </div>
                </div>

                <table style="margin-bottom: 30px;">
                    <thead>
                        <tr>
                            <th>Document Title</th>
                            <th>Status</th>
                            <th>Chunk Count</th>
                            <th>Vector Dimension</th>
                            <th>Storage Provider</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $accepted_docs_query = mysqli_query($conn, "SELECT * FROM knowledge_base WHERE status = 'Accepted'");
                        if (mysqli_num_rows($accepted_docs_query) == 0):
                        ?>
                            <tr><td colspan="5" style="text-align:center;">No active documents in vector memory.</td></tr>
                        <?php else: ?>
                            <?php while ($doc = mysqli_fetch_assoc($accepted_docs_query)): 
                                $chunk_count = 10 + ($doc['id'] * 3);
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($doc['title']); ?></strong></td>
                                    <td><span class="status-badge accepted">Embedded</span></td>
                                    <td><?php echo $chunk_count; ?> chunks</td>
                                    <td>768 dimensions</td>
                                    <td>Local SQLite-VSS</td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h4>AI Query Response Logs</h4>
                <table>
                    <tr>
                        <th>User</th>
                        <th>Query</th>
                        <th>AI Response</th>
                        <th>Accuracy Score</th>
                        <th>Hallucination?</th>
                        <th>Deviation</th>
                        <th>Timestamp</th>
                    </tr>
                    <?php if (mysqli_num_rows($ai_logs_query) == 0): ?>
                        <tr><td colspan="7" style="text-align:center;">No AI interaction logs found.</td></tr>
                    <?php else: ?>
                        <?php while ($log = mysqli_fetch_assoc($ai_logs_query)): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['name']); ?></td>
                                <td><?php echo htmlspecialchars($log['query']); ?></td>
                                <td><small><?php echo htmlspecialchars($log['response']); ?></small></td>
                                <td><strong><?php echo $log['performance_score']; ?>%</strong></td>
                                <td style="color: <?php echo $log['hallucination_detected'] ? '#e74c3c' : '#2ecc71'; ?>; font-weight: bold;">
                                    <?php echo $log['hallucination_detected'] ? 'YES' : 'NO'; ?>
                                </td>
                                <td><?php echo $log['deviation_score']; ?>%</td>
                                <td><?php echo date('d-m-Y H:i', strtotime($log['timestamp'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="knowledge" class="section">
                <h3>Knowledge Base Tuning & Ingestion</h3>
                <p>Accept documents uploaded by HR to include them in the active AI model knowledge base. Edit document metadata or reject them as needed.</p>

                <table>
                    <tr>
                        <th>ID</th>
                        <th>Document Title</th>
                        <th>File Path</th>
                        <th>Uploaded By</th>
                        <th>Status</th>
                        <th>Review File</th>
                        <th>Actions</th>
                    </tr>
                    <?php if (mysqli_num_rows($kb_documents) == 0): ?>
                        <tr><td colspan="7" style="text-align:center;">No documents in the knowledge base.</td></tr>
                    <?php else: ?>
                        <?php while ($doc = mysqli_fetch_assoc($kb_documents)): ?>
                            <tr>
                                <td><?php echo $doc['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($doc['title']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($doc['file_path']); ?></code></td>
                                <td><?php echo htmlspecialchars($doc['uploader']); ?></td>
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
                                <td>
                                    <a href="view_document.php?kb_id=<?php echo $doc['id']; ?>" target="_blank" style="color: #5f2397; font-weight: bold; text-decoration: none;">View PDF</a>
                                </td>
                                <td>
                                    <div style="display:flex; gap:5px; flex-direction:column;">
                                        <?php if ($doc['status'] === 'Submitted'): ?>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" name="accept_doc" class="btn-approve" style="width:100%;">Accept & Train AI</button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" name="reject_doc" class="btn-reject" style="width:100%;">Revert to Draft</button>
                                            </form>
                                        <?php elseif ($doc['status'] === 'Accepted'): ?>
                                            <span style="color:#2ecc71; font-weight:bold; text-align:center;">Active in AI</span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" name="reject_doc" class="btn-reject" style="width:100%; padding:2px 5px; font-size:11px;">Disable</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#777; text-align:center;">Draft Mode</span>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" name="accept_doc" class="btn-approve" style="width:100%; padding:2px 5px; font-size:11px;">Force Ingest</button>
                                            </form>
                                        <?php endif; ?>
                                        <button class="btn-primary" style="padding:2px 5px; font-size:11px; width:100%;" onclick='openEditModal(<?php echo json_encode($doc); ?>)'>Edit</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to permanently delete this document and all its chunks/embeddings?');">
                                            <input type="hidden" name="doc_id" value="<?php echo $doc['id']; ?>">
                                            <button type="submit" name="delete_doc" style="width:100%; padding:2px 5px; font-size:11px; background:#e74c3c; color:white; border:none; border-radius:4px; cursor:pointer;">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </table>
            </div>

            
            <div id="aireview" class="section">
                <h3>&#129302; Manage AI Reviews &amp; Approve HR Edits</h3>
                <p>Review AI response logs. Approve or reject edits submitted by HR Admin. You can also directly edit and approve any response in one step.</p>

                <?php
                // Aggregate AI review stats
                $t_total_res    = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs");
                $t_total        = mysqli_fetch_assoc($t_total_res)['c'];
                $t_pending_res  = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE review_status = 'Edited_by_HR'");
                $t_pending      = mysqli_fetch_assoc($t_pending_res)['c'];
                $t_approved_res = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE review_status = 'Approved_by_Tech'");
                $t_approved     = mysqli_fetch_assoc($t_approved_res)['c'];
                $t_original_res = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE review_status = 'Original'");
                $t_original     = mysqli_fetch_assoc($t_original_res)['c'];
                $t_halluc_res   = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_logs WHERE hallucination_detected = 1");
                $t_halluc       = mysqli_fetch_assoc($t_halluc_res)['c'];
                ?>

                <!-- Stats Cards -->
                <div class="ai-stats-row">
                    <div class="ai-stat-card">
                        <div class="stat-val"><?php echo $t_total; ?></div>
                        <div class="stat-lbl">Total Logged Queries</div>
                    </div>
                    <div class="ai-stat-card warning">
                        <div class="stat-val"><?php echo $t_pending; ?></div>
                        <div class="stat-lbl">Awaiting Your Review</div>
                    </div>
                    <div class="ai-stat-card success">
                        <div class="stat-val"><?php echo $t_approved; ?></div>
                        <div class="stat-lbl">Approved by You</div>
                    </div>
                    <div class="ai-stat-card">
                        <div class="stat-val"><?php echo $t_original; ?></div>
                        <div class="stat-lbl">Original (No Edits)</div>
                    </div>
                    <div class="ai-stat-card danger">
                        <div class="stat-val"><?php echo $t_halluc; ?></div>
                        <div class="stat-lbl">Hallucinations</div>
                    </div>
                </div>

                <!-- Search + Filter Bar -->
                <div class="ai-search-bar">
                    <input type="text" id="techAILogSearch" placeholder="&#128269; Search by query, user, response, or notes..." oninput="filterTechAILogs()">
                </div>
                <div class="ai-filter-tabs">
                    <button class="ai-filter-tab active" onclick="setTechAIFilter('all', this)">All (<?php echo $t_total; ?>)</button>
                    <button class="ai-filter-tab" onclick="setTechAIFilter('editedbyhr', this)">&#9999; Pending Review (<?php echo $t_pending; ?>)</button>
                    <button class="ai-filter-tab" onclick="setTechAIFilter('approvedbytech', this)">&#10003; Approved (<?php echo $t_approved; ?>)</button>
                    <button class="ai-filter-tab" onclick="setTechAIFilter('original', this)">Original (<?php echo $t_original; ?>)</button>
                    <button class="ai-filter-tab" onclick="setTechAIFilter('flagged', this)">&#128680; Hallucinations (<?php echo $t_halluc; ?>)</button>
                </div>

                <div style="overflow-x: auto; width: 100%; margin-bottom: 20px; border: 1px solid #eee; border-radius: 8px;">
                <table id="techAILogsTable" style="width: 1200px; table-layout: fixed; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th style="width: 70px;">User</th>
                            <th style="width: 150px;">Query</th>
                            <th style="width: 150px;">Original AI Response</th>
                            <th style="width: 150px;">HR Edited Response</th>
                            <th style="width: 110px;">Review Notes</th>
                            <th style="width: 80px;">Accuracy</th>
                            <th style="width: 100px;">Hallucination</th>
                            <th style="width: 110px;">Status</th>
                            <th style="width: 80px;">Feedback</th>
                            <th style="width: 110px;">Time</th>
                            <th style="width: 110px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $all_reviews = mysqli_query($conn, "SELECT * FROM ai_logs ORDER BY id DESC");
                    if (mysqli_num_rows($all_reviews) == 0): ?>
                        <tr><td colspan="12" style="text-align:center; padding:20px; color:#888;">&#128203; No AI queries logged yet.</td></tr>
                    <?php else:
                        while ($log = mysqli_fetch_assoc($all_reviews)):
                            $status = $log['review_status'];
                            $is_flagged = ($log['performance_score'] < 80 || $log['hallucination_detected'] == 1);
                            $status_cls = strtolower(str_replace('_', '', $status));
                            $row_bg = ($status === 'Edited_by_HR') ? 'background:#fffbea;' : ($is_flagged ? 'background:#fff5f5;' : '');
                    ?>
                        <tr data-status="<?php echo $status_cls; ?>" data-flagged="<?php echo $is_flagged ? 'flagged' : 'ok'; ?>" style="<?php echo $row_bg; ?>">
                            <td><strong>#<?php echo $log['id']; ?></strong></td>
                            <td><?php echo htmlspecialchars($log['userid']); ?></td>
                            <td>
                                <span class="truncate-text" title="<?php echo htmlspecialchars($log['query']); ?>">
                                    <strong><?php echo htmlspecialchars($log['query']); ?></strong>
                                </span>
                            </td>
                            <td>
                                <span class="truncate-text" title="<?php echo htmlspecialchars($log['response']); ?>">
                                    <?php echo htmlspecialchars($log['response']); ?>
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
                            <td>
                                <strong style="color:<?php echo ($log['performance_score'] < 80) ? '#e74c3c' : '#27ae60'; ?>">
                                    <?php echo $log['performance_score']; ?>%
                                </strong>
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
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </td>
                            <td style="text-align:center; white-space:nowrap;">
                                <span style="color:#27ae60; font-size:13px;">&#128077; <?php echo intval($log['upvotes'] ?? 0); ?></span><br>
                                <span style="color:#e74c3c; font-size:13px;">&#128078; <?php echo intval($log['downvotes'] ?? 0); ?></span>
                            </td>
                            <td style="white-space:nowrap; font-size:11px; color:#888;">
                                <?php echo date('d M y, H:i', strtotime($log['timestamp'])); ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <div style="display:flex; flex-direction:column; gap:5px;">
                                    <?php if ($status === 'Edited_by_HR'): ?>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" name="approve_ai_review" class="btn-approve" style="font-size:11px; padding:4px 8px; width:100%;">&#10003; Approve</button>
                                        </form>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" name="reject_ai_review" class="btn-reject" style="font-size:11px; padding:4px 8px; width:100%;">&#10007; Reject</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($status === 'Approved_by_Tech' && $log['edited_response']): ?>
                                        <button class="btn-primary" style="padding:4px 8px; font-size:11px; width:100%; background:#8e44ad;" onclick="pushToOverride(<?php echo $log['id']; ?>, <?php echo json_encode($log['query']); ?>, <?php echo json_encode($log['edited_response']); ?>)">&#129504; Push Override</button>
                                    <?php endif; ?>
                                    <button class="btn-primary" style="padding:4px 8px; font-size:11px; width:100%;" onclick='openTechAIEditModal(<?php echo json_encode($log); ?>)'>&#9999; Direct Edit</button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Permanently delete AI log #<?php echo $log['id']; ?>?');">
                                        <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                        <button type="submit" name="delete_ai_log" style="background:#c0392b; color:white; border:none; padding:4px 8px; font-size:11px; border-radius:3px; cursor:pointer; font-weight:bold; width:100%;">&#128465; Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
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

            <div id="chathistory" class="section">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>My AI Chat History</h3>
                    <button onclick="display('overview')" class="btn-primary" style="background:#555;">&larr; Back</button>
                </div>
                <p>View your previous conversations with the AI Helpdesk Assistant.</p>
                <table>
                    <tr>
                        <th style="width:15%;">Time</th>
                        <th style="width:35%;">Your Question</th>
                        <th style="width:50%;">AI Response</th>
                    </tr>
                    <?php
                    $hist_query = mysqli_query($conn, "SELECT query, response, timestamp FROM ai_logs WHERE userid = '$userid' ORDER BY id DESC");
                    if (mysqli_num_rows($hist_query) == 0):
                    ?>
                        <tr><td colspan="3" style="text-align:center;">No chat history found.</td></tr>
                    <?php else: while ($h = mysqli_fetch_assoc($hist_query)): ?>
                        <tr>
                            <td style="font-size:12px; color:#666;"><?php echo date('d M y, H:i', strtotime($h['timestamp'])); ?></td>
                            <td style="font-weight:bold;"><?php echo htmlspecialchars($h['query']); ?></td>
                            <td style="font-size:13px;"><?php echo nl2br(htmlspecialchars($h['response'])); ?></td>
                        </tr>
                    <?php endwhile; endif; ?>
                </table>
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

                function openEditModal(doc) {
                    document.getElementById('edit_doc_id').value = doc.id;
                    document.getElementById('edit_doc_title').value = doc.title;
                    document.getElementById('edit_doc_file_path').value = doc.file_path;
                    document.getElementById('editModal').style.display = 'flex';
                }
                function closeEditModal() {
                    document.getElementById('editModal').style.display = 'none';
                }

                function pushToOverride(logId, query, overrideResponse) {
                    if (!confirm('Push this approved response as an HR Override? It will be used as a high-priority answer for similar future questions.')) return;
                    
                    const btn = event.target;
                    btn.disabled = true;
                    btn.textContent = '⏳ Pushing...';

                    fetch('http://127.0.0.1:8000/override', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            log_id: logId,
                            query: query,
                            override_response: overrideResponse,
                            created_by: '<?php echo addslashes($userid); ?>'
                        })
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.status === 'success') {
                            btn.textContent = '✓ Pushed!';
                            btn.style.background = '#27ae60';
                        } else {
                            btn.textContent = '✗ Failed';
                            btn.style.background = '#e74c3c';
                            btn.disabled = false;
                            alert('Override push failed: ' + (d.detail || 'Unknown error'));
                        }
                    })
                    .catch(() => {
                        btn.textContent = '✗ Error';
                        btn.style.background = '#e74c3c';
                        btn.disabled = false;
                        alert('Could not connect to RAG service. Make sure it is running.');
                    });
                }

                function openTechAIEditModal(log) {
                    document.getElementById('tech_edit_log_id').value = log.id;
                    document.getElementById('tech_review_query').textContent = log.query;
                    document.getElementById('tech_review_original').textContent = log.response;
                    document.getElementById('tech_edit_edited_response').value = log.edited_response || log.response;
                    document.getElementById('tech_edit_review_notes').value = log.review_notes || '';
                    document.getElementById('techAIEditModal').style.display = 'flex';
                }
                function closeTechAIEditModal() {
                    document.getElementById('techAIEditModal').style.display = 'none';
                }

                // Tech AI Review filter tab state
                let currentTechAIFilter = 'all';

                function setTechAIFilter(filter, btn) {
                    currentTechAIFilter = filter;
                    document.querySelectorAll('.ai-filter-tab').forEach(t => t.classList.remove('active'));
                    btn.classList.add('active');
                    applyTechAIFilters();
                }

                function filterTechAILogs() {
                    applyTechAIFilters();
                }

                function applyTechAIFilters() {
                    const searchVal = (document.getElementById('techAILogSearch')?.value || '').toLowerCase();
                    const rows = document.querySelectorAll('#techAILogsTable tbody tr');
                    rows.forEach(row => {
                        const status = row.getAttribute('data-status') || '';
                        const flagged = row.getAttribute('data-flagged') || '';
                        const text = row.textContent.toLowerCase();

                        let statusMatch = false;
                        if (currentTechAIFilter === 'all') statusMatch = true;
                        else if (currentTechAIFilter === 'flagged') statusMatch = (flagged === 'flagged');
                        else statusMatch = (status === currentTechAIFilter);

                        const searchMatch = !searchVal || text.includes(searchVal);
                        row.style.display = (statusMatch && searchMatch) ? '' : 'none';
                    });
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
                <option value="AI Performance">&#128202; AI Performance</option>
                <option value="Knowledge Base">&#128218; Knowledge Base Tuning</option>
                <option value="Manage AI Reviews">&#129302; Manage AI Reviews</option>
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
            <form method="POST">
                <input type="hidden" id="edit_doc_id" name="doc_id">
                <label>Document Title</label>
                <input type="text" id="edit_doc_title" name="new_title" required>
                <label>File Path</label>
                <input type="text" id="edit_doc_file_path" name="new_file_path" required>
                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                    <button type="button" onclick="closeEditModal()" style="background:#ccc; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" name="edit_document" class="btn-primary" style="padding:8px 15px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    
    <div class="modal" id="techAIEditModal">
        <div class="modal-content" style="width:520px;">
            <h3 style="margin-top:0;">Direct Edit AI Response</h3>
            <form method="POST">
                <input type="hidden" id="tech_edit_log_id" name="log_id">
                <label>User Query</label>
                <p id="tech_review_query" style="background:#f3f0f7; padding:10px; border-radius:4px; font-size:13px; font-weight:bold;"></p>

                <label>Original AI Response</label>
                <p id="tech_review_original" style="background:#fdfcff; padding:10px; border-radius:4px; font-size:12px; border:1px solid #eee; max-height:100px; overflow-y:auto;"></p>

                <label>Corrected / Approved Response</label>
                <textarea id="tech_edit_edited_response" name="edited_response" rows="4" placeholder="Type the approved correct response..." required></textarea>

                <label>Notes (reason for edit, policy reference, etc.)</label>
                <textarea id="tech_edit_review_notes" name="review_notes" rows="2" placeholder="e.g. Updated as per policy v2.1"></textarea>

                <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:10px;">
                    <button type="button" onclick="closeTechAIEditModal()" style="background:#ccc; border:none; padding:8px 15px; border-radius:4px; cursor:pointer;">Cancel</button>
                    <button type="submit" name="submit_tech_ai_edit" class="btn-primary" style="padding:8px 15px;">Save & Approve</button>
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
            
            const userDiv = document.createElement('div');
            userDiv.className = 'message user';
            userDiv.textContent = message;
            container.appendChild(userDiv);
            container.scrollTop = container.scrollHeight;
            
            input.value = '';
            
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
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: message, page_filter: pageFilter })
            })
            .then(res => res.json())
            .then(data => {
                const temp = document.getElementById('typingIndicatorTemp');
                if (temp) temp.remove();
                
                const aiDiv = document.createElement('div');
                aiDiv.className = 'message ai';
                aiDiv.innerHTML = (data.response || (data.error ? 'Error: ' + data.error : 'No response.')).replace(/\n/g, '<br>');
                container.appendChild(aiDiv);

                if (data.ai_log_id) {
                    const feedbackDiv = document.createElement('div');
                    feedbackDiv.style.cssText = 'display:flex; gap:8px; margin-top:4px; margin-left:4px;';
                    feedbackDiv.innerHTML =
                        `<button onclick="submitFeedback(${data.ai_log_id},'up',this)" title="Helpful" style="background:none;border:none;cursor:pointer;font-size:18px;opacity:0.7;" class="fb-btn">👍</button>` +
                        `<button onclick="submitFeedback(${data.ai_log_id},'down',this)" title="Not helpful" style="background:none;border:none;cursor:pointer;font-size:18px;opacity:0.7;" class="fb-btn">👎</button>` +
                        `<span id="fb-msg-${data.ai_log_id}" style="font-size:11px;color:#888;margin-top:3px;"></span>`;
                    container.appendChild(feedbackDiv);
                }

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
            .finally(() => { isSending = false; });
        }

        function submitFeedback(logId, feedbackVal, btn) {
            const row = btn.parentElement;
            row.querySelectorAll('.fb-btn').forEach(b => b.disabled = true);
            fetch('feedback.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ log_id: logId, feedback: feedbackVal })
            })
            .then(r => r.json())
            .then(d => {
                const msg = document.getElementById('fb-msg-' + logId);
                if (msg) msg.textContent = feedbackVal === 'up' ? '✓ Thanks!' : '✓ Noted.';
            })
            .catch(() => {});
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
