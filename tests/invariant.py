#!/usr/bin/env python3
"""
Static invariant checks on the CRM source.

Each check returns (passed: bool, message: str). Run from repo root.
"""

from __future__ import annotations
import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CRM = ROOT / "crm"

GREEN = "\033[32m"
RED = "\033[31m"
DIM = "\033[2m"
RESET = "\033[0m"

results: list[tuple[bool, str]] = []


def check(name: str, condition: bool, detail: str = "") -> None:
    results.append((condition, name + (f"  {DIM}{detail}{RESET}" if detail else "")))


# ─────────────────────────────────────────────────────────────────────
# Invariant 1: every destructive one-shot endpoint requires auth.
# Pattern: scripts whose names suggest seeding/patching/migrating must
# either token-validate or require a CRM role.
# ─────────────────────────────────────────────────────────────────────

ONESHOT_PATTERNS = ["seed-", "patch-", "_cleanup", "setup-cron", "run-migration"]

def is_oneshot(name: str) -> bool:
    return any(p in name for p in ONESHOT_PATTERNS)

def has_auth(src: str) -> bool:
    return (
        "SEED_TOKEN" in src
        or "crm_requireRole" in src
        or "crm_requireLogin" in src
        or "PANDADOC_WEBHOOK_SECRET" in src
        or "STRIPE_WEBHOOK_SECRET" in src
    )

oneshot_files = sorted(p for p in CRM.glob("*.php") if is_oneshot(p.name))
unprotected = []
for p in oneshot_files:
    src = p.read_text(encoding="utf-8")
    if not has_auth(src):
        unprotected.append(p.name)

