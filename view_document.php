<?php
/**
 * view_document.php
 *
 * Serves a RAG document file inline so the browser can open it directly.
 * 
 * Supports two call signatures:
 *   1. ?kb_id=<id>           — legacy, looks up via knowledge_base table
 *   2. ?rag_id=<uuid>&page=N — direct by RAG document UUID (used by AI source links)
 * 
 * The browser fragment #page=N is appended by chatbot_backend.php in the
 * hyperlink href so PDF.js / native PDF viewer jumps to the correct page.
 */

session_start();
include('db.php');

// Auth check
if (!isset($_SESSION['userid'])) {
    die("Access denied. Please log in.");
}

$ext           = '';
$physical_file = '';
$orig_name     = 'document';

/* ── Route 1: direct rag_id access ───────────────────────────────────────── */
if (!empty($_GET['rag_id'])) {
    $rag_id = preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['rag_id']); // sanitise UUID

    // Connect to erp_rag DB directly
    $rag_conn = new mysqli('localhost', 'root', '', 'erp_rag', 3307);
    if ($rag_conn->connect_error) {
        die("RAG database error: " . $rag_conn->connect_error);
    }

    $stmt = $rag_conn->prepare(
        "SELECT storage_path, original_filename, file_extension FROM rag_documents WHERE document_id = ? LIMIT 1"
    );
    $stmt->bind_param("s", $rag_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows !== 1) {
        die("Document not found in RAG database.");
    }

    $doc           = $res->fetch_assoc();
    $stmt->close();
    $rag_conn->close();

    $physical_file = $doc['storage_path'];
    $orig_name     = $doc['original_filename'];
    $ext           = ltrim(strtolower($doc['file_extension']), '.');

/* ── Route 2: legacy kb_id access ───────────────────────────────────────── */
} elseif (!empty($_GET['kb_id'])) {
    $kb_id = intval($_GET['kb_id']);
    if ($kb_id <= 0) {
        die("Invalid request: missing document ID.");
    }

    $stmt = $conn->prepare("SELECT rag_document_id, file_path FROM knowledge_base WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $kb_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows !== 1) {
        die("Document not found.");
    }

    $doc       = $res->fetch_assoc();
    $stmt->close();
    $conn->close();

    $rag_id_kb = $doc['rag_document_id'];
    $orig_name = $doc['file_path'];

    if (empty($rag_id_kb)) {
        die("This document is a draft and does not have a processed file yet.");
    }

    $ext           = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    $physical_file = __DIR__ . '/fastapi_rag/uploads/' . $rag_id_kb . '.' . $ext;

} else {
    die("Invalid request: provide kb_id or rag_id.");
}

/* ── Serve the file ──────────────────────────────────────────────────────── */
if (!file_exists($physical_file)) {
    die("Error: Document file not found on server storage. Path: " . htmlspecialchars($physical_file));
}

switch ($ext) {
    case 'pdf':
        header('Content-Type: application/pdf');
        break;
    case 'docx':
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        break;
    case 'txt':
        header('Content-Type: text/plain; charset=utf-8');
        break;
    default:
        header('Content-Type: application/octet-stream');
        break;
}

header('Content-Disposition: inline; filename="' . basename($orig_name) . '"');
header('Content-Length: ' . filesize($physical_file));
header('Cache-Control: private, max-age=86400');

readfile($physical_file);
exit();
?>
