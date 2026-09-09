<?php

use App\Jobs\CalculateFunnelMetricsJob;
use App\Models\FunnelMetrics;
use App\Services\BusinessIntelligenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

test('job can be dispatched', function () {
    Queue::fake();

    CalculateFunnelMetricsJob::dispatch();

    Queue::assertPushed(CalculateFunnelMetricsJob::class);
});

test('handle creates daily snapshot', function () {
    Carbon::setTestNow('2024-03-15 10:00:00');

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(
            FunnelMetrics::TYPE_DAILY,
            Mockery::on(fn ($date) => $date->isSameDay('2024-03-15 00:00:00')),
            Mockery::on(fn ($date) => $date->isSameDay('2024-03-15 23:59:59'))
        );

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Carbon::setTestNow();
});

test('handle creates weekly snapshot on Sunday', function () {
    Carbon::setTestNow('2024-03-17'); // Sunday

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_DAILY, Mockery::any(), Mockery::any());
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(
            FunnelMetrics::TYPE_WEEKLY,
            Mockery::on(fn ($date) => $date->isMonday()),
            Mockery::on(fn ($date) => $date->isSunday())
        );

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Carbon::setTestNow();
});

test('handle does not create weekly snapshot on non-Sunday', function () {
    Carbon::setTestNow('2024-03-15'); // Friday

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_DAILY, Mockery::any(), Mockery::any());
    $biService->shouldNotReceive('storeFunnelSnapshot')
        ->with(FunnelMetrics::TYPE_WEEKLY, Mockery::any(), Mockery::any());

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Carbon::setTestNow();
});

test('handle creates monthly snapshot on last day of month', function () {
    Carbon::setTestNow('2024-03-31'); // Last day of March

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_DAILY, Mockery::any(), Mockery::any());
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(
            FunnelMetrics::TYPE_MONTHLY,
            Mockery::on(fn ($date) => $date->isSameDay('2024-03-01')),
            Mockery::on(fn ($date) => $date->isSameDay('2024-03-31'))
        );

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Carbon::setTestNow();
});

test('handle does not create monthly snapshot on non-last-day', function () {
    Carbon::setTestNow('2024-03-15'); // Mid-month

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_DAILY, Mockery::any(), Mockery::any());
    $biService->shouldNotReceive('storeFunnelSnapshot')
        ->with(FunnelMetrics::TYPE_MONTHLY, Mockery::any(), Mockery::any());

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Carbon::setTestNow();
});

test('handle creates all snapshots on last Sunday of month', function () {
    Carbon::setTestNow('2024-03-31'); // Sunday and last day

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_DAILY, Mockery::any(), Mockery::any());
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_WEEKLY, Mockery::any(), Mockery::any());
    $biService->shouldReceive('storeFunnelSnapshot')
        ->once()
        ->with(FunnelMetrics::TYPE_MONTHLY, Mockery::any(), Mockery::any());

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Carbon::setTestNow();
});

test('handle logs success', function () {
    Log::spy();
    Carbon::setTestNow('2024-03-15');

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')->once();

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Log::shouldHaveReceived('info')
        ->with('Calculated funnel metrics', Mockery::on(fn ($ctx) => $ctx['date'] === '2024-03-15' &&
            $ctx['is_sunday'] === false &&
            $ctx['is_month_end'] === false
        ));

    Carbon::setTestNow();
});

test('handle catches and logs errors', function () {
    Log::spy();

    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->andThrow(new Exception('Database error'));

    $job = new CalculateFunnelMetricsJob;
    $job->handle($biService);

    Log::shouldHaveReceived('error')
        ->with('Failed to calculate funnel metrics', Mockery::on(fn ($ctx) => $ctx['error'] === 'Database error'
        ));
});

test('handle does not throw exception on error', function () {
    $biService = Mockery::mock(BusinessIntelligenceService::class);
    $biService->shouldReceive('storeFunnelSnapshot')
        ->andThrow(new Exception('Test error'));

    $job = new CalculateFunnelMetricsJob;

    expect(fn () => $job->handle($biService))->not->toThrow(Exception::class);
});

test('job implements ShouldQueue interface', function () {
    $job = new CalculateFunnelMetricsJob;

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

test('job uses required traits', function () {
    $traits = class_uses(CalculateFunnelMetricsJob::class);

    expect($traits)->toContain(\Illuminate\Bus\Queueable::class)
        ->and($traits)->toContain(\Illuminate\Foundation\Bus\Dispatchable::class)
        ->and($traits)->toContain(\Illuminate\Queue\InteractsWithQueue::class)
        ->and($traits)->toContain(\Illuminate\Queue\SerializesModels::class);
});
