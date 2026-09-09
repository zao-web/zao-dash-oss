<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Storage;

/**
 * Generate Ollie theme.json with brand customizations.
 *
 * Creates a complete theme.json file mapping brand colors and typography
 * to Ollie's design token system.
 */
class OllieGenerateThemeJsonTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Generate Theme JSON';
    }

    public function description(): string
    {
        return 'Generate a customized theme.json for an Ollie child theme. Maps brand colors to Ollie design tokens, configures typography, and applies spacing preferences.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'string',
                    'description' => 'Unique project identifier for file storage',
                ],
                'brand_colors' => [
                    'type' => 'object',
                    'description' => 'Brand color palette with primary, secondary, accent colors as hex values',
                    'properties' => [
                        'primary' => ['type' => 'string', 'description' => 'Primary brand color (hex)'],
                        'secondary' => ['type' => 'string', 'description' => 'Secondary brand color (hex)'],
                        'accent' => ['type' => 'string', 'description' => 'Accent color (hex)'],
                    ],
                ],
                'typography' => [
                    'type' => 'object',
                    'description' => 'Typography preferences',
                    'properties' => [
                        'heading_font' => [
                            'type' => 'string',
                            'enum' => ['primary', 'expanded', 'condensed', 'narrow'],
                            'description' => 'Font variant for headings',
                        ],
                        'body_font' => [
                            'type' => 'string',
                            'enum' => ['primary', 'expanded', 'condensed', 'narrow'],
                            'description' => 'Font variant for body text',
                        ],
                    ],
                ],
                'spacing_scale' => [
                    'type' => 'string',
                    'enum' => ['compact', 'default', 'spacious'],
                    'description' => 'Spacing scale preference',
                ],
                'base_style' => [
                    'type' => 'string',
                    'enum' => ['default', 'agency', 'creator', 'startup', 'studio'],
                    'description' => 'Base Ollie style variation to extend',
                ],
                'button_style' => [
                    'type' => 'object',
                    'description' => 'Button styling preferences',
                    'properties' => [
                        'radius' => ['type' => 'string', 'description' => 'Border radius (e.g., 5px, 10px, 9999px)'],
                        'text_transform' => ['type' => 'string', 'enum' => ['none', 'uppercase', 'capitalize']],
                    ],
                ],
            ],
            'required' => ['project_id', 'brand_colors'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|string',
            'brand_colors' => 'required|array',
            'brand_colors.primary' => 'required|string|regex:/^#[A-Fa-f0-9]{6}$/',
            'brand_colors.secondary' => 'nullable|string|regex:/^#[A-Fa-f0-9]{6}$/',
            'brand_colors.accent' => 'nullable|string|regex:/^#[A-Fa-f0-9]{6}$/',
            'typography' => 'nullable|array',
            'spacing_scale' => 'nullable|in:compact,default,spacious',
            'base_style' => 'nullable|in:default,agency,creator,startup,studio',
        ];
    }

    public function execute(array $params): array
    {
        $projectId = $params['project_id'];
        $brandColors = $params['brand_colors'];
        $typography = $params['typography'] ?? [];
        $spacingScale = $params['spacing_scale'] ?? 'default';
        $baseStyle = $params['base_style'] ?? 'default';
        $buttonStyle = $params['button_style'] ?? [];

        // Generate color palette
        $palette = $this->generateColorPalette($brandColors);

        // Build theme.json structure
        $themeJson = [
            '$schema' => 'https://schemas.wp.org/trunk/theme.json',
            'version' => 3,
            'settings' => [
                'color' => [
                    'palette' => $palette,
                ],
            ],
            'styles' => [
                'elements' => [],
            ],
        ];

        // Apply typography settings
        if (! empty($typography)) {
            $themeJson['styles']['elements']['heading'] = [
                'typography' => [
                    'fontFamily' => 'var(--wp--preset--font-family--'.($typography['heading_font'] ?? 'primary').')',
                ],
            ];
        }

        // Apply button styling
        if (! empty($buttonStyle)) {
            $themeJson['styles']['elements']['button'] = [];
            if (! empty($buttonStyle['radius'])) {
                $themeJson['styles']['elements']['button']['border'] = [
                    'radius' => $buttonStyle['radius'],
                ];
            }
            if (! empty($buttonStyle['text_transform'])) {
                $themeJson['styles']['elements']['button']['typography'] = [
                    'textTransform' => $buttonStyle['text_transform'],
                ];
            }
        }

        // Apply spacing scale
        if ($spacingScale !== 'default') {
            $themeJson['settings']['spacing'] = [
                'spacingSizes' => $this->getSpacingScale($spacingScale),
            ];
        }

        // Save to storage
        $path = "ollie-projects/{$projectId}/theme.json";
        $jsonContent = json_encode($themeJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        Storage::disk('local')->put($path, $jsonContent);

        return [
            'success' => true,
            'path' => $path,
            'theme_json' => $themeJson,
            'color_mapping' => [
                'primary' => $brandColors['primary'],
                'derived' => $palette,
            ],
        ];
    }

    private function generateColorPalette(array $brandColors): array
    {
        $primary = $brandColors['primary'];
        $secondary = $brandColors['secondary'] ?? $this->darken($primary, 20);
        $accent = $brandColors['accent'] ?? $this->lighten($primary, 40);

        return [
            [
                'name' => 'Brand',
                'slug' => 'primary',
                'color' => $primary,
            ],
            [
                'name' => 'Brand Accent',
                'slug' => 'primary-accent',
                'color' => $this->lighten($primary, 85),
            ],
            [
                'name' => 'Brand Alt',
                'slug' => 'primary-alt',
                'color' => $secondary,
            ],
            [
                'name' => 'Brand Alt Accent',
                'slug' => 'primary-alt-accent',
                'color' => $this->darken($secondary, 30),
            ],
            [
                'name' => 'Contrast',
                'slug' => 'main',
                'color' => '#1E1E26',
            ],
            [
                'name' => 'Contrast Accent',
                'slug' => 'main-accent',
                'color' => '#d4d4ec',
            ],
            [
                'name' => 'Base',
                'slug' => 'base',
                'color' => '#fff',
            ],
            [
                'name' => 'Base Accent',
                'slug' => 'secondary',
                'color' => '#545473',
            ],
            [
                'name' => 'Tint',
                'slug' => 'tertiary',
                'color' => '#f8f7fc',
            ],
            [
                'name' => 'Border Base',
                'slug' => 'border-light',
                'color' => '#E3E3F0',
            ],
            [
                'name' => 'Border Contrast',
                'slug' => 'border-dark',
                'color' => '#4E4E60',
            ],
        ];
    }

    private function lighten(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $rgb = array_map('hexdec', str_split($hex, 2));

        foreach ($rgb as &$color) {
            $color = min(255, $color + (255 - $color) * ($percent / 100));
            $color = dechex((int) round($color));
            $color = str_pad($color, 2, '0', STR_PAD_LEFT);
        }

        return '#'.implode('', $rgb);
    }

    private function darken(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        $rgb = array_map('hexdec', str_split($hex, 2));

        foreach ($rgb as &$color) {
            $color = max(0, $color - $color * ($percent / 100));
            $color = dechex((int) round($color));
            $color = str_pad($color, 2, '0', STR_PAD_LEFT);
        }

        return '#'.implode('', $rgb);
    }

    private function getSpacingScale(string $scale): array
    {
        $multiplier = match ($scale) {
            'compact' => 0.75,
            'spacious' => 1.25,
            default => 1.0,
        };

        return [
            ['name' => 'Small', 'slug' => 'small', 'size' => 'clamp('.(0.5 * $multiplier).'rem, 2.5vw, '.(1 * $multiplier).'rem)'],
            ['name' => 'Medium', 'slug' => 'medium', 'size' => 'clamp('.(1.5 * $multiplier).'rem, 4vw, '.(2 * $multiplier).'rem)'],
            ['name' => 'Large', 'slug' => 'large', 'size' => 'clamp('.(2 * $multiplier).'rem, 5vw, '.(3 * $multiplier).'rem)'],
            ['name' => 'Extra Large', 'slug' => 'x-large', 'size' => 'clamp('.(3 * $multiplier).'rem, 7vw, '.(5 * $multiplier).'rem)'],
            ['name' => '2xl', 'slug' => 'xx-large', 'size' => 'clamp('.(4 * $multiplier).'rem, 9vw, '.(7 * $multiplier).'rem)'],
        ];
    }
}
