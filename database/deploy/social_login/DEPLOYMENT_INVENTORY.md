# MohToh — Social Login Database Deployment Inventory & Live Migration Plan

**Scope:** Google + Facebook OAuth/Social Login.
**Analysis source:** live local database `merit_drives` (XAMPP/MariaDB) + the actual
codebase. Every schema, row count, and key below was read from the running DB and
source — **not guessed**.
**Status:** ANALYSIS + DEPLOYMENT PACKAGE ONLY. Production was **not** touched.

Deployment package (this folder — `database/deploy/social_login/`):
- `schema_migration.sql` — FILE 1 (schema only; validated + idempotent)
- `configuration_keys_required.sql` — FILE 2 (empty config keys; no secrets)
- `DO_NOT_MIGRATE.md` — FILE 3 (local data to leave behind)

---

## PHASE 1–3 · Table-by-table inventory

All OAuth code paths write to exactly six tables (verified from prepared
statements in `includes/oauth.php`, `api/social-accounts.php`,
`api/integration-center.php`):

| Table | Status | Local Rows | New Rows in Live | Data kind | Purpose | Required on Live? |
|---|---|---:|---:|---|---|---|
| `oauth_identities` | **NEW** | 0 | 0 initially | Permanent | Links a provider account → a MohToh user; `UNIQUE(provider,provider_user_id)` | **YES** |
| `oauth_states` | **NEW** | 0 | 0 (self-fills) | Temporary | Single-use CSRF/anti-forgery state + PKCE verifier for the code flow | **YES** |
| `auth_rate_limits` | **NEW** | 0 | 0 (self-fills) | Temporary | IP/identifier rate-limit buckets (start/callback/link/unlink) | **YES** |
| `users` | **MODIFIED** | 35 | 0 new rows | Permanent | +1 column `password_is_set`; also receives social-only signups | **YES (column)** |
| `integration_settings` | **EXISTING** | 35 | +8 config keys | Config | Stores the 8 OAuth config keys (secrets encrypted) | Table already there; **keys required** |
| `integration_logs` | **EXISTING** | 54 | grows at runtime | Audit | Security logging, `channel='oauth'` | Table already there; **no schema change** |

Related but **NOT** part of this feature:
| Table | Status | Local Rows | Note |
|---|---|---:|---|
| `user_tokens` | EXISTING, untouched | 3 | API-v1 mobile device tokens (`includes/api_tokens.php`). **OAuth never touches it.** |

### A. Schema-only changes (deploy even with zero rows)
- New table `oauth_identities` (+ PK, `uniq_provider_user`, `idx_user`, `idx_provider_email`, FK→users CASCADE)
- New table `oauth_states` (+ PK, `uniq_state_hash`, `idx_expires`)
- New table `auth_rate_limits` (+ PK, `uniq_bucket_identifier`, `idx_window`)
- New column `users.password_is_set TINYINT(1) NOT NULL DEFAULT 1`

### B. Data/configuration changes (structure to production, values entered by admin)
- 8 `integration_settings` keys (see Phase 8). Deploy as **empty + disabled**; admin enters real credentials on production.

### C. Runtime-generated data (do NOT copy — see FILE 3)
- `oauth_states` rows, `auth_rate_limits` rows, `oauth_identities` rows, `integration_logs` `oauth` rows, local `integration_settings` Google dev rows, `user_tokens` rows.

---

## PHASE 4 · `users` table change analysis

- **Added?** Yes — `password_is_set`, and it is the **only** OAuth-related change to `users` (full column list reviewed; no provider/avatar/oauth_id columns were added).
- **Type:** `TINYINT(1)`
- **Default:** `1`
- **Null:** `NOT NULL`
- **Existing users get the right value automatically?** **Yes.** `DEFAULT 1` applies to all pre-existing rows at ALTER time. Local proof: all **35/35** users = `1`.
- **Should existing production users receive `1`?** **Yes** — every existing account has a real password, so `1` is correct and is applied automatically.
- **Special migration logic for existing rows?** **None.** No backfill, no `UPDATE`, no password touched. (Validated on a throwaway DB: a pre-existing user row showed `password_is_set=1` immediately after the ALTER.)

