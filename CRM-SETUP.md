# Adverton CRM — One-time setup on cPanel

Follow these steps once. After that, the CRM lives at https://adverton.net/crm/
and every audit / contact form submission will be persisted to MySQL automatically.

## 1. Create the MySQL database (cPanel UI)

cPanel → **MySQL® Databases**:

1. **New database**: `advertonnet_crm` → click *Create Database*.
   (cPanel will prefix your account, so the real name becomes something like `advertonn_crm` — note it down.)
2. **New user**: pick a username (e.g. `crmu`) and generate a strong password (save it).
   The real user becomes `advertonn_crmu` — note it down.
3. **Add user to database** with **ALL PRIVILEGES**.

## 2. Place the secret config OUTSIDE public_html

cPanel → **File Manager** → navigate one level above `public_html` (i.e. `/home2/advertonnet/`).

Create `crm-config.php` with this content (use the exact DB name + user from step 1):

```php
<?php
return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'advertonn_crm',         // ← replace with your real DB name
    'DB_USER' => 'advertonn_crmu',        // ← replace with your real DB user
    'DB_PASS' => 'YOUR_STRONG_DB_PASSWORD',

    // Used ONCE for the seeding step. Delete these 3 lines afterwards.
    'SEED_TOKEN'         => 'a-long-random-string-32+chars',
    'SEED_PASS_LEANDRO'  => 'STRONG-PASSWORD-FOR-LEANDRO',
    'SEED_PASS_VA'       => 'STRONG-PASSWORD-FOR-VA',
];
```

Right-click the file → **Change Permissions** → set to `600` (owner read/write only).

## 3. Run the schema in phpMyAdmin

cPanel → **phpMyAdmin** → select the `advertonn_crm` database → **SQL** tab →
paste the contents of `public_html/crm/schema.sql` → **Go**.

You should see two tables created: `users` and `leads`.

Then paste and run the contents of `public_html/crm/schema-v2.sql`
in the same SQL tab. This adds the activity timeline, tasks, and deal fields.

Then paste and run `public_html/crm/schema-v3.sql`. This adds tags, email
templates, last-contacted timestamps, and the per-user "new since last seen"
pointer.

Then paste and run `public_html/crm/schema-v4.sql`. This adds file
attachments, email tracking, lost-reason, and BANT qualification fields.

Then paste and run `public_html/crm/schema-v5.sql`. This is the big one —
it adds the post-won client lifecycle: `clients`, `client_events`,
`commission_events`, sequences (`sequences`, `sequence_steps`,
`sequence_enrollments`), `routing_rules`, `push_subscriptions`, plus ALTERs
for user roles + 2FA columns and a new `inbound_call` lead source.

You should now have 16 tables: `users`, `leads`, `lead_activities`, `tasks`,
`tags`, `lead_tags`, `email_templates`, `lead_files`, `email_sends`,
`clients`, `client_events`, `commission_events`, `sequences`,
`sequence_steps`, `sequence_enrollments`, `routing_rules`,
`push_subscriptions` — that's 17.

**Create the file storage dir** (one-time): cPanel → File Manager → up one level
from `public_html`, create folder `crm-files` and set permissions to `700`.
Full path should be `/home2/advertonnet/crm-files`.

## 4. Seed the two user accounts

The `.htaccess` blocks `seed-users.php` by default. To run it once:

1. cPanel → File Manager → `public_html/crm/.htaccess` → **Edit** → comment out
   the line `<FilesMatch "^(seed-users|import-audit-log|crm-config\.example)\.php$">`
   block (wrap each line with `#`). Save.
2. Browser:
   ```
   https://adverton.net/crm/seed-users.php?token=THE-SEED-TOKEN-FROM-STEP-2
   ```
3. You should see:
   ```
   Upserted user: leandro (Leandro)
   Upserted user: va (VA)
   ```
4. **Re-enable** the FilesMatch block in `.htaccess` (uncomment).

## 4b. (Optional) Import the historical audit.log

If you have leads in `/home2/advertonnet/logs/audit.log` from before the CRM
existed, you can back-fill them:

1. With `.htaccess` still in "open" mode (from step 4.1), visit:
   ```
   https://adverton.net/crm/import-audit-log.php?token=THE-SEED-TOKEN
   ```
2. You'll see a summary of how many leads were imported / deduped / skipped.
   Imported leads are tagged with `source_page = "imported from audit.log @ <ts>"`.
