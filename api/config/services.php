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
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN', env('BOT_TOKEN')),
        'mini_app_short_name' => env('TELEGRAM_MINI_APP_SHORT_NAME'),
        'audit_channel_id' => env('TELEGRAM_AUDIT_CHANNEL_ID'),
    ],

    'bot_sender' => [
        'url' => env('BOT_SENDER_URL', 'http://bot:8080'),
        'token' => env('BOT_API_TOKEN', env('TELEGRAM_BOT_TOKEN', env('BOT_TOKEN'))),
    ],

    'giveaway_audit' => [
        'secret' => env('GIVEAWAY_AUDIT_SECRET'),
    ],

];
