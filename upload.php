<?php
/**
 * upload.php
 *
 * Handles actual file uploads from the HR Admin dashboard.
 * Receives the file from the browser form, validates it,
 * forwards it to the FastAPI /upload endpoint via cURL,
 * and links the returned document_id to the knowledge_base record.
 *
 * Called via AJAX POST or standard form action.
 * Returns JSON response or redirects back to dashboard.
 */

session_start();
include('db.php');

// Detect if request is AJAX
$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
           || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

function return_result($status, $message, $doc_id = null, $kb_id = null) {
    global $is_ajax;
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'status'      => $status,
            'message'     => $message,
            'document_id' => $doc_id,
            'kb_id'       => $kb_id
        ]);
        exit();
    } else {
        // Traditional form fallback: redirect back to dashboard with a query parameter or session message
        $_SESSION['upload_message'] = ($status === 'success' ? '✅ ' : '❌ ') . $message;
        header("Location: dashboard_a.php#knowledge");
        exit();
    }
}

/* ─── Auth check ─────────────────────────────────────────── */
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'Hr_admin') {
    return_result('error', 'Unauthorised.');
}

$userid = $_SESSION['userid'];

/* ─── Validate uploaded file ─────────────────────────────── */
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit (php.ini).',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory available.',
        UPLOAD_ERR_CANT_WRITE => 'Cannot write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
    ];
    $err_code = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
    $err_msg  = $upload_errors[$err_code] ?? 'Unknown upload error.';
    return_result('error', $err_msg);
}

$doc_title    = trim($_POST['doc_title'] ?? '');
$kb_id        = intval($_POST['kb_id'] ?? $_POST['doc_id'] ?? 0); // 0 = new document

$original_name = $_FILES['document']['name'];
$tmp_path      = $_FILES['document']['tmp_name'];
$file_size     = $_FILES['document']['size'];
$extension     = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

/* ─── Allowed file types ─────────────────────────────────── */
$allowed_extensions = ['pdf', 'docx', 'txt'];
if (!in_array($extension, $allowed_extensions)) {
    return_result('error', "File type '.$extension' not allowed. Use PDF, DOCX, or TXT.");
}

/* ─── Max file size: 20 MB ───────────────────────────────── */
$max_size = 20 * 1024 * 1024;
if ($file_size > $max_size) {
    return_result('error', 'File exceeds 20 MB limit.');
}

if (empty($doc_title)) {
    $doc_title = pathinfo($original_name, PATHINFO_FILENAME);
}

/* ─── Forward to FastAPI /upload via cURL ────────────────── */
$fastapi_url = 'http://127.0.0.1:8000/upload';

$curl_file = new CURLFile(
    $tmp_path,
    mime_content_type($tmp_path) ?: 'application/octet-stream',
    $original_name
);

$post_data = [
    'file'    => $curl_file,
    'user_id' => $userid,
];

if ($kb_id > 0) {
    $post_data['kb_id'] = $kb_id;
}

$ch = curl_init($fastapi_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_data,
    CURLOPT_TIMEOUT        => 60,
]);

$raw_response = curl_exec($ch);
$http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error   = curl_error($ch);
curl_close($ch);

/* ─── Handle cURL / FastAPI errors ──────────────────────── */
if ($raw_response === false || !empty($curl_error)) {
    return_result('error', 'Cannot reach the RAG processing service. Ensure FastAPI is running on port 8000.');
}

$api_data = json_decode($raw_response, true);

if ($http_code === 409) {
    // Duplicate filename
    return_result('duplicate', $api_data['detail'] ?? 'A document with this name already exists.');
}

if ($http_code !== 200 || ($api_data['status'] ?? '') !== 'success') {
    $detail = $api_data['detail'] ?? $api_data['message'] ?? 'Unknown error from processing service.';
    return_result('error', $detail);
}

$document_id = $api_data['document_id'];

/* ─── Insert / Update knowledge_base record ─────────────── */
if ($kb_id > 0) {
    // Link to existing KB entry
    $stmt = mysqli_prepare($conn,
        "UPDATE knowledge_base
            SET title = ?, file_path = ?, status = 'Submitted',
                rag_document_id = ?
          WHERE id = ? AND uploaded_by = ?"
    );
    mysqli_stmt_bind_param($stmt, 'sssii',
        $doc_title, $original_name, $document_id, $kb_id, $userid
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
} else {
    // New knowledge_base entry
    $stmt = mysqli_prepare($conn,
        "INSERT INTO knowledge_base
            (title, file_path, status, uploaded_by, rag_document_id)
         VALUES (?, ?, 'Submitted', ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'ssss',
        $doc_title, $original_name, $userid, $document_id
    );
    mysqli_stmt_execute($stmt);
    $kb_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
}

return_result('success', "Document '{$doc_title}' uploaded successfully and submitted for Tech Admin review.", $document_id, $kb_id);
?>
