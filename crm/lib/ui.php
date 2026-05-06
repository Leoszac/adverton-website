<?php
// Shared UI bits — header, nav, common styles.

declare(strict_types=1);

if (!defined('CRM_ENTRY')) { http_response_code(404); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/cron_dispatcher.php';

function crm_renderHead(string $title): void {
    ?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0e0d12">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Adverton">
<link rel="manifest" href="/crm/manifest.webmanifest">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<title><?= crm_h($title) ?> — Adverton CRM</title>
<script>if('serviceWorker' in navigator){window.addEventListener('load',()=>navigator.serviceWorker.register('/crm/sw.js').catch(()=>{}));}</script>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5f4f9;color:#0e0d12}
  header.crm{background:#0e0d12;color:#fff;padding:0 22px;display:flex;align-items:center;justify-content:space-between;height:54px;position:sticky;top:0;z-index:10}
  header.crm .brand{font-weight:800;letter-spacing:-.01em;margin-right:18px}
  header.crm nav{display:flex;gap:4px;flex:1}
  header.crm nav a{color:#bcb6ca;text-decoration:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:8px}
  header.crm nav a:hover{color:#fff;background:#1a1820}
  header.crm nav a.cur{color:#fff;background:#1a1820}
  header.crm nav .badge{background:#dc2626;color:#fff;font-size:11px;padding:1px 7px;border-radius:999px;font-weight:700}
  header.crm nav .badge.amber{background:#f59e0b}
  header.crm .right{font-size:13px;color:#bcb6ca}
  header.crm .right a{color:#fff;text-decoration:none;margin-left:14px;border-bottom:1px dotted #6b6877}
  main{max-width:1280px;margin:0 auto;padding:22px}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em}
  .pill.s-new{background:#eef2ff;color:#3730a3}
  .pill.s-contacted{background:#fef3c7;color:#92400e}
  .pill.s-qualified{background:#dcfce7;color:#166534}
  .pill.s-proposal{background:#fae8ff;color:#6b21a8}
  .pill.s-won{background:#16a34a;color:#fff}
  .pill.s-lost{background:#fee2e2;color:#991b1b}
  .pill.t-hot{background:#fee2e2;color:#dc2626}
  .pill.t-warm{background:#fef3c7;color:#a16207}
  .pill.t-cold{background:#e0e7ff;color:#3730a3}
  button{font-family:inherit}
</style>
</head><body><?php
}

function crm_renderHeader(array $user, string $current = ''): void {
    // Background cron tick — runs after the response is sent
    crm_scheduleCronTick();
    $counts = crm_countDueTasks((int)$user['id']);
    $totalDue = $counts['overdue'] + $counts['today'];
    ?>
<header class="crm">
  <div class="brand">Adverton CRM</div>
  <nav>
    <a href="/crm/" class="<?= $current==='leads'?'cur':'' ?>">Leads</a>
    <a href="/crm/pipeline.php" class="<?= $current==='pipeline'?'cur':'' ?>">Pipeline</a>
    <a href="/crm/clients.php" class="<?= $current==='clients'?'cur':'' ?>">Clients</a>
    <a href="/crm/today.php" class="<?= $current==='today'?'cur':'' ?>">
      Today
      <?php if ($counts['overdue']): ?><span class="badge"><?= $counts['overdue'] ?></span>
      <?php elseif ($counts['today']): ?><span class="badge amber"><?= $counts['today'] ?></span>
      <?php endif; ?>
    </a>
    <a href="/crm/reports.php" class="<?= $current==='reports'?'cur':'' ?>">Reports</a>
    <a href="/crm/templates.php" class="<?= $current==='templates'?'cur':'' ?>">Templates</a>
    <?php if (($user['role'] ?? '') === 'founder'): ?>
      <a href="/crm/sequences.php">Sequences</a>
      <a href="/crm/routing.php">Routing</a>
      <a href="/crm/integrations.php">Integrations</a>
    <?php endif; ?>
    <a href="/crm/account.php" title="Account · password · 2FA" style="margin-left:auto;font-size:14px">⚙️</a>
    <?php
    $newCount = crm_newLeadsSinceLastSeen((int)$user['id']);
    if ($newCount > 0): ?>
      <a href="/crm/?since=last" style="margin-left:auto;background:#6d28d9;border-radius:999px">
        🆕 <?= (int)$newCount ?> new
      </a>
    <?php endif; ?>
  </nav>
  <div class="right">
    <span><?= crm_h($user['display_name']) ?></span>
    <a href="/crm/logout.php">Sign out</a>
  </div>
</header><?php
}

function crm_fmtMoney(?float $v): string {
    if ($v === null || $v === 0.0) return '—';
    return '$' . number_format((float)$v, ($v == (int)$v ? 0 : 2));
}

function crm_fmtRelative(?string $ts): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    if (!$t) return crm_h($ts);
    $diff = time() - $t;
    if ($diff < 60)         return 'just now';
    if ($diff < 3600)       return floor($diff/60) . 'm ago';
    if ($diff < 86400)      return floor($diff/3600) . 'h ago';
    if ($diff < 86400*7)    return floor($diff/86400) . 'd ago';
    return date('M j', $t);
}
