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
probe "/crm/clients.php"                   302 "Clients requires auth"
probe "/crm/update.php"                    302 "Update endpoint requires auth"

echo
echo "One-shot endpoints (must be 404 — already cleaned up):"
probe "/crm/_cleanup-files.php"            404 "Cleanup script destroyed"
probe "/crm/patch-nurture-tail-task.php"   404 "Nurture patch removed"
probe "/crm/seed-nurture-sequences.php"    404 "Nurture seeder removed"
probe "/crm/run-migration-v9.php"          404 "v9 migration removed"

echo
echo "Token-protected endpoints (no token = 403, not 200):"
probe "/crm/cron-sequences.php"            403 "cron-sequences requires token"
probe "/crm/setup-cron.php"                403 "setup-cron requires token"

echo
if [ "$fail" -gt 0 ]; then
    echo "FAILED: $fail failure(s), $ok ok"
    exit 1
fi
echo "PASSED: $ok ok"
