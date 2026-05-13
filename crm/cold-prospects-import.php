<?php
// Bulk import of cold prospects from CSV/TSV (Outscraper exports / online
// lists). Pipeline: parse → normalize phone to E.164 → dedup against
// cold_prospects → INSERT pending → auto-call DNCScrub API → UPDATE status.
//
// Blocked numbers stay in the table (audit trail + dedup on re-import +
// avoid re-paying scrub). The VA's calling view filters them out.
//
// Phone is REQUIRED. Rows without a parseable NANP number are rejected.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/phone_normalize.php';
require_once __DIR__ . '/lib/dnc_scrub.php';

$user = crm_requireRole(['founder', 'sales']);

const COLD_SOURCES = ['outscraper', 'manual_csv', 'online', 'other'];

$ALIASES = [
    'phone'         => ['phone','phonenumber','phone_number','mobile','mobilenumber','cell','cellphone','tel','telephone'],
    'business_name' => ['business','businessname','business_name','company','companyname','company_name','organization','organisation','org','name'],
    'contact_name'  => ['contactname','contact_name','contact','owner','ownername','owner_name','firstname','first_name'],
    'email'         => ['email','e-mail','emailaddress','email_address'],
    'trade'         => ['trade','industry','category','vertical','niche','type'],
    'city'          => ['city','town'],
    'state'         => ['state','region','province'],
    'website'       => ['website','url','domain','site','homepage'],
    'gbp_url'       => ['gbp','gbp_url','google_business','googlebusiness','gmb','gmb_url','maps','google_maps','mapsurl'],
    'notes'         => ['notes','note','comment','comments','description','message'],
];

function coldNormalizeHeader(string $h): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($h)));
}

