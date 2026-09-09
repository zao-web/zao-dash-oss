<?php

namespace App\Agents\Tools;

use App\Services\GravityForms\GravityFormsService;

class GfListFormsTool extends BaseTool
{
    public function category(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'List Gravity Forms';
    }

    public function description(): string
    {
        return 'List all Gravity Forms on the Zao website. Returns form IDs, titles, entry counts, and active status.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_inactive' => [
                    'type' => 'boolean',
                    'description' => 'Include inactive/trashed forms in the list',
                    'default' => false,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): array
    {
        $service = app(GravityFormsService::class);

        if (! $service->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Gravity Forms API is not configured. Check GRAVITY_FORMS_CONSUMER_KEY and GRAVITY_FORMS_CONSUMER_SECRET.',
            ];
        }

        try {
            $forms = $service->listForms();
            $includeInactive = $params['include_inactive'] ?? false;

            $result = [];
            foreach ($forms as $form) {
                $isActive = ($form['is_active'] ?? '1') === '1';
                $isTrashed = ($form['is_trash'] ?? '0') === '1';

                if (! $includeInactive && (! $isActive || $isTrashed)) {
                    continue;
                }

                $result[] = [
                    'id' => $form['id'],
                    'title' => $form['title'],
                    'is_active' => $isActive,
                    'is_trash' => $isTrashed,
                    'field_count' => count($form['fields'] ?? []),
                    'date_created' => $form['date_created'] ?? null,
                ];
            }

            return [
                'success' => true,
                'forms' => $result,
                'total' => count($result),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
