# Changelog

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
