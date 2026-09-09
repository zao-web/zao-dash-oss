<?php

namespace App\Services\GravityForms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with Gravity Forms REST API v2.
 *
 * Handles form CRUD operations, entry management, and webhook configuration.
 *
 * @see https://docs.gravityforms.com/rest-api-v2/
 */
class GravityFormsService
{
    protected string $siteUrl;

    protected string $consumerKey;

    protected string $consumerSecret;

    protected string $wpUser;

    protected string $wpPassword;

    public function __construct()
    {
        $this->siteUrl = rtrim(config('services.gravity_forms.site_url', ''), '/');
        $this->consumerKey = config('services.gravity_forms.consumer_key', '');
        $this->consumerSecret = config('services.gravity_forms.consumer_secret', '');
        $this->wpUser = config('services.gravity_forms.wp_user') ?? '';
        $this->wpPassword = config('services.gravity_forms.wp_password') ?? '';
    }

    public function isWordPressConfigured(): bool
    {
        return ! empty($this->siteUrl)
            && ! empty($this->wpUser)
            && ! empty($this->wpPassword);
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->siteUrl)
            && ! empty($this->consumerKey)
            && ! empty($this->consumerSecret);
    }

    /**
     * Get the configured HTTP client for Gravity Forms API.
     */
    protected function client(): PendingRequest
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }

    /**
     * Get the configured HTTP client for WordPress REST API.
     */
    protected function wpClient(): PendingRequest
    {
        return Http::withBasicAuth($this->wpUser, $this->wpPassword)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }

    /**
     * Get the API base URL.
     */
    protected function apiUrl(string $path = ''): string
    {
        return $this->siteUrl.'/wp-json/gf/v2'.$path;
    }

    /**
     * Test connection to Gravity Forms API.
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client()->get($this->apiUrl('/forms'));

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to Gravity Forms API',
                    'form_count' => count($response->json() ?? []),
                ];
            }

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * List all forms.
     *
     * @return array<int, array{id: int, title: string, ...}>
     */
    public function listForms(): array
    {
        $response = $this->client()->get($this->apiUrl('/forms'));

        if (! $response->successful()) {
            Log::error('GravityFormsService::listForms failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to list forms: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Get a single form by ID.
     *
     * @return array{id: int, title: string, fields: array, ...}
     */
    public function getForm(int $formId): array
    {
        $response = $this->client()->get($this->apiUrl("/forms/{$formId}"));

        if (! $response->successful()) {
            Log::error('GravityFormsService::getForm failed', [
                'form_id' => $formId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to get form: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a new form.
     *
     * @param  array{title: string, fields: array, ...}  $formData
     * @return array{id: int, title: string, ...}
     */
    public function createForm(array $formData): array
    {
        // Ensure forms are active by default
        $formData = array_merge(['is_active' => '1'], $formData);

        $response = $this->client()->post($this->apiUrl('/forms'), $formData);

        if (! $response->successful()) {
            Log::error('GravityFormsService::createForm failed', [
                'form_data' => $formData,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to create form: '.$response->body());
        }

        $form = $response->json();

        Log::info('GravityFormsService::createForm success', [
            'form_id' => $form['id'] ?? null,
            'title' => $form['title'] ?? null,
        ]);

        return $form;
    }

    /**
     * Update an existing form.
     *
     * @param  array{title?: string, fields?: array, ...}  $formData
     * @return array{id: int, title: string, ...}
     */
    public function updateForm(int $formId, array $formData): array
    {
        $response = $this->client()->put($this->apiUrl("/forms/{$formId}"), $formData);

        if (! $response->successful()) {
            Log::error('GravityFormsService::updateForm failed', [
                'form_id' => $formId,
                'form_data' => $formData,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to update form: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Delete (trash) a form.
     */
    public function deleteForm(int $formId): bool
    {
        $response = $this->client()->delete($this->apiUrl("/forms/{$formId}"));

        if (! $response->successful()) {
            Log::error('GravityFormsService::deleteForm failed', [
                'form_id' => $formId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to delete form: '.$response->body());
        }

        return true;
    }

    /**
     * Get entries for a form.
     *
     * @param  array{paging?: array, search?: array, sorting?: array}  $params
     * @return array{entries: array, total_count: int, ...}
     */
    public function getEntries(int $formId, array $params = []): array
    {
        $response = $this->client()->get($this->apiUrl("/forms/{$formId}/entries"), $params);

        if (! $response->successful()) {
            Log::error('GravityFormsService::getEntries failed', [
                'form_id' => $formId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to get entries: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Get a single entry.
     */
    public function getEntry(int $entryId): array
    {
        $response = $this->client()->get($this->apiUrl("/entries/{$entryId}"));

        if (! $response->successful()) {
            throw new \Exception('Failed to get entry: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a form with webhook notification configured for Zao Dash.
     *
     * @param  array{title: string, fields: array, ...}  $formData
     * @param  string|null  $webhookUrl  Custom webhook URL (defaults to Zao Dash webhook)
     * @return array{id: int, title: string, ...}
     */
    public function createFormWithWebhook(array $formData, ?string $webhookUrl = null): array
    {
        $form = $this->createForm($formData);
        $formId = $form['id'];

        $webhookUrl = $webhookUrl ?? route('webhooks.gravity-forms');

        $this->addWebhookNotification($formId, $webhookUrl);

        return $this->getForm($formId);
    }

    /**
     * Add a webhook notification to an existing form.
     *
     * Uses the Webhooks Add-On feed endpoint if available,
     * otherwise adds via form notifications.
     */
    public function addWebhookNotification(int $formId, string $webhookUrl): array
    {
        $form = $this->getForm($formId);

        $notificationId = uniqid();
        $notifications = $form['notifications'] ?? [];

        $notifications[$notificationId] = [
            'id' => $notificationId,
            'isActive' => true,
            'name' => 'Zao Dash Webhook',
            'event' => 'form_submission',
            'toType' => 'webhook',
            'to' => $webhookUrl,
            'from' => '{admin_email}',
            'fromName' => '',
            'replyTo' => '',
            'routing' => null,
            'conditionalLogic' => null,
            'message' => '',
            'subject' => '',
            'disableAutoformat' => false,
            'enableAttachments' => false,
        ];

        $form['notifications'] = $notifications;

        return $this->updateForm($formId, $form);
    }

    /**
     * Build a field definition for common field types.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildField(string $type, int $id, string $label, array $options = []): array
    {
        $field = array_merge([
            'type' => $type,
            'id' => $id,
            'label' => $label,
            'isRequired' => $options['required'] ?? false,
            'visibility' => 'visible',
            'size' => $options['size'] ?? 'large',
        ], $options);

        unset($field['required'], $field['size']);
        $field['size'] = $options['size'] ?? 'large';

        return $field;
    }

    /**
     * Build a text field.
     */
    public function textField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('text', $id, $label, array_merge([
            'placeholder' => $options['placeholder'] ?? '',
            'maxLength' => $options['maxLength'] ?? '',
        ], $options));
    }

    /**
     * Build an email field.
     */
    public function emailField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('email', $id, $label, $options);
    }

    /**
     * Build a phone field.
     */
    public function phoneField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('phone', $id, $label, array_merge([
            'phoneFormat' => $options['phoneFormat'] ?? 'standard',
        ], $options));
    }

    /**
     * Build a textarea (paragraph) field.
     */
    public function textareaField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('textarea', $id, $label, array_merge([
            'placeholder' => $options['placeholder'] ?? '',
            'maxLength' => $options['maxLength'] ?? '',
            'useRichTextEditor' => $options['useRichTextEditor'] ?? false,
        ], $options));
    }

    /**
     * Build a select (dropdown) field.
     *
     * @param  array<array{text: string, value: string}>  $choices
     */
    public function selectField(int $id, string $label, array $choices, array $options = []): array
    {
        return $this->buildField('select', $id, $label, array_merge([
            'choices' => $this->formatChoices($choices),
            'placeholder' => $options['placeholder'] ?? 'Select...',
            'enableEnhancedUI' => $options['enableEnhancedUI'] ?? 0,
        ], $options));
    }

    /**
     * Build a radio button field.
     *
     * @param  array<array{text: string, value: string}>  $choices
     */
    public function radioField(int $id, string $label, array $choices, array $options = []): array
    {
        return $this->buildField('radio', $id, $label, array_merge([
            'choices' => $this->formatChoices($choices),
            'enableOtherChoice' => $options['enableOtherChoice'] ?? false,
        ], $options));
    }

    /**
     * Build a checkbox field.
     *
     * @param  array<array{text: string, value: string}>  $choices
     */
    public function checkboxField(int $id, string $label, array $choices, array $options = []): array
    {
        $formattedChoices = $this->formatChoices($choices);

        $inputs = [];
        foreach ($formattedChoices as $index => $choice) {
            $inputs[] = [
                'id' => $id.'.'.($index + 1),
                'label' => $choice['text'],
                'name' => '',
            ];
        }

        return $this->buildField('checkbox', $id, $label, array_merge([
            'choices' => $formattedChoices,
            'inputs' => $inputs,
            'enableSelectAll' => $options['enableSelectAll'] ?? false,
        ], $options));
    }

    /**
     * Build a name field (compound: first + last).
     */
    public function nameField(int $id, string $label, array $options = []): array
    {
        $format = $options['nameFormat'] ?? 'simple';

        $inputs = match ($format) {
            'advanced' => [
                [
                    'id' => "{$id}.2",
                    'label' => 'Prefix',
                    'name' => '',
                    'isHidden' => true,
                    'choices' => [
                        ['text' => 'Mr.', 'value' => 'Mr.', 'isSelected' => false, 'price' => ''],
                        ['text' => 'Mrs.', 'value' => 'Mrs.', 'isSelected' => false, 'price' => ''],
                        ['text' => 'Miss', 'value' => 'Miss', 'isSelected' => false, 'price' => ''],
                        ['text' => 'Ms.', 'value' => 'Ms.', 'isSelected' => false, 'price' => ''],
                        ['text' => 'Dr.', 'value' => 'Dr.', 'isSelected' => false, 'price' => ''],
                        ['text' => 'Prof.', 'value' => 'Prof.', 'isSelected' => false, 'price' => ''],
                        ['text' => 'Rev.', 'value' => 'Rev.', 'isSelected' => false, 'price' => ''],
                    ],
                    'inputType' => 'radio',
                ],
                ['id' => "{$id}.3", 'label' => 'First', 'name' => '', 'customLabel' => '', 'placeholder' => ''],
                ['id' => "{$id}.4", 'label' => 'Middle', 'name' => '', 'isHidden' => true],
                ['id' => "{$id}.6", 'label' => 'Last', 'name' => '', 'customLabel' => '', 'placeholder' => ''],
                ['id' => "{$id}.8", 'label' => 'Suffix', 'name' => '', 'isHidden' => true],
            ],
            default => [
                ['id' => "{$id}.3", 'label' => 'First', 'name' => '', 'customLabel' => '', 'placeholder' => ''],
                ['id' => "{$id}.6", 'label' => 'Last', 'name' => '', 'customLabel' => '', 'placeholder' => ''],
            ],
        };

        return $this->buildField('name', $id, $label, array_merge([
            'nameFormat' => $format,
            'inputs' => $inputs,
            'subLabelPlacement' => 'hidden_label',
            'choices' => '',
        ], $options));
    }

    /**
     * Build an address field.
     */
    public function addressField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('address', $id, $label, array_merge([
            'addressType' => $options['addressType'] ?? 'united_states',
            'inputs' => [
                ['id' => "{$id}.1", 'label' => 'Street Address', 'name' => ''],
                ['id' => "{$id}.2", 'label' => 'Address Line 2', 'name' => ''],
                ['id' => "{$id}.3", 'label' => 'City', 'name' => ''],
                ['id' => "{$id}.4", 'label' => 'State / Province', 'name' => ''],
                ['id' => "{$id}.5", 'label' => 'ZIP / Postal Code', 'name' => ''],
                ['id' => "{$id}.6", 'label' => 'Country', 'name' => '', 'isHidden' => true],
            ],
        ], $options));
    }

    /**
     * Build a website field.
     */
    public function websiteField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('website', $id, $label, array_merge([
            'placeholder' => $options['placeholder'] ?? 'https://',
        ], $options));
    }

    /**
     * Build a number field.
     */
    public function numberField(int $id, string $label, array $options = []): array
    {
        return $this->buildField('number', $id, $label, array_merge([
            'numberFormat' => $options['numberFormat'] ?? 'decimal_dot',
            'rangeMin' => $options['rangeMin'] ?? '',
            'rangeMax' => $options['rangeMax'] ?? '',
        ], $options));
    }

    /**
     * Build an HTML content block.
     */
    public function htmlField(int $id, string $content, array $options = []): array
    {
        return [
            'type' => 'html',
            'id' => $id,
            'label' => '',
            'content' => $content,
            'visibility' => 'visible',
        ];
    }

    /**
     * Build a section break field.
     */
    public function sectionField(int $id, string $label, string $description = '', array $options = []): array
    {
        return [
            'type' => 'section',
            'id' => $id,
            'label' => $label,
            'description' => $description,
            'visibility' => 'visible',
        ];
    }

    /**
     * Build a hidden field.
     */
    public function hiddenField(int $id, string $label, string $defaultValue = '', array $options = []): array
    {
        return $this->buildField('hidden', $id, $label, array_merge([
            'defaultValue' => $defaultValue,
        ], $options));
    }

    /**
     * Format choices for select/radio/checkbox fields.
     *
     * @param  array<string|array{text: string, value?: string}>  $choices
     * @return array<array{text: string, value: string, isSelected: bool}>
     */
    protected function formatChoices(array $choices): array
    {
        return array_map(function ($choice, $index) {
            if (is_string($choice)) {
                return [
                    'text' => $choice,
                    'value' => $choice,
                    'isSelected' => $index === 0,
                ];
            }

            return [
                'text' => $choice['text'],
                'value' => $choice['value'] ?? $choice['text'],
                'isSelected' => $choice['isSelected'] ?? ($index === 0),
            ];
        }, $choices, array_keys($choices));
    }

    // =========================================================================
    // WordPress REST API - Pages
    // =========================================================================

    /**
     * Get the WordPress REST API URL.
     */
    protected function wpApiUrl(string $path = ''): string
    {
        return $this->siteUrl.'/wp-json/wp/v2'.$path;
    }

    /**
     * List WordPress pages.
     *
     * @return array<int, array{id: int, title: array, slug: string, ...}>
     */
    public function listPages(int $perPage = 20, int $page = 1): array
    {
        $response = $this->wpClient()->get($this->wpApiUrl('/pages'), [
            'per_page' => $perPage,
            'page' => $page,
        ]);

        if (! $response->successful()) {
            Log::error('GravityFormsService::listPages failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to list pages: '.$response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Get a WordPress page by ID.
     *
     * @return array{id: int, title: array, content: array, slug: string, ...}
     */
    public function getPage(int $pageId): array
    {
        $response = $this->wpClient()->get($this->wpApiUrl("/pages/{$pageId}"));

        if (! $response->successful()) {
            Log::error('GravityFormsService::getPage failed', [
                'page_id' => $pageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to get page: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a WordPress page.
     *
     * @param  array{title: string, content: string, status?: string, slug?: string, ...}  $pageData
     * @return array{id: int, title: array, link: string, ...}
     */
    public function createPage(array $pageData): array
    {
        // Default to published status
        $pageData = array_merge(['status' => 'publish'], $pageData);

        $response = $this->wpClient()->post($this->wpApiUrl('/pages'), $pageData);

        if (! $response->successful()) {
            Log::error('GravityFormsService::createPage failed', [
                'page_data' => $pageData,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to create page: '.$response->body());
        }

        $page = $response->json();

        Log::info('GravityFormsService::createPage success', [
            'page_id' => $page['id'] ?? null,
            'title' => $page['title']['rendered'] ?? null,
            'link' => $page['link'] ?? null,
        ]);

        return $page;
    }

    /**
     * Update a WordPress page.
     *
     * @param  array{title?: string, content?: string, status?: string, ...}  $pageData
     * @return array{id: int, title: array, link: string, ...}
     */
    public function updatePage(int $pageId, array $pageData): array
    {
        $response = $this->wpClient()->post($this->wpApiUrl("/pages/{$pageId}"), $pageData);

        if (! $response->successful()) {
            Log::error('GravityFormsService::updatePage failed', [
                'page_id' => $pageId,
                'page_data' => $pageData,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to update page: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Delete a WordPress page.
     */
    public function deletePage(int $pageId, bool $force = false): array
    {
        $response = $this->wpClient()->delete($this->wpApiUrl("/pages/{$pageId}"), [
            'force' => $force,
        ]);

        if (! $response->successful()) {
            Log::error('GravityFormsService::deletePage failed', [
                'page_id' => $pageId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to delete page: '.$response->body());
        }

        return $response->json();
    }

    /**
     * Create a landing page with a Gravity Form embedded.
     *
     * @param  array{title: string, description?: string, form_id: int, slug?: string, heading?: string, cta_text?: string}  $options
     * @return array{page_id: int, page_url: string, form_id: int}
     */
    public function createFormLandingPage(array $options): array
    {
        $formId = $options['form_id'];
        $title = $options['title'];
        $description = $options['description'] ?? '';
        $heading = $options['heading'] ?? $title;
        $ctaText = $options['cta_text'] ?? '';

        // Build content with Gravity Forms shortcode
        $content = $this->buildLandingPageContent($formId, $heading, $description, $ctaText);

        $pageData = [
            'title' => $title,
            'content' => $content,
            'status' => 'publish',
        ];

        if (isset($options['slug'])) {
            $pageData['slug'] = $options['slug'];
        }

        $page = $this->createPage($pageData);

        return [
            'page_id' => $page['id'],
            'page_url' => $page['link'],
            'form_id' => $formId,
            'title' => $page['title']['rendered'] ?? $title,
        ];
    }

    /**
     * Build landing page HTML content with form shortcode.
     */
    protected function buildLandingPageContent(int $formId, string $heading, string $description, string $ctaText): string
    {
        $parts = [];

        if ($heading) {
            $parts[] = "<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">{$heading}</h1>\n<!-- /wp:heading -->";
        }

        if ($description) {
            $parts[] = "<!-- wp:paragraph -->\n<p>{$description}</p>\n<!-- /wp:paragraph -->";
        }

        if ($ctaText) {
            $parts[] = "<!-- wp:paragraph -->\n<p><strong>{$ctaText}</strong></p>\n<!-- /wp:paragraph -->";
        }

        // Gravity Forms shortcode
        $parts[] = "<!-- wp:gravityforms/form {\"formId\":\"{$formId}\"} -->\n[gravityform id=\"{$formId}\" title=\"false\" description=\"false\" ajax=\"true\"]\n<!-- /wp:gravityforms/form -->";

        return implode("\n\n", $parts);
    }

    /**
     * Generate a Gravity Forms shortcode.
     */
    public function formShortcode(int $formId, array $options = []): string
    {
        $attrs = [
            'id' => $formId,
            'title' => $options['title'] ?? 'false',
            'description' => $options['description'] ?? 'false',
            'ajax' => $options['ajax'] ?? 'true',
        ];

        if (isset($options['tabindex'])) {
            $attrs['tabindex'] = $options['tabindex'];
        }

        if (isset($options['field_values'])) {
            $attrs['field_values'] = $options['field_values'];
        }

        $attrString = collect($attrs)
            ->map(fn ($value, $key) => "{$key}=\"{$value}\"")
            ->implode(' ');

        return "[gravityform {$attrString}]";
    }
}
