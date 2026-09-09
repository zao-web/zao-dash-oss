<?php

use App\Services\SpinupWp\OllieDeployScriptGenerator;

describe('OllieDeployScriptGenerator', function () {
    describe('generate', function () {
        it('creates a bash script with shebang and error handling', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate();

            expect($script)->toContain('#!/bin/bash')
                ->and($script)->toContain('set -e');
        });

        it('includes wp-cli check and wait loop', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate();

            expect($script)->toContain('command -v wp')
                ->and($script)->toContain('wp core is-installed')
                ->and($script)->toContain('MAX_ATTEMPTS=30');
        });

        it('installs ollie theme from wordpress.org', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate();

            expect($script)->toContain('wp theme install ollie --activate')
                ->and($script)->toContain('Installing Ollie Theme');
        });

        it('installs core plugins', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate();

            expect($script)->toContain('wp plugin install wordpress-seo')
                ->and($script)->toContain('wp plugin install litespeed-cache')
                ->and($script)->toContain('wp plugin delete hello')
                ->and($script)->toContain('wp plugin delete akismet');
        });

        it('configures site settings', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate(['site_title' => 'My Test Site']);

            expect($script)->toContain('wp option update blogname "My Test Site"')
                ->and($script)->toContain('wp rewrite structure')
                ->and($script)->toContain('wp option update show_on_front')
                ->and($script)->toContain('wp option update default_comment_status');
        });

        it('creates application password by default', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate(['admin_user' => 'myuser']);

            expect($script)->toContain('wp user application-password create myuser')
                ->and($script)->toContain('Zao Dash API');
        });

        it('skips application password when disabled', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate(['create_application_password' => false]);

            expect($script)->not->toContain('wp user application-password create');
        });

        it('uses custom wp path', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate(['wp_path' => '/var/www/html']);

            expect($script)->toContain('cd /var/www/html');
        });
    });

    describe('withOllieProUrl', function () {
        it('includes ollie pro installation when url provided', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator
                ->withOllieProUrl('https://example.com/ollie-pro.zip')
                ->generate();

            expect($script)->toContain('Installing Ollie Pro Plugin')
                ->and($script)->toContain('wp plugin install "https://example.com/ollie-pro.zip"');
        });

        it('skips ollie pro when url is empty', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator->generate(['install_ollie_pro' => true]);

            expect($script)->not->toContain('Installing Ollie Pro Plugin');
        });
    });

    describe('withAdditionalPlugins', function () {
        it('installs additional plugins', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator
                ->withAdditionalPlugins(['contact-form-7', 'wordfence'])
                ->generate();

            expect($script)->toContain('Installing Additional Plugins')
                ->and($script)->toContain('wp plugin install contact-form-7')
                ->and($script)->toContain('wp plugin install wordfence');
        });

        it('supports plugin arrays with activate option', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator
                ->withAdditionalPlugins([
                    ['slug' => 'contact-form-7', 'activate' => true],
                    ['slug' => 'query-monitor', 'activate' => false],
                ])
                ->generate();

            expect($script)->toContain('wp plugin install contact-form-7 --activate')
                ->and($script)->toContain('wp plugin install query-monitor ')
                ->and($script)->not->toContain('wp plugin install query-monitor --activate');
        });
    });

    describe('generateMinimal', function () {
        it('creates script without ollie pro and application password', function () {
            $generator = new OllieDeployScriptGenerator;
            $script = $generator
                ->withOllieProUrl('https://example.com/ollie-pro.zip')
                ->generateMinimal();

            expect($script)->toContain('wp theme install ollie --activate')
                ->and($script)->not->toContain('Installing Ollie Pro Plugin')
                ->and($script)->not->toContain('wp user application-password create');
        });
    });

    describe('forWebsiteProject', function () {
        it('creates script from project config array', function () {
            $script = OllieDeployScriptGenerator::forWebsiteProject([
                'name' => 'Client Website',
                'site_title' => 'Client Website 2025',
                'admin_email' => 'admin@client.com',
                'admin_user' => 'clientadmin',
            ]);

            expect($script)->toContain('wp option update blogname "Client Website 2025"')
                ->and($script)->toContain('wp user application-password create clientadmin');
        });

        it('uses name as site title fallback', function () {
            $script = OllieDeployScriptGenerator::forWebsiteProject([
                'name' => 'Fallback Name',
            ]);

            expect($script)->toContain('wp option update blogname "Fallback Name"');
        });

        it('includes ollie pro when url provided in config', function () {
            $script = OllieDeployScriptGenerator::forWebsiteProject([
                'name' => 'Pro Site',
                'ollie_pro_url' => 'https://example.com/ollie-pro.zip',
                'install_ollie_pro' => true,
            ]);

            expect($script)->toContain('Installing Ollie Pro Plugin');
        });

        it('includes additional plugins from config', function () {
            $script = OllieDeployScriptGenerator::forWebsiteProject([
                'name' => 'Plugin Site',
                'additional_plugins' => ['gravity-forms', 'advanced-custom-fields'],
            ]);

            expect($script)->toContain('wp plugin install gravity-forms')
                ->and($script)->toContain('wp plugin install advanced-custom-fields');
        });
    });
});
