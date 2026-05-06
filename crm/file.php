<?php
// Auth-gated download endpoint for files attached to leads.
// Streams from /home2/advertonnet/crm-files/{lead_id}/...

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/files.php';

crm_requireLogin();

$id = (int)($_GET['id'] ?? 0);
$file = $id > 0 ? crm_getFile($id) : null;
if (!$file) { http_response_code(404); exit('not found'); }

$path = crm_filesDir((int)$file['lead_id']) . '/' . $file['stored_name'];
if (!is_readable($path)) { http_response_code(404); exit('not found'); }

$disposition = isset($_GET['inline']) ? 'inline' : 'attachment';
$asciiName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $file['original_name']);
$utf8Name  = rawurlencode($file['original_name']);
header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . filesize($path));
header(
    "Content-Disposition: {$disposition}; filename=\"{$asciiName}\"; filename*=UTF-8''{$utf8Name}"
);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
