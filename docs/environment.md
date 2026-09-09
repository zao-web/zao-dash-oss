# Environment variables

Reference for every environment variable this tree reads. Generated from `.env.example`, `${NAME}` placeholders in `.mcp.json`, and `env()` calls in `config/`, `app/`, `routes/`, and `bootstrap/`.

Regenerate after a config change:

```bash
php scripts/catalog-env.php
```

This file lists names and purposes only. It does not include values. Do not paste real keys into docs or commits.

Status values:

- `framework` is Laravel or frontend runtime. Set these on first run.
- `configure` is an integration or product setting. An empty value disables that integration.
- `stub` is present so older notes and migrations still parse. Those HTTP routes and MCP tools are not registered in this snapshot.
- `code-only` is read by application code and is absent from `.env.example`.
- `client` is read by an MCP client process, not by the Laravel app.

Catalog size: 351.

| Variable | Status | Purpose | Sources |
| --- | --- | --- | --- |
| `ABLY_KEY` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `AGENT_API_CALLS_PER_MINUTE` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_CIRCUIT_FAILURE_THRESHOLD` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_CIRCUIT_RESET_MINUTES` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_DAILY_SPEND_LIMIT` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_DAILY_TOTAL_LIMIT` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_DEFAULT_BUDGET` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_DEFAULT_MODEL` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_INTERNAL_TOKEN` | configure | Shared secret for agent and website-builder callbacks. Leave empty until you run those jobs. | `.env.example`, `app/Services/Agents/ClaudeCliRunner.php`, `app/Http/Controllers/WebsiteBuilderController.php` |
| `AGENT_MAX_FILE_SIZE_MB` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_MAX_TURNS` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_MONTHLY_LIMIT` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_RUNS_PER_MINUTE` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_SANDBOX_CLEANUP_HOURS` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `AGENT_TIMEOUT` | configure | Agent runtime limits, models, and circuit-breaker settings. | `config/agents.php` |
| `ANTHROPIC_API_KEY` | configure | Anthropic API credentials for in-app agents. Leave empty to skip model calls. | `.env.example`, `config/services.php`, `app/Services/Agents/ClaudeCliRunner.php`, `app/Services/Agents/InteractiveClaudeRunner.php` |
| `APPROVAL_SLACK_CHANNEL` | configure | Read by config/approval_policies.php. | `config/approval_policies.php` |
| `APP_DEBUG` | framework | Shows detailed errors when true. Keep false on any shared host. | `.env.example`, `config/app.php` |
| `APP_DISPLAY_TIMEZONE` | framework | Read by config/app.php. | `config/app.php` |
| `APP_ENV` | framework | Runtime environment. Use local for a first run. | `.env.example`, `config/app.php` |
| `APP_FAKER_LOCALE` | framework | Faker locale for seeders and factories. | `.env.example`, `config/app.php` |
| `APP_FALLBACK_LOCALE` | framework | Locale used when a translation is missing. | `.env.example`, `config/app.php` |
| `APP_KEY` | framework | Laravel encryption key. Generate with php artisan key:generate. Never commit a real key. | `.env.example`, `config/app.php` |
| `APP_LOCALE` | framework | Default locale. | `.env.example`, `config/app.php` |
| `APP_MAINTENANCE_DRIVER` | framework | Driver for php artisan down. | `.env.example`, `config/app.php` |
| `APP_MAINTENANCE_STORE` | framework | Read by config/app.php. | `config/app.php` |
| `APP_NAME` | framework | Product name shown in the UI, mail, and session key prefix. | `.env.example`, `config/app.php`, `config/session.php`, `config/database.php`, +1 |
| `APP_PREVIOUS_KEYS` | framework | Read by config/app.php. | `config/app.php` |
| `APP_URL` | framework | Public URL of this app. MCP clients and OAuth redirects use it. | `.env.example`, `config/app.php`, `config/mail.php`, `config/filesystems.php` |
| `AUTH_GUARD` | framework | Read by config/auth.php. | `config/auth.php` |
| `AUTH_MODEL` | framework | Read by config/auth.php. | `config/auth.php` |
| `AUTH_PASSWORD_BROKER` | framework | Read by config/auth.php. | `config/auth.php` |
| `AUTH_PASSWORD_RESET_TOKEN_TABLE` | framework | Read by config/auth.php. | `config/auth.php` |
| `AUTH_PASSWORD_TIMEOUT` | framework | Read by config/auth.php. | `config/auth.php` |
| `AWS_ACCESS_KEY_ID` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `.env.example`, `config/queue.php`, `config/filesystems.php`, `config/cache.php`, +1 |
| `AWS_BUCKET` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `.env.example`, `config/filesystems.php` |
| `AWS_DEFAULT_REGION` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `.env.example`, `config/queue.php`, `config/filesystems.php`, `config/cache.php`, +1 |
| `AWS_ENDPOINT` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `config/filesystems.php` |
| `AWS_SECRET_ACCESS_KEY` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `.env.example`, `config/queue.php`, `config/filesystems.php`, `config/cache.php`, +1 |
| `AWS_URL` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `config/filesystems.php` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | framework | S3 credentials for the durable upload disk. Needed on Laravel Cloud. | `.env.example`, `config/filesystems.php` |
| `BANK_ACCOUNT_NAME` | configure | Account holder name printed on invoices. | `.env.example`, `config/app.php` |
| `BANK_ACCOUNT_NUMBER` | configure | ACH account number printed on invoices. Empty hides the block. | `.env.example`, `config/app.php` |
| `BANK_NAME` | configure | Bank name printed as ACH instructions. Empty hides the block. | `.env.example`, `config/app.php` |
| `BANK_ROUTING_NUMBER` | configure | ACH routing number printed on invoices. Empty hides the block. | `.env.example`, `config/app.php` |
| `BCRYPT_ROUNDS` | framework | Listed in .env.example. | `.env.example` |
| `BEANSTALKD_QUEUE` | configure | Read by config/queue.php. | `config/queue.php` |
| `BEANSTALKD_QUEUE_HOST` | configure | Read by config/queue.php. | `config/queue.php` |
| `BEANSTALKD_QUEUE_RETRY_AFTER` | configure | Read by config/queue.php. | `config/queue.php` |
| `BROADCAST_CONNECTION` | framework | Broadcast driver. .env.example uses reverb. | `.env.example`, `config/broadcasting.php` |
| `BROWSERLESS_API_KEY` | configure | Optional Browserless key for headless browsing. | `config/services.php` |
| `CACHE_PREFIX` | framework | Read by config/cache.php. | `config/cache.php` |
| `CACHE_STORE` | framework | Cache store. database is enough locally. | `.env.example`, `config/cache.php` |
| `CHROME_PATH` | configure | Chrome binary for the tax-agency bridge. That bridge is stubbed in this snapshot. | `config/services.php` |
| `CLAUDE_CLI_PATH` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `CLAUDE_CODE_OAUTH_TOKEN` | configure | Claude CLI OAuth token for local Claude Code runs. Distinct from ANTHROPIC_API_KEY. | `config/services.php`, `app/Services/AI/ClaudeCliService.php`, `app/Services/Agents/ClaudeCliRunner.php`, `app/Services/Agents/InteractiveClaudeRunner.php`, +1 |
| `CLIENT_EMAIL_CC` | configure | Extra CC on client emails. | `.env.example`, `config/app.php` |
| `CLOUDFLARE_ACCOUNT_ID` | configure | Cloudflare account and Workers AI settings. | `config/services.php` |
| `CLOUDFLARE_API_TOKEN` | configure | Cloudflare account and Workers AI settings. | `config/services.php` |
| `CLOUDFLARE_NARRATIVE_MODEL` | configure | Cloudflare account and Workers AI settings. | `config/services.php` |
| `COMPANY_ADDRESS` | configure | Address printed on invoices. | `.env.example`, `config/app.php` |
| `COMPANY_EMAIL` | configure | From address and invoicer fallback for billing mail. | `.env.example`, `config/app.php` |
| `COMPANY_LOGO_URL` | configure | Logo URL printed on invoices. | `config/app.php` |
| `COMPANY_NAME` | configure | Legal or trading name printed on invoices. | `.env.example`, `config/app.php` |
| `COMPANY_PHONE` | configure | Phone printed on invoices. | `.env.example`, `config/app.php` |
| `DB_CACHE_CONNECTION` | framework | Database connection. SQLite is the local default. | `config/cache.php` |
| `DB_CACHE_LOCK_CONNECTION` | framework | Database connection. SQLite is the local default. | `config/cache.php` |
| `DB_CACHE_LOCK_TABLE` | framework | Database connection. SQLite is the local default. | `config/cache.php` |
| `DB_CACHE_TABLE` | framework | Database connection. SQLite is the local default. | `config/cache.php` |
| `DB_CHARSET` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_COLLATION` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_CONNECTION` | framework | Database connection. SQLite is the local default. | `.env.example`, `config/queue.php`, `config/database.php` |
| `DB_DATABASE` | framework | Database connection. SQLite is the local default. | `.env.example`, `config/database.php` |
| `DB_ENCRYPT` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_FOREIGN_KEYS` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_HOST` | framework | Database connection. SQLite is the local default. | `.env.example`, `config/database.php` |
| `DB_PASSWORD` | framework | Database connection. SQLite is the local default. | `.env.example`, `config/database.php` |
| `DB_PORT` | framework | Database connection. SQLite is the local default. | `.env.example`, `config/database.php` |
| `DB_QUEUE` | framework | Database connection. SQLite is the local default. | `config/queue.php` |
| `DB_QUEUE_CONNECTION` | framework | Database connection. SQLite is the local default. | `config/queue.php` |
| `DB_QUEUE_RETRY_AFTER` | framework | Database connection. SQLite is the local default. | `config/queue.php` |
| `DB_QUEUE_TABLE` | framework | Database connection. SQLite is the local default. | `config/queue.php` |
| `DB_SOCKET` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_TRUST_SERVER_CERTIFICATE` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_URL` | framework | Database connection. SQLite is the local default. | `config/database.php` |
| `DB_USERNAME` | framework | Database connection. SQLite is the local default. | `.env.example`, `config/database.php` |
| `DYNAMODB_CACHE_TABLE` | configure | Read by config/cache.php. | `config/cache.php` |
| `DYNAMODB_ENDPOINT` | configure | Read by config/cache.php. | `config/cache.php` |
| `FACEBOOK_PAGE_ACCESS_TOKEN` | configure | Facebook page token. | `config/services.php` |
| `FFMPEG_PATH` | configure | Path to the ffmpeg binary for video jobs. | `config/services.php` |
| `FFPROBE_PATH` | configure | Path to the ffprobe binary for video jobs. | `config/services.php` |
| `FILESYSTEM_DISK` | framework | Default filesystem disk. Use s3 on Laravel Cloud. | `.env.example`, `config/filesystems.php` |
| `FILESYSTEM_DISK_PUBLIC` | framework | Read by config/filesystems.php. | `config/filesystems.php` |
| `FINANCIAL_ARCHIVE_ROOT` | configure | Read by config/tax.php. | `config/tax.php` |
| `GEMINI_API_KEY` | configure | Google Gemini API credentials. | `.env.example`, `config/services.php`, `app/Services/AI/GeminiService.php` |
| `GITHUB_APP_ID` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `.env.example`, `config/services.php` |
| `GITHUB_APP_PRIVATE_KEY` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `config/services.php`, `app/Console/Commands/SetupGitHubKey.php` |
| `GITHUB_APP_PRIVATE_KEY_BASE64` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `.env.example`, `config/services.php`, `app/Console/Commands/SetupGitHubKey.php` |
| `GITHUB_APP_PRIVATE_KEY_PATH` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `config/services.php`, `app/Console/Commands/SetupGitHubKey.php` |
| `GITHUB_APP_SLUG` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `.env.example`, `config/services.php` |
| `GITHUB_CLIENT_ID` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `.env.example`, `config/services.php` |
| `GITHUB_CLIENT_SECRET` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `.env.example`, `config/services.php` |
| `GITHUB_PRIVATE_KEY_BASE64` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `config/services.php` |
| `GITHUB_REDIRECT_URI` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `config/services.php` |
| `GITHUB_WEBHOOK_SECRET` | configure | GitHub App or OAuth credentials. Empty disables GitHub sync. | `.env.example`, `config/services.php` |
| `GOOGLE_CLIENT_ID` | configure | Google OAuth and related API keys for Gmail, Calendar, and Drive. | `.env.example`, `config/services.php` |
| `GOOGLE_CLIENT_SECRET` | configure | Google OAuth and related API keys for Gmail, Calendar, and Drive. | `.env.example`, `config/services.php` |
| `GOOGLE_PLACES_API_KEY` | configure | Google OAuth and related API keys for Gmail, Calendar, and Drive. | `config/services.php` |
| `GOOGLE_PUBSUB_TOPIC` | configure | Google OAuth and related API keys for Gmail, Calendar, and Drive. | `config/services.php` |
| `GOOGLE_REDIRECT_URI` | configure | Google OAuth and related API keys for Gmail, Calendar, and Drive. | `.env.example`, `config/services.php` |
| `GOTENBERG_URL` | configure | Optional Gotenberg URL for PDF rendering. | `config/services.php` |
| `GRAVITY_FORMS_CONSUMER_KEY` | configure | Gravity Forms REST credentials and webhook secret. | `config/services.php` |
| `GRAVITY_FORMS_CONSUMER_SECRET` | configure | Gravity Forms REST credentials and webhook secret. | `config/services.php` |
| `GRAVITY_FORMS_SITE_URL` | configure | Gravity Forms REST credentials and webhook secret. | `config/services.php` |
| `GRAVITY_FORMS_WEBHOOK_SECRET` | configure | Gravity Forms REST credentials and webhook secret. | `.env.example`, `config/services.php` |
| `GROK_API_KEY` | configure | xAI Grok API credentials and model id. | `.env.example`, `config/services.php` |
| `GROK_MODEL` | configure | xAI Grok API credentials and model id. | `config/services.php` |
| `GROQ_API_KEY` | configure | Groq API key, used when transcription or agents select Groq. | `.env.example`, `config/services.php` |
| `HARVEST_ACCOUNT_ID` | configure | Harvest time-tracking OAuth. Empty disables Harvest sync. | `.env.example`, `config/services.php` |
| `HARVEST_CLIENT_ID` | configure | Harvest time-tracking OAuth. Empty disables Harvest sync. | `.env.example`, `config/services.php` |
| `HARVEST_CLIENT_SECRET` | configure | Harvest time-tracking OAuth. Empty disables Harvest sync. | `.env.example`, `config/services.php` |
| `HARVEST_REDIRECT_URI` | configure | Harvest time-tracking OAuth. Empty disables Harvest sync. | `config/services.php` |
| `HOME` | configure | Read by config/tax.php. | `config/tax.php`, `app/Services/SpinupWp/SpinupWpSshService.php`, `app/Services/Symphony/WorkflowConfig.php`, `app/Services/X/XBookmarkEnricher.php`, +3 |
| `HORIZON_DOMAIN` | framework | Laravel Horizon settings. | `config/horizon.php` |
| `HORIZON_NAME` | framework | Laravel Horizon settings. | `config/horizon.php` |
| `HORIZON_PATH` | framework | Laravel Horizon settings. | `config/horizon.php` |
| `HORIZON_PREFIX` | framework | Laravel Horizon settings. | `config/horizon.php` |
| `IP2LOCATIONIO_TOKEN` | stub | Read by config/location.php. | `config/location.php` |
| `IPDATA_TOKEN` | stub | Read by config/location.php. | `config/location.php` |
| `IPINFO_TOKEN` | stub | Read by config/location.php. | `config/location.php` |
| `IP_API_TOKEN` | stub | Read by config/location.php. | `config/location.php` |
| `KLOUDEND_TOKEN` | stub | Read by config/location.php. | `config/location.php` |
| `LARAVEL_CLOUD` | code-only | Set by Laravel Cloud. Do not invent a local value. | `app/Services/Pdf/TailwindPdf.php`, `app/Services/Invoicing/PdfInvoiceGenerator.php`, `app/Services/Reports/RetainerReportPdfGenerator.php` |
| `LARAVEL_CLOUD_API_TOKEN` | configure | Laravel Cloud API identifiers for environment control. Optional locally. | `config/services.php` |
| `LARAVEL_CLOUD_APP_ID` | configure | Laravel Cloud API identifiers for environment control. Optional locally. | `config/services.php` |
| `LARAVEL_CLOUD_ENVIRONMENT_ID` | configure | Laravel Cloud API identifiers for environment control. Optional locally. | `config/services.php` |
| `LINEAR_API_KEY` | code-only | Optional Linear API key referenced by application code. | `app/Services/Symphony/WorkflowConfig.php` |
| `LINKEDIN_CLIENT_ID` | configure | LinkedIn OAuth. | `.env.example`, `config/services.php` |
| `LINKEDIN_CLIENT_SECRET` | configure | LinkedIn OAuth. | `.env.example`, `config/services.php` |
| `LINKEDIN_REDIRECT_URI` | configure | LinkedIn OAuth. | `.env.example`, `config/services.php` |
| `LOCATION_TESTING` | stub | Location package test flag. Household geo lookups have no public HTTP route in this snapshot. | `config/location.php` |
| `LOG_CHANNEL` | framework | Log channel and level. | `.env.example`, `config/logging.php` |
| `LOG_DAILY_DAYS` | framework | Log channel and level. | `config/logging.php` |
| `LOG_DEPRECATIONS_CHANNEL` | framework | Log channel and level. | `.env.example`, `config/logging.php` |
| `LOG_DEPRECATIONS_TRACE` | framework | Log channel and level. | `config/logging.php` |
| `LOG_LEVEL` | framework | Log channel and level. | `.env.example`, `config/logging.php` |
| `LOG_PAPERTRAIL_HANDLER` | framework | Log channel and level. | `config/logging.php` |
| `LOG_SLACK_EMOJI` | framework | Log channel and level. | `config/logging.php` |
| `LOG_SLACK_USERNAME` | framework | Log channel and level. | `config/logging.php` |
| `LOG_SLACK_WEBHOOK_URL` | framework | Log channel and level. | `config/logging.php` |
| `LOG_STACK` | framework | Log channel and level. | `.env.example`, `config/logging.php` |
| `LOG_STDERR_FORMATTER` | framework | Log channel and level. | `config/logging.php` |
| `LOG_SYSLOG_FACILITY` | framework | Log channel and level. | `config/logging.php` |
| `MAIL_EHLO_DOMAIN` | framework | Outbound mail. Defaults are local placeholders. | `config/mail.php` |
| `MAIL_ENCRYPTION` | framework | Outbound mail. Defaults are local placeholders. | `.env.example` |
| `MAIL_FROM_ADDRESS` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAIL_FROM_NAME` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAIL_HOST` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAIL_LOG_CHANNEL` | framework | Outbound mail. Defaults are local placeholders. | `config/mail.php` |
| `MAIL_MAILER` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAIL_PASSWORD` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAIL_PORT` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAIL_SCHEME` | framework | Outbound mail. Defaults are local placeholders. | `config/mail.php` |
| `MAIL_SENDMAIL_PATH` | framework | Outbound mail. Defaults are local placeholders. | `config/mail.php` |
| `MAIL_URL` | framework | Outbound mail. Defaults are local placeholders. | `config/mail.php` |
| `MAIL_USERNAME` | framework | Outbound mail. Defaults are local placeholders. | `.env.example`, `config/mail.php` |
| `MAXMIND_LICENSE_KEY` | stub | Read by config/location.php. | `config/location.php` |
| `MAXMIND_USER_ID` | stub | Read by config/location.php. | `config/location.php` |
| `MEMCACHED_HOST` | framework | Read by config/cache.php. | `.env.example`, `config/cache.php` |
| `MEMCACHED_PASSWORD` | framework | Read by config/cache.php. | `config/cache.php` |
| `MEMCACHED_PERSISTENT_ID` | framework | Read by config/cache.php. | `config/cache.php` |
| `MEMCACHED_PORT` | framework | Read by config/cache.php. | `config/cache.php` |
| `MEMCACHED_USERNAME` | framework | Read by config/cache.php. | `config/cache.php` |
| `META_ADS_ACCESS_TOKEN` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `.env.example`, `config/services.php` |
| `META_ADS_AD_ACCOUNT_ID` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `.env.example`, `config/services.php` |
| `META_ADS_API_VERSION` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `config/services.php` |
| `META_ADS_APP_ID` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `.env.example`, `config/services.php` |
| `META_ADS_APP_SECRET` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `.env.example`, `config/services.php` |
| `META_ADS_BUSINESS_ID` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `.env.example`, `config/services.php` |
| `META_ADS_SANDBOX_ACCESS_TOKEN` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `config/services.php` |
| `META_ADS_SANDBOX_ACCOUNT_ID` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `config/services.php` |
| `META_ADS_SANDBOX_APP_ID` | configure | Meta ads API credentials. Sandbox keys are separate from live account keys. | `config/services.php` |
| `MYSQL_ATTR_SSL_CA` | configure | Read by config/database.php. | `config/database.php` |
| `NIGHTWATCH_BOT_ID` | framework | Laravel Nightwatch settings. | `config/self-healing.php` |
| `NOTION_CLIENT_ID` | configure | Notion OAuth. Empty disables Notion sync. | `config/services.php` |
| `NOTION_CLIENT_SECRET` | configure | Notion OAuth. Empty disables Notion sync. | `config/services.php` |
| `NOTION_REDIRECT_URI` | configure | Notion OAuth. Empty disables Notion sync. | `config/services.php` |
| `OLLIE_PRO_PLUGIN_URL` | configure | URL of the Ollie Pro plugin zip used by the site builder. Optional. | `.env.example`, `config/services.php` |
| `OPENAI_API_KEY` | configure | OpenAI API credentials. Leave empty to skip those model calls. | `.env.example`, `config/services.php` |
| `OPENROUTER_API_KEY` | configure | OpenRouter API key for routed model calls. | `config/services.php` |
| `PAPERTRAIL_PORT` | configure | Read by config/logging.php. | `config/logging.php` |
| `PAPERTRAIL_URL` | configure | Read by config/logging.php. | `config/logging.php` |
| `PAYPAL_CLIENT_ID` | configure | PayPal invoicing. Keep PAYPAL_MODE=sandbox until go-live. | `.env.example`, `config/services.php` |
| `PAYPAL_CLIENT_SECRET` | configure | PayPal invoicing. Keep PAYPAL_MODE=sandbox until go-live. | `.env.example`, `config/services.php` |
| `PAYPAL_INVOICER_EMAIL` | configure | PayPal invoicing. Keep PAYPAL_MODE=sandbox until go-live. | `.env.example`, `config/services.php` |
| `PAYPAL_MODE` | configure | PayPal invoicing. Keep PAYPAL_MODE=sandbox until go-live. | `.env.example`, `config/services.php` |
| `PAYPAL_WEBHOOK_ID` | configure | PayPal invoicing. Keep PAYPAL_MODE=sandbox until go-live. | `config/services.php` |
| `PLAID_CLIENT_ID` | stub | Plaid keys. Listed so older notes parse. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `PLAID_ENVIRONMENT` | stub | Plaid keys. Listed so older notes parse. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `PLAID_SECRET` | stub | Plaid keys. Listed so older notes parse. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `PLAID_WEBHOOK_URL` | stub | Plaid keys. Listed so older notes parse. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `POSTMARK_API_KEY` | configure | Read by config/services.php. | `config/services.php` |
| `POSTMARK_MESSAGE_STREAM_ID` | configure | Read by config/mail.php. | `config/mail.php` |
| `PUSHER_APP_CLUSTER` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `PUSHER_APP_ID` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `PUSHER_APP_KEY` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `PUSHER_APP_SECRET` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `PUSHER_HOST` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `PUSHER_PORT` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `PUSHER_SCHEME` | configure | Read by config/broadcasting.php. | `config/broadcasting.php` |
| `QUEUE_CONNECTION` | framework | Queue driver. database is enough locally. | `.env.example`, `config/queue.php` |
| `QUEUE_FAILED_DRIVER` | framework | Read by config/queue.php. | `config/queue.php` |
| `QUICKBOOKS_CLIENT_ID` | configure | QuickBooks Online OAuth. Keep the environment on sandbox until you intend to write books. | `.env.example`, `config/services.php` |
| `QUICKBOOKS_CLIENT_SECRET` | configure | QuickBooks Online OAuth. Keep the environment on sandbox until you intend to write books. | `.env.example`, `config/services.php` |
| `QUICKBOOKS_ENVIRONMENT` | configure | QuickBooks Online OAuth. Keep the environment on sandbox until you intend to write books. | `.env.example`, `config/services.php` |
| `QUICKBOOKS_REDIRECT_URI` | configure | QuickBooks Online OAuth. Keep the environment on sandbox until you intend to write books. | `config/services.php` |
| `REDIS_BACKOFF_ALGORITHM` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_BACKOFF_BASE` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_BACKOFF_CAP` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_CACHE_CONNECTION` | framework | Redis connection for cache, queues, or Horizon. | `config/cache.php` |
| `REDIS_CACHE_DB` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_CACHE_LOCK_CONNECTION` | framework | Redis connection for cache, queues, or Horizon. | `config/cache.php` |
| `REDIS_CLIENT` | framework | Redis connection for cache, queues, or Horizon. | `.env.example`, `config/database.php` |
| `REDIS_CLUSTER` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_DB` | framework | Redis connection for cache, queues, or Horizon. | `config/reverb.php`, `config/database.php` |
| `REDIS_HOST` | framework | Redis connection for cache, queues, or Horizon. | `.env.example`, `config/reverb.php`, `config/database.php` |
| `REDIS_MAX_RETRIES` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_PASSWORD` | framework | Redis connection for cache, queues, or Horizon. | `.env.example`, `config/reverb.php`, `config/database.php` |
| `REDIS_PERSISTENT` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_PORT` | framework | Redis connection for cache, queues, or Horizon. | `.env.example`, `config/reverb.php`, `config/database.php` |
| `REDIS_PREFIX` | framework | Redis connection for cache, queues, or Horizon. | `config/database.php` |
| `REDIS_QUEUE` | framework | Redis connection for cache, queues, or Horizon. | `config/queue.php` |
| `REDIS_QUEUE_CONNECTION` | framework | Redis connection for cache, queues, or Horizon. | `config/queue.php` |
| `REDIS_QUEUE_RETRY_AFTER` | framework | Redis connection for cache, queues, or Horizon. | `config/queue.php` |
| `REDIS_TIMEOUT` | framework | Redis connection for cache, queues, or Horizon. | `config/reverb.php` |
| `REDIS_URL` | framework | Redis connection for cache, queues, or Horizon. | `config/reverb.php`, `config/database.php` |
| `REDIS_USERNAME` | framework | Redis connection for cache, queues, or Horizon. | `config/reverb.php`, `config/database.php` |
| `REPLICATE_API_TOKEN` | configure | Replicate token for image generation. | `.env.example`, `config/services.php` |
| `RESEND_API_KEY` | configure | Read by config/services.php. | `config/services.php` |
| `REVERB_APP_ACTIVITY_TIMEOUT` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_APP_ID` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `.env.example`, `config/broadcasting.php`, `config/reverb.php` |
| `REVERB_APP_KEY` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `.env.example`, `config/broadcasting.php`, `config/reverb.php` |
| `REVERB_APP_MAX_CONNECTIONS` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_APP_MAX_MESSAGE_SIZE` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_APP_PING_INTERVAL` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_APP_SECRET` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `.env.example`, `config/broadcasting.php`, `config/reverb.php` |
| `REVERB_HOST` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `.env.example`, `config/broadcasting.php`, `config/reverb.php` |
| `REVERB_MAX_REQUEST_SIZE` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_PORT` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `.env.example`, `config/broadcasting.php`, `config/reverb.php` |
| `REVERB_PULSE_INGEST_INTERVAL` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_SCALING_CHANNEL` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_SCALING_ENABLED` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_SCHEME` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `.env.example`, `config/broadcasting.php`, `config/reverb.php` |
| `REVERB_SERVER` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_SERVER_HOST` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_SERVER_PATH` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_SERVER_PORT` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `REVERB_TELESCOPE_INGEST_INTERVAL` | framework | Laravel Reverb app id, key, and host. Local placeholders in .env.example are not secrets. | `config/reverb.php` |
| `SAM_GOV_API_KEY` | configure | SAM.gov API key for RFP discovery. | `config/services.php` |
| `SELF_AUTO_TRIGGER_AGENT` | configure | When true, a feature request starts the dev agent. | `config/services.php` |
| `SELF_DEVELOPMENT_ENABLED` | configure | Allows the in-app feature-request path. | `config/services.php` |
| `SELF_FEATURE_PRIORITY` | configure | Default priority for self-development tasks. | `config/services.php` |
| `SELF_GITHUB_REPO` | configure | owner/repo string given to the dev agent. Keep the example placeholder. | `config/services.php` |
| `SELF_HEALING_AGENT_TIMEOUT` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_ALERT_CHANNEL` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_BRANCH` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_COOLDOWN_MINUTES` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_ENABLED` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_ESCALATION_MENTIONS` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_FAILURE_THRESHOLD` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_RATE_PER_DAY` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_RATE_PER_HOUR` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_RUN_TESTS` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_SLACK_CHANNEL` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_HEALING_SLACK_CHANNEL_ID` | configure | Read by config/self-healing.php. | `config/self-healing.php` |
| `SELF_PROJECT_ID` | configure | Fallback project id when the slug is missing. | `config/services.php` |
| `SELF_PROJECT_SLUG` | configure | Project slug used by self-development tasks. | `config/services.php` |
| `SERPAPI_API_KEY` | configure | SerpAPI key for search-result lookups. | `.env.example`, `config/services.php` |
| `SERPER_API_KEY` | configure | Serper web-search key for agent research. | `.env.example`, `config/services.php` |
| `SESSION_CONNECTION` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_COOKIE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_DOMAIN` | framework | Session cookie and driver settings. | `.env.example`, `config/session.php` |
| `SESSION_DRIVER` | framework | Session cookie and driver settings. | `.env.example`, `config/session.php` |
| `SESSION_ENCRYPT` | framework | Session cookie and driver settings. | `.env.example`, `config/session.php` |
| `SESSION_EXPIRE_ON_CLOSE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_HTTP_ONLY` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_LIFETIME` | framework | Session cookie and driver settings. | `.env.example`, `config/session.php` |
| `SESSION_PARTITIONED_COOKIE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_PATH` | framework | Session cookie and driver settings. | `.env.example`, `config/session.php` |
| `SESSION_SAME_SITE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_SECURE_COOKIE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_STORE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SESSION_TABLE` | framework | Session cookie and driver settings. | `config/session.php` |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `.env.example`, `config/services.php` |
| `SLACK_BOT_USER_OAUTH_TOKEN` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `.env.example`, `config/services.php` |
| `SLACK_CLIENT_ID` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `.env.example`, `config/services.php` |
| `SLACK_CLIENT_SECRET` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `.env.example`, `config/services.php` |
| `SLACK_INTERNAL_USER_IDS` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `config/services.php` |
| `SLACK_OWNER_USER_ID` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `config/services.php` |
| `SLACK_REDIRECT_URI` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `config/services.php` |
| `SLACK_SIGNING_SECRET` | configure | Slack app OAuth, signing secret, and bot token. Empty disables Slack. | `.env.example`, `config/services.php` |
| `SOW_IMPORT_AI_MAX_CHARS` | configure | Read by config/services.php. | `config/services.php` |
| `SPINUPWP_API_TOKEN` | configure | SpinupWP hosting API and SSH material for the website builder. | `.env.example`, `config/services.php` |
| `SPINUPWP_API_URL` | configure | SpinupWP hosting API and SSH material for the website builder. | `config/services.php` |
| `SPINUPWP_DEFAULT_SERVER_ID` | configure | SpinupWP hosting API and SSH material for the website builder. | `.env.example`, `config/services.php` |
| `SPINUPWP_SSH_KEY_PATH` | configure | SpinupWP hosting API and SSH material for the website builder. | `config/services.php` |
| `SPINUPWP_SSH_PRIVATE_KEY` | configure | SpinupWP hosting API and SSH material for the website builder. | `config/services.php` |
| `SPINUPWP_STAGING_DOMAIN` | configure | SpinupWP hosting API and SSH material for the website builder. | `.env.example`, `config/services.php` |
| `SQS_PREFIX` | configure | sqs.us-east-1.amazonaws.com/your-account-id'. | `config/queue.php` |
| `SQS_QUEUE` | configure | Read by config/queue.php. | `config/queue.php` |
| `SQS_SUFFIX` | configure | Read by config/queue.php. | `config/queue.php` |
| `TAX_AGENCY_BRIDGE_HEADERS_JSON` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_HEADLESS` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_HOST` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_MFA_TIMEOUT_MS` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_PORT` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_SYNC_PATH` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_TIMEOUT_SECONDS` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_TOKEN` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_URL` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TAX_AGENCY_BRIDGE_WORKER_PROFILE` | stub | Tax-agency bridge settings. Those HTTP routes and MCP tools are stubbed in this snapshot. | `config/services.php` |
| `TELLER_APPLICATION_ID` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `TELLER_CERTIFICATE_BASE64` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `config/services.php` |
| `TELLER_CERTIFICATE_PATH` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `TELLER_ENVIRONMENT` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `TELLER_PRIVATE_KEY_BASE64` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `config/services.php` |
| `TELLER_PRIVATE_KEY_PATH` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `TELLER_WEBHOOK_SECRET` | stub | Teller certificates and keys. Personal-bank HTTP and MCP are stubbed in this snapshot. | `config/services.php` |
| `TRANSCRIPTION_MODEL` | configure | Model id for the selected transcription provider. | `config/services.php` |
| `TRANSCRIPTION_PROVIDER` | configure | Which speech-to-text provider agents use. | `.env.example`, `config/services.php` |
| `UNSPLASH_ACCESS_KEY` | configure | Unsplash API credentials for stock images. | `.env.example`, `config/services.php` |
| `UNSPLASH_APP_ID` | configure | Unsplash API credentials for stock images. | `.env.example`, `config/services.php` |
| `UNSPLASH_SECRET_KEY` | configure | Unsplash API credentials for stock images. | `.env.example`, `config/services.php` |
| `VITE_APP_NAME` | framework | Values exposed to the Vite frontend. Do not put secrets here. | `.env.example` |
| `VITE_REVERB_APP_KEY` | framework | Values exposed to the Vite frontend. Do not put secrets here. | `.env.example` |
| `VITE_REVERB_HOST` | framework | Values exposed to the Vite frontend. Do not put secrets here. | `.env.example` |
| `VITE_REVERB_PORT` | framework | Values exposed to the Vite frontend. Do not put secrets here. | `.env.example` |
| `VITE_REVERB_SCHEME` | framework | Values exposed to the Vite frontend. Do not put secrets here. | `.env.example` |
| `WISE_API_URL` | stub | Wise API credentials. Household transfer tools are stubbed in this snapshot. | `config/services.php` |
| `WISE_CLIENT_ID` | stub | Wise API credentials. Household transfer tools are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `WISE_CLIENT_SECRET` | stub | Wise API credentials. Household transfer tools are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `WISE_ENVIRONMENT` | stub | Wise API credentials. Household transfer tools are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `WISE_REDIRECT_URI` | stub | Wise API credentials. Household transfer tools are stubbed in this snapshot. | `config/services.php` |
| `WISE_WEBHOOK_SECRET` | stub | Wise API credentials. Household transfer tools are stubbed in this snapshot. | `.env.example`, `config/services.php` |
| `WP_APPLICATION_PASSWORD` | configure | WordPress application user and password for Gravity Forms or site calls. | `config/services.php` |
| `WP_APPLICATION_USER` | configure | WordPress application user and password for Gravity Forms or site calls. | `config/services.php` |
| `X_CLIENT_ID` | configure | X (Twitter) OAuth. | `.env.example`, `config/services.php` |
| `X_CLIENT_SECRET` | configure | X (Twitter) OAuth. | `.env.example`, `config/services.php` |
| `X_REDIRECT_URI` | configure | X (Twitter) OAuth. | `.env.example`, `config/services.php` |
| `YELP_API_KEY` | configure | Yelp API key. | `config/services.php` |
| `ZAO_DASH_MCP_TOKEN` | client | Client-side Sanctum token for MCP. Create with php artisan mcp:token. Do not put a real token in .env.example. | `.mcp.json` |
