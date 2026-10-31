# Verification results

## Release 0.2.2 on 31 October 2026

All three combinations in `tests/matrix.json` passed 148 named assertions each, plus the eight-process reservation check. Five release-gate tests passed. The new visibility tests first failed against 0.2.1 because it still rendered the deleted-plan view, then passed against 0.2.2.

The admin has two destinations: Zeitpläne and Archiv. Deleted IDs behave like missing IDs, including old confirmation links and combined view/new parameters. The retired `view=deleted` URL returns the working list. Tests cover deleted records with native handoff receipts, preserved internal state, histories for active and archived plans, paused restoration, concise deletion feedback, and the overview redirect. Permission, nonce, stale-revision, and sending-guard checks remain intact. No data migration was added.

Native Browser checks covered both views at 1280px, 390px, and 320px, plus old deleted-plan URLs on the installed local demo. Navigation has 48px targets without clipped labels or horizontal overflow. Tab, Shift+Tab, Enter, hover, and visible keyboard focus passed; narrow-screen links have equal widths. These are focused checks, not a complete accessibility audit.

The demo's existing deleted synthetic record, revision, plugin order, and empty run history remained unchanged. No demo plan was created or deleted. MailPoet and the nonproduction override stayed absent. The isolated matrix passed closeout with no active plans, pending handoffs, cron events, or send statistics; transport remained blocked. No customer installation or public Stage changed during these checks. SMTP delivery and the declared minimum dependency versions remain outside this matrix.

## Release 0.2.1 on 31 October 2026

The three required compatibility combinations passed 130 named assertions each, plus the eight-process reservation check. Six additional rendering checks preserve navigation labels, destinations, the current page, the unknown-view fallback, and archive/deleted detail selection. Five release-gate tests passed. Scheduling, permissions, lifecycle operations, and MailPoet integration code are unchanged.

Native Browser checks covered all three admin views at 1280px, 390px, and 320px. The navigation stays in one row without clipped text or horizontal overflow. Targets are 48px high on desktop and 60px high on narrow screens. Tab, Shift+Tab, Enter, hover, and contrasting keyboard focus were exercised on the installed candidate. These are focused navigation checks, not a full accessibility audit.

The existing local demo record, its revision, and plugin order remained unchanged. No plan was created, archived, restored, or deleted for this visual change. MailPoet and the nonproduction override remained absent; no pending handoff, run, or Starclock cron event was present. The isolated matrix retained its transport denial and passed closeout. No customer or public Stage installation changed.

## Release 0.2.0 on 31 October 2026

The package passed the three combinations in `tests/matrix.json`: WordPress 7.1.4 / PHP 8.3.36 / MailPoet 5.44.0, and WordPress 7.0.4 / PHP 8.4.27 with MailPoet 5.35.1 and 5.39.0. Each case passed 124 named assertions plus the eight-process reservation check. Five release-gate tests passed.

New checks cover archived and deleted plan states, paused restoration, stale revisions, explicit deletion confirmation, permissions, retained receipts, and unchanged MailPoet audience and content counts. Eight competing archive/tick processes settle with at most one handoff, no pending owned queue, and no later execution. A cached idle task cannot hide a provider-side running batch. Archive attempted inside the actual MailPoet worker remains incomplete until that batch ends. A resumed deleted campaign reaches the worker with no permitted recipients.

The maintained local demo passed archive, find-in-archive, paused restoration, delete cancellation, and confirmed deletion. The resulting record is read-only. Native Browser checks covered 1280px desktop and 390px / 320px narrow views without horizontal overflow. MailPoet remained absent, the nonproduction override absent, and the only fixture was a marked plan without recipients or runs. Deactivate/reactivate preserved its deleted state. Private evidence identifies that retained test record and the exact package.

During UI iteration, repeated direct ZIP extraction preserved the normalized archive timestamps. The PHP server continued rendering the preceding confirmation layout despite changed file bytes. Refreshing the installed candidate's file timestamps and reloading exposed the new layout. Direct-extraction updates must refresh the relevant PHP cache or file timestamps and verify the rendered UI, not just disk hashes. WordPress-managed uploads use their own installation path.

