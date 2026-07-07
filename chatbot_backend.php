
<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);

include('db.php');
include('config.php');

header('Content-Type: application/json');

function sendResponse($message, $success = true)
{
    echo json_encode([
        $success ? 'response' : 'error' => $message
    ]);
    exit();
}

/* -------------------------------
   AUTHENTICATION
--------------------------------*/

if (!isset($_SESSION['userid'])) {
    sendResponse('Session expired. Please login again.', false);
}

$userid = $_SESSION['userid'];

/* -------------------------------
   GET INPUT
--------------------------------*/

$input = json_decode(file_get_contents('php://input'), true);

$userMessage = trim($input['message'] ?? '');

if (empty($userMessage)) {
    sendResponse('Please enter a message.', false);
}

$userMessage = substr($userMessage, 0, 250);

/* -------------------------------
   LOAD USER PROFILE
--------------------------------*/

$stmt = mysqli_prepare($conn,
    "SELECT userid, name, role, department,
            email, status, leaves_left,
            weekly_hours
     FROM users
     WHERE userid = ?
     LIMIT 1");

mysqli_stmt_bind_param($stmt, "s", $userid);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$user) {
    sendResponse('User not found.', false);
}

/* -------------------------------
   LOAD LEAVE HISTORY
--------------------------------*/

$leaveHistory = "";

$stmt = mysqli_prepare($conn,
    "SELECT leave_type, from_date,
            to_date, status
     FROM leaves
     WHERE userid = ?
     ORDER BY id DESC
     LIMIT 5");

mysqli_stmt_bind_param($stmt, "s", $userid);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($res)) {

    $leaveHistory .=
        "{$row['leave_type']} | ".
        "{$row['from_date']} to ".
        "{$row['to_date']} | ".
        "{$row['status']}\n";
}

mysqli_stmt_close($stmt);

if (empty($leaveHistory)) {
    $leaveHistory = "No leave records found.";
}

/* -------------------------------
   TODAY ATTENDANCE
--------------------------------*/

$today = date('Y-m-d');

$stmt = mysqli_prepare($conn,
    "SELECT punch_in,
            punch_out,
            duration
     FROM attendance
     WHERE userid = ?
     AND date = ?
     LIMIT 1");

mysqli_stmt_bind_param($stmt, "ss",
    $userid, $today);

mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);

$attendance = mysqli_fetch_assoc($res);

mysqli_stmt_close($stmt);

$attendanceInfo = "No attendance record today.";

if ($attendance) {

    $attendanceInfo =
        "Punch In: " .
        ($attendance['punch_in'] ?: 'Not Yet') .
        "\nPunch Out: " .
        ($attendance['punch_out'] ?: 'Not Yet') .
        "\nHours Worked: " .
        ($attendance['duration'] ?: 0);
}

/* -------------------------------
   LAST LOGIN
--------------------------------*/

$stmt = mysqli_prepare($conn,
    "SELECT login_time
     FROM login_logs
     WHERE userid = ?
     ORDER BY id DESC
     LIMIT 1");

mysqli_stmt_bind_param($stmt, "s", $userid);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);
$login = mysqli_fetch_assoc($res);

mysqli_stmt_close($stmt);

$lastLogin = $login['login_time'] ?? 'No login history';

/* ================================================================
   HYBRID ROUTING DECISION
   ---------------------------------------------------------------
   Determine whether this question is about personal ERP data
   (attendance, leaves, profile, navigation) or about company
   policies / HR documents.

   Personal ERP queries → answered by Gemini with the system
   instruction built below (existing Phase I logic, no change).

   Policy / document queries → forwarded to FastAPI RAG pipeline
   which retrieves relevant chunks and calls Gemini separately.

   This keeps token usage minimal for personal queries (no document
   context loaded) while enabling document-grounded answers for
   policy questions.
================================================================ */

function is_personal_erp_query(string $message): bool
{
    $msg = strtolower(trim($message));

    // Force RAG routing if explicitly asking about policies, rules, processes, or how to apply
    $policy_indicators = [
        'policy', 'rule', 'procedure', 'regulation', 'guideline', 
        'manual', 'document', 'process', 'how to apply', 'how can i apply', 
        'steps to apply', 'how to get', 'how can i get'
    ];
    foreach ($policy_indicators as $indicator) {
        if (strpos($msg, $indicator) !== false) {
            return false; // Route to RAG
        }
    }

    // Personal ERP keywords – actual balance check, punch logging, personal history, or profile metrics
    $personal_patterns = [
        // Attendance logs
        'punch in', 'punch out', 'punched in', 'punched out',
        'clock in', 'clock out', 'check in', 'check out',
        'my hours', 'weekly hours', 'hours worked', 'work hours',
        'late arrival', 'my schedule',

        // Leave balances / personal records
        'leave balance', 'leaves left', 'leaves remaining', 'remaining leave',
        'how many leaves', 'leave status', 'leave history',
        'my leaves', 'my balance', 'my attendance', 'last login',

        // Profile / personal identity
        'my name', 'my email', 'my role', 'my department', 'my id',
        'my profile', 'my account', 'my status', 'who am i',
        'my employee', 'my userid',

        // Navigation
        'navigate', 'go to', 'find the', 'open the', 'access the', 'use the',
        'dashboard', 'logout', 'log out', 'login', 'log in',

        // Direct database events
        'when did i', 'did i punch', 'am i present', 'am i on leave', 'today'
    ];

    foreach ($personal_patterns as $pattern) {
        if (strpos($msg, $pattern) !== false) {
            return true; // Route to Personal
        }
    }

    // If they just type "sick leave", "casual leave", "annual leave" without personal words like "my", "remaining", "balance", 
    // it's likely a query about the policy itself, so route to RAG.
    return false;
}

