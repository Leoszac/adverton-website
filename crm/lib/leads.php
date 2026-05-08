<?php
// CRM lead persistence — INSERT from public forms (audit.php / send.php) and
// SELECT/UPDATE from the CRM UI.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/activities.php';

const CRM_LEAD_SOURCES  = ['audit_auto', 'audit_manual', 'contact_form', 'inbound_call', 'manual', 'ebook_growth_engine', 'referral', 'affiliate', 'csv_import', 'cold_email_instantly'];
const CRM_LEAD_STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

// Auto-classify temperature for audit_auto leads. Mirrors the heuristic in
// website-deploy/lib/audit-email.php classifyLead(): low score + core trade +
// valid phone => HOT. Mid score => WARM. Otherwise COLD.
function crm_autoTemperature(array $data): ?string {
    if (($data['source'] ?? '') !== 'audit_auto') return 'warm'; // benefit of the doubt
    $score = isset($data['audit_score']) ? (int)$data['audit_score'] : null;
    if ($score === null) return null;
    if ($score < 50) return 'hot';
    if ($score < 75) return 'warm';
    return 'cold';
}

// Numeric lead score (0-100) — combines source quality, audit signal,
// engagement and freshness. Higher = more deserving of immediate attention.
//
// Components:
//   Source base       0–35  pts   audit_auto > ebook > contact > other
//   Audit need        0–25  pts   inverse of audit_score (low = high need)
//   Core-trade bonus  0–10  pts   HVAC / Plumbing / Roofing / Electrical
//   Phone present     0–5   pts   valid 10+ digits
//   Engagement        0–25  pts   opens × 3 + clicks × 10  (capped)
//   Recency penalty   0–-10 pts   stale leads lose score over 30+ days
//
// $row keys expected (best-effort, missing = 0): source, audit_score, trade,
// phone, opens, clicks, days_old.
function crm_computeLeadScore(array $row): int {
    $score = 0;

    // Source base
    $sourceBase = [
        'audit_auto'           => 35,  // ran the audit, gave us their profile — highest intent
        'audit_manual'         => 30,
        'ebook_growth_engine'  => 18,  // longer top-of-funnel
        'contact_form'         => 22,
        'inbound_call'         => 35,
        'manual'               => 15,
    ];
    $score += $sourceBase[(string)($row['source'] ?? '')] ?? 10;

    // Audit need (inverse of audit_score: 100 → 0 pts, 0 → 25 pts)
    if (isset($row['audit_score']) && $row['audit_score'] !== null) {
        $audit = (int)$row['audit_score'];
        $score += (int) round(max(0, min(25, (100 - $audit) * 0.25)));
    }

    // Core-trade bonus (the four trades we know convert best)
    $coreTrades = ['HVAC', 'Plumbing', 'Roofing', 'Electrical'];
    if (in_array((string)($row['trade'] ?? ''), $coreTrades, true)) $score += 10;

    // Valid phone
    $phoneDigits = preg_replace('/\D/', '', (string)($row['phone'] ?? ''));
    if (strlen($phoneDigits) >= 10) $score += 5;

    // Engagement (opens × 3 + clicks × 10, cap at 25)
    $opens  = (int)($row['opens']  ?? 0);
    $clicks = (int)($row['clicks'] ?? 0);
    $score += min(25, $opens * 3 + $clicks * 10);

    // Recency penalty: > 30 days since creation, lose 1 pt per 5 days, max -10
    if (isset($row['days_old']) && $row['days_old'] !== null) {
        $daysOld = (int)$row['days_old'];
        if ($daysOld > 30) {
            $score -= (int) min(10, floor(($daysOld - 30) / 5));
        }
    }

    return max(0, min(100, $score));
}

// Pull lead + engagement aggregates and compute a fresh score for one lead.
// Useful when you have just a lead_id and want the current numeric score.
function crm_getLeadScore(int $leadId): int {
    try {
        $stmt = crm_db()->prepare(
            'SELECT l.source, l.audit_score, l.trade, l.phone,
                    DATEDIFF(NOW(), l.created_at) AS days_old,
                    COALESCE(SUM(es.open_count), 0)  AS opens,
                    COALESCE(SUM(es.click_count), 0) AS clicks
               FROM leads l
               LEFT JOIN email_sends es ON es.lead_id = l.id
              WHERE l.id = ?
              GROUP BY l.id'
        );
        $stmt->execute([$leadId]);
        $row = $stmt->fetch();
        if (!$row) return 0;
        return crm_computeLeadScore($row);
    } catch (Throwable $e) { return 0; }
}

