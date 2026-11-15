<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'null'),
        'endpoint' => env('WHATSAPP_API_ENDPOINT', ''),
        'api_token' => env('WHATSAPP_API_TOKEN', ''),
        'timeout' => (int) env('WHATSAPP_TIMEOUT', 10),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('AI_PLANNER_TIMEOUT', 20),
    ],

    'grok' => [
        'api_key' => env('GROK_API_KEY', env('XAI_API_KEY', '')),
        'model' => env('GROK_MODEL', 'grok-3-mini'),
        'endpoint' => env('GROK_ENDPOINT', 'https://api.x.ai/v1/chat/completions'),
        'timeout' => (int) env('AI_PLANNER_TIMEOUT', 20),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY', ''),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-3.8-flash'),
        'endpoint' => env('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
        'timeout' => (int) env('AI_PLANNER_TIMEOUT', 20),
    ],

];
