<?php

return [
    'host' => env('RABBITMQ_HOST', '127.0.0.1'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'login' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // Seconds before the worker reconnects after a failure.
    'error_pause_seconds' => 5,

    // Exchange, queue and routing key.
    'exchange' => 'nodes',
    'queue' => 'node.recalc',
];