**Exact production SQL** (idempotent, cross-engine — from FILE 1):
```sql
SET @has_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='password_is_set');
SET @ddl := IF(@has_col=0,
  'ALTER TABLE users ADD COLUMN password_is_set TINYINT(1) NOT NULL DEFAULT 1',
  'DO 0');
PREPARE _mt_stmt FROM @ddl; EXECUTE _mt_stmt; DEALLOCATE PREPARE _mt_stmt;
```
> Simpler MariaDB-only equivalent: `ALTER TABLE users ADD COLUMN IF NOT EXISTS password_is_set TINYINT(1) NOT NULL DEFAULT 1;`

---

## PHASE 5 · `oauth_identities`

Live `SHOW CREATE TABLE` (authoritative):
```sql
CREATE TABLE oauth_identities (
  id INT(11) NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(30) DEFAULT NULL,
  user_id INT(11) NOT NULL,
  provider VARCHAR(20) NOT NULL,
  provider_user_id VARCHAR(191) NOT NULL,
  provider_email VARCHAR(255) DEFAULT NULL,
  provider_email_verified TINYINT(1) NOT NULL DEFAULT 0,
  provider_name VARCHAR(255) DEFAULT NULL,
  linked_ip VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  updated_at TIMESTAMP NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_provider_user (provider, provider_user_id),
  KEY idx_user (user_id),
  KEY idx_provider_email (provider, provider_email),
  CONSTRAINT oauth_identities_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```
- **Primary key:** `id`
- **FK:** `user_id → users(id) ON DELETE CASCADE` (unlinking a deleted user cleans links up)
- **provider:** `VARCHAR(20)` (`'google'` / `'facebook'`)
- **provider_user_id:** `VARCHAR(191)` — the provider's **stable unique subject ID** (Google `sub`, Facebook `id`); this, not email, is the permanent match key
- **UNIQUE(provider, provider_user_id):** `uniq_provider_user`
- **Timestamps:** `created_at`, `updated_at` (auto)
- **Cascade:** `ON DELETE CASCADE`

**Why the UNIQUE constraint matters:** it guarantees a given provider account maps
to **at most one** MohToh user. Without it, a race or a replayed callback could
create two links for the same Google/Facebook identity and let a provider login
resolve to the wrong/ambiguous account (account-takeover surface). **Validated:**
a duplicate `(google, G-123)` insert was rejected with `ERROR 1062`.

Production SQL: the `CREATE TABLE IF NOT EXISTS` block in FILE 1 (FK name omitted so MySQL auto-assigns it — identical result, no collision risk).

---

## PHASE 6 · `oauth_states`

Live schema:
```sql
CREATE TABLE oauth_states (
  id INT(11) NOT NULL AUTO_INCREMENT,
  state_hash CHAR(64) NOT NULL,          -- SHA-256 of the random state
  provider VARCHAR(20) NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'login',   -- 'login' | 'link'
  user_id INT(11) DEFAULT NULL,          -- initiating user (link mode)
  role_hint VARCHAR(20) DEFAULT NULL,
  redirect_after VARCHAR(255) DEFAULT NULL,
  code_verifier VARCHAR(128) DEFAULT NULL,     -- PKCE (Google)
  created_ip VARCHAR(45) DEFAULT NULL,
  expires_at TIMESTAMP NOT NULL ...,
  created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY uniq_state_hash (state_hash),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```
- **Purpose:** server-side, per-request anti-forgery `state` for the authorization-code flow; also carries flow `mode`, the initiating `user_id` (link mode), and the PKCE `code_verifier` (Google).
- **Expiry:** `expires_at` set on INSERT; `idx_expires` supports cleanup; the consume path rejects expired rows.
- **Single-use:** `UNIQUE(state_hash)` + the row is **DELETEd on consume** (`oauth_consume_state`), so a state cannot be replayed.
- **Migrate local rows?** **No.** Temporary security data. Production starts clean (local count: 0).

---

## PHASE 7 · Rate limiting & security logging

| Concern | Table | New/Existing | Schema change? | Copy local rows? |
|---|---|---|---|---|
| OAuth rate limiting | `auth_rate_limits` | **NEW** | Created by FILE 1 | **No** — runtime counters |
| OAuth security/audit log | `integration_logs` | **EXISTING** | **None** | **No** — dev log noise |

- **`auth_rate_limits`** buckets seen in code: OAuth start, callback, link, and `oauth_unlink` (10/600s). Self-populating per environment.
- **`integration_logs`** gains a new **`channel='oauth'`** value only (no DDL change). Events include `config_saved`, `config_test`, and at runtime start/redirect/blocked/login success·fail/link/unlink. Logging **never** records passwords, secrets, authorization codes, tokens, or session IDs. Local has 1 `oauth` row (`config_saved`) — leave it behind.

