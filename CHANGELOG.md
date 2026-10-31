# Changelog

## 0.2.1 · 2026-10-31

- Refined the three admin views with quieter navigation, a clear current-page indicator, and contrasting keyboard focus.
- Kept all views in one row on narrow screens with larger touch targets. Labels, URLs, permissions, and scheduling behavior are unchanged.

## 0.2.0 · 2026-10-31

- Added archive, paused restoration, and confirmed deletion with separate admin views.
- Retained run receipts and campaign ownership after deletion. MailPoet templates, campaigns, lists, and contacts remain untouched.
- Blocked archive and delete completion while MailPoet processes an owned batch or a handoff remains unresolved.
- Added fresh worker-state checks, lifecycle and admin tests, and concurrent archive/tick coverage.
- Preserved archived and deleted states during deactivation. Older plugin versions cannot safely read these new states.

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
