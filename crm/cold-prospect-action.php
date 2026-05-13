<?php
// POST endpoint for cold-calling.php inline actions.
//
// Accepts a single JSON body: {"action": "...", "csrf": "...", ...args}.
// Always returns JSON: {"ok": true|false, ...} (200 on bad action too, so
// the UI can show error text without alarming network errors).

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/dnc_scrub.php';
require_once __DIR__ . '/lib/phone_normalize.php';

header('Content-Type: application/json');

$user = crm_currentUser();
if (!$user || !in_array($user['role'] ?? '', ['founder','sales'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

if (!crm_csrfCheck((string)($body['csrf'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'bad csrf']);
    exit;
}

$action = (string)($body['action'] ?? '');
$pdo    = crm_db();

try {
    switch ($action) {

        case 'mark_called': {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('bad id');
            $stmt = $pdo->prepare(
                'UPDATE cold_prospects
                    SET call_attempts  = call_attempts + 1,
                        last_called_at = NOW(),
                        last_called_by = ?
                  WHERE id = ?'
            );
            $stmt->execute([(int)$user['id'], $id]);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'set_disposition': {
            $id     = (int)($body['id'] ?? 0);
            $status = (string)($body['status'] ?? '');
            $allowed = ['no_answer','voicemail','busy','wrong_number','not_interested','dead'];
            if ($id <= 0 || !in_array($status, $allowed, true)) {
                throw new RuntimeException('bad input');
            }
            $stmt = $pdo->prepare(
                'UPDATE cold_prospects SET call_status = ? WHERE id = ?'
            );
            $stmt->execute([$status, $id]);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'mark_dnc': {
            $id = (int)($body['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('bad id');
            $stmt = $pdo->prepare(
                'UPDATE cold_prospects
                    SET call_status = "dnc_requested",
                        dnc_status  = "blocked_internal",
                        dnc_meta_json = JSON_OBJECT("reason","va_requested_at_call","by_user_id",?,"at",?)
                  WHERE id = ?'
            );
            $stmt->execute([(int)$user['id'], gmdate('c'), $id]);
            crm_log('cold_dnc_internal id=' . $id . ' by=' . (int)$user['id']);
            echo json_encode(['ok' => true]);
            break;
        }

        case 'convert_to_lead': {
            $id     = (int)($body['id'] ?? 0);
            $temp   = (string)($body['temperature'] ?? 'warm');
            $notes  = trim((string)($body['notes'] ?? ''));
            if ($id <= 0) throw new RuntimeException('bad id');
            if (!in_array($temp, ['hot','warm','cold'], true)) $temp = 'warm';

            $sel = $pdo->prepare('SELECT * FROM cold_prospects WHERE id = ? LIMIT 1');
            $sel->execute([$id]);
            $p = $sel->fetch();
            if (!$p) throw new RuntimeException('prospect not found');
            if ((string)$p['dnc_status'] !== 'clean') {
                throw new RuntimeException('cannot convert a non-clean prospect');
            }
            if (!empty($p['converted_lead_id'])) {
                echo json_encode(['ok' => true, 'lead_id' => (int)$p['converted_lead_id']]);
                break;
            }

            // Split contact_name into first/last for the leads table.
            $first = ''; $last = '';
            $cn = trim((string)($p['contact_name'] ?? ''));
            if ($cn !== '') {
                $parts = preg_split('/\s+/', $cn, 2);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';
            }

            $cityState = trim(((string)$p['city']) . (((string)$p['state']) ? ', ' . $p['state'] : ''), ', ');

            // Compose the lead note with VA attribution + their call notes.
            $whoLabel = $user['display_name'] ?: $user['username'];
            $leadNotes = '[Converted from cold call by ' . $whoLabel . ' on ' . date('Y-m-d H:i') . ']';
            if ($notes !== '') $leadNotes .= "\n\n" . $notes;

            // INSERT directly (don't call crm_insertLead) — we want to skip
            // the auto-fire of sequences for 'lead_created' trigger on this
            // source. A converted cold lead already had a human conversation;
            // dropping them into a generic welcome sequence is wrong.
            $ins = $pdo->prepare(
                'INSERT INTO leads
                  (source, first_name, last_name, email, phone,
                   business_name, trade, city_state, website, gbp_url,
                   status, temperature, notes, message)
                 VALUES ("cold_call", ?, ?, ?, ?, ?, ?, ?, ?, ?, "contacted", ?, ?, ?)'
            );
            $ins->execute([
                $first !== '' ? mb_substr($first, 0, 80)  : null,
                $last  !== '' ? mb_substr($last,  0, 80)  : null,
                !empty($p['email']) ? mb_substr((string)$p['email'], 0, 160) : null,
                (string)$p['phone'],
                !empty($p['business_name']) ? mb_substr((string)$p['business_name'], 0, 160) : null,
                !empty($p['trade'])         ? mb_substr((string)$p['trade'],         0, 80)  : null,
                $cityState !== ''           ? mb_substr($cityState,                  0, 120) : null,
                !empty($p['website'])       ? mb_substr((string)$p['website'],       0, 255) : null,
                !empty($p['gbp_url'])       ? (string)$p['gbp_url'] : null,
                $temp,
                $leadNotes,
                $notes !== '' ? $notes : null,
            ]);
            $leadId = (int) $pdo->lastInsertId();

            $upd = $pdo->prepare(
                'UPDATE cold_prospects
                    SET call_status       = "converted",
                        converted_lead_id = ?,
                        converted_at      = NOW()
                  WHERE id = ?'
            );
            $upd->execute([$leadId, $id]);

            // Seed an activity on the lead (best-effort).
            if (file_exists(__DIR__ . '/lib/activities.php')) {
                require_once __DIR__ . '/lib/activities.php';
                if (function_exists('crm_logActivity')) {
                    crm_logActivity($leadId, (int)$user['id'], 'user', 'cold_converted',
                        'Converted from cold prospect #' . $id . ' (' . (string)$p['phone'] . ')');
                }
            }

            crm_log('cold_converted prospect_id=' . $id . ' lead_id=' . $leadId . ' by=' . (int)$user['id']);
            echo json_encode(['ok' => true, 'lead_id' => $leadId]);
            break;
        }

        case 'rescrub_all': {
            // Founder only — re-call DNCScrub for every currently-clean prospect.
            if (($user['role'] ?? '') !== 'founder') {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'founder only']);
                break;
            }
            $rows = $pdo->query("SELECT id, phone FROM cold_prospects WHERE dnc_status='clean'")->fetchAll();
            if (empty($rows)) {
                echo json_encode(['ok' => true, 'scrubbed' => 0, 'newly_blocked' => 0]);
                break;
            }
            $phones = array_column($rows, 'phone');
            $results = crm_dncScrubBatch($phones);
            $upd = $pdo->prepare(
                'UPDATE cold_prospects
                    SET dnc_status      = ?,
                        dnc_scrubbed_at = NOW(),
                        dnc_meta_json   = ?
                  WHERE id = ? AND dnc_status = "clean"'
            );
            $newlyBlocked = 0;
            foreach ($rows as $r) {
                $res = $results[$r['phone']] ?? ['status' => 'clean', 'meta' => null];
                $status = (string)$res['status'];
                if (strpos($status, 'blocked_') === 0) $newlyBlocked++;
                $upd->execute([
                    $status,
                    $res['meta'] ? json_encode($res['meta'], JSON_UNESCAPED_SLASHES) : null,
                    (int)$r['id'],
                ]);
            }
            crm_log('cold_rescrub_all scrubbed=' . count($rows) . ' newly_blocked=' . $newlyBlocked . ' by=' . (int)$user['id']);
            echo json_encode(['ok' => true, 'scrubbed' => count($rows), 'newly_blocked' => $newlyBlocked]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'unknown action']);
    }
} catch (Throwable $e) {
    error_log('[cold-prospect-action] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
