<?php
// Adverton Care — client (contractor) dashboard. Passwordless: a per-client
// magic-link token (?t=) sets a 90-day cookie. One simple, pretty place to see
// calls / missed-recovered / reviews and request reviews with one tap.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/reviews.php';

$clientId = care_currentClientId();
$token    = (string)($_GET['t'] ?? $_COOKIE['care_sess'] ?? '');

function care_h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$csrf = $token ? substr(hash_hmac('sha256', 'care-csrf', $token), 0, 24) : '';

if (!$clientId) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">'
       . '<title>Care</title><style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#f1f5f4;display:grid;place-items:center;min-height:100vh;margin:0;color:#0f2e2a;text-align:center;padding:24px}div{max-width:380px}h1{color:#0d9488}</style>'
       . '<div><h1>Care</h1><p>This link isn\'t valid or has expired. Please use the link your Adverton team sent you, or get in touch and we\'ll send a new one.</p></div>';
    exit;
}

$db  = care_db();
$biz = care_clientName($clientId);

// ── POST actions (CSRF-guarded; cookie is SameSite=Lax) ──────────────────
$flash = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ok = $csrf !== '' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''));
    if ($ok && ($_POST['action'] ?? '') === 'request_review') {
        $r = care_queueReview($clientId, (string)($_POST['phone'] ?? ''), trim((string)($_POST['name'] ?? '')) ?: null, 'call_tap');
        $flash = $r['ok'] ? 'Review request queued — we\'ll text them shortly. ✓'
                          : ('Couldn\'t queue: ' . ($r['error'] === 'duplicate' ? 'already requested recently' : ($r['error'] === 'opted_out' ? 'they opted out' : 'invalid number')));
    } elseif ($ok && ($_POST['action'] ?? '') === 'add_jobs') {
        $n = 0; $skip = 0;
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['jobs'] ?? '')) as $line) {
            $line = trim($line); if ($line === '') continue;
            // "Name, 555-123-4567"  or  "555-123-4567"
            if (preg_match('/^(.*?)[,;\t ]+(\+?[\d().\- ]{7,})$/', $line, $m) && preg_match('/\d{7,}/', $m[2])) {
                $name = trim($m[1]) ?: null; $phone = $m[2];
            } elseif (preg_match('/\d{7,}/', $line)) { $name = null; $phone = $line; }
            else { $skip++; continue; }
            $res = care_queueReview($clientId, $phone, $name, 'csv');
            $res['ok'] ? $n++ : $skip++;
        }
        $flash = "Queued {$n} review request(s)" . ($skip ? ", skipped {$skip}." : '.');
    }
    // Post/Redirect/Get — drop the token from the URL (cookie carries it).
    header('Location: ' . CARE_BASE_URL . '/?ok=' . rawurlencode($flash));
    exit;
}
if (isset($_GET['ok'])) $flash = (string)$_GET['ok'];

// ── Data ─────────────────────────────────────────────────────────────────
$monthStart = date('Y-m-01 00:00:00');
function care_scalar(PDO $db, string $sql, array $p) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }

$callsMonth   = care_scalar($db, 'SELECT COUNT(*) FROM care_calls WHERE client_id=? AND created_at>=?', [$clientId, $monthStart]);
$missedRecov  = care_scalar($db, 'SELECT COUNT(*) FROM care_calls WHERE client_id=? AND disposition="missed" AND textback_sent=1 AND created_at>=?', [$clientId, $monthStart]);
$reviewsMonth = care_scalar($db, 'SELECT COUNT(*) FROM care_review_requests WHERE client_id=? AND created_at>=?', [$clientId, $monthStart]);

$calls = $db->prepare('SELECT caller, disposition, duration, created_at FROM care_calls WHERE client_id=? ORDER BY id DESC LIMIT 15');
$calls->execute([$clientId]); $calls = $calls->fetchAll();

$reviews = $db->prepare('SELECT customer_phone, customer_name, status, created_at FROM care_review_requests WHERE client_id=? ORDER BY id DESC LIMIT 12');
$reviews->execute([$clientId]); $reviews = $reviews->fetchAll();

