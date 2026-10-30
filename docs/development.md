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

Before releasing a new version, run the scheduling tests, real MailPoet integration tests, and concurrent reservation tests. Check the admin without MailPoet, with an invalid form, as an unauthorized user, and at a narrow viewport. A successful handoff does not prove SMTP acceptance or receipt.

Update the version number, documented compatibility, and package together. Install, activate, and deactivate the new ZIP locally. Verify that no Starclock jobs or pending MailPoet queues owned by Starclock remain. Publish the verified source revision and package only after these checks pass. Keep GitHub Actions disabled.

## Test on Linux staging without customer data

On Linux, run the test process through `systemd-run` with
`RestrictAddressFamilies=AF_UNIX` and `IPAddressDeny=any` to block all Internet
sockets. Keep `NoNewPrivileges=yes` set. Disable `mail`, `exec`, `shell_exec`,
`system`, `passthru`, `proc_open`, and `popen` in the PHP process. Only the
separate database's private Unix socket is reachable.

The PHP sockets extension is required. `tests/isolation.php` must confirm that
the operating system denies both IPv4 and IPv6. The same guard must reject a
run without that block. A failed HTTP request or a timeout is not sufficient.
All five state-changing test programs use this shared guard. Never run them
against a customer database copy.
