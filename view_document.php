<?php
/**
 * view_document.php
 *
 * Safe proxy script to download or view a document from the RAG service uploads directory.
 * Requires an active user session to view files.
 */

session_start();
include('db.php');

// Auth check
if (!isset($_SESSION['userid'])) {
    die("Access denied. Please log in.");
}

$kb_id = intval($_GET['kb_id'] ?? 0);
if ($kb_id <= 0) {
    die("Invalid request: missing document ID.");
}

// Fetch document metadata to find the storage path
$stmt = $conn->prepare("SELECT rag_document_id, file_path FROM knowledge_base WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $kb_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows !== 1) {
    die("Document not found.");
}

$doc = $res->fetch_assoc();
$stmt->close();
$conn->close();

$rag_id    = $doc['rag_document_id'];
$orig_name = $doc['file_path'];

if (empty($rag_id)) {
    die("This document is a draft and does not have a processed file yet.");
}

// Build file path relative to this script
// Files are saved as fastapi_rag/uploads/{rag_id}.{ext}
$ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
$physical_file = __DIR__ . '/fastapi_rag/uploads/' . $rag_id . '.' . $ext;

if (!file_exists($physical_file)) {
    die("Error: Document file not found on server storage.");
}

// Set MIME Type headers based on file extension
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

// Set disposition to view inline
header('Content-Disposition: inline; filename="' . basename($orig_name) . '"');
header('Content-Length: ' . filesize($physical_file));
header('Cache-Control: private, max-age=86400');

// Output file stream
readfile($physical_file);
exit();
?>
