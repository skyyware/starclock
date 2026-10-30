# Verification results

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
