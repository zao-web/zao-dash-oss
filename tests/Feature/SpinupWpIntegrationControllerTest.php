<?php

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    config(['services.spinupwp.api_token' => 'test-token']);
    config(['services.spinupwp.api_url' => 'https://api.spinupwp.app/v1']);
});

describe('SpinupWpIntegrationController', function () {
    describe('status', function () {
        it('returns configured status and servers', function () {
            $server = SpinupWpServer::factory()->provisioned()->create([
                'name' => 'production-server',
                'ip_address' => '10.0.0.1',
            ]);

            $response = $this->getJson('/api/integrations/spinupwp/status');

            $response->assertOk()
                ->assertJsonPath('configured', true)
                ->assertJsonPath('connected', true)
                ->assertJsonCount(1, 'servers')
                ->assertJsonPath('servers.0.name', 'production-server');
        });

        it('returns not connected when no servers exist', function () {
            $response = $this->getJson('/api/integrations/spinupwp/status');

            $response->assertOk()
                ->assertJsonPath('configured', true)
                ->assertJsonPath('connected', false)
                ->assertJsonCount(0, 'servers');
        });

        it('returns not configured when api token is missing', function () {
            config(['services.spinupwp.api_token' => null]);

            $response = $this->getJson('/api/integrations/spinupwp/status');

            $response->assertOk()
                ->assertJsonPath('configured', false);
        });
    });

    describe('syncServers', function () {
        it('syncs servers from spinupwp api', function () {
            Http::fake([
                'api.spinupwp.app/v1/servers*' => Http::response([
                    'data' => [
                        [
                            'id' => 123,
                            'name' => 'new-server',
                            'provider_name' => 'DigitalOcean',
                            'ip_address' => '192.168.1.1',
                            'status' => 'provisioned',
                            'connection_status' => 'connected',
                        ],
                    ],
                    'pagination' => ['next' => null],
                ]),
            ]);

            $response = $this->postJson('/api/integrations/spinupwp/sync-servers');

            $response->assertOk()
                ->assertJsonPath('synced', 1);

            $this->assertDatabaseHas('spinup_wp_servers', [
                'spinup_id' => 123,
                'name' => 'new-server',
            ]);
        });

        it('returns error when not configured', function () {
            config(['services.spinupwp.api_token' => null]);

            $response = $this->postJson('/api/integrations/spinupwp/sync-servers');

            $response->assertStatus(422)
                ->assertJsonPath('error', 'SpinupWP API token not configured');
        });

        it('handles api errors gracefully', function () {
            Http::fake([
                'api.spinupwp.app/v1/servers*' => Http::response(['message' => 'Unauthorized'], 401),
            ]);

            $response = $this->postJson('/api/integrations/spinupwp/sync-servers');

            $response->assertStatus(500)
                ->assertJsonStructure(['error']);
        });
    });

    describe('setDefaultServer', function () {
        it('sets a server as default', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();

            $response = $this->postJson('/api/integrations/spinupwp/default-server', [
                'server_id' => $server->id,
            ]);

            $response->assertOk()
                ->assertJsonPath('server.is_default', true);

            $this->assertDatabaseHas('spinup_wp_servers', [
                'id' => $server->id,
                'is_default' => true,
            ]);
        });

        it('clears previous default when setting new default', function () {
            $oldDefault = SpinupWpServer::factory()->provisioned()->default()->create();
            $newDefault = SpinupWpServer::factory()->provisioned()->create();

            $this->postJson('/api/integrations/spinupwp/default-server', [
                'server_id' => $newDefault->id,
            ]);

            $this->assertDatabaseHas('spinup_wp_servers', [
                'id' => $oldDefault->id,
                'is_default' => false,
            ]);
            $this->assertDatabaseHas('spinup_wp_servers', [
                'id' => $newDefault->id,
                'is_default' => true,
            ]);
        });

        it('rejects unavailable servers', function () {
            $server = SpinupWpServer::factory()->disconnected()->create();

            $response = $this->postJson('/api/integrations/spinupwp/default-server', [
                'server_id' => $server->id,
            ]);

            $response->assertStatus(422)
                ->assertJsonPath('error', 'Server is not available to host sites');
        });

        it('validates server_id is required', function () {
            $response = $this->postJson('/api/integrations/spinupwp/default-server', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['server_id']);
        });
    });

    describe('listSites', function () {
        it('returns all sites', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
                'domain' => 'example.com',
            ]);

            $response = $this->getJson('/api/integrations/spinupwp/sites');

            $response->assertOk()
                ->assertJsonCount(1, 'sites')
                ->assertJsonPath('sites.0.domain', 'example.com');
        });

        it('filters by server_id', function () {
            $server1 = SpinupWpServer::factory()->provisioned()->create();
            $server2 = SpinupWpServer::factory()->provisioned()->create();

            SpinupWpSite::factory()->deployed()->create(['spinup_server_id' => $server1->id]);
            SpinupWpSite::factory()->deployed()->create(['spinup_server_id' => $server2->id]);

            $response = $this->getJson("/api/integrations/spinupwp/sites?server_id={$server1->id}");

            $response->assertOk()
                ->assertJsonCount(1, 'sites');
        });
    });

    describe('refreshServer', function () {
        it('refreshes server data from api', function () {
            $server = SpinupWpServer::factory()->provisioned()->create([
                'spinup_id' => 123,
                'name' => 'old-name',
            ]);

            Http::fake([
                'api.spinupwp.app/v1/servers/123' => Http::response([
                    'data' => [
                        'id' => 123,
                        'name' => 'updated-name',
                        'status' => 'provisioned',
                        'connection_status' => 'connected',
                    ],
                ]),
            ]);

            $response = $this->postJson("/api/integrations/spinupwp/servers/{$server->id}/refresh");

            $response->assertOk()
                ->assertJsonPath('server.name', 'updated-name');

            $this->assertDatabaseHas('spinup_wp_servers', [
                'id' => $server->id,
                'name' => 'updated-name',
            ]);
        });

        it('returns error when not configured', function () {
            config(['services.spinupwp.api_token' => null]);
            $server = SpinupWpServer::factory()->provisioned()->create();

            $response = $this->postJson("/api/integrations/spinupwp/servers/{$server->id}/refresh");

            $response->assertStatus(422)
                ->assertJsonPath('error', 'SpinupWP API token not configured');
        });
    });

    describe('deleteServer', function () {
        it('removes server from tracking', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();

            $response = $this->deleteJson("/api/integrations/spinupwp/servers/{$server->id}");

            $response->assertOk();
            $this->assertDatabaseMissing('spinup_wp_servers', ['id' => $server->id]);
        });

        it('rejects deletion when server has sites', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            SpinupWpSite::factory()->deployed()->create(['spinup_server_id' => $server->id]);

            $response = $this->deleteJson("/api/integrations/spinupwp/servers/{$server->id}");

            $response->assertStatus(422)
                ->assertJsonPath('error', 'Cannot delete server with active sites. Delete sites first.');
        });
    });

    describe('refreshSite', function () {
        it('refreshes site data from api', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
                'spinup_id' => 456,
                'domain' => 'old-domain.com',
            ]);

            Http::fake([
                'api.spinupwp.app/v1/sites/456' => Http::response([
                    'data' => [
                        'id' => 456,
                        'domain' => 'new-domain.com',
                        'status' => 'deployed',
                    ],
                ]),
            ]);

            $response = $this->postJson("/api/integrations/spinupwp/sites/{$site->id}/refresh");

            $response->assertOk()
                ->assertJsonPath('site.domain', 'new-domain.com');
        });
    });

    describe('deleteSite', function () {
        it('removes site from tracking only', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
            ]);

            $response = $this->deleteJson("/api/integrations/spinupwp/sites/{$site->id}");

            $response->assertOk();
            // SpinupWpSite uses SoftDeletes, so check it was soft deleted
            $this->assertSoftDeleted('spinup_wp_sites', ['id' => $site->id]);
        });

        it('deletes from spinupwp when requested', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
                'spinup_id' => 789,
            ]);

            Http::fake([
                'api.spinupwp.app/v1/sites/789' => Http::response([
                    'event_id' => 999,
                ]),
            ]);

            $response = $this->deleteJson("/api/integrations/spinupwp/sites/{$site->id}", [
                'delete_from_spinup' => true,
            ]);

            $response->assertOk();
            $this->assertSoftDeleted('spinup_wp_sites', ['id' => $site->id]);
            Http::assertSent(fn ($request) => str_contains($request->url(), 'sites/789'));
        });
    });

    describe('purgeCache', function () {
        it('purges cache for site', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
                'spinup_id' => 456,
            ]);

            Http::fake([
                'api.spinupwp.app/v1/sites/456/page-cache/purge' => Http::response([
                    'event_id' => 123,
                ]),
            ]);

            $response = $this->postJson("/api/integrations/spinupwp/sites/{$site->id}/purge-cache");

            $response->assertOk()
                ->assertJsonStructure(['message']);
        });
    });

    describe('triggerDeploy', function () {
        it('triggers git deploy for site', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->withGit()->deployed()->create([
                'spinup_server_id' => $server->id,
                'spinup_id' => 456,
            ]);

            Http::fake([
                'api.spinupwp.app/v1/sites/456/git/deploy' => Http::response([
                    'event_id' => 123,
                ]),
            ]);

            $response = $this->postJson("/api/integrations/spinupwp/sites/{$site->id}/deploy");

            $response->assertOk()
                ->assertJsonPath('event_id', 123);
        });

        it('rejects deploy for sites without git config', function () {
            $server = SpinupWpServer::factory()->provisioned()->create();
            $site = SpinupWpSite::factory()->deployed()->create([
                'spinup_server_id' => $server->id,
                'git_config' => null,
            ]);

            $response = $this->postJson("/api/integrations/spinupwp/sites/{$site->id}/deploy");

            $response->assertStatus(422)
                ->assertJsonPath('error', 'Site does not have git deployment configured');
        });
    });
});