All mail integration tests used isolated synthetic databases with OS network denial and disabled PHP mail. Closeout found no active plans, pending owned handoffs, Starclock cron events, or send statistics. Archived/deleted audit records are intentional retained evidence. No customer plan, recipient, Stage installation, Live installation, or sending authorization changed. SMTP receipt and the declared minimum dependency versions are not established by this release's matrix.

## Release 0.1.2 on 30 October 2026

The candidate ZIP passed the following exact combinations in fresh synthetic installations on macOS:

| WordPress | PHP | MailPoet | Result |
| --- | --- | --- | --- |
| 7.1.4 | 8.3.36 | 5.44.0 | Passed |
| 7.0.4 | 8.4.27 | 5.35.1 | Passed |
| 7.0.4 | 8.4.27 | 5.39.0 | Passed |

Each case passed 75 named assertions across scheduling, integration, lost receipts, admin security, markup, concurrent ticks, retained internal tick evidence, inactive MailPoet, and closeout. Eight simultaneous reservations produced one winner and seven rejected duplicates. Eight full ticks produced exactly one native MailPoet campaign. The WordPress-Takt section is absent with active and inactive MailPoet; schedule controls remain. Rendering does not change diagnostic options.

Every PHP test process ran under an OS network denial with PHP mail and process-execution functions disabled. WordPress HTTP, WordPress mail, and asynchronous Action Scheduler requests were blocked. All contacts were synthetic. Final checks found no active schedules, pending owned handoffs, Starclock cron events, or newsletter send statistics.

`tests/matrix.json` defines the required combinations. `bin/verify.py` runs packaged code, saves exact runtime versions, and binds evidence to ZIP and test-suite hashes. `bin/release_check.py` rejects missing, failed, or stale evidence. Five gate tests cover valid evidence and negative cases, including changed versions, hashes, missing checks, incomplete closeout, and duplicate reservations.

Running two matrix cases at once on one database server exposed shared named-lock contention between databases with the same table prefix. Sequential cases pass; within-site concurrency remains tested. The scheduler lock implementation is unchanged. This is an operational limit, not evidence of duplicate sending.

No customer installation, real recipient, hosting schedule, or delivery setting was changed by these product tests. The private owner record tracks demo installation and any access or security exception separately. PHP and WordPress minimum versions are not newly certified by this matrix. SMTP delivery and Linux execution of 0.1.2 remain outside these checks.

## Isolated tests on 29 October 2026

Starclock 0.1.0 was tested exclusively with synthetic contacts. Outbound networking was blocked by the operating system, and PHP mail was disabled. An additional recipient filter stopped every message before transport.

| Environment | Result |
| --- | --- |
| PHP 8.1 and 8.4 | 24 scheduling and input checks passed on each version. |
| WordPress 7.0, MailPoet 5.39.0, PHP 8.4 | 28 integration tests passed. |
| WordPress 7.1.4, MailPoet 5.44.0, PHP 8.4 | 28 integration tests passed. |
| WordPress 7.1.4, MailPoet 5.44.0, PHP 8.1 | 28 integration tests passed. |
| Lost acknowledgement after actual queue creation | 5 checks passed. The native queue is paused without an automatic retry. |
| Admin permissions, HTTP method, and nonce | 4 checks passed. Missing permissions or an invalid nonce prevent mutations. |
| 8 concurrent reservations | 1 winner, 7 rejected duplicates. |
| 8 concurrent full MailPoet runs | Exactly 1 campaign with a handoff receipt, then safely paused. |
| Native browser checks | Save, check, blocked test environment, isolated activation, and pause verified. |
| Installed ZIP | Styles and controls checked at actual CSS widths of 320, 769, and 1280 pixels. No horizontal overflow. |

The integration tests cover confirmed list members, global and list-specific unsubscribes, unchanged templates, modified weekly copies, expired sending windows, stale forms, skipped occurrences, changed website addresses, and deactivation with pending campaigns. Unrelated MailPoet campaigns remain permitted.

During package verification, the running test server retained an old PHP path after a development symlink was replaced with the extracted plugin. The browser exposed the incorrect stylesheet URL. Restarting the isolated server restored the correct plugin URL and styles. After this type of installation change, verify the actual asset URL. A CSS file in the ZIP is not sufficient evidence.

The WordPress notice about an overdue Action Scheduler task came from the isolated test installation, where cron was deliberately disabled. It is not a finding about a customer server. Screenshots show that test installation without alteration.

