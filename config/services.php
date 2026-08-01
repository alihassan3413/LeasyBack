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

    'dekra' => [
        'api_url' => env('DEKRA_API_URL', 'https://ws-auth-test.dekra.com/X4/httpstarter/ReST/internal/order'),
        'username' => env('DEKRA_USERNAME'),
        'password' => env('DEKRA_PASSWORD'),
        'timeout' => (int) env('DEKRA_TIMEOUT', 30),
        'connect_timeout' => (int) env('DEKRA_CONNECT_TIMEOUT', 10),
        // No default: VerifyDekraWebhookSignature fails closed when this is
        // unset, same reasoning as tuvsud.api_key below.
        'webhook_key' => env('DEKRA_WEBHOOK_KEY'),
    ],

    'tuvsud' => [
        'url' => env('TUVSUD_URL', 'https://mobility.autoservice-portal.de/api/rest/auftraege/beauftragung'),
        'username' => env('TUVSUD_USER_NAME', ''),
        'token' => env('TUVSUD_TOKEN', ''),
        'product_key' => env('TUVSUD_PRODUCT_KEY', ''),
        'partner_number' => env('TUVSUD_PARTNER_NUMBER', ''),
        // No default: the webhook middleware fails closed when this is
        // unset, rather than falling back to a key that would otherwise
        // have been committed to source control.
        'api_key' => env('TUVSUD_API_KEY'),
        'contact_name' => env('TUVSUD_CONTACT_NAME', 'Jannis Gremler'),
        'contact_phone' => env('TUVSUD_CONTACT_PHONE', '01234 5678943'),
        'contact_email' => env('TUVSUD_CONTACT_EMAIL', 'jannis.gremler@leasyback.de'),
    ],

    'tim' => [
        'username' => env('TIM_USER_NAME', ''),
        'password' => env('TIM_PASS', ''),
        'wsdl' => env('TIM_WSDL', ''),
    ],

    'notifications' => [
        // Internal/ops recipient for OrderCreatedNotification (staff-facing
        // "a new order came in", not customer-facing). No hardcoded
        // personal-address default, on purpose — the reference system's
        // biggest email flaw was exactly that (a hardcoded personal Gmail
        // address as the effective only recipient, docs/B2C_ADMIN_MIGRATION_AUDIT.md
        // §4.7). If unset, order-created ops emails are skipped (logged),
        // not silently sent nowhere useful.
        'ops_email' => env('OPS_NOTIFICATION_EMAIL'),
    ],

];
