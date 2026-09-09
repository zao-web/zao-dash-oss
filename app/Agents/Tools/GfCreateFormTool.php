<?php

namespace App\Agents\Tools;

use App\Services\GravityForms\GravityFormsService;

class GfCreateFormTool extends BaseTool
{
    public function category(): string
    {
        return 'forms';
    }

    public function name(): string
    {
        return 'Create Gravity Form';
    }

    public function description(): string
    {
        return 'Create a new Gravity Form on the Zao website with specified fields. Automatically configures webhook to send submissions to Zao Dash.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'The form title',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Optional form description shown to users',
                ],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Array of field definitions',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['text', 'textarea', 'email', 'phone', 'website', 'number', 'select', 'radio', 'checkbox', 'name', 'address', 'hidden', 'html', 'section'],
                                'description' => 'Field type',
                            ],
                            'label' => [
                                'type' => 'string',
                                'description' => 'Field label shown to users',
                            ],
                            'required' => [
                                'type' => 'boolean',
                                'description' => 'Whether the field is required',
                            ],
                            'choices' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Options for select/radio/checkbox fields',
                            ],
                            'placeholder' => [
                                'type' => 'string',
                                'description' => 'Placeholder text for text inputs',
                            ],
                            'content' => [
                                'type' => 'string',
                                'description' => 'HTML content for html field type',
                            ],
                            'description' => [
                                'type' => 'string',
                                'description' => 'Description for section field type',
                            ],
                            'default_value' => [
                                'type' => 'string',
                                'description' => 'Default value for hidden fields',
                            ],
                        ],
                        'required' => ['type', 'label'],
                    ],
                ],
                'configure_webhook' => [
                    'type' => 'boolean',
                    'description' => 'Whether to automatically configure Zao Dash webhook (default: true)',
                    'default' => true,
                ],
                'confirmation_message' => [
                    'type' => 'string',
                    'description' => 'Custom confirmation message after submission',
                ],
            ],
            'required' => ['title', 'fields'],
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
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'fields' => 'required|array|min:1',
            'fields.*.type' => 'required|string|in:text,textarea,email,phone,website,number,select,radio,checkbox,name,address,hidden,html,section',
            'fields.*.label' => 'required|string',
            'configure_webhook' => 'nullable|boolean',
            'confirmation_message' => 'nullable|string',
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
            $fields = $this->buildFields($service, $params['fields']);

            $formData = [
                'title' => $params['title'],
                'fields' => $fields,
            ];

            if (! empty($params['description'])) {
                $formData['description'] = $params['description'];
            }

            if (! empty($params['confirmation_message'])) {
                $formData['confirmations'] = [
                    uniqid() => [
                        'id' => uniqid(),
                        'name' => 'Default Confirmation',
                        'isDefault' => true,
                        'type' => 'message',
                        'message' => $params['confirmation_message'],
                    ],
                ];
            }

            $configureWebhook = $params['configure_webhook'] ?? true;

            if ($configureWebhook) {
                $form = $service->createFormWithWebhook($formData);
            } else {
                $form = $service->createForm($formData);
            }

            return [
                'success' => true,
                'form_id' => $form['id'],
                'title' => $form['title'],
                'field_count' => count($form['fields'] ?? []),
                'webhook_configured' => $configureWebhook,
                'edit_url' => config('services.gravity_forms.site_url').'/wp-admin/admin.php?page=gf_edit_forms&id='.$form['id'],
                'message' => "Form '{$form['title']}' created successfully with ID {$form['id']}.",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function buildFields(GravityFormsService $service, array $fieldDefs): array
    {
        $fields = [];
        $fieldId = 1;

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