function coldMapHeaders(array $headers, array $aliases): array {
    $map = [];
    foreach ($headers as $idx => $raw) {
        $norm = coldNormalizeHeader((string)$raw);
        if ($norm === '') continue;
        foreach ($aliases as $canonical => $accepted) {
            $acceptedNorm = array_map('coldNormalizeHeader', $accepted);
            if (in_array($norm, $acceptedNorm, true)) {
                $map[$idx] = $canonical;
                break;
            }
        }
    }
    return $map;
}

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!crm_csrfCheck($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Bad CSRF token. Refresh the page and try again.');
    }
    if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $report = ['error' => 'No file uploaded or upload failed.'];
    } else {
        $tmpPath  = $_FILES['csv']['tmp_name'];
        $size     = (int)($_FILES['csv']['size'] ?? 0);
        $maxBytes = 10 * 1024 * 1024;
        if ($size > $maxBytes) {
            $report = ['error' => 'File too large (max 10 MB).'];
        } else {
            $source = (string)($_POST['source'] ?? 'outscraper');
            if (!in_array($source, COLD_SOURCES, true)) $source = 'outscraper';

            $batchId = strtoupper(bin2hex(random_bytes(4)));

            $fh = fopen($tmpPath, 'r');
            if (!$fh) {
                $report = ['error' => 'Could not open uploaded file.'];
            } else {
                $first = fgets($fh);
                rewind($fh);
                $delim = (substr_count((string)$first, "\t") > substr_count((string)$first, ',')) ? "\t" : ',';

                $headers = fgetcsv($fh, 0, $delim);
                if (!$headers) {
                    $report = ['error' => 'Could not read header row.'];
                } else {
                    $colMap = coldMapHeaders($headers, $ALIASES);
                    if (!$colMap || !in_array('phone', $colMap, true)) {
                        $report = ['error' => 'No phone column detected. Headers seen: ' . implode(', ', $headers)];
                    } else {
                        // First pass: parse rows in memory, normalize phones,
                        // bucket as new vs duplicate vs rejected.
                        $rows        = [];
                        $duplicates  = 0;
                        $rejected    = [];
                        $rowNum      = 1;
                        $seenInBatch = [];

                        $pdo = crm_db();
                        $dupStmt = $pdo->prepare('SELECT id, dnc_status FROM cold_prospects WHERE phone = ? LIMIT 1');

                        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                            $rowNum++;
                            $data = [];
                            foreach ($colMap as $idx => $canonical) {
                                $val = trim((string)($row[$idx] ?? ''));
                                if ($val !== '') $data[$canonical] = $val;
                            }
                            $rawPhone = (string)($data['phone'] ?? '');
                            $e164     = crm_phoneToE164($rawPhone);
                            if ($e164 === null) {
                                $rejected[] = "Row {$rowNum}: phone " . ($rawPhone !== '' ? "'{$rawPhone}'" : '(empty)') . " not a valid US/CA number";
                                if (count($rejected) > 30) { $rejected[] = '... (truncated)'; break; }
                                continue;
                            }
                            if (isset($seenInBatch[$e164])) {
                                $duplicates++;
                                continue;
                            }
                            $seenInBatch[$e164] = true;
                            $dupStmt->execute([$e164]);
                            $existing = $dupStmt->fetch();
                            if ($existing) {
                                $duplicates++;
                                continue;
                            }
                            $data['phone'] = $e164;
                            $rows[] = $data;
                        }
                        fclose($fh);

                        // Second pass: bulk INSERT pending, then call DNCScrub
                        // for the same set in one shot, then UPDATE each.
                        $insertedIds = [];
                        if (!empty($rows)) {
                            $insertStmt = $pdo->prepare(
                                'INSERT INTO cold_prospects
                                  (phone, business_name, contact_name, email, trade,
                                   city, state, website, gbp_url, notes,
                                   source, imported_batch_id, imported_by, dnc_status)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
                            );
                            foreach ($rows as $r) {
                                try {
                                    $insertStmt->execute([
                                        $r['phone'],
                                        mb_substr((string)($r['business_name'] ?? ''), 0, 160) ?: null,
                                        mb_substr((string)($r['contact_name']  ?? ''), 0, 160) ?: null,
                                        mb_substr((string)($r['email']         ?? ''), 0, 160) ?: null,
                                        mb_substr((string)($r['trade']         ?? ''), 0, 80)  ?: null,
                                        mb_substr((string)($r['city']          ?? ''), 0, 80)  ?: null,
                                        mb_substr((string)($r['state']         ?? ''), 0, 40)  ?: null,
                                        mb_substr((string)($r['website']       ?? ''), 0, 255) ?: null,
                                        mb_substr((string)($r['gbp_url']       ?? ''), 0, 500) ?: null,
                                        (string)($r['notes'] ?? '') ?: null,
                                        $source,
                                        $batchId,
                                        (int)$user['id'],
                                    ]);
                                    $insertedIds[$r['phone']] = (int)$pdo->lastInsertId();
                                } catch (Throwable $e) {
                                    // Race condition (someone uploaded same phone concurrently)
                                    // or unexpected schema error. Skip silently — they'll just
                                    // count as duplicates.
                                    $duplicates++;
                                }
                            }
                        }

                        // Auto-scrub all freshly-inserted phones in one batch.
                        $statusCounts = [
                            'clean' => 0,
                            'blocked_federal' => 0,
                            'blocked_state' => 0,
                            'blocked_wireless' => 0,
                            'blocked_litigator' => 0,
                            'scrub_error' => 0,
                        ];
                        if (!empty($insertedIds)) {
                            $scrubResults = crm_dncScrubBatch(array_keys($insertedIds));
                            $updStmt = $pdo->prepare(
                                'UPDATE cold_prospects
                                    SET dnc_status      = ?,
                                        dnc_scrubbed_at = NOW(),
                                        dnc_meta_json   = ?
                                  WHERE id = ?'
                            );
                            foreach ($insertedIds as $phone => $id) {
                                $res = $scrubResults[$phone] ?? ['status' => 'scrub_error', 'meta' => null];
                                $status = $res['status'];
                                if (!array_key_exists($status, $statusCounts)) $status = 'scrub_error';
                                $statusCounts[$status]++;
                                try {
                                    $updStmt->execute([
                                        $status,
                                        $res['meta'] ? json_encode($res['meta'], JSON_UNESCAPED_SLASHES) : null,
                                        $id,
                                    ]);
                                } catch (Throwable $e) {
                                    error_log('[cold_import_update] ' . $e->getMessage());
                                }
                            }
                        }

                        $report = [
                            'batch_id'      => $batchId,
                            'imported'      => count($insertedIds),
                            'duplicates'    => $duplicates,
                            'rejected'      => $rejected,
                            'mapped'        => array_values(array_unique(array_values($colMap))),
                            'status_counts' => $statusCounts,
                            'scrub_live'    => crm_dncIsLive(),
                        ];
                    }
                }
            }
        }
    }
}

