<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireLogin();
$db   = crm_db();

// ---- Per-sequence breakdown ----
$sequences = $db->query(
    "SELECT id, name, trigger_event, trigger_value, active FROM sequences ORDER BY name ASC"
)->fetchAll();

$bySeq = [];
foreach ($sequences as $s) {
    $sid = (int)$s['id'];
    $stats = [
        'sequence' => $s,
        'enrolled_total' => 0,
        'in_flight'      => 0,
        'completed_no_reply' => 0,
        'replied_or_hot' => 0,
        'won'            => 0,
        'lost'           => 0,
        'dnc'            => 0,
        'lead_gone'      => 0,
    ];

    // Total enrollments and breakdown by status/reason
    $stmt = $db->prepare(
        'SELECT completed_at, unenrolled_reason, COUNT(*) AS n
         FROM sequence_enrollments WHERE sequence_id = ?
         GROUP BY completed_at IS NULL, unenrolled_reason'
    );
    $stmt->execute([$sid]);
    foreach ($stmt->fetchAll() as $row) {
        $n = (int)$row['n'];
        $stats['enrolled_total'] += $n;
        if ($row['completed_at'] === null) {
            $stats['in_flight'] += $n;
        } else {
            $r = (string)($row['unenrolled_reason'] ?? '');
            if ($r === 'completed')                 $stats['completed_no_reply'] += $n;
            elseif ($r === 'engagement_hot' || $r === 'replied') $stats['replied_or_hot'] += $n;
            elseif ($r === 'status_won')            $stats['won']  += $n;
            elseif ($r === 'status_lost')           $stats['lost'] += $n;
            elseif ($r === 'dnc')                   $stats['dnc']  += $n;
            elseif ($r === 'lead_gone')             $stats['lead_gone'] += $n;
            else                                    $stats['completed_no_reply'] += $n;
        }
    }

    // Email send metrics across all enrollments of this sequence
    $stmt = $db->prepare(
        'SELECT COUNT(es.id) AS sent,
                SUM(CASE WHEN es.first_opened_at  IS NOT NULL THEN 1 ELSE 0 END) AS opens,
                SUM(CASE WHEN es.first_clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicks
         FROM email_sends es
         JOIN sequence_enrollments se ON se.lead_id = es.lead_id
         WHERE se.sequence_id = ? AND es.template_id IN (
            SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, "$.template_id"))
            FROM sequence_steps WHERE sequence_id = ? AND action = "send_template"
         )'
    );
    try {
        $stmt->execute([$sid, $sid]);
        $em = $stmt->fetch();
        $stats['emails_sent']   = (int)($em['sent']   ?? 0);
        $stats['unique_opens']  = (int)($em['opens']  ?? 0);
        $stats['unique_clicks'] = (int)($em['clicks'] ?? 0);
    } catch (Throwable $e) {
        // Fallback if MySQL JSON functions not available
        $stats['emails_sent'] = $stats['unique_opens'] = $stats['unique_clicks'] = 0;
    }

    $bySeq[] = $stats;
}

// ---- Source funnel (lead magnet → conversion) ----
$sources = $db->query(
    "SELECT source,
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'new'        THEN 1 ELSE 0 END) AS s_new,
            SUM(CASE WHEN status = 'contacted'  THEN 1 ELSE 0 END) AS s_contacted,
            SUM(CASE WHEN status = 'qualified'  THEN 1 ELSE 0 END) AS s_qualified,
            SUM(CASE WHEN status = 'proposal'   THEN 1 ELSE 0 END) AS s_proposal,
            SUM(CASE WHEN status = 'won'        THEN 1 ELSE 0 END) AS s_won,
            SUM(CASE WHEN status = 'lost'       THEN 1 ELSE 0 END) AS s_lost
     FROM leads GROUP BY source ORDER BY total DESC"
)->fetchAll();

// ---- Engagement scoring impact ----
$tempBumps = (int) $db->query(
    "SELECT COUNT(*) AS n FROM lead_activities
     WHERE type = 'system' AND disposition = 'temperature_bumped'"
)->fetch()['n'];

$hotPromoted = (int) $db->query(
    "SELECT COUNT(*) AS n FROM sequence_enrollments
     WHERE unenrolled_reason = 'engagement_hot'"
)->fetch()['n'];

