<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Self-Healing System Configuration
    |--------------------------------------------------------------------------
    |
    | Controls the autonomous error detection and fixing system that monitors
    | Laravel Nightwatch errors via Slack and automatically deploys fixes.
    |
    */

    'enabled' => env('SELF_HEALING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Slack Configuration
    |--------------------------------------------------------------------------
    */

    'slack_channel' => env('SELF_HEALING_SLACK_CHANNEL', '#ops-logs'),
    'slack_channel_id' => env('SELF_HEALING_SLACK_CHANNEL_ID', ''),
    'nightwatch_bot_id' => env('NIGHTWATCH_BOT_ID', ''),

    /*
    |--------------------------------------------------------------------------
    | Repository Restrictions
    |--------------------------------------------------------------------------
    |
    | Only these repositories can be auto-fixed. This prevents the system
    | from accidentally modifying client code or other projects.
    |
    */

    'allowed_repos' => [
        'zao-web/zao-dash',
    ],

    'default_branch' => env('SELF_HEALING_BRANCH', 'main'),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Prevent runaway fixing by limiting how many attempts can be made.
    |
    */

    'rate_limits' => [
        'per_hour' => (int) env('SELF_HEALING_RATE_PER_HOUR', 5),
        'per_day' => (int) env('SELF_HEALING_RATE_PER_DAY', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | If too many consecutive failures occur, the system pauses to prevent
    | making things worse. Resets after cooldown period.
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('SELF_HEALING_FAILURE_THRESHOLD', 3),
        'cooldown_minutes' => (int) env('SELF_HEALING_COOLDOWN_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    |
    | Prevent fixing the same error multiple times.
    |
    */

    'deduplication' => [
        // Don't retry a successfully fixed error for this many hours
        'success_cooldown_hours' => 24,

        // Don't retry a failed fix for this many hours
        'failure_cooldown_hours' => 6,

        // Don't attempt if another fix for same error is in progress
        'block_concurrent' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    'notifications' => [
        // Post status updates as thread replies to the original Nightwatch message
        'slack_thread_updates' => true,

        // Notify this channel when circuit breaker opens
        'alert_channel' => env('SELF_HEALING_ALERT_CHANNEL', '#eng-alerts'),

        // Mention these users when escalating
        'escalation_mentions' => env('SELF_HEALING_ESCALATION_MENTIONS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dev Agent Configuration
    |--------------------------------------------------------------------------
    */

    'agent' => [
        // Path to Claude CLI binary (defaults to 'claude' in PATH)
        'claude_path' => env('CLAUDE_CLI_PATH', 'claude'),

        // Timeout for agent execution in seconds
        'timeout' => (int) env('SELF_HEALING_AGENT_TIMEOUT', 300),

        // Run tests before committing
        'run_tests' => env('SELF_HEALING_RUN_TESTS', true),

        // Auto-commit fixes
        'auto_commit' => true,

        // Auto-push to remote
        'auto_push' => true,

        // Commit message prefix
        'commit_prefix' => '[auto-fix]',
    ],
];