crm_renderHead('Import cold prospects');
crm_renderHeader($user, 'cold');
?>
<style>
  main{max-width:920px}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;margin-bottom:14px}
  h1{margin:0 0 6px;font-size:22px;letter-spacing:-0.01em}
  .lede{color:#6b6877;font-size:14px;margin:0 0 22px;line-height:1.5}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=file],select{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;box-sizing:border-box}
  button.primary{margin-top:18px;background:#6d28d9;color:#fff;border:0;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .ok{background:#dcfce7;color:#166534;padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
  .warn{background:#fef3c7;color:#92400e;padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
  .err{background:#fee2e2;color:#991b1b;padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
  .stat{display:inline-block;background:#f7f6fb;border:1px solid #ece9f3;border-radius:8px;padding:8px 14px;margin:4px 8px 4px 0;font-size:13px;font-weight:600}
  .stat.bad{background:#fee2e2;border-color:#fecaca;color:#991b1b}
  .stat.good{background:#dcfce7;border-color:#bbf7d0;color:#166534}
  ul.errs{margin:6px 0 0 18px;padding:0;font-size:13px;color:#991b1b}
  ul.errs li{margin:2px 0}
  code{background:#f3f1f8;padding:2px 6px;border-radius:4px;font-size:12px}
  .stub-banner{background:#fef3c7;border:1px solid #fbbf24;color:#78350f;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:14px}
</style>
<main>
  <h1>Import cold prospects</h1>
  <p class="lede">Upload an Outscraper/online CSV. Phones are auto-normalized to E.164 and run through the National DNC + state lists + wireless flag + litigator list. Blocked numbers stay in the database for audit + dedup, but are hidden from the VA's calling view.</p>

  <?php if (!crm_dncIsLive()): ?>
    <div class="stub-banner">
      <strong>⚠️ DNC scrub is in STUB MODE.</strong> No <code>DNCSCRUB_API_KEY</code> configured —
      every number will be marked <code>clean</code> until you set up the vendor at
      <a href="/crm/integrations.php" style="color:#78350f;text-decoration:underline">Integrations → DNC scrub</a>.
      Do NOT start cold-calling against numbers imported in stub mode.
    </div>
  <?php endif; ?>

  <?php if ($report): ?>
    <?php if (!empty($report['error'])): ?>
      <div class="err"><strong>Error:</strong> <?= crm_h($report['error']) ?></div>
    <?php else:
      $sc = $report['status_counts'];
      $blocked = $sc['blocked_federal'] + $sc['blocked_state'] + $sc['blocked_wireless'] + $sc['blocked_litigator'];
    ?>
      <div class="ok">
        <div style="font-weight:700;margin-bottom:8px">
          Import #<?= crm_h($report['batch_id']) ?> complete
          <?php if (!$report['scrub_live']): ?><span style="background:#fbbf24;color:#78350f;padding:2px 8px;border-radius:999px;font-size:11px;margin-left:6px">STUB SCRUB</span><?php endif; ?>
        </div>
        <span class="stat"><?= (int)$report['imported'] ?> new rows imported</span>
        <span class="stat"><?= (int)$report['duplicates'] ?> duplicates skipped</span>
        <span class="stat <?= count($report['rejected']) ? 'bad' : '' ?>"><?= count($report['rejected']) ?> rejected (bad phone)</span>
        <div style="margin-top:12px">
          <span class="stat good"><?= $sc['clean'] ?> callable (clean)</span>
          <span class="stat bad"><?= $blocked ?> blocked DNC</span>
          <?php if ($sc['scrub_error']): ?><span class="stat bad"><?= $sc['scrub_error'] ?> scrub errors</span><?php endif; ?>
        </div>
        <?php if ($blocked > 0): ?>
          <div style="margin-top:8px;font-size:13px;color:#4a4856">
            Breakdown: federal <?= $sc['blocked_federal'] ?> · state <?= $sc['blocked_state'] ?> · wireless <?= $sc['blocked_wireless'] ?> · litigator <?= $sc['blocked_litigator'] ?>
          </div>
        <?php endif; ?>
        <?php if ($sc['clean'] > 0): ?>
          <div style="margin-top:12px;font-size:14px">
            <a href="/crm/cold-calling.php?batch=<?= urlencode($report['batch_id']) ?>" style="color:#166534;font-weight:600">→ Start calling the <?= $sc['clean'] ?> callable prospects</a>
          </div>
        <?php endif; ?>
        <div style="margin-top:6px;font-size:12px;color:#6b6877">
          Mapped columns: <?= $report['mapped'] ? '<code>' . implode('</code> <code>', array_map('crm_h', $report['mapped'])) . '</code>' : '<em>none</em>' ?>
        </div>
        <?php if (!empty($report['rejected'])): ?>
          <details style="margin-top:10px"><summary style="cursor:pointer">Show rejected rows</summary>
            <ul class="errs"><?php foreach ($report['rejected'] as $e): ?><li><?= crm_h($e) ?></li><?php endforeach; ?></ul>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form class="card" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <label>CSV or TSV file (max 10 MB) — must include a <code>phone</code> column</label>
    <input type="file" name="csv" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values" required>

    <label>Source (tagged on every imported prospect)</label>
    <select name="source">
      <?php foreach (COLD_SOURCES as $s): ?>
        <option value="<?= crm_h($s) ?>" <?= $s==='outscraper'?'selected':'' ?>><?= crm_h($s) ?></option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="primary">Import &amp; auto-scrub</button>
  </form>

  <div class="card" style="background:#f9f8fc">
    <strong style="font-size:13px">Recognized headers (case-insensitive, hyphens/underscores ignored):</strong>
    <ul style="margin:8px 0 0 18px;font-size:13px;color:#4a4856">
      <?php foreach ($ALIASES as $canonical => $accepted): ?>
        <li><code><?= crm_h($canonical) ?></code><?= $canonical==='phone'?' <strong style="color:#dc2626">(required)</strong>':'' ?> → <?= crm_h(implode(', ', $accepted)) ?></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin:14px 0 0;font-size:12px;color:#6b6877">
      Phones must be valid US/CA (NANP) numbers. International numbers, vanity strings ("555-CALL-NOW"), and rows with empty phones are rejected.
    </p>
  </div>
</main>
</body></html>
