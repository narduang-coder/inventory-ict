# SCT Department Stock Rule

## User side
For a normal user, an SCT can use **only the authenticated department's current stock** from `item_department_stock`.

When the SCT is successfully created:
1. The requested quantity is locked and checked in the department stock row.
2. The quantity is deducted immediately.
3. A row is written to `sct_department_reservations`.
4. The old historical request totals are not used as available stock.

Therefore a second SCT cannot reuse the quantity already consumed/reserved by the first SCT.

## Approval / Issue
For department-source SCTs, the admin approval step does not deduct warehouse stock.
The admin issue step records the handover and does not deduct the same department stock a second time.

The SCT detail page shows the department stock remaining after reservation.

## Rejection / Cancellation
If a department-source SCT is rejected or cancelled while its reservation is still active, the reserved quantity is returned to `item_department_stock`.

If an issued department-source SCT is cancelled, its reserved/issued quantity is returned to the department stock.

## Local XAMPP migration
After importing the main database, run:

`database/migrations/20260831_sct_department_stock.sql`

This migration is safe to run repeatedly because it uses `CREATE TABLE IF NOT EXISTS`.
