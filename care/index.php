<?php
// Adverton Care — contractor dashboard ("premium home" style). Passwordless
// magic-link (?t= sets a 90-day cookie). Greeting + "it's working" status +
// stat cards + a prominent review action.

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
       . '<title>Care</title><style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#0f766e;display:grid;place-items:center;min-height:100vh;margin:0;color:#fff;text-align:center;padding:24px}div{max-width:340px}h1{font-size:32px;margin:0 0 8px}p{opacity:.85;line-height:1.5}</style>'
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
        $flash = $r['ok'] ? "Done — we’ll text them your review link. ⭐"
                          : ($r['error'] === 'duplicate' ? 'Already asked them recently ✓' : ($r['error'] === 'opted_out' ? 'They opted out of texts.' : 'That number didn’t look right.'));
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

$calls = $db->prepare('SELECT caller, disposition, created_at FROM care_calls WHERE client_id=? ORDER BY id DESC LIMIT 10');
$calls->execute([$clientId]); $calls = $calls->fetchAll();
$reviews = $db->prepare('SELECT customer_phone, customer_name, status, created_at FROM care_review_requests WHERE client_id=? ORDER BY id DESC LIMIT 6');
$reviews->execute([$clientId]); $reviews = $reviews->fetchAll();

$pretty = function ($e164) { $d = preg_replace('/\D/', '', (string)$e164); if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1); return strlen($d) === 10 ? '(' . substr($d,0,3) . ') ' . substr($d,3,3) . '-' . substr($d,6) : (string)$e164; };
$ago = function ($ts) { $s = time() - strtotime($ts); if ($s < 3600) return max(1,(int)($s/60)) . 'm ago'; if ($s < 86400) return (int)($s/3600) . 'h ago'; if ($s < 172800) return 'yesterday'; return date('M j', strtotime($ts)); };
$firstName = trim(explode(' ', $biz)[0]);

$status = $missedRecov > 0
  ? "Your phone’s covered — we caught <b>{$missedRecov}</b> missed " . ($missedRecov === 1 ? 'call' : 'calls') . " for you this month. No lead lost."
  : ($callsMonth > 0 ? "Your phone’s covered — <b>{$callsMonth}</b> " . ($callsMonth === 1 ? 'call' : 'calls') . " handled this month."
                     : "Your phone’s covered. We catch every call and missed lead for you.");