// Insert from public forms. Best-effort: silently swallows DB errors so
// a misconfigured DB never breaks lead capture.
//
// Dedupe: if a lead with the same email or normalized phone already exists,
// we DO NOT create a new row. We append a "system" activity to the existing
// lead and return its id. This keeps the pipeline clean when prospects
// re-submit from different pages.
function crm_insertLead(array $data): ?int {
    try {
        if (!in_array($data['source'] ?? '', CRM_LEAD_SOURCES, true)) {
            throw new InvalidArgumentException('bad source: ' . ($data['source'] ?? ''));
        }

        $email     = strtolower(trim((string)($data['email'] ?? '')));
        $phoneNorm = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));

        // Try to find an existing lead by email or phone
        $existing = crm_findDuplicateLead($email, $phoneNorm);
        if ($existing) {
            $bits = [];
            $bits[] = 'Re-submitted from ' . ($data['source'] ?? '?');
            if (!empty($data['source_page'])) {
                $bits[] = 'on ' . $data['source_page'];
            }
            if (!empty($data['audit_score'])) {
                $bits[] = 'audit score ' . (int)$data['audit_score'] . '/100';
            }
            crm_logActivity((int)$existing, null, 'system', 'duplicate', implode(' · ', $bits));
            return (int)$existing;
        }

        $cols = [
            'source','source_page',
            'first_name','last_name','email','phone',
            'business_name','trade','city_state','website',
            'gbp_url','audit_score','audit_id',
            'revenue','message',
            'ip','user_agent',
            'utm_source','utm_medium','utm_campaign',
            'temperature',
        ];
        $values = [];
        foreach ($cols as $c) {
            $values[$c] = array_key_exists($c, $data) ? $data[$c] : null;
        }
        if (isset($values['audit_score'])) {
            $values['audit_score'] = max(0, min(100, (int)$values['audit_score']));
        }
        if ($values['temperature'] === null) {
            $values['temperature'] = crm_autoTemperature($data);
        }
        // Truncate strings to schema lengths to avoid INSERT errors
        $maxLens = [
            'source_page'   => 255, 'first_name' => 80, 'last_name' => 80,
            'email'         => 160, 'phone'      => 40, 'business_name' => 160,
            'trade'         => 80,  'city_state' => 120, 'website'    => 255,
            'audit_id'      => 32,  'revenue'    => 40,
            'ip'            => 45,  'user_agent' => 255,
            'utm_source'    => 80,  'utm_medium' => 80, 'utm_campaign' => 80,
        ];
        foreach ($maxLens as $k => $n) {
            if (is_string($values[$k] ?? null)) {
                $values[$k] = mb_substr($values[$k], 0, $n);
            }
        }

        // Routing: auto-assign owner if a routing rule matches
        $assignedOwner = null;
        if (file_exists(__DIR__ . '/routing.php')) {
            require_once __DIR__ . '/routing.php';
            $assignedOwner = crm_resolveOwner($values);
        }

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colSql = implode(',', $cols);
        $extraCol = ''; $extraPlaceholder = ''; $extraVal = null;
        if ($assignedOwner) {
            $extraCol = ', owner_user_id';
            $extraPlaceholder = ', ?';
            $extraVal = $assignedOwner;
        }
        $sql = "INSERT INTO leads ({$colSql}{$extraCol}) VALUES ({$placeholders}{$extraPlaceholder})";
        $stmt = crm_db()->prepare($sql);
        $params = array_values($values);
        if ($assignedOwner) $params[] = $extraVal;
        $stmt->execute($params);
        $id = (int) crm_db()->lastInsertId();

        // Seed initial activity
        $where = $values['source_page'] ? ' from ' . $values['source_page'] : '';
        crm_logActivity($id, null, 'system', 'submitted',
            ucfirst(str_replace('_', ' ', (string)$values['source'])) . $where);

        // Best-effort webhook notification (Slack-compatible, Telegram via bot, etc.)
        crm_fireNewLeadWebhook($id, $values);

        // HOT lead → bypass nurture, create immediate "call now" task.
        // Nurture sequences are designed for cold/warm leads who need warming up.
        // A hot lead (low audit score, core trade, valid phone) needs a human
        // call within the hour, not a 14-day drip.
        if (($values['temperature'] ?? '') === 'hot' && file_exists(__DIR__ . '/tasks.php')) {
            require_once __DIR__ . '/tasks.php';
            $score = $values['audit_score'] ?? null;
            $title = 'CALL NOW — HOT lead' . ($score !== null ? " ({$score}/100)" : '');
            $notes = 'Auto-created on lead intake. '
                   . 'Source: ' . ($values['source'] ?? '?')
                   . ' · Trade: ' . ($values['trade'] ?? '—')
                   . ' · Phone: ' . ($values['phone'] ?? '—')
                   . ' · Speed-to-lead: every minute past minute one is conversion left on the table.';
            crm_createTask([
                'lead_id'     => $id,
                'assigned_to' => $assignedOwner,  // null if no routing rule matched — task still shows in unassigned bucket
                'title'       => $title,
                'notes'       => $notes,
                'due_at'      => date('Y-m-d H:i:s', time() + 3600),  // +1 hour
            ]);
            crm_logActivity($id, null, 'system', 'hot_lead_routed',
                'Bypassed nurture; created callback task due in 1 hour: ' . $title);
            return $id;
        }

        // Auto-enroll in active sequences scoped to this source
        // (audit_auto, audit_manual, ebook_growth_engine, contact_form, ...)
        // Only WARM/COLD leads — HOT bypassed above.
        if (file_exists(__DIR__ . '/sequences.php')) {
            require_once __DIR__ . '/sequences.php';
            crm_dispatchSequenceTrigger('lead_created', $id, (string)($values['source'] ?? ''));
        }

        return $id;
    } catch (Throwable $e) {
        error_log('[crm_insertLead] ' . $e->getMessage());
        return null;
    }
}

