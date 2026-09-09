<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\AdjustRetainerTimeEntryTool;
use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->client = Client::factory()->create(['name' => 'Example Client LLC']);
    $this->period = RetainerPeriod::factory()->create([
        'client_id' => $this->client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);
    $this->entry = TimeEntry::create([
        'client_id' => $this->client->id,
        'retainer_period_id' => $this->period->id,
        'source' => 'ai_estimated',
        'hours' => 1.0,
        'spent_date' => '2026-05-15',
        'notes' => 'Update WordPress plugins',
        'is_billable' => true,
        'is_billed' => false,
    ]);
});

test('lists the period entries when given only a period_id', function () {
    $response = ZaoDashServer::actingAs($this->admin)->tool(AdjustRetainerTimeEntryTool::class, [
        'period_id' => $this->period->id,
    ]);

    $response->assertOk();
    $response->assertSee('Update WordPress plugins');
});

test('corrects hours and promotes the entry to manual', function () {
    $response = ZaoDashServer::actingAs($this->admin)->tool(AdjustRetainerTimeEntryTool::class, [
        'entry_id' => $this->entry->id,
        'hours' => 5.5,
        'notes' => 'Plugin updates across all sites (took longer than estimated)',
    ]);

    $response->assertOk();

    $this->entry->refresh();
    expect((float) $this->entry->hours)->toBe(5.5)
        ->and($this->entry->source)->toBe('manual')
        ->and($this->entry->notes)->toContain('took longer');
});

test('a promoted entry survives narrative regeneration', function () {
    // Correct it (promote to manual), then run the persist step that a
    // narrative refresh runs — which deletes ai_estimated rows.
    app(AdjustRetainerTimeEntryTool::class);
    ZaoDashServer::actingAs($this->admin)->tool(AdjustRetainerTimeEntryTool::class, [
        'entry_id' => $this->entry->id,
        'hours' => 5.5,
    ]);

    app(\App\Services\Reports\RetainerNarrativeService::class)
        ->persistAsTimeEntries($this->period, []); // empty topics → wipes ai_estimated only

    expect(TimeEntry::find($this->entry->id))->not->toBeNull()
        ->and((float) TimeEntry::find($this->entry->id)->hours)->toBe(5.5);
});

test('removes an entry (client handled the work)', function () {
    $response = ZaoDashServer::actingAs($this->admin)->tool(AdjustRetainerTimeEntryTool::class, [
        'entry_id' => $this->entry->id,
        'delete' => true,
    ]);

    $response->assertOk();
    expect(TimeEntry::find($this->entry->id))->toBeNull();
});

test('validates the entry exists', function () {
    $response = ZaoDashServer::actingAs($this->admin)->tool(AdjustRetainerTimeEntryTool::class, [
        'entry_id' => 999999,
        'hours' => 2,
    ]);

    $response->assertHasErrors();
});
