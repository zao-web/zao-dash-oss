<?php

use App\Services\GravityForms\GravityFormsService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.gravity_forms.site_url' => 'https://example.com',
        'services.gravity_forms.consumer_key' => 'ck_test_key',
        'services.gravity_forms.consumer_secret' => 'cs_test_secret',
        'services.gravity_forms.wp_user' => 'test_user',
        'services.gravity_forms.wp_password' => 'test_app_password',
    ]);
});

describe('GravityFormsService configuration', function () {
    it('reports configured when all credentials are set', function () {
        $service = new GravityFormsService;

        expect($service->isConfigured())->toBeTrue();
    });

    it('reports not configured when credentials are missing', function () {
        config(['services.gravity_forms.consumer_key' => '']);

        $service = new GravityFormsService;

        expect($service->isConfigured())->toBeFalse();
    });
});

describe('GravityFormsService::listForms', function () {
    it('returns list of forms from API', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms' => Http::response([
                ['id' => 1, 'title' => 'Contact Form', 'fields' => [], 'is_active' => '1'],
                ['id' => 2, 'title' => 'Lead Capture', 'fields' => [['id' => 1]], 'is_active' => '1'],
            ], 200),
        ]);

        $service = new GravityFormsService;
        $forms = $service->listForms();

        expect($forms)->toHaveCount(2);
        expect($forms[0]['title'])->toBe('Contact Form');
        expect($forms[1]['title'])->toBe('Lead Capture');
    });

    it('throws exception on API failure', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $service = new GravityFormsService;

        expect(fn () => $service->listForms())->toThrow(Exception::class);
    });
});

describe('GravityFormsService::getForm', function () {
    it('returns form details by ID', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms/1' => Http::response([
                'id' => 1,
                'title' => 'Contact Form',
                'fields' => [
                    ['id' => 1, 'type' => 'text', 'label' => 'Name'],
                    ['id' => 2, 'type' => 'email', 'label' => 'Email'],
                ],
                'confirmations' => [],
                'notifications' => [],
            ], 200),
        ]);

        $service = new GravityFormsService;
        $form = $service->getForm(1);

        expect($form['id'])->toBe(1);
        expect($form['title'])->toBe('Contact Form');
        expect($form['fields'])->toHaveCount(2);
    });
});

describe('GravityFormsService::createForm', function () {
    it('creates a new form via API', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms' => Http::response([
                'id' => 5,
                'title' => 'New Form',
                'fields' => [
                    ['id' => 1, 'type' => 'text', 'label' => 'Name'],
                ],
            ], 200),
        ]);

        $service = new GravityFormsService;
        $form = $service->createForm([
            'title' => 'New Form',
            'fields' => [
                ['id' => 1, 'type' => 'text', 'label' => 'Name'],
            ],
        ]);

        expect($form['id'])->toBe(5);
        expect($form['title'])->toBe('New Form');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/wp-json/gf/v2/forms'
                && $request->method() === 'POST'
                && $request['title'] === 'New Form'
                && $request['is_active'] === '1'; // Forms should be active by default
        });
    });
});

describe('GravityFormsService::updateForm', function () {
    it('updates an existing form', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms/1' => Http::response([
                'id' => 1,
                'title' => 'Updated Form Title',
            ], 200),
        ]);

        $service = new GravityFormsService;
        $form = $service->updateForm(1, ['title' => 'Updated Form Title']);

        expect($form['title'])->toBe('Updated Form Title');

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && $request['title'] === 'Updated Form Title';
        });
    });
});

describe('GravityFormsService::deleteForm', function () {
    it('deletes a form by ID', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms/1' => Http::response(['deleted' => true], 200),
        ]);

        $service = new GravityFormsService;
        $result = $service->deleteForm(1);

        expect($result)->toBeTrue();

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE';
        });
    });
});