$use_rag = !is_personal_erp_query($userMessage);

/* ================================================================
   PATH A – RAG PIPELINE (policy / document questions)
   Forward to FastAPI /chat and return document-grounded answer.
================================================================ */

if ($use_rag) {

    $fastapi_url = 'http://127.0.0.1:8000/chat';

    $payload_rag = json_encode([
        'question' => $userMessage,
        'user_id'  => $userid,
    ]);

    $ch_rag = curl_init($fastapi_url);
    curl_setopt_array($ch_rag, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload_rag,
        CURLOPT_TIMEOUT        => 45,
    ]);

    $rag_raw   = curl_exec($ch_rag);
    $rag_code  = curl_getinfo($ch_rag, CURLINFO_HTTP_CODE);
    $rag_err   = curl_error($ch_rag);
    curl_close($ch_rag);

    // If FastAPI is unreachable, fall back gracefully to Phase I
    if ($rag_raw === false || !empty($rag_err) || $rag_code !== 200) {
        $use_rag = false; // fall through to Phase I below
    } else {

        $rag_data = json_decode($rag_raw, true);
        $rag_answer = $rag_data['answer'] ?? "I couldn't find an answer in the uploaded documents.";
        $rag_sources = $rag_data['sources'] ?? [];

        // Append source citations to the answer
        if (!empty($rag_sources)) {
            $rag_answer .= "\n\n📄 Sources:";
            foreach ($rag_sources as $src) {
                $rag_answer .= "\n• {$src['filename']} (Page {$src['page']})";
            }
        }

        /* ── Save chat history ─────────────────────────────────── */
        $stmt = mysqli_prepare($conn,
            "INSERT INTO chat_memory (userid, sender, message)
             VALUES (?, 'user', ?)");
        mysqli_stmt_bind_param($stmt, "ss", $userid, $userMessage);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($conn,
            "INSERT INTO chat_memory (userid, sender, message)
             VALUES (?, 'ai', ?)");
        mysqli_stmt_bind_param($stmt, "ss", $userid, $rag_answer);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        mysqli_close($conn);
        echo json_encode(['response' => $rag_answer]);
        exit();
    }
}

/* ================================================================
   PATH B – DIRECT GEMINI (personal ERP data questions)
   Original Phase I logic – unchanged, token-efficient system
   instruction with live ERP data injected, no document context.
================================================================ */

/* -------------------------------
   SYSTEM INSTRUCTION
--------------------------------*/

$systemInstruction = "

You are an AI assistant for an ERP Portal.

User Information:

Name: {$user['name']}
Employee ID: {$user['userid']}
Role: {$user['role']}
Department: {$user['department']}
Email: {$user['email']}
Account Status: {$user['status']}

Leaves Remaining:
{$user['leaves_left']}

Weekly Hours:
{$user['weekly_hours']}

Recent Leave History:
{$leaveHistory}

Today's Attendance:
{$attendanceInfo}

Last Login:
{$lastLogin}

Rules:

1. Answer using available ERP information whenever possible.
2. Employees can ask about attendance, leaves, policies and profile.
3. HR Admin can ask HR-related questions.
4. Technical Admin can ask technical questions.
5. Keep answers concise.
6. Use bullet points when necessary.
7. If information is unavailable, politely mention it.

Today's Date: " . date('d M Y');

/* -------------------------------
   GEMINI REQUEST
--------------------------------*/

$url =
"https://generativelanguage.googleapis.com/v1beta/models/"
. GEMINI_MODEL .
":generateContent";

$payload = [

    'contents' => [[
        'role' => 'user',
        'parts' => [[
            'text' => $userMessage
        ]]
    ]],

    'systemInstruction' => [
        'parts' => [[
            'text' => $systemInstruction
        ]]
    ],

    'generationConfig' => [
        'temperature' => 0.4,
        'maxOutputTokens' => 200
    ]
];

$ch = curl_init($url);

curl_setopt_array($ch, [

    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,

    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY
    ],

    CURLOPT_POSTFIELDS => json_encode($payload),

    CURLOPT_TIMEOUT => 30
]);

$maxRetries = 3;
$attempt = 0;

do {

    $response = curl_exec($ch);

    $httpCode =
        curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($httpCode == 200) {
        break;
    }

    if ($httpCode == 429) {

        sleep(2);

        $attempt++;
    }
    else {
        break;
    }

} while ($attempt < $maxRetries);

$curlError = curl_error($ch);

curl_close($ch);

/* -------------------------------
   HANDLE ERRORS
--------------------------------*/

if ($response === false) {

    sendResponse(
        "Connection error: " .
        $curlError,
        false
    );
}

if ($httpCode != 200) {

    sendResponse(
        "AI service temporarily unavailable.",
        false
    );
}

/* -------------------------------
   PARSE RESPONSE
--------------------------------*/

$data = json_decode($response, true);

$aiResponse =
$data['candidates'][0]['content']['parts'][0]['text']
?? "Unable to generate response.";

/* -------------------------------
   SAVE CHAT HISTORY
--------------------------------*/

$stmt = mysqli_prepare($conn,
    "INSERT INTO chat_memory
    (userid, sender, message)
    VALUES (?, 'user', ?)");

mysqli_stmt_bind_param(
    $stmt,
    "ss",
    $userid,
    $userMessage
);

mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn,
    "INSERT INTO chat_memory
    (userid, sender, message)
    VALUES (?, 'ai', ?)");

mysqli_stmt_bind_param(
    $stmt,
    "ss",
    $userid,
    $aiResponse
);

mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

/* -------------------------------
   RETURN RESPONSE
--------------------------------*/

echo json_encode([
    'response' => $aiResponse
]);

mysqli_close($conn);
exit();
?>

