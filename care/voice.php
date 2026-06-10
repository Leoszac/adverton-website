<?php
// Adverton Care — incoming-call webhook. Twilio hits this when someone calls a
// client's Care number; we return TwiML that forwards the call to the
// contractor's cell, with a status callback to detect missed calls.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/flows.php';

$sig = (string)($_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '');
$url = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
if (!care_twilioVerifySignature($url, $_POST, $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'forbidden';
    exit;
}

header('Content-Type: text/xml; charset=utf-8');
$base   = 'https://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/care/voice.php')), '/');
$action = $base . '/voice-status.php';
echo care_handleIncomingCall((string)($_POST['To'] ?? ''), $action);
