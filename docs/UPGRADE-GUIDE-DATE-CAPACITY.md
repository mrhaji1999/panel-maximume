# Upgrade Guide – Date-based Capacity Reservations

Follow these steps to roll out the date-aware capacity changes safely.

## 1. Preparation
- **Back up the database** (especially the `wp_ucb_reservations` table).
- Ensure the site is running WordPress 6.x and PHP 7.4 or higher.

## 2. Deploy the updated plugins
- Deploy the new versions of **User Cards Bridge** and **User Cards**.
- Clear any opcode/object caches that might cache plugin files (OPcache, Redis, etc.).

## 3. Run the database migration
- Visit any WordPress admin page as an administrator. The `ReservationDateMigration` will add the `reservation_date` column and indexes automatically.
- Alternatively, run the migration manually via WP-CLI:
  ```bash
  wp eval "\\UCB\\Migrations\\ReservationDateMigration::migrate();"
  ```
- Verify the table now contains the `reservation_date` column and the indexes `reservation_date` and `date_slot`:
  ```sql
  SHOW COLUMNS FROM wp_ucb_reservations LIKE 'reservation_date';
  SHOW INDEX FROM wp_ucb_reservations WHERE Key_name IN ('reservation_date','date_slot');
  ```

## 4. Clear caches and refresh schedule data
- If you use persistent object caching, flush the `ucb` cache group or run `wp cache flush`.
- If there is a custom cache layer for availability, clear it so the new per-date counts are visible immediately.

## 5. Functional testing
1. Call the REST endpoint with a specific date and ensure the slot payload contains `weekday`, `hour`, `capacity`, `used`, `remaining`, and `is_full`:
   ```bash
   curl "https://example.com/wp-json/user-cards-bridge/v1/availability/123?date=2025-10-20"
   ```
2. Create a reservation for a partially used slot via the REST API (or UI) and verify `used` increments and `remaining` decrements.
3. Attempt another reservation for the same slot once capacity is reached and confirm the API returns HTTP `409` with the translated “slot full” error.
4. Repeat the reservation test for the same weekday on the following week (e.g. `2025-10-27`) to confirm it is independent from the previous week.
5. Open the front-end booking popup, pick a date, and check that full slots appear disabled and the submission includes the `reservation_date` parameter.
6. Inspect browser console and server logs to ensure there are no PHP or JavaScript warnings.

## 6. Post-deployment checklist
- Inform support staff that 409 responses now indicate a full slot for the specific date.
- Update any monitoring/alerting that parses reservation payloads to include the new `reservation_date` field.
