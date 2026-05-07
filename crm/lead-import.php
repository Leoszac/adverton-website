<?php
// Bulk lead import from CSV / TSV. Auto-detects column headers (flexible
// aliases — `email` or `email_address`, `phone` or `mobile`, etc.). Each
// row goes through crm_insertLead() so existing dedupe-by-email/phone
// applies — re-running the same file is a no-op.
//
// Founder + sales can use this. Unauthenticated users get the login redirect.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/leads.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder','sales']);

$report = null;

// Header alias map. Keys are canonical column names; values are accepted
// header strings (lowercased, spaces/underscores normalized).
$ALIASES = [
    'email'         => ['email','e-mail','emailaddress','email_address','correo'],
    'phone'         => ['phone','phonenumber','phone_number','mobile','mobilenumber','cell','cellphone','tel','telephone'],
    'first_name'    => ['firstname','first','first_name','fname','givenname','given_name'],
    'last_name'     => ['lastname','last','last_name','lname','surname','familyname','family_name'],
    'name'          => ['name','fullname','full_name','contactname','contact_name'],
    'business_name' => ['business','businessname','business_name','company','companyname','company_name','organization','organisation','org'],
    'trade'         => ['trade','industry','category','vertical','niche'],
    'city_state'    => ['city_state','citystate','location','city','address'],
    'website'       => ['website','url','domain','site','homepage'],
    'gbp_url'       => ['gbp','gbp_url','google_business','googlebusiness','gmb','gmb_url'],
    'message'       => ['message','notes','note','comment','comments','description'],
];

function normalizeHeader(string $h): string {
    return preg_replace('/[^a-z0-9]+/', '', strtolower(trim($h)));
}

function mapHeaders(array $headers, array $aliases): array {
    $map = [];
    foreach ($headers as $idx => $raw) {
        $norm = normalizeHeader((string)$raw);
        if ($norm === '') continue;
        foreach ($aliases as $canonical => $accepted) {
            $acceptedNorm = array_map('normalizeHeader', $accepted);
            if (in_array($norm, $acceptedNorm, true)) {
                $map[$idx] = $canonical;
                break;
            }
        }
    }
    return $map;
}

