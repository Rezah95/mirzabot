# Changelog

## 0.5.10 — 2026-10-01

- Fix cron registration selecting an executable without MySQL support: probe PHP CLI >= 8.2, `mysqli_connect` and `pdo_mysql`, and pin the verified executable. VPS updates register the dispatcher using the migration-compatible CLI as `www-data`.
- Detect missing PHP/MySQL dependencies before bootstrapping jobs, explaining `mysqli_connect()` failures without cascading null-PDO errors.
- Save cron status at startup and before/after every job; record exit/die interruptions, missing scripts, lock contention and status-write failures. Show job errors and running states in the admin health menu.
- Return from the bulk-message worker when its lock is busy instead of terminating all following cron jobs; contain lottery bootstrap errors within normal job handling and use a fixed schedule tick.
- Replace this bot's cron entries in one operation, preserve unrelated entries, keep the prior crontab on failure, and stop reporting false repair success.
- Add isolated tests for absent MySQL extensions, PHP selection, lock contention, interrupted jobs, registration failures and continuation. No database or system crontab is used by these tests.

Upgrade: install/enable MySQL extensions for the PHP CLI used by the VPS and upgrade the bot; refresh cron registration if an old generic PHP path remains. This release is a compatible patch following 0.5.9. Previously compensated Tronado invoices remain subject to the 0.5.9 reconciliation policy.

## 0.5.9 — 2026-09-30

- Fix Tronado callbacks being acknowledged but left queued on PHP handlers without `fastcgi_finish_request`. Attempt immediate delivery on all handlers; retain cron recovery.
- Record callback arrival, acceptance and fulfillment, and log failed Telegram reports. Add read-only callback/order diagnostics to the administrator's Tronado settings.
- Restore the cron dispatcher's working directory and error-log destination after the Tronado job.
- Prevent automatic replay of historical invoices that may have been manually credited during the incident; these require reconciliation (`review`). New invoices use the corrected automatic delivery path.
- Test real HTTP acknowledgement before slow notifications, wallet credit and Tronado reporting without cron, duplicate callbacks, cron recovery and historical-credit protection using isolated MySQL.

### Upgrade and version migration

- The legacy release `0.5.8.17` is followed by SemVer `0.5.9`, tagged `v0.5.9`. Existing tags are immutable. Installer numeric-tag filtering and version sorting accept this transition. Future tags retain the `v` prefix.
- Existing pending invoices must be reconciled with manual wallet credits before manual settlement. No historical orders are automatically replayed by this upgrade. No credential or callback-URL changes are required.
- Published on `dev` and `master` under the maintainer's standing release authorization. No deployment to the live VPS is performed by the repository release.
