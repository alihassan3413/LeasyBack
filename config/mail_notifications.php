<?php

return [

    'admin_recipients' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ADMIN_NOTIFICATION_EMAILS', (string) env('OPS_NOTIFICATION_EMAIL', ''))),
    ))),

    'portal' => [
        'url' => env('MAIL_PORTAL_URL'),
        'dashboard_path' => env('MAIL_PORTAL_DASHBOARD_PATH') ?: '/dashboard',
        'b2c_vehicle_path' => env('MAIL_PORTAL_B2C_VEHICLE_PATH') ?: '/vehicles/{vehicle}',
        'b2b_vehicle_path' => env('MAIL_PORTAL_B2B_VEHICLE_PATH') ?: '/vehicles/{vehicle}',
    ],

    'branding' => [
        'logo_url' => env('MAIL_LOGO_URL') ?: null,
        'logo_asset' => 'leasyback-stacked.png',
        'company_name' => env('MAIL_COMPANY_NAME') ?: 'LeasyBack GmbH',
        'company_address' => env('MAIL_COMPANY_ADDRESS') ?: 'Rolshover Str. 45, 51105 Köln, NRW, Deutschland',
        'website_url' => env('MAIL_COMPANY_WEBSITE_URL') ?: 'https://www.leasyback.com',
    ],

    'support' => [
        'email' => env('MAIL_SUPPORT_EMAIL') ?: 'office@leasyback.com',
        'phone' => env('MAIL_SUPPORT_PHONE') ?: '0221 88750755',
    ],

];
