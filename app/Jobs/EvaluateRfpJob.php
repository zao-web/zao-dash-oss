<?php

namespace App\Jobs;

use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpEvaluationService;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Evaluate RFP opportunities for agency fit using the scoring rubric.
 *
 * Can evaluate a single opportunity by ID or batch-evaluate all
 * opportunities with status 'discovered'.
 */
class EvaluateRfpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 60;

    public function __construct(
        public ?int $rfpOpportunityId = null,
    ) {
        $this->onQueue('slack-mentions');
    }

    public function handle(RfpEvaluationService $evaluationService): void
    {
        if ($this->rfpOpportunityId) {
            $this->evaluateSingle($this->rfpOpportunityId, $evaluationService);
        } else {
            $this->evaluateBatch($evaluationService);
        }
    }

    /**
     * Evaluate a single RFP opportunity by ID.
     */
    protected function evaluateSingle(int $opportunityId, RfpEvaluationService $evaluationService): void
    {
        $opportunity = RfpOpportunity::find($opportunityId);

        if (! $opportunity) {
            Log::warning('EvaluateRfpJob: opportunity not found', [
                'opportunity_id' => $opportunityId,
            ]);

            return;
        }

        $this->evaluateOpportunity($opportunity, $evaluationService);
    }

    /**
     * Batch-evaluate all discovered opportunities.
     */
    protected function evaluateBatch(RfpEvaluationService $evaluationService): void
    {
        $opportunities = RfpOpportunity::query()
            ->where('status', 'discovered')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($opportunities->isEmpty()) {
            Log::info('EvaluateRfpJob: no discovered opportunities to evaluate');

            return;
        }

        Log::info('EvaluateRfpJob: starting batch evaluation', [
            'count' => $opportunities->count(),
        ]);

        $results = ['qualified' => 0, 'evaluating' => 0, 'declined' => 0];

        foreach ($opportunities as $opportunity) {
            $result = $this->evaluateOpportunity($opportunity, $evaluationService);

            if ($result) {
                $results[$result['recommended_status']]++;
            }
        }

        Log::info('EvaluateRfpJob: batch evaluation completed', $results);
    }

    /**
     * Evaluate a single opportunity and persist the results.
     *
     * @return array|null The evaluation result or null on failure
     */
    protected function evaluateOpportunity(RfpOpportunity $opportunity, RfpEvaluationService $evaluationService): ?array
    {
        // Skip already-expired opportunities — decline immediately without scoring
        if ($opportunity->isExpired()) {
            $opportunity->update([
                'status' => 'declined',
                'priority' => 'low',
                'decline_reason' => "Deadline passed ({$opportunity->submission_deadline->toDateString()}).",
            ]);

            Log::info('EvaluateRfpJob: auto-declined expired opportunity', [
                'opportunity_id' => $opportunity->id,
                'title' => $opportunity->title,
                'deadline' => $opportunity->submission_deadline->toDateString(),
            ]);

            return ['recommended_status' => 'declined'];
        }

        try {
            $result = $evaluationService->evaluate($opportunity);

            $updates = [
                'fit_score' => $result['fit_score'],
                'fit_score_breakdown' => $result['fit_score_breakdown'],
                'status' => $result['recommended_status'],
                'priority' => $result['recommended_priority'],
            ];

            // Add decline reason for auto-declined opportunities
            if ($result['recommended_status'] === 'declined') {
                $updates['decline_reason'] = $this->buildDeclineReason($result['fit_score_breakdown']);
            }

            $opportunity->update($updates);

            Log::info('EvaluateRfpJob: evaluated opportunity', [
                'opportunity_id' => $opportunity->id,
                'title' => $opportunity->title,
                'fit_score' => $result['fit_score'],
                'status' => $result['recommended_status'],
                'priority' => $result['recommended_priority'],
            ]);

            $notifier = app(RfpSlackNotifier::class);

            if ($result['recommended_status'] === 'qualified') {
                // Auto-retrieve document if not already available
                if (! $opportunity->full_document_url && ! $opportunity->full_document_path) {
                    RetrieveRfpDocumentJob::dispatch($opportunity->id);
                }

                // Collapse qualified → pursuing → proposal_drafting automatically
                GenerateRfpProposalJob::dispatch($opportunity->id)->delay(now()->addSeconds(10));

                $notifier->notifyQualified($opportunity);

                Log::info('EvaluateRfpJob: qualified — dispatched proposal generation', [
                    'opportunity_id' => $opportunity->id,
                ]);
            } elseif ($result['recommended_status'] === 'evaluating') {
                $notifier->notifyNeedsReview($opportunity);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('EvaluateRfpJob: failed to evaluate opportunity', [
                'opportunity_id' => $opportunity->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a human-readable decline reason from the score breakdown.
     */
    protected function buildDeclineReason(array $breakdown): string
    {
        $weakAreas = [];

        foreach ($breakdown as $dimension => $data) {
            $percentage = ($data['max'] > 0) ? ($data['score'] / $data['max']) * 100 : 0;

            if ($percentage < 40) {
                $label = str_replace('_', ' ', $dimension);
                $weakAreas[] = "{$label} ({$data['score']}/{$data['max']})";
            }
        }

        if (empty($weakAreas)) {
            return 'Overall score below qualification threshold.';
        }

        return 'Low scores in: '.implode(', ', $weakAreas).'.';
    }
}