3. Re-enable the FilesMatch block in `.htaccess`.

## 5. DELETE the one-shot scripts

cPanel → **File Manager** → `public_html/crm/`:
- Delete `seed-users.php`
- Delete `import-audit-log.php` (if you ran the optional import)

Then edit `/home2/advertonnet/crm-config.php` and remove the three `SEED_*` lines.

## 6. Test

Open https://adverton.net/crm/ → log in with `leandro` and the password you set.

Submit the audit form (https://adverton.net/audit) and the contact form on any
industry page. Both leads should appear at the top of the list.

## (Optional) New-lead webhook

To get an instant push when a lead arrives — Slack, Discord, Telegram, Zapier,
or anything that accepts JSON — add this line to `crm-config.php`:

```php
'NEW_LEAD_WEBHOOK_URL' => 'https://hooks.slack.com/services/...',
```

The CRM will POST a JSON body like:
```json
{
  "text": "🔥 New lead — Mike Smith (Tampa Plumbing) · Plumbing · 38/100 · audit_auto\nhttps://adverton.net/crm/lead.php?id=42",
  "lead": { "id": 42, "name": "...", "phone": "...", "email": "...", ... }
}
```

For **Telegram**, set up a bot with @BotFather, then either:
- Use a no-code relay like Pipedream / n8n that accepts the webhook and forwards
  to Telegram's `sendMessage` API, or
- Point the URL at your own tiny endpoint that does the conversion.

Failures are silent (3-second timeout) — they won't break lead capture.

## (Optional) Email tracking + Resend send

To send templates from the CRM (with open + click tracking), add to
`crm-config.php`:

```php
'RESEND_API_KEY'   => 're_...',                          // same key as audit-config.php
'CRM_FROM_ADDRESS' => 'Adverton <leandro@adverton.net>', // domain must be verified in Resend
'CRM_REPLY_TO'     => 'leandro@adverton.net',
```

Once configured, the **✉️ Send template ▾** dropdown on each lead detail will
send via Resend instead of opening your mail client. Every send embeds a
tracking pixel + redirector. The lead's detail page shows opens/clicks per
email; the Reports page aggregates totals.

**Caveat:** Apple Mail Privacy Protection auto-pre-opens emails for ~50% of US
users, inflating opens. Click rates are reliable.

If `RESEND_API_KEY` is not set, the Send buttons will report an error and you
can keep using `mailto:` ("Blank email") or your own client.

## (Optional) Calendly auto-sync

The CRM can pull your Calendly bookings every 15 min and log a `📅 Meeting`
activity on the matching lead (matched by email).

1. **Get your iCal feed**: Calendly → Account → Calendar connections → click
   "Get iCal feed" → copy the URL.
2. Add to `/home2/advertonnet/crm-config.php`:
   ```php
   'CALENDLY_ICAL_URL' => 'https://calendly.com/api/.../ical/...',
   ```
3. **Set up the cron** in cPanel → Cron Jobs → Add new:
   - Schedule: every 15 minutes (`*/15 * * * *`)
   - Command:
     ```
     /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-calendly.php > /home2/advertonnet/logs/calendly.log 2>&1
     ```

When a prospect books via your Calendly link, the next cron run will:
- Find the lead by email
- Log `📅 Meeting scheduled — Discovery call · 15 Mar 14:00 UTC`
- Create a "Prep meeting with X" task due 1h before
- Bump status from `new` to `qualified`

## (Optional) Mobile install

The CRM ships as a PWA. On iOS Safari → Share → "Add to Home Screen". On
Android Chrome → menu → "Install app". You'll get an icon-launcher that opens
the CRM full-screen with no browser chrome.

## (Optional) Stripe webhook — payment status

To get automatic payment_status updates + past_due alerts:

1. Stripe Dashboard → **Developers → Webhooks → Add endpoint**.
2. URL: `https://adverton.net/crm/stripe-webhook.php`
3. Events to send:
   - `invoice.payment_succeeded`
   - `invoice.payment_failed`
   - `customer.subscription.deleted`
   - `customer.subscription.updated`
4. Copy the signing secret (`whsec_...`) → paste into `crm-config.php` as `STRIPE_WEBHOOK_SECRET`.
5. **Set `stripe_subscription_id` on each client** (manually for now in client.php's "Stripe IDs" field, or sync via your own backfill script).

## (Optional) PandaDoc webhook — auto-promote on contract sign

When the client signs the proposal in PandaDoc:
- Lead status → `won` (which triggers all the won-cascades).
- A row in `clients` is auto-created.
- A task "Onboarding intake — {client}" is assigned to the operator user.

1. Set `PANDADOC_WEBHOOK_SECRET` in `crm-config.php` to a 32+ char random string.
2. PandaDoc → Settings → Webhooks → URL: `https://adverton.net/crm/pandadoc-webhook.php?token=THE_SECRET`
3. Subscribe to `document_state_changed` events.

## (Optional) OpenPhone webhook — auto-log calls + SMS

1. Set `OPENPHONE_WEBHOOK_SECRET` in `crm-config.php`.
2. OpenPhone → Settings → Integrations → Webhooks → URL:
   `https://adverton.net/crm/openphone-webhook.php` (header-signed, no token in URL).
3. Subscribe to: `call.completed`, `call.recording.completed`, `message.received`, `message.delivered`.

Inbound calls from unknown numbers automatically create a new lead with
`source = inbound_call`.

## (Optional) Smartlead / Instantly webhook — cold email tracking

1. Set `SMARTLEAD_WEBHOOK_SECRET` in `crm-config.php`.
2. In your cold-email tool, configure a webhook to:
   `https://adverton.net/crm/smartlead-webhook.php?token=THE_SECRET`
3. Subscribe to open/reply/bounce events.

When a prospect **replies**, the lead is auto-bumped to `contacted` and any
active sequence enrollment is unenrolled.

## (Optional) Cron jobs — triggers, sequences, health, lost-re-engage

cPanel → Cron Jobs → Add four jobs:

```
# Daily client triggers (renewal, upsells, at-risk)
0 8 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-client-triggers.php >> /home2/advertonnet/logs/crm-cron.log 2>&1

# Sequence runner — every 30 min
*/30 * * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-sequences.php >> /home2/advertonnet/logs/crm-cron.log 2>&1

# Daily health score recalc + day-90 commission credits
15 8 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-health-score.php >> /home2/advertonnet/logs/crm-cron.log 2>&1

# Daily lost-by-timing re-engagement (day 60)
30 8 * * * /usr/local/bin/php /home2/advertonnet/public_html/crm/cron-lost-reengagement.php >> /home2/advertonnet/logs/crm-cron.log 2>&1
```

These scripts are **blocked from web access** by `.htaccess` and only run
via the CLI cron above.

## (Optional) 2FA

Each user can enable TOTP at `/crm/2fa-setup.php`:

1. Click "Set up 2FA" → scan QR with Google Authenticator / 1Password / Authy.
2. Enter the current 6-digit code → enabled.
3. From next login: password → 6-digit code → in.

The seed script's bcrypt password is still required first. To recover from a
lost authenticator, an admin can disable 2FA by running this SQL via phpMyAdmin:
```sql
UPDATE users SET totp_secret = NULL, totp_enabled = FALSE WHERE username = 'X';
```

## (Optional) Daily backup

Create a cron job that mysqldumps + tar's the file storage:

```
30 4 * * * mkdir -p /home2/advertonnet/backups && \
  mysqldump --single-transaction -u DB_USER -pDB_PASS DB_NAME | gzip > /home2/advertonnet/backups/crm-$(date +\%F).sql.gz && \
  tar -czf /home2/advertonnet/backups/crm-files-$(date +\%F).tar.gz -C /home2/advertonnet crm-files && \
  find /home2/advertonnet/backups -mtime +30 -delete
```

(Replace `DB_USER`, `DB_PASS`, `DB_NAME` with the values from your `crm-config.php`.)

## Operational notes

- **Backup**: cPanel → Backups → download a copy of the `advertonn_crm` database
  before any major change.
- **Adding a new user**: temporarily put `seed-users.php` back, add the row to the
  `accounts` array, set fresh `SEED_*` values, run, delete again. Or insert
  directly via phpMyAdmin using a bcrypt hash:
  `password_hash('thePassword', PASSWORD_BCRYPT)` from any PHP playground.
- **Logs**: auth events are logged to `/home2/advertonnet/logs/crm.log`.
- **Forgot password**: easiest path is to re-seed (see above) — the seeder
  upserts on conflict, so it overwrites the existing hash.
