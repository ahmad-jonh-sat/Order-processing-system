<?php

return [
    'user' => [
        'url' => env('USER_SERVICE_URL', 'http://internal-router:8081/user'),
    ],

    'order' => [
        'url' => env('ORDER_SERVICE_URL', 'http://internal-router:8081/order'),
    ],

    'report' => [
        'url' => env('REPORT_SERVICE_URL', 'http://internal-router:8081/report'),
    ],
];