After the tests, no active Starclock schedules, pending owned handoffs, Starclock cron events, or MailPoet send statistics remained. The test constant was removed, the UI test plugin was disabled, and the test processes were stopped. Synthetic data and evidence remain available locally for reproduction.

These isolated tests did not prove SMTP delivery, a production host cron job, or approval of a real template and its recipients. No customer automation was installed or enabled during these tests. The declared minimum WordPress version, 6.5, has not been tested. The table lists the versions actually checked.

## Linux staging tests on 30 October 2026

A separate synthetic WordPress installation on the staging server used
WordPress 7.0.4, PHP 8.4.27, and MailPoet 5.44.0. It passed 24 scheduling checks,
28 integration checks, 5 lost-handoff-receipt checks, and 4 permission and nonce
checks. Eight concurrent full ticks created exactly one campaign. Eight
concurrent reservations produced exactly one winner. The unchanged 0.1.0 ZIP
passed two activation and deactivation cycles.

Linux denied IPv4 and IPv6 sockets with error 97. However, `fsockopen` returned
error 0 under that restriction. The earlier macOS-specific check for error 1
was therefore not portable. The shared test guard now checks both socket
families directly on Linux. It rejected an unrestricted run and accepted the
restricted run. The existing macOS sandbox still passes. A timeout alone is
not accepted as proof of a network block. This test guard is available from
commit `767d540`. The installed plugin code remained release 0.1.0.

The database was new, entirely synthetic, and reachable only through its
private Unix socket. PHP mail, external network connections, WordPress HTTP
requests, and asynchronous Action Scheduler calls were blocked. No public
test endpoint was created. After completion, no active schedules, pending
handoffs, Starclock cron events, or send statistics remained. The plugin was
deactivated in the test system, and the private database process was stopped.
SMTP delivery was not tested in this environment.

## Bounded live test on 30 October 2026

Release 0.1.1 was installed on WordPress 7.1.4, PHP 8.3.36, and MailPoet 5.44.0.
All 16 package files matched the release manifest. The license and
documentation changed from 0.1.0. The scheduler did not change.

An explicitly authorized internal delivery test used only a controlled test
address. An additional guard for that installation checked the schedule,
expiry, and exact recipient before each batch and again before SMTP. Cc and
Bcc were excluded. An atomic reservation allowed exactly one transport
attempt. The production schedule remained disabled and separately blocked.

WordPress-Cron triggered the test about two minutes after the scheduled time.
Starclock created exactly one run, one MailPoet copy, and one queue receipt.
A further targeted tick did not create a second campaign. The SMTP receipt
and the received message had the same Message-ID. The message arrived in the
inbox, not spam. SPF, DKIM, and DMARC passed. Unsubscribed and unconfirmed test
contacts did not receive a message.

Personalization, HTML and plain-text versions, and the unsubscribe option
were checked in the received message. The MailPoet web view had no horizontal
overflow at 390 CSS pixels. This was not a mobile email-client test. Private
mail content, addresses, account data, and screenshots remain outside this
public repository.

After completion, the test authorization was closed and the test schedule
was paused. No active schedule, pending owned queue, or Starclock cron event
remained. Test contacts, the test list, and test campaigns were soft-deleted.
Run and transport receipts were retained. Regular sending was not enabled.

This test proves one native trigger and delivery on the tested combination.
It does not prove an independent hosting-provider trigger or future delivery
to every recipient. Each installation needs separate checks for both.
A successful test does not enable customer automation.

## Independent host trigger check on 30 October 2026

The hosting control panel accepted a one-minute HTTPS job. The provider called
a short-lived probe at 18:16:01 UTC without a page visit or manual GET request.
The probe wrote one receipt without loading WordPress, MailPoet, or any mail
transport. Twelve local checks covered invalid requests, expiry, and duplicate
calls. A HEAD request to the deployed probe returned 405 as expected.

The job was disabled before its target was changed to WordPress-Cron. The saved
job remains disabled, without an output-email address. This verifies the
provider's independent HTTP trigger separately from the delivery test above.
It does not prove a full host-to-mail run. Activating the WordPress target also
allows other due WordPress tasks to run and needs its own controlled release.
The production schedule and delivery guard remain closed.
