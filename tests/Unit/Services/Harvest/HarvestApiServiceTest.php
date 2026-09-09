<?php

namespace Tests\Unit\Services\Harvest;

use App\Models\HarvestCredential;
use App\Services\Harvest\HarvestApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HarvestApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected HarvestApiService $service;

    protected HarvestCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HarvestApiService;
        $this->credential = HarvestCredential::factory()->create([
            'access_token' => 'test-token',
            'account_id' => '12345',
        ]);
    }

    /** @test */
    public function it_lists_projects()
    {
        Http::fake([
            'api.harvestapp.com/v2/projects*' => Http::response([
                'projects' => [
                    [
                        'id' => 1,
                        'name' => 'Test Project',
                        'code' => 'TEST',
                    ],
                ],
            ], 200),
        ]);

        $projects = $this->service->listProjects($this->credential);

        $this->assertCount(1, $projects);
        $this->assertEquals('Test Project', $projects[0]['name']);
    }

    /** @test */
    public function it_lists_time_entries()
    {
        Http::fake([
            'api.harvestapp.com/v2/time_entries*' => Http::response([
                'time_entries' => [
                    [
                        'id' => 1,
                        'hours' => 8.0,
                        'notes' => 'Working',
                    ],
                ],
            ], 200),
        ]);

        $entries = $this->service->listTimeEntries($this->credential);

        $this->assertCount(1, $entries);
        $this->assertEquals(8.0, $entries[0]['hours']);
    }

    /** @test */
    public function it_sends_auth_headers()
    {
        Http::fake([
            'api.harvestapp.com/v2/projects*' => Http::response([
                'projects' => [],
            ], 200),
        ]);

        $this->service->listProjects($this->credential);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token') &&
                   $request->hasHeader('Harvest-Account-Id', '12345');
        });
    }
}
