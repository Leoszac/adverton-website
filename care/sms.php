<?php
// Adverton Care — incoming-SMS webhook. Handles STOP/START opt-out and the
// 2-way relay: a customer's text is forwarded to the contractor's cell, and the
// contractor's reply is routed back to that customer from the Care number.

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
echo care_handleIncomingSms($_POST);
