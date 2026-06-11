<?php
// Adverton Care — contractor dashboard. Passwordless magic-link (?t= sets a
// 90-day cookie). Designed to be dead simple: see it's working, and ask a
// customer for a review in one tap.

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
       . '<title>Care</title><style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#0f766e;display:grid;place-items:center;min-height:100vh;margin:0;color:#fff;text-align:center;padding:24px}div{max-width:340px}h1{font-size:30px;margin:0 0 8px}p{opacity:.85;line-height:1.5}</style>'
       . '<div><h1>Care</h1><p>This link isn’t valid anymore. Tap the link your Adverton team sent you, or text us and we’ll send a fresh one.</p></div>';
    exit;
}

$db  = care_db();
$biz = care_clientName($clientId);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ok = $csrf !== '' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if ($ok && ($action === 'request_review' || $action === 'add_one')) {
        $r = care_queueReview($clientId, (string)($_POST['phone'] ?? ''), trim((string)($_POST['name'] ?? '')) ?: null, $action === 'add_one' ? 'manual' : 'call_tap');
        $flash = $r['ok'] ? "On it — we’ll text them your review link. ⭐"
                          : ($r['error'] === 'duplicate' ? 'Already asked them recently ✓' : ($r['error'] === 'opted_out' ? 'They opted out of texts.' : 'Hmm, that number didn’t look right.'));
    } elseif ($ok && $action === 'add_jobs') {
        $n = 0; $skip = 0;
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['jobs'] ?? '')) as $line) {
            $line = trim($line); if ($line === '') continue;
            if (preg_match('/^(.*?)[,;\t ]+(\+?[\d().\- ]{7,})$/', $line, $m) && preg_match('/\d{7,}/', $m[2])) { $name = trim($m[1]) ?: null; $phone = $m[2]; }
            elseif (preg_match('/\d{7,}/', $line)) { $name = null; $phone = $line; }
            else { $skip++; continue; }
            care_queueReview($clientId, $phone, $name, 'csv')['ok'] ? $n++ : $skip++;
        }
        $flash = "Sent {$n} review request" . ($n === 1 ? '' : 's') . ($skip ? " ({$skip} skipped)" : '') . '. ⭐';
    }
    header('Location: ' . CARE_BASE_URL . '/?ok=' . rawurlencode($flash ?? ''));
    exit;
}
$flash = isset($_GET['ok']) ? (string)$_GET['ok'] : '';

$monthStart = date('Y-m-01 00:00:00');
function care_scalar(PDO $db, string $sql, array $p) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
$callsMonth   = care_scalar($db, 'SELECT COUNT(*) FROM care_calls WHERE client_id=? AND created_at>=?', [$clientId, $monthStart]);
$missedRecov  = care_scalar($db, 'SELECT COUNT(*) FROM care_calls WHERE client_id=? AND disposition="missed" AND textback_sent=1 AND created_at>=?', [$clientId, $monthStart]);
$reviewsMonth = care_scalar($db, 'SELECT COUNT(*) FROM care_review_requests WHERE client_id=? AND created_at>=?', [$clientId, $monthStart]);

$calls = $db->prepare('SELECT caller, disposition, created_at FROM care_calls WHERE client_id=? ORDER BY id DESC LIMIT 12');
$calls->execute([$clientId]); $calls = $calls->fetchAll();
$reviews = $db->prepare('SELECT customer_phone, customer_name, status, created_at FROM care_review_requests WHERE client_id=? ORDER BY id DESC LIMIT 8');
$reviews->execute([$clientId]); $reviews = $reviews->fetchAll();