**Schema vs data:** deploy the `auth_rate_limits` **table**; deploy **no** rows from either table.

---

## PHASE 8 · Integration settings / credentials

- **Where stored:** the pre-existing **`integration_settings`** table (`setting_key` PK, `setting_value` TEXT, `is_secret` TINYINT, `updated_by_admin_id` FK, `updated_at`).
- **Encryption:** secret values are **AES-256-GCM** (`openssl_encrypt`, `includes/secrets.php`), keyed by **`APP_ENCRYPTION_KEY`** (per-environment). Admin API returns secrets **masked only** (`••••••••1234`); plaintext never leaves the server.
- **The 8 required configuration keys** (authoritative from `GOOGLE_OAUTH_KEYS`/`FACEBOOK_OAUTH_KEYS` in `api/integration-center.php`):

| Key | is_secret | Local value (masked) |
|---|:--:|---|
| `integration_google_oauth_enabled` | 0 | `1` (dev leftover — should be `0` on prod) |
| `integration_google_oauth_client_id` | 0 | **empty** |
| `integration_google_oauth_client_secret` | **1** | **CONFIGURED (masked)** — dev value, encrypted with local key |
| `integration_google_oauth_redirect_uri` | 0 | `http://localhost/...callback.php` (dev URL) |
| `integration_facebook_oauth_enabled` | 0 | **NOT PRESENT** |
| `integration_facebook_oauth_app_id` | 0 | **NOT PRESENT** |
| `integration_facebook_oauth_app_secret` | **1** | **NOT CONFIGURED** |
| `integration_facebook_oauth_redirect_uri` | 0 | **NOT PRESENT** |

