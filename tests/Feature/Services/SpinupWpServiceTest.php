<?php

use App\Models\SpinupWpServer;
use App\Services\SpinupWp\SpinupWpService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['services.spinupwp.api_token' => 'test-token']);
    config(['services.spinupwp.api_url' => 'https://api.spinupwp.app/v1']);
});

describe('SpinupWpService', function () {
    describe('isConfigured', function () {
        it('returns true when api token is set', function () {
            $service = new SpinupWpService;

            expect($service->isConfigured())->toBeTrue();
        });

        it('returns false when api token is empty', function () {
            config(['services.spinupwp.api_token' => null]);
            $service = new SpinupWpService;

            expect($service->isConfigured())->toBeFalse();
        });
    });

    describe('listServers', function () {
        it('returns servers from api', function () {
            Http::fake([
                'api.spinupwp.app/v1/servers*' => Http::response([
                    'data' => [
                        [
                            'id' => 1,
                            'name' => 'test-server',
                            'provider_name' => 'DigitalOcean',
                            'ip_address' => '10.0.0.1',
                            'status' => 'provisioned',
                        ],
                    ],
                    'pagination' => ['next' => null],
                ]),
            ]);

            $service = new SpinupWpService;
            $servers = $service->listServers();

            expect($servers)->toHaveCount(1);
            expect($servers[0]['name'])->toBe('test-server');
        });

        it('handles pagination', function () {
            Http::fake([
                'api.spinupwp.app/v1/servers?page=1*' => Http::response([
                    'data' => [['id' => 1, 'name' => 'server-1']],
                    'pagination' => ['next' => 'page2'],
                ]),
                'api.spinupwp.app/v1/servers?page=2*' => Http::response([
                    'data' => [['id' => 2, 'name' => 'server-2']],
                    'pagination' => ['next' => null],
                ]),
            ]);

            $service = new SpinupWpService;
            $servers = $service->listServers();

            expect($servers)->toHaveCount(2);
        });
    });

    describe('syncServers', function () {
        it('creates server records from api data', function () {
            Http::fake([
                'api.spinupwp.app/v1/servers*' => Http::response([
                    'data' => [
                        [
                            'id' => 123,
                            'name' => 'production-server',
                            'provider_name' => 'DigitalOcean',
                            'ubuntu_version' => '24.04',
                            'ip_address' => '192.168.1.1',
                            'ssh_port' => 22,
                            'status' => 'provisioned',
                            'connection_status' => 'connected',
                        ],
                    ],
                    'pagination' => ['next' => null],
                ]),
            ]);

            $service = new SpinupWpService;
            $count = $service->syncServers();

            expect($count)->toBe(1);

            $server = SpinupWpServer::where('spinup_id', 123)->first();
            expect($server)->not->toBeNull();
            expect($server->name)->toBe('production-server');
            expect($server->ip_address)->toBe('192.168.1.1');
            expect($server->status)->toBe('provisioned');
        });

        it('updates existing server records', function () {
            SpinupWpServer::create([
                'spinup_id' => 123,
                'name' => 'old-name',
                'status' => 'unknown',
            ]);

            Http::fake([
                'api.spinupwp.app/v1/servers*' => Http::response([
                    'data' => [
                        [
                            'id' => 123,
                            'name' => 'new-name',
                            'status' => 'provisioned',
                        ],
                    ],
                    'pagination' => ['next' => null],
                ]),
            ]);

            $service = new SpinupWpService;
            $service->syncServers();

            $server = SpinupWpServer::where('spinup_id', 123)->first();
            expect($server->name)->toBe('new-name');
            expect($server->status)->toBe('provisioned');
        });
    });

    describe('createSite', function () {
        it('creates a site on spinupwp', function () {
            $server = SpinupWpServer::create([
                'spinup_id' => 1,
                'name' => 'test-server',
                'status' => 'provisioned',
            ]);

            Http::fake([
                'api.spinupwp.app/v1/sites' => Http::response([
                    'event_id' => 42,
                    'data' => [
                        'id' => 99,
                        'domain' => 'example.com',
                        'site_user' => 'example',
                        'status' => 'deploying',
                    ],
                ]),
            ]);

            $service = new SpinupWpService;
            $result = $service->createSite($server, [
                'domain' => 'example.com',
                'wordpress' => [
                    'title' => 'Test Site',
                    'admin_user' => 'admin',
                    'admin_email' => 'admin@example.com',
                    'admin_password' => 'secret123',
                ],
            ]);

            expect($result['event_id'])->toBe(42);
            expect($result['site']['id'])->toBe(99);
            expect($result['site']['domain'])->toBe('example.com');

            Http::assertSent(function ($request) {
                return $request->url() === 'https://api.spinupwp.app/v1/sites'
                    && $request['server_id'] === 1
                    && $request['domain'] === 'example.com'
                    && $request['installation_method'] === 'wp';
            });
        });

        it('includes deploy script when provided', function () {
            $server = SpinupWpServer::create([
                'spinup_id' => 1,
                'name' => 'test-server',
                'status' => 'provisioned',
            ]);

            Http::fake([
                'api.spinupwp.app/v1/sites' => Http::response([
                    'event_id' => 1,
                    'data' => ['id' => 1],
                ]),
            ]);

            $service = new SpinupWpService;
            $service->createSite($server, [
                'domain' => 'test.com',
                'deploy_script' => 'wp theme install ollie --activate',
            ]);

            Http::assertSent(function ($request) {
                return str_contains($request['deploy_script'] ?? '', 'wp theme install ollie');
            });
        });
    });

    describe('getEvent', function () {
        it('returns event data', function () {
            Http::fake([
                'api.spinupwp.app/v1/events/42' => Http::response([
                    'data' => [
                        'id' => 42,
                        'name' => 'Creating site test.com',
                        'status' => 'deployed',
                        'finished_at' => '2024-01-01T12:00:00Z',
                    ],
                ]),
            ]);

            $service = new SpinupWpService;
            $event = $service->getEvent(42);

            expect($event['id'])->toBe(42);
            expect($event['status'])->toBe('deployed');
        });
    });

    describe('getDefaultServer', function () {
        it('returns server from config', function () {
            $server = SpinupWpServer::create([
                'spinup_id' => 999,
                'name' => 'config-default',
                'status' => 'provisioned',
                'connection_status' => 'connected',
            ]);

            config(['services.spinupwp.default_server_id' => 999]);

            $service = new SpinupWpService;
            $default = $service->getDefaultServer();

            expect($default)->not->toBeNull();
            expect($default->spinup_id)->toBe(999);
        });

        it('returns database default when no config', function () {
            $server = SpinupWpServer::create([
                'spinup_id' => 1,
                'name' => 'db-default',
                'status' => 'provisioned',
                'connection_status' => 'connected',
                'is_default' => true,
            ]);

            config(['services.spinupwp.default_server_id' => null]);

            $service = new SpinupWpService;
            $default = $service->getDefaultServer();

            expect($default)->not->toBeNull();
            expect($default->name)->toBe('db-default');
        });

        it('returns first available server as fallback', function () {
            SpinupWpServer::create([
                'spinup_id' => 1,
                'name' => 'first-available',
                'status' => 'provisioned',
                'connection_status' => 'connected',
            ]);

            config(['services.spinupwp.default_server_id' => null]);

            $service = new SpinupWpService;
            $default = $service->getDefaultServer();

            expect($default)->not->toBeNull();
            expect($default->name)->toBe('first-available');
        });
    });
});
