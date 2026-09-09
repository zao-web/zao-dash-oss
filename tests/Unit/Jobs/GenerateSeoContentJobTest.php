<?php

use App\Enums\SeoPageStatus;
use App\Jobs\GenerateSeoContentJob;
use App\Models\Agent;
use App\Models\SeoPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Log::spy();
});

test('creates new page when url does not exist', function () {
    $job = new GenerateSeoContentJob(
        title: 'Test Title',
        keyword: 'test keyword',
        playbook: 'Comparisons',
        urlSlug: 'test-new-page',
        proprietaryData: [],
        priority: 5,
        orchestratorRunId: null
    );

    $job->handle();

    expect(SeoPage::where('page_url', '/test-new-page')->exists())->toBeTrue();
    $page = SeoPage::where('page_url', '/test-new-page')->first();
    expect($page->status)->toBe(SeoPageStatus::Draft); // No active agent, so falls back to draft
    expect($page->target_keyword)->toBe('test keyword');
    expect($page->page_type)->toBe('comparison');
});

test('skips generation when page already exists with published status', function () {
    $existingPage = SeoPage::factory()->create([
        'page_url' => '/existing-published',
        'status' => SeoPageStatus::Published,
        'orchestrator_run_id' => null,
    ]);

    $job = new GenerateSeoContentJob(
        title: 'Test Title',
        keyword: 'test keyword',
        playbook: 'Comparisons',
        urlSlug: 'existing-published',
        proprietaryData: [],
        priority: 5,
        orchestratorRunId: null
    );

    $job->handle();

    // Page should not be modified
    $existingPage->refresh();
    expect($existingPage->status)->toBe(SeoPageStatus::Published);

    Log::shouldHaveReceived('info')
        ->with('SEO page already exists, skipping generation', \Mockery::any())
        ->once();
});

test('skips generation when page is currently generating', function () {
    SeoPage::factory()->create([
        'page_url' => '/currently-generating',
        'status' => SeoPageStatus::Generating,
        'orchestrator_run_id' => null,
    ]);

    $job = new GenerateSeoContentJob(
        title: 'Test Title',
        keyword: 'test keyword',
        playbook: 'Comparisons',
        urlSlug: 'currently-generating',
        proprietaryData: [],
    );

    $job->handle();

    Log::shouldHaveReceived('info')
        ->with('SEO page already exists, skipping generation', \Mockery::any())
        ->once();
});

test('retries generation for failed pages', function () {
    $existingPage = SeoPage::factory()->create([
        'page_url' => '/failed-page',
        'status' => SeoPageStatus::Failed,
        'orchestrator_run_id' => null,
    ]);

    $job = new GenerateSeoContentJob(
        title: 'Test Title',
        keyword: 'test keyword',
        playbook: 'Comparisons',
        urlSlug: 'failed-page',
        proprietaryData: [],
        orchestratorRunId: null
    );

    $job->handle();

    $existingPage->refresh();
    // Page status should be draft (no active agent)
    expect($existingPage->status)->toBe(SeoPageStatus::Draft);

    Log::shouldHaveReceived('info')
        ->with('Retrying SEO page generation', \Mockery::any())
        ->once();
});

test('retries generation for draft pages', function () {
    $existingPage = SeoPage::factory()->create([
        'page_url' => '/draft-page',
        'status' => SeoPageStatus::Draft,
        'orchestrator_run_id' => null,
    ]);

    $job = new GenerateSeoContentJob(
        title: 'Test Title',
        keyword: 'test keyword',
        playbook: 'Comparisons',
        urlSlug: 'draft-page',
        proprietaryData: [],
        orchestratorRunId: null
    );

    $job->handle();

    $existingPage->refresh();
    // Page status should be draft (no active agent)
    expect($existingPage->status)->toBe(SeoPageStatus::Draft);

    Log::shouldHaveReceived('info')
        ->with('Retrying SEO page generation', \Mockery::any())
        ->once();
});

test('maps playbook to correct page type', function () {
    $mappings = [
        ['playbook' => 'Comparisons', 'expected' => 'comparison'],
        ['playbook' => 'Templates', 'expected' => 'template'],
        ['playbook' => 'Curation', 'expected' => 'curation'],
        ['playbook' => 'Calculators', 'expected' => 'tool'],
        ['playbook' => 'Examples', 'expected' => 'gallery'],
        ['playbook' => 'Location', 'expected' => 'location'],
        ['playbook' => 'Glossary', 'expected' => 'guide'],
        ['playbook' => 'Directory', 'expected' => 'directory'],
        ['playbook' => 'Case Study', 'expected' => 'case-study'],
        ['playbook' => 'Unknown', 'expected' => 'landing'],
    ];

    foreach ($mappings as $index => $mapping) {
        $job = new GenerateSeoContentJob(
            title: 'Test',
            keyword: 'test',
            playbook: $mapping['playbook'],
            urlSlug: "mapping-test-{$index}",
            proprietaryData: [],
        );

        $job->handle();

        $page = SeoPage::where('page_url', "/mapping-test-{$index}")->first();
        expect($page->page_type)->toBe($mapping['expected']);
    }
});

test('dispatches agent run when content writer agent is active', function () {
    Queue::fake();

    $agent = Agent::factory()->create([
        'slug' => 'seo-content-writer',
        'status' => 'active',
    ]);

    $job = new GenerateSeoContentJob(
        title: 'Test Title',
        keyword: 'test keyword',
        playbook: 'Comparisons',
        urlSlug: 'agent-dispatch-test',
        proprietaryData: ['key' => 'value'],
        orchestratorRunId: null
    );

    $job->handle();

    $page = SeoPage::where('page_url', '/agent-dispatch-test')->first();
    expect($page->status)->toBe(SeoPageStatus::Generating);

    expect($agent->runs()->count())->toBe(1);
    $run = $agent->runs()->first();
    expect($run->status)->toBe('running');
    expect($run->context['seo_page_id'])->toBe($page->id);

    Queue::assertPushed(\App\Jobs\RunAgentJob::class);
});
