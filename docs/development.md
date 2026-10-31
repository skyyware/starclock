# Test and package Starclock

## Run local checks

Use PHP 8.1 or later:

```sh
php tests/unit.php
python3 bin/package.py
```

The packager checks PHP syntax, scheduling tests, and the allowed package files. Output goes to `.runtime/dist/`. The ZIP contains the `starclock/` directory and excludes tests, local data, and development tools.

## Test the MailPoet integration

Use a separate WordPress installation with synthetic contacts only. Never use a customer database copy for testing. The test requires:

- `DB_NAME` starts with `starclock_test_`.
- `WP_HOME` is `http://starclock-test.invalid`.
- `WP_ENVIRONMENT_TYPE` is `local`, and `DISABLE_WP_CRON` is `true`.
- The PHP `mail` function is disabled. The operating system blocks outbound network connections. Only the private Unix database socket is reachable.
- A must-use guard blocks WordPress HTTP requests, `wp_mail`, and the asynchronous Action Scheduler runner.

Install and activate the MailPoet version you want to test. Run the following command through the isolated PHP process:

```sh
php /path/to/wp-cli.phar --path=/path/to/synthetic-wordpress eval 'require "/path/to/starclock/tests/integration.php";'
```

Do not use `eval-file`. WP-CLI prepends code to the file, which breaks `strict_types`. The `eval` command loads the file through `require`, so PHP checks it normally.

The test creates uniquely marked lists, contacts, templates, and run receipts. The real MailPoet worker selects recipients. A subsequent test filter removes all recipients before the blocked transport. Test schedules end paused. After the test, check for pending jobs owned by the test and stop the isolated database process. Keep the evidence. Do not clean up unrelated data.

`tests/handoff-failure.php` causes a failure after MailPoet has created a real queue. `tests/admin-security.php` checks permissions and nonces. `tests/concurrency.php` reserves the synthetic schedule specified by `STARCLOCK_RACE_PLAN`. `tests/tick-race.php` runs the full tick at a controlled test time. Run concurrent calls only in the same isolated test system. Afterwards, pause the test campaign. Keep the operating-system transport block active throughout.

The temporary `STARCLOCK_ALLOW_NONPRODUCTION` constant permits activation only for separately protected tests. It does not block sending and must not be used on a customer's staging site.

## Verify a release

The WordPress owner maintains the product and its demo together. Run this procedure when code, a supported dependency, or the demo installation changes. No scheduled refresh, repository pipeline, or unattended customer update is part of this procedure.

1. Confirm the current installation versions with the installation owner. Update `tests/matrix.json` when a required combination changes. Keep customer mappings and demo addresses in the private WordPress repository.
2. Update the plugin version, CSS cache version, changelog, and relevant documentation. Build the candidate with `python3 bin/package.py`.
3. Run every matrix case against a separate synthetic database. Use the exact PHP, WordPress, and MailPoet versions from the matrix. Run cases sequentially when they share a database server. Keep the OS transport block in place throughout.
4. Check the actual installed ZIP: activate, deactivate, reactivate, and compare every installed file with the archive. Inspect the admin at desktop and narrow widths. Verify missing MailPoet, invalid forms, and permission denial.
5. Run `python3 tests/release-gate.py`. Then pass the package and one successful report per matrix case to `python3 bin/release_check.py PACKAGE REPORT_CURRENT REPORT_PREVIOUS REPORT_REGRESSION`. Changed package bytes, test code, dependency versions, missing checks, or incomplete closeout invalidate the evidence.
6. Install the same candidate ZIP on the maintained demo. Record its previous version and active plugin order first. Keep MailPoet absent or inactive, schedules paused, and the nonproduction override absent. Compare installed files and verify the actual admin. Do not change unrelated plugins, users, settings, or content.
7. Audit the Git diff and ZIP for customer information and secrets. Commit and push explicit paths. Tag the verified source revision and upload the unchanged ZIP and checksum manifest through the existing manual release route. Keep GitHub Actions and dependency automation disabled.
8. Read back the published checksum and demo version. Record the package hash, source revision, checks, demo result, and recovery path in the private owner record. Send the customer owner the verified release and any limits. Customer installation and sending authorization remain with that owner.

If product checks fail, stop the release. If only demo access or security blocks the update, the owner may publish the independently verified package with that explicit exception. Keep the demo task open and report its actual state. Do not claim a completed product-and-demo update until both match. Keep the previous demo package and test evidence. Never retry a delivery to repair a test or release record.

The ZIP has normalized timestamps for reproducible hashes. If installing by direct extraction, refresh the installed files' timestamps or invalidate only their PHP cache entries. Check the actual rendered controls after deployment. Do not restart unrelated services or infer runtime freshness from matching disk hashes alone.

### Run a matrix case

Provision a fresh installation with the guards above and activate only its exact MailPoet version. Starclock must remain inactive while the tests use controlled dates. Set `WP_HOME` and `WP_SITEURL` to `http://starclock-test.invalid`. Never copy a customer database.

On macOS, pass the sandbox profile that denies outbound networking and permits only the private database socket:

```sh
python3 bin/verify.py --case current \
  --wp /absolute/path/to/synthetic-wordpress \
  --php /absolute/path/to/php \
  --wp-cli /absolute/path/to/wp-cli.phar \
  --package .runtime/dist/starclock-0.2.0.zip \
  --sandbox /absolute/path/to/isolation.sb
```

On Linux, run the command inside the restricted `systemd-run` process described below and omit `--sandbox`. The PHP guard refuses unrestricted processes before fixture creation. The runner extracts the candidate ZIP, adds only the source tests to its temporary copy, and checks those packaged classes. It saves results under `.runtime/verification/CASE/`, including the ZIP hash, test-suite hash, exact runtime versions, and closeout result.

The runner covers scheduling, native MailPoet integration, lost receipts, admin permissions, confirmed deletion, archive and restoration, eight concurrent ticks, eight concurrent reservations, competing archive/tick processes, inactive MailPoet, and retained internal tick evidence. Lifecycle tests check cached versus fresh worker state and attempt archive inside a real recipient batch. It pauses synthetic schedules and checks owned pending handoffs, cron events, and send statistics even after a test failure. After the final case, stop the private database process and retain its evidence. A successful handoff does not prove SMTP acceptance or receipt.

Before UI tests, record the installed version and existing plan IDs. Create only marked synthetic plans with sending disabled. Exercise archive, paused restoration, cancel, and confirmed delete at desktop and narrow widths. Retain their deleted audit records, identify them in the private closeout record, and verify no pending run or cron event remains. Never remove real plans to demonstrate this feature.

The current database lock name includes the table prefix, not the database name. Separate databases with the same prefix on one server can therefore return a temporary busy response to each other. Run matrix cases sequentially or use separate database servers. Do not weaken the lock or retry an uncertain handoff to hide this limit.

## Test on Linux staging without customer data

On Linux, run the test process through `systemd-run` with
`RestrictAddressFamilies=AF_UNIX` and `IPAddressDeny=any` to block all Internet
sockets. Keep `NoNewPrivileges=yes` set. Disable `mail`, `exec`, `shell_exec`,
`system`, `passthru`, `proc_open`, and `popen` in the PHP process. Only the
separate database's private Unix socket is reachable.

The PHP sockets extension is required. `tests/isolation.php` must confirm that
the operating system denies both IPv4 and IPv6. The same guard must reject a
run without that block. A failed HTTP request or a timeout is not sufficient.
All state-changing test programs use this shared guard. Never run them
against a customer database copy.
