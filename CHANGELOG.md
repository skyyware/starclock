# Changelog

## 0.1.2 · 2026-10-30

- Removed the WordPress-Takt admin section. Internal tick, error, and run records remain unchanged.
- Added MailPoet 5.35.1 to the tested adapter versions.
- Added package-bound compatibility checks, admin regression tests, and explicit test closeout checks.
- Defined a manual release workflow that keeps the maintained demo on the verified package.

## 0.1.1 · 2026-10-30

- Licensed the plugin's own code under MIT. No license keys or license fees.
- Scheduler and MailPoet adapter unchanged from 0.1.0.

## 0.1.0 · 2026-10-29

- Weekly schedules with a time zone, start date, bounded sending window, and individual skipped occurrences.
- Separate save, check, and enable steps. Overview with the next occurrence and run history.
- MailPoet adapter with draft duplication, confirmed queue handoff, and a check before each recipient batch.
- Atomic reservations, stale-form protection, a pause after template changes, and visible handling of interrupted runs.
- Extensions through `Starclock\Adapter`, manual release packages, and retained run receipts.
