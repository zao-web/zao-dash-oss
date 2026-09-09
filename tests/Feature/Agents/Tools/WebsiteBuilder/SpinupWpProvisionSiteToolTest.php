<?php

use App\Agents\Tools\WebsiteBuilder\SpinupWpProvisionSiteTool;
use App\Events\WebsiteBuilderMessageReceived;
use App\Events\WebsiteBuilderStatusUpdated;
use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Services\SpinupWp\SpinupWpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    Event::fake([
        WebsiteBuilderMessageReceived::class,
        WebsiteBuilderStatusUpdated::class,
    ]);
    config(['services.spinupwp.api_token' => 'test-token']);
    config(['services.spinupwp.staging_domain' => 'staging.test.com']);
});

describe('SpinupWpProvisionSiteTool', function () {
    describe('metadata', function () {
        it('has correct name and description', function () {
            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);

            expect($tool->name())->toBe('Provision SpinupWP Site')
                ->and($tool->description())->toContain('WordPress staging site')
                ->and($tool->category())->toBe('website-builder');
        });

        it('requires approval', function () {
            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);

            expect($tool->requiresApproval())->toBeTrue()
                ->and($tool->riskLevel())->toBe('medium');
        });

        it('has required input schema fields', function () {
            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);
            $schema = $tool->inputSchema();

            expect($schema['required'])->toContain('project_id')
                ->and($schema['required'])->toContain('admin_email')
                ->and($schema['properties'])->toHaveKey('domain')
                ->and($schema['properties'])->toHaveKey('server_id')
                ->and($schema['properties'])->toHaveKey('git_repo');
        });
    });

    describe('execute', function () {
        it('returns error when spinupwp is not configured', function () {
            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(false);

            $project = WebsiteProject::factory()->create();
            $tool = new SpinupWpProvisionSiteTool($service);

            $result = $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
            ]);

            expect($result['success'])->toBeFalse()
                ->and($result['error'])->toContain('not configured');
        });

        it('returns error when no server available', function () {
            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);
            $service->shouldReceive('getDefaultServer')->once()->andReturn(null);

            $project = WebsiteProject::factory()->create();
            $tool = new SpinupWpProvisionSiteTool($service);

            $result = $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
            ]);

            expect($result['success'])->toBeFalse()
                ->and($result['error'])->toContain('No SpinupWP server available');
        });

        it('returns existing site when already provisioned', function () {
            $project = WebsiteProject::factory()->create();
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
                'website_project_id' => $project->id,
                'domain' => 'existing.staging.test.com',
            ]);

            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);

            $tool = new SpinupWpProvisionSiteTool($service);
            $result = $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
            ]);

            expect($result['success'])->toBeTrue()
                ->and($result['status'])->toBe('deployed')
                ->and($result['domain'])->toBe('existing.staging.test.com');
        });

        it('checks status when site is provisioning', function () {
            $project = WebsiteProject::factory()->create();
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->provisioning()->create([
                'spinup_server_id' => $server->id,
                'website_project_id' => $project->id,
                'domain' => 'provisioning.staging.test.com',
            ]);

            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);
            $service->shouldReceive('checkProvisioningStatus')
                ->once()
                ->andReturn($site);

            $tool = new SpinupWpProvisionSiteTool($service);
            $result = $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
            ]);

            expect($result['success'])->toBeTrue()
                ->and($result['status'])->toBe('provisioning')
                ->and($result['message'])->toContain('still being provisioned');
        });

        it('creates new site when none exists with wait disabled', function () {
            $project = WebsiteProject::factory()->create(['name' => 'Test Project']);
            $server = SpinupWpServer::factory()->provisioned()->create();

            $newSite = SpinupWpSite::factory()->provisioning()->make([
                'id' => 999,
                'spinup_server_id' => $server->id,
                'website_project_id' => $project->id,
                'domain' => 'test-project-abc123.staging.test.com',
                'provision_event_id' => 12345,
            ]);

            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);
            $service->shouldReceive('getDefaultServer')->once()->andReturn($server);
            $service->shouldReceive('provisionSiteForProject')
                ->once()
                ->andReturn($newSite);

            $tool = new SpinupWpProvisionSiteTool($service);
            $result = $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
                'wait_for_completion' => false,
            ]);

            expect($result['success'])->toBeTrue()
                ->and($result['status'])->toBe('provisioning')
                ->and($result['spinup_event_id'])->toBe(12345)
                ->and($result['estimated_time'])->toBe('2-5 minutes');
        });

        it('uses specified server when server_id provided', function () {
            $project = WebsiteProject::factory()->create(['name' => 'Test Project']);
            $server = SpinupWpServer::factory()->provisioned()->create(['spinup_id' => 777]);

            $newSite = SpinupWpSite::factory()->provisioning()->make([
                'id' => 999,
                'spinup_server_id' => $server->id,
            ]);

            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);
            $service->shouldReceive('provisionSiteForProject')
                ->once()
                ->with(
                    Mockery::on(fn ($serverId) => $serverId === 777),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any()
                )
                ->andReturn($newSite);

            $tool = new SpinupWpProvisionSiteTool($service);
            $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
                'server_id' => 777,
                'wait_for_completion' => false,
            ]);
        });

        it('uses custom domain when provided', function () {
            $project = WebsiteProject::factory()->create();
            $server = SpinupWpServer::factory()->provisioned()->create();

            $newSite = SpinupWpSite::factory()->provisioning()->make([
                'domain' => 'custom.domain.com',
            ]);

            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);
            $service->shouldReceive('getDefaultServer')->once()->andReturn($server);
            $service->shouldReceive('provisionSiteForProject')
                ->once()
                ->with(
                    Mockery::any(),
                    'custom.domain.com',
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any()
                )
                ->andReturn($newSite);

            $tool = new SpinupWpProvisionSiteTool($service);
            $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
                'domain' => 'custom.domain.com',
                'wait_for_completion' => false,
            ]);
        });

        it('handles provisioning exception', function () {
            $project = WebsiteProject::factory()->create();
            $server = SpinupWpServer::factory()->provisioned()->create();

            $service = Mockery::mock(SpinupWpService::class);
            $service->shouldReceive('isConfigured')->once()->andReturn(true);
            $service->shouldReceive('getDefaultServer')->once()->andReturn($server);
            $service->shouldReceive('provisionSiteForProject')
                ->once()
                ->andThrow(new Exception('API connection failed'));

            $tool = new SpinupWpProvisionSiteTool($service);
            $result = $tool->execute([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
            ]);

            expect($result['success'])->toBeFalse()
                ->and($result['error'])->toContain('Failed to provision site')
                ->and($result['error'])->toContain('API connection failed');
        });
    });

    describe('validation', function () {
        it('validates project_id is required', function () {
            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);

            $tool->validate([
                'admin_email' => 'admin@example.com',
            ]);
        })->throws(InvalidArgumentException::class);

        it('validates admin_email is required', function () {
            $project = WebsiteProject::factory()->create();

            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);

            $tool->validate([
                'project_id' => $project->id,
            ]);
        })->throws(InvalidArgumentException::class);

        it('validates admin_email format', function () {
            $project = WebsiteProject::factory()->create();

            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);

            $tool->validate([
                'project_id' => $project->id,
                'admin_email' => 'not-an-email',
            ]);
        })->throws(InvalidArgumentException::class);

        it('passes validation with valid params', function () {
            $project = WebsiteProject::factory()->create();

            $service = Mockery::mock(SpinupWpService::class);
            $tool = new SpinupWpProvisionSiteTool($service);

            $validated = $tool->validate([
                'project_id' => $project->id,
                'admin_email' => 'admin@example.com',
                'domain' => 'test.staging.com',
            ]);

            expect($validated['project_id'])->toBe($project->id)
                ->and($validated['admin_email'])->toBe('admin@example.com')
                ->and($validated['domain'])->toBe('test.staging.com');
        });
    });
});
