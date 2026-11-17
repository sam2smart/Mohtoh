# MOHTOH — Security & Credential Remediation (Phase 3)

No secret value is printed anywhere in this document, in the commands that produced it, or in the terminal output reviewed while writing it. Every claim below was established either from file *existence*/*metadata* (line counts, key *names* only) or from a one-way fingerprint comparison that cannot be reversed into the underlying secret.

## Headline finding — act on this first

**The GitHub repository `sam2smart/Mohtoh` is PUBLIC** (confirmed via an unauthenticated call to the GitHub API: `"private": false`, `"visibility": "public"`). `.env` and `.env.bak` have been tracked in git since this repository's very first commit and are present in the current `origin/main` HEAD — **this is a live, ongoing exposure to anyone on the internet, not a historical artifact.**

**Separately and more urgently**: using a safe fingerprint comparison (see "How this was determined" below — the method itself never touches a secret value), this environment's `APP_ENCRYPTION_KEY` is confirmed to be **unset/empty**, which means `includes/secrets.php` is currently deriving its encryption key from a hardcoded salt (`'mohtoh-merit-enc-v1'`) and app name, both committed in that same public file. **Anyone who reads this public repository can derive the exact key currently encrypting data at rest** — specifically: 4 integration credentials in `integration_settings` (`integration_email_password`, `integration_google_oauth_client_secret`, `integration_termii_api_key`, `integration_twilio_auth_token` — confirmed to have stored values by name/flag only, not inspected), and the `account_number_encrypted` column on every `network_withdrawal_accounts` row (0 rows currently hold data in this dev environment, but this is the live MOHTOH NETWORK withdrawal bank-account feature — the first real user to add a withdrawal account will be exposed by this).

**This is true of the dev environment tested. It cannot be confirmed or ruled out for production from here** — production's own `.env` was not accessible. **Check production's `APP_ENCRYPTION_KEY` the same safe way (below) before assuming it's fine.**

### How this was determined (no secret read)

`includes/secrets.php`'s fallback key derivation is fully public (`sha256('mohtoh' . '|' . 'mohtoh-merit-enc-v1')`, true)`). A tiny script loaded the app's real config and computed two SHA-256 fingerprints (`substr(hash('sha256', $key), 0, 8)` — the same safe "key identity" helper the codebase already ships as `mt_secret_key_id()`, used elsewhere for diagnostics):
1. The fingerprint of whatever key is **actually in effect** right now.
2. The fingerprint of what the **public fallback** would independently produce.

They matched exactly. This proves the fallback is in effect without ever reading, printing, or needing the real `.env` value — a fingerprint cannot be reversed back into the key. **Recommended**: run the same check against production's environment before deciding whether rotation is urgent there too (script preserved at the bottom of this document for reuse — it only ever prints two 8-character hashes and `true`/`false`, never a secret).

## `.env` / `.env.bak` — findings

| Question (Phase 3 ask) | Answer |
|---|---|
| Real credentials/secrets present? | `.env` is actively parsed at runtime (`includes/environment.php: mt_load_env()`) and the app reads `APP_ENV`, `APP_BASE_URL`, `ALLOWED_HOSTS`, `APP_ENCRYPTION_KEY` from it via `getenv()`/`$_ENV`. Whether `.env`'s own raw lines hold a real `APP_ENCRYPTION_KEY` value could not be determined directly — see above, this environment's effective key is confirmed to be the *public fallback*, which is equivalent to "no usable real key is in effect" regardless of what `.env`'s raw text contains. |
| Which services/systems affected? | Everything behind `encrypt_secret()`: SMTP email password, Termii API key, Twilio auth token, Google OAuth client secret (setting still present from prior work though that feature isn't in this branch), and MOHTOH NETWORK's withdrawal bank account numbers once any exist. `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` were NOT previously read from `.env` at all (see "Hardcoded DB credentials" below) — so `.env`'s content, real or not, was irrelevant to database access until this phase's fix. |
| Already exposed in git history? | Yes — present in the very first commit, unchanged since (confirmed via `git log --follow`), and the repository is public now, so the exposure is current, not merely historical. |
| Which files should remain tracked? | `.env.example` only (already existed, already sanitized — verified by reading it directly, since a `.example` file is explicitly meant to be non-secret and shown). `.env`/`.env.bak` should not be tracked. |

## Implemented in this phase (code/config changes, no secrets rotated)

1. **`.gitignore` created** (`.gitignore`) — `.env`, `.env.bak`, `.env.local`, `.env.*.local`, plus the hygiene patterns (`*.bak`, local Claude tooling, logs, uploads) that the project's own prior unrelated work had already converged on independently.
2. **`.env`/`.env.bak` untracked** — `git rm --cached .env .env.bak`. **Local working copies are untouched on disk** (confirmed: both files still present, same size, after the `rm --cached`). Nothing is deleted; they simply stop being part of the next commit's tracked tree.
3. **`.env.example` updated** — added the previously-undocumented `APP_ENCRYPTION_KEY` (with a `php -r "echo bin2hex(random_bytes(32));"` generation command and an explicit warning about the public-fallback risk) and the new `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` keys (see next item), each with inline guidance.
4. **Hardcoded DB credentials removed from `includes/db.php`** — now reads `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` from the environment (loaded via `require_once __DIR__.'/environment.php'`, added so `db.php` works correctly regardless of which order callers require it and `config.php` in — confirmed inconsistent across entry points, e.g. `api/auth.php` requires `db.php` before `config.php`, `rider/dashboard.php` the reverse). **Falls back to the exact previous hardcoded values (`root`/empty/`localhost`/`merit_drives`) only when the corresponding env var is absent or blank** — a local XAMPP checkout with no `.env` entry for these keeps working with zero setup, exactly as before. Tested: connected successfully and returned the correct user count after the change, with the current (env-var-less) local setup.
5. **Safe runtime warning added** (`includes/secrets.php: mt_secret_key()`) — the first time the public fallback key is used in a process, it now writes one line to `error_log.txt`: *"SECURITY WARNING: APP_ENCRYPTION_KEY is unset or invalid — falling back to a key derivable from public source code..."*. This turns the silent fallback into something that actually surfaces during ordinary operation (log review, monitoring) instead of requiring someone to know to check. Never logs the key itself.

## Not implemented (requires separately approved execution)

- **Setting a real `APP_ENCRYPTION_KEY`** in any environment's actual `.env` — generating one is a one-line command (in `.env.example`'s comment), but *where* to set it (dev/staging/production) and *when* is an operational decision, not a code change, and per this assignment's restrictions, no live environment's credentials are being touched here.
- **Rotating any of the 4 `is_secret` integration credentials** or re-encrypting `network_withdrawal_accounts` rows — see the procedure below, prepared but not executed.
- **Determining whether production's own `.env` has the same empty-key problem** — only this dev environment was accessible.

## APP_ENCRYPTION_KEY — rotation procedure (prepared, NOT executed)

**Do not run this until a real key has been decided on and a maintenance window approved.** Rotating `APP_ENCRYPTION_KEY` without re-encrypting existing ciphertext makes every already-stored secret permanently undecryptable — `decrypt_secret()` returns `null` on a key mismatch, which the app already treats the same as "no credential configured" (no crash, but data loss unless migrated first).

1. **Backup first** (non-negotiable): `mysqldump` the `integration_settings` and `network_withdrawal_accounts` tables specifically, plus a full backup per the deployment runbook.
2. **Generate the new key**: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` — 64 hex characters, 32 bytes, matches `mt_secret_key()`'s `hex2bin(...)`/`strlen()===32` validation exactly.
3. **Dual-key migration window** (the safe way — avoids ever having un-decryptable live data):
   a. Before changing `.env`, run a one-off script that, for every row with a non-empty encrypted column (`integration_settings.setting_value` where `is_secret=1`, `network_withdrawal_accounts.account_number_encrypted`): `decrypt_secret($old_value)` using the **current** key (whatever is actually in effect — verify with the fingerprint check above first, since it may already be the public fallback, not a "real" old key) → re-encrypt with the **new** key using a temporary second `mt_secret_key()`-equivalent call pinned to the new value → write back.
   b. Only after every row has been successfully re-encrypted and spot-checked (decrypt a sample row with the NEW key and confirm it matches what the OLD key decrypted it to, before overwriting), update `.env`'s `APP_ENCRYPTION_KEY` to the new value.
   c. Any row where `decrypt_secret()` on the OLD key already returns `null` (already corrupt/already using a different stale key) should be flagged for manual re-entry by the credential owner (SMTP password, API key, or the partner re-submitting their bank details) rather than silently skipped.
4. **Verification**: after deploy, run the fingerprint-comparison check again — the "actual" fingerprint should now differ from the public-fallback fingerprint. Spot-check that email sending (SMTP), Termii/Twilio (if enabled), and one real `network_withdrawal_accounts` row (if any exist) still decrypt correctly through the app's own UI (masked display, not raw value).
5. **Recovery plan**: keep the OLD key value (securely, outside this repo) until the new key has been in production for at least one full billing/withdrawal cycle with no decrypt failures reported — only then discard it. If re-encryption (step 3) is skipped entirely and the key is just swapped, the recovery plan is: restore `APP_ENCRYPTION_KEY` to the old value, restore from the step-1 backup, and start over.

### Rotation checklist — per affected service (names only, confirmed via `integration_settings.is_secret=1`, no values read)

| Credential | Where stored | Action needed after a real key is adopted |
|---|---|---|
| SMTP password (`integration_email_password`) | `integration_settings`, encrypted | Re-encrypt in place (step 3), or simplest: re-enter via the existing admin Integration Center UI once the new key is live (it already supports "leave blank to keep / re-enter to change") |
| Termii API key (`integration_termii_api_key`) | `integration_settings`, encrypted | Same as above |
| Twilio auth token (`integration_twilio_auth_token`) | `integration_settings`, encrypted | Same as above |
| Google OAuth client secret (`integration_google_oauth_client_secret`) | `integration_settings`, encrypted | Same as above — note: the Social Login feature this belongs to is not part of this branch's code, so this row is inert here, but the data exists in the shared dev DB and should still be covered if/when that feature is reintroduced |
| MOHTOH NETWORK withdrawal bank accounts (`network_withdrawal_accounts.account_number_encrypted`) | DB, encrypted | Zero rows currently — nothing to re-encrypt *yet*, but this MUST be covered by step 3 before any real partner withdrawal account is submitted in a target environment |

## Safe fingerprint-check script (reusable, prints no secret)

```php
<?php
require_once __DIR__ . '/includes/environment.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/secrets.php';

$actual = mt_secret_key_id();
$fallback = substr(hash('sha256', hash('sha256', (defined('APP_NAME') ? APP_NAME : 'mohtoh') . '|mohtoh-merit-enc-v1', true)), 0, 8);

echo "actual=$actual fallback=$fallback using_public_fallback=" . ($actual === $fallback ? 'TRUE - SET A REAL APP_ENCRYPTION_KEY' : 'false') . PHP_EOL;
```

Run this against any environment (including production, with appropriate access) to answer "is this environment's encryption key the public fallback?" without ever reading or transmitting the actual key.
