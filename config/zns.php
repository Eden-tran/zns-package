<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Zalo OAuth Configuration
    |--------------------------------------------------------------------------
    */

    'app_id' => env('ZALO_APP_ID'),
    'app_secret' => env('ZALO_APP_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | OAuth URLs
    |--------------------------------------------------------------------------
    */
    'oa_access_token_url' => 'https://oauth.zaloapp.com/v4/oa/access_token',

    /*
    |--------------------------------------------------------------------------
    | API URLs for ZNS (Zalo Notification Service)
    |--------------------------------------------------------------------------
    */
    'zns_api_url' => 'https://business.openapi.zalo.me',
    'zns_message_endpoint' => '/message/template',
    'zns_status_endpoint' => '/message/status',
    'zns_quota_endpoint' => '/message/quota',
    'zns_template_list_endpoint' => '/template/all',
    'zns_template_info_endpoint' => '/template/info/v2',
];