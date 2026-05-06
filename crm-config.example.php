<?php
// Template for CRM secrets. The REAL file lives OUTSIDE public_html on the
// server (recommended path: /home2/advertonnet/crm-config.php with chmod 600).
//
// Do NOT commit the real values. Do NOT place the real file inside
// public_html — anything in public_html is web-accessible.
//
// crm/lib/db.php looks for the real file at:
//   1. /home2/advertonnet/crm-config.php   (recommended)
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
    // configured in /home2/advertonnet/audit-config.php.
    'RESEND_API_KEY'   => '',

    // From + Reply-To used for tracked sends. Domain must be verified in Resend.
    'CRM_FROM_ADDRESS' => 'Adverton <leandro@adverton.net>',
    'CRM_REPLY_TO'     => 'leandro@adverton.net',

    // Calendly iCal feed URL. Find at: Calendly → Account → Calendar
    // connections → "Get iCal feed". Pulled by cron-calendly.php (cron job).
    // Leave unset to disable.
    'CALENDLY_ICAL_URL' => '',

    // ===== v5 integrations (Phase 3-6) =====

    // Stripe webhook signing secret. Stripe Dashboard → Developers → Webhooks
    // → endpoint URL = https://adverton.net/crm/stripe-webhook.php
    // Subscribe to: invoice.payment_succeeded, invoice.payment_failed,
    // customer.subscription.deleted, customer.subscription.updated.
    'STRIPE_WEBHOOK_SECRET' => '',

    // PandaDoc shared token. We don't validate the request body cryptographically
    // (PandaDoc free tier doesn't HMAC), so use ?token=<this> on the webhook URL.
    // Endpoint URL: https://adverton.net/crm/pandadoc-webhook.php?token=...
    'PANDADOC_WEBHOOK_SECRET' => '',

    // OpenPhone webhook secret (HMAC-SHA256 over the raw body).
    // Endpoint URL: https://adverton.net/crm/openphone-webhook.php
    'OPENPHONE_WEBHOOK_SECRET' => '',

    // Smartlead / Instantly shared token. Endpoint URL with ?token=<this>:
    //   https://adverton.net/crm/smartlead-webhook.php?token=...
    'SMARTLEAD_WEBHOOK_SECRET' => '',

    // Web Push (PWA notifications) — VAPID keys.
    // Generate with: web-push generate-vapid-keys (npm) or use https://web-push-codelab.glitch.me/
    // Sending payloads requires installing web-push-php (Composer) — not done by default.
    'VAPID_PUBLIC_KEY'  => '',
    'VAPID_PRIVATE_KEY' => '',
    'VAPID_SUBJECT'     => 'mailto:leandro@adverton.net',
];
