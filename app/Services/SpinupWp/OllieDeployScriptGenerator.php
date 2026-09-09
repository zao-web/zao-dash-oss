<?php

namespace App\Services\SpinupWp;

class OllieDeployScriptGenerator
{
    protected string $ollieThemeZipUrl;

    protected string $ollieProPluginZipUrl;

    protected array $additionalPlugins = [];

    public function __construct()
    {
        $this->ollieThemeZipUrl = config(
            'services.spinupwp.ollie_theme_url',
            'https://downloads.wordpress.org/theme/ollie.zip'
        );

        $this->ollieProPluginZipUrl = config(
            'services.spinupwp.ollie_pro_url',
            ''
        );
    }

    public function withOllieProUrl(string $url): self
    {
        $this->ollieProPluginZipUrl = $url;

        return $this;
    }

    public function withAdditionalPlugins(array $plugins): self
    {
        $this->additionalPlugins = $plugins;

        return $this;
    }

    public function generate(array $options = []): string
    {
        $wpPath = $options['wp_path'] ?? '~/files';
        $adminUser = $options['admin_user'] ?? 'admin';
        $adminEmail = $options['admin_email'] ?? '';
        $siteTitle = $options['site_title'] ?? 'New Site';
        $installOlliePro = $options['install_ollie_pro'] ?? ! empty($this->ollieProPluginZipUrl);
        $createApplicationPassword = $options['create_application_password'] ?? true;
        $applicationPasswordName = $options['application_password_name'] ?? 'Zao Dash API';

        $script = $this->buildScriptHeader();
        $script .= $this->buildWpCliCheck($wpPath);
        $script .= $this->buildOllieThemeInstall($wpPath);

        if ($installOlliePro && $this->ollieProPluginZipUrl) {
            $script .= $this->buildOllieProInstall($wpPath);
        }

        $script .= $this->buildCorePluginsInstall($wpPath);

        if (! empty($this->additionalPlugins)) {
            $script .= $this->buildAdditionalPluginsInstall($wpPath);
        }

        $script .= $this->buildSiteConfiguration($wpPath, $siteTitle);

        if ($createApplicationPassword && $adminUser) {
            $script .= $this->buildApplicationPassword($wpPath, $adminUser, $applicationPasswordName);
        }

        $script .= $this->buildCleanup($wpPath);
        $script .= $this->buildScriptFooter();

        return $script;
    }

    public function generateMinimal(): string
    {
        return $this->generate([
            'install_ollie_pro' => false,
            'create_application_password' => false,
        ]);
    }

    protected function buildScriptHeader(): string
    {
        return <<<'BASH'
#!/bin/bash
set -e

echo "=== Zao Website Builder Deploy Script ==="
echo "Starting deployment at $(date)"

BASH;
    }

    protected function buildWpCliCheck(string $wpPath): string
    {
        return <<<BASH

# Ensure we're in the WordPress directory
cd {$wpPath}

# Verify WP-CLI is available
if ! command -v wp &> /dev/null; then
    echo "ERROR: WP-CLI not found"
    exit 1
fi

# Wait for WordPress to be ready
MAX_ATTEMPTS=30
ATTEMPT=0
while ! wp core is-installed --quiet 2>/dev/null; do
    ATTEMPT=\$((ATTEMPT + 1))
    if [ \$ATTEMPT -ge \$MAX_ATTEMPTS ]; then
        echo "ERROR: WordPress installation not detected after \$MAX_ATTEMPTS attempts"
        exit 1
    fi
    echo "Waiting for WordPress installation... (attempt \$ATTEMPT/\$MAX_ATTEMPTS)"
    sleep 5
done

echo "WordPress installation detected"

BASH;
    }

    protected function buildOllieThemeInstall(string $wpPath): string
    {
        return <<<BASH

echo "=== Installing Ollie Theme ==="
cd {$wpPath}

# Install Ollie theme from WordPress.org
wp theme install ollie --activate || {
    echo "Failed to install Ollie from WordPress.org, trying direct URL..."
    wp theme install {$this->ollieThemeZipUrl} --activate
}

# Verify theme is active
ACTIVE_THEME=\$(wp theme list --status=active --field=name)
if [ "\$ACTIVE_THEME" != "ollie" ]; then
    echo "WARNING: Ollie theme may not be active. Active theme: \$ACTIVE_THEME"
fi

echo "Ollie theme installed and activated"

BASH;
    }

    protected function buildOllieProInstall(string $wpPath): string
    {
        if (empty($this->ollieProPluginZipUrl)) {
            return '';
        }

        return <<<BASH

echo "=== Installing Ollie Pro Plugin ==="
cd {$wpPath}

# Install Ollie Pro plugin
wp plugin install "{$this->ollieProPluginZipUrl}" --activate || {
    echo "WARNING: Failed to install Ollie Pro plugin"
}

echo "Ollie Pro plugin installation attempted"

BASH;
    }

