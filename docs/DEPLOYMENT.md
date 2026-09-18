# Deployment checklist

## Server
- PHP 8.1+ / MySQL 8.x or compatible MariaDB
- PDO MySQL enabled
- HTTPS enabled
- Apache `mod_rewrite`/authorization support if using `.htaccess`

## Environment
Create `.env` from `.env.example` and provide real database credentials.
Never use the development `root` account for production.

## Database
Apply the SQL files in `database/` from oldest to newest. Back up the database before migrations.

## Filesystem
The web server must write to `assets/uploads/` only. Do not make `includes/`, `database/`, or `logs/` writable by the web process unless strictly required.

## Verification
- Login as admin and normal user
- Dashboard loads
- Item CRUD works
- Upload/download/view attachment works
- Request/approval/issuance flow works
- Stock movement and adjustment work
- SCT process pages work
- Logout works
- Confirm `.env`, `.sql`, and logs are not directly downloadable


## Production security checklist
- Copy `.env.example` to `.env` and set `APP_BASE_URL`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `APP_ENV=production`, `APP_DEBUG=false`.
- Use a dedicated MySQL account with only the privileges required by `inventory_db`; never use MySQL `root`.
- Serve over HTTPS and keep the application behind a current Apache/Nginx + PHP version.
- Keep `assets/uploads/items/documents` and `assets/uploads/attachments` inaccessible directly; the included `.htaccess` files enforce this on Apache.
- Keep external log/backup directories outside the public web root.
- Apply files in `database/` through a controlled deployment process before application rollout. The production migration runner is intentionally removed.
- After deployment, verify `APP_DEBUG=false`, HTTPS, session cookies, login throttling, document access authorization and backup permissions.