function crm_fireNewLeadWebhook(int $leadId, array $values): void {
    $url = crm_config('NEW_LEAD_WEBHOOK_URL');
    if (!$url) return;
    $name  = trim(($values['first_name'] ?? '') . ' ' . ($values['last_name'] ?? ''));
    $temp  = $values['temperature'] ?? '';
    $emoji = $temp === 'hot' ? '🔥' : ($temp === 'cold' ? '🧊' : '⭐');
    $score = isset($values['audit_score']) && $values['audit_score'] !== null ? "{$values['audit_score']}/100" : '';
    $text  = "{$emoji} New lead — " . ($name ?: 'Unnamed')
           . ($values['business_name'] ? " ({$values['business_name']})" : '')
           . ($values['trade'] ? " · {$values['trade']}" : '')
           . ($score ? " · {$score}" : '')
           . ' · ' . ($values['source'] ?? '');
    $crmUrl = 'https://adverton.net/crm/lead.php?id=' . $leadId;

    // Slack-incoming-webhook compatible. Discord webhooks accept the same `text`.
    $payload = [
        'text' => $text . "\n" . $crmUrl,
        // Extra structured payload — receivers can ignore or use:
        'lead' => [
            'id'            => $leadId,
            'name'          => $name,
            'email'         => $values['email']         ?? null,
            'phone'         => $values['phone']         ?? null,
            'business_name' => $values['business_name'] ?? null,
            'trade'         => $values['trade']         ?? null,
            'source'        => $values['source']        ?? null,
            'audit_score'   => $values['audit_score']   ?? null,
            'temperature'   => $temp,
            'crm_url'       => $crmUrl,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER    => true,
        CURLOPT_POST              => true,
        CURLOPT_POSTFIELDS        => json_encode($payload),
        CURLOPT_HTTPHEADER        => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT_MS        => 1500,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_NOSIGNAL          => 1,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function crm_findDuplicateLead(string $emailLc, string $phoneDigits): ?int {
    $sql = 'SELECT id FROM leads WHERE 1=0';
    $params = [];
    if ($emailLc !== '') {
        $sql .= ' OR LOWER(email) = ?';
        $params[] = $emailLc;
    }
    if ($phoneDigits !== '' && strlen($phoneDigits) >= 10) {
        // Match on last 10 digits (handles +1 prefix variations)
        $sql .= " OR REGEXP_REPLACE(phone, '[^0-9]', '') REGEXP CONCAT(?, '$')";
        $params[] = substr($phoneDigits, -10);
    }
    $sql .= ' ORDER BY id DESC LIMIT 1';
    if (!$params) return null;
    try {
        $stmt = crm_db()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    } catch (Throwable $e) {
        // Older MySQL without REGEXP_REPLACE — fall back to email-only
        if ($emailLc === '') return null;
        try {
            $stmt = crm_db()->prepare('SELECT id FROM leads WHERE LOWER(email) = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$emailLc]);
            $row = $stmt->fetch();
            return $row ? (int)$row['id'] : null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

// Filtered list. $filters keys: source, status, q (search), temperature, owner, mine.
// Returns each lead with an extra computed `lead_score` (0-100) baked in.
// $sort: 'created' (default) | 'score' | 'engagement' | 'stale'
function crm_listLeads(array $filters = [], int $limit = 50, int $offset = 0, string $sort = 'created'): array {
    [$where, $params] = crm_buildWhere($filters);

    // crm_buildWhere returns refs qualified as `leads.col` (works when
    // FROM leads has no alias, e.g. crm_countLeads). Here we use `FROM leads l`
    // so MySQL requires the alias — convert every `leads.` → `l.` in the
    // WHERE clause. This handles ambiguous columns from the email_sends JOIN
    // and is robust against future buildWhere changes (any new `leads.col`
    // automatically picks up the alias).
    $whereAliased = str_replace('leads.', 'l.', $where);

    // ORDER BY whitelist — never interpolate raw user input into SQL.
    // 'engagement' = recent open/click activity bubbled up.
    // 'stale'      = oldest leads first (by last update or creation).
    // 'score'      = computed lead_score (we sort in PHP after the SELECT
    //                because score is a PHP function, not a SQL expression).
    $orderBy = match ($sort) {
        'engagement' => 'COALESCE(es.clicks,0) * 3 + COALESCE(es.opens,0) DESC, l.created_at DESC',
        'stale'      => 'l.updated_at ASC',
        default      => 'l.created_at DESC',  // 'created' or unknown
    };

    $sql = "SELECT l.id, l.source, l.source_page, l.first_name, l.last_name, l.email, l.phone,
                   l.business_name, l.trade, l.audit_score, l.status, l.owner_user_id,
                   l.temperature, l.monthly_fee, l.ad_budget, l.mgmt_fee_pct, l.expected_close_at,
                   l.created_at, l.updated_at,
                   DATEDIFF(NOW(), l.created_at) AS days_old,
                   COALESCE(es.opens, 0)  AS opens,
                   COALESCE(es.clicks, 0) AS clicks
            FROM leads l
            LEFT JOIN (
              SELECT lead_id,
                     SUM(open_count)  AS opens,
                     SUM(click_count) AS clicks
                FROM email_sends
               GROUP BY lead_id
            ) es ON es.lead_id = l.id
            {$whereAliased}
            ORDER BY {$orderBy}
            LIMIT {$limit} OFFSET {$offset}";

    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['lead_score'] = crm_computeLeadScore($r);
    }
    unset($r);

    // 'score' is computed in PHP, so we sort in PHP after fetch. (Trade-off:
    // this only sorts the current page, not the global ranking — acceptable
    // for the typical 50-row page since most pages are page 1 anyway.)
    if ($sort === 'score') {
        usort($rows, fn($a, $b) => ($b['lead_score'] ?? 0) <=> ($a['lead_score'] ?? 0));
    }
    return $rows;
}

function crm_countLeads(array $filters = []): int {
    [$where, $params] = crm_buildWhere($filters);
    $stmt = crm_db()->prepare("SELECT COUNT(*) AS n FROM leads {$where}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['n'];
}

function crm_getLead(int $id): ?array {
    $stmt = crm_db()->prepare('SELECT * FROM leads WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function crm_updateLead(int $id, array $patch, ?int $actorUserId = null): bool {
    $allowed = [
        'status', 'owner_user_id', 'notes',
        'monthly_fee', 'ad_budget', 'mgmt_fee_pct', 'expected_close_at', 'temperature',
        'lost_reason', 'lost_reason_note', 'won_reason_note',
        'bant_budget', 'bant_authority', 'bant_need', 'bant_timeline', 'bant_notes',
    ];
    $current = crm_getLead($id);
    if (!$current) return false;

    $bant3 = ['yes','no','unsure'];
    $sets = []; $params = [];
    foreach ($allowed as $k) {
        if (!array_key_exists($k, $patch)) continue;

        $v = $patch[$k];
        if ($k === 'status' && !in_array($v, CRM_LEAD_STATUSES, true)) continue;
        if ($k === 'temperature' && $v !== null && $v !== '' && !in_array($v, ['hot','warm','cold'], true)) continue;
        if ($k === 'lost_reason'   && $v !== null && $v !== '' && !in_array($v, ['price','not_a_fit','competitor','no_response','timing','other'], true)) continue;
        if (in_array($k, ['bant_budget','bant_authority','bant_need'], true) && $v !== null && $v !== '' && !in_array($v, $bant3, true)) continue;
        if ($k === 'bant_timeline' && $v !== null && $v !== '' && !in_array($v, ['asap','30d','90d','later','none'], true)) continue;

        if ($k === 'owner_user_id')   $v = ($v === '' || $v === null) ? null : (int)$v;
        if ($k === 'monthly_fee')     $v = ($v === '' || $v === null) ? null : (float)$v;
        if ($k === 'ad_budget')       $v = ($v === '' || $v === null) ? null : (float)$v;
        if ($k === 'mgmt_fee_pct')    $v = ($v === '' || $v === null) ? null : (float)$v;
        if ($k === 'expected_close_at') $v = ($v === '' || $v === null) ? null : (string)$v;
        if (in_array($k, ['temperature','lost_reason','bant_budget','bant_authority','bant_need','bant_timeline'], true)) {
            $v = ($v === '' ? null : $v);
        }
        if (in_array($k, ['lost_reason_note','won_reason_note','bant_notes'], true)) {
            $v = ($v === '' || $v === null) ? null : (string)$v;
        }

        $sets[] = "{$k} = ?";
        $params[] = $v;
    }
    if (!$sets) return false;
    $params[] = $id;
    $sql = 'UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $stmt = crm_db()->prepare($sql);
    $ok = $stmt->execute($params);

    // Auto-log status change as an activity + auto-create follow-up tasks
    if ($ok && isset($patch['status']) && $patch['status'] !== $current['status']) {
        crm_logActivity(
            $id,
            $actorUserId,
            'status_change',
            null,
            $current['status'] . ' → ' . $patch['status']
        );
        crm_autoTasksOnStageTransition($id, $current['status'], $patch['status'], $actorUserId, $current);

        // Sequence trigger dispatch
        if (file_exists(__DIR__ . '/sequences.php')) {
            require_once __DIR__ . '/sequences.php';
            crm_dispatchSequenceTrigger('status_change_to_' . $patch['status'], $id);
            // Auto-unenroll on terminal states
            if (in_array($patch['status'], ['won','lost'], true)) {
                crm_unenrollLead($id, 'status_' . $patch['status']);
            }
        }

        // Won → promote to active client (no commission event — commissions disabled)
        if ($patch['status'] === 'won') {
            if (file_exists(__DIR__ . '/clients.php')) {
                require_once __DIR__ . '/clients.php';
                crm_promoteLeadToClient($id, $actorUserId);
            }
        }
    }
    return $ok;
}

// Stage-transition rules. Creates the follow-up task that should happen next.
// Hardcoded by design: keeps the sales process consistent without admin UI.
function crm_autoTasksOnStageTransition(int $leadId, string $from, string $to, ?int $actorUserId, array $current): void {
    if (!function_exists('crm_createTask')) {
        require_once __DIR__ . '/tasks.php';
    }
    $owner = $current['owner_user_id'] ?? $actorUserId;
    $name  = trim(($current['first_name'] ?? '') . ' ' . ($current['last_name'] ?? ''));
    $label = $name !== '' ? $name : ($current['business_name'] ?? "Lead #{$leadId}");

    $rules = [
        // from        => [days_until_due, title-template]
        'new->contacted'         => [2, "Follow up with {label}"],
        'contacted->qualified'   => [3, "Send proposal to {label}"],
        'qualified->proposal'    => [3, "Check in with {label} on proposal"],
        'proposal->qualified'    => [2, "Re-pitch {label}"],
        'proposal->won'          => [1, "Onboard {label} — kickoff call"],
        // contacted with no progression → re-engage
        'contacted->contacted'   => [3, "Re-engage {label}"],
    ];
    $key = "{$from}->{$to}";
    if (!isset($rules[$key])) return;

    [$days, $template] = $rules[$key];
    $title = strtr($template, ['{label}' => $label]);
    $dueAt = date('Y-m-d H:i:s', strtotime("+{$days} days 10:00"));

    // De-dup: don't create another auto-task if an open one already exists for this lead
    try {
        $stmt = crm_db()->prepare(
            'SELECT 1 FROM tasks WHERE lead_id = ? AND done_at IS NULL AND title = ? LIMIT 1'
        );
        $stmt->execute([$leadId, $title]);
        if ($stmt->fetch()) return;
    } catch (Throwable $e) { /* fall through */ }

    crm_createTask([
        'lead_id'     => $leadId,
        'assigned_to' => $owner,
        'created_by'  => $actorUserId,
        'title'       => $title,
        'due_at'      => $dueAt,
    ]);
    crm_logActivity($leadId, null, 'system', 'auto_task',
        "Created task '{$title}' (due in {$days}d)");
}

// Computed monthly revenue this lead would represent for Adverton.
function crm_leadMrr(array $row): float {
    $base = (float)($row['monthly_fee'] ?? 0);
    $ad   = (float)($row['ad_budget'] ?? 0);
    $pct  = (float)($row['mgmt_fee_pct'] ?? 0);
    return $base + ($ad * $pct / 100.0);
}

function crm_touchLastContacted(int $leadId): void {
    try {
        $stmt = crm_db()->prepare('UPDATE leads SET last_contacted_at = NOW() WHERE id = ?');
        $stmt->execute([$leadId]);
    } catch (Throwable $e) { /* ignore */ }
}

// Hard-delete a lead: removes attached files from disk, then DELETEs from DB.
// Linked rows (activities, tasks, tags, lead_tags, files, email_sends, sequence_enrollments)
// auto-cascade. Linked clients (clients.lead_id FK) ON DELETE SET NULL — client survives.
function crm_deleteLead(int $id): bool {
    if ($id <= 0) return false;
    try {
        // Wipe physical files first (rows will cascade)
        if (function_exists('crm_filesDir')) {
            $dir = crm_filesDir($id);
            if (is_dir($dir)) {
                foreach ((array) glob($dir . '/*') as $f) @unlink($f);
                @rmdir($dir);
            }
        }
        $stmt = crm_db()->prepare('DELETE FROM leads WHERE id = ?');
        $ok = $stmt->execute([$id]);
        crm_log("lead_delete id={$id} rows=" . $stmt->rowCount());
        return $ok;
    } catch (Throwable $e) {
        error_log('[crm_deleteLead] ' . $e->getMessage());
        return false;
    }
}

function crm_newLeadsSinceLastSeen(int $userId): int {
    try {
        $stmt = crm_db()->prepare(
            'SELECT COUNT(*) AS n FROM leads
             WHERE id > COALESCE((SELECT last_seen_lead_id FROM users WHERE id = ?), 0)'
        );
        $stmt->execute([$userId]);
        return (int) ($stmt->fetch()['n'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

function crm_markLeadsSeen(int $userId): void {
    try {
        $stmt = crm_db()->prepare(
            'UPDATE users SET last_seen_lead_id = (SELECT IFNULL(MAX(id), 0) FROM leads) WHERE id = ?'
        );
        $stmt->execute([$userId]);
    } catch (Throwable $e) { /* ignore */ }
}

// Bulk update for selected lead ids. $action ∈ ['status','owner','tag_add','tag_remove','delete'].
// Returns number of affected leads.
function crm_bulkUpdate(array $ids, string $action, $value, ?int $actorUserId): int {
    $ids = array_values(array_filter(array_map('intval', $ids), fn($i) => $i > 0));
    if (!$ids) return 0;
    $n = 0;
    foreach ($ids as $id) {
        switch ($action) {
            case 'status':
                if (!in_array($value, CRM_LEAD_STATUSES, true)) continue 2;
                if (crm_updateLead($id, ['status' => $value], $actorUserId)) $n++;
                break;
            case 'owner':
                $v = $value === '' ? null : (int)$value;
                if (crm_updateLead($id, ['owner_user_id' => $v], $actorUserId)) $n++;
                break;
            case 'tag_add':
                if (function_exists('crm_addTagToLead') && crm_addTagToLead($id, (string)$value)) $n++;
                break;
            case 'tag_remove':
                if (function_exists('crm_removeTagFromLead') && crm_removeTagFromLead($id, (int)$value)) $n++;
                break;
            case 'delete':
                if (crm_deleteLead($id)) $n++;
                break;
        }
    }
    return $n;
}

function crm_listUsers(): array {
    $stmt = crm_db()->query('SELECT id, username, display_name FROM users ORDER BY id ASC');
    return $stmt->fetchAll();
}

function crm_buildWhere(array $f): array {
    $w = []; $p = [];
    if (!empty($f['source']) && in_array($f['source'], CRM_LEAD_SOURCES, true)) {
        $w[] = 'leads.source = ?'; $p[] = $f['source'];
    }
    if (!empty($f['status']) && in_array($f['status'], CRM_LEAD_STATUSES, true)) {
        $w[] = 'leads.status = ?'; $p[] = $f['status'];
    }
    if (!empty($f['temperature']) && in_array($f['temperature'], ['hot','warm','cold'], true)) {
        $w[] = 'leads.temperature = ?'; $p[] = $f['temperature'];
    }
    if (!empty($f['owner'])) {
        $w[] = 'leads.owner_user_id = ?'; $p[] = (int)$f['owner'];
    }
    if (!empty($f['stale_days']) && (int)$f['stale_days'] > 0) {
        $w[] = 'leads.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
        $w[] = "leads.status NOT IN ('won','lost')";
        $p[] = (int)$f['stale_days'];
    }
    if (!empty($f['tag'])) {
        // Filter by tag id (resolved upstream from tag name)
        $w[] = 'leads.id IN (SELECT lead_id FROM lead_tags WHERE tag_id = ?)';
        $p[] = (int)$f['tag'];
    }
    if (!empty($f['q'])) {
        $like = '%' . addcslashes((string)$f['q'], '%_\\') . '%';
        $w[] = '(leads.email LIKE ? OR leads.business_name LIKE ? OR leads.first_name LIKE ? OR leads.last_name LIKE ? OR leads.phone LIKE ?)';
        array_push($p, $like, $like, $like, $like, $like);
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

// Streams CSV to STDOUT. Caller must set headers.
function crm_exportCsv(array $filters): void {
    [$where, $params] = crm_buildWhere($filters);
    $sql = "SELECT id, created_at, source, source_page, status, temperature,
                   first_name, last_name, email, phone, business_name, trade,
                   city_state, website, audit_score, gbp_url, revenue, message, notes,
                   monthly_fee, ad_budget, mgmt_fee_pct, expected_close_at,
                   utm_source, utm_medium, utm_campaign, ip
            FROM leads {$where}
            ORDER BY created_at DESC";
    $stmt = crm_db()->prepare($sql);
    $stmt->execute($params);

    $out = fopen('php://output', 'w');
    $first = true;
    while ($row = $stmt->fetch()) {
        if ($first) {
            fputcsv($out, array_keys($row));
            $first = false;
        }
        fputcsv($out, array_values($row));
    }
    if ($first) fputcsv($out, ['id','created_at','source','email']); // empty result still gets headers
    fclose($out);
}
