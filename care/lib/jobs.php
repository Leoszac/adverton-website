<?php
// Adverton Care — the mega-simple job tracker. The "no CRM? use our list"
// fallback so EVERY client gets automatic reviews. Missed-call leads land here
// automatically; moving a job to "done" auto-queues the review request.
// Deliberately bare (name/phone/address/status only) — not a CRM. Clients who
// want real job management use Tradio / Housecall Pro / Jobber (which integrate
// for the same auto-review trigger).

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/reviews.php';   // care_db, care_e164, care_queueReview, care_twilio*

const CARE_JOB_STATUSES = ['lead', 'scheduled', 'done', 'lost'];

// Twilio Caller-ID Name (CNAM) lookup. ~1¢/call when live; null in stub or when
// the carrier has no name (common for mobiles).
function care_lookupCallerName(string $phoneE164): ?string {
    if (care_twilioStub()) return null;
    $sid = care_twilioSid(); $token = care_twilioToken();
    if (!$sid || !$token) return null;
    $url = 'https://lookups.twilio.com/v2/PhoneNumbers/' . rawurlencode($phoneE164) . '?Fields=caller_name';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>6, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_USERPWD=>$sid . ':' . $token]);
    $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($r === false || $code >= 400) return null;
    $d = json_decode((string)$r, true);
    $nm = $d['caller_name']['caller_name'] ?? null;
    return ($nm && strtoupper($nm) !== 'UNKNOWN') ? $nm : null;
}

// Called from the call flow: drop an inbound caller into the pipeline as a
// "lead" (deduped) so the contractor can move it along. Best-effort.
function care_upsertJobFromCall(int $clientId, string $phone): void {
    $e = care_e164($phone);
    if (!$e) return;
    try {
        $st = care_db()->prepare("SELECT id FROM care_jobs WHERE client_id=? AND phone=? AND status IN ('lead','scheduled') LIMIT 1");
        $st->execute([$clientId, $e]);
        if ($st->fetchColumn()) return;   // already an open job for this number
        $name = care_lookupCallerName($e);
        care_db()->prepare("INSERT INTO care_jobs (client_id, name, phone, status, source) VALUES (?, ?, ?, 'lead', 'call')")
            ->execute([$clientId, $name, $e]);
    } catch (Throwable $ex) { care_log('upsertJob err: ' . $ex->getMessage()); }
}

function care_addJob(int $clientId, ?string $name, string $phone, ?string $address): array {
    $e = care_e164($phone);
    if (!$e) return ['ok'=>false, 'error'=>'bad phone'];
    try {
        care_db()->prepare("INSERT INTO care_jobs (client_id, name, phone, address, status, source) VALUES (?, ?, ?, ?, 'lead', 'manual')")
            ->execute([$clientId, ($name ?: null), $e, ($address ?: null)]);
        return ['ok'=>true, 'id'=>(int)care_db()->lastInsertId()];
    } catch (Throwable $ex) { return ['ok'=>false, 'error'=>$ex->getMessage()]; }
}

// Update name/status. Moving to "done" auto-queues the review (once).
function care_updateJob(int $clientId, int $jobId, ?string $status, ?string $name): void {
    try {
        $st = care_db()->prepare('SELECT phone, name, review_queued FROM care_jobs WHERE id=? AND client_id=?');
        $st->execute([$jobId, $clientId]);
        $j = $st->fetch();
        if (!$j) return;
        $sets = []; $args = [];
        if ($name !== null)   { $sets[] = 'name = ?';   $args[] = ($name !== '' ? $name : null); }
        if ($status !== null && in_array($status, CARE_JOB_STATUSES, true)) { $sets[] = 'status = ?'; $args[] = $status; }
        if ($sets) { $args[] = $jobId; $args[] = $clientId; care_db()->prepare('UPDATE care_jobs SET ' . implode(', ', $sets) . ' WHERE id=? AND client_id=?')->execute($args); }
        if ($status === 'done' && !$j['review_queued']) {
            $finalName = ($name !== null && $name !== '') ? $name : ($j['name'] ?: null);
            care_queueReview($clientId, (string)$j['phone'], $finalName, 'manual');
            care_db()->prepare('UPDATE care_jobs SET review_queued=1 WHERE id=?')->execute([$jobId]);
        }
    } catch (Throwable $ex) { care_log('updateJob err: ' . $ex->getMessage()); }
}

// Build the WHERE for a filtered/searched list (scales to thousands of jobs).
function care_jobsWhere(int $clientId, string $filter, string $q): array {
    $where = 'client_id = ?'; $args = [$clientId];
    if (in_array($filter, CARE_JOB_STATUSES, true)) { $where .= ' AND status = ?'; $args[] = $filter; }
    $q = trim($q);
    if ($q !== '') {
        $digits = preg_replace('/\D/', '', $q);
        if ($digits !== '' && strlen($digits) >= 3) { $where .= ' AND (name LIKE ? OR phone LIKE ?)'; $args[] = "%$q%"; $args[] = "%$digits%"; }
        else { $where .= ' AND name LIKE ?'; $args[] = "%$q%"; }
    }
    return [$where, $args];
}

// One status bucket at a time, newest first, paginated + searchable.
function care_listJobs(int $clientId, string $filter = 'lead', string $q = '', int $limit = 20, int $offset = 0): array {
    try {
        [$where, $args] = care_jobsWhere($clientId, $filter, $q);
        $st = care_db()->prepare("SELECT * FROM care_jobs WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?");
        $i = 1; foreach ($args as $v) { $st->bindValue($i++, $v); }
        $st->bindValue($i++, $limit, PDO::PARAM_INT);
        $st->bindValue($i, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $ex) { return []; }
}

function care_countJobs(int $clientId, string $filter = 'lead', string $q = ''): int {
    try {
        [$where, $args] = care_jobsWhere($clientId, $filter, $q);
        $st = care_db()->prepare("SELECT COUNT(*) FROM care_jobs WHERE $where");
        $st->execute($args);
        return (int)$st->fetchColumn();
    } catch (Throwable $ex) { return 0; }
}

function care_jobCounts(int $clientId): array {
    $out = ['lead'=>0,'scheduled'=>0,'done'=>0,'lost'=>0];
    try {
        $st = care_db()->prepare('SELECT status, COUNT(*) c FROM care_jobs WHERE client_id=? GROUP BY status');
        $st->execute([$clientId]);
        foreach ($st as $r) { $out[$r['status']] = (int)$r['c']; }
    } catch (Throwable $ex) {}
    return $out;
}
