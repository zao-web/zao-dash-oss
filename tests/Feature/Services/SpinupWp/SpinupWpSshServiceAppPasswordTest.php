<?php

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Services\SpinupWp\SpinupWpSshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->server = SpinupWpServer::factory()->create([
        'ip_address' => '192.168.1.100',
        'ssh_port' => 22,
    ]);

    $this->site = SpinupWpSite::factory()->create([
        'spinup_server_id' => $this->server->id,
        'domain' => 'test-site.example.com',
        'site_user' => 'testsiteuser',
        'wp_admin_user' => 'admin',
    ]);
});

describe('discoverAdminUser', function () {
    it('discovers admin username via WP-CLI', function () {
        Process::fake([
            '*' => Process::result(
                output: 'discoveredadmin',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $service = new SpinupWpSshService;
        $result = $service->discoverAdminUser($this->site);

        expect($result['success'])->toBeTrue()
            ->and($result['username'])->toBe('discoveredadmin');

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'wp user list')
                && str_contains($process->command, '--role=administrator');
        });
    });

    it('fails gracefully when no admin found', function () {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $service = new SpinupWpSshService;
        $result = $service->discoverAdminUser($this->site);

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('No administrator user found');
    });
});

describe('generateApplicationPassword', function () {
    it('generates application password and stores it', function () {
        Process::fake([
            '*' => Process::result(
                output: 'abcd 1234 EFGH 5678',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $service = new SpinupWpSshService;
        $result = $service->generateApplicationPassword($this->site, 'Test App');

        expect($result['success'])->toBeTrue()
            ->and($result['password'])->toBe('abcd 1234 EFGH 5678')
            ->and($result['wordpress_site'])->not->toBeNull();

        $this->site->refresh();
        expect($this->site->wp_admin_password)->toBe('abcd 1234 EFGH 5678');

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'wp user application-password create')
                && str_contains($process->command, 'admin')
                && str_contains($process->command, '--porcelain');
        });
    });

    it('fails when no wp_admin_user configured', function () {
        $this->site->update(['wp_admin_user' => null]);

        $service = new SpinupWpSshService;
        $result = $service->generateApplicationPassword($this->site);

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toContain('No WordPress admin user');
    });

    it('creates linked WordPressSite record', function () {
        Process::fake([
            '*' => Process::result(
                output: 'newpassword123',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $service = new SpinupWpSshService;
        $result = $service->generateApplicationPassword($this->site);

        expect($result['wordpress_site'])->not->toBeNull()
            ->and($result['wordpress_site']->url)->toBe($this->site->url)
            ->and($result['wordpress_site']->username)->toBe('admin');
    });
});
