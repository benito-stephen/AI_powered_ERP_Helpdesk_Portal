<?php
/**
 * rag_status.php
 *
 * Lightweight AJAX endpoint that proxies the FastAPI GET /status/{doc_id}
 * call. Used by the Tech Admin dashboard to poll pipeline progress
 * without exposing FastAPI directly to the browser.
 *
 * GET  rag_status.php?doc_id=<UUID>    → single document status
 * GET  rag_status.php?all=1            → all documents summary
 */

session_start();
header('Content-Type: application/json');

/* ─── Auth ──────────────────────────────────────────────────────────────── */
if (!isset($_SESSION['userid'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit();
}

// Only HR Admin and Tech Admin can poll status
$allowed_roles = ['Hr_admin', 'Technical_admin'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorised.']);
    exit();
}

/* ─── Build FastAPI URL ─────────────────────────────────────────────────── */
$base_url = 'http://127.0.0.1:8000';

if (isset($_GET['all']) && $_GET['all'] == '1') {
    $api_url = $base_url . '/status';
} elseif (!empty($_GET['doc_id'])) {
    // Sanitise: UUID format only
    $doc_id = preg_replace('/[^a-f0-9\-]/', '', strtolower($_GET['doc_id']));
    if (strlen($doc_id) < 32) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid document ID.']);
        exit();
    }
    $api_url = $base_url . '/status/' . $doc_id;
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing doc_id parameter.']);
    exit();
}

/* ─── Proxy call to FastAPI ─────────────────────────────────────────────── */
$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET        => true,
    CURLOPT_TIMEOUT        => 10,
]);

$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($raw === false || !empty($err)) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'RAG service unreachable. Please ensure FastAPI is running.'
    ]);
    exit();
}

// Pass through FastAPI response directly
if ($code >= 100 && $code < 600) {
    http_response_code($code);
} else {
    http_response_code(502);
}
echo $raw;
?>
