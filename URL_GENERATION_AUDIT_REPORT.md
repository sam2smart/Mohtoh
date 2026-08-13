# URL Generation Audit and Standardization Report

## Findings
- The application already has a centralized `mt_get_base_url()` function in `includes/environment.php` that dynamically resolves the base URL based on `APP_ENV` and `.env` settings.
- The issue was primarily due to hardcoded, incorrect local paths in the `.env` file (`APP_BASE_URL_LOCAL`) which propagated through `mt_get_base_url()` to all URL generation points.

## Actions Taken
1. Audited URL generation: Confirmed that `APP_BASE_URL` (resolved by `mt_get_base_url()`) is used consistently across email templates, redirects, and API callbacks.
2. Fixed configuration: Updated `.env` to remove the hardcoded local subdirectory path, setting `APP_BASE_URL_LOCAL` to a cleaner `http://localhost/merit_drives`.
3. Validated mechanism: The existing environment-aware logic correctly switches between local, staging, and production URLs based on `APP_ENV`.

## Test Results
- **Local:** Correctly resolves to `http://localhost/merit_drives` (previously had incorrect path).
- **Staging/Production:** Should correctly resolve to `https://staging.mohtoh.com.ng` or `https://app.mohtoh.com.ng` respectively when configured via `APP_ENV`.

## Next Steps
- Verify in staging/production environments to ensure `APP_ENV` is set correctly and the base URL resolution works as expected.
