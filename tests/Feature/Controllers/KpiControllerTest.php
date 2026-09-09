<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can get kpi dashboard data', function () {
    $response = $this->getJson(route('kpis.index'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'pipeline' => [
            'total_pipeline',
            'weighted_pipeline',
            'win_rate',
            'won_count',
            'lost_count',
            'won_value_mtd',
            'funnel',
            'by_stage',
        ],
        'clients' => [
            'total_active',
            'avg_health',
            'health_distribution',
            'at_risk_clients',
            'by_status',
        ],
        'operations' => [
            'task_completion_rate',
            'completed_tasks_month',
            'total_tasks_month',
            'tasks_by_status',
            'overdue_tasks',
            'agent_success_rate',
            'total_agent_runs',
            'agent_costs_30d',
            'active_projects',
            'projects_by_status',
        ],
        'financial' => [
            'revenue_mtd',
            'revenue_change_pct',
            'outstanding_ar',
            'overdue_amount',
            'overdue_count',
            'avg_days_to_pay',
            'invoices_paid_mtd',
        ],
        'time_tracking' => [
            'hours_mtd',
            'billable_hours',
            'non_billable_hours',
            'utilization_rate',
            'billable_amount_mtd',
            'hours_this_week',
            'unbilled_hours',
            'top_clients',
        ],
        'github' => [
            'open_issues',
            'issues_closed_week',
            'agent_tasks',
            'prs_merged_week',
            'open_prs',
            'avg_pr_cycle_days',
            'issues_by_label',
        ],
        'trends' => [
            'pipeline',
            'tasks',
            'agent_costs',
            'overdue',
            'revenue',
            'hours',
        ],
    ]);
});

test('unauthenticated user cannot access kpis', function () {
    auth()->logout();

    $response = $this->getJson(route('kpis.index'));

    $response->assertStatus(401);
});

test('kpi data returns correct pipeline metrics', function () {
    // Create test leads
    \App\Models\Lead::factory()->create([
        'stage' => 'qualified',
        'deal_value' => 10000,
    ]);

    \App\Models\Lead::factory()->create([
        'stage' => 'proposal',
        'deal_value' => 25000,
    ]);

    \App\Models\Lead::factory()->create([
        'stage' => 'won',
        'deal_value' => 50000,
    ]);

    $response = $this->getJson(route('kpis.index'));

    $response->assertStatus(200);
    expect($response->json('pipeline.total_pipeline'))->toBe(35000.0);
    expect($response->json('pipeline.won_count'))->toBe(1);
});

test('kpi data returns correct client health metrics', function () {
    \App\Models\Client::factory()->create([
        'status' => 'active',
        'health_score' => 9,
    ]);

    \App\Models\Client::factory()->create([
        'status' => 'active',
        'health_score' => 4,
    ]);

    $response = $this->getJson(route('kpis.index'));

    $response->assertStatus(200);
    expect($response->json('clients.total_active'))->toBe(2);
    expect($response->json('clients.health_distribution.healthy'))->toBe(1);
    expect($response->json('clients.health_distribution.critical'))->toBe(1);
});

test('kpi data returns correct task metrics', function () {
    \App\Models\Task::factory()->create([
        'status' => 'completed',
        'created_at' => now(),
    ]);

    \App\Models\Task::factory()->create([
        'status' => 'pending',
        'created_at' => now(),
    ]);

    $response = $this->getJson(route('kpis.index'));

    $response->assertStatus(200);
    expect($response->json('operations.total_tasks_month'))->toBe(2);
    expect($response->json('operations.completed_tasks_month'))->toBe(1);
});
