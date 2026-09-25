# Changelog

## Unreleased

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
- `teams:import` and `Teams::import()` for taking over teams with fixed ids and uuids.