$pretty = function ($e164) { $d = preg_replace('/\D/', '', (string)$e164); if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1); return strlen($d) === 10 ? '(' . substr($d,0,3) . ') ' . substr($d,3,3) . '-' . substr($d,6) : (string)$e164; };
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= care_h2($biz) ?> — Care</title>
<style>
  :root{--teal:#0d9488;--teal-d:#0f766e;--ink:#0f2e2a;--muted:#5b7771;--bg:#f1f5f4;--line:#e2ebe9;--ok:#16a34a;--warn:#d97706}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink);font-size:16px;-webkit-font-smoothing:antialiased;padding-bottom:40px}
  .wrap{max-width:620px;margin:0 auto;padding:0 16px}
  header{background:linear-gradient(135deg,var(--teal) 0%,var(--teal-d) 100%);color:#fff;padding:22px 0 26px}
  header .wrap{display:flex;align-items:center;justify-content:space-between}
  .brand{font-weight:800;font-size:19px;letter-spacing:-.01em}
  .brand small{font-weight:600;opacity:.8;font-size:12px;display:block;margin-top:1px}
  .biz{font-size:13px;background:rgba(255,255,255,.18);padding:5px 12px;border-radius:999px;font-weight:600}
  .flash{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:12px;padding:12px 16px;margin:16px 0;font-weight:600;font-size:15px}
  .stats{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin:18px 0 6px}
  .stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 12px;text-align:center}
  .stat .n{font-size:28px;font-weight:900;color:var(--teal-d);line-height:1}
  .stat .l{font-size:12px;color:var(--muted);margin-top:6px;font-weight:600}
  h2{font-size:15px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:26px 0 10px;font-weight:800}
  .card{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
  .row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;border-bottom:1px solid var(--line)}
  .row:last-child{border-bottom:none}
  .row .who{font-weight:700;font-size:15px}
  .row .meta{font-size:12.5px;color:var(--muted);margin-top:2px}
  .badge{font-size:11px;font-weight:800;padding:3px 9px;border-radius:999px;text-transform:uppercase;letter-spacing:.03em}
  .b-missed{background:#fef3c7;color:#92400e}.b-answered{background:#dcfce7;color:#166534}.b-other{background:#eef2f1;color:#5b7771}
  .b-sent{background:#dbeafe;color:#1e40af}.b-queued{background:#fef9c3;color:#854d0e}.b-reminded{background:#e0e7ff;color:#3730a3}.b-stopped{background:#fee2e2;color:#991b1b}
  .ask{background:var(--teal);color:#fff;border:none;font-weight:800;font-size:13px;padding:9px 14px;border-radius:10px;cursor:pointer;white-space:nowrap;min-height:40px}
  .ask:active{transform:translateY(1px)}
  .done{font-size:12px;color:var(--ok);font-weight:700}
  .addbox{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px}
  .addbox textarea{width:100%;border:1px solid var(--line);border-radius:10px;padding:12px;font:inherit;font-size:16px;min-height:84px;resize:vertical}
  .addbox button{margin-top:10px;background:var(--teal);color:#fff;border:none;font-weight:800;padding:12px 20px;border-radius:10px;font-size:15px;cursor:pointer;min-height:46px}
  .hint{font-size:12.5px;color:var(--muted);margin-top:6px}
  .empty{padding:22px 16px;color:var(--muted);text-align:center;font-size:14px}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:28px}
</style>
</head>
<body>
<header><div class="wrap">
  <div class="brand">Care<small>by Adverton</small></div>
  <div class="biz"><?= care_h2($biz) ?></div>
</div></header>

<div class="wrap">
  <?php if ($flash): ?><div class="flash"><?= care_h2($flash) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="n"><?= $callsMonth ?></div><div class="l">Calls this month</div></div>
    <div class="stat"><div class="n"><?= $missedRecov ?></div><div class="l">Missed → texted back</div></div>
    <div class="stat"><div class="n"><?= $reviewsMonth ?></div><div class="l">Reviews requested</div></div>
  </div>

  <h2>Recent calls</h2>
  <div class="card">
    <?php if (!$calls): ?><div class="empty">No calls yet. They'll show up here as they come in.</div><?php endif; ?>
    <?php foreach ($calls as $c): $cls = $c['disposition']==='missed'?'b-missed':($c['disposition']==='answered'?'b-answered':'b-other'); ?>
    <div class="row">
      <div>
        <div class="who"><?= care_h2($pretty($c['caller'])) ?></div>
        <div class="meta"><span class="badge <?= $cls ?>"><?= care_h2($c['disposition']) ?></span> &nbsp;<?= care_h2(date('M j, g:i a', strtotime($c['created_at']))) ?></div>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="request_review">
        <input type="hidden" name="phone" value="<?= care_h2($c['caller']) ?>">
        <button class="ask" type="submit">Ask for review</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>

  <h2>Add finished jobs</h2>
  <div class="addbox">
    <form method="post" style="margin:0">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="add_jobs">
      <textarea name="jobs" placeholder="One per line:&#10;John Smith, 555-123-4567&#10;555-987-6543"></textarea>
      <div class="hint">Paste the customers you finished jobs for — name optional. We'll text each a review request.</div>
      <button type="submit">Send review requests</button>
    </form>
  </div>

  <h2>Reviews requested</h2>
  <div class="card">
    <?php if (!$reviews): ?><div class="empty">No review requests yet.</div><?php endif; ?>
    <?php foreach ($reviews as $r): $b='b-'.($r['status']==='done'?'answered':$r['status']); ?>
    <div class="row">
      <div>
        <div class="who"><?= care_h2($r['customer_name'] ?: $pretty($r['customer_phone'])) ?></div>
        <div class="meta"><?= care_h2(date('M j', strtotime($r['created_at']))) ?></div>
      </div>
      <span class="badge <?= preg_replace('/[^a-z\-]/','',$b) ?>"><?= care_h2($r['status']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <footer>Adverton Care · we run this for you</footer>
</div>
</body></html>
