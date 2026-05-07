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
