# Starclock

Standalone WordPress plugin. Keep site names, subscriber data, customer URLs and credentials out of this repository. MailPoet owns composition, consent and transport; Starclock owns schedules and receipts.

Write repository documentation in English. Preserve exact code identifiers, commands, and existing UI labels when explaining a control. Documentation translation does not change product UI strings or historical release archives.

Use explicit reviewed paths for commits. Sascha authorized public open-source distribution under MIT on 30 October 2026. Audit tracked history and release contents before publication. GitHub Actions and dependency automation remain disabled; run checks locally with `php tests/unit.php` and the documented isolated WordPress integration route.

New schedules start paused. Tests use synthetic contacts and an operating-system transport block. Never test on a customer database or activate a real mailing as part of a code test. Changes to internal MailPoet interfaces require an actual compatibility test before expanding the version allowlist.

Preserve run receipts. Never retry an ambiguous handoff automatically. Deactivation must pause owned pending campaigns before removing the final recipient guard. No automatic uninstall deletion.

No narrative code comments. Keep only the WordPress-consumed plugin header and required legal notices. Follow the current task's authority, native Browser and delivery rules.

The WordPress owner maintains the product and its demo. Follow `docs/development.md` for every release. `tests/matrix.json` is the required compatibility matrix. Run its cases sequentially on shared database servers. The package and test-suite hashes bind reports to the candidate; `bin/release_check.py` rejects stale or incomplete reports. The normal completion condition is the same verified package on the demo. A demo security or access blocker must stay explicit and open; it does not invalidate independently passed product checks. Customer deployment remains with the customer owner. This is an event-driven manual workflow, not permission to create jobs or enable sending.
