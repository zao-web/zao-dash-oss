<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use App\Services\SpinupWp\SpinupWpSshService;
use App\Services\WordPress\WordPressMcpService;

class WebsiteBuilderUpdateGlobalStylesTool extends BaseTool
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
        return 'Update Global Styles';
    }

    public function description(): string
    {
        return 'Update WordPress global styles for layout, colors, typography, and spacing. Use this to set content width (wide/full), adjust spacing, change colors site-wide, or update typography settings. Changes apply to the entire site.';
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
                'layout' => [
                    'type' => 'object',
                    'description' => 'Layout settings for content width',
                    'properties' => [
                        'content_size' => [
                            'type' => 'string',
                            'description' => 'Default content width (e.g., 650px, 800px, 1200px)',
                        ],
                        'wide_size' => [
                            'type' => 'string',
                            'description' => 'Wide content width (e.g., 1200px, 1400px, 100%)',
                        ],
                    ],
                ],
                'colors' => [
                    'type' => 'object',
                    'description' => 'Color overrides using CSS custom properties',
                    'properties' => [
                        'background' => ['type' => 'string', 'description' => 'Background color (hex or var)'],
                        'text' => ['type' => 'string', 'description' => 'Text color (hex or var)'],
                        'primary' => ['type' => 'string', 'description' => 'Primary brand color (hex)'],
                        'secondary' => ['type' => 'string', 'description' => 'Secondary color (hex)'],
                    ],
                ],
                'typography' => [
                    'type' => 'object',
                    'description' => 'Typography settings',
                    'properties' => [
                        'font_family' => ['type' => 'string', 'description' => 'Body font family slug'],
                        'font_size' => ['type' => 'string', 'description' => 'Base font size (e.g., 16px, 1rem)'],
                        'line_height' => ['type' => 'string', 'description' => 'Base line height (e.g., 1.6)'],
                    ],
                ],
                'spacing' => [
                    'type' => 'object',
                    'description' => 'Spacing settings',
                    'properties' => [
                        'block_gap' => ['type' => 'string', 'description' => 'Gap between blocks (e.g., 24px, 2rem)'],
                        'padding' => [
                            'type' => 'object',
                            'properties' => [
                                'top' => ['type' => 'string'],
                                'right' => ['type' => 'string'],
                                'bottom' => ['type' => 'string'],
                                'left' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                'elements' => [
                    'type' => 'object',
                    'description' => 'Element-specific styles (buttons, headings, links)',
                    'properties' => [
                        'button' => [
                            'type' => 'object',
                            'properties' => [
                                'background_color' => ['type' => 'string'],
                                'text_color' => ['type' => 'string'],
                                'border_radius' => ['type' => 'string'],
                                'padding' => ['type' => 'string'],
                            ],
                        ],
                        'heading' => [
                            'type' => 'object',
                            'properties' => [
                                'font_family' => ['type' => 'string'],
                                'font_weight' => ['type' => 'string'],
                                'color' => ['type' => 'string'],
                            ],
                        ],
                        'link' => [
                            'type' => 'object',
                            'properties' => [
                                'color' => ['type' => 'string'],
                                'text_decoration' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'required' => ['project_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'layout' => 'nullable|array',
            'colors' => 'nullable|array',
            'typography' => 'nullable|array',
            'spacing' => 'nullable|array',
            'elements' => 'nullable|array',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $styles = $this->buildStylesPayload($params);

        if (empty($styles)) {
            return ['success' => false, 'error' => 'No style changes specified'];
        }

        $wpSite = $this->resolveWordPressSite($project);

        if ($wpSite) {
            $result = $this->updateViaRestApi($wpSite, $project, $styles);
            if ($result['success']) {
                return $result;
            }
        }

        $spinupSite = $this->resolveSpinupSite($project);

        if ($spinupSite && $spinupSite->server) {
            return $this->updateViaSsh($spinupSite, $project, $styles);
        }

        return [
            'success' => false,
            'error' => 'No WordPress site or SSH access available. The site must be deployed with either REST API credentials or SpinupWP SSH access.',
        ];
    }

    protected function buildStylesPayload(array $params): array
    {
        $styles = [];

        if (! empty($params['layout'])) {
            $styles['settings']['layout'] = [];
            if (! empty($params['layout']['content_size'])) {
                $styles['settings']['layout']['contentSize'] = $params['layout']['content_size'];
            }
            if (! empty($params['layout']['wide_size'])) {
                $styles['settings']['layout']['wideSize'] = $params['layout']['wide_size'];
            }
        }

        if (! empty($params['colors'])) {
            $styles['styles']['color'] = [];
            if (! empty($params['colors']['background'])) {
                $styles['styles']['color']['background'] = $params['colors']['background'];
            }
            if (! empty($params['colors']['text'])) {
                $styles['styles']['color']['text'] = $params['colors']['text'];
            }
        }

        if (! empty($params['typography'])) {
            $styles['styles']['typography'] = [];
            if (! empty($params['typography']['font_family'])) {
                $styles['styles']['typography']['fontFamily'] = 'var(--wp--preset--font-family--'.$params['typography']['font_family'].')';
            }
            if (! empty($params['typography']['font_size'])) {
                $styles['styles']['typography']['fontSize'] = $params['typography']['font_size'];
            }
            if (! empty($params['typography']['line_height'])) {
                $styles['styles']['typography']['lineHeight'] = $params['typography']['line_height'];
            }
        }

        if (! empty($params['spacing'])) {
            $styles['styles']['spacing'] = [];
            if (! empty($params['spacing']['block_gap'])) {
                $styles['styles']['spacing']['blockGap'] = $params['spacing']['block_gap'];
            }
            if (! empty($params['spacing']['padding'])) {
                $styles['styles']['spacing']['padding'] = $params['spacing']['padding'];
            }
        }

        if (! empty($params['elements'])) {
            $styles['styles']['elements'] = [];

            if (! empty($params['elements']['button'])) {
                $btn = $params['elements']['button'];
                $styles['styles']['elements']['button'] = [];
                if (! empty($btn['background_color'])) {
                    $styles['styles']['elements']['button']['color']['background'] = $btn['background_color'];
                }
                if (! empty($btn['text_color'])) {
                    $styles['styles']['elements']['button']['color']['text'] = $btn['text_color'];
                }
                if (! empty($btn['border_radius'])) {
                    $styles['styles']['elements']['button']['border']['radius'] = $btn['border_radius'];
                }
            }

            if (! empty($params['elements']['heading'])) {
                $heading = $params['elements']['heading'];
                $styles['styles']['elements']['heading'] = [];
                if (! empty($heading['font_family'])) {
                    $styles['styles']['elements']['heading']['typography']['fontFamily'] = 'var(--wp--preset--font-family--'.$heading['font_family'].')';
                }
                if (! empty($heading['font_weight'])) {
                    $styles['styles']['elements']['heading']['typography']['fontWeight'] = $heading['font_weight'];
                }
                if (! empty($heading['color'])) {
                    $styles['styles']['elements']['heading']['color']['text'] = $heading['color'];
                }
            }

            if (! empty($params['elements']['link'])) {
                $link = $params['elements']['link'];
                $styles['styles']['elements']['link'] = [];
                if (! empty($link['color'])) {
                    $styles['styles']['elements']['link']['color']['text'] = $link['color'];
                }
                if (! empty($link['text_decoration'])) {
                    $styles['styles']['elements']['link']['typography']['textDecoration'] = $link['text_decoration'];
                }
            }
        }

        return $styles;
    }

    protected function updateViaRestApi(WordPressSite $wpSite, WebsiteProject $project, array $styles): array
    {
        try {
            $globalStyles = $this->wpService->getGlobalStyles($wpSite);

            if (empty($globalStyles)) {
                return ['success' => false, 'error' => 'Could not retrieve global styles from WordPress'];
            }

            $styleId = is_array($globalStyles) && isset($globalStyles[0]['id'])
                ? $globalStyles[0]['id']
                : ($globalStyles['id'] ?? null);

            if (! $styleId) {
                return ['success' => false, 'error' => 'Could not find global styles ID'];
            }

            $result = $this->wpService->updateGlobalStyles($wpSite, $styleId, $styles['styles'] ?? []);

            $this->storeStylesInProject($project, $styles);

            return [
                'success' => true,
                'method' => 'rest_api',
                'project_id' => $project->id,
                'styles_applied' => array_keys($styles['styles'] ?? []),
                'edit_url' => rtrim($wpSite->url, '/').'/wp-admin/site-editor.php?path=%2Fwp_global_styles',
                'message' => 'Global styles updated successfully via REST API.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function updateViaSsh(SpinupWpSite $spinupSite, WebsiteProject $project, array $styles): array
    {
        try {
            $theme = $project->design_config['theme'] ?? 'ollie';

            // Ensure child theme exists and is activated
            $childThemeResult = $this->sshService->ensureChildThemeExists($spinupSite, $theme);
            if (! $childThemeResult['success']) {
                return $childThemeResult;
            }

            $childTheme = $theme.'-child';
            $themeJsonContent = $this->buildThemeJsonContent($styles, $project);
            $remotePath = "~/files/wp-content/themes/{$childTheme}/theme.json";
            $tempPath = '/tmp/theme_'.uniqid().'.json';

            $result = $this->sshService->uploadContent($spinupSite, $themeJsonContent, $tempPath);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to upload theme.json: '.($result['error'] ?? 'Unknown error'),
                ];
            }

            $moveResult = $this->sshService->runCommand(
                $spinupSite,
                "mv {$tempPath} {$remotePath}"
            );

            if (! $moveResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to move theme.json to theme directory: '.($moveResult['error'] ?? 'Unknown error'),
                ];
            }

            $this->storeStylesInProject($project, $styles);

            return [
                'success' => true,
                'method' => 'ssh_theme_json',
                'project_id' => $project->id,
                'theme' => $childTheme,
                'styles_applied' => array_keys($styles['styles'] ?? []),
                'settings_applied' => array_keys($styles['settings'] ?? []),
                'edit_url' => rtrim($spinupSite->url, '/').'/wp-admin/site-editor.php?path=%2Fwp_global_styles',
                'message' => "Theme.json updated for {$childTheme} via SSH.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function buildThemeJsonContent(array $styles, WebsiteProject $project): string
    {
        $existingConfig = $project->design_config['theme_json'] ?? [];

        $themeJson = array_merge([
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
        ], $existingConfig);

        if (! empty($styles['settings'])) {
            $themeJson['settings'] = array_merge($themeJson['settings'] ?? [], $styles['settings']);
        }

        if (! empty($styles['styles'])) {
            $themeJson['styles'] = array_merge_recursive($themeJson['styles'] ?? [], $styles['styles']);
        }

        return json_encode($themeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    protected function storeStylesInProject(WebsiteProject $project, array $styles): void
    {
        $designConfig = $project->design_config ?? [];
        $designConfig['global_styles'] = array_merge($designConfig['global_styles'] ?? [], $styles);
        $designConfig['styles_updated_at'] = now()->toIso8601String();

        $project->update(['design_config' => $designConfig]);
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
}
