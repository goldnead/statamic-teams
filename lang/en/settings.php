<?php

return [
    'groups' => [
        'invitations' => [
            'title' => 'Invitations and joining',
            'description' => 'How long an invitation link works and how somebody gets into a team.',
        ],
        'mail' => [
            'title' => 'Mails',
            'description' => 'Which team events send a mail. The wording is edited under Email templates.',
        ],
    ],
    'fields' => [
        'invitations_expires_after_days' => ['label' => 'Invitation valid for (days)', 'description' => '0 means the link does not expire.'],
        'invitations_require_matching_email' => ['label' => 'Only the invited address may accept', 'description' => 'The account accepting must carry the invited email. Off: whoever holds the link gets in.'],
        'join_codes_length' => ['label' => 'Length of new join codes', 'description' => 'Applies to codes created from now on. Existing codes keep working.'],
        'personal_create_on_registration' => ['label' => 'Personal team on registration', 'description' => 'Everyone who registers gets a personal team.'],
        'mail_invitation_enabled' => ['label' => 'Invitation', 'description' => 'To the invited address, with the link.'],
        'mail_member_joined_enabled' => ['label' => 'New member', 'description' => 'To the owners of the team.'],
        'mail_member_removed_enabled' => ['label' => 'Removed from the team', 'description' => 'To whoever was removed by someone else.'],
        'mail_role_changed_enabled' => ['label' => 'Role changed', 'description' => 'To the member whose role changed.'],
    ],
];
