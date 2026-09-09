# SpinupWP Integration Technical Spec

## Overview

SpinupWP integration enables automatic WordPress staging site provisioning for the Website Builder system. When agents build websites, they can instantly spin up fresh WordPress installations with the Ollie theme pre-configured, eliminating the "no staging environment" blocker.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      SPINUPWP INTEGRATION FLOW                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. Website Builder Agent creates site pages                                │
│                           ↓                                                 │
│  2. Deploy Phase Triggered                                                  │
│      ┌─────────────────────────────────────────────────────────────────┐   │
│      │  Has connected WordPressSite?                                    │   │
│      │    YES → Deploy directly (existing flow)                        │   │
│      │    NO  → SpinupWP: Create staging site                          │   │
│      │          ↓                                                      │   │
│      │          1. Select server (default or specified)                │   │
│      │          2. Generate deploy script (Ollie + plugins)            │   │
│      │          3. Call SpinupWP API to create site                    │   │
│      │          4. Poll for provisioning completion                    │   │
│      │          5. Create linked WordPressSite record                  │   │
│      │          6. Deploy pages via WordPress REST API                 │   │
│      └─────────────────────────────────────────────────────────────────┘   │
│                           ↓                                                 │
│  3. User reviews staging → Approve for production                           │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Configuration

### Environment Variables

Add to `.env`:

```bash
# SpinupWP API (Required)
SPINUPWP_API_TOKEN=your_api_token_here

# Optional Configuration
SPINUPWP_API_URL=https://api.spinupwp.app/v1
SPINUPWP_DEFAULT_SERVER_ID=12345
SPINUPWP_STAGING_DOMAIN=staging.example.com

# Ollie Pro Plugin (Optional - for premium features)
OLLIE_PRO_PLUGIN_URL=https://your-private-url.com/ollie-pro.zip
```

### Config Reference (`config/services.php`)

```php
'spinupwp' => [
    'api_token' => env('SPINUPWP_API_TOKEN'),
    'api_url' => env('SPINUPWP_API_URL', 'https://api.spinupwp.app/v1'),
    'default_server_id' => env('SPINUPWP_DEFAULT_SERVER_ID'),
    'staging_domain' => env('SPINUPWP_STAGING_DOMAIN', 'staging.example.com'),
    'ollie_theme_url' => 'https://downloads.wordpress.org/theme/ollie.zip',
    'ollie_pro_url' => env('OLLIE_PRO_PLUGIN_URL', ''),
    'site_defaults' => [
        'php_version' => '8.3',
        'https_enabled' => true,
        'page_cache_enabled' => true,
    ],
],
```

### Getting Your API Token

