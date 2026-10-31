# Adapter contract

An extension implements `Starclock\Adapter` and registers it through the `starclock_adapters` WordPress filter. The registry key must match `id()`. Do not overwrite an existing registration.

| Method | Contract |
| --- | --- |
| `id()` | Stable key containing lowercase letters, digits, underscores, or hyphens. Maximum 40 characters. |
| `label()` | Name displayed in the template selector. |
| `available()` | Checks dependencies and tested versions. Throws an exception when the configuration is unsupported. |
| `choices()` | List of positive integer `id` values and readable `label` values. Offers unpublished templates only. |
| `inspect()` | Returns `fingerprint`, `subject`, `sender`, `reply_to`, and `lists`. The fingerprint covers content, recipient selection, and sender, but not changing list membership. With `requireDraft=false`, it also checks a copy that has already been handed off. |
| `duplicate()` | Creates an unsent draft. Does not queue or send mail. |
| `enqueue()` | Hands off only the copy and returns a durable receipt. An ambiguous response is an error, not permission to retry. |
| `pause()` | Pauses the pending copy at the provider. Returns only when no batch that could already have passed the recipient guard is in progress. Read fresh provider state; cached idle state is insufficient. May return without changes after completed sending. Throw while stopping is incomplete. |
| `deliveryState()` | Returns `draft`, `queued`, `completed`, `paused`, or `cancelled`. Completion at the provider does not prove actual delivery. |

The extension must call `Engine::permits(adapter, externalId, now)` immediately before each recipient batch. If that check throws, sending must remain blocked. The engine leaves unknown campaigns unchanged. An adapter must not register as capable of sending without this final check.

The engine atomically reserves each schedule occurrence. It stores the external copy ID before calling `enqueue()`. A unique database key prevents a second handoff, including concurrent attempts. An interrupted process leaves a reserved or uncertain run that requires manual review.

Plan mutations and ticks share the database lock. Archive first pauses the plan and clears approval, then stops owned queues. It completes only after pending handoffs are resolved. Restore returns to paused. Delete retains a minimal plan record and every run receipt; the ownership check must continue to deny those campaigns. No adapter method deletes provider content.

The MailPoet worker persists `in_progress` before selecting a recipient batch. The adapter refreshes the native scheduled task before and after pausing it. A batch that already passed the guard therefore prevents a successful archive until the worker releases it. The release matrix tests both stale cached state and archive attempts inside the actual worker. Transport acceptance that happened earlier is not reversible.

The MailPoet adapter uses internal classes because the public API does not provide a suitable recurring standard campaign. `MailPoetAdapter::TESTED_VERSIONS` contains the exact version list. Adding a version requires a passing integration test with the real MailPoet queue and its recipient filter.
