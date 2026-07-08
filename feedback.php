<?php
/**
 * feedback.php
 * 
 * Handles thumbs-up / thumbs-down feedback for AI responses.
 * 
 * POST body (JSON or form):
 *   log_id   : int    — ID of the ai_logs row
 *   feedback : string — "up" or "down"
 * 
 * Returns JSON { status: "ok" | "error", message: string }
 */

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['userid'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit();
}

include('db.php');
$userid = $_SESSION['userid'];

// Accept both JSON body and form POST
$input = json_decode(file_get_contents('php://input'), true);
$log_id  = intval($input['log_id']  ?? $_POST['log_id']  ?? 0);
$feedback = trim($input['feedback']  ?? $_POST['feedback'] ?? '');

if ($log_id <= 0 || !in_array($feedback, ['up', 'down'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid log_id or feedback value']);
    exit();
}

// Upsert feedback (one vote per user per log)
$stmt = mysqli_prepare($conn,
    "INSERT INTO ai_feedback (log_id, userid, feedback) VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE feedback = VALUES(feedback)"
);
mysqli_stmt_bind_param($stmt, 'iss', $log_id, $userid, $feedback);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// Recompute vote counts from ai_feedback
$up_res   = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_feedback WHERE log_id = $log_id AND feedback = 'up'");
$down_res = mysqli_query($conn, "SELECT COUNT(*) as c FROM ai_feedback WHERE log_id = $log_id AND feedback = 'down'");
$upvotes   = mysqli_fetch_assoc($up_res)['c'] ?? 0;
$downvotes = mysqli_fetch_assoc($down_res)['c'] ?? 0;

// Update denormalised counts in ai_logs
$stmt2 = mysqli_prepare($conn,
    "UPDATE ai_logs SET upvotes = ?, downvotes = ? WHERE id = ?"
);
mysqli_stmt_bind_param($stmt2, 'iii', $upvotes, $downvotes, $log_id);
mysqli_stmt_execute($stmt2);
mysqli_stmt_close($stmt2);

mysqli_close($conn);

echo json_encode([
    'status'    => 'ok',
    'upvotes'   => $upvotes,
    'downvotes' => $downvotes,
]);
exit();
?>
