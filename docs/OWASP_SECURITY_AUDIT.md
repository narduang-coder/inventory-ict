# EDL Inventory - Final OWASP Security Audit

Date: 2026-08-31
Scope: application source, production package, database schema compatibility, authentication/authorization controls, file handling, and static regression checks.

## Result

Status: **Production candidate after server hardening**

No Critical/High issue was identified by the static audit after the fixes in this package. A live authenticated DAST/penetration test is still required for a formal security sign-off.

## OWASP Top 10 mapping

1. Broken Access Control — hardened role checks, object ownership checks, private document access, and SCT requester authorization.
2. Cryptographic Failures — password hashing with PASSWORD_DEFAULT; production DB credentials are environment-only.
3. Injection — PDO prepared statements retained; no dangerous PHP execution functions found.
4. Insecure Design — inventory changes use transactional domain functions and row locking where applicable.
5. Security Misconfiguration — production error display disabled, sensitive files blocked, upload execution blocked, security headers enabled.
6. Vulnerable/Outdated Components — package does not ship Composer/vendor test dependencies; external CDN dependencies still require organizational dependency policy review.
7. Identification/Authentication Failures — session regeneration and login throttling enabled; login CSRF token added.
8. Software/Data Integrity Failures — randomized upload filenames and MIME/content validation retained; migrations are explicit SQL files.
9. Logging/Monitoring Failures — security/activity logging retained without storing passwords or session secrets.
10. SSRF — no server-side arbitrary URL fetch feature was identified in the audited PHP source.

## Important fixes in this release

- Fixed production error handling so `APP_DEBUG` cannot expose PHP errors when `APP_ENV=production`.
- Fixed hard-coded `/edl-inven` redirect/QR paths to use `APP_BASE_URL`.
- Removed empty/root DB fallback requirement for production configuration.
- Fixed stored XSS output in the user-facing school name and restricted asset URL handling to relative application paths.
- Fixed SCT role lookup to use the real `$_SESSION['role']` key.
- Prevented normal users from choosing another requester/department when creating SCT processes.
- Added the missing SCT workflow functions and included them in application bootstrap.
- Added required database status migration for `issued`, `completed`, and `cancelled` SCT workflow states.
- Made private attachment storage non-public and retained authorization checks.
- Added login CSRF protection and session regeneration.

## Regression checks performed

- 44 PHP files passed `php -l` syntax validation.
- No `eval`, `assert`, `shell_exec`, `system`, `exec`, `passthru`, `popen`, or `proc_open` calls found.
- No stale `$_SESSION['user_role']` references remain.
- No `DB_USER=root` fallback remains.
- No invalid `DirectoryMatch` directive remains in `.htaccess`.
- `migration_runner.php` is absent from the production package.
- SCT database status migration is included.

## Deployment blockers

Before external security sign-off:

- Configure a dedicated MySQL application account; do not use root.
- Set `APP_ENV=production`, `APP_DEBUG=false`, and a strong `APP_BASE_URL`.
- Serve the application only over HTTPS.
- Restrict MySQL to localhost/private network.
- Ensure `.env` is outside the public document root when possible.
- Run `database/migrations/20260831_sct_statuses.sql` once before using SCT workflow states.
- Confirm the web server blocks `database/`, `includes/`, `docs/`, logs, `.env`, and SQL files.
- Perform authenticated DAST/penetration testing against the deployed environment.
- Verify backup restoration before production acceptance.
