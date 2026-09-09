<?php

namespace App\Services\SpinupWp;

use App\Models\SpinupWpSite;
use Illuminate\Support\Facades\Process;

class SpinupWpSshService
{
    protected ?string $sshKeyPath;

    protected int $timeout;

    protected ?string $runtimeKeyPath = null;

    public function __construct()
    {
        $this->sshKeyPath = config('services.spinupwp.ssh_key_path');
        $this->timeout = config('services.spinupwp.ssh_timeout', 120);
        $this->ensureSshKey();
    }

    /**
     * Ensure SSH key is available, writing from env var if needed.
     */
    protected function ensureSshKey(): void
    {
        // If we have a configured path and it exists, use it
        if ($this->sshKeyPath && file_exists($this->resolvePath($this->sshKeyPath))) {
            return;
        }

        // Check if we have a private key in env var
        $privateKey = config('services.spinupwp.ssh_private_key');
        if (! $privateKey) {
            return;
        }

        // Write the key to a runtime temp file
        $this->runtimeKeyPath = sys_get_temp_dir().'/spinupwp_ssh_key_'.md5($privateKey);

        if (! file_exists($this->runtimeKeyPath)) {
            // Decode if base64 encoded, otherwise use as-is
            $keyContent = $this->isBase64($privateKey)
                ? base64_decode($privateKey)
                : $privateKey;

            file_put_contents($this->runtimeKeyPath, $keyContent);
            chmod($this->runtimeKeyPath, 0600);
        }
    }

