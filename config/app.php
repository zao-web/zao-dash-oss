<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC, but dates shown to people — and the
    | boundaries of reporting periods — should be expressed in the agency's
    | local timezone. Convert at the edges (display + period windows); never
    | change the storage timezone above, which would reinterpret existing
    | UTC rows and shift all historical data.
    |
    */

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'America/Los_Angeles'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Company Information
    |--------------------------------------------------------------------------
    |
    | Company details used in invoices, emails, and other communications.
    |
    */

    'company_name' => env('COMPANY_NAME', 'Zao'),
    'company_email' => env('COMPANY_EMAIL', 'billing@example.com'),
    'company_phone' => env('COMPANY_PHONE', ''),
    'company_address' => env('COMPANY_ADDRESS', ''),
    'company_logo_url' => env('COMPANY_LOGO_URL', ''),

    /*
     | Address(es) CC'd on every outgoing client-facing email (invoices,
     | retainer reports, etc.). Comma-separate multiple. Use to keep
     | internal stakeholders looped in without manual BCC management.
     */
    'client_email_cc' => env('CLIENT_EMAIL_CC', ''),

    /*
    |--------------------------------------------------------------------------
    | ACH Bank Details (for invoice payment instructions)
    |--------------------------------------------------------------------------
    |
    | Bank account details displayed on invoices for ACH/wire payments.
    | These are YOUR receiving account details.
    |
    */

    'bank_name' => env('BANK_NAME', ''),
    'bank_routing_number' => env('BANK_ROUTING_NUMBER', ''),
    'bank_account_number' => env('BANK_ACCOUNT_NUMBER', ''),
    'bank_account_name' => env('BANK_ACCOUNT_NAME', ''), // Account holder name

];
