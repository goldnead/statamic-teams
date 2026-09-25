<?php

return [
    'groups' => [
        'invitations' => [
            'title' => 'Einladungen und Beitritt',
            'description' => 'Wie lange ein Einladungslink gilt und wie jemand in ein Team kommt.',
        ],
        'mail' => [
            'title' => 'Mails',
            'description' => 'Welche Team-Ereignisse eine Mail verschicken. Den Wortlaut bearbeitest du unter E-Mail-Vorlagen.',
        ],
    ],
    'fields' => [
        'invitations_expires_after_days' => ['label' => 'Einladung gilt (Tage)', 'description' => '0 heißt: der Link läuft nicht ab.'],
        'invitations_require_matching_email' => ['label' => 'Nur die eingeladene Adresse darf annehmen', 'description' => 'Das annehmende Konto muss die eingeladene E-Mail tragen. Aus: wer den Link hat, kommt hinein.'],
        'join_codes_length' => ['label' => 'Länge neuer Beitrittscodes', 'description' => 'Gilt für Codes, die ab jetzt entstehen. Bestehende Codes funktionieren weiter.'],
        'personal_create_on_registration' => ['label' => 'Persönliches Team bei der Registrierung', 'description' => 'Wer sich registriert, bekommt ein persönliches Team.'],
        'mail_invitation_enabled' => ['label' => 'Einladung', 'description' => 'An die eingeladene Adresse, mit dem Link.'],
        'mail_member_joined_enabled' => ['label' => 'Neues Mitglied', 'description' => 'An die Inhaber:innen des Teams.'],
        'mail_member_removed_enabled' => ['label' => 'Aus dem Team entfernt', 'description' => 'An die Person, die von jemand anderem entfernt wurde.'],
        'mail_role_changed_enabled' => ['label' => 'Rolle geändert', 'description' => 'An das Mitglied, dessen Rolle sich geändert hat.'],
    ],
];
