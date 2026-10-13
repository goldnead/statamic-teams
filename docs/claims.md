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
Resource availability is rechecked under a row lock when approving.

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

The claims workflow still exposes no public routes or Control Panel screen. Hosts need
authorized moderation views and a retention policy before exposing it.
Rejected claims can be submitted again with additional evidence. Hosts must add submission
rate limits and a repeat-request policy before exposing public endpoints; pending deduplication
does not prevent abuse through repeated rejected requests.

`ClaimRequested`, `ClaimDecided`, `TeamCreated` and `MemberJoined` dispatch after the enclosing
database transaction commits. Failed approval must leave no team, member, binding or decision
event. After-commit dispatch is not a durable notification outbox: reliable user notifications
and recipient checks remain integration work, with no delivery promise from these services.

This change is based on v0.3.1 in `codex/chorgesucht-claims`. No new release is published.
