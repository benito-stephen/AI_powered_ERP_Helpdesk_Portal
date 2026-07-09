<?php
/**
 * system_logs.php
 * 
 * Serves the FastAPI RAG service log file to the Tech Admin.
 * Supports:
 *   - GET ?lines=N        → tail last N lines (default 300)
 *   - GET ?level=ERROR    → filter to a severity level (INFO/WARNING/ERROR)
 *   - GET ?search=text    → text search across log lines
 *   - GET ?download=1     → force-download the raw log file
 * 
 * Only accessible by Technical_admin role.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'Technical_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit();
}

$LOG_FILE = __DIR__ . '/fastapi_rag/logs/rag_service.log';

// Force download of raw log
if (!empty($_GET['download'])) {
    if (!file_exists($LOG_FILE)) {
        http_response_code(404);
        echo json_encode(['error' => 'Log file not found.']);
        exit();
    }
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="rag_service.log"');
    readfile($LOG_FILE);
    exit();
}

if (!file_exists($LOG_FILE)) {
    echo json_encode([
        'status' => 'ok',
        'lines'  => [],
        'total'  => 0,
        'note'   => 'Log file does not exist yet. Start the FastAPI service first.',
    ]);
    exit();
}

$max_lines = max(50, min(2000, intval($_GET['lines'] ?? 300)));
$level_filter  = strtoupper(trim($_GET['level']  ?? ''));
$search_filter = trim($_GET['search'] ?? '');

// Read log with PHP's SplFileObject (memory-efficient tail)
$file = new SplFileObject($LOG_FILE, 'r');
$file->seek(PHP_INT_MAX);
$total_lines = $file->key();

// Tail the last $max_lines lines efficiently
$start = max(0, $total_lines - $max_lines);
$file->seek($start);
$raw_lines = [];
while (!$file->eof()) {
    $line = rtrim($file->fgets());
    if ($line !== '') {
        $raw_lines[] = $line;
    }
}

// Parse lines into structured objects
// Log format: "2026-07-09 14:23:01  INFO      rag_service – some message"
$parsed = [];
foreach (array_reverse($raw_lines) as $line) {
    // Determine level
    $level = 'INFO';
    if (strpos($line, '  ERROR   ') !== false || strpos($line, 'ERROR') !== false) $level = 'ERROR';
    elseif (strpos($line, '  WARNING ') !== false || strpos($line, 'WARNING') !== false) $level = 'WARNING';
    elseif (strpos($line, '  DEBUG   ') !== false) $level = 'DEBUG';
    elseif (strpos($line, '  CRITICAL') !== false) $level = 'CRITICAL';

    // Apply level filter
    if ($level_filter && $level !== $level_filter) continue;

    // Apply text search
    if ($search_filter && stripos($line, $search_filter) === false) continue;

    // Extract timestamp if present
    $timestamp = '';
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
        $timestamp = $m[1];
    }

    // Extract logger name (between last – and message, or after level)
    $logger_name = '';
    if (preg_match('/\d{2}:\d{2}:\d{2}\s+\w+\s+([\w.]+)\s*[–-]/', $line, $m)) {
        $logger_name = $m[1];
    }

    // Extract the message after the – separator
    $message = $line;
    if (($pos = strrpos($line, '– ')) !== false) {
        $message = substr($line, $pos + 3);
    } elseif (($pos = strrpos($line, '- ')) !== false) {
        // fallback for ASCII dash
        $message = substr($line, $pos + 2);
    }

    $parsed[] = [
        'timestamp'   => $timestamp,
        'level'       => $level,
        'logger'      => $logger_name,
        'message'     => $message,
        'raw'         => $line,
    ];
}

echo json_encode([
    'status'   => 'ok',
    'lines'    => $parsed,
    'total'    => count($parsed),
    'log_file' => basename($LOG_FILE),
    'file_size_kb' => round(filesize($LOG_FILE) / 1024, 1),
]);
exit();
?>
