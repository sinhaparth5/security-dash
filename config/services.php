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

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => '/auth/github/callback',
        // Comma-separated GitHub usernames allowed to sign in to the dashboard.
        // Fine-grained token with Contents and Pull requests write access, used to open version-bump PRs.
        'pr_token' => env('GITHUB_PR_TOKEN'),
        'allowed_logins' => array_values(array_filter(array_map('trim', explode(',', (string) env('GITHUB_ALLOWED_LOGINS', 'sinhaparth5'))))),
    ],

    'typesafe' => [
        // Jev triages advisories when a key is set; without one, triage is skipped.
        'key' => env('TYPESAFE_API_KEY'),
        'model' => env('TYPESAFE_MODEL', 'jev-latest'),
    ],

    'alerts' => [
        // Slack or Discord incoming webhook that gets the same alerts as the Red mail.
        'webhook_url' => env('ALERT_WEBHOOK_URL'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
