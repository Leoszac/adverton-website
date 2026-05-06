<?php
// Public 1x1 tracking pixel. Returns a transparent GIF unconditionally —
// invalid tokens are silently absorbed so we don't leak existence.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/activities.php';
require_once __DIR__ . '/lib/email_track.php';

$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));
if (strlen($token) === 32) {
    crm_recordOpen(
        $token,
        (string)($_SERVER['REMOTE_ADDR']    ?? ''),
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
}

// 43-byte transparent 1x1 GIF
header('Content-Type: image/gif');
header('Cache-Control: no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
