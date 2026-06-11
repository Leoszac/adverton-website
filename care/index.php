<?php
// Adverton Care — contractor dashboard, built as a mobile app with a fixed
// bottom nav (Home · Jobs · Reviews · Help). Passwordless magic-link (?t= sets
// a 90-day cookie). Each ?view= renders one focused screen — no giant scroll.

declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/jobs.php';   // pulls reviews + flows + the job tracker
require_once __DIR__ . '/lib/users.php';  // multi-user access

$me       = care_currentUser();
$clientId = $me['client_id'] ?? null;
$role     = $me['role'] ?? 'staff';
$token    = (string)($_GET['t'] ?? $_COOKIE['care_sess'] ?? '');
function care_h2(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$csrf = $token ? substr(hash_hmac('sha256', 'care-csrf', $token), 0, 24) : '';

if (isset($_GET['logout'])) {
    setcookie('care_sess', '', ['expires'=>time()-3600, 'path'=>'/care', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Lax']);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><title>Care</title>'
       . '<style>body{font-family:-apple-system,Segoe UI,sans-serif;background:#0f766e;display:grid;place-items:center;min-height:100vh;margin:0;color:#fff;text-align:center;padding:24px}h1{font-size:30px;margin:0 0 8px}p{opacity:.85}</style>'
       . '<div><h1>Care</h1><p>You’re signed out. Use your link to sign back in.</p></div>';
    exit;
}

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
header('Referrer-Policy: no-referrer');   // keep the ?t= token out of Referer

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $ok = $csrf !== '' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    $code = '';
    if ($ok && ($action === 'request_review' || $action === 'add_one')) {
        $r = care_queueReview($clientId, (string)($_POST['phone'] ?? ''), trim((string)($_POST['name'] ?? '')) ?: null, $action === 'add_one' ? 'manual' : 'call_tap');
        $code = $r['ok'] ? 'rq_ok' : ($r['error'] === 'duplicate' ? 'rq_dup' : ($r['error'] === 'opted_out' ? 'rq_opt' : 'rq_bad'));
    } elseif ($ok && $action === 'add_jobs') {
        $n = 0;
        foreach (array_slice(preg_split('/\r\n|\r|\n/', (string)($_POST['jobs'] ?? '')), 0, 100) as $line) {
            $line = trim($line); if ($line === '') continue;
            if (preg_match('/^(.*?)[,;\t ]+(\+?[\d().\- ]{7,})$/', $line, $m) && preg_match('/\d{7,}/', $m[2])) { $name = trim($m[1]) ?: null; $phone = $m[2]; }
            elseif (preg_match('/\d{7,}/', $line)) { $name = null; $phone = $line; }
            else { continue; }
            if (care_queueReview($clientId, $phone, $name, 'csv')['ok']) $n++;
        }
        $code = 'rq_many';
    } elseif ($ok && $action === 'set_link') {
        $code = care_setReviewLink($clientId, (string)($_POST['review_link'] ?? '')) ? 'lk_ok' : 'lk_bad';
    } elseif ($ok && $action === 'add_job') {
        $r = care_addJob($clientId, trim((string)($_POST['name'] ?? '')) ?: null, (string)($_POST['phone'] ?? ''), trim((string)($_POST['address'] ?? '')) ?: null);
        $code = $r['ok'] ? 'jb_add' : 'jb_bad';
    } elseif ($ok && $action === 'update_job') {
        care_updateJob($clientId, (int)($_POST['job_id'] ?? 0), isset($_POST['status']) ? (string)$_POST['status'] : null, isset($_POST['name']) ? trim((string)$_POST['name']) : null);
        $code = (($_POST['status'] ?? '') === 'done') ? 'jb_done' : 'jb_upd';
    } elseif ($ok && $action === 'edit_job') {
        care_editJob($clientId, (int)($_POST['job_id'] ?? 0), trim((string)($_POST['name'] ?? '')) ?: null, (string)($_POST['phone'] ?? ''), trim((string)($_POST['address'] ?? '')) ?: null);
        $code = 'jb_edit';
    } elseif ($ok && $action === 'delete_job') {
        $code = care_deleteJob($clientId, (int)($_POST['job_id'] ?? 0)) ? 'jb_del' : 'jb_delfail';
    } elseif ($ok && $role === 'owner' && $action === 'add_user') {
        $a = care_addUser($clientId, trim((string)($_POST['name'] ?? '')) ?: null, trim((string)($_POST['contact'] ?? '')) ?: null, 'staff');
        $code = $a['ok'] ? 'usr_add' : 'usr_bad';
    } elseif ($ok && $role === 'owner' && $action === 'revoke_user') {
        $code = care_revokeUser($clientId, (int)($_POST['user_id'] ?? 0)) ? 'usr_rm' : 'usr_rmfail';
    }
    $rv  = in_array($action, ['add_job','update_job','edit_job','delete_job'], true) ? 'jobs'
         : (in_array($action, ['add_user','revoke_user'], true) ? 'help' : 'reviews');
    $ret = (isset($_POST['ret']) && in_array($_POST['ret'], CARE_JOB_STATUSES, true)) ? '&tab=' . rawurlencode((string)$_POST['ret']) : '';
    header('Location: ' . CARE_BASE_URL . '/?view=' . $rv . '&ok=' . $code . $ret);
    exit;
}
// Flash messages come from a fixed code (never free text in the URL).
$codeMap = [
    'rq_ok'=>'Done — we’ll text them your review link. ⭐', 'rq_dup'=>'Already asked them recently ✓', 'rq_opt'=>'They opted out of texts.', 'rq_bad'=>'That number didn’t look right.',
    'rq_many'=>'Review requests sent. ⭐', 'lk_ok'=>'Saved your Google review link ✓', 'lk_bad'=>'That didn’t look like a valid link.',
    'jb_add'=>'Job added to your list ✓', 'jb_bad'=>'That number didn’t look right.', 'jb_done'=>'Marked done — we’ll text them for a review ⭐', 'jb_upd'=>'Updated ✓', 'jb_edit'=>'Job updated ✓', 'jb_del'=>'Job deleted ✓', 'jb_delfail'=>'Could not delete.',
    'usr_add'=>'Team member added — send them their link ✓', 'usr_bad'=>'Couldn’t add — check the details.', 'usr_rm'=>'Access removed ✓', 'usr_rmfail'=>'Couldn’t remove that one.',
];
$flash = $codeMap[(string)($_GET['ok'] ?? '')] ?? '';

$view = (string)($_GET['view'] ?? 'home');
if (!in_array($view, ['home','jobs','reviews','help'], true)) $view = 'home';

// ── Data ─────────────────────────────────────────────────────────────────
$series = [];
for ($i = 5; $i >= 0; $i--) { $m = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $series[$m] = ['calls'=>0,'saved'=>0,'reviews'=>0]; }
$since = date('Y-m-01', strtotime(date('Y-m-01') . ' -5 month'));
$cq = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c, SUM(disposition='missed' AND textback_sent=1) s FROM care_calls WHERE client_id=? AND created_at>=? GROUP BY ym");
$cq->execute([$clientId, $since]);
foreach ($cq as $r) { if (isset($series[$r['ym']])) { $series[$r['ym']]['calls']=(int)$r['c']; $series[$r['ym']]['saved']=(int)$r['s']; } }
$rq = $db->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c FROM care_review_requests WHERE client_id=? AND created_at>=? GROUP BY ym");
$rq->execute([$clientId, $since]);
foreach ($rq as $r) { if (isset($series[$r['ym']])) $series[$r['ym']]['reviews']=(int)$r['c']; }
$keys = array_keys($series); $cur = $series[$keys[5]]; $prev = $series[$keys[4]];

function care_scalar(PDO $db, string $sql, array $p) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
$allSaved = care_scalar($db, "SELECT COUNT(*) FROM care_calls WHERE client_id=? AND disposition='missed' AND textback_sent=1", [$clientId]);
$allCalls = care_scalar($db, "SELECT COUNT(*) FROM care_calls WHERE client_id=?", [$clientId]);

$delta = function (int $c, int $p): array {
    if ($p === 0) return $c > 0 ? ['▲','up','new'] : ['','flat','—'];
    $d = (int)round(($c - $p) / $p * 100);
    return [$d >= 0 ? '▲' : '▼', $d >= 0 ? 'up' : 'down', abs($d) . '%'];
};
[$dcA,$dcC,$dcL] = $delta($cur['calls'], $prev['calls']);
[$dsA,$dsC,$dsL] = $delta($cur['saved'], $prev['saved']);
[$drA,$drC,$drL] = $delta($cur['reviews'], $prev['reviews']);

$reviews = $db->prepare('SELECT customer_phone, customer_name, status, created_at FROM care_review_requests WHERE client_id=? ORDER BY id DESC LIMIT 8');
$reviews->execute([$clientId]); $reviews = $reviews->fetchAll();
$reviewLink = care_reviewLink($clientId);
$reviewMsgPreview = care_reviewMessage($biz, null, $reviewLink ?: 'https://g.page/your-business', false);
$missedMsg = care_missedCallMessage($biz);
$jtab = (string)($_GET['tab'] ?? 'lead'); if (!in_array($jtab, CARE_JOB_STATUSES, true)) $jtab = 'lead';
$jq = trim((string)($_GET['q'] ?? '')); $jpage = max(0, (int)($_GET['page'] ?? 0)); $jper = 20;
$jc = care_jobCounts($clientId);
$jobs = care_listJobs($clientId, $jtab, $jq, $jper, $jpage * $jper);
$jtotal = care_countJobs($clientId, $jtab, $jq);

$pretty = function ($e164) { $d = preg_replace('/\D/', '', (string)$e164); if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1); return strlen($d) === 10 ? '(' . substr($d,0,3) . ') ' . substr($d,3,3) . '-' . substr($d,6) : (string)$e164; };
$ago = function ($ts) { $s = time() - strtotime($ts); if ($s < 3600) return max(1,(int)($s/60)) . 'm ago'; if ($s < 86400) return (int)($s/3600) . 'h ago'; if ($s < 172800) return 'yesterday'; return date('M j', strtotime($ts)); };
$firstName = trim(explode(' ', $biz)[0]);
$titles = ['home'=>'Hi, ' . $firstName . ' 👋', 'jobs'=>'Your jobs', 'reviews'=>'Reviews', 'help'=>'How Care works'];

$chart = function (array $series): string {
    $W=340; $H=148; $padB=22; $padT=20; $n=count($series); $gap=12;
    $bw=($W-($n+1)*$gap)/$n; $max=1; foreach($series as $s){ $max=max($max,$s['calls']); }
    $cH=$H-$padB-$padT; $svg=''; $i=0;
    foreach($series as $ym=>$s){
        $x=$gap+$i*($bw+$gap); $h=$max?($s['calls']/$max*$cH):0; $y=$padT+($cH-$h);
        $sh=$max?($s['saved']/$max*$cH):0; $sy=$padT+$cH-$sh;
        $svg.='<rect x="'.round($x,1).'" y="'.round($y,1).'" width="'.round($bw,1).'" height="'.round(max($h,2),1).'" rx="5" fill="#0d9488"/>';
        if($sh>0) $svg.='<rect x="'.round($x,1).'" y="'.round($sy,1).'" width="'.round($bw,1).'" height="'.round($sh,1).'" rx="5" fill="#f59e0b"/>';
        if($s['calls']>0) $svg.='<text x="'.round($x+$bw/2,1).'" y="'.round($y-6,1).'" text-anchor="middle" font-size="11.5" font-weight="800" fill="#102a26">'.$s['calls'].'</text>';
        $svg.='<text x="'.round($x+$bw/2,1).'" y="'.($H-5).'" text-anchor="middle" font-size="11" fill="#7d9690">'.date('M',strtotime($ym.'-01')).'</text>';
        $i++;
    }
    return '<svg viewBox="0 0 '.$W.' '.$H.'" width="100%" preserveAspectRatio="xMidYMid meet">'.$svg.'</svg>';
};
$icStar = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>';
$icPhone = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= care_h2($biz) ?> — Care</title>
<style>
  :root{--teal:#0d9488;--teal-d:#0f766e;--ink:#102a26;--muted:#6e8a85;--bg:#eef3f2;--line:#e7eeec;--gold:#f59e0b;
    --shadow:0 1px 2px rgba(16,42,38,.05),0 8px 24px rgba(16,42,38,.07);--shadow-sm:0 1px 2px rgba(16,42,38,.05),0 4px 12px rgba(16,42,38,.05)}
  *{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
  body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:16px;-webkit-font-smoothing:antialiased;padding-bottom:84px}
  .wrap{max-width:540px;margin:0 auto;padding:0 18px}
  svg{display:block}
  .hero{background:radial-gradient(120% 120% at 0% 0%,#16c0ad 0%,var(--teal) 42%,var(--teal-d) 100%);color:#fff;padding:20px 0 76px;border-radius:0 0 30px 30px;position:relative;overflow:hidden}
  .hero::after{content:"";position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.07)}
  .hero .top{display:flex;align-items:center;justify-content:space-between;position:relative;z-index:1}
  .brand{font-weight:800;font-size:18px}.brand .by{font-weight:600;opacity:.7;font-size:11px;margin-left:4px}
  .biz{font-size:12.5px;font-weight:700;background:rgba(255,255,255,.15);padding:6px 12px;border-radius:999px}
  .greet{font-size:26px;font-weight:850;letter-spacing:-.02em;margin:18px 0 6px;position:relative;z-index:1}
  .headline{font-size:15px;line-height:1.5;color:rgba(255,255,255,.93);position:relative;z-index:1}.headline b{font-weight:800}
  main{margin-top:-50px;position:relative;z-index:2}
  .flash{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:14px;padding:13px 16px;margin:0 0 16px;font-weight:650;font-size:14.5px;box-shadow:var(--shadow-sm)}
  .kpis{display:grid;grid-template-columns:repeat(3,1fr);gap:11px}
  .kpi{background:#fff;border-radius:18px;box-shadow:var(--shadow);padding:15px 12px 13px;text-align:center}
  .kpi .n{font-size:26px;font-weight:900;line-height:1;color:var(--ink)}
  .kpi .l{font-size:11px;color:var(--muted);margin-top:5px;font-weight:650;line-height:1.25}
  .kpi .d{display:inline-block;margin-top:8px;font-size:10.5px;font-weight:800;padding:2px 8px;border-radius:999px}
  .kpi .d.up{background:#d1fae5;color:#047857}.kpi .d.down{background:#fee2e2;color:#b91c1c}.kpi .d.flat{background:#eef2f1;color:#7d9690}
  .card{background:#fff;border-radius:20px;box-shadow:var(--shadow);padding:18px 18px;margin-top:16px}
  .card .ttl{font-size:13px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;font-weight:800;display:flex;justify-content:space-between;align-items:center}
  .legend{font-size:11.5px;color:var(--muted);font-weight:600}.legend i{width:9px;height:9px;border-radius:3px;display:inline-block;margin:0 4px 0 9px;vertical-align:middle}
  .total{font-size:13.5px;color:var(--muted);margin-top:12px;text-align:center}.total b{color:var(--teal-d);font-weight:800}
  .rlink{background:#f2faf8;border:1px solid #d3ede7;border-radius:14px;padding:13px 15px;margin:14px 0 0}.rlink.warn{background:#fffbeb;border-color:#fde68a}
  .rlrow{display:flex;align-items:center;justify-content:space-between;gap:12px}
  .rllabel{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
  .rlurl{display:block;margin-top:3px;font-size:13.5px;color:var(--teal-d);font-weight:600;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .rltest{flex:none;background:#fff;border:1.5px solid var(--teal);color:var(--teal-d);font-weight:800;font-size:13px;padding:9px 14px;border-radius:10px;text-decoration:none;min-height:40px;display:flex;align-items:center}
  .rledit{margin-top:11px;border:none;padding:0}.rledit summary{font-size:13px}
  .preview{margin-top:14px}.pvlabel{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);margin-bottom:8px}
  .bubble{background:#eaf2ff;color:#23446e;border-radius:16px 16px 16px 5px;padding:13px 15px;font-size:13.5px;line-height:1.5}
  .badge-auto{font-size:10.5px;font-weight:800;background:#fef3c7;color:#92400e;padding:4px 11px;border-radius:999px;white-space:nowrap}
  .badge-you{font-size:10.5px;font-weight:800;background:#e6f5f2;color:#0f766e;padding:4px 11px;border-radius:999px;white-space:nowrap}
  .lead2{color:var(--muted);font-size:14px;line-height:1.5;margin:8px 0 0}.lead2 b{color:var(--ink);font-weight:750}
  details.add{margin-top:14px;border-top:1px solid var(--line);padding-top:14px}details.add summary{list-style:none;cursor:pointer;color:var(--teal-d);font-weight:750;font-size:14.5px}details.add summary::-webkit-details-marker{display:none}
  .addform{margin-top:13px;display:grid;gap:10px}.addform input,.addform textarea{width:100%;border:1.5px solid var(--line);border-radius:13px;padding:13px;font:inherit;font-size:16px;background:#fbfdfc}.addform input:focus,.addform textarea:focus{outline:none;border-color:var(--teal);background:#fff}.addform textarea{min-height:74px;resize:vertical}.addform .go{background:var(--teal);color:#fff;border:none;font-weight:800;padding:14px;border-radius:13px;font-size:15px;cursor:pointer;min-height:50px}
  .hist .r,.sent .r{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-top:1px solid var(--line);font-size:14.5px}.hist .r:first-of-type,.sent .r:first-of-type{border-top:none}
  .hist .mo{font-weight:750}.hist .vals{font-size:13px;color:var(--muted)}.hist .vals b{color:var(--ink)}
  .tag{font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;padding:4px 10px;border-radius:999px;background:#e0f2fe;color:#0369a1}.tag.queued{background:#fef9c3;color:#854d0e}.tag.reminded{background:#ede9fe;color:#5b21b6}
  .howto>summary{font-weight:800;font-size:15.5px;color:var(--ink);list-style:none;cursor:pointer}.howto>summary::-webkit-details-marker{display:none}
  .steps{margin-top:15px;display:grid;gap:14px}.step{display:flex;gap:12px;font-size:14px;line-height:1.45;color:#3a5853}.step span{font-size:21px;flex:none}.step b{color:var(--ink)}
  .job{padding:13px 0;border-top:1px solid var(--line)}
  .jtop{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
  .jinfo{min-width:0;flex:1}.jname{font-weight:750;font-size:16px}.jmeta{font-size:12.5px;color:var(--muted);margin-top:2px}
  .jaddr{font-size:12.5px;color:var(--muted);margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .jedit{margin-top:9px}.jedit>summary{list-style:none;cursor:pointer;color:var(--teal-d);font-weight:700;font-size:12.5px}.jedit>summary::-webkit-details-marker{display:none}
  .delform{margin-top:6px}.delform button{background:none;border:none;color:#b91c1c;font-weight:700;font-size:13px;cursor:pointer;padding:7px 0}
  .rolepill{font-size:10px;font-weight:800;text-transform:uppercase;background:#eef2f1;color:#6e8a85;padding:2px 7px;border-radius:6px;vertical-align:middle}
  .delbtn{flex:none;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;font-weight:700;font-size:13px;padding:9px 14px;border-radius:10px;cursor:pointer;min-height:40px}
  .jstatus{margin:0;flex:none}.jstatus select{border:1.5px solid var(--line);border-radius:11px;padding:9px 12px;font:inherit;font-size:14px;font-weight:750;background:#fbfdfc;color:var(--ink);min-height:42px;-webkit-appearance:none;appearance:none}
  .tabs{display:flex;gap:7px;margin:14px 0 12px;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}.tabs::-webkit-scrollbar{display:none}
  .tabs a{flex:none;padding:8px 14px;border-radius:999px;font-size:13.5px;font-weight:750;color:var(--muted);background:#eef2f1;text-decoration:none;white-space:nowrap}
  .tabs a.on{background:var(--teal);color:#fff}.tabs a b{font-weight:850;opacity:.85}.tabs a.on b{opacity:1}
  .jsearch{display:flex;align-items:center;gap:8px;margin-bottom:4px;position:relative}
  .jsearch input{flex:1;border:1.5px solid var(--line);border-radius:12px;padding:11px 13px;font:inherit;font-size:16px;background:#fbfdfc}.jsearch input:focus{outline:none;border-color:var(--teal);background:#fff}
  .jsearch .clr{position:absolute;right:13px;color:var(--muted);text-decoration:none;font-weight:700;font-size:15px}
  .pager{display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:13px;color:var(--muted)}.pager a{color:var(--teal-d);font-weight:800;text-decoration:none}.pager span{min-width:40px}
  .empty{color:var(--muted);font-size:14px;padding:16px 0;text-align:center}
  footer{text-align:center;color:var(--muted);font-size:12px;margin-top:26px}
  /* bottom nav */
  .botnav{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid var(--line);z-index:50;box-shadow:0 -2px 14px rgba(16,42,38,.06);display:flex;justify-content:center;padding-bottom:env(safe-area-inset-bottom)}
  .botnav .nv{display:flex;width:100%;max-width:540px}
  .botnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;text-decoration:none;color:#90a8a3;font-size:11px;font-weight:700;padding:9px 0 8px}
  .botnav a svg{width:23px;height:23px}
  .botnav a.on{color:var(--teal)}
</style>
</head>
<body>
<header class="hero"><div class="wrap">
  <div class="top"><span class="brand">Care<span class="by">by Adverton</span></span><span class="biz"><?= care_h2($biz) ?></span></div>
  <div class="greet"><?= care_h2($titles[$view]) ?></div>
  <?php if ($view==='home'): ?><div class="headline">This month: <b><?= $cur['calls'] ?></b> calls and <b><?= $cur['saved'] ?></b> missed <?= $cur['saved']===1?'lead':'leads' ?> we caught for you.</div><?php endif; ?>
</div></header>

<main><div class="wrap">
  <?php if ($flash): ?><div class="flash"><?= care_h2($flash) ?></div><?php endif; ?>

  <?php if ($view === 'home'): ?>
  <div class="kpis">
    <div class="kpi"><div class="n"><?= $cur['calls'] ?></div><div class="l">calls<br>this month</div><span class="d <?= $dcC ?>"><?= $dcA . ' ' . $dcL ?></span></div>
    <div class="kpi"><div class="n"><?= $cur['saved'] ?></div><div class="l">leads<br>we caught</div><span class="d <?= $dsC ?>"><?= $dsA . ' ' . $dsL ?></span></div>
    <div class="kpi"><div class="n"><?= $cur['reviews'] ?></div><div class="l">reviews<br>asked</div><span class="d <?= $drC ?>"><?= $drA . ' ' . $drL ?></span></div>
  </div>
  <div class="card">
    <div class="ttl"><span>Calls — last 6 months</span><span class="legend"><i style="background:#0d9488"></i>answered<i style="background:#f59e0b"></i>missed</span></div>
    <?= $chart($series) ?>
    <div class="total">Since you started, we’ve handled <b><?= $allCalls ?></b> calls and saved you <b><?= $allSaved ?></b> missed leads.</div>
  </div>
  <div class="card hist">
    <div class="ttl"><span>Your months</span></div>
    <div style="margin-top:8px">
    <?php foreach (array_reverse($series, true) as $ym => $s): ?>
      <div class="r"><span class="mo"><?= date('F Y', strtotime($ym.'-01')) ?></span><span class="vals"><b><?= $s['calls'] ?></b> calls · <b><?= $s['saved'] ?></b> saved · <b><?= $s['reviews'] ?></b> reviews</span></div>
    <?php endforeach; ?>
    </div>
  </div>

  <?php elseif ($view === 'jobs'): ?>
  <div class="card jobs">
    <div class="ttl"><span>Pipeline</span><span class="badge-auto">⚡ Done → auto review</span></div>
    <p class="lead2">Move a job to <b>Done</b> when you finish — we text that customer for a review automatically. Missed-call leads land here on their own.</p>
    <div class="tabs">
      <?php foreach (['lead'=>'Leads','scheduled'=>'Scheduled','done'=>'Done','lost'=>'Lost'] as $k=>$lbl): ?>
      <a href="?view=jobs&tab=<?= $k ?><?= $jq!==''?'&q='.rawurlencode($jq):'' ?>" class="<?= $jtab===$k?'on':'' ?>"><?= $lbl ?> <b><?= $jc[$k] ?></b></a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="jsearch" action="">
      <input type="hidden" name="view" value="jobs"><input type="hidden" name="tab" value="<?= care_h2($jtab) ?>">
      <input name="q" value="<?= care_h2($jq) ?>" placeholder="Search name or number…">
      <?php if ($jq!==''): ?><a class="clr" href="?view=jobs&tab=<?= $jtab ?>">✕</a><?php endif; ?>
    </form>
    <?php if (!$jobs): ?><div class="empty"><?= $jq!=='' ? 'No matches for “'.care_h2($jq).'”.' : 'Nothing in '.$jtab.' yet.' ?></div><?php endif; ?>
    <?php foreach ($jobs as $j): ?>
    <div class="job">
      <div class="jtop">
        <div class="jinfo">
          <div class="jname"><?= care_h2($j['name'] ?: $pretty($j['phone'])) ?></div>
          <div class="jmeta"><?= care_h2($pretty($j['phone'])) ?></div>
          <?php if ($j['address']): ?><div class="jaddr"><?= care_h2($j['address']) ?></div><?php endif; ?>
        </div>
        <form method="post" class="jstatus"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="update_job"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="ret" value="<?= care_h2($jtab) ?>">
          <select name="status" onchange="this.form.submit()">
            <?php foreach (['lead'=>'Lead','scheduled'=>'Scheduled','done'=>'Done ✓','lost'=>'Lost'] as $k=>$v): ?><option value="<?= $k ?>" <?= $j['status']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
          </select>
        </form>
      </div>
      <details class="jedit">
        <summary>Edit / delete</summary>
        <form class="addform" method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="edit_job"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="ret" value="<?= care_h2($jtab) ?>">
          <input name="name" value="<?= care_h2((string)$j['name']) ?>" placeholder="Name">
          <input name="phone" inputmode="tel" value="<?= care_h2($pretty($j['phone'])) ?>" placeholder="Phone">
          <input name="address" value="<?= care_h2((string)$j['address']) ?>" placeholder="Address">
          <button class="go" type="submit">Save changes</button>
        </form>
        <form method="post" class="delform" onsubmit="return confirm('Delete this job? This can’t be undone.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="delete_job"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><input type="hidden" name="ret" value="<?= care_h2($jtab) ?>"><button type="submit">🗑 Delete this job</button></form>
      </details>
    </div>
    <?php endforeach; ?>
    <?php if ($jtotal > $jper): $jpages=(int)ceil($jtotal/$jper); $qs=$jq!==''?'&q='.rawurlencode($jq):''; ?>
    <div class="pager">
      <?php if ($jpage>0): ?><a href="?view=jobs&tab=<?= $jtab.$qs ?>&page=<?= $jpage-1 ?>">← Newer</a><?php else: ?><span></span><?php endif; ?>
      <span><?= $jpage*$jper+1 ?>–<?= min(($jpage+1)*$jper,$jtotal) ?> of <?= $jtotal ?></span>
      <?php if ($jpage+1<$jpages): ?><a href="?view=jobs&tab=<?= $jtab.$qs ?>&page=<?= $jpage+1 ?>">Older →</a><?php else: ?><span></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <details class="add"><summary>➕ Add a job</summary>
      <form class="addform" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_job"><input type="hidden" name="ret" value="<?= care_h2($jtab) ?>">
        <input name="name" placeholder="Customer name (optional)"><input name="phone" inputmode="tel" placeholder="Their phone number" required><input name="address" placeholder="Address (optional)">
        <button class="go" type="submit">Add to my jobs</button>
      </form>
    </details>
  </div>

  <?php elseif ($view === 'reviews'): ?>
  <div class="card action">
    <div class="ttl"><span>Get 5-star reviews</span><span class="badge-you">👆 You pick · we send</span></div>
    <p class="lead2">Finishing a job (in <b>Jobs</b>) asks that customer automatically. You can also ask anyone directly here.</p>
    <div class="rlink <?= $reviewLink ? '' : 'warn' ?>">
      <?php if ($reviewLink): ?>
        <div class="rlrow"><div style="min-width:0"><div class="rllabel">Your Google review link</div><a class="rlurl" href="<?= care_h2($reviewLink) ?>" target="_blank" rel="noopener noreferrer"><?= care_h2($reviewLink) ?></a></div><a class="rltest" href="<?= care_h2($reviewLink) ?>" target="_blank" rel="noopener noreferrer">Test ↗</a></div>
        <details class="rledit"><summary>Not the right link? Fix it</summary><form class="addform" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="set_link"><input name="review_link" value="<?= care_h2($reviewLink) ?>"><button class="go" type="submit">Save link</button></form></details>
      <?php else: ?>
        <div class="rllabel">⚠️ Add your Google review link</div><div class="lead2" style="margin:5px 0 11px">We need it before we can ask for reviews. Find it in Google Business Profile → “Ask for reviews” and paste it here.</div>
        <form class="addform" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="set_link"><input name="review_link" placeholder="https://g.page/r/..." required><button class="go" type="submit">Save my review link</button></form>
      <?php endif; ?>
    </div>
    <div class="preview"><div class="pvlabel">This is exactly what your customers get:</div><div class="bubble"><?= care_h2($reviewMsgPreview) ?></div></div>
    <details class="add"><summary>➕ Ask a specific customer</summary><form class="addform" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_one"><input name="name" placeholder="Customer name (optional)"><input name="phone" inputmode="tel" placeholder="Their phone number" required><button class="go" type="submit">Text them a review request ⭐</button></form></details>
    <details class="add"><summary>📋 Ask several at once</summary><form class="addform" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_jobs"><textarea name="jobs" placeholder="One per line:&#10;John Smith, 555-123-4567&#10;555-987-6543"></textarea><button class="go" type="submit">Send all review requests ⭐</button></form></details>
  </div>
  <?php if ($reviews): ?>
  <div class="card sent">
    <div class="ttl"><span>Recently asked</span></div>
    <div style="margin-top:6px">
      <?php foreach ($reviews as $r): $st=$r['status']; ?>
      <div class="r"><span><?= care_h2($r['customer_name'] ?: $pretty($r['customer_phone'])) ?> <span style="color:#a6bdb8;font-size:12.5px">· <?= care_h2($ago($r['created_at'])) ?></span></span><span class="tag <?= preg_replace('/[^a-z]/','',$st) ?>"><?= $st==='sent'?'asked':care_h2($st) ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php elseif ($view === 'help'): ?>
  <div class="card">
    <div class="ttl"><span>When you can’t pick up</span><span class="badge-auto">⚡ Automatic</span></div>
    <p class="lead2">The second a call rings out, we text the caller back for you — so the job doesn’t walk to a competitor. <b>You do nothing.</b></p>
    <div class="pvlabel" style="margin-top:12px">What we send them, instantly:</div>
    <div class="bubble"><?= care_h2($missedMsg) ?></div>
    <p class="lead2" style="margin-top:11px">If they reply, it lands on your phone and you answer like normal — they only ever see your business number.</p>
  </div>
  <div class="card">
    <div class="ttl"><span>The 4 things Care does</span></div>
    <div class="steps">
      <div class="step"><span>📵</span><div><b>Never miss a lead.</b> Can’t pick up? We instantly text the caller back so the job doesn’t go to a competitor.</div></div>
      <div class="step"><span>💬</span><div><b>You reply like normal.</b> Their text lands on your phone — you answer, and they only ever see your business number.</div></div>
      <div class="step"><span>📋</span><div><b>Track jobs simply.</b> In <b>Jobs</b>, drag a customer to Done when you finish — no CRM needed.</div></div>
      <div class="step"><span>⭐</span><div><b>Get more reviews.</b> Finishing a job auto-texts that customer your Google review link. More reviews = higher on Google.</div></div>
    </div>
  </div>
  <?php if ($role === 'owner'): ?>
  <div class="card">
    <div class="ttl"><span>Your team</span></div>
    <p class="lead2">Add anyone on your team — each gets their <b>own private link</b>. Remove access anytime; nothing is shared.</p>
    <?php foreach (care_listUsers($clientId) as $u): ?>
    <div class="job">
      <div class="jtop">
        <div class="jinfo"><div class="jname"><?= care_h2($u['name'] ?: ($u['role']==='owner'?'Owner':'Team member')) ?> <span class="rolepill"><?= care_h2($u['role']) ?></span></div><?php if ($u['contact']): ?><div class="jmeta"><?= care_h2((string)$u['contact']) ?></div><?php endif; ?></div>
        <?php if ($u['role']!=='owner'): ?><form method="post" style="margin:0" onsubmit="return confirm('Remove this person’s access?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="revoke_user"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><button class="delbtn" type="submit">Remove</button></form><?php endif; ?>
      </div>
      <details class="jedit"><summary>Copy their link</summary><div class="bubble" style="word-break:break-all;font-size:12px"><?= care_h2(CARE_BASE_URL.'/?t='.$u['token']) ?></div></details>
    </div>
    <?php endforeach; ?>
    <details class="add"><summary>➕ Add a team member</summary>
      <form class="addform" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_user">
        <input name="name" placeholder="Their name"><input name="contact" placeholder="Their cell or email (optional)">
        <button class="go" type="submit">Add &amp; create their link</button>
      </form>
    </details>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="ttl"><span>Account</span></div>
    <p class="lead2" style="margin-bottom:12px">Signed in<?= $me['name'] ? ' as ' . care_h2($me['name']) : '' ?> · <?= care_h2($role) ?></p>
    <a class="rltest" href="?logout=1">Sign out</a>
  </div>
  <?php endif; ?>

  <footer>Adverton Care · we run this for you, automatically</footer>
</div></main>

<nav class="botnav"><div class="nv">
  <a href="?view=home" class="<?= $view==='home'?'on':'' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5L12 3l9 6.5"/><path d="M5 10v10h14V10"/></svg><span>Home</span></a>
  <a href="?view=jobs" class="<?= $view==='jobs'?'on':'' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3.5" width="16" height="17" rx="2.5"/><path d="M8 8.5h8M8 12.5h8M8 16.5h5"/></svg><span>Jobs</span></a>
  <a href="?view=reviews" class="<?= $view==='reviews'?'on':'' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l2.6 5.3 5.9.8-4.3 4.1 1 5.8L12 16.6 6.8 19l1-5.8L3.5 9.1l5.9-.8L12 3z"/></svg><span>Reviews</span></a>
  <a href="?view=help" class="<?= $view==='help'?'on':'' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.2a2.5 2.5 0 0 1 4.2 1.3c0 1.3-1.6 1.6-2 2.5"/><circle cx="12" cy="16.6" r=".6" fill="currentColor" stroke="none"/></svg><span>Help</span></a>
</div></nav>
</body></html>