check(
    "One-shot endpoints all require auth",
    len(unprotected) == 0,
    f"unprotected: {', '.join(unprotected)}" if unprotected else f"checked {len(oneshot_files)} files",
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 2: update.php enforces CSRF globally (one check, not per-handler).
# ─────────────────────────────────────────────────────────────────────

update_src = (CRM / "update.php").read_text(encoding="utf-8")
csrf_lines = re.findall(r"crm_csrfCheck\([^)]+\)", update_src)
check(
    "update.php has at least one global CSRF check",
    len(csrf_lines) >= 1,
    f"{len(csrf_lines)} call(s) found",
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 3: destructive `_delete` handlers either restrict to founder
# OR own-resource ones (file_delete, where the user can only act on rows
# that belong to leads they manage) are explicitly allowlisted here.
# ─────────────────────────────────────────────────────────────────────

# Sales VAs can delete files attached to leads they manage; the row-level
# check (crm_getFile returns null for non-existent ids) is enough.
DELETE_FOUNDER_EXEMPT = {"file_delete"}

case_blocks = re.findall(
    r"case '([a-z_]+)':\s*\{(.*?)(?=\n\s*case '|\n\s*default:|\n\}\s*\n)",
    update_src, re.DOTALL,
)
delete_cases = [(name, body) for name, body in case_blocks if name.endswith("_delete")]
unprotected_deletes = [
    name for name, body in delete_cases
    if name not in DELETE_FOUNDER_EXEMPT
    and "founder" not in body and "crm_requireRole" not in body
]
check(
    "Sensitive *_delete handlers restrict to founder role",
    len(unprotected_deletes) == 0,
    f"checked {len(delete_cases)} delete handler(s), {len(DELETE_FOUNDER_EXEMPT)} exempt" + (
        f"; missing founder check: {', '.join(unprotected_deletes)}" if unprotected_deletes else ""
    ),
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 4: JS step builder uses CRM_SEQ_ACTIONS from PHP, not a
# hardcoded list. Specifically the `const ACTIONS =` declaration in
# sequences.php must reference CRM_SEQ_ACTIONS.
# ─────────────────────────────────────────────────────────────────────

seq_src = (CRM / "sequences.php").read_text(encoding="utf-8")
js_actions_decl = re.search(r"const ACTIONS\s*=\s*([^;]+);", seq_src, re.DOTALL)
uses_php_const = bool(js_actions_decl and "CRM_SEQ_ACTIONS" in js_actions_decl.group(1))
check(
    "JS ACTIONS array is sourced from PHP CRM_SEQ_ACTIONS (no drift)",
    uses_php_const,
    "checked sequences.php"
    + ("" if uses_php_const else "; JS hardcodes its own list"),
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 5: multi-write library functions either wrap in a transaction
# or are explicitly marked as either upsert (one logical write) or as
# inner helpers (caller owns the transaction).
#
# A function gets a free pass if:
#   - The body has the marker comment `// no-tx-needed:` (with reason)
#   - The function name ends in `Inner` (caller-managed transaction)
#   - The body is a single UPSERT (UPDATE branch + INSERT branch via if/else)
# ─────────────────────────────────────────────────────────────────────

multi_write_files = list((CRM / "lib").glob("*.php"))
violations = []
func_re = re.compile(r"function\s+(crm_\w+)\s*\([^)]*\)[^{]*\{(.*?)\n\}", re.DOTALL)
write_re = re.compile(r"prepare\(\s*['\"]?\s*(?:INSERT|UPDATE|DELETE)", re.IGNORECASE)

UPSERT_NAMES = {  # documented save-or-update funcs that are semantically 1 write
    "crm_saveTemplate", "crm_saveRoutingRule", "crm_saveSequence",
    "crm_saveLead", "crm_recordOpen", "crm_bumpTemperatureOnEngagement",
}

for f in multi_write_files:
    src = f.read_text(encoding="utf-8")
    for m in func_re.finditer(src):
        name, body = m.group(1), m.group(2)
        if name in UPSERT_NAMES:                      continue
        if name.endswith("Inner"):                    continue
        if "// no-tx-needed:" in body:                continue
        writes = len(write_re.findall(body))
        if writes >= 2 and "beginTransaction" not in body:
            violations.append(f"{f.name}::{name} ({writes} writes, no transaction)")

check(
    "Multi-write library functions use transactions",
    len(violations) == 0,
    f"checked {len(multi_write_files)} lib file(s), {len(UPSERT_NAMES)} upserts exempt" + (
        f"; violations: {violations}" if violations else ""
    ),
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 6: webhook receivers never use HTTP 500 for the
# "secret not configured" case — should be 503 with a generic message,
# not 500 with the env-var name.
# ─────────────────────────────────────────────────────────────────────

webhook_files = sorted(CRM.glob("*-webhook.php"))
secret_500_violators = []
for f in webhook_files:
    src = f.read_text(encoding="utf-8")
    # look for the bad pattern: 500 + WEBHOOK_SECRET in same line/block
    bad = re.search(
        r"http_response_code\(500\)[^;]*;\s*echo[^;]*WEBHOOK_SECRET",
        src, re.IGNORECASE | re.DOTALL,
    )
    if bad:
        secret_500_violators.append(f.name)

check(
    "Webhooks return 503 (not 500) when secret is missing",
    len(secret_500_violators) == 0,
    f"checked {len(webhook_files)} webhook(s)" + (
        f"; still using 500: {', '.join(secret_500_violators)}" if secret_500_violators else ""
    ),
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 7: no live secrets hardcoded in tracked PHP source
# (sk_live_, whsec_, re_ for Resend, etc.). They must come from
# crm_config() which reads crm-config.php (gitignored).
# ─────────────────────────────────────────────────────────────────────

scanned = 0
hardcoded = []
for php in CRM.glob("**/*.php"):
    # crm-config.example.php is allowed to show placeholder shapes
    if php.name == "crm-config.example.php":
        continue
    scanned += 1
    src = php.read_text(encoding="utf-8")
    # Patterns that are real secret prefixes, not placeholder text
    for pat in [
        r"sk_live_[A-Za-z0-9]{20,}",
        r"whsec_[A-Za-z0-9]{20,}",
        r"re_[A-Za-z0-9]{20,}",
    ]:
        if re.search(pat, src):
            hardcoded.append(f"{php.name} ({pat.split('_')[0]}_…)")

check(
    "No live API secrets hardcoded in PHP source",
    len(hardcoded) == 0,
    f"scanned {scanned} php file(s)" + (
        f"; secrets found in: {', '.join(hardcoded)}" if hardcoded else ""
    ),
)


# ─────────────────────────────────────────────────────────────────────
# Invariant 8: every webhook validates a secret/signature before
# acting on the payload (defense against unauth POST flooding).
# ─────────────────────────────────────────────────────────────────────

unverified_webhooks = []
for f in webhook_files:
    src = f.read_text(encoding="utf-8")
    if "hash_equals" not in src and "hash_hmac" not in src and "stripeVerifySignature" not in src:
        unverified_webhooks.append(f.name)

check(
    "All webhooks verify signature/token before processing payload",
    len(unverified_webhooks) == 0,
    f"checked {len(webhook_files)} webhook(s)" + (
        f"; missing verify: {', '.join(unverified_webhooks)}" if unverified_webhooks else ""
    ),
)


# ─────────────────────────────────────────────────────────────────────
# Report
# ─────────────────────────────────────────────────────────────────────

print()
print("Invariant checks on", CRM)
print()
ok = 0
fail = 0
for passed, msg in results:
    if passed:
        print(f"  {GREEN}ok{RESET}  {msg}")
        ok += 1
    else:
        print(f"  {RED}FAIL{RESET} {msg}")
        fail += 1

print()
if fail:
    print(f"{RED}FAILED: {fail} failure(s), {ok} ok{RESET}")
    sys.exit(1)
print(f"{GREEN}PASSED: {ok} ok{RESET}")
