# EDL Inventory – Security Audit & Hardening Record

## Scope
Static source review of the production package, including authentication/authorization, CSRF, sessions, SQL access, file uploads/downloads, error handling, HTTP security headers, and deployment exposure.

## Fixed in this build
- Removed the browser-accessible migration runner from the production package.
- Replaced invalid/unsafe root Apache rules with rules that deny direct access to `.env`, SQL, logs, database internals and deployment documentation.
- Protected private document storage from direct HTTP access; documents are served through an authenticated controller.
- Added admin/user authorization checks to direct admin endpoints and APIs.
- Added CSRF enforcement to state-changing user/admin endpoints, including returns, SCT operations, issuance and attachment upload.
- Added session ID regeneration after successful login and robust logout cookie/session destruction.
- Added login throttling for repeated failed attempts per username/IP pair.
- Removed production database fallbacks to `root` with an empty password; database configuration must come from environment variables.
- Added baseline CSP, HSTS (HTTPS only), clickjacking, MIME-sniffing, referrer and cross-domain policy headers.
- Removed verbose database/exception messages from user-facing responses.
- Hardened attachment authorization so authenticated users cannot download arbitrary attachment IDs outside their permitted scope.
- Hardened file path containment checks against sibling-prefix bypasses.
- Added content-based MIME validation and extension/MIME matching for item uploads.
- Reduced item document upload types to PDF/DOCX/XLSX/PPTX/TXT.
- Replaced predictable upload filenames with cryptographically random names.
- Disabled script execution in upload directories.
- Removed SVG from logo uploads to reduce active-content/XSS risk.
- Added password length and role validation for account administration.
- Prevented an administrator from demoting their own account through the user-management screen.
- Removed the runtime activity log from the distributable package; production logs are written outside the web root.

## Verification performed
- PHP syntax lint completed for every PHP file in the production package.
- No use of common direct command-execution functions (`eval`, `system`, `shell_exec`, `passthru`, `popen`, `proc_open`) was found.
- No production migration runner remains in the package.
- State-changing admin/user POST handlers were reviewed for CSRF enforcement.
- Direct admin endpoint access is protected by role checks.

## Deployment requirements
1. Use HTTPS only.
2. Set a strong dedicated MySQL application account; do not use MySQL `root`.
3. Populate `.env` from `.env.example` outside source control.
4. Keep `APP_DEBUG=false` in production.
5. Apply SQL migrations through a controlled deployment/DBA process; do not expose a migration endpoint.
6. Ensure the external log/backup directories are writable by the PHP process but not publicly served.
7. Set restrictive filesystem permissions on application files and upload directories.
8. Put the application behind a production web server with current PHP and Apache/Nginx security configuration.
9. Run a real authenticated DAST/penetration test before formal security acceptance.

## Remaining limits
This is a source-level hardening pass, not a substitute for a live penetration test. Infrastructure issues such as TLS configuration, server patching, WAF rules, database network exposure, OS permissions, dependency CVEs and production secrets cannot be proven from the ZIP alone.
