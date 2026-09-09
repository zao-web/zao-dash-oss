<?php

namespace App\Mcp\Tools;

use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class BuildRetainerNarrativeFromTextTool extends Tool
{
    protected string $name = 'build-retainer-narrative-from-text';

    protected string $title = 'Build Retainer Narrative From Transcript';

    protected string $description = <<<'TEXT'
        Build a retainer period's narrative + AI hour estimates from a pasted
        transcript, for work that never synced into Slack/email/commits (e.g. a
        Slack conversation the bot/user-token can't see, an inactive workspace,
        or an email thread). Runs the transcript through the same LLM estimation
        pipeline as the normal report and persists ai_estimated time entries +
        caches the narrative, so the report reflects it on next load. Use
        dry_run=true to preview without saving.
        TEXT;

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'period_id' => 'required|integer|exists:retainer_periods,id',
            'transcript' => 'required|string|min:1',
            'dry_run' => 'nullable|boolean',
        ], [
            'period_id.exists' => 'No retainer period exists with that id.',
            'transcript.required' => 'Paste the conversation/transcript to build the narrative from.',
        ]);

        $period = RetainerPeriod::with('client')->findOrFail($request->integer('period_id'));
        $dryRun = $request->boolean('dry_run');

        $result = app(RetainerNarrativeService::class)->buildNarrativeFromText(
            $period,
            (string) $request->get('transcript'),
            persist: ! $dryRun,
        );

        if (! empty($result['warnings'])) {
            return Response::error('Narrative could not be built: '.implode(' / ', $result['warnings']));
        }

        $topics = $result['topics'] ?? [];

        return Response::structured([
            'period_id' => $period->id,
            'client' => $period->client?->name,
            'period' => $period->period_start.' → '.$period->period_end,
            'persisted' => ! $dryRun,
            'dry_run' => $dryRun,
            'total_estimated_hours' => $result['total_estimated_hours'] ?? 0,
            'value_summary' => $result['value_summary'] ?? '',
            'topics' => collect($topics)->map(fn ($t) => [
                'title' => $t['title'] ?? null,
                'status' => $t['status'] ?? null,
                'estimated_hours' => $t['estimated_hours'] ?? 0,
                'start_date' => $t['start_date'] ?? null,
                'end_date' => $t['end_date'] ?? null,
                'summary' => $t['summary'] ?? null,
            ])->all(),
            'note' => $dryRun
                ? 'Dry run — nothing saved. Re-run with dry_run=false to persist and update the report.'
                : 'Persisted as ai_estimated time entries and cached. Refresh the retainer report to see it.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period_id' => $schema->integer()
                ->description('retainer_periods.id — the number in the report URL, e.g. /retainers/5/report → 5.'),
            'transcript' => $schema->string()
                ->description('The full conversation/transcript for the period. Paste Slack/email/meeting text; the model estimates hours directly from it.'),
            'dry_run' => $schema->boolean()
                ->description('Preview the topics, hours, and value summary without saving. Defaults to false (persists).'),
        ];
    }
}