    protected function isBase64(string $string): bool
    {
        return base64_encode(base64_decode($string, true)) === $string;
    }

    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, '~')) {
            return str_replace('~', getenv('HOME') ?: '/root', $path);
        }

        return $path;
    }

    public function runWpCli(SpinupWpSite $site, string $command, array $args = []): array
    {
        $server = $site->server;

        if (! $server) {
            throw new \RuntimeException('Site has no associated server');
        }

        $wpCliCommand = $this->buildWpCliCommand($command, $args);
        $sshCommand = $this->buildSshCommand($server->ip_address, $site->site_user, $server->ssh_port ?? 22, $wpCliCommand);

        $result = Process::timeout($this->timeout)->run($sshCommand);

        return [
            'success' => $result->successful(),
            'output' => trim($result->output()),
            'error' => trim($result->errorOutput()),
            'exit_code' => $result->exitCode(),
        ];
    }

    public function runCommand(SpinupWpSite $site, string $command): array
    {
        $server = $site->server;

        if (! $server) {
            throw new \RuntimeException('Site has no associated server');
        }

        $sshCommand = $this->buildSshCommand($server->ip_address, $site->site_user, $server->ssh_port ?? 22, $command);

        $result = Process::timeout($this->timeout)->run($sshCommand);

        return [
            'success' => $result->successful(),
            'output' => trim($result->output()),
            'error' => trim($result->errorOutput()),
            'exit_code' => $result->exitCode(),
        ];
    }

    protected function buildWpCliCommand(string $command, array $args): string
    {
        $escapedArgs = array_map(fn ($arg) => escapeshellarg($arg), $args);
        $argsString = implode(' ', $escapedArgs);

        // SpinupWP uses ~/files for WordPress, not ~/public
        return "cd ~/files && wp {$command} {$argsString}";
    }

    protected function buildSshCommand(string $host, string $user, int $port, string $remoteCommand): string
    {
        $sshOptions = [
            '-o StrictHostKeyChecking=accept-new',
            '-o ConnectTimeout=30',
            '-o BatchMode=yes',
            "-p {$port}",
        ];

        $sshKeyPath = $this->resolveSshKeyPath();
        if ($sshKeyPath && file_exists($sshKeyPath)) {
            $sshOptions[] = "-i {$sshKeyPath}";
        }

        $optionsString = implode(' ', $sshOptions);

        // Use double quotes to allow ~ expansion on remote shell
        // Escape double quotes and backticks in the command
        $escaped = str_replace(['"', '`', '\\'], ['\\"', '\\`', '\\\\'], $remoteCommand);

        return "ssh {$optionsString} {$user}@{$host} \"{$escaped}\"";
    }

    protected function resolveSshKeyPath(): ?string
    {
        // Use runtime key if we wrote one from env var
        if ($this->runtimeKeyPath && file_exists($this->runtimeKeyPath)) {
            return $this->runtimeKeyPath;
        }

        // Fall back to configured path
        if ($this->sshKeyPath) {
            return $this->resolvePath($this->sshKeyPath);
        }

        return null;
    }

    public function updateTemplatePart(SpinupWpSite $site, string $slug, string $content, string $theme = 'ollie'): array
    {
        $tempFile = '/tmp/template_part_'.uniqid().'.html';

        $uploadResult = $this->uploadContent($site, $content, $tempFile);
        if (! $uploadResult['success']) {
            return $uploadResult;
        }

        // Strategy 1: Find by exact slug + theme match using direct WP-CLI
        $postId = $this->findTemplatePartByWpCli($site, $slug, $theme);

        // Strategy 2: Find by exact slug + theme match using SQL (broader pattern matching)
        if (! $postId) {
            $postId = $this->findTemplatePartByTheme($site, $slug, $theme);
        }

        // Strategy 3: If not found, try finding ANY template part with this slug
        if (! $postId) {
            $anyMatch = $this->findTemplatePartBySlug($site, $slug);
            if ($anyMatch) {
                $postId = $anyMatch['id'];
                // Update the theme taxonomy to match what we want
                $this->runCommand($site, "cd ~/files && wp post term set {$postId} wp_theme {$theme}");
            }
        }

        if ($postId) {
            $result = $this->runCommand($site, "cd ~/files && wp post update {$postId} {$tempFile} --post_status=publish");
            if ($result['success']) {
                $result['output'] = "Updated template part {$slug} (ID: {$postId}) with theme {$theme}";
            }
        } else {
            $result = $this->createTemplatePartWithTheme($site, $slug, $tempFile, $theme);
        }

        $this->runCommand($site, "rm -f {$tempFile}");

        return $result;
    }

    /**
     * Find a template part using WP-CLI's post list command.
     * This is more reliable for finding existing posts by slug.
     */
    protected function findTemplatePartByWpCli(SpinupWpSite $site, string $slug, string $theme): ?int
    {
        // First get all template parts and filter manually
        // WP-CLI --post_name filter doesn't always work reliably
        $result = $this->runCommand(
            $site,
            'cd ~/files && wp post list --post_type=wp_template_part --format=json --fields=ID,post_name,post_title'
        );

        if (! $result['success'] || empty(trim($result['output']))) {
            return null;
        }

        $posts = json_decode($result['output'], true);
        if (! is_array($posts)) {
            return null;
        }

        // Find posts matching our slug (exact match or partial)
        $matchingIds = [];
        foreach ($posts as $post) {
            $postName = $post['post_name'] ?? '';
            $postTitle = strtolower($post['post_title'] ?? '');

            // Match if post_name equals slug, or contains the slug in various patterns
            if (
                $postName === $slug ||
                str_contains($postName, "//{$slug}") ||
                str_contains($postName, "{$slug}//") ||
                $postTitle === strtolower($slug)
            ) {
                $matchingIds[] = (int) $post['ID'];
            }
        }

        if (empty($matchingIds)) {
            return null;
        }

        // Check if any of these IDs has the correct theme
        foreach ($matchingIds as $id) {
            $termResult = $this->runCommand(
                $site,
                "cd ~/files && wp post term list {$id} wp_theme --format=csv --fields=slug 2>/dev/null | tail -n +2"
            );
            $postTheme = trim($termResult['output']);

            if ($postTheme === $theme) {
                return $id;
            }
        }

        // If no theme match, return first matching ID anyway (we'll update its theme)
        return $matchingIds[0];
    }

    /**
     * Find ANY template part with a given slug, regardless of theme.
     * Returns the most recently modified one if multiple exist.
     *
     * WordPress FSE template parts can have post_name in various formats:
     * - Simple: "header"
     * - Composite: "header//ollie" or "ollie//header"
     *
     * @return array{id: int, theme: ?string}|null
     */
    public function findTemplatePartBySlug(SpinupWpSite $site, string $slug): ?array
    {
        // Find all template parts matching the slug (exact or as part of composite name)
        // Include ANY status since customized parts may have various states
        $query = "SELECT p.ID, p.post_name, t.slug as theme_slug FROM wp_posts p 
            LEFT JOIN wp_term_relationships tr ON p.ID = tr.object_id 
            LEFT JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'wp_theme'
            LEFT JOIN wp_terms t ON tt.term_id = t.term_id 
            WHERE p.post_type = 'wp_template_part' 
            AND (p.post_name = '{$slug}' OR p.post_name LIKE '%//{$slug}' OR p.post_name LIKE '{$slug}//%')
            ORDER BY p.post_modified DESC
            LIMIT 1";

        $result = $this->runCommand($site, "cd ~/files && wp db query \"{$query}\" --skip-column-names");

        if ($result['success'] && ! empty(trim($result['output']))) {
            $line = trim($result['output']);
            $parts = preg_split('/\s+/', $line, 3);
            $postId = (int) ($parts[0] ?? 0);
            $themeSlug = $parts[2] ?? null;

            if ($postId > 0) {
                return ['id' => $postId, 'theme' => $themeSlug];
            }
        }

        return null;
    }

    /**
     * List all template parts in the database for debugging.
     *
     * @return array{success: bool, template_parts?: array, error?: string}
     */
    public function listTemplateParts(SpinupWpSite $site): array
    {
        $result = $this->runCommand($site, 'cd ~/files && wp post list --post_type=wp_template_part --format=json --fields=ID,post_name,post_title,post_status,post_modified');

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?: 'Failed to list template parts',
            ];
        }

        $posts = json_decode($result['output'], true) ?? [];

        // Get theme taxonomy for each post
        foreach ($posts as &$post) {
            $termResult = $this->runCommand($site, "cd ~/files && wp post term list {$post['ID']} wp_theme --format=csv --fields=slug 2>/dev/null | tail -n +2");
            $post['theme'] = trim($termResult['output']) ?: null;
        }

        return [
            'success' => true,
            'template_parts' => $posts,
        ];
    }

    /**
     * Find a template part post ID that belongs to a specific theme.
     * WordPress FSE stores template parts with a wp_theme taxonomy term.
     *
     * Template part post_name can be in various formats:
     * - Simple: "header"
     * - Composite: "header//ollie" or "ollie//header"
     */
    protected function findTemplatePartByTheme(SpinupWpSite $site, string $slug, string $theme): ?int
    {
        $query = "SELECT p.ID FROM wp_posts p 
            INNER JOIN wp_term_relationships tr ON p.ID = tr.object_id 
            INNER JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id 
            INNER JOIN wp_terms t ON tt.term_id = t.term_id 
            WHERE p.post_type = 'wp_template_part' 
            AND (p.post_name = '{$slug}' OR p.post_name LIKE '%//{$slug}' OR p.post_name LIKE '{$slug}//%')
            AND tt.taxonomy = 'wp_theme' 
            AND t.slug = '{$theme}' 
            LIMIT 1";

        $result = $this->runCommand($site, "cd ~/files && wp db query \"{$query}\" --skip-column-names");

        if ($result['success'] && ! empty(trim($result['output']))) {
            $postId = (int) trim($result['output']);
            if ($postId > 0) {
                return $postId;
            }
        }

        return null;
    }

    /**
     * Create a new template part and associate it with the correct theme.
     */
    protected function createTemplatePartWithTheme(SpinupWpSite $site, string $slug, string $tempFile, string $theme): array
    {
        $title = ucwords(str_replace(['-', '_'], ' ', $slug));

        $createResult = $this->runWpCli($site, 'post create', [
            $tempFile,
            '--post_type=wp_template_part',
            '--post_name='.$slug,
            '--post_status=publish',
            '--post_title='.$title,
            '--porcelain',
        ]);

        if (! $createResult['success']) {
            return $createResult;
        }

        $newPostId = (int) trim($createResult['output']);
        if ($newPostId <= 0) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'Failed to get new post ID after creation',
            ];
        }

        $termResult = $this->runCommand($site, "cd ~/files && wp post term set {$newPostId} wp_theme {$theme}");

        if (! $termResult['success']) {
            return [
                'success' => false,
                'output' => $createResult['output'],
                'error' => 'Created post but failed to set theme term: '.$termResult['error'],
            ];
        }

        return [
            'success' => true,
            'output' => "Created template part {$slug} (ID: {$newPostId}) with theme {$theme}",
            'error' => '',
        ];
    }

    public function uploadContent(SpinupWpSite $site, string $content, string $remotePath): array
    {
        $server = $site->server;

        if (! $server) {
            throw new \RuntimeException('Site has no associated server');
        }

        $localTempFile = sys_get_temp_dir().'/template_'.uniqid().'.html';
        file_put_contents($localTempFile, $content);

        $scpCommand = $this->buildScpCommand(
            $localTempFile,
            $server->ip_address,
            $site->site_user,
            $server->ssh_port ?? 22,
            $remotePath
        );

        $result = Process::timeout($this->timeout)->run($scpCommand);

        @unlink($localTempFile);

        return [
            'success' => $result->successful(),
            'output' => trim($result->output()),
            'error' => trim($result->errorOutput()),
        ];
    }

    protected function buildScpCommand(string $localPath, string $host, string $user, int $port, string $remotePath): string
    {
        $scpOptions = [
            '-o StrictHostKeyChecking=accept-new',
            '-o ConnectTimeout=30',
            '-o BatchMode=yes',
            "-P {$port}",
        ];

        $sshKeyPath = $this->resolveSshKeyPath();
        if ($sshKeyPath && file_exists($sshKeyPath)) {
            $scpOptions[] = "-i {$sshKeyPath}";
        }

        $optionsString = implode(' ', $scpOptions);

        return "scp {$optionsString} {$localPath} {$user}@{$host}:{$remotePath}";
    }

    /**
     * Generate a WordPress application password via WP-CLI and store it.
     *
     * @return array{success: bool, password?: string, wordpress_site?: \App\Models\WordPressSite, error?: string}
     */
    public function generateApplicationPassword(SpinupWpSite $site, string $appName = 'Zao Dash API'): array
    {
        $wpUser = $site->wp_admin_user;

        if (! $wpUser) {
            return [
                'success' => false,
                'error' => 'No WordPress admin user configured for this site',
            ];
        }

        $result = $this->runWpCli($site, 'user application-password create', [
            $wpUser,
            $appName,
            '--porcelain',
        ]);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?: 'Failed to create application password',
            ];
        }

        $password = trim($result['output']);

        if (empty($password)) {
            return [
                'success' => false,
                'error' => 'WP-CLI returned empty password',
            ];
        }

        $site->wp_admin_password = $password;
        $site->save();

        $wordpressSite = $site->wordpressSite;
        if (! $wordpressSite) {
            $wordpressSite = $site->createLinkedWordPressSite();
        } else {
            $wordpressSite->update([
                'username' => $wpUser,
                'application_password' => $password,
            ]);
        }

        return [
            'success' => true,
            'password' => $password,
            'wordpress_site' => $wordpressSite,
        ];
    }

    /**
     * Get the WordPress admin username via WP-CLI if not already stored.
     */
    public function discoverAdminUser(SpinupWpSite $site): array
    {
        $result = $this->runWpCli($site, 'user list', [
            '--role=administrator',
            '--field=user_login',
            '--number=1',
        ]);

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?: 'Failed to list admin users',
            ];
        }

        $username = trim($result['output']);

        if (empty($username)) {
            return [
                'success' => false,
                'error' => 'No administrator user found',
            ];
        }

        if (! $site->wp_admin_user) {
            $site->update(['wp_admin_user' => $username]);
        }

        return [
            'success' => true,
            'username' => $username,
        ];
    }

    /**
     * Ensure a child theme exists and is activated.
     *
     * Creates the child theme directory with required style.css if it doesn't exist,
     * then activates it. This is required for template part and global style overrides.
     *
     * @return array{success: bool, created?: bool, already_exists?: bool, error?: string}
     */
    public function ensureChildThemeExists(SpinupWpSite $site, string $parentTheme = 'ollie'): array
    {
        $childTheme = $parentTheme.'-child';
        $themePath = "~/files/wp-content/themes/{$childTheme}";

        $checkResult = $this->runCommand($site, "test -d {$themePath} && echo 'exists'");

        if (str_contains($checkResult['output'] ?? '', 'exists')) {
            // Ensure child theme is active
            $this->runWpCli($site, 'theme activate', [$childTheme]);

            return ['success' => true, 'already_exists' => true, 'theme' => $childTheme];
        }

        $mkdirResult = $this->runCommand($site, "mkdir -p {$themePath}");

        if (! $mkdirResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create child theme directory: '.($mkdirResult['error'] ?? 'Unknown error'),
            ];
        }

        $styleCss = $this->generateChildThemeStyleCss($parentTheme, $childTheme);
        $styleTempPath = '/tmp/child_style_'.uniqid().'.css';
        $styleUploadResult = $this->uploadContent($site, $styleCss, $styleTempPath);

        if (! $styleUploadResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to upload style.css: '.($styleUploadResult['error'] ?? 'Unknown error'),
            ];
        }

        $this->runCommand($site, "mv {$styleTempPath} {$themePath}/style.css");
        $this->runWpCli($site, 'theme activate', [$childTheme]);

        return ['success' => true, 'created' => true, 'theme' => $childTheme];
    }

    protected function generateChildThemeStyleCss(string $parentTheme, string $childTheme): string
    {
        $themeName = ucwords(str_replace('-', ' ', $childTheme));
        $parentName = ucwords(str_replace('-', ' ', $parentTheme));

        return <<<CSS
/*
 Theme Name:   {$themeName}
 Theme URI:    https://zaowebdesign.com
 Description:  Custom child theme for {$parentName}
 Author:       Zao
 Author URI:   https://zaowebdesign.com
 Template:     {$parentTheme}
 Version:      1.0.0
 Text Domain:  {$childTheme}
*/

/* Add your custom styles below this line */
CSS;
    }

    /**
     * Delete database template parts for a given slug.
     *
     * This removes any database-stored template parts that would override
     * file-based template parts. Database template parts take precedence
     * over file-based ones in WordPress FSE.
     *
     * @param  string  $slug  Template part slug (e.g., 'header', 'footer')
     * @return array{success: bool, deleted_ids?: array, output?: string}
     */
    public function deleteDbTemplateParts(SpinupWpSite $site, string $slug): array
    {
        // Use WP-CLI to find template parts - this handles table prefixes automatically
        // Search for any template part where the slug appears in the post_name
        // FSE template parts are stored as "theme//slug" format
        $result = $this->runCommand(
            $site,
            "cd ~/files && wp post list --post_type=wp_template_part --post_status=any --field=ID --format=csv 2>/dev/null | tail -n +2 | while read id; do name=\$(wp post get \$id --field=post_name 2>/dev/null); if echo \"\$name\" | grep -qi '{$slug}'; then echo \$id; fi; done"
        );

        // If the complex query fails, fall back to direct db query
        if (! $result['success'] || empty(trim($result['output']))) {
            // Try with wp post list and grep as a simpler approach
            $result = $this->runCommand(
                $site,
                "cd ~/files && wp post list --post_type=wp_template_part --post_status=any --fields=ID,post_name --format=csv | grep -i '{$slug}' | cut -d',' -f1"
            );
        }

        if (! $result['success']) {
            return [
                'success' => false,
                'error' => 'Failed to query template parts: '.($result['error'] ?? 'Unknown error'),
            ];
        }

        $ids = array_filter(array_map('intval', preg_split('/\s+/', trim($result['output']))));

        if (empty($ids)) {
            return [
                'success' => true,
                'deleted_ids' => [],
                'output' => 'No database template parts found to delete',
            ];
        }

        // Delete each template part
        $deletedIds = [];
        foreach ($ids as $id) {
            $deleteResult = $this->runCommand($site, "cd ~/files && wp post delete {$id} --force");
            if ($deleteResult['success']) {
                $deletedIds[] = $id;
            }
        }

        return [
            'success' => true,
            'deleted_ids' => $deletedIds,
            'output' => 'Deleted '.count($deletedIds).' database template part(s): '.implode(', ', $deletedIds),
        ];
    }

    /**
     * Write a template part file directly to the child theme's parts folder.
     *
     * This is an alternative to database-based template parts that's more
     * reliable for FSE themes. The file-based approach always works regardless
     * of WordPress's template resolution quirks.
     *
     * IMPORTANT: This method also deletes any database-stored template parts
     * for the same slug, as database parts take precedence over file-based ones.
     *
     * @param  string  $slug  Template part slug (e.g., 'header', 'footer')
     * @param  string  $content  Block content for the template part
     * @param  string  $parentTheme  Parent theme name (default: 'ollie')
     * @return array{success: bool, path?: string, error?: string}
     */
    public function writeTemplatePartFile(SpinupWpSite $site, string $slug, string $content, string $parentTheme = 'ollie'): array
    {
        $childTheme = $parentTheme.'-child';
        $partsDir = "~/files/wp-content/themes/{$childTheme}/parts";
        $filePath = "{$partsDir}/{$slug}.html";

        // Ensure child theme exists
        $childResult = $this->ensureChildThemeExists($site, $parentTheme);
        if (! $childResult['success']) {
            return $childResult;
        }

        // CRITICAL: Delete any database template parts for this slug first
        // Database template parts ALWAYS override file-based ones in WordPress FSE
        $deleteResult = $this->deleteDbTemplateParts($site, $slug);
        $deletedInfo = $deleteResult['success'] && ! empty($deleteResult['deleted_ids'])
            ? ' (deleted '.count($deleteResult['deleted_ids']).' database entries)'
            : '';

        // Create parts directory if it doesn't exist
        $mkdirResult = $this->runCommand($site, "mkdir -p {$partsDir}");
        if (! $mkdirResult['success']) {
            return [
                'success' => false,
                'error' => 'Failed to create parts directory: '.($mkdirResult['error'] ?? 'Unknown error'),
            ];
        }

        // Upload content to temp file then move to final location
        $tempFile = '/tmp/template_part_file_'.uniqid().'.html';
        $uploadResult = $this->uploadContent($site, $content, $tempFile);
        if (! $uploadResult['success']) {
            return $uploadResult;
        }

        // Move to final location
        $moveResult = $this->runCommand($site, "mv {$tempFile} {$filePath}");
        if (! $moveResult['success']) {
            $this->runCommand($site, "rm -f {$tempFile}");

            return [
                'success' => false,
                'error' => 'Failed to write template part file: '.($moveResult['error'] ?? 'Unknown error'),
            ];
        }

        return [
            'success' => true,
            'path' => $filePath,
            'output' => "Wrote template part file to {$filePath}{$deletedInfo}",
        ];
    }
}
