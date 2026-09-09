<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Services\SpinupWp\SpinupWpSshService;

class WebsiteBuilderUploadThemeFileTool extends BaseTool
{
    public function __construct(
        protected SpinupWpSshService $sshService
    ) {}

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Upload Theme File';
    }

    public function description(): string
    {
        return 'Upload a file to the WordPress child theme directory via SSH. Use this to push theme.json, style.css, custom CSS files, or other theme assets. Creates the child theme directory if it does not exist.';
    }

    public function requiresApproval(): bool
    {
        return false; // Orchestrator-invoked, part of automated website builder workflow
    }

    public function riskLevel(): string
    {
        return 'low';
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
                'filename' => [
                    'type' => 'string',
                    'description' => 'Target filename (e.g., theme.json, style.css, custom.css, functions.php)',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'File content to upload',
                ],
                'subdirectory' => [
                    'type' => 'string',
                    'description' => 'Optional subdirectory within the theme (e.g., assets, css, parts)',
                ],
                'parent_theme' => [
                    'type' => 'string',
                    'description' => 'Parent theme name (defaults to ollie)',
                ],
                'create_child_theme' => [
                    'type' => 'boolean',
                    'description' => 'Create the child theme with required files if it does not exist (default: true)',
                ],
            ],
            'required' => ['project_id', 'filename', 'content'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'filename' => 'required|string|max:255',
            'content' => 'required|string',
            'subdirectory' => 'nullable|string|max:100',
            'parent_theme' => 'nullable|string|max:100',
            'create_child_theme' => 'nullable|boolean',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $spinupSite = $this->resolveSpinupSite($project);

        if (! $spinupSite || ! $spinupSite->server) {
            return [
                'success' => false,
                'error' => 'No SpinupWP site with SSH access available for this project.',
            ];
        }

        $parentTheme = $params['parent_theme'] ?? $project->design_config['theme'] ?? 'ollie';
        $childTheme = $parentTheme.'-child';
        $filename = $params['filename'];
        $content = $params['content'];
        $subdirectory = $params['subdirectory'] ?? null;
        $createChildTheme = $params['create_child_theme'] ?? true;

        if (! $this->isAllowedFilename($filename)) {
            return [
                'success' => false,
                'error' => "Filename '{$filename}' is not allowed. Only theme-related files are permitted.",
            ];
        }

        try {
            if ($createChildTheme) {
                $setupResult = $this->sshService->ensureChildThemeExists($spinupSite, $parentTheme);
                if (! $setupResult['success']) {
                    return $setupResult;
                }
            }

            $themePath = "~/files/wp-content/themes/{$childTheme}";
            $targetPath = $subdirectory
                ? "{$themePath}/{$subdirectory}/{$filename}"
                : "{$themePath}/{$filename}";

            if ($subdirectory) {
                $mkdirResult = $this->sshService->runCommand(
                    $spinupSite,
                    "mkdir -p {$themePath}/{$subdirectory}"
                );

                if (! $mkdirResult['success']) {
                    return [
                        'success' => false,
                        'error' => 'Failed to create subdirectory: '.($mkdirResult['error'] ?? 'Unknown error'),
                    ];
                }
            }

            $tempPath = '/tmp/theme_file_'.uniqid().'_'.basename($filename);
            $uploadResult = $this->sshService->uploadContent($spinupSite, $content, $tempPath);

            if (! $uploadResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to upload file: '.($uploadResult['error'] ?? 'Unknown error'),
                ];
            }

            $moveResult = $this->sshService->runCommand($spinupSite, "mv {$tempPath} {$targetPath}");

            if (! $moveResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to move file to theme directory: '.($moveResult['error'] ?? 'Unknown error'),
                ];
            }

            $this->storeFileInProject($project, $filename, $content, $subdirectory);

            return [
                'success' => true,
                'project_id' => $project->id,
                'theme' => $childTheme,
                'filename' => $filename,
                'path' => $targetPath,
                'subdirectory' => $subdirectory,
                'file_size' => strlen($content),
                'edit_url' => rtrim($spinupSite->url, '/').'/wp-admin/theme-editor.php?file='.$filename.'&theme='.$childTheme,
                'message' => "File '{$filename}' uploaded successfully to {$childTheme} theme.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function isAllowedFilename(string $filename): bool
    {
        $allowedExtensions = ['json', 'css', 'php', 'js', 'html', 'txt', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, $allowedExtensions)) {
            return false;
        }

        $blockedNames = ['wp-config.php', '.htaccess', 'wp-settings.php'];
        if (in_array(strtolower($filename), $blockedNames)) {
            return false;
        }

        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return false;
        }

        return true;
    }

    protected function storeFileInProject(WebsiteProject $project, string $filename, string $content, ?string $subdirectory): void
    {
        $designConfig = $project->design_config ?? [];
        $themeFiles = $designConfig['theme_files'] ?? [];

        $key = $subdirectory ? "{$subdirectory}/{$filename}" : $filename;
        $themeFiles[$key] = [
            'content' => $content,
            'uploaded_at' => now()->toIso8601String(),
        ];

        $designConfig['theme_files'] = $themeFiles;
        $project->update(['design_config' => $designConfig]);
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
}
