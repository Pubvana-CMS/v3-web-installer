# Changelog

## [Unreleased]

### Added

- `.installer-progress.json` records finished substeps beside `install.php`. A reload or expired session resumes at the first unfinished step. The file holds step names only and is deleted when installation completes.
- `site_settings` substep. Writes `CMS.siteName` and `CMS.adminEmail` from the wizard into the settings table after migrations, and inserts the row when missing.
- `hasAdmin()` on the auth adapters. Admin creation is skipped when an account already exists.
- `Result::retry()` for work that finishes across several requests.
- `ServerEnvironment::$modRewriteReason` separates an HTTP authentication block from an unreachable server.

### Changed

- The distribution zip now extracts to `<name>/<name>.php` and `<name>/installer-config.php`. `--name=3.0.0` produces `3.0.0/3.0.0.php`.
- The admin step collects email and password only. Shield derives the username from the email address. The SQL fallback derives and de-duplicates it.
- Wizard titles and field labels use sentence case.
- User-facing copy is shorter. Removed filler, "Please", "successfully", and semicolons.
- README and END-USER-README describe the folder layout and the email-only admin fields.

### Fixed

- `mod_rewrite` probe no longer fails when HTTP authentication returns 401 or 403, or when the server cannot reach itself. Both cases report a warning.
- Interrupted migrations. Each call keeps its requests short, finishes pending migrations through an admin URL, and reports "still working" instead of failing. The wizard retries automatically.
- Install progress no longer resets to "Begin Installation" after a reload.
- Expired sessions no longer dead-end on CSRF. The wizard shows Reload, and database credentials plus site URL fall back to the installed `.env`.
- Admin creation is idempotent. A retry cannot create a duplicate account.
- No-JavaScript form posts return to the install page with the step result instead of raw JSON.
- `SITE_NAME` and `ADMIN_EMAIL` are no longer ignored. They update `CMS.siteName` and `CMS.adminEmail`.
- The username field is gone from the admin step.
