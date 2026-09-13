<?php

$EM_CONF['typovigil'] = [
    'title' => 'TypoVigil',
    'description' => 'Central dashboard for TYPO3 update and security monitoring.',
    'category' => 'module',
    'author' => 'Maik Demuth',
    'author_email' => 'hi@maidem.de',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-0.0.0',
            'typo3' => '14.0.0-14.99.99',
            'scheduler' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