// Inline icons
$icPhone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
$icShield = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>';
$icStar = '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= care_h2($biz) ?> — Care</title>
<style>
  :root{
    --teal:#0d9488;--teal2:#14b8a6;--teal-d:#0f766e;--ink:#102a26;--muted:#6e8a85;
    --bg:#eef3f2;--line:#e7eeec;--gold:#f59e0b;
    --shadow:0 1px 2px rgba(16,42,38,.05),0 8px 24px rgba(16,42,38,.07);
    --shadow-sm:0 1px 2px rgba(16,42,38,.05),0 4px 12px rgba(16,42,38,.05);
  }
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:16px;-webkit-font-smoothing:antialiased;padding-bottom:46px}
  .wrap{max-width:540px;margin:0 auto;padding:0 18px}
  svg{width:1em;height:1em;display:block}

  .hero{background:radial-gradient(120% 120% at 0% 0%,#16c0ad 0%,var(--teal) 42%,var(--teal-d) 100%);color:#fff;padding:20px 0 78px;border-radius:0 0 30px 30px;position:relative;overflow:hidden}
  .hero::after{content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.07)}
  .hero .top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
  .brand{font-weight:800;font-size:18px;letter-spacing:.02em}
  .brand .by{font-weight:600;opacity:.7;font-size:11px;margin-left:4px}
  .biz{font-size:12.5px;font-weight:700;background:rgba(255,255,255,.15);padding:6px 12px;border-radius:999px}
  .greet{font-size:27px;font-weight:850;letter-spacing:-.02em;margin:20px 0 8px;position:relative;z-index:1}
  .status{font-size:15px;line-height:1.5;color:rgba(255,255,255,.92);max-width:90%;position:relative;z-index:1}
  .status b{font-weight:800}

  main{margin-top:-50px;position:relative;z-index:2}
  .flash{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:14px;padding:13px 16px;margin:0 0 16px;font-weight:650;font-size:14.5px;box-shadow:var(--shadow-sm)}

  .stats{display:grid;grid-template-columns:repeat(3,1fr);gap:11px}
  .stat{background:#fff;border-radius:18px;box-shadow:var(--shadow);padding:16px 10px 14px;text-align:center}
  .stat .ic{width:34px;height:34px;margin:0 auto 8px;border-radius:11px;display:grid;place-items:center;background:#e6f5f2;color:var(--teal-d);font-size:18px}
  .stat .ic.g{background:#fef3c7;color:var(--gold)}
  .stat .n{font-size:25px;font-weight:900;line-height:1;color:var(--ink)}
  .stat .l{font-size:11.5px;color:var(--muted);margin-top:5px;font-weight:650}

  .action{background:#fff;border-radius:22px;box-shadow:var(--shadow);padding:22px 20px;margin-top:18px}
  .action .h{display:flex;align-items:center;gap:9px;font-size:19px;font-weight:850;letter-spacing:-.01em}
  .action .h .s{color:var(--gold);font-size:20px}
  .action .lead{color:var(--muted);font-size:14px;line-height:1.45;margin:7px 0 4px}

  .person{display:flex;align-items:center;gap:13px;padding:13px 0;border-top:1px solid var(--line)}
  .person .av{width:42px;height:42px;flex:none;border-radius:50%;background:#e6f5f2;color:var(--teal-d);display:grid;place-items:center;font-size:17px}
  .person .info{flex:1;min-width:0}
  .person .ph{font-weight:750;font-size:16px}
  .person .meta{font-size:12.5px;color:var(--muted);margin-top:2px;display:flex;align-items:center;gap:6px}
  .badge{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.03em;padding:2px 7px;border-radius:6px}
  .b-m{background:#fef3c7;color:#92400e}.b-a{background:#d1fae5;color:#065f46}
  .ask{flex:none;background:var(--teal);color:#fff;border:none;font-weight:800;font-size:14px;padding:11px 16px;border-radius:12px;cursor:pointer;min-height:44px;box-shadow:0 5px 14px rgba(13,148,136,.30);display:flex;align-items:center;gap:5px}
  .ask:active{transform:translateY(1px)}
  .ask .s{color:#ffe9a8}
  .empty{color:var(--muted);font-size:14px;padding:14px 0 2px;text-align:center}

  details{margin-top:14px;border-top:1px solid var(--line);padding-top:14px}
  summary{list-style:none;cursor:pointer;color:var(--teal-d);font-weight:750;font-size:14.5px}
  summary::-webkit-details-marker{display:none}
  .addform{margin-top:13px;display:grid;gap:10px}
  .addform input,.addform textarea{width:100%;border:1.5px solid var(--line);border-radius:13px;padding:13px;font:inherit;font-size:16px;background:#fbfdfc}
  .addform input:focus,.addform textarea:focus{outline:none;border-color:var(--teal);background:#fff}
  .addform textarea{min-height:74px;resize:vertical}
  .addform .go{background:var(--teal);color:#fff;border:none;font-weight:800;padding:14px;border-radius:13px;font-size:15px;cursor:pointer;min-height:50px}

  .sent h2{font-size:12.5px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;font-weight:800;margin:26px 6px 11px}
  .sent .card{background:#fff;border-radius:18px;box-shadow:var(--shadow-sm);overflow:hidden}
  .sent .r{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid var(--line);font-size:15px}
  .sent .r:first-child{border-top:none}
  .tag{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:4px 10px;border-radius:999px;background:#e0f2fe;color:#0369a1}
  .tag.queued{background:#fef9c3;color:#854d0e}.tag.reminded{background:#ede9fe;color:#5b21b6}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:30px}
</style>
</head>
<body>
<header class="hero"><div class="wrap">
  <div class="top">
    <span class="brand">Care<span class="by">by Adverton</span></span>
    <span class="biz"><?= care_h2($biz) ?></span>
  </div>
  <div class="greet">Hi, <?= care_h2($firstName) ?> 👋</div>
  <div class="status"><?= $status ?></div>
</div></header>

<main><div class="wrap">

  <?php if ($flash): ?><div class="flash"><?= care_h2($flash) ?></div><?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="ic"><?= $icPhone ?></div><div class="n"><?= $callsMonth ?></div><div class="l">calls<br>this month</div></div>
    <div class="stat"><div class="ic"><?= $icShield ?></div><div class="n"><?= $missedRecov ?></div><div class="l">missed calls<br>we caught</div></div>
    <div class="stat"><div class="ic g"><?= $icStar ?></div><div class="n"><?= $reviewsMonth ?></div><div class="l">reviews<br>asked</div></div>
  </div>

  <section class="action">
    <div class="h"><span class="s"><?= $icStar ?></span> Ask for a 5-star review</div>
    <div class="lead">Tap a recent customer — we’ll text them your Google review link. More reviews = higher on Google.</div>

    <?php if (!$calls): ?>
      <div class="empty">No recent calls yet. Use “Add a customer” below to ask anyone.</div>
    <?php endif; ?>
    <?php foreach ($calls as $c): $m = $c['disposition'] === 'missed'; $pp = $pretty($c['caller']); ?>
    <div class="person">
      <div class="av"><?= $icPhone ?></div>
      <div class="info">
        <div class="ph"><?= care_h2($pp) ?></div>
        <div class="meta"><span class="badge <?= $m ? 'b-m' : 'b-a' ?>"><?= $m ? 'missed' : 'answered' ?></span> <?= care_h2($ago($c['created_at'])) ?></div>
      </div>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="request_review">
        <input type="hidden" name="phone" value="<?= care_h2($c['caller']) ?>">
        <button class="ask" type="submit">Ask <span class="s"><?= $icStar ?></span></button>
      </form>
    </div>
    <?php endforeach; ?>

    <details>
      <summary>➕ Add a customer who’s not here</summary>
      <form class="addform" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_one">
        <input name="name" placeholder="Customer name (optional)">
        <input name="phone" inputmode="tel" placeholder="Their phone number" required>
        <button class="go" type="submit">Text them a review request ⭐</button>
      </form>
    </details>

    <details>
      <summary>📋 Add several at once</summary>
      <form class="addform" method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_jobs">
        <textarea name="jobs" placeholder="One per line:&#10;John Smith, 555-123-4567&#10;555-987-6543"></textarea>
        <button class="go" type="submit">Send all review requests ⭐</button>
      </form>
    </details>
  </section>

  <?php if ($reviews): ?>
  <div class="sent">
    <h2>Recently asked</h2>
    <div class="card">
      <?php foreach ($reviews as $r): $st = $r['status']; ?>
      <div class="r">
        <span><?= care_h2($r['customer_name'] ?: $pretty($r['customer_phone'])) ?> <span style="color:#a6bdb8;font-size:12.5px">· <?= care_h2($ago($r['created_at'])) ?></span></span>
        <span class="tag <?= preg_replace('/[^a-z]/','',$st) ?>"><?= $st === 'sent' ? 'asked' : care_h2($st) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <footer>Adverton Care · we run this for you, automatically</footer>
</div></main>
</body></html>
