<?php

namespace App\Agents\Tools;

use App\Services\GravityForms\GravityFormsService;

class GfDeleteFormTool extends BaseTool
{
    public function category(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'Delete Gravity Form';
    }

    public function description(): string
    {
        return 'Delete (trash) a Gravity Form. The form will be moved to trash and can be restored from WordPress admin.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the form to delete',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Confirm deletion (must be true)',
                ],
            ],
            'required' => ['form_id', 'confirm'],
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'high';
    }

    protected function validationRules(): array
    {
        return [
            'form_id' => 'required|integer|min:1',
            'confirm' => 'required|boolean|accepted',
        ];
    }

    public function execute(array $params): array
    {
        if (! ($params['confirm'] ?? false)) {
            return [
                'success' => false,
                'error' => 'Deletion not confirmed. Set confirm: true to proceed.',
            ];
        }

        $service = app(GravityFormsService::class);

        if (! $service->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Gravity Forms API is not configured.',
            ];
        }

        try {
            $form = $service->getForm($params['form_id']);
            $title = $form['title'] ?? 'Unknown';

            $service->deleteForm($params['form_id']);

            return [
                'success' => true,
                'form_id' => $params['form_id'],
                'title' => $title,
                'message' => "Form '{$title}' (ID: {$params['form_id']}) has been moved to trash.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
