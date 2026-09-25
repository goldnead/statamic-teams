<?php

return [
    'team_created' => ['label' => 'Team created', 'description' => 'A new team exists. Carries the team and who created it.'],
    'team_updated' => ['label' => 'Team changed', 'description' => 'Name, join method, settings or billing address changed. Carries the names of the changed fields.'],
    'team_deleted' => ['label' => 'Team deleted', 'description' => 'The team is gone. Carries the team as it was.'],
    'team_ownership_transferred' => ['label' => 'Ownership transferred', 'description' => 'Another member owns the team now.'],
    'member_joined' => ['label' => 'Member joined', 'description' => 'Somebody entered a team: by invitation, by join code, added in the CP, or as its creator. Not when a personal team is created.'],
    'member_left' => ['label' => 'Member left', 'description' => 'Somebody left a team or was removed. The field reason says which.'],
    'member_role_changed' => ['label' => 'Role changed', 'description' => 'A member holds a different role in the team.'],
    'invitation_sent' => ['label' => 'Invitation sent', 'description' => 'An address was invited, or invited again. Carries no link.'],
    'invitation_accepted' => ['label' => 'Invitation accepted', 'description' => 'The invited person accepted and is now a member.'],
    'invitation_revoked' => ['label' => 'Invitation withdrawn', 'description' => 'An open invitation was withdrawn; its link stopped working.'],
    'role_created' => ['label' => 'Role created', 'description' => 'A new role exists: global or for one team only (role.scope). Also when a deleted default role is reset.'],
    'role_updated' => ['label' => 'Role changed', 'description' => 'The name or the permissions of a role changed. The field changes says which.'],
    'role_deleted' => ['label' => 'Role deleted', 'description' => 'A role is gone. reassigned_to says which role its members were moved to, reassigned how many.'],
];
