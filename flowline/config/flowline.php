<?php

declare(strict_types=1);

return [
    'path' => 'api/flowline',

    'app_name' => env('APP_NAME', ''),

    'serve_url' => env('FLOWLINE_SERVE_URL', ''),

    'signing_key' => env('FLOWLINE_SIGNING_KEY'),

    'event_key' => env('FLOWLINE_EVENT_KEY', ''),

    'platform_url' => env('FLOWLINE_PLATFORM_URL', 'https://api.flowline.dev'),
];
