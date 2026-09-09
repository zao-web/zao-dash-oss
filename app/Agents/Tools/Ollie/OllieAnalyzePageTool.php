<?php

namespace App\Agents\Tools\Ollie;

use App\Agents\Tools\BaseTool;
use App\Services\Ollie\OllieAIPageAnalyzer;
use App\Services\Ollie\OllieBlockGenerator;
use App\Services\Ollie\OllieContentExtractor;
use Illuminate\Support\Facades\Log;

class OllieAnalyzePageTool extends BaseTool
{
    public function category(): string
    {
        return 'ollie';
    }

    public function name(): string
    {
        return 'Analyze Page Layout';
    }

    public function description(): string
    {
        return 'Analyze a page URL to identify its sections (hero, features, testimonials, etc.), extract content, and generate WordPress block markup for migration. Uses AI-powered semantic analysis by default.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
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
                    'description' => 'Use AI-powered analysis (default: true). Falls back to regex if AI fails.',
                ],
                'colors' => [
                    'type' => 'object',
                    'description' => 'Brand colors to use in generated blocks',
                    'properties' => [
                        'primary' => ['type' => 'string'],
                        'secondary' => ['type' => 'string'],
                        'accent' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['url'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'url' => 'required|url',
            'page_name' => 'nullable|string|max:255',
            'generate_blocks' => 'nullable|boolean',
            'use_ai' => 'nullable|boolean',
            'colors' => 'nullable|array',
        ];
    }

    public function execute(array $params): array
    {
        $url = $params['url'];
        $pageName = $params['page_name'] ?? $this->inferPageName($url);
        $generateBlocks = $params['generate_blocks'] ?? false;
        $useAi = $params['use_ai'] ?? true; // AI is default
        $colors = $params['colors'] ?? [];

        try {
            $result = null;
            $analysisMethod = 'regex';

            // Try AI-powered analysis first (default)
            if ($useAi) {
                try {
                    $aiAnalyzer = new OllieAIPageAnalyzer;
                    $result = $aiAnalyzer->analyzePage($url);

                    if ($result['success']) {
                        $analysisMethod = 'ai';
                        Log::info('OllieAnalyzePageTool: AI analysis succeeded', [
                            'url' => $url,
                            'sections_count' => count($result['sections'] ?? []),
                        ]);
                    } else {
                        Log::warning('OllieAnalyzePageTool: AI analysis failed, falling back to regex', [
                            'url' => $url,
                            'error' => $result['error'] ?? 'Unknown error',
                        ]);
                        $result = null; // Clear to trigger fallback
                    }
                } catch (\Exception $e) {
                    Log::warning('OllieAnalyzePageTool: AI analysis exception, falling back to regex', [
                        'url' => $url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Fallback to regex-based extraction
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

            $response = [
                'success' => true,
                'url' => $url,
                'page_name' => $pageName,
                'analysis_method' => $analysisMethod,
                'sections' => $sections,
                'suggested_patterns' => $suggestedPatterns,
                'page_summary' => $result['page_summary'] ?? null,
                'migration_notes' => $result['migration_notes'] ?? null,
                'suggested_page_template' => $result['suggested_page_template'] ?? null,
                'metadata' => $result['metadata'] ?? [],
                'images' => $result['images'] ?? [],
                'summary' => $this->generateSummary($sections),
            ];

            if ($generateBlocks && ! empty($sections)) {
                $generator = new OllieBlockGenerator;
                if (! empty($colors)) {
                    $generator->setColors($colors);
                }
                $response['block_markup'] = $generator->generatePageFromSections($sections);
            }

            return $response;
        } catch (\Exception $e) {
            Log::error('OllieAnalyzePageTool failed', [
                'url' => $url,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
        $patterns = [];

        $patternMap = [
            'hero' => [
                'id' => 'heroes',
                'name' => 'Heroes',
                'icon' => '🏠',
                'patterns' => ['ollie/hero-dark', 'ollie/hero-light', 'ollie/hero-call-to-action-buttons'],
            ],
            'features' => [
                'id' => 'features',
                'name' => 'Features',
                'icon' => '✨',
                'patterns' => ['ollie/feature-boxes-with-button', 'ollie/feature-boxes-with-icon-dark', 'ollie/features-with-emojis'],
            ],
            'testimonials' => [
                'id' => 'testimonials',
                'name' => 'Testimonials',
                'icon' => '💬',
                'patterns' => ['ollie/testimonials-and-logos', 'ollie/testimonial-highlight', 'ollie/testimonials-with-big-text'],
            ],
            'pricing' => [
                'id' => 'pricing',
                'name' => 'Pricing',
                'icon' => '💰',
                'patterns' => ['ollie/pricing-table', 'ollie/pricing-table-3-column'],
            ],
            'cta' => [
                'id' => 'ctas',
                'name' => 'Call to Action',
                'icon' => '📣',
                'patterns' => ['ollie/text-call-to-action', 'ollie/text-call-to-action-buttons', 'ollie/card-big-text-call-to-action'],
            ],
            'team' => [
                'id' => 'team',
                'name' => 'Team',
                'icon' => '👥',
                'patterns' => ['ollie/team-members'],
            ],
            'blog' => [
                'id' => 'blog',
                'name' => 'Blog',
                'icon' => '📝',
                'patterns' => ['ollie/blog-post-columns', 'ollie/post-loop-grid-default'],
            ],
            'contact' => [
                'id' => 'contact',
                'name' => 'Contact',
                'icon' => '📧',
                'patterns' => ['ollie/contact-details', 'ollie/card-contact'],
            ],
            'gallery' => [
                'id' => 'gallery',
                'name' => 'Gallery',
                'icon' => '🖼️',
                'patterns' => [],
            ],
            'faq' => [
                'id' => 'faq',
                'name' => 'FAQ',
                'icon' => '❓',
                'patterns' => ['ollie/faq'],
            ],
        ];

        foreach ($sections as $section) {
            $type = $section['type'] ?? '';
            if (isset($patternMap[$type])) {
                $mapping = $patternMap[$type];
                $patterns[] = [
                    'section_type' => $type,
                    'pattern_category' => $mapping['id'],
                    'category_name' => $mapping['name'],
                    'icon' => $mapping['icon'],
                    'confidence' => $section['confidence'] ?? 0,
                    'suggested_patterns' => $mapping['patterns'],
                    'content' => $section['content'] ?? [],
                    'has_extracted_content' => ! empty($section['content']),
                ];
            }
        }

        return $patterns;
    }

    private function generateSummary(array $sections): string
    {
        if (empty($sections)) {
            return 'No specific sections detected. A generic page layout will be suggested.';
        }

        $types = array_map(fn ($s) => $s['type'] ?? 'unknown', $sections);
        $contentCount = count(array_filter($sections, fn ($s) => ! empty($s['content'])));

        return sprintf(
            'Detected %d sections (%s) with %d containing extractable content.',
            count($sections),
            implode(', ', $types),
            $contentCount
        );
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
