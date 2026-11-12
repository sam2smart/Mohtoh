# DO NOT MIGRATE — MohToh Social Login (Google/Facebook)

**FILE 3 of 3 of the deployment package.**

This lists every piece of **local `merit_drives` data that must NOT be copied to
production.** Deploy the **schema** (`schema_migration.sql`) and the **empty
config keys** (`configuration_keys_required.sql`) only. Everything below is
local development/test/runtime data — copying it would at best be noise and at
worst leak dev artifacts, break decryption, or create fake accounts.

> Golden rule: **push schema, not rows.** The only rows that ever belong in
> production are the ones an admin types into the Integration Center on the
> production box, and the identities/logs that real production users generate.

---

## 1. Leftover Google OAuth config rows in `integration_settings`  ⚠️ IMPORTANT

The local DB currently holds a **half-configured, dev-only** Google entry:

| Key | Local state | Why it must NOT be copied |
|---|---|---|
| `integration_google_oauth_client_id` | **empty** | nothing to copy |
| `integration_google_oauth_client_secret` | **present, encrypted (dev value)** | Encrypted with the **local** `APP_ENCRYPTION_KEY`; it is **undecryptable on production** (different key) and is not a real credential anyway. |
| `integration_google_oauth_enabled` | `1` | Would switch the provider **ON in production with no valid client_id** — misconfiguration. Production must start `0`. |
| `integration_google_oauth_redirect_uri` | `http://localhost/Mostmerit/merit_drives - Copy.v1/auth/oauth/google/callback.php` | A **localhost dev URL** — wrong and non-HTTPS for production. |

**Action:** do **not** export these rows. Use `configuration_keys_required.sql`
(empty defaults, `enabled=0`) instead, then enter real credentials via the
Integration Center on production. (Facebook keys don't even exist locally — 0 rows.)

## 2. `oauth_states` — ALL rows
Temporary, single-use authorization-code state. Short-lived and meaningless
outside the request that created them. **Never migrate.** (Local rows: 0.)

## 3. `auth_rate_limits` — ALL rows
Runtime IP/identifier rate-limit counters. Environment-specific and
self-populating. **Never migrate.** (Local rows: 0.)

## 4. `oauth_identities` — ALL local rows
Links a real provider account to a real user. Any that exist locally are **test
links** tied to local user IDs. Production must start empty and accumulate its
own real links. **Never migrate.** (Local rows: 0.)

## 5. `integration_logs` — OAuth dev log rows
The local `channel='oauth'` entries (e.g. a `config_saved` test event) are
development audit noise. Production keeps its own audit trail. **Do not copy**
these log rows. (The **table** is pre-existing and unchanged — no schema action.)

## 6. `user_tokens` — ALL rows (3 locally)
**Unrelated to Social Login.** These are API-v1 mobile device tokens
(`includes/api_tokens.php`). OAuth never reads or writes this table. Listed here
only to be explicit: **do not copy** these dev device tokens to production.

## 7. Any local test users / social-only accounts
Do **not** copy `users` rows from local to production. (For the record, local
has **0** social-only accounts — all 35 local users have `password_is_set=1`.)
Production users are untouched by this deployment; no user rows move in either
direction.

## 8. PHP session data
OAuth uses native PHP `$_SESSION` (regenerated on login), **not** a DB session
table (none exists). There is no session data to migrate.

---

## Quick "leave behind" checklist
- [ ] `integration_settings` rows `integration_google_oauth_*` (dev/localhost) — **left behind**
- [ ] `oauth_states` rows — **left behind**
- [ ] `auth_rate_limits` rows — **left behind**
- [ ] `oauth_identities` rows — **left behind**
- [ ] `integration_logs` `channel='oauth'` rows — **left behind**
- [ ] `user_tokens` rows — **left behind**
- [ ] any local `users` rows — **left behind**

## What DOES go to production
- [x] `schema_migration.sql` — 3 new tables + `users.password_is_set` column
- [x] `configuration_keys_required.sql` — 8 empty/disabled config keys (no secrets)
- [x] Real Google/Facebook credentials — typed into the **production** Integration Center by an admin
