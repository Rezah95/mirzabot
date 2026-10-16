# Changelog

## 0.7.1 — 2026-10-16

- Promote the locally validated 0.7.1-dev.1 integration with maintainer approval: upstream panel drivers and WireGuard delivery retain fork discount, payment, renewal-reminder and cron protections.
- Preserve invoice expiry/depletion cache maintenance and prevent same-minute cron repeats using independent start timestamps. Fix the leftover table.php merge brace.
- Remove unused alireza_single and s_ui panel integrations. Upgrade through the normal updater/db bootstrap after a backup; no live deployment is performed.
- Validation: PHP syntax checks, isolated dispatcher/renewal/gateway/broadcast/bulk tests, and temporary-MySQL callback and reminder integration tests passed. Graphify refreshed locally; generated artifacts remain ignored.

## 0.7.1-dev.1 — 2026-10-16 (prerelease)

- Integrate upstream 0.7.1, including the provider registry, Nexora/WGMate drivers, WireGuard delivery, low-balance alerts and order cleanup.
- Resolve merge conflicts while retaining the fork's safe discount redemption, payment fulfillment, renewal reminders and cron runtime diagnostics.
- Maintain invoice expiry/depletion caches after successful driver operations; preserve zero for unlimited WireGuard expiry. Return an explicit failure for unsupported driver operations.
- Prevent same-minute dispatcher repeats using a separate start timestamp; retain interruption, dependency and lock diagnostics.
- Remove duplicated legacy schema setup from table.php and retain db/bootstrap.php, report-topic synchronization and webhook secrets.
- Adapt renewal and gateway navigation regression fixtures to the integrated driver and explicit-navigation contracts; add dispatcher/cache coverage.

Compatibility: unused alireza_single and s_ui panels are removed. Before 1.0, features or incompatible changes increment the minor line; retain the incoming 0.7.1 target above 0.5.10. Back up and run the normal updater/schema migration. No live deployment or provider verification is included. Local Graphify output remains ignored by repository policy.

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
