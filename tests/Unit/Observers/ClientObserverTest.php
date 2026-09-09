<?php

use App\Models\Client;
use App\Services\HealthAlertEscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('observer calls escalation service when health score drops below 4', function () {
    $client = Client::factory()->create(['health_score' => 5.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) use ($client) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once()
            ->withArgs(function ($clientArg, $oldScore, $newScore) use ($client) {
                return $clientArg->id === $client->id
                    && $oldScore === 5.0
                    && $newScore === 3.5;
            });
    });

    $client->update(['health_score' => 3.5]);
});

test('observer calls escalation service when health score drops below 6', function () {
    $client = Client::factory()->create(['health_score' => 7.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) use ($client) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once()
            ->withArgs(function ($clientArg, $oldScore, $newScore) use ($client) {
                return $clientArg->id === $client->id
                    && $oldScore === 7.0
                    && $newScore === 5.5;
            });
    });

    $client->update(['health_score' => 5.5]);
});

test('observer calls escalation service when health score drops by 1.5 or more points', function () {
    $client = Client::factory()->create(['health_score' => 8.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) use ($client) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once()
            ->withArgs(function ($clientArg, $oldScore, $newScore) use ($client) {
                return $clientArg->id === $client->id
                    && $oldScore === 8.0
                    && $newScore === 6.5;
            });
    });

    $client->update(['health_score' => 6.5]);
});

test('observer does not call escalation service when health score increases', function () {
    $client = Client::factory()->create(['health_score' => 5.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createAlertFromHealthDrop');
    });

    $client->update(['health_score' => 6.0]);
});

test('observer does not call escalation service for small health score drops', function () {
    $client = Client::factory()->create(['health_score' => 7.5]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createAlertFromHealthDrop');
    });

    // Drop of only 0.5 points, not crossing thresholds
    $client->update(['health_score' => 7.0]);
});

test('observer does not call escalation service when health score does not change', function () {
    $client = Client::factory()->create(['health_score' => 7.5]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createAlertFromHealthDrop');
    });

    $client->update(['name' => 'Updated Name']);
});

test('observer detects alert for drop from healthy to at-risk range', function () {
    $client = Client::factory()->create(['health_score' => 6.5]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once()
            ->withArgs(function ($clientArg, $oldScore, $newScore) {
                return $oldScore === 6.5 && $newScore === 5.5;
            });
    });

    $client->update(['health_score' => 5.5]);
});

test('observer detects alert for drop from at-risk to critical range', function () {
    $client = Client::factory()->create(['health_score' => 4.5]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once()
            ->withArgs(function ($clientArg, $oldScore, $newScore) {
                return $oldScore === 4.5 && $newScore === 3.5;
            });
    });

    $client->update(['health_score' => 3.5]);
});

test('observer does not alert for small drops within same range', function () {
    $client = Client::factory()->create(['health_score' => 7.5]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createAlertFromHealthDrop');
    });

    // Small drop within healthy range (6+)
    $client->update(['health_score' => 7.2]);
});

test('observer handles exact threshold crossings', function () {
    $client = Client::factory()->create(['health_score' => 6.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once();
    });

    // Exact crossing of 6.0 threshold
    $client->update(['health_score' => 5.9]);
});

test('observer handles float precision in health scores', function () {
    $client = Client::factory()->create(['health_score' => 6.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->once();
    });

    $client->update(['health_score' => 3.9]);
});

test('observer only triggers on health_score field changes', function () {
    $client = Client::factory()->create(['health_score' => 8.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createAlertFromHealthDrop');
    });

    // Update multiple fields but not health_score
    $client->update([
        'name' => 'New Name',
        'website' => 'https://example.com',
        'status' => 'inactive',
    ]);
});

test('observer handles multiple consecutive health score drops', function () {
    $client = Client::factory()->create(['health_score' => 8.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')
            ->twice();
    });

    // First drop: 8.0 -> 6.5 (1.5+ point drop)
    $client->update(['health_score' => 6.5]);

    // Second drop: 6.5 -> 5.5 (crosses 6.0 threshold)
    $client->refresh();
    $client->update(['health_score' => 5.5]);
});

test('shouldCreateAlert returns true for critical threshold crossing', function () {
    $client = Client::factory()->create(['health_score' => 4.5]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')->once();
    });

    $client->update(['health_score' => 3.8]);
});

test('shouldCreateAlert returns true for significant drop', function () {
    $client = Client::factory()->create(['health_score' => 9.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldReceive('createAlertFromHealthDrop')->once();
    });

    // 2.0 point drop, exceeds 1.5 point threshold
    $client->update(['health_score' => 7.0]);
});

test('shouldCreateAlert returns false for minor increases', function () {
    $client = Client::factory()->create(['health_score' => 5.0]);

    $this->mock(HealthAlertEscalationService::class, function (MockInterface $mock) {
        $mock->shouldNotReceive('createAlertFromHealthDrop');
    });

    $client->update(['health_score' => 5.5]);
});
