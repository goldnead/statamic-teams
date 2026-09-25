<?php

return [
    'description' => 'Mail of the Teams addon. Variables: :variables',

    'placeholders' => [
        'team_name' => 'Name of the team',
        'inviter_name' => 'Who invites',
        'member_name' => 'Name of the member',
        'member_email' => 'Email of the member',
        'role' => 'Role in the team',
        'role_from' => 'Role before',
        'role_to' => 'Role now',
        'email' => 'Invited address',
        'accept_url' => 'Link to the invitation',
        'expires_at' => 'Valid until',
    ],

    'invitation' => [
        'title' => 'Teams: Invitation',
        'trigger' => 'Invitation into a team',
        'subject' => 'Invitation to {{ team.name }}',
        'body' => '<p>Hello,</p>'
            .'<p>{{ inviter.name }} invites you to <strong>{{ team.name }}</strong>. Your role there: {{ role }}.</p>'
            .'<p><a href="{{ accept_url }}">View and accept the invitation</a></p>'
            .'<p>The link works until {{ expires_at }} and only for {{ email }}. If you have no account yet, create one with this address.</p>'
            .'<p>If you do not know what this is about, delete this mail. Nothing happens without your consent.</p>',
    ],

    'member_joined' => [
        'title' => 'Teams: New member',
        'trigger' => 'New member, to the owners',
        'subject' => '{{ member.name }} joined {{ team.name }}',
        'body' => '<p>Hello,</p>'
            .'<p><strong>{{ member.name }}</strong> ({{ member.email }}) joined {{ team.name }} with the role {{ role }}.</p>',
    ],

    'member_removed' => [
        'title' => 'Teams: Removed from a team',
        'trigger' => 'Removed from a team',
        'subject' => 'You are no longer in {{ team.name }}',
        'body' => '<p>Hello {{ member.name }},</p>'
            .'<p>you were removed from <strong>{{ team.name }}</strong>. Whatever you used through the team is no longer available to you.</p>'
            .'<p>If this was a mistake, talk to the people running the team.</p>',
    ],

    'role_changed' => [
        'title' => 'Teams: Role changed',
        'trigger' => 'Role in a team changed',
        'subject' => 'Your role in {{ team.name }}',
        'body' => '<p>Hello {{ member.name }},</p>'
            .'<p>your role in <strong>{{ team.name }}</strong> is now {{ role.to }} (before: {{ role.from }}).</p>',
    ],
];
