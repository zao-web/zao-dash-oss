# Infrastructure Documentation

Complete infrastructure configuration for the Laravel application including all config files, environment variables, queues, broadcasting, and third-party services.

---

## Table of Contents

1. [Environment Variables](#environment-variables)
2. [Configuration Files](#configuration-files)
3. [Queue & Job System](#queue--job-system)
4. [Broadcasting & WebSockets](#broadcasting--websockets)
5. [Third-Party Services](#third-party-services)
6. [Agent Infrastructure](#agent-infrastructure)
7. [Development & Deployment](#development--deployment)

---

## Environment Variables

### Required Variables

**Application Core:**
```env
APP_NAME=Laravel                    # Application name
APP_ENV=local                       # Environment: local, staging, production
APP_KEY=                            # Generate with `php artisan key:generate`
APP_DEBUG=true                      # Enable debug mode (false in production)
APP_URL=http://localhost            # Application URL
APP_LOCALE=en                       # Default locale
APP_FALLBACK_LOCALE=en              # Fallback locale
APP_FAKER_LOCALE=en_US              # Faker locale for seeding
```

**Database:**
```env
DB_CONNECTION=sqlite                # Database driver: sqlite, mysql, pgsql
DB_DATABASE=database/database.sqlite # Database path (sqlite) or name
# For MySQL/PostgreSQL:
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_USERNAME=root
# DB_PASSWORD=
```

**AI Services (Multi-model Consortium):**
```env
ANTHROPIC_API_KEY=                  # Claude API key (required for agents)
OPENAI_API_KEY=                     # OpenAI API key (optional)
GEMINI_API_KEY=                     # Google Gemini API key (optional)
GROK_API_KEY=                       # Grok API key (optional)
```

### Optional Variables

**Logging:**
```env
LOG_CHANNEL=stack                   # Log channel: stack, single, daily, slack
LOG_STACK=single                    # Channels for stack driver
LOG_DEPRECATIONS_CHANNEL=null       # Deprecation warnings channel
LOG_LEVEL=debug                     # Log level: debug, info, warning, error
```

**Session:**
```env
SESSION_DRIVER=database             # Session driver: database, file, redis, cookie
SESSION_LIFETIME=120                # Session lifetime in minutes
SESSION_ENCRYPT=false               # Encrypt session data
SESSION_PATH=/                      # Session cookie path
SESSION_DOMAIN=null                 # Session cookie domain
```

**Cache:**
```env
CACHE_STORE=database                # Cache driver: database, file, redis, memcached
CACHE_PREFIX=                       # Cache key prefix
```

**Queue:**
```env
QUEUE_CONNECTION=database           # Queue driver: database, redis, sync, sqs
```

**Broadcasting (Reverb WebSockets):**
```env
BROADCAST_CONNECTION=reverb         # Broadcast driver: reverb, pusher, redis, log
REVERB_APP_ID=local                 # Reverb app ID
REVERB_APP_KEY=local                # Reverb app key
REVERB_APP_SECRET=local             # Reverb app secret
REVERB_HOST=localhost               # Reverb host
REVERB_PORT=8080                    # Reverb port
REVERB_SCHEME=http                  # Reverb scheme: http, https
```

**Mail:**
```env
MAIL_MAILER=log                     # Mail driver: log, smtp, ses, postmark, resend
MAIL_HOST=127.0.0.1                 # SMTP host
MAIL_PORT=2525                      # SMTP port
MAIL_USERNAME=null                  # SMTP username
MAIL_PASSWORD=null                  # SMTP password
MAIL_FROM_ADDRESS=hello@example.com # From address
MAIL_FROM_NAME="${APP_NAME}"        # From name
```

**Redis:**
```env
REDIS_CLIENT=phpredis               # Redis client: phpredis, predis
REDIS_HOST=127.0.0.1                # Redis host
REDIS_PASSWORD=null                 # Redis password
REDIS_PORT=6379                     # Redis port
```

**AWS (S3, SES, SQS):**
```env
AWS_ACCESS_KEY_ID=                  # AWS access key
AWS_SECRET_ACCESS_KEY=              # AWS secret key
AWS_DEFAULT_REGION=us-east-1        # AWS region
AWS_BUCKET=                         # S3 bucket name
AWS_USE_PATH_STYLE_ENDPOINT=false   # S3 path style
```

---

## Configuration Files

### config/app.php

**Purpose:** Core application configuration

**Key Settings:**
- `name`: Application name from `APP_NAME`
- `env`: Environment from `APP_ENV` (production, local, staging)
- `debug`: Debug mode from `APP_DEBUG`
- `url`: Application URL from `APP_URL`
- `timezone`: Default timezone (UTC)
- `locale`: Application locale
- `cipher`: Encryption cipher (AES-256-CBC)
- `key`: Encryption key from `APP_KEY`
- `maintenance.driver`: Maintenance mode driver (file, cache)

**Required Env Vars:**
- `APP_NAME`
- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `APP_KEY`

---

### config/database.php

**Purpose:** Database connection configuration

**Supported Drivers:**
- `sqlite`: SQLite database (default)
- `mysql`: MySQL database
- `mariadb`: MariaDB database
- `pgsql`: PostgreSQL database
- `sqlsrv`: SQL Server database

**SQLite Configuration (default):**
```php
'sqlite' => [
    'driver' => 'sqlite',
    'database' => env('DB_DATABASE', database_path('database.sqlite')),
    'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
]
```

**MySQL Configuration:**
```php
'mysql' => [
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
]
```

**Redis Configuration:**
```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
    ],
    'cache' => [
        'database' => env('REDIS_CACHE_DB', '1'),
    ],
]
```

**Required Env Vars:**
- `DB_CONNECTION`
- `DB_DATABASE` (for SQLite)
- For other drivers: `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`

---

### config/cache.php

**Purpose:** Cache configuration

**Supported Drivers:**
- `database`: Database cache (default)
- `file`: File-based cache
- `redis`: Redis cache
- `memcached`: Memcached cache
- `array`: In-memory cache (testing)
- `dynamodb`: AWS DynamoDB cache

**Database Cache:**
- Table: `cache` (from `DB_CACHE_TABLE`)
- Uses migration: `0001_01_01_000001_create_cache_table.php`

**Redis Cache:**
- Connection: `cache` (database 1)
- Prefix: `{app-name}-cache-`

**Required Env Vars:**
- `CACHE_STORE`

**Optional Env Vars:**
- `CACHE_PREFIX`
- `DB_CACHE_TABLE`
- `REDIS_CACHE_DB`

---

### config/queue.php

**Purpose:** Queue configuration for background jobs

**Supported Drivers:**
- `database`: Database queue (default)
- `redis`: Redis queue
- `sync`: Synchronous (no queue)
- `sqs`: AWS SQS
- `beanstalkd`: Beanstalkd queue

**Database Queue:**
- Table: `jobs` (from `DB_QUEUE_TABLE`)
- Retry after: 90 seconds
- Uses migration: `0001_01_01_000002_create_jobs_table.php`

**Failed Jobs:**
- Driver: `database-uuids`
- Table: `failed_jobs`

**Job Batching:**
- Table: `job_batches`

**Required Env Vars:**
- `QUEUE_CONNECTION`

**Optional Env Vars:**
- `DB_QUEUE_TABLE`
- `DB_QUEUE_RETRY_AFTER`
- `REDIS_QUEUE_CONNECTION`

---

### config/broadcasting.php

**Purpose:** Real-time broadcasting configuration

**Supported Drivers:**
- `reverb`: Laravel Reverb (default)
- `pusher`: Pusher
- `ably`: Ably
- `redis`: Redis
- `log`: Log driver (development)
- `null`: Disabled

**Reverb Configuration:**
```php
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],
]
```

**Required Env Vars:**
- `BROADCAST_CONNECTION`
- `REVERB_APP_ID`
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`
- `REVERB_HOST`
- `REVERB_PORT`
- `REVERB_SCHEME`

**Frontend Configuration:**
```env
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

---

### config/reverb.php

**Purpose:** Laravel Reverb WebSocket server configuration

**Server Configuration:**
- Host: `0.0.0.0` (from `REVERB_SERVER_HOST`)
- Port: `8080` (from `REVERB_SERVER_PORT`)
- Max request size: `10_000` bytes

**Scaling Configuration:**
- Enabled: `false` (from `REVERB_SCALING_ENABLED`)
- Uses Redis for horizontal scaling
- Channel: `reverb`

**App Configuration:**
- Ping interval: `60` seconds
- Activity timeout: `30` seconds
- Allowed origins: `['*']`

**Required Env Vars:**
- `REVERB_APP_KEY`
- `REVERB_APP_SECRET`
- `REVERB_APP_ID`
- `REVERB_HOST`

**Optional Env Vars:**
- `REVERB_SERVER_HOST`
- `REVERB_SERVER_PORT`
- `REVERB_SCALING_ENABLED`

---

### config/session.php

**Purpose:** Session management configuration

**Driver Options:**
- `database`: Database sessions (default)
- `file`: File-based sessions
- `redis`: Redis sessions
- `cookie`: Cookie-only sessions
- `memcached`: Memcached sessions

**Configuration:**
- Lifetime: `120` minutes (from `SESSION_LIFETIME`)
- Expire on close: `false`
- Encrypt: `false` (from `SESSION_ENCRYPT`)
- Table: `sessions` (for database driver)
- Cookie name: `{app-name}-session`

**Required Env Vars:**
- `SESSION_DRIVER`
- `SESSION_LIFETIME`

---

### config/filesystems.php

**Purpose:** File storage configuration

**Disks:**
- `local`: Private storage (`storage/app/private`)
- `public`: Public storage (`storage/app/public`)
- `s3`: AWS S3 storage

**S3 Configuration:**
```php
's3' => [
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
]
```

**Required Env Vars:**
- `FILESYSTEM_DISK`

**For S3:**
- `AWS_ACCESS_KEY_ID`
- `AWS_SECRET_ACCESS_KEY`
- `AWS_DEFAULT_REGION`
- `AWS_BUCKET`

---

### config/mail.php

**Purpose:** Email configuration

**Supported Mailers:**
- `log`: Log emails (development, default)
- `smtp`: SMTP server
- `ses`: AWS SES
- `postmark`: Postmark
- `resend`: Resend
- `sendmail`: Sendmail

**SMTP Configuration:**
```php
'smtp' => [
    'host' => env('MAIL_HOST', '127.0.0.1'),
    'port' => env('MAIL_PORT', 2525),
    'username' => env('MAIL_USERNAME'),
    'password' => env('MAIL_PASSWORD'),
]
```

**From Address:**
- Address: `MAIL_FROM_ADDRESS`
- Name: `MAIL_FROM_NAME`

**Required Env Vars:**
- `MAIL_MAILER`
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

---

### config/logging.php

**Purpose:** Application logging configuration

**Channels:**
- `stack`: Multiple channels (default)
- `single`: Single log file
- `daily`: Daily rotating logs
- `slack`: Slack notifications
- `stderr`: Standard error output
- `syslog`: System log
- `null`: Discard logs

**Stack Configuration:**
- Channels: From `LOG_STACK` (default: `single`)
- Path: `storage/logs/laravel.log`
- Level: From `LOG_LEVEL` (default: `debug`)

**Daily Logs:**
- Days to retain: `14` (from `LOG_DAILY_DAYS`)

**Required Env Vars:**
- `LOG_CHANNEL`

**Optional Env Vars:**
- `LOG_LEVEL`
- `LOG_STACK`
- `LOG_DAILY_DAYS`
- `LOG_SLACK_WEBHOOK_URL`

---

### config/horizon.php

**Purpose:** Laravel Horizon queue monitoring (optional)

**Configuration:**
- Path: `/horizon` (from `HORIZON_PATH`)
- Redis connection: `default`
- Memory limit: `64` MB

**Queue Workers:**
- Production: Max 10 processes
- Local: Max 3 processes

**Trim Settings:**
- Recent jobs: `60` minutes
- Failed jobs: `10080` minutes (7 days)

**Required Env Vars:**
- None (optional package)

**Optional Env Vars:**
- `HORIZON_PATH`
- `HORIZON_DOMAIN`
- `HORIZON_NAME`

---

### config/services.php

**Purpose:** Third-party service configuration

**AI Services:**
```php
'anthropic' => [
    'api_key' => env('ANTHROPIC_API_KEY'),
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
    'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
    'pubsub_topic' => env('GOOGLE_PUBSUB_TOPIC'),
],
```

**Integration Services:**
```php
'slack' => [
    'client_id' => env('SLACK_CLIENT_ID'),
    'client_secret' => env('SLACK_CLIENT_SECRET'),
    'signing_secret' => env('SLACK_SIGNING_SECRET'),
    'redirect_uri' => env('SLACK_REDIRECT_URI'),
],
'github' => [
    'app_id' => env('GITHUB_APP_ID'),
    'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
    'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
],
'harvest' => [
    'client_id' => env('HARVEST_CLIENT_ID'),
    'client_secret' => env('HARVEST_CLIENT_SECRET'),
    'redirect_uri' => env('HARVEST_REDIRECT_URI'),
],
'notion' => [
    'client_id' => env('NOTION_CLIENT_ID'),
    'client_secret' => env('NOTION_CLIENT_SECRET'),
    'redirect_uri' => env('NOTION_REDIRECT_URI'),
],
'quickbooks' => [
    'client_id' => env('QUICKBOOKS_CLIENT_ID'),
    'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
    'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),
    'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'),
],
```

**Search APIs:**
```php
'serper' => [
    'api_key' => env('SERPER_API_KEY'),
],
'serpapi' => [
    'api_key' => env('SERPAPI_API_KEY'),
],
```

**Required Env Vars (by feature):**
- Agent system: `ANTHROPIC_API_KEY`
- Slack integration: `SLACK_CLIENT_ID`, `SLACK_CLIENT_SECRET`
- GitHub integration: `GITHUB_APP_ID`, `GITHUB_APP_PRIVATE_KEY`
- Search tools: `SERPER_API_KEY` or `SERPAPI_API_KEY`

---

### config/agents.php

**Purpose:** Agent system configuration

**Sections:**

**1. Defaults:**
```php
'defaults' => [
    'model' => env('AGENT_DEFAULT_MODEL', 'sonnet'),
    'max_budget_usd' => env('AGENT_DEFAULT_BUDGET', 5.00),
    'max_turns' => env('AGENT_MAX_TURNS', 50),
    'timeout_seconds' => env('AGENT_TIMEOUT', 300),
    'requires_approval' => true,
]
```

**2. Models:**
- `opus`: Claude Opus 4 ($0.015/$0.075 per 1k tokens)
- `sonnet`: Claude Sonnet 4 ($0.003/$0.015 per 1k tokens)
- `haiku`: Claude Haiku 3 ($0.00025/$0.00125 per 1k tokens)

**3. Tools:** Per-agent tool allowlists
```php
'tools' => [
    'dev-agent' => [
        'read_file',
        'write_file',
        'search_code',
        'run_tests',
        'git_operations',
    ],
]
```

**4. Domains:** Per-agent network allowlists
```php
'domains' => [
    'marketing' => [
        'api.twitter.com',
        'api.linkedin.com',
    ],
]
```

**5. Circuit Breaker:**
```php
'circuit_breaker' => [
    'failure_threshold' => env('AGENT_CIRCUIT_FAILURE_THRESHOLD', 3),
    'reset_timeout_minutes' => env('AGENT_CIRCUIT_RESET_MINUTES', 60),
]
```

**6. Spend Limits:**
```php
'spend_limits' => [
    'daily_per_agent' => env('AGENT_DAILY_SPEND_LIMIT', 100.00),
    'daily_total' => env('AGENT_DAILY_TOTAL_LIMIT', 500.00),
    'monthly_total' => env('AGENT_MONTHLY_LIMIT', 5000.00),
]
```

**7. Rate Limits:**
```php
'rate_limits' => [
    'runs_per_minute' => env('AGENT_RUNS_PER_MINUTE', 10),
    'api_calls_per_minute' => env('AGENT_API_CALLS_PER_MINUTE', 60),
]
```

**8. Sandbox:**
```php
'sandbox' => [
    'base_path' => storage_path('app/agent-workspaces'),
    'cleanup_after_hours' => env('AGENT_SANDBOX_CLEANUP_HOURS', 24),
    'max_file_size_mb' => env('AGENT_MAX_FILE_SIZE_MB', 50),
]
```

**9. Schedules:** Cron schedules for automated agents
```php
'schedules' => [
    'business-strategist' => [
        'weekly' => '0 6 * * 1',      // Monday 6am
        'daily' => '0 7 * * 2-5',     // Tue-Fri 7am
    ],
    'client-health-monitor' => '0 7 * * *', // Daily 7am
]
```

**10. Chains:** Agent chaining configuration
```php
'chains' => [
    'dev-agent' => ['qa-agent'],
    'qa-agent' => ['communication'],
]
```

**Required Env Vars:**
- None (all have defaults)

**Optional Env Vars:**
- `AGENT_DEFAULT_MODEL`
- `AGENT_DEFAULT_BUDGET`
- `AGENT_MAX_TURNS`
- `AGENT_TIMEOUT`
- `AGENT_CIRCUIT_FAILURE_THRESHOLD`
- `AGENT_CIRCUIT_RESET_MINUTES`
- `AGENT_DAILY_SPEND_LIMIT`
- `AGENT_DAILY_TOTAL_LIMIT`
- `AGENT_MONTHLY_LIMIT`
- `AGENT_RUNS_PER_MINUTE`
- `AGENT_API_CALLS_PER_MINUTE`
- `AGENT_SANDBOX_CLEANUP_HOURS`
- `AGENT_MAX_FILE_SIZE_MB`

---

### config/approval_policies.php

**Purpose:** Approval workflow configuration

**Risk Levels:**
- `critical`: Irreversible actions (requires 2FA)
- `high`: Significant impact, external-facing
- `medium`: Moderate impact, reversible
- `low`: Minimal impact, internal only

**Categories:**
- `deploy.production`: Production deployment
- `deploy.staging`: Staging deployment
- `financial.invoice`: Send invoice
- `financial.payment`: Process payment
- `financial.categorize`: Categorize expense
- `database.migration`: Run migration
- `communication.client_email`: Client email
- `communication.cold_outreach`: Cold outreach
- `content.publish`: Publish content
- `content.social_post`: Social media post
- `code.merge`: Merge code
- `agent.task_assignment`: Assign agent task

**Auto-Approve Conditions:**
- `amount_under`: Max dollar amount
- `existing_client`: Must be existing client
- `tests_pass`: All tests pass
- `no_breaking_changes`: No breaking changes
- `pre_approved_template`: Pre-approved template IDs

**Notification Settings:**
```php
'notifications' => [
    'channels' => ['database', 'mail', 'slack'],
    'urgent_channels' => ['database', 'mail', 'slack', 'sms'],
    'slack_channel' => env('APPROVAL_SLACK_CHANNEL', '#approvals'),
]
```

**Escalation:**
```php
'escalation' => [
    'escalate_after_hours' => 12,
    'escalate_to' => ['owner'],
    'immediate_escalation' => [
        'deploy.production',
        'financial.payment',
        'database.migration',
    ],
]
```

**Required Env Vars:**
- None

**Optional Env Vars:**
- `APPROVAL_SLACK_CHANNEL`

---

### config/auth.php

**Purpose:** Authentication configuration

**Guards:**
- `web`: Session-based authentication (default)

**Providers:**
- `users`: Eloquent user provider

**Password Reset:**
- Table: `password_reset_tokens`
- Expire: `60` minutes
- Throttle: `60` seconds

**Password Timeout:**
- Default: `10800` seconds (3 hours)

**Required Env Vars:**
- None

**Optional Env Vars:**
- `AUTH_GUARD`
- `AUTH_PASSWORD_BROKER`
- `AUTH_PASSWORD_TIMEOUT`

---

## Queue & Job System

### Queue Configuration

**Default Driver:** `database`

**Database Queue Tables:**
- `jobs`: Pending and processing jobs
- `job_batches`: Job batch tracking
- `failed_jobs`: Failed job storage

**Job Classes:**
- `ExecuteAgentJob`: Run agent tasks
- `ProcessAgentResultJob`: Process agent results
- `ProcessAgentTasksJob`: Process delegated tasks
- `UpdateGoalProgressJob`: Update strategic goals
- `CalculateFunnelMetricsJob`: Calculate funnel snapshots
- `ParseMeetingJob`: Parse meeting notes
- `SyncGoogleDriveJob`: Sync Google Drive
- `SyncSlackJob`: Sync Slack messages
- `SyncNotionJob`: Sync Notion databases
- `SyncGitHubJob`: Sync GitHub repos/issues
- `SyncHarvestJob`: Sync Harvest time entries
- `SyncWordPressJob`: Sync WordPress posts
- `SyncQuickBooksJob`: Sync QuickBooks data
- `SyncGSuiteJob`: Sync Gmail/Calendar

**Running Queue Workers:**
```bash
# Single worker
php artisan queue:work

# With tries
php artisan queue:listen --tries=1

# Development (with all services)
composer dev
```

---

## Broadcasting & WebSockets

### Reverb Configuration

**Server:**
- Host: `0.0.0.0` (listens on all interfaces)
- Port: `8080` (configurable)
- Scheme: `http` (use `https` in production)

**Starting Reverb:**
```bash
php artisan reverb:start
```

**Broadcast Channels:**

**Private Channels:**
```php
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
```

**Agent Channels:**
```php
// Agent-specific updates
Broadcast::channel('agents.{agentId}', function ($user, $agentId) {
    return Agent::where('id', $agentId)->exists();
});

// Run-specific updates
Broadcast::channel('agent-runs.{runId}', function ($user, $runId) {
    return AgentRun::where('id', $runId)->exists();
});

// User notifications
Broadcast::channel('notifications.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
```

**Frontend Configuration:**

**Laravel Echo Setup (resources/js/app.ts):**
```typescript
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

window.Pusher = Pusher

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    scheme: import.meta.env.VITE_REVERB_SCHEME,
    forceTLS: false,
})
```

**Listening to Channels:**
```typescript
// User-specific notifications
Echo.private(`notifications.${userId}`)
    .listen('NotificationCreated', (e) => {
        console.log('New notification:', e.notification)
    })

// Agent run updates
Echo.channel(`agent-runs.${runId}`)
    .listen('AgentRunUpdated', (e) => {
        console.log('Run updated:', e.run)
    })
```

---

## Third-Party Services

### AI Services

**Anthropic (Claude):**
- API Key: `ANTHROPIC_API_KEY`
- Models: Opus 4, Sonnet 4, Haiku 3
- Used for: Agent execution, text generation

**OpenAI (ChatGPT):**
- API Key: `OPENAI_API_KEY`
- Used for: Fallback AI processing

**Google (Gemini):**
- API Key: `GEMINI_API_KEY`
- Used for: Multi-modal AI tasks

**Grok (X.AI):**
- API Key: `GROK_API_KEY`
- Model: `GROK_MODEL`
- Used for: Search and research tasks

---

### Integration Services

**Slack:**
- Client ID: `SLACK_CLIENT_ID`
- Client Secret: `SLACK_CLIENT_SECRET`
- Signing Secret: `SLACK_SIGNING_SECRET`
- Redirect URI: `SLACK_REDIRECT_URI`
- Bot Token: `SLACK_BOT_USER_OAUTH_TOKEN`
- Features: Messages, threads, channels, notifications

**GitHub:**
- App ID: `GITHUB_APP_ID`
- App Slug: `GITHUB_APP_SLUG`
- Private Key: `GITHUB_APP_PRIVATE_KEY`
- Webhook Secret: `GITHUB_WEBHOOK_SECRET`
- Features: Repos, issues, PRs, deployments

**Google Workspace:**
- Client ID: `GOOGLE_CLIENT_ID`
- Client Secret: `GOOGLE_CLIENT_SECRET`
- Redirect URI: `GOOGLE_REDIRECT_URI`
- PubSub Topic: `GOOGLE_PUBSUB_TOPIC`
- Features: Gmail, Calendar, Drive

**Harvest:**
- Client ID: `HARVEST_CLIENT_ID`
- Client Secret: `HARVEST_CLIENT_SECRET`
- Redirect URI: `HARVEST_REDIRECT_URI`
- Account ID: `HARVEST_ACCOUNT_ID`
- Features: Time tracking, projects, invoices

**Notion:**
- Client ID: `NOTION_CLIENT_ID`
- Client Secret: `NOTION_CLIENT_SECRET`
- Redirect URI: `NOTION_REDIRECT_URI`
- Features: Databases, pages, content

**QuickBooks:**
- Client ID: `QUICKBOOKS_CLIENT_ID`
- Client Secret: `QUICKBOOKS_CLIENT_SECRET`
- Redirect URI: `QUICKBOOKS_REDIRECT_URI`
- Environment: `QUICKBOOKS_ENVIRONMENT` (sandbox, production)
- Features: Invoices, expenses, customers, transactions

**LinkedIn:**
- Client ID: `LINKEDIN_CLIENT_ID`
- Client Secret: `LINKEDIN_CLIENT_SECRET`
- Redirect URI: `LINKEDIN_REDIRECT_URI`
- Features: Posts, profile

**X (Twitter):**
- Client ID: `X_CLIENT_ID`
- Client Secret: `X_CLIENT_SECRET`
- Redirect URI: `X_REDIRECT_URI`
- Features: Posts, trends

---

### Search APIs

**Serper:**
- API Key: `SERPER_API_KEY`
- Used for: Web search in agents

**SerpAPI:**
- API Key: `SERPAPI_API_KEY`
- Used for: Alternative web search

---

## Agent Infrastructure

### Agent System Components

**Traits:**
- `HasApprovalGates`: Approval workflow functionality
- `HasSandbox`: Filesystem/network isolation

**Services:**
- `AgentExecutor`: Execute agents via CLI or SDK
- `ChainExecutor`: Handle agent chaining
- `ClaudeAgentSdk`: API-based execution
- `ClaudeCliRunner`: CLI-based execution
- `ApprovalService`: Manage approval requests
- `AgentSandbox`: Sandbox management

**Jobs:**
- `ExecuteAgentJob`: Execute agent runs
- `ProcessAgentResultJob`: Process results and chain
- `ProcessAgentTasksJob`: Process delegated tasks

**Models:**
- `Agent`: Agent definitions
- `AgentRun`: Execution history
- `ApprovalRequest`: Approval queue
- `AgentTask`: Delegated tasks

**Observers:**
- `AgentRunObserver`: Broadcast run events
- `ApprovalRequestObserver`: Broadcast approval events
- `ClientObserver`: Track client changes

---

### Sandbox Configuration

**Base Path:** `storage/app/agent-workspaces`

**Sandbox Structure:**
```
storage/app/agent-workspaces/{run-id}/
├── workspace/     # Working files (read/write)
├── output/        # Results (preserved after cleanup)
├── temp/          # Temporary files
├── logs/          # Agent logs
└── .sandbox-manifest.json
```

**File Size Limit:** 50 MB (configurable)

**Cleanup:** After 24 hours (configurable)

**Blocked Patterns:**
- `*.env*`, `*credentials*`, `*secret*`
- `*.pem`, `*.key`, `*password*`
- `.git/config`, `config/*.php`

---

### Circuit Breaker

**Trigger:** 3 consecutive failures (configurable)

**Effect:** Agent stops executing

**Reset:** Automatic after 1 hour, or manual via UI

**Database Fields:**
```php
$agent->consecutive_failures  // int
$agent->circuit_broken_at     // timestamp or null
```

---

### Spend Governor

**Limits:**
- Per agent daily: $100 (configurable)
- Total daily: $500 (configurable)
- Monthly total: $5000 (configurable)

**Enforcement:** Pre-execution budget check

---

## Development & Deployment

### Local Development

**Requirements:**
- PHP 8.2+
- Composer
- Node.js 18+
- NPM
- SQLite (or MySQL/PostgreSQL)

**Setup:**
```bash
# Clone and install
git clone <repo>
cd zao-dash
composer setup

# Or manually:
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

**Development Server:**
```bash
# All services (server, queue, logs, vite)
composer dev

# Individual services:
php artisan serve              # Web server
php artisan queue:listen       # Queue worker
php artisan reverb:start       # WebSocket server
npm run dev                    # Frontend dev server
php artisan pail              # Log viewer
```

---

### Scheduled Tasks

**Schedule Runner:**
```bash
# In production, add to crontab:
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

**Scheduled Jobs (from routes/console.php):**

**Every Minute:**
- `agents:run-scheduled`: Execute scheduled agents

**Every 5 Minutes:**
- Expire old approval requests
- Process agent tasks

**Every 15 Minutes:**
- Process health alert escalations

**Every 30 Minutes:**
- Sync Slack (backup to webhooks)

**Hourly:**
- Update goal progress
- Reset circuit breakers
- Agent health check

**Every 2 Hours:**
- Sync Harvest

**Every 4 Hours:**
- Sync Google Drive
- Sync GitHub
- Sync WordPress

**Every 6 Hours:**
- Sync Notion

**Daily:**
- Calculate funnel metrics (11:55 PM)
- Sync QuickBooks (6:00 AM)
- Sync Google Drive (5:00 AM)

---

### Package Dependencies

**PHP (composer.json):**
- `laravel/framework`: ^12.0
- `laravel/reverb`: ^1.6 (WebSockets)
- `laravel/horizon`: ^5.40 (Queue monitoring)
- `inertiajs/inertia-laravel`: ^2.0 (SPA)
- `openai-php/laravel`: ^0.18.0 (OpenAI)
- `predis/predis`: ^3.3 (Redis client)

**JavaScript (package.json):**
- `vue`: ^3.5.25
- `@inertiajs/vue3`: ^2.2.19
- `@ai-sdk/anthropic`: ^2.0.56
- `laravel-echo`: ^2.2.6
- `pusher-js`: ^8.4.0
- `@unovis/vue`: ^1.6.2 (Charts)
- `tailwindcss`: ^4.1.17
- `vite`: ^7.0.7

---

### Build & Deployment

**Build Frontend:**
```bash
npm run build
```

**Optimize Laravel:**
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**Clear Caches:**
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

**Run Migrations:**
```bash
php artisan migrate --force
```

**Storage Permissions:**
```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

---

### Testing

**Run Tests:**
```bash
composer test
# or
php artisan test
```

**Test Framework:** Pest PHP

---

### Environment Setup Checklist

**Required:**
- [ ] `APP_KEY` generated
- [ ] Database configured
- [ ] `ANTHROPIC_API_KEY` set (for agents)
- [ ] Reverb configuration set
- [ ] Storage directories writable

**Production:**
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] HTTPS configured
- [ ] Database backups enabled
- [ ] Queue workers running
- [ ] Reverb running (or Pusher configured)
- [ ] Cron job for scheduler
- [ ] Integration API keys configured
- [ ] Mail service configured

---

## Monitoring & Observability

### Laravel Nightwatch

**Purpose:** Application performance monitoring with query, request, and exception tracking.

**Package:** `laravel/nightwatch` ^1.21

**Quota Management:**

Free tier provides 300k events/month. Query filtering is configured in `NightwatchServiceProvider` to conserve quota by excluding high-frequency, low-value queries.

**Filtered Query Categories:**

| Category | Queries | Rationale |
|----------|---------|-----------|
| ID Lookups | `harvest_projects`, `slack_channels`, `slack_workspaces`, `github_installations` | Simple FK lookups, ~150K+/month |
| Internal Processing | `slack_messages`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions` | Background sync noise |
| Sync Updates | `synced_at`, `last_message_at`, `issues_synced_at`, `prs_synced_at` | Timestamp updates during sync |
| Resolution Queries | `harvest_client_id`, `wp_post_id`, `client_contacts.email` | Entity resolution during sync |

**Preserved Queries:**

| Category | Examples | Reason |
|----------|----------|--------|
| Business Logic | `leads`, `qbo_invoices`, `clients` (list), `projects` (list) | Revenue/business visibility |
| Agent System | `agents`, `agent_runs`, `approval_requests` | Core functionality |
| User-Facing | Login, dashboard, settings queries | UX monitoring |

**Configuration Location:** `app/Providers/NightwatchServiceProvider.php`

**Disabling All Query Monitoring:**
```env
NIGHTWATCH_IGNORE_QUERIES=true
```

**Estimated Savings:**
- Before: ~300K+ queries/month (quota exhausted in ~3 weeks)
- After: ~50-80K queries/month (business-critical only)

**Modifying Filters:**

To add/remove filtered queries, edit the arrays in `NightwatchServiceProvider`:
```php
protected array $filterIdLookups = [
    'harvest_projects',    // Add tables here for ID lookup filtering
];

protected array $filterAllQueries = [
    'slack_messages',      // Add tables here to filter ALL queries
];
```

---

## See Also

- [Agent System](./AGENTS.md) - Agent definitions and workflows
- [API Documentation](./API.md) - API endpoints
- [Commands](./COMMANDS.md) - Artisan commands
- [System Architecture](./SYSTEM.md) - Overall system design
