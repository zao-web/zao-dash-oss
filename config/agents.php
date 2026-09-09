<?php

/**
 * Agent Configuration
 *
 * Defines agent settings, tool allowlists, and execution parameters.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Agent Settings
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'model' => env('AGENT_DEFAULT_MODEL', 'sonnet'),
        'max_budget_usd' => env('AGENT_DEFAULT_BUDGET', 5.00),
        'max_turns' => env('AGENT_MAX_TURNS', 50),
        'timeout_seconds' => env('AGENT_TIMEOUT', 300),
        'requires_approval' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    */
    'models' => [
        'opus' => [
            'name' => 'claude-opus-4-20250514',
            'cost_per_1k_input' => 0.015,
            'cost_per_1k_output' => 0.075,
            'context_window' => 200000,
        ],
        'sonnet' => [
            'name' => 'claude-sonnet-4-20250514',
            'cost_per_1k_input' => 0.003,
            'cost_per_1k_output' => 0.015,
            'context_window' => 200000,
        ],
        'haiku' => [
            'name' => 'claude-haiku-3-20240307',
            'cost_per_1k_input' => 0.00025,
            'cost_per_1k_output' => 0.00125,
            'context_window' => 200000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tool Allowlists by Agent
    |--------------------------------------------------------------------------
    |
    | Define which tools each agent can access. Tools not in the list
    | are blocked for that agent.
    |
    */
    'tools' => [
        // Research & Analysis agents - read-only tools
        'meeting-parser' => [
            'read_file',
            'search_content',
            'create_task',
            'update_task',
        ],

        'client-health-monitor' => [
            'search_clients',
            'search_communications',
            'get_sentiment',
            'create_alert',
        ],

        'opportunity-scout' => [
            'search_projects',
            'search_clients',
            'web_search',
            'create_opportunity',
        ],

        // Content agents - generation + publishing
        'content-creator' => [
            'search_projects',
            'search_clients',
            'web_search',
            'generate_content',
        ],

        'marketing' => [
            'get_x_trends',
            'search_projects',
            'search_clients',
            'get_stats',
            'web_search',
            'search_content',
            'post_to_linkedin',
            'post_to_x',
        ],

        'programmatic-seo' => [
            'seo_keyword_research',
            'seo_analyze_serp',
            'seo_competitor_gaps',
            'seo_search_volume',
            'seo_generate_landing',
            'seo_generate_blog',
            'seo_optimize_content',
            'seo_get_rankings',
            'seo_track_page',
            'seo_get_pseo_performance',
            'seo_get_conversions',
            'get_x_trends',
            'web_search',
            'search_projects',
            'search_clients',
            'get_quarterly_patterns',
            'wp_create_page',
            'wp_create_post',
        ],

        // Dev agents - code access
        'dev-agent' => [
            'read_file',
            'write_file',
            'search_code',
            'run_tests',
            'git_operations',
        ],

        'qa-agent' => [
            'read_file',
            'run_tests',
            'screenshot',
            'validate_html',
            'check_accessibility',
        ],

        // Financial agents - sensitive operations
        'bookkeeping' => [
            'qbo_get_expenses',
            'qbo_get_categories',
            'qbo_suggest_category',
            'qbo_categorize_expense',
        ],

        'invoice-analyzer' => [
            'harvest_get_entries',
            'harvest_get_projects',
            'create_invoice_draft',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Network Domain Allowlists by Agent
    |--------------------------------------------------------------------------
    */
    'domains' => [
        // All agents get Anthropic API
        '_default' => [
            'api.anthropic.com',
        ],

        'marketing' => [
            'api.twitter.com',
            'api.x.com',
            'api.linkedin.com',
        ],

        'programmatic-seo' => [
            'searchconsole.googleapis.com',
            'analyticsdata.googleapis.com',
            'api.grok.x.ai',
        ],

        'dev-agent' => [
            'api.github.com',
            'registry.npmjs.org',
            'packagist.org',
        ],

        'wordpress-publisher' => [
            // Dynamically added based on connected WordPress sites
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Settings
    |--------------------------------------------------------------------------
    */
    'circuit_breaker' => [
        'failure_threshold' => env('AGENT_CIRCUIT_FAILURE_THRESHOLD', 3),
        'reset_timeout_minutes' => env('AGENT_CIRCUIT_RESET_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spend Governor
    |--------------------------------------------------------------------------
    */
    'spend_limits' => [
        'daily_per_agent' => env('AGENT_DAILY_SPEND_LIMIT', 100.00),
        'daily_total' => env('AGENT_DAILY_TOTAL_LIMIT', 500.00),
        'monthly_total' => env('AGENT_MONTHLY_LIMIT', 5000.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'runs_per_minute' => env('AGENT_RUNS_PER_MINUTE', 10),
        'api_calls_per_minute' => env('AGENT_API_CALLS_PER_MINUTE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sandbox Settings
    |--------------------------------------------------------------------------
    */
    'sandbox' => [
        'base_path' => storage_path('app/agent-workspaces'),
        'cleanup_after_hours' => env('AGENT_SANDBOX_CLEANUP_HOURS', 24),
        'max_file_size_mb' => env('AGENT_MAX_FILE_SIZE_MB', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Scheduling
    |--------------------------------------------------------------------------
    */
    'schedules' => [
        'business-strategist' => [
            'weekly' => '0 6 * * 1',      // Monday 6am
            'daily' => '0 7 * * 2-5',      // Tue-Fri 7am
        ],
        'lead-generation' => '0 8 * * 1',   // Monday 8am
        'outreach-campaign' => '0 9 * * 1-5', // Weekdays 9am
        'client-health-monitor' => '0 7 * * *', // Daily 7am
        'programmatic-seo' => '0 7 * * 1',  // Monday 7am
        'marketing' => '0 8 * * 1',         // Monday 8am
        'bookkeeping' => '0 7 * * 1-5',     // Weekdays 7am
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Chaining
    |--------------------------------------------------------------------------
    */
    'chains' => [
        // Dev completion triggers QA
        'dev-agent' => ['qa-agent'],

        // QA completion triggers communication (if approved)
        'qa-agent' => ['communication'],

        // Content creator can trigger WordPress publishing
        'content-creator' => ['wordpress-publisher'],

        // Programmatic SEO can trigger marketing
        'programmatic-seo' => ['marketing'],
    ],
];
