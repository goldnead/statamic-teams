<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | The roles a member can hold inside a team, and what each may do there.
    | These are team roles, not Statamic roles: Statamic roles stay for the
    | Control Panel. A team can add its own roles on top (table `team_roles`);
    | a team role with the same handle as one of these replaces it for that
    | team only.
    |
    | `*` grants every team permission. The owner role is what the creator of
    | a team gets, and it cannot be removed from the last owner.
    |
    */

    'roles' => [
        'owner' => [
            'label' => 'Owner',
            'permissions' => ['*'],
        ],
        'admin' => [
            'label' => 'Admin',
            'permissions' => [
                'invite members',
                'remove members',
                'change roles',
                'update team',
                'view billing',
                'manage billing',
            ],
        ],
        'member' => [
            'label' => 'Member',
            'permissions' => [],
        ],
    ],

    'owner_role' => 'owner',

    'default_role' => 'member',

    /*
    |--------------------------------------------------------------------------
    | Team permissions
    |--------------------------------------------------------------------------
    |
    | The permissions this addon checks itself. A site may add its own and
    | check them with `Teams::can($user, $team, 'your permission')`.
    |
    */

    'permissions' => [
        'invite members',
        'remove members',
        'change roles',
        'update team',
        'delete team',
        'view billing',
        'manage billing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Team types
    |--------------------------------------------------------------------------
    |
    | `personal` is special: at most one per user, created on demand by
    | `Teams::personalTeam($user)`, and nobody can be invited into it.
    |
    */

    'types' => [
        'team' => 'Team',
        'personal' => 'Personal',
    ],

    'default_type' => 'team',

    'personal' => [
        // Create a personal team when a user registers through Statamic's
        // registration form.
        'create_on_registration' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    */

    'invitations' => [
        'expires_after_days' => 7,

        // The account accepting an invitation must carry the invited email.
        // A forwarded mail is then not a key to the team.
        'require_matching_email' => true,

        // Where the link in the invitation mail points. `{token}` is replaced.
        // Null uses the addon's own route, /teams/invitations/{token}.
        'accept_url' => null,

        // Where somebody who is not signed in is sent before accepting. The
        // current URL is appended as `?redirect=`.
        'login_url' => '/login',

        // Where the invitation page sends somebody after accepting.
        'after_accept' => '/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Join codes
    |--------------------------------------------------------------------------
    |
    | Characters that are confused when read aloud or copied from paper
    | (0/O, 1/I/L) are left out.
    |
    */

    'join_codes' => [
        'length' => 10,
        'alphabet' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',
    ],

    /*
    |--------------------------------------------------------------------------
    | Current team
    |--------------------------------------------------------------------------
    |
    | Where the middleware `teams.current` looks for the team a request is
    | about. Several sources that disagree are rejected with 422.
    |
    */

    'current' => [
        'header' => 'X-Team-ID',
        'parameter' => 'team_id',
        'route_parameters' => ['team', 'team_id'],

        // Without a named team, use the user's current team. Off: the
        // request has no team, and `Teams::currentOrFail()` answers 422.
        'fallback_to_current' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | Subject types in statamic-entitlements whose id is a user key, so the
    | user's teams count for them. `user`, the auth model's class and its
    | morph alias are always included; add others here.
    |
    */

    'entitlements' => [
        'user_types' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership meta labels
    |--------------------------------------------------------------------------
    |
    | How the CP names the fields in a membership's `meta`, e.g.
    | ['voice_part' => 'Voice part']. Labels go through the translator. A key
    | without a label is shown as the key in words (`seat_row` → "Seat row"),
    | also through the translator.
    |
    */

    'meta_labels' => [],

    // How the CP shows the values of a meta field, per key, e.g.
    // ['voice_part' => ['soprano' => 'Soprano', 'bass' => 'Bass']].
    // Through the translator as well. A value without a label is shown as is.
    'meta_value_labels' => [],

    /*
    |--------------------------------------------------------------------------
    | Mails
    |--------------------------------------------------------------------------
    |
    | Each mail is a template in goldnead/statamic-email-templates when that
    | addon is installed (slug below). Without it, the default text shipped
    | with this addon is sent.
    |
    */

    'mail' => [
        'invitation' => ['enabled' => true, 'template' => 'teams-invitation'],
        'member_joined' => ['enabled' => true, 'template' => 'teams-member-joined'],
        'member_removed' => ['enabled' => true, 'template' => 'teams-member-removed'],
        'role_changed' => ['enabled' => false, 'template' => 'teams-role-changed'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'teams',
        'middleware' => ['web'],
        // Attempts per hour at joining by code, as in ChoirLive: a code is
        // short enough to be guessed if nobody brakes.
        'join_limits' => [
            'per_user' => 10,
            'per_ip' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    */

    'integrations' => [
        'automations' => true,
        'webhook_manager' => true,
        'activity' => true,
        'entitlements' => true,
        'email_templates' => true,
    ],

];
