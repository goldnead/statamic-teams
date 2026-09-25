# Changelog

## 0.3.1 — 2026-09-26

Findings from the review of 0.3.0.

### Security

- **No widening through "back to the global role".** Deleting a team's narrower version of a
  global role hands its holders the global permissions back. A role editor may now only do that
  when the global role holds nothing more than they do; owners and the system still may.
- **A new global role no longer takes over team roles of the same handle.** Creating one (or
  resetting a deleted config role) whose handle teams already use for a role of their own is
  refused with `role_handle_in_teams` (409, the teams in `details.teams`). Before, the team roles
  silently turned into "replaces global".
- **No silent fallback to the config.** The stored global roles fall back to `teams.roles` only
  when their table is missing. Any other database error is thrown; before, it returned the config,
  and a role deleted or narrowed in the CP got its config permissions back.

### Fixed

- Deleting a role counts its holders inside the transaction (rows locked) and again after the
  delete; somebody given the role in between rolls the deletion back instead of holding a role that
  no longer exists.
- Team roles are read once per request or job instead of once per `Teams::can()`, and forgotten on
  every write through the model. README: what a long-running job sees.
- Roles page: the row menu sits next to the name, so it is reachable at 1440 px although the matrix
  scrolls sideways; cells without a tick carry "No" for screen readers; "Reset to default" says
  which name and permissions change.

## 0.3.0 — 2026-09-26

Roles are managed, not only configured.

### Added

- **Teams → Roles** in the CP: every global role with one column per permission, create, rename,
  change permissions (core publish form, a checkbox per permission), reset to the config, delete.
  Needs the new Statamic permission `manage team roles`; `manage teams` is not enough.
- **Roles panel on the team page:** every role that applies there, marked global or team-own;
  create a role for this team, adjust a global role for it, delete or go back to the global one.
- Global roles changed in the CP are stored in the new table `team_global_roles` and win over
  `teams.roles`, which stays the starting point. **Run `php artisan migrate`.**
- Facade: `createRole()`, `updateRole()`, `deleteRole()`, `resetRole()`, `roleUsage()`,
  `permissions()`, `registerPermission()`. `roles()` now says `scope`, `source` and
  `overrides_global` per role. See README, "Managing roles", also for an app-api endpoint sketch.
- Team permission `manage team roles` (in `teams.permissions`, held by owners, not by `admin` by
  default): a member may change their team's roles through the API only with it, and only within
  what they hold.
- Events `teams.role.created`, `teams.role.updated`, `teams.role.deleted`, with triggers in
  automations and the webhook manager, activity entries, and rows on the Wiring page.
- Refusal reasons `role_exists`, `role_protected`, `role_in_use` (409, with `details`),
  `unknown_permission`, `wildcard_not_allowed`, `invalid_role_handle`. `TeamsException` carries
  `details`, also in `toArray()`.
- Permission labels in `lang/*/permissions.php`.

### Security

- `*` only in the owner role; the owner role keeps it and can only be renamed. Owner role and
  default role cannot be deleted. A role somebody holds is deleted only with a role to move them
  to, never the owner role. Nobody widens a role beyond what they hold, their own role included.

## 0.2.0 — 2026-09-25

Findings from the ChoirLive end-to-end check.

### Changed

- **A personal team no longer announces its owner joining.** Creating it fires `teams.team.created`
  (with `team_type = personal`) and no `teams.member.joined`; the owner is still its member. Before,
  every registration on a site with personal teams fired `member.joined` and ran the flows,
  webhooks and the "member joined" mail meant for real teams. A regular team still announces its
  founder (`via = created`). Anything that relied on `member.joined` for personal teams should
  listen to `team.created` instead.

### Added

- `team_type` at the top level of every event payload, the same value as `team.type`, so a
  webhook filter or flow condition reading only the first level can tell the types apart.
- Automations: every Teams trigger has a **Team type** setting (select from `teams.types`). Empty
  fires for all teams, a type only for teams of that type.

### Upgrading

- No migration, no new config key.

## 0.1.1 — 2026-09-25

### Fixed

- `lang/de.json` no longer translates "Events", "Activity", "Automations" and "Webhook Manager" globally (it renamed the Events addon to "Ereignisse" in the addon list). The wiring page uses keys under `teams::cp`.

## 0.1.0 — 2026-09-25

### Added

- Teams with members, roles per team (config plus `team_roles` per team) and free `meta` per membership.
- Invitations by link: token stored as sha256 hash, bound to the invited address, expiring, single use; inviting again replaces the link.
- Join codes without look-alike characters, normalised on input, regenerable.
- Middleware `teams.current` (header, query, body, route; 403 / 422) and `teams.writable` (423 for read-only teams); `Teams::current()`.
- Personal team per user, optional on registration.
- Public API in the `Teams` facade with stable refusal reasons (`TeamsException`).
- Entitlements: a team is the subject `team:<id>`; `Teams::allows()` checks the user's teams.
- Payments: `Teams::checkout()` with the team's billing address as buyer.
- Mails as templates in email-templates (`teams:mail-templates`), with shipped defaults in German and English.
- Ten events, bridged to automations, webhook-manager and activity.
- Control Panel: teams, team page with members, invitations and billing address, wiring page.
- Antlers tags and front-end forms: switcher, member list, invite, join, accept, leave.
- `teams:import` and `Teams::import()` for taking over teams with fixed ids and uuids; a fixed id held by another team stops the import, `--dry-run` lists every problem, invitation `status` and unsupported join methods are taken over or reported.
- Nobody hands out, invites into or removes a role holding more than their own; owners only by owners; last-owner check under a row lock.
- `teams.current.fallback_to_current` and `Teams::currentOrFail()`.
- Join by code limited per account and per address (`teams.routes.join_limits`); form redirects only to paths on the site.
- `teams.meta_labels` and `teams.meta_value_labels` for the membership fields in the CP.
- The four mails are registered with email-templates' template registry when it is there.
- Accepting an invitation grants at most what its sender may still give; `updateMemberMeta` follows the role rule.
- Import: tokens of other teams and fixed ids of other teams are collisions; ids without uuids import idempotently; `expired` and undated invitations do not come back open.
- `transferOwnership` re-reads both rows under lock and counts the rows it changes.
- Registers itself with entitlements as subject expander; user subject types from the auth model and `teams.entitlements.user_types`.
- `Teams::checkout()` requires a payer holding `manage billing` in the team.
- Checkout names the team through payments' `$details['for']`; `meta.entitlement_subject` is no longer sent. With statamic-invoices, the VAT ID check is frozen as `meta.vat_id_check`; the CP marks the VAT ID as not verified.
