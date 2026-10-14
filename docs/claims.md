# Resource claims (development branch)

Claims connect an existing host resource to a newly created team after a moderation decision.
This backend is opt-in: it exposes no public routes or Control Panel screen and sends no mail.
`ClaimPolicy` denies requests and reviews until the host binds an implementation.

Register resource adapters under `teams.claims.resources` and bind
`Goldnead\Teams\Contracts\ClaimPolicy` in the host application. Each resource implements
`ClaimResource`: `lock(key, site)` locks and validates availability inside the SQL transaction,
`teamName(key, site)` returns the team name, and `bind(key, site, team)` performs the conditional
SQL binding. An adapter must use the same default database connection as Teams. It must not
perform external calls or mutate flat files during approval.

`ClaimService::request(type, key, site, actor, justification, contact)` keeps one pending claim
per resource, site and claimant. `approve(id, reviewer, note)` creates an owned team and binds
it atomically; `reject(id, reviewer, note)` requires a reason and can close a pending claim even
when its resource is no longer available. Self-review and replaying a decision are rejected.
Resource availability is rechecked under a row lock when approving. Locks are acquired in
resource, claimant-account, claim order. The account lock serializes quota checks across
different resources.

`teams.claims.limits` defaults to `max_per_account: 3`, `window_minutes: 1440` and
`rejected_cooldown_minutes: 1440`. Each value must be a non-negative integer; zero disables
that limit. The request limit counts all claims for the account created in the rolling window.
The rejection cooldown applies to the same resource type, key and site. A matching pending
claim is returned unchanged before either limit is checked.

The host policy must check the actual account and reviewer permission. Approval resolves the
claimant again and checks eligibility before creating a team. There is no inferred ownership
based on matching names or email addresses. Publishing remains the host's separate decision.

`TeamClaim` contains private evidence. Its normal array/JSON representation hides claimant,
contact, justification and decision actor/note. When `goldnead/statamic-accounts` is installed,
the addon conditionally registers a `team_claims` export and eraser. The export separates
applications from reviews. Applications include the claimant's resource, key, site, status,
team, timestamps, justification and contact. Reviews include the reviewer's resource, key,
site, status, timestamps and own decision note. It does not export internal account IDs,
reviewer identity to claimants, claimant identity or evidence to reviewers, or the internal
pending-deduplication key.

Account deletion clears claimant IDs, contact details, justifications, pending keys and
decision notes from the claimant's rows. Pending claims become `withdrawn`; approved and
rejected statuses stay as recorded. Reviews authored by the deleted reviewer lose their
reviewer ID and decision note. Resource, team, status and timestamps remain as claim history,
so this removes direct identifiers and free-form evidence but does not promise that retained
historical relationships cannot be linked by other information. The eraser does not remove
teams or memberships; Accounts' built-in Teams contributor owns those records. The migration's
down step refuses to run while anonymized rows have a null claimant ID, so rollback requires
those rows to be resolved first.

The durable, privacy-minimal `team_claim_outbox` has `id`, `claim_id` (cascade delete), `kind`
(`requested` or `decided`), `status` (status snapshot), `created_at`, `available_at` (defaults
to now), `attempts` (defaults to 0), nullable `last_error_code` (at most 64 characters) and
nullable `processed_at`, with one row per claim and kind. Request and decision rows are inserted
in their claim transactions. The consumer columns let a delivery job postpone blocked or erroring
rows (push `available_at` forward, count `attempts`, record `last_error_code`) instead of
starving on them. Erasure removes outbox rows for the erased claimant before anonymizing claim
history, and removes the claimant's account-lock row. No delivery consumer is included.

The claims workflow still exposes no public routes or Control Panel screen. Hosts need
authorized moderation views and a retention policy before exposing it.
Rejected claims can be submitted again with additional evidence after the configured cooldown.
Hosts should keep the limits enabled before exposing public endpoints.

`ClaimRequested`, `ClaimDecided`, `TeamCreated` and `MemberJoined` dispatch after the enclosing
database transaction commits. Failed approval must leave no team, member, binding or decision
event. The outbox records durable lifecycle snapshots only; reliable user notifications and
recipient checks remain integration work, with no delivery promise from these services.

This change is based on v0.3.1 in `codex/chorgesucht-claims`. No new release is published.