$pretty = function ($e164) { $d = preg_replace('/\D/', '', (string)$e164); if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1); return strlen($d) === 10 ? '(' . substr($d,0,3) . ') ' . substr($d,3,3) . '-' . substr($d,6) : (string)$e164; };
$ago = function ($ts) { $s = time() - strtotime($ts); if ($s < 3600) return max(1,(int)($s/60)) . 'm ago'; if ($s < 86400) return (int)($s/3600) . 'h ago'; if ($s < 172800) return 'yesterday'; return date('M j', strtotime($ts)); };
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= care_h2($biz) ?> — Care</title>
<style>
  :root{--teal:#0d9488;--teal-d:#0f766e;--ink:#142e29;--muted:#6f8a85;--bg:#f5f7f7;--line:#e8eeed;--soft:0 1px 3px rgba(20,46,41,.06),0 6px 18px rgba(20,46,41,.04)}
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:var(--bg);color:var(--ink);font-size:16px;-webkit-font-smoothing:antialiased;padding-bottom:48px}
  .wrap{max-width:540px;margin:0 auto;padding:0 18px}
  header{background:linear-gradient(140deg,#14b8a6 0%,var(--teal-d) 100%);color:#fff;padding:26px 0 60px;border-radius:0 0 26px 26px}
  header .wrap{display:flex;align-items:center;justify-content:space-between}
  .brand{font-weight:800;font-size:21px;letter-spacing:-.02em;line-height:1}
  .brand small{display:block;font-weight:600;font-size:11px;opacity:.75;margin-top:3px;letter-spacing:.02em}
  .biz{font-size:13px;font-weight:700;background:rgba(255,255,255,.16);padding:6px 13px;border-radius:999px;backdrop-filter:blur(4px)}
  main{margin-top:-44px}
  .flash{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:14px;padding:13px 16px;margin-bottom:14px;font-weight:600;font-size:14.5px;box-shadow:var(--soft)}

  /* slim stats strip */
  .stats{display:flex;background:#fff;border-radius:16px;box-shadow:var(--soft);overflow:hidden;margin-bottom:18px}
  .stats div{flex:1;padding:15px 8px;text-align:center;border-right:1px solid var(--line)}
  .stats div:last-child{border-right:none}
  .stats .n{font-size:24px;font-weight:900;color:var(--teal-d);line-height:1}
  .stats .l{font-size:11px;color:var(--muted);margin-top:5px;font-weight:600;line-height:1.25}

  /* hero: get a review */
  .hero{background:#fff;border-radius:20px;box-shadow:var(--soft);padding:22px 20px;margin-bottom:18px}
  .hero h1{font-size:21px;font-weight:850;letter-spacing:-.01em;display:flex;align-items:center;gap:8px}
  .hero .lead{color:var(--muted);font-size:14.5px;margin:6px 0 16px;line-height:1.45}
  .person{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:13px 0;border-top:1px solid var(--line)}
  .person:first-of-type{border-top:none}
  .person .ph{font-weight:750;font-size:16px}
  .person .sub{font-size:12.5px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:7px}
  .dot{width:7px;height:7px;border-radius:50%;display:inline-block}
  .dot.m{background:#f59e0b}.dot.a{background:#10b981}
  .ask{background:var(--teal);color:#fff;border:none;font-weight:800;font-size:14px;padding:11px 18px;border-radius:12px;cursor:pointer;white-space:nowrap;min-height:44px;box-shadow:0 4px 12px rgba(13,148,136,.28)}
  .ask:active{transform:translateY(1px)}
  .empty{color:var(--muted);font-size:14px;padding:8px 0 2px}

  details{margin-top:16px;border-top:1px solid var(--line);padding-top:14px}
  summary{list-style:none;cursor:pointer;color:var(--teal-d);font-weight:750;font-size:14.5px;display:flex;align-items:center;gap:7px}
  summary::-webkit-details-marker{display:none}
  .addform{margin-top:14px;display:grid;gap:10px}
  .addform input,.addform textarea{width:100%;border:1.5px solid var(--line);border-radius:12px;padding:13px;font:inherit;font-size:16px;background:#fbfdfc}
  .addform input:focus,.addform textarea:focus{outline:none;border-color:var(--teal)}
  .addform textarea{min-height:74px;resize:vertical}
  .addform button{background:var(--teal);color:#fff;border:none;font-weight:800;padding:13px;border-radius:12px;font-size:15px;cursor:pointer;min-height:48px}
  .sub2{font-size:12.5px;color:var(--muted)}

  .sent h2{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:800;margin:24px 4px 10px}
  .sent .card{background:#fff;border-radius:16px;box-shadow:var(--soft);overflow:hidden}
  .sent .r{display:flex;align-items:center;justify-content:space-between;padding:13px 16px;border-top:1px solid var(--line);font-size:15px}
  .sent .r:first-child{border-top:none}
  .tag{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:4px 9px;border-radius:999px;background:#e0f2fe;color:#0369a1}
  .tag.queued{background:#fef9c3;color:#854d0e}.tag.reminded{background:#ede9fe;color:#5b21b6}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:30px}
</style>
</head>
<body>
<header><div class="wrap">
  <div class="brand">Care<small>BY ADVERTON</small></div>
  <div class="biz"><?= care_h2($biz) ?></div>
</div></header>

<main><div class="wrap">

  <?php if ($flash): ?><div class="flash"><?= care_h2($flash) ?></div><?php endif; ?>

  <div class="stats">
    <div><div class="n"><?= $callsMonth ?></div><div class="l">calls<br>this month</div></div>
    <div><div class="n"><?= $missedRecov ?></div><div class="l">missed calls<br>we caught</div></div>
    <div><div class="n"><?= $reviewsMonth ?></div><div class="l">reviews<br>asked</div></div>
  </div>

  <div class="hero">
    <h1>⭐ Ask for a review</h1>
    <p class="lead">Tap a recent customer — we'll text them your Google review link. The more reviews, the higher you rank.</p>

    <?php if (!$calls): ?>
      <div class="empty">No recent calls yet. Use “Add a customer” below to ask anyone for a review.</div>
    <?php endif; ?>
    <?php foreach ($calls as $c): $m = $c['disposition'] === 'missed'; ?>
    <div class="person">
      <div>
        <div class="ph"><?= care_h2($pretty($c['caller'])) ?></div>
        <div class="sub"><span class="dot <?= $m ? 'm' : 'a' ?>"></span><?= $m ? 'missed' : 'answered' ?> · <?= care_h2($ago($c['created_at'])) ?></div>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="request_review">
        <input type="hidden" name="phone" value="<?= care_h2($c['caller']) ?>">
        <button class="ask" type="submit">Ask ⭐</button>
      </form>
    </div>
    <?php endforeach; ?>

    <details>
      <summary>➕ Add a customer who's not here</summary>
      <form class="addform" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_one">
        <input name="name" placeholder="Customer name (optional)">
        <input name="phone" inputmode="tel" placeholder="Their phone number" required>
        <button type="submit">Text them a review request ⭐</button>
        <div class="sub2">Got a few at once? <a href="#bulk" onclick="document.getElementById('bulk').open=true">paste a list</a>.</div>
      </form>
    </details>

    <details id="bulk">
      <summary>📋 Add several at once</summary>
      <form class="addform" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_jobs">
        <textarea name="jobs" placeholder="One per line:&#10;John Smith, 555-123-4567&#10;555-987-6543"></textarea>
        <button type="submit">Send all review requests ⭐</button>
      </form>
    </details>
  </div>

  <?php if ($reviews): ?>
  <div class="sent">
    <h2>Recently asked</h2>
    <div class="card">
      <?php foreach ($reviews as $r): $st = $r['status']; ?>
      <div class="r">
        <span><?= care_h2($r['customer_name'] ?: $pretty($r['customer_phone'])) ?> <span style="color:#9db5b0;font-size:12.5px">· <?= care_h2($ago($r['created_at'])) ?></span></span>
        <span class="tag <?= preg_replace('/[^a-z]/','',$st) ?>"><?= $st === 'sent' ? 'asked' : care_h2($st) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <footer>Adverton Care · we run this for you, automatically</footer>
</div></main>
</body></html>
