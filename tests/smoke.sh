#!/usr/bin/env bash
# Smoke tests against production. Exit non-zero on any failure.
set -u
HOST="${SMOKE_HOST:-https://adverton.net}"
fail=0
ok=0

probe() {
    local path="$1" expected="$2" desc="$3"
    local code
    code=$(curl -sS -o /dev/null -w '%{http_code}' "${HOST}${path}")
    if [ "$code" = "$expected" ]; then
        printf "  \e[32mok\e[0m  %-50s %s → %s\n" "$desc" "$path" "$code"
        ok=$((ok+1))
    else
        printf "  \e[31mFAIL\e[0m %-50s %s → got %s, want %s\n" "$desc" "$path" "$code" "$expected"
        fail=$((fail+1))
    fi
}

echo "Smoke tests against $HOST"
echo

echo "Public site:"
probe "/"                                  200 "Homepage loads"
probe "/audit"                             200 "Audit landing"

echo
echo "CRM gates (must redirect or show login form when not authed):"
# /crm/ shows login form inline (200 + login HTML), not a redirect.
body=$(curl -sS "${HOST}/crm/")
if echo "$body" | grep -qi 'name="password"'; then
    printf "  \e[32mok\e[0m  %-50s %s → login form served\n" "CRM index gates with login form" "/crm/"
    ok=$((ok+1))
else
    printf "  \e[31mFAIL\e[0m %-50s %s → no login form in response\n" "CRM index gates with login form" "/crm/"
    fail=$((fail+1))
fi
probe "/crm/sequences.php"                 302 "Sequences UI requires auth"
probe "/crm/lead.php?id=1"                 302 "Lead detail requires auth"
probe "/crm/lead-import.php"               302 "Lead import requires auth"
probe "/crm/clients.php"                   302 "Clients requires auth"
probe "/crm/update.php"                    302 "Update endpoint requires auth"

echo
echo "One-shot endpoints (must be 404 — already cleaned up):"
probe "/crm/_cleanup-files.php"            404 "Cleanup script destroyed"
probe "/crm/patch-nurture-tail-task.php"   404 "Nurture patch removed"
probe "/crm/seed-nurture-sequences.php"    404 "Nurture seeder removed"
probe "/crm/run-migration-v9.php"          404 "v9 migration removed"

echo
echo "Token-protected crons (no token = 403, never 200/500):"
probe "/crm/cron-sequences.php"            403 "cron-sequences requires token"
probe "/crm/cron-calendly.php"             403 "cron-calendly requires token"
probe "/crm/cron-client-triggers.php"      403 "cron-client-triggers requires token"
probe "/crm/cron-health-score.php"         403 "cron-health-score requires token"
probe "/crm/cron-lost-reengagement.php"    403 "cron-lost-reengagement requires token"
probe "/crm/cron-backup.php"               403 "cron-backup requires token"
probe "/crm/setup-cron.php"                403 "setup-cron requires token"

echo
echo "Webhooks (GET = 4xx/503, never 500 with stack info):"
# Stripe is configured (secret present) → 400 (bad signature)
# Other 3 are not configured yet → 503 (not configured), expressly NOT 500
probe "/crm/stripe-webhook.php"            400 "stripe-webhook rejects bad signature"
probe "/crm/pandadoc-webhook.php"          503 "pandadoc-webhook missing secret returns 503 not 500"
probe "/crm/openphone-webhook.php"         503 "openphone-webhook missing secret returns 503 not 500"
probe "/crm/smartlead-webhook.php"         503 "smartlead-webhook missing secret returns 503 not 500"

echo
echo "Email tracking (public, by design — called from prospect inboxes):"
probe "/crm/t.php"                         200 "open-pixel returns 200 unconditionally"
probe "/crm/r.php"                         400 "click-redirect rejects missing params"

echo
if [ "$fail" -gt 0 ]; then
    echo "FAILED: $fail failure(s), $ok ok"
    exit 1
fi
echo "PASSED: $ok ok"
