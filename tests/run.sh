#!/usr/bin/env bash
# Test runner — dispatches to smoke / invariant / both.
set -u
cd "$(dirname "$0")/.."

mode="${1:-all}"
fail=0

if [ "$mode" = "all" ] || [ "$mode" = "invariant" ]; then
    echo "════════════════════════════════════════════════════════════"
    echo " INVARIANT CHECKS (static, local source)"
    echo "════════════════════════════════════════════════════════════"
    python3 tests/invariant.py || fail=$((fail+1))
    echo
fi

if [ "$mode" = "all" ] || [ "$mode" = "smoke" ]; then
    echo "════════════════════════════════════════════════════════════"
    echo " SMOKE TESTS (HTTP, against production)"
    echo "════════════════════════════════════════════════════════════"
    bash tests/smoke.sh || fail=$((fail+1))
    echo
fi

if [ "$fail" -gt 0 ]; then
    echo "❌  $fail suite(s) failed"
    exit 1
fi
echo "✅  All suites passed"
