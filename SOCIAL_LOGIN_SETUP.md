# Social Login (Continue with Google / Facebook) — Setup & Operations

This document describes the secure Google + Facebook social-login system that
was added to MohToh, and — most importantly — **the exact credentials an
administrator must supply** before the feature can go live. No credentials are
hard-coded. Until an admin enters real provider credentials, the buttons stay
hidden and the backend refuses to start a flow. That is by design.

> The implementation is complete and production-ready. The **only** things
> missing are the four secret values that Google and Facebook issue to *your*
> organization — they cannot be generated from here. Supply them in the admin
> Integration Center (see below) and the feature turns on.

---

## 1. Credentials the admin must supply

### Google (Google Cloud Console → APIs & Services → Credentials → *OAuth 2.0 Client ID*, type **Web application**)

| What to get from Google | Where it goes in MohToh |
|---|---|
| **Client ID** (looks like `1234567890-abc123.apps.googleusercontent.com`) | Admin → Integrations → *Continue with Google* → **Client ID** |
| **Client secret** (looks like `GOCSPX-xxxxxxxxxxxxxxxxxxxx`) | Admin → Integrations → *Continue with Google* → **Client secret** |

Register this **Authorized redirect URI** in the same Google credential
(*Authorized redirect URIs* section) — it must match **character-for-character**:

```
https://<your-domain>/auth/oauth/google/callback.php
```

For this deployment the app currently computes its base URL as
`http://app.mohtoh.com.ng/Mostmerit/merit_drives`, so the callback path is:

```
/auth/oauth/google/callback.php
```

> ⚠️ **HTTPS is mandatory for Google.** Google rejects non-`localhost`
> redirect URIs that are not `https://`. The base URL above is `http://`, so in
> production you must serve the site over HTTPS and register the **`https://`**
> form of the callback. If your public HTTPS URL differs from the auto-computed
> value, type the exact URL you registered into the **Redirect URI** field on
> the Google card — the login flow uses whatever is stored there, and it must be
> identical to what Google has on file.

### Facebook (Meta for Developers → *My Apps* → your app → **Facebook Login** product → Settings)

| What to get from Meta | Where it goes in MohToh |
|---|---|
| **App ID** (numeric, e.g. `1234567890123456`) | Admin → Integrations → *Continue with Facebook* → **App ID** |
| **App secret** (32-char hex) | Admin → Integrations → *Continue with Facebook* → **App secret** |

In **Facebook Login → Settings**, add this to **Valid OAuth Redirect URIs**:

```
https://<your-domain>/auth/oauth/facebook/callback.php
```

Callback path for this deployment:

```
/auth/oauth/facebook/callback.php
```

> ⚠️ Facebook also requires **HTTPS** OAuth redirect URIs for any app in *Live*
> mode. Keep the app in *Development* mode (with your account added as a tester)
> only while testing; switch to *Live* before real users sign in. Request the
> `email` permission (granted by default) so MohToh can read the account email.

### Summary of the four secret values you must obtain

1. **Google Client ID**
2. **Google Client Secret**
3. **Facebook App ID**
4. **Facebook App Secret**

None of these can be invented — they are issued by Google/Meta for your specific
project/app. Everything else (encryption, callbacks, session handling, linking,
rate limiting, logging, UI) is already built and working.

---

## 2. How an admin turns it on

1. Log in as a **super-admin** (the same permission that gates every other
   Integration Center secret — a regular admin cannot save these).
2. Go to **Dashboard → Integrations**.
3. On the **Continue with Google** card:
   - Paste the **Client ID** and **Client secret**.
   - Confirm the **Redirect URI** matches what you registered with Google
     (a **Reset to default** link fills the computed value; overwrite it if your
     public HTTPS URL differs).
   - Tick **Enabled**.
   - **Save**, then click **Test configuration** — it performs a credential
     probe against Google’s token endpoint (using a deliberately invalid code)
     and reports whether the Client ID/secret pair is accepted. It never sends a
     real login and never returns a token.
4. Repeat on the **Continue with Facebook** card (App ID / App secret / Redirect
   URI / Enabled / Save / Test).
5. Reload the public landing page — the branded **Continue with Google / Facebook**
   buttons now appear on both the Login and Create-account sheets.

