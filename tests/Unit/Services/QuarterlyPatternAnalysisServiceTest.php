<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\ContentSuggestion;
use App\Models\HarvestProject;
use App\Models\HarvestTaskCategory;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\QuarterlyPatternAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuarterlyPatternAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QuarterlyPatternAnalysisService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QuarterlyPatternAnalysisService;
    }

    /** @test */
    public function it_analyzes_current_quarter_by_default()
    {
        $analysis = $this->service->analyze();

        $this->assertArrayHasKey('period', $analysis);
        $this->assertArrayHasKey('start', $analysis['period']);
        $this->assertArrayHasKey('end', $analysis['period']);
        $this->assertArrayHasKey('label', $analysis['period']);

        $expectedLabel = 'Q'.now()->quarter.' '.now()->year;
        $this->assertEquals($expectedLabel, $analysis['period']['label']);
    }

    /** @test */
    public function it_analyzes_specific_quarter()
    {
        $quarterStart = Carbon::parse('2024-01-01');

        $analysis = $this->service->analyze($quarterStart);

        $this->assertEquals('Q1 2024', $analysis['period']['label']);
        $this->assertEquals('2024-01-01', $analysis['period']['start']);
        $this->assertEquals('2024-03-31', $analysis['period']['end']);
    }

    /** @test */
    public function it_includes_all_analysis_sections()
    {
        $analysis = $this->service->analyze();

        $this->assertArrayHasKey('industry_patterns', $analysis);
        $this->assertArrayHasKey('service_patterns', $analysis);
        $this->assertArrayHasKey('technology_patterns', $analysis);
        $this->assertArrayHasKey('client_growth_patterns', $analysis);
        $this->assertArrayHasKey('content_suggestions', $analysis);
        $this->assertArrayHasKey('landing_page_suggestions', $analysis);
        $this->assertArrayHasKey('outreach_suggestions', $analysis);
    }

    /** @test */
    public function it_analyzes_industry_patterns()
    {
        $quarterStart = now()->startOfQuarter();
        $client1 = Client::factory()->create(['industry' => 'Healthcare']);
        $client2 = Client::factory()->create(['industry' => 'Healthcare']);
        $client3 = Client::factory()->create(['industry' => 'Finance']);

        Project::factory()->create([
            'client_id' => $client1->id,
            'budget' => 10000,
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        Project::factory()->create([
            'client_id' => $client2->id,
            'budget' => 15000,
            'created_at' => $quarterStart->copy()->addDays(10),
        ]);

        Project::factory()->create([
            'client_id' => $client3->id,
            'budget' => 8000,
            'created_at' => $quarterStart->copy()->addDays(15),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $this->assertNotEmpty($analysis['industry_patterns']);
        $this->assertArrayHasKey('Healthcare', $analysis['industry_patterns']);
        $this->assertEquals(2, $analysis['industry_patterns']['Healthcare']['count']);
        $this->assertEquals(25000, $analysis['industry_patterns']['Healthcare']['revenue']);
    }

    /** @test */
    public function it_analyzes_service_patterns()
    {
        $quarterStart = now()->startOfQuarter();
        $category = HarvestTaskCategory::factory()->create(['name' => 'Development']);
        $project = HarvestProject::factory()->create();

        TimeEntry::factory()->create([
            'task_category_id' => $category->id,
            'project_id' => $project->id,
            'hours' => 40,
            'billable_rate' => 150,
            'spent_date' => $quarterStart->copy()->addDays(5),
        ]);

        TimeEntry::factory()->create([
            'task_category_id' => $category->id,
            'project_id' => $project->id,
            'hours' => 30,
            'billable_rate' => 150,
            'spent_date' => $quarterStart->copy()->addDays(10),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $this->assertNotEmpty($analysis['service_patterns']);
        $this->assertArrayHasKey('Development', $analysis['service_patterns']);
        $this->assertEquals(70, $analysis['service_patterns']['Development']['hours']);
    }

    /** @test */
    public function it_analyzes_technology_patterns()
    {
        $quarterStart = now()->startOfQuarter();
        $client = Client::factory()->create();

        Project::factory()->create([
            'client_id' => $client->id,
            'technologies' => ['Laravel', 'Vue', 'Tailwind'],
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        Project::factory()->create([
            'client_id' => $client->id,
            'technologies' => ['Laravel', 'React'],
            'created_at' => $quarterStart->copy()->addDays(10),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $this->assertNotEmpty($analysis['technology_patterns']);
        $this->assertEquals(2, $analysis['technology_patterns']['Laravel']);
        $this->assertEquals(1, $analysis['technology_patterns']['Vue']);
        $this->assertEquals(1, $analysis['technology_patterns']['React']);
    }

    /** @test */
    public function it_analyzes_client_growth_patterns()
    {
        $quarterStart = now()->startOfQuarter();

        // New clients
        Client::factory()->count(3)->create([
            'created_at' => $quarterStart->copy()->addDays(10),
        ]);

        // Churned client
        Client::factory()->create([
            'status' => 'churned',
            'updated_at' => $quarterStart->copy()->addDays(15),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $growth = $analysis['client_growth_patterns'];
        $this->assertEquals(3, $growth['new_clients']);
        $this->assertEquals(1, $growth['churned_clients']);
        $this->assertEquals(2, $growth['net_growth']);
    }

    /** @test */
    public function it_generates_industry_case_study_suggestions()
    {
        $quarterStart = now()->startOfQuarter();
        $client1 = Client::factory()->create(['industry' => 'E-commerce']);
        $client2 = Client::factory()->create(['industry' => 'E-commerce']);

        Project::factory()->count(2)->create([
            'client_id' => $client1->id,
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        Project::factory()->create([
            'client_id' => $client2->id,
            'created_at' => $quarterStart->copy()->addDays(10),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $caseStudies = collect($analysis['content_suggestions'])
            ->where('type', 'case_study')
            ->where('title', 'like', '%E-commerce%');

        $this->assertGreaterThan(0, $caseStudies->count());
    }

    /** @test */
    public function it_generates_service_expertise_suggestions()
    {
        $quarterStart = now()->startOfQuarter();
        $category = HarvestTaskCategory::factory()->create(['name' => 'UI/UX Design']);
        $project = HarvestProject::factory()->create();

        TimeEntry::factory()->create([
            'task_category_id' => $category->id,
            'project_id' => $project->id,
            'hours' => 60,
            'spent_date' => $quarterStart->copy()->addDays(5),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $blogPosts = collect($analysis['content_suggestions'])
            ->where('type', 'blog_post');

        $this->assertGreaterThan(0, $blogPosts->count());
    }

    /** @test */
    public function it_generates_technology_tutorial_suggestions()
    {
        $quarterStart = now()->startOfQuarter();
        $client = Client::factory()->create();

        Project::factory()->count(2)->create([
            'client_id' => $client->id,
            'technologies' => ['Next.js'],
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $tutorials = collect($analysis['content_suggestions'])
            ->where('type', 'tutorial')
            ->where('title', 'like', '%Next.js%');

        $this->assertGreaterThan(0, $tutorials->count());
    }

    /** @test */
    public function it_generates_landing_page_suggestions()
    {
        $quarterStart = now()->startOfQuarter();
        $industry = 'SaaS';

        $clients = Client::factory()->count(3)->create(['industry' => $industry]);

        foreach ($clients as $client) {
            Project::factory()->create([
                'client_id' => $client->id,
                'budget' => 20000,
                'created_at' => $quarterStart->copy()->addDays(rand(1, 30)),
            ]);
        }

        $analysis = $this->service->analyze($quarterStart);

        $landingPages = collect($analysis['landing_page_suggestions'])
            ->where('title', 'like', "%{$industry}%");

        $this->assertGreaterThan(0, $landingPages->count());
    }

    /** @test */
    public function it_generates_outreach_suggestions()
    {
        $quarterStart = now()->startOfQuarter();
        $industry = 'Education';

        $clients = Client::factory()->count(2)->create(['industry' => $industry]);

        foreach ($clients as $client) {
            Project::factory()->create([
                'client_id' => $client->id,
                'created_at' => $quarterStart->copy()->addDays(5),
            ]);
        }

        $analysis = $this->service->analyze($quarterStart);

        $outreach = collect($analysis['outreach_suggestions'])
            ->where('industry', $industry);

        $this->assertGreaterThan(0, $outreach->count());
        if ($outreach->count() > 0) {
            $this->assertArrayHasKey('suggested_icp', $outreach->first());
        }
    }

    /** @test */
    public function it_sorts_content_suggestions_by_priority()
    {
        $quarterStart = now()->startOfQuarter();
        $client1 = Client::factory()->create(['industry' => 'Tech']);
        $client2 = Client::factory()->create(['industry' => 'Retail']);

        // More Tech projects (higher priority)
        Project::factory()->count(5)->create([
            'client_id' => $client1->id,
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        // Fewer Retail projects
        Project::factory()->count(2)->create([
            'client_id' => $client2->id,
            'created_at' => $quarterStart->copy()->addDays(10),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $suggestions = $analysis['content_suggestions'];
        if (count($suggestions) > 1) {
            $this->assertGreaterThanOrEqual(
                $suggestions[1]['priority'],
                $suggestions[0]['priority']
            );
        }
    }

    /** @test */
    public function it_saves_suggestions_to_database()
    {
        $quarterStart = now()->startOfQuarter();
        $client = Client::factory()->create(['industry' => 'Healthcare']);

        Project::factory()->count(3)->create([
            'client_id' => $client->id,
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        $analysis = $this->service->analyze($quarterStart);
        $count = $this->service->saveSuggestions($analysis);

        $this->assertGreaterThan(0, $count);
        $this->assertDatabaseHas('content_suggestions', [
            'status' => 'pending',
            'quarter' => $analysis['period']['label'],
        ]);
    }

    /** @test */
    public function it_updates_existing_suggestions()
    {
        $quarterStart = now()->startOfQuarter();
        $client = Client::factory()->create(['industry' => 'Tech']);

        Project::factory()->count(2)->create([
            'client_id' => $client->id,
            'created_at' => $quarterStart->copy()->addDays(5),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        // Save once
        $this->service->saveSuggestions($analysis);
        $firstCount = ContentSuggestion::count();

        // Save again - should update, not duplicate
        $this->service->saveSuggestions($analysis);
        $secondCount = ContentSuggestion::count();

        $this->assertEquals($firstCount, $secondCount);
    }

    /** @test */
    public function it_excludes_projects_outside_quarter()
    {
        $quarterStart = Carbon::parse('2024-01-01');
        $quarterEnd = Carbon::parse('2024-03-31');
        $client = Client::factory()->create(['industry' => 'Tech']);

        // Inside quarter
        Project::factory()->create([
            'client_id' => $client->id,
            'created_at' => Carbon::parse('2024-02-15'),
        ]);

        // Outside quarter (before)
        Project::factory()->create([
            'client_id' => $client->id,
            'created_at' => Carbon::parse('2023-12-15'),
        ]);

        // Outside quarter (after)
        Project::factory()->create([
            'client_id' => $client->id,
            'created_at' => Carbon::parse('2024-04-15'),
        ]);

        $analysis = $this->service->analyze($quarterStart);

        $techPattern = $analysis['industry_patterns']['Tech'] ?? null;
        $this->assertNotNull($techPattern);
        $this->assertEquals(1, $techPattern['count']);
    }

    /** @test */
    public function it_handles_empty_quarter()
    {
        $quarterStart = Carbon::parse('2020-01-01');

        $analysis = $this->service->analyze($quarterStart);

        $this->assertEmpty($analysis['industry_patterns']);
        $this->assertEmpty($analysis['service_patterns']);
        $this->assertEmpty($analysis['technology_patterns']);
        $this->assertEmpty($analysis['content_suggestions']);
    }
}
