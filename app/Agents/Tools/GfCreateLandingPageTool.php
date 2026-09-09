<?php

namespace App\Agents\Tools;

use App\Services\GravityForms\GravityFormsService;

class GfCreateLandingPageTool extends BaseTool
{
    public function category(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'Create Form Landing Page';
    }

    public function description(): string
    {
        return 'Create a WordPress landing page with an embedded Gravity Form. Creates a focused page with heading, description, and the form ready for submissions.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form_id' => [
                    'type' => 'integer',
                    'description' => 'The Gravity Form ID to embed on the page',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Page title (shown in browser tab and as page heading)',
                ],
                'heading' => [
                    'type' => 'string',
                    'description' => 'Main heading displayed above the form (optional, defaults to title)',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Introductory text displayed between heading and form',
                ],
                'cta_text' => [
                    'type' => 'string',
                    'description' => 'Call-to-action text displayed just before the form (e.g., "Fill out the form below to get started")',
                ],
                'slug' => [
                    'type' => 'string',
                    'description' => 'URL slug for the page (e.g., "apply-now" creates /apply-now/)',
                ],
            ],
            'required' => ['form_id', 'title'],
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    protected function validationRules(): array
    {
        return [
            'form_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'heading' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'cta_text' => 'nullable|string',
            'slug' => 'nullable|string|max:100',
        ];
    }

    public function execute(array $params): array
    {
        $service = app(GravityFormsService::class);

        if (! $service->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Gravity Forms API is not configured.',
            ];
        }

        try {
            $form = $service->getForm($params['form_id']);

            if (empty($form['id'])) {
                return [
                    'success' => false,
                    'error' => "Form ID {$params['form_id']} not found.",
                ];
            }

            $result = $service->createFormLandingPage([
                'form_id' => $params['form_id'],
                'title' => $params['title'],
                'heading' => $params['heading'] ?? $params['title'],
                'description' => $params['description'] ?? '',
                'cta_text' => $params['cta_text'] ?? '',
                'slug' => $params['slug'] ?? null,
            ]);

            return [
                'success' => true,
                'page_id' => $result['page_id'],
                'page_url' => $result['page_url'],
                'form_id' => $result['form_id'],
                'form_title' => $form['title'],
                'message' => "Landing page created successfully at {$result['page_url']}",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
