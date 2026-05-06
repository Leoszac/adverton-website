<?php
// Template for audit secrets. The REAL file lives OUTSIDE public_html on the
// server (recommended path: /home2/advertonnet/audit-config.php with chmod 600).
//
// Do NOT commit the real values. Do NOT place the real file inside
// public_html — anything in public_html is web-accessible.
//
// audit.php looks for the real file at:
//   1. /home2/advertonnet/audit-config.php   (recommended)
//   2. dirname(__DIR__) . '/audit-config.php' (one level up from public_html)
//   3. __DIR__ . '/audit-config.php'          (LAST resort, must chmod 600)

return [
    // Google Maps Platform — Places API (New) enabled.
    // Restrict by server IP in GCP console. Required.
    'GOOGLE_API_KEY'           => 'AIzaSy...',

    // Resend (https://resend.com). Verify the adverton.net domain in Resend
    // (SPF + DKIM + DMARC records on the main domain — no subdomain needed).
    // OPTIONAL: if missing or empty, audit-email.php falls back to PHP mail().
    // Recommended for production deliverability.
    'RESEND_API_KEY'            => 're_...',

    // reCAPTCHA v3 server-side secret. The matching site key is hardcoded in
    // audit.html (the public key — fine to commit).
    'RECAPTCHA_SECRET'          => '6Ld...',

    // Where lead-notification emails go. Defaults to hello@adverton.net if unset.
    'LEAD_NOTIFICATION_EMAIL'   => 'hello@adverton.net',

    // Used to sign unsubscribe tokens. Any random string, ~32+ chars.
    // If you change this, existing unsubscribe links become invalid.
    'UNSUBSCRIBE_SALT'          => 'change-me-to-anything-random-32-chars',
];
