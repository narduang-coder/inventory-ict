# XAMPP Local Development Setup

## Requirements
- Windows + XAMPP
- Apache
- MySQL/MariaDB
- PHP 8.1+ recommended
- PDO MySQL enabled

## Install
Extract the project to:

`C:\xampp\htdocs\edl-inven`

Open:

`http://localhost/edl-inven/`

## Database
1. Start Apache and MySQL in XAMPP.
2. Open `http://localhost/phpmyadmin/`.
3. Create database `inventory_db` using `utf8mb4`.
4. Import the supplied `inventory_db.sql` base dump first.
5. Run `database/migrations/20260831_sct_statuses.sql` once. The migration is idempotent and safe to run again.

Do not run unrelated migrations blindly if your imported dump already contains their schema changes.

## Environment
The included `.env` is LOCAL ONLY:

- `APP_ENV=local`
- `APP_DEBUG=true`
- `APP_BASE_URL=/edl-inven`
- `DB_HOST=127.0.0.1`
- `DB_NAME=inventory_db`
- `DB_USER=root`
- `DB_PASS=`

If your local XAMPP MySQL root has a password, set `DB_PASS` accordingly.

Never use this `.env` in production.

## Local test order
1. Login as admin.
2. Login as normal user.
3. Inventory create/edit/delete.
4. Stock receive/issue/return/adjustment.
5. Upload valid PDF/image.
6. Verify PHP/SVG/double-extension uploads are rejected.
7. Verify document authorization.
8. Create SCT request.
9. Approve/reject SCT.
10. Issue SCT and verify stock.
11. Complete/return/cancel and verify stock/history.
12. Logout and verify protected pages cannot be reopened.

## Apache
The included `.htaccess` uses Apache 2.4 directives. Ensure `AllowOverride All` is enabled for the project directory. If Apache returns 500, check `C:\xampp\apache\logs\error.log`.
