<?php

namespace App\Agents\Tools;

use App\Services\GravityForms\GravityFormsService;

class GfUpdateFormTool extends BaseTool
{
    public function category(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'Update Gravity Form';
    }

    public function description(): string
    {
        return 'Update an existing Gravity Form. Can update title, add/modify fields, or change settings.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'form_id' => [
                    'type' => 'integer',
                    'description' => 'The ID of the form to update',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'New form title (optional)',
                ],
                'is_active' => [
                    'type' => 'boolean',
                    'description' => 'Set form active/inactive status',
                ],
                'add_fields' => [
                    'type' => 'array',
                    'description' => 'New fields to add to the form',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'required' => ['type' => 'boolean'],
                            'choices' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'confirmation_message' => [
                    'type' => 'string',
                    'description' => 'Update the default confirmation message',
                ],
                'add_webhook' => [
                    'type' => 'boolean',
                    'description' => 'Add Zao Dash webhook notification if not already configured',
                ],
            ],
            'required' => ['form_id'],
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
            'form_id' => 'required|integer|min:1',
            'title' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'add_fields' => 'nullable|array',
            'confirmation_message' => 'nullable|string',
            'add_webhook' => 'nullable|boolean',
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
            $currentForm = $service->getForm($params['form_id']);
            $updates = [];
            $changes = [];

            if (! empty($params['title'])) {
                $updates['title'] = $params['title'];
                $changes[] = 'title updated';
            }

            if (isset($params['is_active'])) {
                $updates['is_active'] = $params['is_active'] ? '1' : '0';
                $changes[] = $params['is_active'] ? 'form activated' : 'form deactivated';
            }

            if (! empty($params['add_fields'])) {
                $existingFields = $currentForm['fields'] ?? [];
                $maxId = 0;
                foreach ($existingFields as $field) {
                    if ($field['id'] > $maxId) {
                        $maxId = $field['id'];
                    }
                }

                $newFields = $this->buildFields($service, $params['add_fields'], $maxId + 1);
                $updates['fields'] = array_merge($existingFields, $newFields);
                $changes[] = count($newFields).' fields added';
            }

            if (! empty($params['confirmation_message'])) {
                $confirmations = $currentForm['confirmations'] ?? [];
                foreach ($confirmations as $id => $conf) {
                    if ($conf['isDefault'] ?? false) {
                        $confirmations[$id]['message'] = $params['confirmation_message'];
                        break;
                    }
                }
                $updates['confirmations'] = $confirmations;
                $changes[] = 'confirmation message updated';
            }

            if (! empty($updates)) {
                $service->updateForm($params['form_id'], $updates);
            }

            if ($params['add_webhook'] ?? false) {
                $service->addWebhookNotification($params['form_id'], route('webhooks.gravity-forms'));
                $changes[] = 'webhook notification added';
            }

            if (empty($changes)) {
                return [
                    'success' => true,
                    'form_id' => $params['form_id'],
                    'message' => 'No changes requested.',
                ];
            }

            return [
                'success' => true,
                'form_id' => $params['form_id'],
                'changes' => $changes,
                'message' => 'Form updated: '.implode(', ', $changes),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function buildFields(GravityFormsService $service, array $fieldDefs, int $startId): array
    {
        $fields = [];
        $fieldId = $startId;

        foreach ($fieldDefs as $def) {
            $type = $def['type'];
            $label = $def['label'];
            $options = [
                'required' => $def['required'] ?? false,
                'placeholder' => $def['placeholder'] ?? '',
            ];

            $field = match ($type) {
                'text' => $service->textField($fieldId, $label, $options),
                'textarea' => $service->textareaField($fieldId, $label, $options),
                'email' => $service->emailField($fieldId, $label, $options),
                'phone' => $service->phoneField($fieldId, $label, $options),
                'website' => $service->websiteField($fieldId, $label, $options),
                'number' => $service->numberField($fieldId, $label, $options),
                'select' => $service->selectField($fieldId, $label, $def['choices'] ?? [], $options),
                'radio' => $service->radioField($fieldId, $label, $def['choices'] ?? [], $options),
                'checkbox' => $service->checkboxField($fieldId, $label, $def['choices'] ?? [], $options),
                'name' => $service->nameField($fieldId, $label, $options),
                'address' => $service->addressField($fieldId, $label, $options),
                'hidden' => $service->hiddenField($fieldId, $label, $def['default_value'] ?? '', $options),
                'html' => $service->htmlField($fieldId, $def['content'] ?? ''),
                'section' => $service->sectionField($fieldId, $label, $def['description'] ?? ''),
                default => $service->textField($fieldId, $label, $options),
            };

            $fields[] = $field;
            $fieldId++;
        }

        return $fields;
    }
}
