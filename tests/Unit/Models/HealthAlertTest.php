<?php

use App\Models\Client;
use App\Models\HealthAlert;

test('has guarded attributes empty', function () {
    expect((new HealthAlert)->getGuarded())->toBe(['*']);
});

test('casts health_score to decimal', function () {
    $alert = HealthAlert::factory()->create(['health_score' => 45.5]);

    expect($alert->health_score)->toBeFloat()
        ->and((string) $alert->health_score)->toBe('45.5');
});

test('casts previous_score to decimal', function () {
    $alert = HealthAlert::factory()->create(['previous_score' => 75.0]);

    expect($alert->previous_score)->toBeFloat();
});

test('casts factors to array', function () {
    $alert = HealthAlert::factory()->create(['factors' => ['factor1' => 'value1']]);

    expect($alert->factors)->toBeArray();
});

test('casts escalation_history to array', function () {
    $alert = HealthAlert::factory()->create(['escalation_history' => [['level' => 1]]]);

    expect($alert->escalation_history)->toBeArray();
});

test('casts acknowledged_at to datetime', function () {
    $alert = HealthAlert::factory()->create(['acknowledged_at' => now()]);

    expect($alert->acknowledged_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts resolved_at to datetime', function () {
    $alert = HealthAlert::factory()->create(['resolved_at' => now()]);

    expect($alert->resolved_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts next_escalation_at to datetime', function () {
    $alert = HealthAlert::factory()->create(['next_escalation_at' => now()->addHour()]);

    expect($alert->next_escalation_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('has status constants', function () {
    expect(HealthAlert::STATUS_OPEN)->toBe('open')
        ->and(HealthAlert::STATUS_ACKNOWLEDGED)->toBe('acknowledged')
        ->and(HealthAlert::STATUS_ESCALATED)->toBe('escalated')
        ->and(HealthAlert::STATUS_RESOLVED)->toBe('resolved');
});

test('has severity constants', function () {
    expect(HealthAlert::SEVERITY_LOW)->toBe('low')
        ->and(HealthAlert::SEVERITY_MEDIUM)->toBe('medium')
        ->and(HealthAlert::SEVERITY_HIGH)->toBe('high')
        ->and(HealthAlert::SEVERITY_CRITICAL)->toBe('critical');
});

test('has type constants', function () {
    expect(HealthAlert::TYPE_HEALTH_CRITICAL)->toBe('health_critical')
        ->and(HealthAlert::TYPE_HEALTH_WARNING)->toBe('health_warning')
        ->and(HealthAlert::TYPE_SENTIMENT_NEGATIVE)->toBe('sentiment_negative')
        ->and(HealthAlert::TYPE_CHURN_RISK)->toBe('churn_risk')
        ->and(HealthAlert::TYPE_DECLINING_TREND)->toBe('declining_trend');
});

test('belongs to client relationship', function () {
    $alert = HealthAlert::factory()->create();

    expect($alert->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to acknowledged by user relationship', function () {
    $alert = HealthAlert::factory()->create();

    expect($alert->acknowledgedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to resolved by user relationship', function () {
    $alert = HealthAlert::factory()->create();

    expect($alert->resolvedBy())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('scopeOpen filters open alerts', function () {
    HealthAlert::factory()->create(['status' => HealthAlert::STATUS_OPEN]);
    HealthAlert::factory()->create(['status' => HealthAlert::STATUS_RESOLVED]);

    $openAlerts = HealthAlert::open()->count();

    expect($openAlerts)->toBe(1);
});

test('scopeUnresolved filters unresolved alerts', function () {
    HealthAlert::factory()->create(['status' => HealthAlert::STATUS_OPEN]);
    HealthAlert::factory()->create(['status' => HealthAlert::STATUS_ACKNOWLEDGED]);
    HealthAlert::factory()->create(['status' => HealthAlert::STATUS_ESCALATED]);
    HealthAlert::factory()->create(['status' => HealthAlert::STATUS_RESOLVED]);

    $unresolvedAlerts = HealthAlert::unresolved()->count();

    expect($unresolvedAlerts)->toBe(3);
});

test('scopeCritical filters critical alerts', function () {
    HealthAlert::factory()->create(['severity' => HealthAlert::SEVERITY_CRITICAL]);
    HealthAlert::factory()->create(['severity' => HealthAlert::SEVERITY_LOW]);

    $criticalAlerts = HealthAlert::critical()->count();

    expect($criticalAlerts)->toBe(1);
});

test('scopeForClient filters by client', function () {
    $client1 = Client::factory()->create();
    $client2 = Client::factory()->create();

    HealthAlert::factory()->create(['client_id' => $client1->id]);
    HealthAlert::factory()->create(['client_id' => $client2->id]);

    $client1Alerts = HealthAlert::forClient($client1->id)->count();

    expect($client1Alerts)->toBe(1);
});

test('getSeverityColorAttribute returns correct colors', function () {
    $lowAlert = HealthAlert::factory()->create(['severity' => HealthAlert::SEVERITY_LOW]);
    $mediumAlert = HealthAlert::factory()->create(['severity' => HealthAlert::SEVERITY_MEDIUM]);
    $highAlert = HealthAlert::factory()->create(['severity' => HealthAlert::SEVERITY_HIGH]);
    $criticalAlert = HealthAlert::factory()->create(['severity' => HealthAlert::SEVERITY_CRITICAL]);

    expect($lowAlert->severity_color)->toBe('emerald')
        ->and($mediumAlert->severity_color)->toBe('amber')
        ->and($highAlert->severity_color)->toBe('orange')
        ->and($criticalAlert->severity_color)->toBe('red');
});

test('getEscalationLevelNameAttribute returns correct names', function () {
    $alert0 = HealthAlert::factory()->create(['escalation_level' => 0]);
    $alert1 = HealthAlert::factory()->create(['escalation_level' => 1]);
    $alert2 = HealthAlert::factory()->create(['escalation_level' => 2]);
    $alert3 = HealthAlert::factory()->create(['escalation_level' => 3]);

    expect($alert0->escalation_level_name)->toBe('Initial')
        ->and($alert1->escalation_level_name)->toBe('Manager')
        ->and($alert2->escalation_level_name)->toBe('Director')
        ->and($alert3->escalation_level_name)->toBe('Executive');
});

test('can be created via factory', function () {
    $alert = HealthAlert::factory()->create();

    expect($alert)->toBeInstanceOf(HealthAlert::class)
        ->and($alert->exists)->toBeTrue();
});