1. Log into [SpinupWP Dashboard](https://spinupwp.app)
2. Go to **Account Settings** → **API Tokens**
3. Create a new token with full access
4. Copy the token to your `.env` file

---

## Data Model

### SpinupWpServer

Represents a server managed by SpinupWP where sites can be provisioned.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `spinup_id` | bigint | SpinupWP server ID (unique) |
| `name` | string | Server name |
| `provider_name` | string | Cloud provider (DigitalOcean, Vultr, etc.) |
| `ubuntu_version` | string | Ubuntu version |
| `ip_address` | string | Server IP |
| `ssh_port` | int | SSH port (default: 22) |
| `timezone` | string | Server timezone |
| `region` | string | Cloud region |
| `size` | string | Server size/tier |
| `disk_space` | json | `{total, used, available}` in bytes |
| `database_config` | json | Database server configuration |
| `ssh_publickey` | text | SSH public key for deployments |
| `git_publickey` | text | Git public key for repo access |
| `connection_status` | string | `connected`, `disconnected`, `unknown` |
| `reboot_required` | boolean | Server needs reboot |
| `upgrade_required` | boolean | Server needs upgrade |
| `install_notes` | text | Installation notes |
| `status` | string | `provisioning`, `provisioned`, `failed` |
| `is_default` | boolean | Use as default for new sites |
| `last_synced_at` | timestamp | Last API sync |

**Relationships:**
- `hasMany` → `SpinupWpSite`

**Key Methods:**
```php
$server->isProvisioned();        // Status check
$server->isConnected();          // Connection check
$server->canHostSites();         // Both provisioned AND connected
$server->getDiskUsagePercent();  // Returns 0-100
$server->getDiskAvailableGb();   // Remaining space in GB
$server->setAsDefault();         // Make this the default server
```

### SpinupWpSite

Represents a WordPress site provisioned via SpinupWP.

| Field | Type | Description |
|-------|------|-------------|
| `id` | bigint | Primary key |
| `spinup_id` | bigint | SpinupWP site ID (unique) |
| `spinup_server_id` | bigint | FK to SpinupWpServer |
| `wordpress_site_id` | bigint | FK to WordPressSite (nullable) |
| `website_project_id` | bigint | FK to WebsiteProject (nullable) |
| `domain` | string | Primary domain |
| `additional_domains` | json | Array of additional domains |
| `site_user` | string | Linux user for the site |
| `php_version` | string | PHP version (default: 8.3) |
| `public_folder` | string | Public directory |
| `is_wordpress` | boolean | WordPress installation |
| `page_cache_enabled` | boolean | Page caching enabled |
| `https_enabled` | boolean | HTTPS/SSL enabled |
| `nginx_config` | json | Nginx configuration |
| `database_config` | json | Database configuration |
| `backup_config` | json | Backup settings |
| `git_config` | json | Git deployment config |
| `basic_auth_enabled` | boolean | Basic auth protection |
| `basic_auth_username` | string | Basic auth user |
| `status` | string | `pending`, `provisioning`, `deployed`, `failed`, `deleting` |
| `provision_event_id` | bigint | SpinupWP event ID for tracking |
| `provisioned_at` | timestamp | When provisioning completed |
| `wp_admin_user` | string | WordPress admin username |
| `wp_admin_email` | string | WordPress admin email |
| `wp_admin_password_encrypted` | text | Encrypted WP admin password |
| `database_password_encrypted` | text | Encrypted database password |
| `last_synced_at` | timestamp | Last API sync |
| `deleted_at` | timestamp | Soft delete |

**Relationships:**
```php
$site->server();         // BelongsTo SpinupWpServer
$site->wordpressSite();  // BelongsTo WordPressSite
$site->websiteProject(); // BelongsTo WebsiteProject
```

**Key Properties:**
```php
$site->url;              // https://domain.com
$site->admin_url;        // https://domain.com/wp-admin
$site->wp_admin_password; // Decrypted password (accessor)
$site->database_password; // Decrypted password (accessor)
$site->git_deployment_url; // Git webhook URL if configured
```

**Key Methods:**
```php
$site->isProvisioned();          // Status is 'deployed'
$site->isProvisioning();         // Status is 'provisioning'
$site->hasFailed();              // Status is 'failed'
$site->hasGitEnabled();          // Git deployment configured
$site->createLinkedWordPressSite(); // Create WordPressSite record
```

**Scopes:**
```php
SpinupWpSite::provisioned()->get();      // Only deployed sites
SpinupWpSite::forServer($serverId)->get(); // Sites on a server
SpinupWpSite::forProject($projectId)->get(); // Sites for a project
```

---

## API Reference

### SpinupWpService

Core service class for SpinupWP API interactions.

#### Configuration Check

```php
$service = app(SpinupWpService::class);

if ($service->isConfigured()) {
    // API token is set
}
```

#### Server Management

```php
// List all servers from SpinupWP API
$servers = $service->listServers();

// Get single server details
$serverData = $service->getServer($spinupId);

// Sync servers from API to local database
$count = $service->syncServers();
// Returns: number of servers synced

// Get default server for new sites
$server = $service->getDefaultServer();
// Returns: SpinupWpServer or null
```

#### Site Management

```php
// List sites (optionally filter by server)
$sites = $service->listSites();
$sites = $service->listSites($serverId);

// Get single site details
$siteData = $service->getSite($spinupId);

// Create a new site
$result = $service->createSite($server, [
    'domain' => 'example.staging.example.com',
    'site_user' => 'exampleuser',      // Optional, auto-generated
    'php_version' => '8.3',            // Optional
    'page_cache_enabled' => true,      // Optional
    'https_enabled' => true,           // Optional
    'database' => [                    // Optional
        'name' => 'example_db',
        'username' => 'example_db',
        'password' => 'secure_password',
    ],
    'wordpress' => [                   // Optional
        'title' => 'Example Site',
        'admin_user' => 'admin',
        'admin_email' => 'admin@example.com',
        'admin_password' => 'secure_password',
    ],
    'git' => [                         // Optional
        'repo' => 'git@github.com:org/repo.git',
        'branch' => 'main',
        'push_to_deploy' => true,
    ],
    'deploy_script' => '#!/bin/bash\n...',  // Optional
]);
// Returns: ['event_id' => 123, 'site' => [...]]

// Delete a site
$eventId = $service->deleteSite($spinupId, $deleteDatabase = true);
```

#### Git Deployment

```php
// Trigger git deployment
$eventId = $service->triggerGitDeploy($spinupId);
```

#### Cache Management

```php
// Purge page cache
$eventId = $service->purgePageCache($spinupId);

// Purge object cache
$eventId = $service->purgeObjectCache($spinupId);
```

#### Event Tracking

```php
// Get event status
$event = $service->getEvent($eventId);
// Returns: ['status' => 'deployed|completed|failed', ...]

// Wait for event completion (blocking)
$event = $service->waitForEvent($eventId, $maxWaitSeconds = 600);
```

#### High-Level Provisioning

```php
// Provision a complete site for a Website Project
$spinupSite = $service->provisionSiteForProject(
    serverId: $server->spinup_id,
    domain: 'client-site.staging.example.com',
    siteTitle: 'Client Website',
    adminEmail: 'admin@client.com',
    gitRepo: 'git@github.com:org/repo.git',  // Optional
    deployScript: $script,                    // Optional
    websiteProjectId: $project->id            // Optional
);
// Returns: SpinupWpSite model

// Check provisioning status
$site = $service->checkProvisioningStatus($spinupSite);
```

---

## REST API Endpoints

All endpoints require authentication.

### Integration Status

```http
GET /api/integrations/spinupwp/status
```

**Response:**
```json
{
    "configured": true,
    "connected": true,
    "servers": [
        {
            "id": 1,
            "spinup_id": 12345,
            "name": "Production Server",
            "ip_address": "192.168.1.1",
            "provider_name": "DigitalOcean",
            "status": "provisioned",
            "connection_status": "connected",
            "is_default": true,
            "sites_count": 5,
            "disk_usage_percent": 45,
            "disk_available_gb": 23.5,
            "last_synced_at": "2 hours ago"
        }
    ]
}
```

### Sync Servers

```http
POST /api/integrations/spinupwp/sync-servers
```

**Response:**
```json
{
    "message": "Synced 3 server(s)",
    "synced": 3
}
```

### Set Default Server

```http
POST /api/integrations/spinupwp/default-server
Content-Type: application/json

{
    "server_id": 1
}
```

### List Sites

```http
GET /api/integrations/spinupwp/sites
GET /api/integrations/spinupwp/sites?server_id=1
```

**Response:**
```json
{
    "sites": [
        {
            "id": 1,
            "spinup_id": 67890,
            "domain": "client.staging.example.com",
            "status": "deployed",
            "php_version": "8.3",
            "https_enabled": true,
            "page_cache_enabled": true,
            "server": {
                "id": 1,
                "name": "Production Server"
            },
            "wordpress_site": {
                "id": 5,
                "name": "client.staging.example.com"
            },
            "website_project": {
                "id": 10,
                "name": "Client Website Redesign"
            },
            "provisioned_at": "3 days ago",
            "last_synced_at": "1 hour ago"
        }
    ]
}
```

### Server Operations

```http
# Refresh server data from SpinupWP
POST /api/integrations/spinupwp/servers/{id}/refresh

# Remove server from tracking (doesn't delete from SpinupWP)
DELETE /api/integrations/spinupwp/servers/{id}
```

### Site Operations

```http
# Refresh site data from SpinupWP
POST /api/integrations/spinupwp/sites/{id}/refresh

# Delete site (optionally from SpinupWP too)
DELETE /api/integrations/spinupwp/sites/{id}
Content-Type: application/json

{
    "delete_from_spinup": true,
    "delete_database": true
}

# Purge site cache
POST /api/integrations/spinupwp/sites/{id}/purge-cache

# Trigger git deployment
POST /api/integrations/spinupwp/sites/{id}/deploy
```

---

## Agent Tool: SpinupWpProvisionSiteTool

AI agents can provision sites using this tool.

### Tool Definition

| Property | Value |
|----------|-------|
| Category | `website-builder` |
| Name | `Provision SpinupWP Site` |
| Requires Approval | Yes |
| Risk Level | Medium |

### Input Schema

```json
{
    "type": "object",
    "properties": {
        "project_id": {
            "type": "integer",
            "description": "The Website Project ID to provision a site for"
        },
        "domain": {
            "type": "string",
            "description": "Domain name (auto-generated if not provided)"
        },
        "server_id": {
            "type": "integer",
            "description": "SpinupWP server ID (uses default if not specified)"
        },
        "site_title": {
            "type": "string",
            "description": "WordPress site title (defaults to project name)"
        },
        "admin_email": {
            "type": "string",
            "description": "Admin email for WordPress (required)"
        },
        "install_ollie_pro": {
            "type": "boolean",
            "description": "Install Ollie Pro plugin",
            "default": false
        },
        "git_repo": {
            "type": "string",
            "description": "Git repository URL for deployment"
        },
        "wait_for_completion": {
            "type": "boolean",
            "description": "Wait for provisioning to complete",
            "default": true
        }
    },
    "required": ["project_id", "admin_email"]
}
```

### Success Response

```json
{
    "success": true,
    "status": "deployed",
    "message": "WordPress site provisioned and ready for content deployment",
    "spinup_site_id": 1,
    "wordpress_site_id": 5,
    "domain": "client-abc123.staging.example.com",
    "site_url": "https://client-abc123.staging.example.com",
    "admin_url": "https://client-abc123.staging.example.com/wp-admin",
    "credentials": {
        "admin_user": "admin",
        "admin_email": "admin@example.com"
    },
    "git_enabled": false,
    "git_deploy_url": null
}
```

### Error Responses

```json
// SpinupWP not configured
{
    "success": false,
    "error": "SpinupWP is not configured. Add SPINUPWP_API_TOKEN to your environment."
}

// No server available
{
    "success": false,
    "error": "No SpinupWP server available. Please sync servers first or specify a server_id."
}

// Provisioning failed
{
    "success": false,
    "error": "Failed to provision site: <API error message>"
}

// Provisioning timeout
{
    "success": false,
    "error": "Provisioning timed out after 10 minutes. Site may still be deploying.",
    "spinup_site_id": 1,
    "spinup_event_id": 12345
}
```

---

## Deploy Script Generator

The `OllieDeployScriptGenerator` creates bash scripts that run after site provisioning.

### What It Does

1. **Installs Ollie Theme** from WordPress.org
2. **Installs Ollie Pro Plugin** (optional, requires URL)
3. **Installs Core Plugins**: Yoast SEO, LiteSpeed Cache
4. **Removes Default Plugins**: Hello Dolly, Akismet
5. **Configures WordPress**:
   - Sets site title
   - Configures permalinks (`/%postname%/`)
   - Sets timezone to America/Chicago
   - Disables comments
   - Creates placeholder home page
6. **Creates Application Password** for REST API access
7. **Optimizes**: Flushes caches and rewrite rules

### Usage

```php
// Simple usage via static method
$script = OllieDeployScriptGenerator::forWebsiteProject([
    'site_title' => 'My New Site',
    'admin_email' => 'admin@example.com',
    'admin_user' => 'admin',
    'install_ollie_pro' => false,
]);

// Advanced usage with builder pattern
$generator = new OllieDeployScriptGenerator();
$script = $generator
    ->withOllieProUrl('https://example.com/ollie-pro.zip')
    ->withAdditionalPlugins([
        'woocommerce',
        ['slug' => 'contact-form-7', 'activate' => true],
    ])
    ->generate([
        'site_title' => 'E-commerce Site',
        'admin_email' => 'shop@example.com',
        'create_application_password' => true,
        'application_password_name' => 'Zao Dash API',
    ]);

// Minimal script (no Ollie Pro, no application password)
$script = $generator->generateMinimal();
```

### Generated Script Structure

```bash
#!/bin/bash
set -e

echo "=== Zao Website Builder Deploy Script ==="

# 1. Wait for WordPress installation
# 2. Install Ollie theme
# 3. Install Ollie Pro (if configured)
# 4. Install core plugins (Yoast, LiteSpeed)
# 5. Remove default plugins
# 6. Configure site settings
# 7. Create application password
# 8. Cleanup and optimize

echo "=== Deployment Complete ==="
```

---

## Integration with Website Builder

### Workflow Integration

The SpinupWP integration plugs into the Website Builder at the deployment phase:

```
WebsiteProject created
    ↓
Agents analyze brief/design/build pages
    ↓
Ready to deploy
    ↓
┌─────────────────────────────────────┐
│ Check for existing WordPress site    │
│                                     │
│ IF site exists → Deploy directly    │
│ ELSE → Provision via SpinupWP       │
│        ↓                            │
│        SpinupWpProvisionSiteTool    │
│        ↓                            │
│        Site ready with Ollie        │
│        ↓                            │
│        Deploy pages                 │
└─────────────────────────────────────┘
    ↓
User reviews staging site
    ↓
Approve for production
```

### WebsiteProject Connection

When a site is provisioned:

1. `SpinupWpSite` record created with `website_project_id`
2. `WordPressSite` record created automatically
3. `WebsiteProject` updated with:
   - `wordpress_site_id`
   - `staging_url`
   - `client_credentials` (admin URL, user, email)

```php
// Access from WebsiteProject
$project->wordpress_site;           // WordPressSite model
$project->staging_url;              // https://client.staging.example.com
$project->client_credentials['wp_admin_url'];

// Access SpinupWP site
$spinupSite = SpinupWpSite::forProject($project->id)->first();
$spinupSite->url;
$spinupSite->admin_url;
$spinupSite->wp_admin_password;  // Decrypted
```

---

## Security Considerations

### Credential Storage

- **WordPress admin password**: Encrypted using Laravel's `Crypt::encryptString()`
- **Database password**: Encrypted using Laravel's `Crypt::encryptString()`
- **SpinupWP API token**: Stored in `.env` only, never in database

### Access Control

- All API endpoints require authentication
- Site provisioning tool requires approval (`requiresApproval: true`)
- Passwords are never exposed in API responses (hidden attributes)

### Best Practices

1. **Use dedicated API tokens** per environment (dev/staging/prod)
2. **Rotate credentials** after site handoff to clients
3. **Enable basic auth** for staging sites to prevent public access
4. **Delete staging sites** after project completion

---

## Troubleshooting

### Common Issues

| Issue | Solution |
|-------|----------|
| "SpinupWP API token not configured" | Add `SPINUPWP_API_TOKEN` to `.env` |
| "No SpinupWP server available" | Run server sync: `POST /api/integrations/spinupwp/sync-servers` |
| Provisioning stuck | Check SpinupWP dashboard for event status |
| Site shows 404 | Wait for DNS propagation (use staging domain) |
| Deploy script failed | Check server logs in SpinupWP dashboard |

### Debugging

```php
// Check service configuration
$service = app(SpinupWpService::class);
dump($service->isConfigured());

// List servers from API
$servers = $service->listServers();
dump($servers);

// Check event status
$event = $service->getEvent($eventId);
dump($event);
```

### CLI Commands

```bash
# Sync servers (via tinker)
php artisan tinker
>>> app(\App\Services\SpinupWp\SpinupWpService::class)->syncServers();

# Check default server
>>> app(\App\Services\SpinupWp\SpinupWpService::class)->getDefaultServer();
```

---

## See Also

- [Ollie Site Builder](../OLLIE_SITE_BUILDER.md) - Website Builder documentation
- [WordPress MCP Integration](./WORDPRESS_MCP_INTEGRATION.md) - WordPress API integration
- [Agent System](../AGENTS.md) - Agent tool documentation
- [SpinupWP API Docs](https://api.spinupwp.com/) - Official API reference