describe('GravityFormsService field builders', function () {
    it('builds a text field correctly', function () {
        $service = new GravityFormsService;
        $field = $service->textField(1, 'Full Name', ['required' => true]);

        expect($field['type'])->toBe('text');
        expect($field['id'])->toBe(1);
        expect($field['label'])->toBe('Full Name');
        expect($field['isRequired'])->toBeTrue();
    });

    it('builds an email field correctly', function () {
        $service = new GravityFormsService;
        $field = $service->emailField(2, 'Email Address');

        expect($field['type'])->toBe('email');
        expect($field['label'])->toBe('Email Address');
    });

    it('builds a select field with choices', function () {
        $service = new GravityFormsService;
        $field = $service->selectField(3, 'How did you hear about us?', [
            'Google',
            'Friend',
            'Social Media',
        ]);

        expect($field['type'])->toBe('select');
        expect($field['choices'])->toHaveCount(3);
        expect($field['choices'][0]['text'])->toBe('Google');
        expect($field['choices'][0]['value'])->toBe('Google');
    });

    it('builds a checkbox field with inputs', function () {
        $service = new GravityFormsService;
        $field = $service->checkboxField(4, 'Services interested in', [
            'Web Development',
            'AI Integration',
            'Consulting',
        ]);

        expect($field['type'])->toBe('checkbox');
        expect($field['choices'])->toHaveCount(3);
        expect($field['inputs'])->toHaveCount(3);
        expect($field['inputs'][0]['id'])->toBe('4.1');
    });

    it('builds a name field with first/last inputs', function () {
        $service = new GravityFormsService;
        $field = $service->nameField(5, 'Your Name');

        expect($field['type'])->toBe('name');
        expect($field['inputs'])->toHaveCount(2);
        expect($field['inputs'][0]['label'])->toBe('First');
        expect($field['inputs'][1]['label'])->toBe('Last');
    });

    it('builds a section field', function () {
        $service = new GravityFormsService;
        $field = $service->sectionField(6, 'Contact Information', 'Please provide your details below.');

        expect($field['type'])->toBe('section');
        expect($field['label'])->toBe('Contact Information');
        expect($field['description'])->toBe('Please provide your details below.');
    });
});

describe('GravityFormsService::createFormWithWebhook', function () {
    it('creates form and adds webhook notification', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms' => Http::response([
                'id' => 10,
                'title' => 'Test Form',
                'fields' => [],
                'notifications' => [],
            ], 200),
            'example.com/wp-json/gf/v2/forms/10' => Http::response([
                'id' => 10,
                'title' => 'Test Form',
                'fields' => [],
                'notifications' => ['abc' => ['name' => 'Zao Dash Webhook']],
            ], 200),
        ]);

        $service = new GravityFormsService;
        $form = $service->createFormWithWebhook([
            'title' => 'Test Form',
            'fields' => [],
        ]);

        expect($form['id'])->toBe(10);
    });
});

describe('GravityFormsService::testConnection', function () {
    it('returns success when API responds', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms' => Http::response([
                ['id' => 1, 'title' => 'Form 1'],
            ], 200),
        ]);

        $service = new GravityFormsService;
        $result = $service->testConnection();

        expect($result['success'])->toBeTrue();
        expect($result['form_count'])->toBe(1);
    });

    it('returns failure when API errors', function () {
        Http::fake([
            'example.com/wp-json/gf/v2/forms' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $service = new GravityFormsService;
        $result = $service->testConnection();

        expect($result['success'])->toBeFalse();
        expect($result['status'])->toBe(401);
    });
});

describe('GravityFormsService WordPress Pages', function () {
    it('creates a page with default published status', function () {
        Http::fake([
            'example.com/wp-json/wp/v2/pages' => Http::response([
                'id' => 123,
                'title' => ['rendered' => 'Test Page'],
                'link' => 'https://example.com/test-page/',
                'status' => 'publish',
            ], 201),
        ]);

        $service = new GravityFormsService;
        $page = $service->createPage([
            'title' => 'Test Page',
            'content' => '<p>Test content</p>',
        ]);

        expect($page['id'])->toBe(123);
        expect($page['status'])->toBe('publish');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://example.com/wp-json/wp/v2/pages'
                && $request->method() === 'POST'
                && $request['status'] === 'publish';
        });
    });

    it('creates a landing page with embedded form', function () {
        Http::fake([
            'example.com/wp-json/wp/v2/pages' => Http::response([
                'id' => 456,
                'title' => ['rendered' => 'Apply Now'],
                'link' => 'https://example.com/apply-now/',
            ], 201),
        ]);

        $service = new GravityFormsService;
        $result = $service->createFormLandingPage([
            'title' => 'Apply Now',
            'description' => 'Fill out the form below',
            'form_id' => 16,
            'heading' => 'Start Your Application',
        ]);

        expect($result['page_id'])->toBe(456);
        expect($result['form_id'])->toBe(16);
        expect($result['page_url'])->toBe('https://example.com/apply-now/');

        Http::assertSent(function ($request) {
            return str_contains($request['content'], '[gravityform id="16"')
                && str_contains($request['content'], 'Start Your Application');
        });
    });

    it('generates correct form shortcode', function () {
        $service = new GravityFormsService;

        $shortcode = $service->formShortcode(16);
        expect($shortcode)->toBe('[gravityform id="16" title="false" description="false" ajax="true"]');

        $shortcodeWithOptions = $service->formShortcode(16, [
            'title' => 'true',
            'description' => 'true',
            'tabindex' => 10,
        ]);
        expect($shortcodeWithOptions)->toContain('title="true"');
        expect($shortcodeWithOptions)->toContain('tabindex="10"');
    });
});
