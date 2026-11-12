-- =====================================================================
--  MohToh — SOCIAL LOGIN  ·  CONFIGURATION KEYS REQUIRED  (FILE 2 of 3)
-- =====================================================================
--  Seeds the 8 Integration Center keys the Social Login feature reads, with
--  SAFE, NON-SECRET defaults. Both providers start DISABLED.
--
--  *** NO REAL SECRETS ARE IN THIS FILE, AND NONE SHOULD EVER BE ADDED. ***
--  Google/Facebook credentials are entered ONLY through:
--      Admin  ->  Integrations  ->  Continue with Google / Facebook
--  where the app encrypts them at rest (AES-256-GCM, keyed by the
--  environment's APP_ENCRYPTION_KEY) and masks them in every response.
--
--  This file is OPTIONAL: the application already treats a missing key as its
--  documented fallback (enabled=0, empty credentials, auto-derived redirect).
--  Running it simply makes the keys visible in `integration_settings` with the
--  correct `is_secret` flags so the Integration Center renders/masks them
--  consistently on a brand-new production database.
--
--  INSERT IGNORE => it will NEVER overwrite a value an admin has already saved.
--  Target table `integration_settings` is PRE-EXISTING (created by
--  database/migrate_integration_settings.php) — this file adds ROWS only.
-- =====================================================================

-- setting_key                               | value | is_secret
INSERT IGNORE INTO integration_settings (setting_key, setting_value, is_secret) VALUES
  -- ---- Google ("Continue with Google") -------------------------------------
  ('integration_google_oauth_enabled',        '0', 0),   -- '0'=OFF until admin enables
  ('integration_google_oauth_client_id',      '',  0),   -- ADMIN SUPPLIES: Google OAuth Client ID
  ('integration_google_oauth_client_secret',  '',  1),   -- ADMIN SUPPLIES: Google OAuth Client Secret (stored encrypted)
  ('integration_google_oauth_redirect_uri',   '',  0),   -- empty => app auto-derives {BASE_URL}/auth/oauth/google/callback.php
                                                          --          (override with the exact HTTPS URI registered at Google)
  -- ---- Facebook ("Continue with Facebook") ---------------------------------
  ('integration_facebook_oauth_enabled',       '0', 0),  -- '0'=OFF until admin enables
  ('integration_facebook_oauth_app_id',        '',  0),  -- ADMIN SUPPLIES: Facebook/Meta App ID
  ('integration_facebook_oauth_app_secret',    '',  1),  -- ADMIN SUPPLIES: Facebook/Meta App Secret (stored encrypted)
  ('integration_facebook_oauth_redirect_uri',  '',  0);  -- empty => app auto-derives {BASE_URL}/auth/oauth/facebook/callback.php
                                                          --          (override with the exact HTTPS URI registered at Meta)

-- =====================================================================
--  The FOUR values the administrator must obtain from the providers and enter
--  by hand in the Integration Center (they cannot be generated and must NOT be
--  hardcoded, committed, or copied from the local/dev database):
--
--     1. Google  Client ID       -> integration_google_oauth_client_id
--     2. Google  Client Secret   -> integration_google_oauth_client_secret   (secret)
--     3. Facebook App ID         -> integration_facebook_oauth_app_id
--     4. Facebook App Secret     -> integration_facebook_oauth_app_secret     (secret)
--
--  Then set each *_enabled to 1 (via the Integration Center "Enabled" toggle),
--  register the HTTPS redirect URIs with Google/Meta, and use "Test
--  configuration" before go-live. See SOCIAL_LOGIN_SETUP.md.
-- =====================================================================
