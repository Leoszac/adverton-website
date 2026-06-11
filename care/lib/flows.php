<?php
// Adverton Care — call/SMS flow logic. Kept in a lib (not the webhook files)
// so it's unit-testable without a Twilio signature. The thin webhooks
// (voice.php / voice-status.php / sms.php) verify the signature, then call
// these. All outbound SMS goes FROM the client's Care number so the customer
// always sees the business, never the contractor's personal cell.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/twilio.php';   // pulls care.php (crm_db, crm_config, crm_h, care_*)

function care_xml(string $inner): string {
    return '<?xml version="1.0" encoding="UTF-8"?>' . $inner;
}

// The automatic text sent to a caller when the contractor doesn't pick up.
// Single source of truth — also shown in the contractor dashboard so they see
// exactly what their customers receive.
function care_missedCallMessage(string $biz): string {
    return "Sorry we missed your call to {$biz}. How can we help? Reply here and we'll text you right back. (Reply STOP to opt out.)";
}

// Active Care number row by the dialed/texted number (E.164).
function care_numberRow(string $careNumber): ?array {
    try {
        $st = care_db()->prepare('SELECT * FROM care_numbers WHERE twilio_number = ? AND active = 1 LIMIT 1');
        $st->execute([$careNumber]);
        $r = $st->fetch();
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function care_clientName(int $clientId): string {
    try {
        $st = care_db()->prepare('SELECT business_name FROM clients WHERE id = ?');
        $st->execute([$clientId]);
        $n = (string)($st->fetchColumn() ?: '');
        return $n !== '' ? $n : 'us';
    } catch (Throwable $e) { return 'us'; }
}

// ── Logging helpers ──────────────────────────────────────────────────────
function care_logCall(int $clientId, string $careNumber, string $caller, string $callSid, string $disposition, int $duration): void {
    try {
        $st = care_db()->prepare(
            'INSERT INTO care_calls (client_id, twilio_number, caller, call_sid, disposition, duration)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE disposition = VALUES(disposition), duration = VALUES(duration)'
        );
        $st->execute([$clientId, $careNumber, $caller, ($callSid ?: null), $disposition, $duration]);
    } catch (Throwable $e) { care_log('logCall err: ' . $e->getMessage()); }
}

function care_markTextback(string $callSid): void {
    if ($callSid === '') return;
    try { care_db()->prepare('UPDATE care_calls SET textback_sent = 1 WHERE call_sid = ?')->execute([$callSid]); }
    catch (Throwable $e) {}
}

function care_logSms(int $clientId, string $direction, string $careNumber, string $counterparty, string $body, ?string $sid, string $kind): void {
    try {
        $st = care_db()->prepare(
            'INSERT INTO care_sms (client_id, direction, twilio_number, counterparty, body, message_sid, kind)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $st->execute([$clientId, $direction, $careNumber, $counterparty, mb_substr($body, 0, 2000), ($sid ?: null), $kind]);
    } catch (Throwable $e) { care_log('logSms err: ' . $e->getMessage()); }
}

// Send FROM the Care number + log it. Honors opt-out and the daily safety cap.
function care_sendSms(int $clientId, string $careNumber, string $to, string $body, string $kind): array {
    if (care_isOptedOut($to)) { care_log("suppressed (opt-out) to={$to}"); return ['ok'=>false, 'error'=>'opted_out']; }
    if (care_dailySmsCapReached($clientId)) { care_log("suppressed (daily cap) client={$clientId} to={$to}"); return ['ok'=>false, 'error'=>'daily_cap']; }
    $r = care_twilioSendSms($to, $careNumber, $body);
    care_logSms($clientId, 'out', $careNumber, $to, $body, ($r['data']['sid'] ?? null), $kind);
    return $r;
}

// True once a client has sent CARE_DAILY_SMS_CAP outbound texts today (safety
// valve against a runaway loop / abuse spiking the Twilio bill).
function care_dailySmsCapReached(int $clientId): bool {
    try {
        $st = care_db()->prepare("SELECT COUNT(*) FROM care_sms WHERE client_id=? AND direction='out' AND created_at >= CURDATE()");
        $st->execute([$clientId]);
        return (int)$st->fetchColumn() >= CARE_DAILY_SMS_CAP;
    } catch (Throwable $e) { return false; }   // never block a legit send on a counter error
}

function care_optOut(?string $phone): void {
    if (!$phone) return;
    try { care_db()->prepare('INSERT IGNORE INTO care_optouts (phone) VALUES (?)')->execute([$phone]); }
    catch (Throwable $e) {}
}
function care_optIn(?string $phone): void {
    if (!$phone) return;
    try { care_db()->prepare('DELETE FROM care_optouts WHERE phone = ?')->execute([$phone]); }
    catch (Throwable $e) {}
}

// Most-recent customer this client texted with (for routing a contractor reply).
function care_lastCustomer(int $clientId, string $excludePhone): ?string {
    try {
        // Only route to a customer we heard from in the last 3h, so a stray
        // contractor text doesn't get relayed to an old/unrelated conversation.
        $st = care_db()->prepare(
            "SELECT counterparty FROM care_sms
             WHERE client_id = ? AND direction = 'in' AND counterparty <> ?
               AND created_at > (NOW() - INTERVAL 3 HOUR)
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$clientId, $excludePhone]);
        $c = $st->fetchColumn();
        return $c ?: null;
    } catch (Throwable $e) { return null; }
}

// ── Flow handlers (return TwiML strings) ─────────────────────────────────

// Incoming call → forward to the contractor's cell.
function care_handleIncomingCall(string $careNumber, string $actionUrl): string {
    $row = care_numberRow($careNumber);
    if (!$row) return care_xml('<Response><Say>This number is not in service.</Say><Hangup/></Response>');
    $ring   = (int)($row['ring_seconds'] ?: 20);
    $fwd    = crm_h((string)$row['forward_to']);
    $action = crm_h($actionUrl);
    // callerId = the Care number (a number we own): required so the forwarded
    // leg isn't rejected (trial: unverified caller ID 21264; production:
    // carriers block presenting a third-party number). The contractor sees the
    // call arrive from their own business line.
    return care_xml(
        '<Response><Dial timeout="' . $ring . '" answerOnBridge="true" callerId="' . crm_h($careNumber) . '" action="' . $action . '" method="POST">'
        . '<Number>' . $fwd . '</Number></Dial></Response>'
    );
}

// <Dial> finished → answered vs missed; on missed, text the caller back.
function care_handleDialStatus(array $p): string {
    $careNumber = (string)($p['To'] ?? '');
    $caller     = (string)($p['From'] ?? '');
    $status     = (string)($p['DialCallStatus'] ?? '');
    $callSid    = (string)($p['CallSid'] ?? '');
    $duration   = (int)($p['DialCallDuration'] ?? 0);

    $row = care_numberRow($careNumber);
    if (!$row) return care_xml('<Response><Hangup/></Response>');
    $clientId = (int)$row['client_id'];

    $answered    = ($status === 'completed' && $duration > 0);
    $disposition = $answered ? 'answered' : 'missed';
    care_logCall($clientId, $careNumber, $caller, $callSid, $disposition, $duration);

    // Drop the caller into the job tracker as a lead (deduped, name via CNAM).
    if (function_exists('care_upsertJobFromCall')) care_upsertJobFromCall($clientId, $caller);

    if (!$answered) {
        // Don't re-text on Twilio status-callback retries for the same call.
        $already = false;
        if ($callSid !== '') {
            try { $st = care_db()->prepare('SELECT textback_sent FROM care_calls WHERE call_sid = ? LIMIT 1'); $st->execute([$callSid]); $already = ((int)$st->fetchColumn() === 1); }
            catch (Throwable $e) {}
        }
        $callerE = care_e164($caller);
        if (!$already && $callerE && !care_isOptedOut($callerE)) {
            $biz = care_clientName($clientId);
            care_sendSms($clientId, $careNumber, $callerE, care_missedCallMessage($biz), 'textback');
            care_markTextback($callSid);
        }
        return care_xml('<Response><Say voice="alice">Sorry we missed you. We just sent you a text message.</Say><Hangup/></Response>');
    }
    return care_xml('<Response><Hangup/></Response>');
}

// Website-form lead → drop it into the tracker as a lead + text the contractor
// on their own Care number. Returns ['care'=>true] when Care handled it (client
// has an active number); ['care'=>false] tells the caller to fall back to email.
function care_handleWebLead(int $clientId, ?string $name, ?string $phone, ?string $message): array {
    try {
        $st = care_db()->prepare('SELECT twilio_number, forward_to FROM care_numbers WHERE client_id=? AND active=1 ORDER BY id DESC LIMIT 1');
        $st->execute([$clientId]);
        $row = $st->fetch();
    } catch (Throwable $e) { $row = null; }
    if (!$row) return ['care'=>false];   // not provisioned → caller emails instead

    $careNumber = (string)$row['twilio_number'];
    $fwd = care_e164((string)$row['forward_to']) ?: (string)$row['forward_to'];
    $e   = care_e164((string)$phone);

    // Store as a lead, deduped against an open job for the same number. 'web'
    // source needs schema-v20; if not yet applied the insert is caught + skipped
    // (the contractor still gets the alert below).
    if ($e) {
        try {
            $q = care_db()->prepare("SELECT id FROM care_jobs WHERE client_id=? AND phone=? AND status IN ('lead','scheduled') LIMIT 1");
            $q->execute([$clientId, $e]);
            if (!$q->fetchColumn()) {
                care_db()->prepare("INSERT INTO care_jobs (client_id, name, phone, status, source) VALUES (?, ?, ?, 'lead', 'web')")
                    ->execute([$clientId, ($name ?: null), $e]);
            }
        } catch (Throwable $ex) { care_log('webLead job err: ' . $ex->getMessage()); }
    }

    // Alert the contractor (1 segment, no emoji → stays one ~1¢ SMS).
    if ($fwd) {
        $who  = ($name !== null && $name !== '') ? $name : 'New lead';
        $body = 'New lead - ' . $who . ', ' . ($e ?: (string)$phone) . '. Call them back.';
        care_sendSms($clientId, $careNumber, $fwd, $body, 'lead');
    }
    return ['care'=>true];
}

// Incoming SMS → STOP/START, or 2-way relay between customer and contractor.
function care_handleIncomingSms(array $p): string {
    $careNumber = (string)($p['To'] ?? '');
    $from       = (string)($p['From'] ?? '');
    $body       = trim((string)($p['Body'] ?? ''));
    $sid        = (string)($p['MessageSid'] ?? '');

    $row = care_numberRow($careNumber);
    if (!$row) return care_xml('<Response/>');
    $clientId = (int)$row['client_id'];
    $fromE    = care_e164($from) ?: $from;
    $fwd      = care_e164((string)$row['forward_to']) ?: (string)$row['forward_to'];

    // Opt-out / opt-in keywords (TCPA).
    $up = strtoupper($body);
    if (in_array($up, ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
        care_optOut($fromE);
        care_logSms($clientId, 'in', $careNumber, $fromE, $body, $sid, 'other');
        return care_xml("<Response><Message>You're opted out and won't get more texts. Reply START to opt back in.</Message></Response>");
    }
    if (in_array($up, ['START', 'UNSTOP', 'YES'], true)) { care_optIn($fromE); }

    care_logSms($clientId, 'in', $careNumber, $fromE, $body, $sid, 'relay');

    if ($fromE === $fwd) {
        // Text-to-trigger a review: contractor texts "review <customer number>".
        if (preg_match('/^\s*review\b\s*(.*)$/i', $body, $m) && function_exists('care_queueReview')) {
            $target = care_e164(trim($m[1]));
            if ($target) {
                care_queueReview($clientId, $target, null, 'sms');
                care_sendSms($clientId, $careNumber, $fwd, "Got it — we'll text {$target} for a review.", 'other');
                return care_xml('<Response/>');
            }
        }
        // Otherwise: relay the reply to the most-recent customer for this client.
        $cust = care_lastCustomer($clientId, $fwd);
        if ($cust) care_sendSms($clientId, $careNumber, $cust, $body, 'relay');
        return care_xml('<Response/>');
    }

    // Customer → forward the message to the contractor's cell.
    if (!care_isOptedOut($fromE)) {
        care_sendSms($clientId, $careNumber, $fwd, "New text from {$fromE}: {$body}", 'relay');
    }
    return care_xml('<Response/>');
}
