<?php

declare(strict_types=1);

return [
    'frontend' => [
        'maidemde/typovigil/report-receiver' => [
            'target' => \Maidemde\Typovigil\Middleware\ReportReceiver::class,
            // Before site resolution: the endpoint must answer even when no site
            // configuration exists yet.
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
