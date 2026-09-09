<?php

namespace App\Mcp\Tools;

use App\Services\GravityForms\GravityFormsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GfCreateFormTool extends Tool
{
    protected string $name = 'gf-create-form';

    protected string $title = 'Create Gravity Form';

    protected string $description = 'Create a new Gravity Form with specified fields. Automatically configures webhook for Zao Dash.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'fields' => 'required|array|min:1',
            'fields.*.type' => 'required|string',
            'fields.*.label' => 'required|string',
            'configure_webhook' => 'nullable|boolean',
        ]);

        $service = app(GravityFormsService::class);

        if (! $service->isConfigured()) {
            return Response::structured([
                'success' => false,
                'error' => 'Gravity Forms API is not configured.',
            ]);
        }

        try {
            $fields = $this->buildFields($service, $validated['fields']);

            $formData = [
                'title' => $validated['title'],
                'fields' => $fields,
            ];

            $configureWebhook = $validated['configure_webhook'] ?? true;

            if ($configureWebhook) {
                $form = $service->createFormWithWebhook($formData);
            } else {
                $form = $service->createForm($formData);
            }

            return Response::structured([
                'success' => true,
                'form_id' => $form['id'],
                'title' => $form['title'],
                'field_count' => count($form['fields'] ?? []),
                'webhook_configured' => $configureWebhook,
                'message' => "Form '{$form['title']}' created with ID {$form['id']}.",
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Form title'),
            'fields' => $schema->array()->items(
                $schema->object([
                    'type' => $schema->string()->required()->enum([
                        'text', 'textarea', 'email', 'phone', 'website', 'number',
                        'select', 'radio', 'checkbox', 'name', 'address', 'hidden', 'html', 'section',
                    ])->description('Field type'),
                    'label' => $schema->string()->required()->description('Field label'),
                    'required' => $schema->boolean()->description('Whether field is required'),
                    'placeholder' => $schema->string()->description('Placeholder text'),
                    'choices' => $schema->array()->items($schema->string())->description('Choices for select/radio/checkbox fields'),
                    'default_value' => $schema->string()->description('Default value for hidden fields'),
                    'content' => $schema->string()->description('HTML content for html fields'),
                    'description' => $schema->string()->description('Description for section fields'),
                ])
            )->required()->description('Array of field definitions with type and label'),
            'configure_webhook' => $schema->boolean()->description('Auto-configure Zao Dash webhook (default: true)'),
        ];
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
