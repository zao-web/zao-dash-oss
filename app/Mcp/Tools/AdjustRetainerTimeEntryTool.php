<?php

namespace App\Mcp\Tools;

use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Services\Reports\RetainerTimeEntryAdjuster;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class AdjustRetainerTimeEntryTool extends Tool
{
    protected string $name = 'adjust-retainer-time-entry';

    protected string $title = 'Adjust Retainer Time Entry';

    protected string $description = <<<'TEXT'
        Correct a retainer period's time entries against ground truth. Pass
        period_id alone to LIST the period's entries (with ids, hours, notes).
        Pass entry_id with hours/notes/is_billable to CORRECT an entry — it is
        promoted to source='manual' so a later narrative regenerate won't erase
        it. Pass entry_id + delete=true to REMOVE an entry (e.g. work the client
        handled). Use this to fix AI estimates: zero/remove work that didn't
        happen, raise hours that took longer than estimated.
        TEXT;

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'period_id' => 'required_without:entry_id|integer|exists:retainer_periods,id',
            'entry_id' => 'required_without:period_id|integer|exists:time_entries,id',
            'hours' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'is_billable' => 'nullable|boolean',
            'delete' => 'nullable|boolean',
        ]);

        // List mode: no entry_id → show the period's entries so the caller can
        // pick which to correct.
        if (! $request->get('entry_id')) {
            return $this->listEntries((int) $request->get('period_id'));
        }

        $entry = TimeEntry::findOrFail($request->integer('entry_id'));
        $adjuster = app(RetainerTimeEntryAdjuster::class);

        if ($request->boolean('delete')) {
            $periodId = $entry->retainer_period_id;
            $adjuster->remove($entry);

            return Response::structured([
                'deleted' => true,
                'entry_id' => $request->integer('entry_id'),
                'period_id' => $periodId,
                'note' => 'Entry removed. If you regenerate the narrative later it may re-appear from the raw evidence.',
            ]);
        }

        $changes = array_filter(
            $request->only(['hours', 'notes', 'is_billable']),
            fn ($v) => $v !== null,
        );

        if (empty($changes)) {
            return Response::error('Nothing to change — provide hours, notes, is_billable, or delete=true.');
        }

        $entry = $adjuster->adjust($entry, $changes);

        return Response::structured([
            'adjusted' => true,
            'entry' => $this->present($entry),
            'note' => 'Promoted to source=manual; it will survive narrative regeneration.',
        ]);
    }

    protected function listEntries(int $periodId): Response|ResponseFactory
    {
        $period = RetainerPeriod::with('client')->findOrFail($periodId);

        $entries = TimeEntry::query()
            ->where('retainer_period_id', $periodId)
            ->orderBy('spent_date')
            ->orderBy('id')
            ->get();

        return Response::structured([
            'period_id' => $period->id,
            'client' => $period->client?->name,
            'period' => $period->period_start.' → '.$period->period_end,
            'total_hours' => round((float) $entries->sum('hours'), 2),
            'entries' => $entries->map(fn ($e) => $this->present($e))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->spent_date?->format('Y-m-d'),
            'hours' => round((float) $entry->hours, 2),
            'source' => $entry->source,
            'is_billable' => (bool) $entry->is_billable,
            'notes' => $entry->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period_id' => $schema->integer()->description('Retainer period id. Pass alone to list the period\'s entries.'),
            'entry_id' => $schema->integer()->description('time_entries.id to adjust or delete.'),
            'hours' => $schema->number()->description('Corrected hours for the entry.'),
            'notes' => $schema->string()->description('Corrected description/notes for the entry.'),
            'is_billable' => $schema->boolean()->description('Whether the entry is billable.'),
            'delete' => $schema->boolean()->description('Set true to remove the entry entirely.'),
        ];
    }
}
