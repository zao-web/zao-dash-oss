<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use App\Services\SpinupWp\SpinupWpSshService;
use App\Services\WordPress\WordPressMcpService;

class WebsiteBuilderUpdateTemplatePartTool extends BaseTool
{
    public function __construct(
        protected WordPressMcpService $wpService,
        protected SpinupWpSshService $sshService
    ) {}

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Update Template Part';
    }

    public function description(): string
    {
        return 'Update a WordPress template part (header, footer, sidebar) with custom block content. Use this to replace stock theme content with business-specific information like company name, contact details, navigation menus, etc.';
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID',
                ],
                'template_part' => [
                    'type' => 'string',
                    'enum' => ['header', 'footer', 'sidebar', 'header-dark', 'footer-dark', 'footer-light'],
                    'description' => 'Which template part to update (header, footer, sidebar, or theme-specific variants)',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Block editor content (HTML with WordPress block comments). Use wp:group, wp:columns, wp:paragraph, wp:heading, wp:navigation, etc.',
                ],
                'area' => [
                    'type' => 'string',
                    'enum' => ['header', 'footer', 'sidebar', 'uncategorized'],
                    'description' => 'Template part area classification (defaults based on template_part name)',
                ],
            ],
            'required' => ['project_id', 'template_part', 'content'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'template_part' => 'required|string',
            'content' => 'required|string|min:10',
            'area' => 'nullable|string|in:header,footer,sidebar,uncategorized',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $templatePart = $params['template_part'];
        $content = $params['content'];
        $area = $params['area'] ?? $this->inferArea($templatePart);
        $baseTheme = $project->design_config['theme'] ?? 'ollie';
        // Use child theme for template parts so they override the parent
        $theme = $baseTheme.'-child';

        $wpSite = $this->resolveWordPressSite($project);

        if ($wpSite) {
            $result = $this->updateViaRestApi($wpSite, $project, $templatePart, $content, $area, $theme);
            if ($result['success']) {
                return $result;
            }
        }

        $spinupSite = $this->resolveSpinupSite($project);

        if ($spinupSite && $spinupSite->server) {
            return $this->updateViaSsh($spinupSite, $project, $templatePart, $content, $theme);
        }

        return [
            'success' => false,
            'error' => 'No WordPress site or SSH access available for this project. The site must be deployed with either REST API credentials or SpinupWP SSH access.',
        ];
    }

    protected function updateViaRestApi(WordPressSite $wpSite, WebsiteProject $project, string $templatePart, string $content, string $area, string $theme): array
    {
        try {
            $result = $this->wpService->upsertTemplatePart(
                $wpSite,
                $templatePart,
                $content,
                $area,
                $theme
            );

            $this->storeTemplatePartInProject($project, $templatePart, $content);

            return [
                'success' => true,
                'method' => 'rest_api',
                'project_id' => $project->id,
                'template_part' => $templatePart,
                'area' => $area,
                'wordpress_id' => $result['id'] ?? null,
                'edit_url' => rtrim($wpSite->url, '/').'/wp-admin/site-editor.php?path=%2Fpatterns&categoryType=wp_template_part&categoryId='.$templatePart,
                'message' => "Template part '{$templatePart}' updated successfully via REST API.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function updateViaSsh(SpinupWpSite $spinupSite, WebsiteProject $project, string $templatePart, string $content, string $theme): array
    {
        try {
            // Ensure child theme exists and is activated before updating template parts
            $baseTheme = $project->design_config['theme'] ?? 'ollie';
            $childThemeResult = $this->sshService->ensureChildThemeExists($spinupSite, $baseTheme);
            if (! $childThemeResult['success']) {
                return $childThemeResult;
            }

            $result = $this->sshService->updateTemplatePart($spinupSite, $templatePart, $content, $theme);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => 'SSH command failed: '.($result['error'] ?: $result['output']),
                    'suggestion' => $this->getSuggestionForSshError($result['error'] ?? ''),
                ];
            }

            $this->storeTemplatePartInProject($project, $templatePart, $content);

            return [
                'success' => true,
                'method' => 'ssh_wp_cli',
                'project_id' => $project->id,
                'template_part' => $templatePart,
                'output' => $result['output'],
                'edit_url' => rtrim($spinupSite->url, '/').'/wp-admin/site-editor.php?path=%2Fpatterns&categoryType=wp_template_part&categoryId='.$templatePart,
                'message' => "Template part '{$templatePart}' updated successfully via WP-CLI over SSH.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'suggestion' => $this->getSuggestionForSshError($e->getMessage()),
            ];
        }
    }

    protected function resolveWordPressSite(WebsiteProject $project): ?WordPressSite
    {
        $spinupSite = SpinupWpSite::where('website_project_id', $project->id)
            ->where('status', SpinupWpSite::STATUS_DEPLOYED)
            ->first();

        if ($spinupSite && $spinupSite->wordpressSite) {
            return $spinupSite->wordpressSite;
        }

        if ($spinupSite && $spinupSite->wp_admin_user && $spinupSite->wp_admin_password) {
            return new WordPressSite([
                'url' => $spinupSite->url,
                'username' => $spinupSite->wp_admin_user,
                'application_password' => $spinupSite->wp_admin_password,
            ]);
        }

        if ($project->staging_url) {
            return WordPressSite::where('url', 'like', '%'.parse_url($project->staging_url, PHP_URL_HOST).'%')
                ->first();
        }

        return null;
    }

    protected function resolveSpinupSite(WebsiteProject $project): ?SpinupWpSite
    {
        $spinupSite = SpinupWpSite::where('website_project_id', $project->id)
            ->where('status', SpinupWpSite::STATUS_DEPLOYED)
            ->with('server')
            ->first();

        if ($spinupSite) {
            return $spinupSite;
        }

        if ($project->staging_url) {
            $host = parse_url($project->staging_url, PHP_URL_HOST);

            return SpinupWpSite::where('domain', $host)
                ->where('status', SpinupWpSite::STATUS_DEPLOYED)
                ->with('server')
                ->first();
        }

        return null;
    }

    protected function inferArea(string $templatePart): string
    {
        if (str_contains($templatePart, 'header')) {
            return 'header';
        }

        if (str_contains($templatePart, 'footer')) {
            return 'footer';
        }

        if (str_contains($templatePart, 'sidebar')) {
            return 'sidebar';
        }

        return 'uncategorized';
    }

    protected function storeTemplatePartInProject(WebsiteProject $project, string $slug, string $content): void
    {
        $templateParts = $project->template_parts ?? [];
        $templateParts[$slug] = [
            'content' => $content,
            'updated_at' => now()->toIso8601String(),
        ];

        $project->update(['template_parts' => $templateParts]);
    }

    protected function getSuggestionForSshError(string $error): string
    {
        if (str_contains($error, 'Permission denied') || str_contains($error, 'publickey')) {
            return 'SSH key authentication failed. Ensure the server SSH key is configured in Zao Dash.';
        }

        if (str_contains($error, 'Connection refused') || str_contains($error, 'Connection timed out')) {
            return 'Cannot connect to server via SSH. Check that the server is online and SSH port is accessible.';
        }

        if (str_contains($error, 'wp: command not found')) {
            return 'WP-CLI is not installed on the server. Contact SpinupWP support.';
        }

        return 'Check server SSH access and WP-CLI installation.';
    }
}