function splitName(string $full): array {
    $full = trim($full);
    if ($full === '') return ['', ''];
    $parts = preg_split('/\s+/', $full, 2);
    return [$parts[0] ?? '', $parts[1] ?? ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!crm_csrfCheck($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Bad CSRF token. Refresh the page and try again.');
    }
    if (empty($_FILES['csv']) || ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $report = ['error' => 'No file uploaded or upload failed.'];
    } else {
        $tmpPath = $_FILES['csv']['tmp_name'];
        $size    = (int)($_FILES['csv']['size'] ?? 0);
        $maxBytes = 5 * 1024 * 1024; // 5 MB cap
        if ($size > $maxBytes) {
            $report = ['error' => 'File too large (max 5 MB).'];
        } else {
            // Default to 'manual' (safe — present since schema-v5). 'csv_import',
            // 'referral', 'affiliate' only work after schema-v10.sql is applied.
            $source = (string)($_POST['source'] ?? 'manual');
            if (!in_array($source, CRM_LEAD_SOURCES, true)) $source = 'manual';

            $fh = fopen($tmpPath, 'r');
            if (!$fh) {
                $report = ['error' => 'Could not open uploaded file.'];
            } else {
                // Detect delimiter from first line
                $first = fgets($fh);
                rewind($fh);
                $delim = (substr_count((string)$first, "\t") > substr_count((string)$first, ',')) ? "\t" : ',';

                $headers = fgetcsv($fh, 0, $delim);
                if (!$headers) {
                    $report = ['error' => 'Could not read header row.'];
                } else {
                    $colMap = mapHeaders($headers, $ALIASES);
                    if (!$colMap) {
                        $report = ['error' => 'No recognizable columns. Headers seen: ' . implode(', ', $headers)];
                    } else {
                        $imported = 0; $deduped = 0; $errors = []; $rowNum = 1;
                        $existingIds = [];
                        while (($row = fgetcsv($fh, 0, $delim)) !== false) {
                            $rowNum++;
                            $data = ['source' => $source];
                            foreach ($colMap as $idx => $canonical) {
                                $val = trim((string)($row[$idx] ?? ''));
                                if ($val === '') continue;
                                if ($canonical === 'name') {
                                    [$fn, $ln] = splitName($val);
                                    if (empty($data['first_name']) && $fn !== '') $data['first_name'] = $fn;
                                    if (empty($data['last_name'])  && $ln !== '') $data['last_name']  = $ln;
                                } else {
                                    $data[$canonical] = $val;
                                }
                            }
                            // Minimum: email OR phone OR business_name
                            if (empty($data['email']) && empty($data['phone']) && empty($data['business_name'])) {
                                $errors[] = "Row {$rowNum}: needs at least email, phone, or business name";
                                continue;
                            }
                            // Detect dedupe via the same path crm_insertLead uses
                            $emailLc   = strtolower((string)($data['email'] ?? ''));
                            $phoneNorm = preg_replace('/\D/', '', (string)($data['phone'] ?? ''));
                            $beforeDup = ($emailLc || $phoneNorm) ? crm_findDuplicateLead($emailLc, $phoneNorm) : null;

                            $id = crm_insertLead($data);
                            if (!$id) {
                                $errors[] = "Row {$rowNum}: insert failed (likely invalid source/data)";
                                continue;
                            }
                            if ($beforeDup) { $deduped++; }
                            else            { $imported++; $existingIds[] = (int)$id; }
                            if (count($errors) > 50) { $errors[] = "… (truncated; first 50 errors shown)"; break; }
                        }
                        fclose($fh);
                        $report = [
                            'imported'  => $imported,
                            'deduped'   => $deduped,
                            'errors'    => $errors,
                            'mapped'    => array_values(array_unique(array_values($colMap))),
                            'newIds'    => $existingIds,
                        ];
                    }
                }
            }
        }
    }
}

crm_renderHead('Import leads');
crm_renderHeader($user, '');
?>
<style>
  main{max-width:880px}
  .card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:22px;margin-bottom:14px}
  h1{margin:0 0 10px;font-size:22px;letter-spacing:-0.01em}
  .lede{color:#6b6877;font-size:14px;margin:0 0 22px}
  label{display:block;font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin:14px 0 6px}
  input[type=file],select{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:14px;box-sizing:border-box}
  button.primary{margin-top:18px;background:#6d28d9;color:#fff;border:0;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  .ok{background:#dcfce7;color:#166534;padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
  .warn{background:#fef3c7;color:#92400e;padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
  .err{background:#fee2e2;color:#991b1b;padding:14px 16px;border-radius:8px;font-size:14px;margin-bottom:12px}
  .stat{display:inline-block;background:#f7f6fb;border:1px solid #ece9f3;border-radius:8px;padding:8px 14px;margin-right:8px;font-size:13px;font-weight:600}
  ul.errs{margin:6px 0 0 18px;padding:0;font-size:13px;color:#991b1b}
  ul.errs li{margin:2px 0}
  code{background:#f3f1f8;padding:2px 6px;border-radius:4px;font-size:12px}
</style>
<main>
  <h1>Import leads from CSV</h1>
  <p class="lede">Upload a CSV (or TSV). Headers are auto-detected — column names like <code>email</code>, <code>phone</code>, <code>name</code>, <code>business</code>, <code>trade</code>, <code>city</code>, <code>website</code> all work. Re-running the same file is safe; existing leads are deduplicated by email/phone.</p>

  <?php if ($report): ?>
    <?php if (!empty($report['error'])): ?>
      <div class="err"><strong>Error:</strong> <?= crm_h($report['error']) ?></div>
    <?php else: ?>
      <div class="<?= $report['imported'] > 0 ? 'ok' : 'warn' ?>">
        <span class="stat"><?= (int)$report['imported'] ?> imported</span>
        <span class="stat"><?= (int)$report['deduped'] ?> duplicates skipped</span>
        <span class="stat"><?= count($report['errors']) ?> error<?= count($report['errors'])===1?'':'s' ?></span>
        <div style="margin-top:10px;font-size:13px">
          Mapped columns: <?= $report['mapped'] ? '<code>' . implode('</code> <code>', array_map('crm_h', $report['mapped'])) . '</code>' : '<em>none recognized</em>' ?>
        </div>
        <?php if (!empty($report['newIds'])): ?>
          <div style="margin-top:8px;font-size:13px">
            <a href="/crm/?since=last" style="color:#166534;font-weight:600">→ See the <?= count($report['newIds']) ?> new lead<?= count($report['newIds'])===1?'':'s' ?></a>
          </div>
        <?php endif; ?>
        <?php if (!empty($report['errors'])): ?>
          <details style="margin-top:10px"><summary style="cursor:pointer">Show errors</summary>
            <ul class="errs"><?php foreach ($report['errors'] as $e): ?><li><?= crm_h($e) ?></li><?php endforeach; ?></ul>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <form class="card" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <label>CSV or TSV file (max 5 MB)</label>
    <input type="file" name="csv" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values" required>

    <label>Source (tagged on every imported lead)</label>
    <select name="source">
      <?php foreach (CRM_LEAD_SOURCES as $s): ?>
        <option value="<?= crm_h($s) ?>" <?= $s==='manual'?'selected':'' ?>><?= crm_h($s) ?></option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="primary">Import</button>
  </form>

  <div class="card" style="background:#f9f8fc">
    <strong style="font-size:13px">Recognized headers (case-insensitive, hyphens/underscores ignored):</strong>
    <ul style="margin:8px 0 0 18px;font-size:13px;color:#4a4856">
      <?php foreach ($ALIASES as $canonical => $accepted): ?>
        <li><code><?= crm_h($canonical) ?></code> → <?= crm_h(implode(', ', $accepted)) ?></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin:14px 0 0;font-size:12px;color:#6b6877">
      A row needs at least one of <code>email</code>, <code>phone</code>, or <code>business_name</code> to be importable.
      <code>name</code> auto-splits into first/last.
    </p>
  </div>
</main>
</body></html>
