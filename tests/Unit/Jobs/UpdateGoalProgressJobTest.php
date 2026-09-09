<?php

use App\Jobs\UpdateGoalProgressJob;
use App\Models\StrategicGoal;
use App\Services\BusinessIntelligenceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    UpdateGoalProgressJob::dispatch();

    Queue::assertPushed(UpdateGoalProgressJob::class);
});

test('handle updates all active goals', function () {
    $activeGoal1 = StrategicGoal::factory()->create([
        'status' => StrategicGoal::STATUS_ACTIVE,
        'fiscal_year' => 2024,
    ]);

    $activeGoal2 = StrategicGoal::factory()->create([
        'status' => StrategicGoal::STATUS_ACTIVE,
        'fiscal_year' => 2024,
    ]);

    $inactiveGoal = StrategicGoal::factory()->create([
        'status' => 'completed',
    ]);

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('updateGoalActuals')
        ->twice()
        ->with(Mockery::on(fn ($goal) => $goal->status === StrategicGoal::STATUS_ACTIVE));

    $job = new UpdateGoalProgressJob;
    $job->handle($biService);
});

test('handle logs success for each goal', function () {
    Log::spy();

    $goal = StrategicGoal::factory()->create([
        'status' => StrategicGoal::STATUS_ACTIVE,
        'fiscal_year' => 2024,
    ]);

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('updateGoalActuals')->once();

    $job = new UpdateGoalProgressJob;
    $job->handle($biService);

    Log::shouldHaveReceived('info')
        ->with('Updated goal progress', Mockery::on(fn ($ctx) => $ctx['goal_id'] === $goal->id && $ctx['fiscal_year'] === 2024
        ));
});

test('handle catches and logs errors for individual goals', function () {
    Log::spy();

    $goal1 = StrategicGoal::factory()->create(['status' => StrategicGoal::STATUS_ACTIVE]);
    $goal2 = StrategicGoal::factory()->create(['status' => StrategicGoal::STATUS_ACTIVE]);

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('updateGoalActuals')
        ->once()
        ->andThrow(new Exception('Test error'));
    $biService->shouldReceive('updateGoalActuals')
        ->once();

    $job = new UpdateGoalProgressJob;
    $job->handle($biService);

    Log::shouldHaveReceived('error')
        ->with('Failed to update goal progress', Mockery::any());
});

test('handle continues processing after error', function () {
    $goal1 = StrategicGoal::factory()->create(['status' => StrategicGoal::STATUS_ACTIVE]);
    $goal2 = StrategicGoal::factory()->create(['status' => StrategicGoal::STATUS_ACTIVE]);

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('updateGoalActuals')
        ->twice()
        ->andReturnUsing(function ($goal) use ($goal1) {
            if ($goal->id === $goal1->id) {
                throw new Exception('Error for first goal');
            }
        });

    $job = new UpdateGoalProgressJob;

    // Should not throw exception
    expect(fn () => $job->handle($biService))->not->toThrow(Exception::class);
});

test('handle does nothing when no active goals', function () {
    StrategicGoal::factory()->create(['status' => 'completed']);
    StrategicGoal::factory()->create(['status' => 'cancelled']);

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldNotReceive('updateGoalActuals');

    $job = new UpdateGoalProgressJob;
    $job->handle($biService);
});

test('handle only processes active status goals', function () {
    $active = StrategicGoal::factory()->create(['status' => StrategicGoal::STATUS_ACTIVE]);
    $draft = StrategicGoal::factory()->create(['status' => 'draft']);
    $completed = StrategicGoal::factory()->create(['status' => 'completed']);

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('updateGoalActuals')
        ->once()
        ->with(Mockery::on(fn ($goal) => $goal->id === $active->id));

    $job = new UpdateGoalProgressJob;
    $job->handle($biService);
});

test('job implements ShouldQueue interface', function () {
    $job = new UpdateGoalProgressJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(UpdateGoalProgressJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
