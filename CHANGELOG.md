# Changelog

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
