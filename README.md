# EDL Inventory System

Production-oriented PHP/MySQL inventory application.

## Structure

- `index.php`, `admin.php`, `user.php` — public entry points / routers
- `admin/` — administrator features
- `user/` — user features
- `api/` — HTTP endpoints
- `includes/` — application bootstrap, authentication, database and domain helpers (not public)
- `assets/` — CSS, JavaScript and uploaded files
- `database/` — versioned SQL migrations
- `logs/` — application logs (web access denied)
- `docs/` — deployment and operational notes

## Production deployment

1. Copy `.env.example` to `.env` on the server; do not commit `.env`.
2. Use PHP 8.1+ with PDO MySQL enabled.
3. Point Apache/Nginx document root at this project directory and enable the included access restrictions.
4. Create/import the MySQL database, then apply migrations in `database/` in filename order.
5. Ensure `assets/uploads/` is writable by the web server, but PHP/script execution remains disabled there.
6. Keep application logs and database backups outside the web root when possible.
7. Set `APP_ENV=production` and `APP_DEBUG=false`.
8. Enable HTTPS in production.

## Important

The production archive intentionally excludes the local `.env` file and development/test artifacts. Existing application routes and upload paths are retained to avoid breaking stored database references.

## Security hardening included
- Production fails closed when database credentials are missing.
- Session cookies use HttpOnly/SameSite and HTTPS Secure when applicable.
- Login brute-force throttling is enabled.
- Admin endpoints require admin role; state-changing POST requests require CSRF.
- Direct access to application internals, SQL, logs and private documents is blocked.
- Upload script execution is blocked and document access is routed through an authenticated controller.
- Production migration runner is intentionally excluded; apply SQL migrations through a controlled deployment process.

## XAMPP local development

For local testing with XAMPP, see `docs/XAMPP_LOCAL.md`. The local package includes a development-only `.env` for the default XAMPP MySQL configuration. Do not use that `.env` in production.
