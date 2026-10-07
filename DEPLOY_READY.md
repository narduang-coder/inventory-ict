# ICT Inventory — Final Deployment

## Required order
1. Create an empty MySQL/MariaDB database.
2. Import `database/inventory_db_base.sql`.
3. Run `database/migration_20260905_final_production_compat.sql`.
4. Run `database/migration_20261006_request_attachments.sql`.
5. Run `database/migration_20261007_request_attachments_blob.sql`.
6. Run `database/migration_20261007_stock_movement_condition.sql`.
7. Create `.env` in the project root using `.env.example`.
8. Set real server DB credentials:
   - DB_HOST
   - DB_NAME
   - DB_USER
   - DB_PASS
9. Use:
   - APP_ENV=production
   - APP_DEBUG=false
   - APP_BASE_URL=  (empty for domain-root deployment)
10. Apache DocumentRoot must point to this project root (the directory containing index.php).
11. Make sure PHP PDO MySQL and Fileinfo are enabled.
12. Make sure the upload directories are writable by the web server:
   - assets/uploads/items
   - assets/uploads/items/images
   - assets/uploads/items/documents
   - assets/uploads/attachments
   - assets/uploads/logos
   - Request attachments are stored in the database; this directory is still used for item attachments.
13. Test:
   - Login
   - Admin > Items: add equipment
   - Admin > Items: receive/restock
   - User > Return
   - User > TSG management
   - Admin > Requests: approve return
   - Admin > Issuance: issue/transfer
   - QR scan: /admin/qr_detail.php?id=ITEM_ID
   - Attachment upload/download
   - Document preview

## Important
Do NOT copy the old local `.env` from the development machine to production.
The final deployment ZIP intentionally does not contain `.env`.
