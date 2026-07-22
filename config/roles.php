<?php

return [
    'agent' => 'agent',
    'responsable_de_validation' => 'responsable_de_validation',
    'manager' => 'manager',
    'administrateur_plateforme' => 'administrateur_plateforme',
    'client' => 'client',
    'demandeur_authentifie' => 'demandeur_authentifie',
    'auditeur' => 'auditeur',

    'staff' => [
        'agent',
        'responsable_de_validation',
        'manager',
        'administrateur_plateforme',
        'auditeur',
    ],

    'participants' => [
        'client',
        'demandeur_authentifie',
    ],

    'enrollment_reviewers' => [
        'agent',
        'responsable_de_validation',
        'manager',
    ],
];
