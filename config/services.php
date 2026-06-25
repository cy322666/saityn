<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'command_chat_id' => env('TELEGRAM_COMMAND_CHAT_ID'),
        'php_cli_binary' => env('PHP_CLI_BINARY', 'php'),
        'connect_timeout' => env('TELEGRAM_CONNECT_TIMEOUT', 20),
        'timeout' => env('TELEGRAM_TIMEOUT', 60),
    ],

    'amocrm' => [
        'base_domain' => env('AMOCRM_BASE_DOMAIN'),
        'client_id' => env('AMOCRM_CLIENT_ID'),
        'client_secret' => env('AMOCRM_CLIENT_SECRET'),
        'redirect_uri' => env('AMOCRM_REDIRECT_URI', env('APP_URL').'/amocrm/oauth/callback'),
        'max_export_batch' => env('AMOCRM_MAX_EXPORT_BATCH', 100),
        'pipeline_id' => env('AMOCRM_PIPELINE_ID'),
        'status_id' => env('AMOCRM_STATUS_ID'),
    ],

];
