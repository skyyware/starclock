# Starclock

Schedule recurring emails in WordPress. MailPoet manages content, mailing lists, unsubscribes, and delivery. Starclock adds weekly schedules, pause controls, and a record of each handoff.

Version 0.2.2. New schedules are disabled. Installation does not enable sending or create a server cron job.

Open source under the [MIT license](LICENSE). No license fees, license keys, or license activation. Retain the copyright and license notice when redistributing the plugin. WordPress and MailPoet retain their own licenses and are not bundled.

## Set up a schedule

The current Starclock admin uses German labels. The steps below retain those labels so you can find each control.

1. Install and activate `starclock-0.2.2.zip` through WordPress **Plugins → Add New Plugin → Upload Plugin**.
2. Create a standard newsletter draft in MailPoet. Set its subject, lists, sender, reply-to address, and unsubscribe link. Keep this draft as the template.
3. Under **Starclock → Neuer Zeitplan** (new schedule), select the template and weekly time. Check the time zone, start date, and sending window. Select **Pausiert speichern** (save paused).
4. Select **Vorlage und Zeitplan prüfen** (check template and schedule). Check the displayed recipients and sender. Review the content in MailPoet.
5. Verify that your hosting provider runs WordPress-Cron regularly, independently of page visits. Then select **Automatik aktivieren** (enable automatic sending).

Automatic sending starts at the next scheduled occurrence. Each run creates a separate standard campaign in MailPoet and leaves the template unchanged. New confirmed subscribers to the selected lists are included. MailPoet excludes unsubscribed contacts.

**Pausieren** (pause) stops future runs and pauses pending MailPoet campaigns owned by Starclock. A send already in progress may still reach recipients. Sent emails cannot be recalled. **Nächsten Lauf auslassen** (skip next run) skips exactly one occurrence.

Changes to the template, sender, or lists require a new check. Starclock does not automatically retry a failed handoff. Check the status in MailPoet first, then close the interrupted run in Starclock. The reserved occurrence remains blocked. You can enable the following occurrence.

## Archive or delete a schedule

Open **Starclock → Zeitpläne**. Select **Archivieren** to stop future runs and move a plan to **Archiv**. Pending owned campaigns must be paused before archival completes. If MailPoet is already processing a batch, the plan stays paused and reports that the operation is incomplete. Wait for that batch to finish, then try again. Emails already handed to the transport cannot be recalled.

Under **Archiv**, select **Pausiert wiederherstellen** to return a plan to the working list. Restoration never enables sending. Check the template and explicitly enable the plan again when ready.

To remove an unnecessary plan, pause or archive it, select **Löschen…**, and confirm **Zeitplan löschen**. **Abbrechen** leaves the plan unchanged. Active plans and unresolved handoffs cannot be deleted. Resolve ambiguous handoffs in MailPoet and Starclock first.

Deleted plans disappear from the admin and cannot be opened through old links or restored. Deletion removes schedule settings, not MailPoet templates, campaigns, lists, or contacts. Minimal internal records and run receipts remain outside the visible admin to preserve campaign ownership and the recipient guard. Histories for existing and archived plans remain available.

## Operation

- Requires PHP 8.1 or later and WordPress 6.5 or later. Individual sites in a multisite installation are supported. Network activation is blocked.
- The MailPoet adapter accepts only verified versions. Handoffs remain blocked on other versions. The allowed version range never expands automatically.
- Weekly schedules use an IANA time zone such as `Europe/Berlin`. A time skipped by a daylight-saving transition is omitted. A repeated time runs once, at its first occurrence.
- The sending window limits late handoffs and pending recipients. It ends no later than midnight. Missed days are not retried.
- Without an external trigger, WordPress-Cron depends on page visits. The operator must configure and verify the hosting provider's trigger. The internal options `starclock_last_tick` and `starclock_tick_error` retain diagnostic evidence. They are not shown in the schedule admin. See the [WordPress cron documentation](https://developer.wordpress.org/plugins/cron/).
- A changed website host or a non-production environment blocks execution. Before cloning a site, also isolate MailPoet and the mail transport.
- Changes require site-management and MailPoet email-management permissions, a valid WordPress nonce, and the current edit revision.
- Starclock stores schedules, template metadata, and run receipts in the site's WordPress database. It does not copy recipient lists or collect telemetry.

## Maintenance

Install updates manually from a verified ZIP. There is no remote control or automatic GitHub updater. Deactivation first pauses pending MailPoet campaigns owned by Starclock. If that fails, Starclock blocks deactivation to preserve the protection against late sending.

Schedules and run receipts survive plugin deactivation and removal. They prevent duplicate handoffs after reinstallation. Archived and deleted plans keep their state during deactivation. Do not downgrade below 0.2.0 after archiving or deleting plans: earlier versions do not understand those states. A code rollback alone does not restore data.

[Development and testing](docs/development.md) · [Verification results](docs/verification.md) · [Adapter contract](docs/adapters.md) · [Changelog](CHANGELOG.md)
