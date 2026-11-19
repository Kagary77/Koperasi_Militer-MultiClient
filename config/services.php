<?php

return [

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

    'qris' => [
        'expiry_seconds' => (int) env('QRIS_EXPIRY_SECONDS', 900),
        'fake_unavailable' => (bool) env('QRIS_FAKE_UNAVAILABLE', false),
    ],

    'whatsapp' => [
        'fake_unavailable' => (bool) env('WHATSAPP_FAKE_UNAVAILABLE', false),
    ],

];
