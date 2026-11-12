-- =====================================================================
--  MohToh — SOCIAL LOGIN (Google / Facebook)  ·  PRODUCTION SCHEMA MIGRATION
-- =====================================================================
--  FILE 1 of the deployment package.  SCHEMA ONLY.
--
--  Safe for production:
--    * Additive only — creates 3 new tables + 1 new column.
--    * No DROP, no data rewrite, no change to any existing column.
--    * Idempotent — safe to run more than once (CREATE TABLE IF NOT EXISTS
--      + an information_schema guard for the ADD COLUMN).
--    * Cross-engine — MySQL 5.7 / 8.0 and MariaDB 10.2+.
--
--  This reproduces EXACTLY what database/migrate_social_login.php created on
--  the local `merit_drives` database (verified against SHOW CREATE TABLE).
--
--  RECOMMENDED alternative: run  `php database/migrate_social_login.php`
--  on the production host — it is self-guarding and produces the identical
--  result. This .sql is provided for DBA review / manual apply.
--
--  RUN A FULL PRODUCTION BACKUP BEFORE APPLYING.  (See PHASE H in the report.)
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1) oauth_identities  — dedicated social-identity table (permanent data)
--    One row per linked provider account.
--    UNIQUE (provider, provider_user_id) is the security backbone: a given
--    Google/Facebook account maps to AT MOST ONE MohToh user, so a provider
--    login can never silently match/hijack a second account.
--    FK user_id -> users(id) ON DELETE CASCADE removes links with the user.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS oauth_identities (
  id                       INT(11)      NOT NULL AUTO_INCREMENT,
  public_id                VARCHAR(30)  DEFAULT NULL,
  user_id                  INT(11)      NOT NULL,
  provider                 VARCHAR(20)  NOT NULL,
  provider_user_id         VARCHAR(191) NOT NULL,
  provider_email           VARCHAR(255) DEFAULT NULL,
  provider_email_verified  TINYINT(1)   NOT NULL DEFAULT 0,
  provider_name            VARCHAR(255) DEFAULT NULL,
  linked_ip                VARCHAR(45)  DEFAULT NULL,
  created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_provider_user (provider, provider_user_id),
  KEY idx_user (user_id),
  KEY idx_provider_email (provider, provider_email),
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 2) oauth_states  — single-use authorization-code / anti-forgery state
--    Temporary, runtime-generated data (rows expire and are consumed).
--    state_hash is the SHA-256 of the random state; UNIQUE => single-use.
--    NOTE: the local table incidentally carries `ON UPDATE CURRENT_TIMESTAMP`
--    on expires_at (a MariaDB implicit-TIMESTAMP quirk). It is functionally
--    irrelevant — the app always sets expires_at explicitly on INSERT and
--    never UPDATEs a state row (it DELETEs on consume) — so it is omitted
--    here for a clean, portable definition.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS oauth_states (
  id              INT(11)      NOT NULL AUTO_INCREMENT,
  state_hash      CHAR(64)     NOT NULL,
  provider        VARCHAR(20)  NOT NULL,
  mode            VARCHAR(20)  NOT NULL DEFAULT 'login',
  user_id         INT(11)      DEFAULT NULL,
  role_hint       VARCHAR(20)  DEFAULT NULL,
  redirect_after  VARCHAR(255) DEFAULT NULL,
  code_verifier   VARCHAR(128) DEFAULT NULL,
  created_ip      VARCHAR(45)  DEFAULT NULL,
  expires_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_state_hash (state_hash),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 3) auth_rate_limits  — IP/identifier rate-limit buckets for OAuth
--    (start, callback, link, unlink). Temporary, runtime-generated data.
--    The app's pre-existing limiter is per-account lockout only; OAuth needs
--    IP-based buckets, which this table provides.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_rate_limits (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  bucket        VARCHAR(40)  NOT NULL,
  identifier    VARCHAR(100) NOT NULL,
  hits          INT(11)      NOT NULL DEFAULT 0,
  window_start  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_bucket_identifier (bucket, identifier),
  KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 4) users.password_is_set  — 1 = account has a real, user-chosen password.
--    DEFAULT 1 means EVERY existing production user is correctly flagged the
--    instant the column is added — NO backfill, NO row rewrite, no password
--    is touched. Only social-only signups created by the OAuth flow write 0.
--    Backs the "don't let a user unlink their ONLY way back in" safeguard.
--
--    Idempotent + cross-engine guard (MySQL 8 has no ADD COLUMN IF NOT EXISTS).
-- ---------------------------------------------------------------------
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'users'
    AND COLUMN_NAME  = 'password_is_set'
);
SET @ddl := IF(@has_col = 0,
  'ALTER TABLE users ADD COLUMN password_is_set TINYINT(1) NOT NULL DEFAULT 1',
  'DO 0 /* password_is_set already present — skipped */');
PREPARE _mt_stmt FROM @ddl;
EXECUTE _mt_stmt;
DEALLOCATE PREPARE _mt_stmt;

-- =====================================================================
--  END schema_migration.sql
--  Verify afterwards:
--    SHOW TABLES LIKE 'oauth_%';
--    SHOW TABLES LIKE 'auth_rate_limits';
--    SHOW COLUMNS FROM users LIKE 'password_is_set';
--    SHOW CREATE TABLE oauth_identities;   -- confirm UNIQUE uniq_provider_user
-- =====================================================================
