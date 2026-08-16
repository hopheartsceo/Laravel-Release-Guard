<?php

declare(strict_types=1);

return [
    'paths' => [
        'application' => [
            'app',
        ],

        'migrations' => [
            'database/migrations',
        ],
    ],

    'confidence' => [
        'fail_on' => 'definite',
    ],

    'rules' => [
        'DB001' => true,
        'DB002' => true,
        'DB003' => true,
        'DB004' => true,
        'DB005' => true,
        'DB006' => true,
    ],
];
