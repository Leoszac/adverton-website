<?php
declare(strict_types=1);
define('CRM_ENTRY', 1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/ui.php';

$user = crm_requireRole(['founder']);

// Accept both legacy `?saved=1` (settings save) and any non-empty
// `?saved=<message>` (cron sync passes the summary string).
$savedRaw = (string)($_GET['saved'] ?? '');
$saved    = ($savedRaw !== '');
$savedMsg = ($savedRaw === '1' || $savedRaw === '') ? 'Saved.' : $savedRaw;

// Load current values for each whitelisted key
$cur = [];
foreach (CRM_DB_BACKED_KEYS as $k) {
    $cur[$k] = crm_getSettingMeta($k);
}

$base = 'https://adverton.net';

crm_renderHead('Integrations');
crm_renderHeader($user, '');
?>
<style>
  .wrap{max-width:920px;margin:0 auto}
  h1{margin:0 0 4px;font-size:22px}
  .sub{color:#6b6877;font-size:13px;margin-bottom:18px}
  .saved{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}

  .section{background:#fff;border:1px solid #e7e4ee;border-radius:12px;padding:20px;margin-bottom:14px}
  .section h2{margin:0 0 4px;font-size:16px;color:#0e0d12}
  .section .desc{font-size:13px;color:#6b6877;margin-bottom:14px;line-height:1.5}

  .row{display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start;padding:12px 0;border-top:1px solid #f0eef5}
  .row:first-child{border-top:0}
  .row .meta .name{font-weight:600;font-size:13px;color:#0e0d12}
  .row .meta .help{font-size:12px;color:#6b6877;margin-top:3px;line-height:1.4}
  .row .meta .badge{display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:999px;margin-top:6px}
  .row .meta .badge.set{background:#dcfce7;color:#166534}
  .row .meta .badge.unset{background:#fee2e2;color:#991b1b}

  .row .copy{background:#faf9ff;border:1px solid #e7e4ee;border-radius:6px;padding:6px 10px;font:12px ui-monospace,monospace;color:#0e0d12;display:flex;align-items:center;gap:6px;margin-bottom:6px;word-break:break-all}
  .row .copy button{background:#fff;border:1px solid #e7e4ee;color:#6b6877;font-size:11px;padding:2px 8px;border-radius:4px;cursor:pointer;flex-shrink:0}
  .row .copy button:hover{border-color:#6d28d9;color:#6d28d9}
  .row input[type=text],.row input[type=password],.row input[type=email]{width:100%;background:#fff;border:1px solid #e7e4ee;color:#0e0d12;padding:9px 12px;border-radius:8px;font-size:13px;font-family:ui-monospace,monospace;box-sizing:border-box}

  .actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px}
  button.primary{background:#6d28d9;color:#fff;border:0;padding:11px 22px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer}
  button.primary:hover{background:#5b21b6}
</style>
<main class="wrap">
  <h1>Integrations</h1>
  <div class="sub">Configure third-party webhooks and integration secrets. Saved values override <code>crm-config.php</code>.</div>

  <?php if ($saved): ?><div class="saved"><?= crm_h($savedMsg) ?></div><?php endif; ?>
  <?php $err = (string)($_GET['err'] ?? ''); if ($err): ?>
    <div class="saved" style="background:#fee2e2;color:#991b1b">Validation error: <?= crm_h($err) ?></div>
  <?php endif; ?>

  <form method="post" action="/crm/update.php">
    <input type="hidden" name="mode" value="integration_save">
    <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">

    <!-- ============== Email sending ============== -->
    <div class="section">
      <h2>Email sending (Resend)</h2>
      <div class="desc">Used for tracked sends from /crm/lead.php "Compose with template". Each user can override the From with their own in <a href="/crm/account.php" style="color:#6d28d9">⚙️ Account → Profile</a>; the values here are the global defaults.</div>

      <?php
      $rows = [
        ['RESEND_API_KEY',   'Resend API key',     'password', 're_...',                                      'Get at https://resend.com/api-keys. Required for tracked sending.'],
        ['CRM_FROM_ADDRESS', 'Default From',       'text',     'Adverton <leandro@adverton.net>',             'Used when a sending user has no per-user From set.'],
        ['CRM_REPLY_TO',     'Default Reply-To',   'email',    'leandro@adverton.net',                        'Used when a sending user has no per-user Reply-to set.'],
      ];
      foreach ($rows as [$key,$label,$type,$placeholder,$help]):
        $set = !empty($cur[$key]['set']);
      ?>
        <div class="row">
          <div class="meta">
            <div class="name"><?= crm_h($label) ?></div>
            <div class="help"><?= $help ?></div>
            <span class="badge <?= $set?'set':'unset' ?>"><?= $set?'configured':'not set' ?></span>
          </div>
          <div>
            <input type="<?= $type ?>" name="<?= crm_h($key) ?>" value="<?= crm_h($cur[$key]['value']) ?>" placeholder="<?= crm_h($placeholder) ?>" autocomplete="off">
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- ============== Stripe ============== -->
    <div class="section">
      <h2>Stripe — payment links + status webhook</h2>
      <div class="desc">Once both the <strong>API key</strong> and <strong>webhook secret</strong> are set, the "💳 Send payment link" button on each client page will create a Stripe Checkout subscription and email it. When the customer pays, the webhook auto-updates payment_status / installment_count.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Stripe API secret key</div>
          <div class="help">Stripe → Developers → API keys → Standard keys → Secret key. Starts with <code>sk_live_</code> (or <code>sk_test_</code> while testing). Used to create Customers + Checkout Sessions.</div>
          <span class="badge <?= $cur['STRIPE_API_KEY']['set']?'set':'unset' ?>"><?= $cur['STRIPE_API_KEY']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="STRIPE_API_KEY" value="<?= crm_h($cur['STRIPE_API_KEY']['value']) ?>" placeholder="sk_live_..." autocomplete="off"></div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Webhook URL (paste in Stripe dashboard)</div>
          <div class="help">Stripe → Developers → Webhooks → Add endpoint. Subscribe to: <code>checkout.session.completed</code>, <code>invoice.payment_succeeded</code>, <code>invoice.payment_failed</code>, <code>customer.subscription.deleted</code>, <code>customer.subscription.updated</code>.</div>
        </div>
        <div>
          <div class="copy"><span><?= $base ?>/crm/stripe-webhook.php</span><button type="button" onclick="copy(this,'<?= $base ?>/crm/stripe-webhook.php')">Copy</button></div>
        </div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Webhook signing secret</div>
          <div class="help">Shown after creating the endpoint, starts with <code>whsec_</code>.</div>
          <span class="badge <?= $cur['STRIPE_WEBHOOK_SECRET']['set']?'set':'unset' ?>"><?= $cur['STRIPE_WEBHOOK_SECRET']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="STRIPE_WEBHOOK_SECRET" value="<?= crm_h($cur['STRIPE_WEBHOOK_SECRET']['value']) ?>" placeholder="whsec_..." autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== PandaDoc ============== -->
    <div class="section">
      <h2>PandaDoc — auto-promote on contract sign</h2>
      <div class="desc">When the client signs the proposal in PandaDoc, the matching lead auto-bumps to <code>won</code> (creating a client record), and an onboarding task is assigned to the operator.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Webhook URL (paste in PandaDoc)</div>
          <div class="help">PandaDoc → Settings → Webhooks. Subscribe to <code>document_state_changed</code>. Append <code>?token=YOUR_SECRET</code> to the URL when configuring (PandaDoc free tier doesn't HMAC, so we use a shared token).</div>
        </div>
        <div>
          <div class="copy"><span><?= $base ?>/crm/pandadoc-webhook.php?token=YOUR_SECRET</span><button type="button" onclick="copy(this,'<?= $base ?>/crm/pandadoc-webhook.php?token=YOUR_SECRET')">Copy</button></div>
        </div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Shared token (any random 32+ chars)</div>
          <span class="badge <?= $cur['PANDADOC_WEBHOOK_SECRET']['set']?'set':'unset' ?>"><?= $cur['PANDADOC_WEBHOOK_SECRET']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="PANDADOC_WEBHOOK_SECRET" value="<?= crm_h($cur['PANDADOC_WEBHOOK_SECRET']['value']) ?>" placeholder="random-32-chars" autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== DNC Scrub (cold calling) ============== -->
    <div class="section">
      <h2>DNC scrub — cold-calling compliance</h2>
      <div class="desc">Every phone imported into <a href="/crm/cold-prospects-import.php" style="color:#6d28d9">Cold Prospects</a> is checked against the National DNC Registry (federal + state lists + wireless flag + litigator list). Without an API key, the system runs in <strong>stub mode</strong> (marks everything <code>clean</code>) — do NOT cold-call until this is configured.</div>

      <div class="row">
        <div class="meta">
          <div class="name">DNCScrub API key</div>
          <div class="help">Sign up at <a href="https://www.dncscrub.com" target="_blank" rel="noopener">DNCScrub.com</a> (Contact Center Compliance) or equivalent vendor. Pricing ~$0.005&ndash;$0.015/number. Required for legal cold-calling.</div>
          <span class="badge <?= $cur['DNCSCRUB_API_KEY']['set']?'set':'unset' ?>"><?= $cur['DNCSCRUB_API_KEY']['set']?'configured':'STUB MODE' ?></span>
        </div>
        <div><input type="password" name="DNCSCRUB_API_KEY" value="<?= crm_h($cur['DNCSCRUB_API_KEY']['value']) ?>" placeholder="paste API token here" autocomplete="off"></div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">DNCScrub API URL (optional override)</div>
          <div class="help">Default: <code>https://api.dncscrub.com/v1/scrub</code>. Override only if using a different vendor (PossibleNOW, DNC.com, etc.) with a different endpoint.</div>
          <span class="badge <?= $cur['DNCSCRUB_API_URL']['set']?'set':'unset' ?>"><?= $cur['DNCSCRUB_API_URL']['set']?'overridden':'default' ?></span>
        </div>
        <div><input type="text" name="DNCSCRUB_API_URL" value="<?= crm_h($cur['DNCSCRUB_API_URL']['value']) ?>" placeholder="https://api.dncscrub.com/v1/scrub" autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Outscraper ============== -->
    <div class="section">
      <h2>Outscraper — prospect sourcing</h2>
      <div class="desc">Pulls contractor lists (Google Maps search + email enrichment + validation) for cold-email batches. Get the key at <a href="https://app.outscraper.com/profile" target="_blank" rel="noopener">app.outscraper.com → Profile → API key</a>. Paste it once here — sourcing scripts read it automatically (no re-pasting).</div>
      <div class="row">
        <div class="meta">
          <div class="name">Outscraper API key</div>
          <div class="help">Sent as the <code>X-API-KEY</code> header to <code>api.app.outscraper.com</code>.</div>
          <span class="badge <?= $cur['OUTSCRAPER_API_KEY']['set']?'set':'unset' ?>"><?= $cur['OUTSCRAPER_API_KEY']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="OUTSCRAPER_API_KEY" value="<?= crm_h($cur['OUTSCRAPER_API_KEY']['value']) ?>" placeholder="paste Outscraper API key" autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== OpenPhone ============== -->
    <div class="section">
      <h2>OpenPhone — auto-log calls + SMS</h2>
      <div class="desc">Inbound + outbound calls and SMS get logged to the matching lead automatically (matched by phone). Inbound from unknown numbers creates a new lead with <code>source = inbound_call</code>.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Webhook URL</div>
          <div class="help">OpenPhone → Settings → Integrations → Webhooks. Subscribe to <code>call.completed</code>, <code>call.recording.completed</code>, <code>message.received</code>, <code>message.delivered</code>.</div>
        </div>
        <div>
          <div class="copy"><span><?= $base ?>/crm/openphone-webhook.php</span><button type="button" onclick="copy(this,'<?= $base ?>/crm/openphone-webhook.php')">Copy</button></div>
        </div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Webhook signing secret (HMAC-SHA256)</div>
          <span class="badge <?= $cur['OPENPHONE_WEBHOOK_SECRET']['set']?'set':'unset' ?>"><?= $cur['OPENPHONE_WEBHOOK_SECRET']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="OPENPHONE_WEBHOOK_SECRET" value="<?= crm_h($cur['OPENPHONE_WEBHOOK_SECRET']['value']) ?>" placeholder="..." autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Smartlead / Instantly ============== -->
    <div class="section">
      <h2>Smartlead / Instantly — cold email tracking</h2>
      <div class="desc">When a prospect opens or replies to a cold email, the activity gets logged on the lead. On reply, the lead bumps to <code>contacted</code> and any active sequence is unenrolled.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Webhook URL</div>
          <div class="help">In your cold-email tool, subscribe to open/reply/bounce events.</div>
        </div>
        <div>
          <div class="copy"><span><?= $base ?>/crm/smartlead-webhook.php?token=YOUR_SECRET</span><button type="button" onclick="copy(this,'<?= $base ?>/crm/smartlead-webhook.php?token=YOUR_SECRET')">Copy</button></div>
        </div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Shared token</div>
          <span class="badge <?= $cur['SMARTLEAD_WEBHOOK_SECRET']['set']?'set':'unset' ?>"><?= $cur['SMARTLEAD_WEBHOOK_SECRET']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="SMARTLEAD_WEBHOOK_SECRET" value="<?= crm_h($cur['SMARTLEAD_WEBHOOK_SECRET']['value']) ?>" placeholder="random-32-chars" autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Instantly API ============== -->
    <div class="section">
      <h2>Instantly — cold email API</h2>
      <div class="desc">Pulls connected mailbox status + warmup health, lists campaigns, and (later) enrolls leads programmatically. Used by <code>crm/lib/instantly.php</code>. After saving, click <strong>Test connection</strong> to verify.</div>

      <div class="row">
        <div class="meta">
          <div class="name">API key (V2)</div>
          <div class="help">Generate at: app.instantly.ai → Settings → Integrations → API Keys → Create. Key is base64-encoded <code>workspace_id:token</code>.</div>
          <span class="badge <?= $cur['INSTANTLY_API_KEY']['set']?'set':'unset' ?>"><?= $cur['INSTANTLY_API_KEY']['set']?'configured':'not set' ?></span>
        </div>
        <div>
          <input type="password" name="INSTANTLY_API_KEY" value="<?= crm_h($cur['INSTANTLY_API_KEY']['value']) ?>" placeholder="NDY5...==" autocomplete="off">
          <div style="margin-top:6px;font-size:12px"><a href="/crm/instantly-test.php" target="_blank">Test connection &amp; show mailboxes →</a></div>
        </div>
      </div>

      <div class="row">
        <div class="meta">
          <div class="name">Webhook URL (replies → CRM)</div>
          <div class="help">In Instantly: Settings → Integrations → Webhooks → Create. Subscribe to: <code>email_reply</code>, <code>email_bounced</code>, <code>email_opened</code>, <code>email_link_clicked</code>. Replies auto-create leads with source <code>cold_email_instantly</code>.</div>
        </div>
        <div>
          <div class="copy"><span><?= $base ?>/crm/instantly-webhook.php?token=YOUR_SECRET</span><button type="button" onclick="copy(this,'<?= $base ?>/crm/instantly-webhook.php?token=YOUR_SECRET')">Copy</button></div>
        </div>
      </div>

      <div class="row">
        <div class="meta">
          <div class="name">Webhook shared token</div>
          <div class="help">Generate any random 32+ chars (e.g., <code>php -r "echo bin2hex(random_bytes(16));"</code>) and paste here AND in the Instantly webhook URL above.</div>
          <span class="badge <?= $cur['INSTANTLY_WEBHOOK_SECRET']['set']?'set':'unset' ?>"><?= $cur['INSTANTLY_WEBHOOK_SECRET']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="INSTANTLY_WEBHOOK_SECRET" value="<?= crm_h($cur['INSTANTLY_WEBHOOK_SECRET']['value']) ?>" placeholder="random-32-chars" autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Outbound notification ============== -->
    <div class="section">
      <h2>New-lead notification (Slack / Discord / Telegram)</h2>
      <div class="desc">When a new lead is captured (audit form, contact form, manual, inbound call), POST a payload to this URL. Compatible with Slack/Discord incoming webhooks. For Telegram, point at a relay (Pipedream/n8n) that forwards to <code>sendMessage</code>.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Webhook URL (where to send)</div>
          <span class="badge <?= $cur['NEW_LEAD_WEBHOOK_URL']['set']?'set':'unset' ?>"><?= $cur['NEW_LEAD_WEBHOOK_URL']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="text" name="NEW_LEAD_WEBHOOK_URL" value="<?= crm_h($cur['NEW_LEAD_WEBHOOK_URL']['value']) ?>" placeholder="https://hooks.slack.com/services/..." autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Calendly ============== -->
    <div class="section">
      <h2>Calendly — meeting auto-log</h2>
      <div class="desc">Polls scheduled events every 15 min via Calendly API and logs meetings on matching leads (matched by email). Bumps lead to <code>qualified</code> and creates a "Prep meeting" task 1 hour before the call.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Personal Access Token</div>
          <div class="help">Calendly → Integrations &amp; apps → API and webhooks → Personal Access Tokens. Required scopes: <code>scheduled_events:read</code>, <code>event_types:read</code>.</div>
          <span class="badge <?= $cur['CALENDLY_API_TOKEN']['set']?'set':'unset' ?>"><?= $cur['CALENDLY_API_TOKEN']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="CALENDLY_API_TOKEN" value="<?= crm_h($cur['CALENDLY_API_TOKEN']['value']) ?>" placeholder="eyJraWQi..." autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Anthropic (AI generator + Vision) ============== -->
    <div class="section">
      <h2>Anthropic — AI copy generator + photo classification</h2>
      <div class="desc">Used by the kickoff wizard to generate website copy from intake answers (<code>crm/lib/ai-generator.php</code>) and to classify photos sent to <code>assets@adverton.net</code> (<code>crm/lib/photos.php</code>). Sin esta key, ambas funciones devuelven error suave.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Anthropic API key</div>
          <div class="help">Anthropic Console → Settings → API keys. Empieza con <code>sk-ant-</code>. El modelo usado es <code>claude-sonnet-4-6</code> (mismo billing tier que Opus).</div>
          <span class="badge <?= $cur['ANTHROPIC_API_KEY']['set']?'set':'unset' ?>"><?= $cur['ANTHROPIC_API_KEY']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="ANTHROPIC_API_KEY" value="<?= crm_h($cur['ANTHROPIC_API_KEY']['value']) ?>" placeholder="sk-ant-..." autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Pre-contract → Stripe Checkout (click-wrap T&C) ============== -->
    <div class="section">
      <h2>Pre-contract → Stripe Checkout (click-wrap)</h2>
      <div class="desc">Cuando el cliente completa el pre-contract form, Adverton arma una <strong>Stripe Checkout session</strong> con la casilla "I agree to the Service Agreement" requerida + el plan de subscription, y le manda el link por email. Click + payment = aceptación legalmente vinculante (clic-wrap, válido en US para SaaS sub-$1k/mo). Sin tools de eSignature externas mientras Adverton arranca.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Service Agreement link</div>
          <div class="help">Embedded directly in Stripe's consent checkbox via markdown — no Dashboard setting needed. Edit <code>legal/service-agreement.html</code> in the repo to update the actual agreement text.</div>
        </div>
        <div>
          <div class="copy"><span><?= $base ?>/legal/service-agreement.html</span><button type="button" onclick="copy(this,'<?= $base ?>/legal/service-agreement.html')">Copy</button></div>
        </div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Upgrade path</div>
          <div class="help">Si en el futuro querés firma formal con audit trail granular (ej. cliente enterprise): el lib <code>crm/lib/opensign.php</code> queda dormido en el repo, listo para activarse pegando una API key de OpenSign Paid (~\$9.99/mo) o self-hosted.</div>
        </div>
        <div style="font-size:13px;color:#6b6877;line-height:1.5">No fields needed — el flow usa la <code>STRIPE_API_KEY</code> ya configurada arriba.</div>
      </div>
    </div>

    <!-- ============== Credentials vault master key ============== -->
    <div class="section">
      <h2>Credentials vault — master encryption key</h2>
      <div class="desc">Usada por <code>crm/lib/credentials.php</code> para cifrar/descifrar passwords del cliente (cPanel, SFTP, GBP, LSA, etc.) con AES-256-CBC. Una vez seteada y con credenciales guardadas, <strong>no la cambies</strong> — perderías acceso a todo el vault.</div>

      <div class="row">
        <div class="meta">
          <div class="name">Master key (64-char hex)</div>
          <div class="help">⚠️ <strong>Generala UNA vez y no la cambies después de guardar credenciales</strong>. Para generar una nueva: <code>php -r "echo bin2hex(random_bytes(32));"</code>. Si está vacía, podés guardar/leer todo en plaintext mientras evaluás — el vault está deshabilitado hasta que setees una.</div>
          <span class="badge <?= $cur['CREDENTIALS_KEY']['set']?'set':'unset' ?>"><?= $cur['CREDENTIALS_KEY']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="CREDENTIALS_KEY" value="<?= crm_h($cur['CREDENTIALS_KEY']['value']) ?>" placeholder="64-char hex..." autocomplete="off"></div>
      </div>
    </div>

    <!-- ============== Namecheap (optional fallback domain registrar) ============== -->
    <div class="section">
      <h2>Namecheap — fallback domain registrar (optional)</h2>
      <div class="desc">Sólo necesario si Adverton va a comprar dominios para clientes que aún no tienen uno. Si todos tus clientes ya tienen su .com, dejá esto vacío.</div>

      <div class="row">
        <div class="meta">
          <div class="name">API user</div>
          <div class="help">Namecheap → Profile → Tools → API Access. El "API User" suele ser igual al username de tu cuenta.</div>
          <span class="badge <?= $cur['NAMECHEAP_API_USER']['set']?'set':'unset' ?>"><?= $cur['NAMECHEAP_API_USER']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="text" name="NAMECHEAP_API_USER" value="<?= crm_h($cur['NAMECHEAP_API_USER']['value']) ?>" placeholder="advertonnet" autocomplete="off"></div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">API key</div>
          <span class="badge <?= $cur['NAMECHEAP_API_KEY']['set']?'set':'unset' ?>"><?= $cur['NAMECHEAP_API_KEY']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="password" name="NAMECHEAP_API_KEY" value="<?= crm_h($cur['NAMECHEAP_API_KEY']['value']) ?>" placeholder="..." autocomplete="off"></div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Whitelisted client IP</div>
          <div class="help">El IP saliente de adverton.net que tenés que whitelistar en Namecheap para que la API responda. Si lo dejás vacío, el lib usa <code>$_SERVER['SERVER_ADDR']</code> automáticamente.</div>
          <span class="badge <?= $cur['NAMECHEAP_CLIENT_IP']['set']?'set':'unset' ?>"><?= $cur['NAMECHEAP_CLIENT_IP']['set']?'configured':'not set' ?></span>
        </div>
        <div><input type="text" name="NAMECHEAP_CLIENT_IP" value="<?= crm_h($cur['NAMECHEAP_CLIENT_IP']['value']) ?>" placeholder="123.45.67.89" autocomplete="off"></div>
      </div>
      <div class="row">
        <div class="meta">
          <div class="name">Use sandbox API</div>
          <div class="help">Pone <code>1</code> mientras testeás (no compras reales). Vacío = producción.</div>
          <span class="badge <?= $cur['NAMECHEAP_SANDBOX']['set']?'set':'unset' ?>"><?= $cur['NAMECHEAP_SANDBOX']['set']?'sandbox':'live' ?></span>
        </div>
        <div><input type="text" name="NAMECHEAP_SANDBOX" value="<?= crm_h($cur['NAMECHEAP_SANDBOX']['value']) ?>" placeholder="(empty = live, 1 = sandbox)" autocomplete="off"></div>
      </div>
    </div>

    <div class="actions">
      <button type="submit" class="primary">Save all integrations</button>
    </div>
  </form>

  <!-- ============== Cron jobs (server-side scheduled tasks) ============== -->
  <div class="section" style="margin-top:24px">
    <h2>Cron jobs — server-side scheduled tasks</h2>
    <div class="desc">
      Adverton runs 8 managed crons (nurture sequences, Calendly sync, client triggers, lost re-engagement, health score, Instantly health, watchdog, DNC rescrub).
      Click <strong>Sync cron jobs</strong> to install / repair them on the server crontab in one shot. Idempotent: preserves any unrelated lines, replaces only the managed ones, sets <code>CRON_TZ=America/New_York</code> so schedules fire at ET wall-clock.
      Current status is always at <a href="/crm/_health.php" target="_blank">/crm/_health.php</a>.
    </div>
    <form method="post" action="/crm/update.php" style="margin-top:10px"
          onsubmit="return confirm('Sync the 8 managed cron lines (+ CRON_TZ header) on the server crontab?\\n\\nPreserves any unrelated entries.');">
      <input type="hidden" name="csrf" value="<?= crm_h(crm_csrfToken()) ?>">
      <input type="hidden" name="mode" value="sync_crontab">
      <button type="submit" class="primary">🔧 Sync cron jobs</button>
    </form>
  </div>
</main>
<script>
function copy(btn, txt){
  navigator.clipboard.writeText(txt).then(() => {
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = orig, 1500);
  });
}
</script>
</body></html>
