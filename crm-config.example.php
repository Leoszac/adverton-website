<?php
// Template for CRM secrets. The REAL file lives OUTSIDE public_html on the
// server (recommended path: /home/advertonnet/crm-config.php with chmod 600).
//
// Do NOT commit the real values. Do NOT place the real file inside
// public_html — anything in public_html is web-accessible.
//
// crm/lib/db.php looks for the real file at:
//   1. /home/advertonnet/crm-config.php   (recommended)
//   2. dirname(public_html)/crm-config.php (one level up)
//   3. public_html/crm-config.php          (LAST resort, must chmod 600)

return [
    // MySQL — created in cPanel → MySQL Databases.
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'advertonnet_crm',
    'DB_USER' => 'advertonnet_crmu',
    'DB_PASS' => 'change-me',

    // One-shot user seeding. Set these BEFORE hitting /crm/seed-users.php.
    // After seeding, DELETE the seed-users.php file and clear these values.
    'SEED_TOKEN'         => 'put-a-long-random-string-here-then-delete',
    'SEED_PASS_LEANDRO'  => 'change-this-strong-password',
    'SEED_PASS_VA'       => 'change-this-strong-password',

    // OPTIONAL: webhook fired on every new lead. Slack / Discord / Telegram
    // (via bot URL) / Zapier / n8n / custom — anything that accepts a JSON
    // body with a "text" field will work.
    //   Slack:    https://hooks.slack.com/services/T0.../B0.../...
    //   Discord:  https://discord.com/api/webhooks/.../...
    //   Telegram: needs a small relay (see CRM-SETUP.md)
    // Leave unset to disable.
    'NEW_LEAD_WEBHOOK_URL' => '',

    // Resend API key — required if you want to send emails from the CRM
    // (template "Send" buttons + open/click tracking). Reuse the same key
    // configured in /home/advertonnet/audit-config.php.
    'RESEND_API_KEY'   => '',

    // From + Reply-To used for tracked sends. Domain must be verified in Resend.
    'CRM_FROM_ADDRESS' => 'Adverton <leandro@adverton.net>',
    'CRM_REPLY_TO'     => 'leandro@adverton.net',

    // Calendly Personal Access Token. Generate at: Calendly → Integrations &
    // apps → API and webhooks → Personal Access Tokens. Pulled by
    // cron-calendly.php (every 15 min) to log meetings on matching leads.
    // Leave unset to disable.
    'CALENDLY_API_TOKEN' => '',

    // ===== v5 integrations (Phase 3-6) =====

    // Stripe webhook signing secret. Stripe Dashboard → Developers → Webhooks
    // → endpoint URL = https://adverton.net/crm/stripe-webhook.php
    // Subscribe to: invoice.payment_succeeded, invoice.payment_failed,
    // customer.subscription.deleted, customer.subscription.updated.
    'STRIPE_WEBHOOK_SECRET' => '',

    // PandaDoc legacy webhook (kept for any historical sender — Adverton's
    // pre-contract flow now uses OpenSign instead). Safe to leave empty.
    'PANDADOC_WEBHOOK_SECRET' => '',

    // OpenSign — used by crm/lib/opensign.php to CREATE + SEND the service
    // agreement after a client completes the pre-contract form. ALL FOUR
    // values are managed from /crm/integrations.php (DB-backed); the entries
    // here are just documentation and act as fallback if the DB is empty.
    'OPENSIGN_API_KEY'        => '',  // Settings → API → Create token
    'OPENSIGN_TEMPLATE_ID'    => '',  // Templates → your contract template UUID
    'OPENSIGN_WEBHOOK_SECRET' => '',  // shared token, append ?token=… to webhook URL
    'OPENSIGN_BASE_URL'       => '',  // empty = OpenSign Cloud; set if self-hosted

    // Anthropic API key — used by crm/lib/ai-generator.php to draft client
    // website copy from kickoff intake answers. Console → Settings → API keys.
    'ANTHROPIC_API_KEY'   => '',

    // Master encryption key for crm/lib/credentials.php. 64-character hex
    // (32 raw bytes). Generate locally:
    //     php -r "echo bin2hex(random_bytes(32));"
    // ROTATE means: generate new key, decrypt-then-reencrypt every row in
    // client_credentials with both keys side-by-side. Document procedure
    // before changing this in production.
    'CREDENTIALS_KEY' => '',

    // Namecheap Domain API — for buying domains on behalf of clients when
    // they don't bring their own. Account: Profile → Tools → Namecheap API
    // Access. Whitelist outbound IP first or every call returns 1011102.
    'NAMECHEAP_API_USER'  => '',
    'NAMECHEAP_API_KEY'   => '',
    'NAMECHEAP_CLIENT_IP' => '',     // outbound IP whitelisted on Namecheap
    'NAMECHEAP_SANDBOX'   => false,  // true while testing

    // OpenPhone webhook secret (HMAC-SHA256 over the raw body).
    // Endpoint URL: https://adverton.net/crm/openphone-webhook.php
    'OPENPHONE_WEBHOOK_SECRET' => '',

    // Smartlead / Instantly shared token. Endpoint URL with ?token=<this>:
    //   https://adverton.net/crm/smartlead-webhook.php?token=...
    'SMARTLEAD_WEBHOOK_SECRET' => '',

    // Instantly API key (V2 format: base64-encoded "<workspace_id>:<token>").
    // Generate at: https://app.instantly.ai → Settings → Integrations → API Keys.
    // Used by crm/lib/instantly.php for: listing connected mailboxes,
    // pulling warmup health scores, enrolling leads in campaigns, etc.
    // RECOMMENDED: manage via /crm/integrations.php (DB-backed) — that takes
    // precedence over this file value.
    'INSTANTLY_API_KEY' => '',

    // Web Push (PWA notifications) — VAPID keys.
    // Generate with: web-push generate-vapid-keys (npm) or use https://web-push-codelab.glitch.me/
    // Sending payloads requires installing web-push-php (Composer) — not done by default.
    'VAPID_PUBLIC_KEY'  => '',
    'VAPID_PRIVATE_KEY' => '',
    'VAPID_SUBJECT'     => 'mailto:leandro@adverton.net',
];
