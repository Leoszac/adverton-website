# Tests

Two suites that run with bash + python (no PHP install needed). Run from
the repo root:

```sh
bash tests/run.sh           # both suites
bash tests/run.sh smoke     # HTTP smoke tests against production
bash tests/run.sh invariant # static analysis on the local code
```

## Suites

**`smoke.sh`** — HTTP black-box probes against `https://adverton.net`.
Catches regressions where a change accidentally takes down a page or
re-exposes a one-shot endpoint. Run after every deploy.

**`invariant.py`** — Static checks on the local PHP source. Catches:
- Destructive endpoints (`_*.php`, `seed-*.php`, `patch-*.php`,
  `setup-*.php`, `cleanup-*.php`) that lack auth (token, role, or login).
- `update.php` handlers that don't validate role on destructive actions.
- JS↔PHP drift in `CRM_SEQ_ACTIONS`.
- Multi-write library functions missing `beginTransaction`.

Both suites exit non-zero on failure so they're CI-friendly.
