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
        'client_id' => env('SLACK_CLIENT_ID'),
        'client_secret' => env('SLACK_CLIENT_SECRET'),
        'signing_secret' => env('SLACK_SIGNING_SECRET'),
        'redirect_uri' => env('SLACK_REDIRECT_URI', 'http://localhost:8000/auth/slack/callback'),
        'owner_user_id' => env('SLACK_OWNER_USER_ID'),

        // Comma-separated Slack user IDs treated as "internal team" for retainer
        // reports. Anyone in a client channel NOT in this list is classified as
        // "from client". The owner_user_id is always treated as internal.
        // Needed because client people who are full members of the workspace
        // don't trip Slack's is_restricted/is_stranger flags.
        'internal_user_ids' => array_filter(array_map(
            'trim',
            explode(',', (string) env('SLACK_INTERNAL_USER_IDS', ''))
        )),
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Services (Multi-model Consortium)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Workers AI
    |--------------------------------------------------------------------------
    | Free-tier LLM access via Cloudflare. Used as the primary LLM for
    | RetainerNarrativeService when configured; falls back to Anthropic.
    | Get credentials at dash.cloudflare.com — Account ID is in the right
    | sidebar; API token needs "Workers AI - Run" permission.
    */
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        // Model to use for narrative analysis. Llama 3.3 70B is in the open
        // Workers AI catalog (no beta-access gating). Override with
        // CLOUDFLARE_NARRATIVE_MODEL if you have access to a stronger one
        // (e.g. @cf/moonshotai/kimi-k2-instruct for accounts allowlisted
        // for it, or @cf/qwen/qwen2.5-coder-32b-instruct).
        'narrative_model' => env('CLOUDFLARE_NARRATIVE_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'), // For Claude CLI (Max subscription)
        'sow_import_max_chars' => (int) env('SOW_IMPORT_AI_MAX_CHARS', 45000),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'grok' => [
        'api_key' => env('GROK_API_KEY'),
        'model' => env('GROK_MODEL', 'grok-beta'),
    ],

    'google' => [
        'gemini_api_key' => env('GEMINI_API_KEY'),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/auth/google/callback'),
        'pubsub_topic' => env('GOOGLE_PUBSUB_TOPIC'),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
    ],

    'facebook' => [
        'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
    ],

    'yelp' => [
        'api_key' => env('YELP_API_KEY'),
    ],

    'github' => [
        'app_id' => env('GITHUB_APP_ID'),
        'app_slug' => env('GITHUB_APP_SLUG'),
        'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH'),
        'private_key_base64' => env('GITHUB_APP_PRIVATE_KEY_BASE64') ?? env('GITHUB_PRIVATE_KEY_BASE64'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => env('GITHUB_REDIRECT_URI', 'http://localhost:8000/auth/github/user/callback'),
    ],

    'laravel_cloud' => [
        'api_token' => env('LARAVEL_CLOUD_API_TOKEN'),
        'application_id' => env('LARAVEL_CLOUD_APP_ID'),
        'environment_id' => env('LARAVEL_CLOUD_ENVIRONMENT_ID'),
    ],

    'harvest' => [
        'client_id' => env('HARVEST_CLIENT_ID'),
        'client_secret' => env('HARVEST_CLIENT_SECRET'),
        'redirect_uri' => env('HARVEST_REDIRECT_URI', 'http://localhost:8000/auth/harvest/callback'),
        'account_id' => env('HARVEST_ACCOUNT_ID'),
    ],

    'notion' => [
        'client_id' => env('NOTION_CLIENT_ID'),
        'client_secret' => env('NOTION_CLIENT_SECRET'),
        'redirect_uri' => env('NOTION_REDIRECT_URI', 'http://localhost:8000/auth/notion/callback'),
    ],

    'quickbooks' => [
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
        'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI', 'http://localhost:8000/auth/quickbooks/callback'),
        'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'), // 'sandbox' or 'production'
    ],

    'plaid' => [
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
        'environment' => env('PLAID_ENVIRONMENT', 'production'), // production required for live bank/card sync
        'webhook_url' => env('PLAID_WEBHOOK_URL'),
    ],

    'teller' => [
        'application_id' => env('TELLER_APPLICATION_ID'),
        'certificate_path' => env('TELLER_CERTIFICATE_PATH'),       // File path OR base64-encoded cert
        'certificate_base64' => env('TELLER_CERTIFICATE_BASE64'),   // Base64-encoded cert (for cloud deploy)
        'private_key_path' => env('TELLER_PRIVATE_KEY_PATH'),       // File path OR base64-encoded key
        'private_key_base64' => env('TELLER_PRIVATE_KEY_BASE64'),   // Base64-encoded key (for cloud deploy)
        'environment' => env('TELLER_ENVIRONMENT', 'sandbox'),
        'webhook_secret' => env('TELLER_WEBHOOK_SECRET'),
    ],

    'wise' => [
        'api_url' => env('WISE_API_URL', 'https://api.wise.com'),
        'environment' => env('WISE_ENVIRONMENT', 'production'), // 'sandbox' or 'production'
        'client_id' => env('WISE_CLIENT_ID'),
        'client_secret' => env('WISE_CLIENT_SECRET'),
        'redirect_uri' => env('WISE_REDIRECT_URI', 'http://localhost:8000/auth/wise/callback'),
        'webhook_secret' => env('WISE_WEBHOOK_SECRET'),
    ],

    'gravity_forms' => [
        'site_url' => env('GRAVITY_FORMS_SITE_URL', 'https://example.com'),
        'consumer_key' => env('GRAVITY_FORMS_CONSUMER_KEY'),
        'consumer_secret' => env('GRAVITY_FORMS_CONSUMER_SECRET'),
        'webhook_secret' => env('GRAVITY_FORMS_WEBHOOK_SECRET'),
        'wp_user' => env('WP_APPLICATION_USER'),
        'wp_password' => env('WP_APPLICATION_PASSWORD'),
        'field_maps' => [
            // Map form_id => ['gf_field_id' => 'lead_field']
            // Name compound fields: X.3 = first, X.6 = last (handled automatically)
            // Use 'first_name' or 'last_name' for explicit mapping
        ],
    ],

    'linkedin' => [
        'client_id' => env('LINKEDIN_CLIENT_ID'),
        'client_secret' => env('LINKEDIN_CLIENT_SECRET'),
        'redirect_uri' => env('LINKEDIN_REDIRECT_URI', 'http://localhost:8000/auth/linkedin/callback'),
    ],

    'spinupwp' => [
        'api_token' => env('SPINUPWP_API_TOKEN'),
        'api_url' => env('SPINUPWP_API_URL', 'https://api.spinupwp.app/v1'),
        'default_server_id' => env('SPINUPWP_DEFAULT_SERVER_ID'),
        'staging_domain' => env('SPINUPWP_STAGING_DOMAIN', 'staging.example.com'),
        'ollie_theme_url' => 'https://downloads.wordpress.org/theme/ollie.zip',
        'ollie_pro_url' => env('OLLIE_PRO_PLUGIN_URL', ''),
        'ssh_key_path' => env('SPINUPWP_SSH_KEY_PATH'),
        'ssh_private_key' => env('SPINUPWP_SSH_PRIVATE_KEY'),
        'site_defaults' => [
            'php_version' => '8.3',
            'https_enabled' => true,
            'page_cache_enabled' => true,
        ],
    ],

    'x' => [
        'client_id' => env('X_CLIENT_ID'),
        'client_secret' => env('X_CLIENT_SECRET'),
        'redirect_uri' => env('X_REDIRECT_URI', 'http://localhost:8000/auth/x/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta Ads (Facebook/Instagram Advertising)
    |--------------------------------------------------------------------------
    */

    'meta_ads' => [
        'app_id' => env('META_ADS_APP_ID'),
        'app_secret' => env('META_ADS_APP_SECRET'),
        'access_token' => env('META_ADS_ACCESS_TOKEN'),
        'account_id' => env('META_ADS_AD_ACCOUNT_ID'),
        'business_id' => env('META_ADS_BUSINESS_ID'),
        'api_version' => env('META_ADS_API_VERSION', 'v21.0'),

        // Sandbox credentials
        'sandbox_app_id' => env('META_ADS_SANDBOX_APP_ID'),
        'sandbox_access_token' => env('META_ADS_SANDBOX_ACCESS_TOKEN'),
        'sandbox_account_id' => env('META_ADS_SANDBOX_ACCOUNT_ID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search APIs (for agent web research)
    |--------------------------------------------------------------------------
    */

    'serper' => [
        'api_key' => env('SERPER_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Unsplash API (Stock Photos for SEO Content)
    |--------------------------------------------------------------------------
    | Used as fallback when AI image generation fails. Must comply with
    | Unsplash API Guidelines: https://help.unsplash.com/en/articles/2511245
    | - Always use hotlinked URLs from photo.urls
    | - Track downloads via photo.links.download_location
    | - Include proper attribution with UTM parameters
    */

    'unsplash' => [
        'access_key' => env('UNSPLASH_ACCESS_KEY'),
        'secret_key' => env('UNSPLASH_SECRET_KEY'),
        'app_id' => env('UNSPLASH_APP_ID'),
    ],

    'serpapi' => [
        'api_key' => env('SERPAPI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SAM.gov Federal Procurement API
    |--------------------------------------------------------------------------
    | Used by RfpDiscoveryService to query federal procurement opportunities.
    | Register for an API key at https://api.sam.gov/
    */

    'sam_gov' => [
        'api_key' => env('SAM_GOV_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Video Processing
    |--------------------------------------------------------------------------
    */

    'ffmpeg' => [
        // Laravel Cloud: /var/www/bin/ffmpeg/ffmpeg (installed via deploy/ffmpeg.sh)
        // Local: uses system ffmpeg from PATH
        'ffmpeg_path' => env('FFMPEG_PATH', file_exists('/var/www/bin/ffmpeg/ffmpeg') ? '/var/www/bin/ffmpeg/ffmpeg' : 'ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', file_exists('/var/www/bin/ffmpeg/ffprobe') ? '/var/www/bin/ffmpeg/ffprobe' : 'ffprobe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hugging Face AI (Transcription)
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Transcription (Whisper)
    |--------------------------------------------------------------------------
    | Provider options: 'openai' (default) or 'groq' (free)
    | OpenAI uses whisper-1 model
    | Groq uses whisper-large-v3 model (free, very fast)
    */

    'transcription' => [
        'provider' => env('TRANSCRIPTION_PROVIDER', 'openai'),
        // Groq models: 'whisper-large-v3-turbo' (faster, cheaper) or 'whisper-large-v3' (more accurate)
        'model' => env('TRANSCRIPTION_MODEL', 'whisper-large-v3-turbo'),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
    ],

    'replicate' => [
        'api_key' => env('REPLICATE_API_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Processing (PayPal)
    |--------------------------------------------------------------------------
    */

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'), // 'sandbox' or 'live'
        'invoicer_email' => env('PAYPAL_INVOICER_EMAIL', env('COMPANY_EMAIL', 'billing@example.com')),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),
    ],

    'browserless' => [
        'api_key' => env('BROWSERLESS_API_KEY'),
    ],

    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
    ],

    'tax_agency_bridge' => [
        'url' => env('TAX_AGENCY_BRIDGE_URL'),
        'token' => env('TAX_AGENCY_BRIDGE_TOKEN'),
        'sync_path' => env('TAX_AGENCY_BRIDGE_SYNC_PATH', '/sync'),
        'timeout_seconds' => (int) env('TAX_AGENCY_BRIDGE_TIMEOUT_SECONDS', 120),
        'worker_profile' => env('TAX_AGENCY_BRIDGE_WORKER_PROFILE', 'default'),
        'headers' => json_decode((string) env('TAX_AGENCY_BRIDGE_HEADERS_JSON', '[]'), true) ?: [],
        'host' => env('TAX_AGENCY_BRIDGE_HOST', '127.0.0.1'),
        'port' => (int) env('TAX_AGENCY_BRIDGE_PORT', 8792),
        'headless' => filter_var(env('TAX_AGENCY_BRIDGE_HEADLESS', true), FILTER_VALIDATE_BOOL),
        'mfa_timeout_ms' => (int) env('TAX_AGENCY_BRIDGE_MFA_TIMEOUT_MS', 180000),
        'chrome_path' => env('CHROME_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-Development (Zao Dash developing itself)
    |--------------------------------------------------------------------------
    | When "Request a Feature" is invoked, tasks are created in this project
    | and optionally the Dev Agent is triggered to implement them.
    */

    'self_development' => [
        'enabled' => env('SELF_DEVELOPMENT_ENABLED', true),
        'project_slug' => env('SELF_PROJECT_SLUG', 'zao-dash'),
        'project_id' => env('SELF_PROJECT_ID'), // Fallback if slug doesn't exist
        'auto_trigger_agent' => env('SELF_AUTO_TRIGGER_AGENT', false), // Auto-trigger Dev Agent
        'default_priority' => env('SELF_FEATURE_PRIORITY', 'medium'),
        'github_repo' => env('SELF_GITHUB_REPO', 'example/zao-dash'), // For Dev Agent context
    ],

];