- **Copy these rows to production?** **No.** The Google rows are a half-configured dev leftover (empty client_id, localhost redirect, `enabled=1`, and an encrypted secret that is **undecryptable under production's different `APP_ENCRYPTION_KEY`**). Deploy the **empty/disabled keys** from FILE 2, then have an admin enter the four real values (Google Client ID + Secret, Facebook App ID + Secret) in the **production** Integration Center.

---

## PHASE 9 · Local vs production comparison

**Important honesty note:** no full production dump is present in this workspace.
`database/schema.sql` is a 17-table bootstrap stub (it lacks `integration_settings`,
`integration_logs`, `oauth_*`, verification columns, etc.), so it is **not** a
production baseline. A live production diff was **not** run (production must not be
touched). The comparison below is therefore the **exact delta this feature adds** —
i.e. what a pre-Social-Login production is missing and must receive:

- **Tables missing from production:** `oauth_identities`, `oauth_states`, `auth_rate_limits`
- **Columns missing from production:** `users.password_is_set`
- **Indexes missing from production:** `uniq_provider_user`, `idx_user`, `idx_provider_email` (oauth_identities); `uniq_state_hash`, `idx_expires` (oauth_states); `uniq_bucket_identifier`, `idx_window` (auth_rate_limits)
- **Foreign keys missing from production:** `oauth_identities.user_id → users(id) ON DELETE CASCADE`
- **Enum/type differences:** none (no enums added; `password_is_set` is TINYINT(1))
- **Default value differences:** `password_is_set DEFAULT 1`. *(Local `oauth_states.expires_at` also carries an incidental MariaDB `ON UPDATE CURRENT_TIMESTAMP`; FILE 1 omits it deliberately — functionally irrelevant, documented in the file.)*
- **Unique constraint differences:** the three UNIQUE keys above (most importantly `uniq_provider_user`)
- **Config-key rows missing from production:** the 8 keys in Phase 8 (deploy empty via FILE 2)

> To run a real diff before deploying: on production execute `SHOW TABLES LIKE 'oauth_%';`, `SHOW TABLES LIKE 'auth_rate_limits';`, and `SHOW COLUMNS FROM users LIKE 'password_is_set';` — anything absent is exactly what FILE 1 adds.

---

## PHASE 11 · Data safety check

| # | Check | Result |
|---|---|---|
| 1 | Existing users still log in with email/password? | **Yes** — auth code unchanged; only an additive column. |
| 2 | Existing password users get `password_is_set=1`? | **Yes** — `DEFAULT 1`, automatic, no backfill (verified). |
| 3 | Any existing passwords modified? | **No** — migration never touches `password`. |
| 4 | Any users duplicated? | **No** — no user rows are inserted/copied by deployment. |
| 5 | OAuth identities start clean? | **Yes** — table created empty; no rows migrated. |
| 6 | Temporary OAuth states migrated? | **No** — none copied. |
| 7 | Local test data pushed to prod? | **No** — FILE 3 enumerates everything left behind. |
| 8 | Secrets exposed in SQL/report/logs? | **No** — FILE 2 has empty values; report masks; logs never store secrets. |
| 9 | Existing Integration Center settings intact? | **Yes** — `INSERT IGNORE` adds only missing keys; never overwrites. |
| 10 | Existing login/registration keeps working? | **Yes** — purely additive; unrelated features untouched. |

---

## PHASE 12 · Final report

### A. New tables created
`oauth_identities`, `oauth_states`, `auth_rate_limits`.

### B. Existing tables modified
`users` — added `password_is_set TINYINT(1) NOT NULL DEFAULT 1` (the only OAuth change to `users`).

### C. Existing tables used but unchanged (no DDL)
`integration_settings` (gains config-key rows), `integration_logs` (gains `channel='oauth'` values). `user_tokens` is unrelated and untouched.

### D. Current local row counts
`oauth_identities`=0, `oauth_states`=0, `auth_rate_limits`=0, `integration_settings`=35 (4 Google OAuth keys, 0 Facebook), `integration_logs`=54 (1 `oauth`), `users`=35 (all `password_is_set=1`), `user_tokens`=3 (unrelated).

### E. Rows to migrate to production
**None copied from local.** Only *create*: the 8 empty/disabled config keys (FILE 2). Real credentials are typed into the production Integration Center by an admin.

### F. Rows NOT to migrate
Local Google dev config rows, all `oauth_states`/`auth_rate_limits`/`oauth_identities` rows, `integration_logs` `oauth` rows, `user_tokens` rows, any local `users`. (Full list: FILE 3.)

### G. Exact production schema SQL
See `schema_migration.sql` (FILE 1) — validated on a throwaway DB: applies cleanly, idempotent on re-run, `password_is_set` auto-fills `1`, and `UNIQUE(provider,provider_user_id)` rejects duplicates.

### H. Production deployment order
1. **Full production backup** (`mysqldump` of the whole schema+data).
2. Apply `schema_migration.sql`.
3. Verify: `SHOW TABLES LIKE 'oauth_%'`, `SHOW TABLES LIKE 'auth_rate_limits'`, `SHOW COLUMNS FROM users LIKE 'password_is_set'`, `SHOW CREATE TABLE oauth_identities` (confirm `uniq_provider_user`).
4. Apply `configuration_keys_required.sql` (empty/disabled keys).
5. Enter **real** Google/Facebook credentials via **Admin → Integrations** (encrypted at rest).
6. Configure **HTTPS** redirect URIs and register them with Google/Meta.
7. Enable each provider (Integration Center toggle) + "Test configuration".
8. Test Google login (full round-trip).
9. Test Facebook login (full round-trip).
10. Test existing **email/password** login (regression).
11. Test **password reset** (regression; confirms `password_is_set` path).
12. Test account **linking/unlinking** on Profile → Login & security.

### I. Rollback plan
The migration is additive, so rollback is low-risk:
1. **Preferred:** restore the pre-deploy backup from step H-1 (guaranteed clean revert).
2. **Targeted manual revert** (only if needed, and only after confirming no real
   users have linked social accounts yet):
   ```sql
   -- Safe: these tables are new and, on a fresh deploy, empty.
   DROP TABLE IF EXISTS oauth_identities;   -- drop FIRST (holds the FK)
   DROP TABLE IF EXISTS oauth_states;
   DROP TABLE IF EXISTS auth_rate_limits;
   -- Column drop is OPTIONAL. Leaving password_is_set in place is harmless
   -- (DEFAULT 1). Only drop it if you must fully reverse:
   -- ALTER TABLE users DROP COLUMN password_is_set;
   -- Optional: remove the seeded config keys (no secrets involved):
   -- DELETE FROM integration_settings WHERE setting_key LIKE 'integration_google_oauth_%'
   --   OR setting_key LIKE 'integration_facebook_oauth_%';
   ```
   > ⚠️ If **real users have already linked** Google/Facebook, dropping
   > `oauth_identities` deletes those links (they'd re-link on next login) and
   > dropping `password_is_set` would remove the unlink safeguard's data — prefer
   > the backup restore in that case. Never drop `integration_settings`,
   > `integration_logs`, `users`, or `user_tokens` (pre-existing/shared).

---

*Generated by static + live inspection of `merit_drives` and the codebase. No
production system was accessed or modified.*