    protected function buildCorePluginsInstall(string $wpPath): string
    {
        return <<<BASH

echo "=== Installing Core Plugins ==="
cd {$wpPath}

# Application passwords plugin for REST API access (if not using WP 5.6+)
# WordPress 5.6+ has built-in application passwords

# Install essential SEO plugin
wp plugin install wordpress-seo --activate || echo "WARNING: Failed to install Yoast SEO"

# Install performance plugin
wp plugin install litespeed-cache || echo "WARNING: Failed to install LiteSpeed Cache"

# Remove default plugins we don't need
wp plugin delete hello || true
wp plugin delete akismet || true

echo "Core plugins configured"

BASH;
    }

    protected function buildAdditionalPluginsInstall(string $wpPath): string
    {
        if (empty($this->additionalPlugins)) {
            return '';
        }

        $pluginCommands = '';
        foreach ($this->additionalPlugins as $plugin) {
            $slug = is_array($plugin) ? $plugin['slug'] : $plugin;
            $activate = is_array($plugin) && ($plugin['activate'] ?? true) ? '--activate' : '';
            $pluginCommands .= "wp plugin install {$slug} {$activate} || echo \"WARNING: Failed to install {$slug}\"\n";
        }

        return <<<BASH

echo "=== Installing Additional Plugins ==="
cd {$wpPath}

{$pluginCommands}
echo "Additional plugins installed"

BASH;
    }

    protected function buildSiteConfiguration(string $wpPath, string $siteTitle): string
    {
        $escapedTitle = addslashes($siteTitle);

        return <<<BASH

echo "=== Configuring Site Settings ==="
cd {$wpPath}

# Set site title
wp option update blogname "{$escapedTitle}"

# Configure permalinks for SEO
wp rewrite structure '/%postname%/' --hard

# Set timezone
wp option update timezone_string 'America/Chicago'

# Disable comments by default
wp option update default_comment_status 'closed'
wp option update default_ping_status 'closed'

# Set reading settings for static front page (will be configured later)
wp option update show_on_front 'page'

# Delete sample content
wp post delete 1 --force 2>/dev/null || true
wp post delete 2 --force 2>/dev/null || true

# Create a placeholder home page
HOME_PAGE=\$(wp post create --post_type=page --post_title="Home" --post_status=publish --porcelain)
wp option update page_on_front \$HOME_PAGE

echo "Site configuration complete"

BASH;
    }

    protected function buildApplicationPassword(string $wpPath, string $adminUser, string $appName): string
    {
        $escapedAppName = addslashes($appName);

        return <<<BASH

echo "=== Creating Application Password ==="
cd {$wpPath}

# Generate application password for API access
APP_PASSWORD=\$(wp user application-password create {$adminUser} "{$escapedAppName}" --porcelain 2>/dev/null || echo "")

if [ -n "\$APP_PASSWORD" ]; then
    echo "Application password created successfully"
    echo "APP_PASSWORD_CREATED=true"
    echo "APP_PASSWORD=\$APP_PASSWORD"
else
    echo "WARNING: Could not create application password"
    echo "APP_PASSWORD_CREATED=false"
fi

BASH;
    }

    protected function buildCleanup(string $wpPath): string
    {
        return <<<BASH

echo "=== Cleanup & Optimization ==="
cd {$wpPath}

# Flush rewrite rules
wp rewrite flush --hard

# Clear any caches
wp cache flush 2>/dev/null || true

# Optimize database
wp db optimize 2>/dev/null || true

echo "Cleanup complete"

BASH;
    }

    protected function buildScriptFooter(): string
    {
        return <<<'BASH'

echo "=== Deployment Complete ==="
echo "Finished at $(date)"
echo "Site is ready for content deployment"

BASH;
    }

    public static function forWebsiteProject(array $projectConfig): string
    {
        $generator = new self;

        if (! empty($projectConfig['ollie_pro_url'])) {
            $generator->withOllieProUrl($projectConfig['ollie_pro_url']);
        }

        if (! empty($projectConfig['additional_plugins'])) {
            $generator->withAdditionalPlugins($projectConfig['additional_plugins']);
        }

        return $generator->generate([
            'site_title' => $projectConfig['site_title'] ?? $projectConfig['name'] ?? 'New Site',
            'admin_email' => $projectConfig['admin_email'] ?? 'admin@example.com',
            'admin_user' => $projectConfig['admin_user'] ?? 'admin',
            'install_ollie_pro' => $projectConfig['install_ollie_pro'] ?? false,
            'create_application_password' => $projectConfig['create_application_password'] ?? true,
        ]);
    }
}
