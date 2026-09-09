<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;
use App\Services\Ollie\OllieAIPageAnalyzer;
use App\Services\Ollie\OllieBlockGenerator;
use App\Services\Ollie\OllieContentExtractor;
use Illuminate\Support\Facades\Log;

class WebsiteBuilderAnalyzePagesTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Analyze Page Layout';
    }

    public function description(): string
    {
        return 'Analyze a page URL to identify its sections (hero, features, testimonials, etc.), extract content, and generate WordPress block markup. Stores analysis in project pages field.';
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
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the page to analyze',
                ],
                'page_name' => [
                    'type' => 'string',
                    'description' => 'Name of the page (for context)',
                ],
                'generate_blocks' => [
                    'type' => 'boolean',
                    'description' => 'Whether to generate WordPress block markup from extracted content',
                ],
                'use_ai' => [
                    'type' => 'boolean',
                    'description' => 'Use AI-powered analysis (default: true)',
                ],
                'colors' => [
                    'type' => 'object',
                    'description' => 'Brand colors to use in generated blocks',
                ],
            ],
            'required' => ['project_id', 'url'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'url' => 'required|url',
            'page_name' => 'nullable|string|max:255',
            'generate_blocks' => 'nullable|boolean',
            'use_ai' => 'nullable|boolean',
            'colors' => 'nullable|array',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $url = $params['url'];
        $pageName = $params['page_name'] ?? $this->inferPageName($url);
        $generateBlocks = $params['generate_blocks'] ?? false;
        $useAi = $params['use_ai'] ?? true;
        $colors = $params['colors'] ?? [];

        try {
            $result = null;
            $analysisMethod = 'regex';

            if ($useAi) {
                try {
                    $aiAnalyzer = new OllieAIPageAnalyzer;
                    $result = $aiAnalyzer->analyzePage($url);

                    if ($result['success']) {
                        $analysisMethod = 'ai';
                        Log::info('WebsiteBuilderAnalyzePagesTool: AI analysis succeeded', [
                            'url' => $url,
                            'sections_count' => count($result['sections'] ?? []),
                        ]);
                    } else {
                        $result = null;
                    }
                } catch (\Exception $e) {
                    Log::warning('WebsiteBuilderAnalyzePagesTool: AI analysis failed, falling back to regex', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $result || ! $result['success']) {
                $extractor = new OllieContentExtractor;
                $result = $extractor->extractFromUrl($url);
                $analysisMethod = 'regex';
            }

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Extraction failed',
                    'url' => $url,
                    'page_name' => $pageName,
                ];
            }

            $sections = $result['sections'] ?? [];
            $suggestedPatterns = $this->mapSectionsToPatterns($sections);

            $pageAnalysis = [
                'url' => $url,
                'name' => $pageName,
                'analysis_method' => $analysisMethod,
                'sections' => $sections,
                'suggested_patterns' => $suggestedPatterns,
                'page_summary' => $result['page_summary'] ?? null,
                'metadata' => $result['metadata'] ?? [],
                'images' => $result['images'] ?? [],
                'analyzed_at' => now()->toIso8601String(),
            ];

            if ($generateBlocks && ! empty($sections)) {
                $generator = new OllieBlockGenerator;
                if (! empty($colors)) {
                    $generator->setColors($colors);
                }
                $pageAnalysis['block_markup'] = $generator->generatePageFromSections($sections);
            }

            $pages = $project->pages ?? [];
            $pages[$pageName] = $pageAnalysis;

            $project->update([
                'pages' => $pages,
            ]);

            return [
                'success' => true,
                'project_id' => $project->id,
                'page_name' => $pageName,
                'analysis' => $pageAnalysis,
                'summary' => sprintf(
                    'Detected %d sections (%s) using %s analysis.',
                    count($sections),
                    implode(', ', array_column($sections, 'type')),
                    $analysisMethod
                ),
            ];
        } catch (\Exception $e) {
            Log::error('WebsiteBuilderAnalyzePagesTool failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Failed to analyze page: '.$e->getMessage(),
                'url' => $url,
                'page_name' => $pageName,
            ];
        }
    }

    private function mapSectionsToPatterns(array $sections): array
    {
        $patternMap = [
            'hero' => ['id' => 'heroes', 'patterns' => ['ollie/hero-dark', 'ollie/hero-light']],
            'features' => ['id' => 'features', 'patterns' => ['ollie/feature-boxes-with-button', 'ollie/features-with-emojis']],
            'testimonials' => ['id' => 'testimonials', 'patterns' => ['ollie/testimonials-and-logos', 'ollie/testimonial-highlight']],
            'pricing' => ['id' => 'pricing', 'patterns' => ['ollie/pricing-table', 'ollie/pricing-table-3-column']],
            'cta' => ['id' => 'ctas', 'patterns' => ['ollie/text-call-to-action', 'ollie/text-call-to-action-buttons']],
            'team' => ['id' => 'team', 'patterns' => ['ollie/team-members']],
            'blog' => ['id' => 'blog', 'patterns' => ['ollie/blog-post-columns', 'ollie/post-loop-grid-default']],
            'contact' => ['id' => 'contact', 'patterns' => ['ollie/contact-details', 'ollie/card-contact']],
            'faq' => ['id' => 'faq', 'patterns' => ['ollie/faq']],
        ];

        $patterns = [];

        foreach ($sections as $section) {
            $type = $section['type'] ?? '';
            if (isset($patternMap[$type])) {
                $mapping = $patternMap[$type];
                $patterns[] = [
                    'section_type' => $type,
                    'pattern_category' => $mapping['id'],
                    'suggested_patterns' => $mapping['patterns'],
                    'content' => $section['content'] ?? [],
                    'confidence' => $section['confidence'] ?? 0,
                ];
            }
        }

        return $patterns;
    }

    private function inferPageName(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        if ($path === '/' || $path === '') {
            return 'Home';
        }

        $path = trim($path, '/');
        $segments = explode('/', $path);
        $name = end($segments);
        $name = preg_replace('/\.(html?|php|aspx?)$/i', '', $name);
        $name = str_replace(['-', '_'], ' ', $name);

        return ucwords($name) ?: 'Page';
    }
}