crm_renderHead('Nurture stats');
crm_renderHeader($user, 'reports');
?>
<style>
  .ns-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
  @media (max-width:880px){ .ns-kpis{grid-template-columns:repeat(2,1fr)} }
  .ns-kpi{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:16px}
  .ns-kpi .label{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700;margin-bottom:6px}
  .ns-kpi .value{font-size:24px;font-weight:800;letter-spacing:-.01em;color:#0e0d12}
  .ns-kpi .sub{font-size:12px;color:#6b6877;margin-top:2px}

  .ns-card{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:18px;margin-bottom:14px}
  .ns-card h2{margin:0 0 14px;font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877}

  .ns-seq{padding:14px 0;border-top:1px solid #f0eef5}
  .ns-seq:first-child{border-top:0;padding-top:0}
  .ns-seq h3{margin:0 0 10px;font-size:15px;color:#0e0d12;font-weight:700}
  .ns-seq .meta{font-size:12px;color:#6b6877;margin-bottom:10px}
  .ns-seq .badge{display:inline-block;background:#f3eeff;color:#5b21b6;font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px;margin-right:6px}
  .ns-seq .badge.off{background:#fef2f2;color:#991b1b}
  .ns-funnel{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
  .ns-funnel .seg{flex:1;min-width:90px;background:#faf9ff;border:1px solid #ede9fe;border-radius:8px;padding:10px}
  .ns-funnel .seg .v{font-size:18px;font-weight:700;color:#0e0d12;line-height:1}
  .ns-funnel .seg .l{font-size:11px;color:#6b6877;text-transform:uppercase;letter-spacing:.06em;margin-top:4px}
  .ns-funnel .seg.hot{background:#fff7ed;border-color:#fed7aa}
  .ns-funnel .seg.hot .v{color:#c2410c}
  .ns-funnel .seg.won{background:#f0fdf4;border-color:#bbf7d0}
  .ns-funnel .seg.won .v{color:#15803d}
  .ns-rates{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px;padding-top:10px;border-top:1px dashed #f0eef5}
  .ns-rate{font-size:13px}
  .ns-rate .v{font-weight:700;color:#5b21b6}

  table.src{width:100%;border-collapse:collapse}
  table.src th,table.src td{padding:8px 10px;text-align:left;font-size:13px;border-bottom:1px solid #f0eef5}
  table.src th{font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#6b6877;font-weight:700}
  table.src td.num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
  table.src tr:last-child td{border-bottom:0}
  .pct{color:#6b6877;font-size:11px;margin-left:4px}
</style>
<main>
  <div class="ns-kpis">
    <div class="ns-kpi">
      <div class="label">Active sequences</div>
      <div class="value"><?= count(array_filter($bySeq, fn($s) => (int)$s['sequence']['active'] === 1)) ?></div>
      <div class="sub">of <?= count($bySeq) ?> total</div>
    </div>
    <div class="ns-kpi">
      <div class="label">In nurture right now</div>
      <div class="value"><?= array_sum(array_column($bySeq, 'in_flight')) ?></div>
      <div class="sub">leads receiving emails</div>
    </div>
    <div class="ns-kpi">
      <div class="label">Promoted to HOT via engagement</div>
      <div class="value"><?= $hotPromoted ?></div>
      <div class="sub"><?= $tempBumps ?> total temperature bumps</div>
    </div>
    <div class="ns-kpi">
      <div class="label">Won from lead-magnet leads</div>
      <div class="value"><?= array_sum(array_column($bySeq, 'won')) ?></div>
      <div class="sub">attributed to nurture sequences</div>
    </div>
  </div>

  <div class="ns-card">
    <h2>Per-sequence breakdown</h2>
    <?php foreach ($bySeq as $st): $seq = $st['sequence']; ?>
      <div class="ns-seq">
        <h3><?= crm_h($seq['name']) ?></h3>
        <div class="meta">
          <span class="badge<?= $seq['active'] ? '' : ' off' ?>"><?= $seq['active'] ? 'Active' : 'Paused' ?></span>
          <span class="badge"><?= crm_h($seq['trigger_event']) ?></span>
          <?php if ($seq['trigger_value']): ?>
            <span class="badge">value=<?= crm_h($seq['trigger_value']) ?></span>
          <?php endif; ?>
        </div>

        <div class="ns-funnel">
          <div class="seg">
            <div class="v"><?= $st['enrolled_total'] ?></div>
            <div class="l">enrolled</div>
          </div>
          <div class="seg">
            <div class="v"><?= $st['in_flight'] ?></div>
            <div class="l">in flight</div>
          </div>
          <div class="seg">
            <div class="v"><?= $st['completed_no_reply'] ?></div>
            <div class="l">completed (no reply)</div>
          </div>
          <div class="seg hot">
            <div class="v"><?= $st['replied_or_hot'] ?></div>
            <div class="l">replied / hot</div>
          </div>
          <div class="seg won">
            <div class="v"><?= $st['won'] ?></div>
            <div class="l">won</div>
          </div>
        </div>

        <?php if ($st['enrolled_total'] > 0): ?>
          <?php
            $sent = max(1, $st['emails_sent']);
            $openRate  = $sent > 0 ? ($st['unique_opens']  / $sent) : 0;
            $clickRate = $sent > 0 ? ($st['unique_clicks'] / $sent) : 0;
            $convRate  = $st['enrolled_total'] > 0 ? ($st['won'] / $st['enrolled_total']) : 0;
          ?>
          <div class="ns-rates">
            <div class="ns-rate">Open rate <span class="v"><?= number_format($openRate*100, 1) ?>%</span> <span class="pct">(<?= $st['unique_opens'] ?> / <?= $st['emails_sent'] ?> sent)</span></div>
            <div class="ns-rate">Click rate <span class="v"><?= number_format($clickRate*100, 1) ?>%</span> <span class="pct">(<?= $st['unique_clicks'] ?>)</span></div>
            <div class="ns-rate">Won rate <span class="v"><?= number_format($convRate*100, 1) ?>%</span> <span class="pct">(<?= $st['won'] ?> / <?= $st['enrolled_total'] ?>)</span></div>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($bySeq)): ?>
      <div style="color:#6b6877;font-size:13px">No sequences defined yet. Create one in <a href="sequences.php" style="color:#6d28d9;font-weight:600">Sequences</a>.</div>
    <?php endif; ?>
  </div>

  <div class="ns-card">
    <h2>Conversion by source</h2>
    <table class="src">
      <thead>
        <tr>
          <th>Source</th>
          <th class="num">Total</th>
          <th class="num">Open pipeline</th>
          <th class="num">Won</th>
          <th class="num">Lost</th>
          <th class="num">Win rate</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($sources as $r):
            $open = (int)$r['s_new'] + (int)$r['s_contacted'] + (int)$r['s_qualified'] + (int)$r['s_proposal'];
            $tot = (int)$r['total'];
            $wr = $tot > 0 ? ((int)$r['s_won'] / $tot) : 0;
        ?>
          <tr>
            <td><strong><?= crm_h(crm_sourceLabel($r['source'])) ?></strong></td>
            <td class="num"><?= $tot ?></td>
            <td class="num"><?= $open ?></td>
            <td class="num" style="color:#15803d"><?= (int)$r['s_won'] ?></td>
            <td class="num" style="color:#991b1b"><?= (int)$r['s_lost'] ?></td>
            <td class="num"><?= number_format($wr*100, 1) ?>%</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="ns-card" style="background:#faf9ff">
    <h2>How to read this</h2>
    <p style="font-size:13px;color:#383640;line-height:1.6;margin:0 0 8px">
      <strong>In flight:</strong> leads currently receiving nurture emails.
      <strong>Completed (no reply):</strong> got the full sequence and didn't reply — manual follow-up task auto-created.
      <strong>Replied / hot:</strong> auto-unenrolled because they replied to an email or hit the engagement-scoring threshold (1 click or 4 opens).
      <strong>Won:</strong> closed deals from leads that went through this sequence.
    </p>
    <p style="font-size:13px;color:#383640;line-height:1.6;margin:0">
      <strong>Open rate target:</strong> 40-55% (industry average for cold lists is 20-25%; we're sending to opted-in lead magnet downloaders).
      <strong>Click rate target:</strong> 4-8%.
      <strong>Won rate target:</strong> 2-5% over 60-90 days. Below that, the copy needs work.
    </p>
  </div>
</main>
