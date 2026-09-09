<?php

namespace App\Agents\Tools;

use App\Services\GravityForms\GravityFormsService;

class GfGetFormTool extends BaseTool
{
    public function category(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'Get Gravity Form Details';
    }

    public function description(): string
    {
        return 'Get detailed information about a specific Gravity Form including all fields, confirmations, and notifications.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the form to retrieve',
                ],
                'include_entries' => [
                    'type' => 'boolean',
                    'description' => 'Include recent entry count',
                    'default' => false,
                ],
            ],
            'required' => ['form_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'form_id' => 'required|integer|min:1',
            'include_entries' => 'nullable|boolean',
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

            $fields = array_map(function ($field) {
                $fieldInfo = [
                    'id' => $field['id'],
                    'type' => $field['type'],
                    'label' => $field['label'] ?? '',
                    'is_required' => $field['isRequired'] ?? false,
                ];

                if (! empty($field['choices'])) {
                    $fieldInfo['choices'] = array_map(fn ($c) => $c['text'] ?? $c['value'], $field['choices']);
                }

                if (! empty($field['inputs'])) {
                    $fieldInfo['inputs'] = array_map(fn ($i) => [
                        'id' => $i['id'],
                        'label' => $i['label'] ?? '',
                    ], $field['inputs']);
                }

                return $fieldInfo;
            }, $form['fields'] ?? []);

            $result = [
                'success' => true,
                'form' => [
                    'id' => $form['id'],
                    'title' => $form['title'],
                    'is_active' => ($form['is_active'] ?? '1') === '1',
                    'date_created' => $form['date_created'] ?? null,
                    'fields' => $fields,
                    'confirmations' => array_values($form['confirmations'] ?? []),
                    'notification_count' => count($form['notifications'] ?? []),
                ],
            ];

            if ($params['include_entries'] ?? false) {
                try {
                    $entries = $service->getEntries($params['form_id'], ['paging' => ['page_size' => 1]]);
                    $result['form']['entry_count'] = $entries['total_count'] ?? 0;
                } catch (\Exception $e) {
                    $result['form']['entry_count'] = 'unknown';
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