Disabling a provider (untick **Enabled**, Save) immediately:
- hides its button on the landing page, **and**
- makes `auth/oauth/<provider>/start.php` and `.../callback.php` refuse to run
  (safe “currently unavailable” message, logged as `blocked`).

The enable switch is enforced on the **backend**, not just by hiding the button.

---

## 3. Security properties (already implemented)

- **Authorization-code flow only.** No implicit/client-side token flow. The
  browser never receives a client secret, authorization code, or access token.
- **Secrets encrypted at rest** (AES-256-GCM via `includes/secrets.php`) in the
  `integration_settings` table. The admin API returns secrets **masked only**
  (`••••••••1234`); the decrypted value never leaves the server, never reaches
  JS/HTML, and is never logged.
- **CSRF-secure state.** A cryptographically random `state` is stored server-side
  (`oauth_states`), single-use, expiry-bounded, and bound to provider/mode/user.
  Missing/invalid/expired/reused state is rejected.
- **PKCE (S256)** on Google in addition to the client secret.
- **Provider identity is validated server-side**, never trusted from the browser:
  Google’s `id_token` is checked (`aud` = your client ID, issuer, expiry);
  Facebook’s token is verified with `appsecret_proof` + `debug_token`
  (app-id check). The stable **provider user ID** is what we store and match on —
  never the email string alone.
- **Facebook email is always treated as UNVERIFIED** (Meta does not guarantee it),
  so it can never silently satisfy the email-verification policy.
- **No blind account creation or linking.** A provider-verified email that
  matches an existing account only *prompts* the user to log in and prove
  ownership before linking — it never auto-merges on an email match.
- **Session security.** On success the session ID is regenerated
  (`session_regenerate_id(true)`), and the same session variables, role checks,
  and email/phone verification policy as normal login apply. There is no weaker
  parallel session.
- **Existing auth is untouched.** Email/password login, registration, password
  reset, and email/phone verification all work exactly as before; social login
  is purely additive.
- **Account linking/unlinking** lives on the rider/driver **Profile → Login &
  security** card. Unlinking is CSRF-protected, rate-limited, and refused when it
  would remove the user’s only way to sign in (they’re told to set a password via
  Forgot Password first — which now correctly records that a password exists).
- **Rate limiting** on OAuth start, callback, link, and unlink (`auth_rate_limits`).
- **Security logging** (`integration_logs`, channel `oauth`) records start /
  redirect / blocked / login success/fail / link / unlink / config-saved. It
  **never** logs passwords, secrets, authorization codes, tokens, or session IDs.

---

## 4. Database objects (already migrated)

Created by `database/migrate_social_login.php` and verified present:

- `oauth_identities` — one row per linked provider account, with a **UNIQUE
  (`provider`, `provider_user_id`)** constraint (`uniq_provider_user`).
- `oauth_states` — pending authorization-code `state` records (single-use).
- `users.password_is_set` — `TINYINT(1) NOT NULL DEFAULT 1`; set to `0` for
  social-only signups so the unlink safeguard knows they have no password yet.
- `auth_rate_limits`, `integration_settings`, `integration_logs` — shared with
  the rest of the app.

If moving to a fresh database, run:

```bash
C:/xampp2/php/php.exe database/migrate_social_login.php
```

---

## 5. Fixed callback URLs (never taken from a query parameter)

| Provider | Callback (register these) |
|---|---|
| Google   | `https://<your-domain>/auth/oauth/google/callback.php` |
| Facebook | `https://<your-domain>/auth/oauth/facebook/callback.php` |

The redirect target is fixed server-side from the stored **Redirect URI** setting
and is never derived from an untrusted request parameter, preventing open-redirect
abuse.

---

## 6. Go-live checklist

- [ ] Serve the site over **HTTPS** and confirm `mt_get_base_url()` reflects the
      public `https://` origin (or set the Redirect URI fields to the exact
      registered HTTPS URLs).
- [ ] Google: create the OAuth Web client, register the `https://…/google/callback.php`
      redirect URI, copy Client ID + secret into the Google card, **Enable**, **Save**, **Test**.
- [ ] Facebook: add Facebook Login, register the `https://…/facebook/callback.php`
      redirect URI, copy App ID + secret into the Facebook card, **Enable**, **Save**, **Test**.
- [ ] Confirm the buttons appear on the landing page and a full round-trip login
      creates/links an account and lands on the correct dashboard.
- [ ] Confirm disabling a provider hides the button **and** blocks the callback.
