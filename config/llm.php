<?php

return [
    // Vendor settings live only here and in Shared/Adapters/Llm.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

        // Model per tier. Fixed versions, so behaviour doesn't change under us; replace them when retired.
        'models' => [
            'cheap' => env('GEMINI_MODEL_CHEAP', 'gemini-3.5-flash-lite'),
            'standard' => env('GEMINI_MODEL_STANDARD', 'gemini-3.6-flash'),
            'smart' => env('GEMINI_MODEL_SMART', 'gemini-3.8-flash'),
        ],

        // minimal, low, medium or high; empty leaves each model its own default.
        'thinking_level' => env('GEMINI_THINKING_LEVEL'),
    ],
];
