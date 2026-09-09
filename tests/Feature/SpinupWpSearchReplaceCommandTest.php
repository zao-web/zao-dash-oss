<?php

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
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
    ]);
});

describe('spinupwp:search-replace command', function () {
    it('runs search-replace via WP-CLI', function () {
        Process::fake([
            '*' => Process::result(
                output: "Success: Made 42 replacements.\n+------------------+-----+\n| Table            | Row |\n+------------------+-----+",
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'https://staging.example.com',
            'replace' => 'https://example.com',
            '--site' => $this->site->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Success: Made 42 replacements');

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'wp search-replace')
                && str_contains($process->command, 'https://staging.example.com')
                && str_contains($process->command, 'https://example.com');
        });
    });

    it('supports dry-run mode', function () {
        Process::fake([
            '*' => Process::result(
                output: "+------------------+-----+\n| Table            | Row |\n+------------------+-----+",
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'old-url',
            'replace' => 'new-url',
            '--site' => $this->site->id,
            '--dry-run' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('DRY RUN');

        Process::assertRan(function ($process) {
            return str_contains($process->command, '--dry-run');
        });
    });

    it('supports all-tables option', function () {
        Process::fake([
            '*' => Process::result(
                output: 'Success',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'old',
            'replace' => 'new',
            '--site' => $this->site->id,
            '--all-tables' => true,
        ])->assertSuccessful();

        Process::assertRan(function ($process) {
            return str_contains($process->command, '--all-tables');
        });
    });

    it('finds site by domain', function () {
        Process::fake([
            '*' => Process::result(
                output: 'Success',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'old',
            'replace' => 'new',
            '--site' => 'test-site.example.com',
        ])->assertSuccessful();
    });

    it('finds site by project ID', function () {
        $project = \App\Models\WebsiteProject::factory()->create();
        $this->site->update(['website_project_id' => $project->id]);

        Process::fake([
            '*' => Process::result(
                output: 'Success',
                errorOutput: '',
                exitCode: 0,
            ),
        ]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'old',
            'replace' => 'new',
            '--project' => $project->id,
        ])->assertSuccessful();
    });

    it('fails when site not found', function () {
        $this->artisan('spinupwp:search-replace', [
            'search' => 'old',
            'replace' => 'new',
            '--site' => 99999,
        ])
            ->assertFailed()
            ->expectsOutputToContain('Could not find SpinupWP site');
    });

    it('fails when server has no IP', function () {
        $this->server->update(['ip_address' => null]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'old',
            'replace' => 'new',
            '--site' => $this->site->id,
        ])
            ->assertFailed()
            ->expectsOutputToContain('no server with IP address');
    });

    it('reports SSH errors', function () {
        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'Connection refused',
                exitCode: 1,
            ),
        ]);

        $this->artisan('spinupwp:search-replace', [
            'search' => 'old',
            'replace' => 'new',
            '--site' => $this->site->id,
        ])
            ->assertFailed()
            ->expectsOutputToContain('Failed');
    });
});
