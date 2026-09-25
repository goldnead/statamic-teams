<?php

return [
    'description' => 'Mail aus dem Addon Teams. Platzhalter: :variables',

    'placeholders' => [
        'team_name' => 'Name des Teams',
        'inviter_name' => 'Wer einlädt',
        'member_name' => 'Name des Mitglieds',
        'member_email' => 'E-Mail des Mitglieds',
        'role' => 'Rolle im Team',
        'role_from' => 'Rolle vorher',
        'role_to' => 'Rolle jetzt',
        'email' => 'Eingeladene Adresse',
        'accept_url' => 'Link zur Einladung',
        'expires_at' => 'Gültig bis',
    ],

    'invitation' => [
        'title' => 'Teams: Einladung',
        'trigger' => 'Einladung in ein Team',
        'subject' => 'Einladung zu {{ team.name }}',
        'body' => '<p>Hallo,</p>'
            .'<p>{{ inviter.name }} lädt dich zu <strong>{{ team.name }}</strong> ein. Deine Rolle dort: {{ role }}.</p>'
            .'<p><a href="{{ accept_url }}">Einladung ansehen und annehmen</a></p>'
            .'<p>Der Link gilt bis {{ expires_at }} und nur für {{ email }}. Wenn du noch kein Konto hast, legst du es mit dieser Adresse an.</p>'
            .'<p>Wenn du nicht weißt, worum es geht, kannst du diese Mail löschen. Ohne deine Zustimmung passiert nichts.</p>',
    ],

    'member_joined' => [
        'title' => 'Teams: Neues Mitglied',
        'trigger' => 'Neues Mitglied, an Inhaber:innen',
        'subject' => '{{ member.name }} ist jetzt in {{ team.name }}',
        'body' => '<p>Hallo,</p>'
            .'<p><strong>{{ member.name }}</strong> ({{ member.email }}) ist {{ team.name }} beigetreten, mit der Rolle {{ role }}.</p>',
    ],

    'member_removed' => [
        'title' => 'Teams: Aus dem Team entfernt',
        'trigger' => 'Aus dem Team entfernt',
        'subject' => 'Du bist nicht mehr in {{ team.name }}',
        'body' => '<p>Hallo {{ member.name }},</p>'
            .'<p>du wurdest aus <strong>{{ team.name }}</strong> entfernt. Was du über das Team nutzen konntest, steht dir damit nicht mehr zur Verfügung.</p>'
            .'<p>Wenn das ein Versehen war, sprich die Leitung des Teams an.</p>',
    ],

    'role_changed' => [
        'title' => 'Teams: Rolle geändert',
        'trigger' => 'Rolle im Team geändert',
        'subject' => 'Deine Rolle in {{ team.name }}',
        'body' => '<p>Hallo {{ member.name }},</p>'
            .'<p>deine Rolle in <strong>{{ team.name }}</strong> ist jetzt {{ role.to }} (vorher {{ role.from }}).</p>',
    ],
];
