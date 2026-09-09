<?php

namespace App\Services\Tax;

use App\Models\FinancialDocument;
use App\Models\QuickBooksConnection;
use App\Models\TaxCalendarEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaxConfidenceReviewService
{
    public function build(
        Collection $qboConnections,
        Collection $calendarEvents,
        Collection $taxEstimates,
        Collection $taxDocuments,
    ): array {
        $gates = collect([
            $this->returnPacketGate($taxDocuments),
            $this->filingAcceptanceGate($taxDocuments),
            $this->paymentProofGate($taxDocuments, $taxEstimates),
            $this->transcriptProofGate($taxDocuments),
            $this->passThroughRollupGate($taxDocuments, $qboConnections),
            $this->reasonableCompensationGate($taxDocuments),
            $this->bankBooksTieOutGate($taxDocuments, $qboConnections),
            $this->openIssueClearanceGate($taxDocuments, $calendarEvents),
        ]);

        $maxPoints = (int) $gates->sum('max_points');
        $earnedPoints = (int) $gates->sum('earned_points');
        $confidencePercent = $maxPoints > 0 ? (int) floor(($earnedPoints / $maxPoints) * 100) : 0;
        $blockingGates = $gates->reject(fn (array $gate): bool => $gate['status'] === 'complete')->values();
        $currentGate = $blockingGates->first();

        return [
            'target_label' => '100% evidence confidence',
            'status' => $confidencePercent === 100 ? 'ready_for_system_signoff' : 'blocked_by_evidence',
            'status_label' => $confidencePercent === 100 ? 'Ready for Tax Office sign-off' : 'Evidence still missing',
            'confidence_percent' => $confidencePercent,
            'earned_points' => $earnedPoints,
            'max_points' => $maxPoints,
            'complete_gate_count' => $gates->where('status', 'complete')->count(),
            'blocking_gate_count' => $blockingGates->count(),
            'total_gate_count' => $gates->count(),
            'mode' => 'Internal Tax Office review with no outside CPA hand-off. Owner approval is still required before filing, paying, exporting, or changing records.',
            'definition' => 'The system can only show 100% when every required source-evidence gate is complete: filed-return packet, acceptance proof, payment proof, transcript proof, pass-through rollup, reasonable compensation, bank/books tie-out, and open issue clearance.',
            'current_gate' => $currentGate ? [
                'code' => $currentGate['code'],
                'title' => $currentGate['title'],
                'status' => $currentGate['status'],
                'action' => $currentGate['action'],
            ] : [
                'code' => 'system_signoff',
                'title' => 'Tax Office sign-off',
                'status' => 'complete',
                'action' => 'All gates are complete. Build the filing or amendment decision record with owner approval before any external action.',
            ],
            'gates' => $gates->values()->toArray(),
        ];
    }

    protected function returnPacketGate(Collection $taxDocuments): array
    {
        $documents = $this->matchingDocuments($taxDocuments, ['tax_return'], [
            'tax return',
            'return packet',
            '1040',
            '1120-s',
            '1120s',
            '1065',
        ]);

        return $this->documentGate(
            code: 'return_packet_inventory',
            title: 'Return packet inventoried',
            maxPoints: 12,
            documents: $documents,
            evidenceSlots: ['1040 packet', '1120-S packet', '1065 packet if applicable', 'State and local return packets'],
            action: 'Upload or mark reviewed the filed return packet for every entity and jurisdiction in the year being reviewed.',
        );
    }

    protected function filingAcceptanceGate(Collection $taxDocuments): array
    {
        $acceptanceDocuments = $this->matchingDocuments($taxDocuments, ['efile_acceptance', 'filing_acceptance'], [
            'efile acceptance',
            'e-file acceptance',
            'filing acceptance',
            'accepted return',
            'irs ack',
            'state ack',
            'acknowledgement',
            'acknowledgment',
        ]);

        $signalDocuments = $this->matchingDocuments($taxDocuments, ['extension_acceptance'], [
            '8879',
            '7004',
            '4868',
            'extension',
            'signature authorization',
        ]);

        return $this->documentGate(
            code: 'filing_acceptance',
            title: 'Filing and extension acceptance proved',
            maxPoints: 12,
            documents: $acceptanceDocuments,
            evidenceSlots: ['IRS e-file acceptance', 'State/local acceptance', 'Extension acceptance or explicit not-applicable note', 'Signed authorization when e-filed'],
            action: 'Attach the IRS, state, and local e-file acceptance records. If an extension was not used, record that explicitly with supporting filing dates.',
            hasSignal: $signalDocuments->isNotEmpty(),
        );
    }

    protected function paymentProofGate(Collection $taxDocuments, Collection $taxEstimates): array
    {
        $documents = $this->matchingDocuments($taxDocuments, ['payment_confirmation'], [
            'payment confirmation',
            'payment receipt',
            'eftps',
            'direct pay',
            'estimated payment',
            'tax payment',
        ]);

        $hasPaymentSignal = $taxEstimates->contains(fn ($estimate): bool => (float) $estimate->ytd_payments_made > 0);

        return $this->documentGate(
            code: 'payment_proof',
            title: 'Tax payments matched to receipts',
            maxPoints: 12,
            documents: $documents,
            evidenceSlots: ['EFTPS receipt', 'IRS Direct Pay receipt', 'State/local payment receipt', 'Bank-cleared payment proof'],
            action: 'Attach payment confirmations and match each tax payment to the return, estimate, transcript, or agency balance it satisfied.',
            hasSignal: $hasPaymentSignal,
        );
    }

    protected function transcriptProofGate(Collection $taxDocuments): array
    {
        $documents = $this->matchingDocuments($taxDocuments, ['tax_transcript', 'account_transcript'], [
            'account transcript',
            'return transcript',
            'tax transcript',
            'irs transcript',
            'wage and income transcript',
        ]);

        return $this->documentGate(
            code: 'transcript_match',
            title: 'Agency transcript matched',
            maxPoints: 12,
            documents: $documents,
            evidenceSlots: ['IRS account transcript', 'IRS return transcript if needed', 'State account transcript or notice', 'Wage and income transcript if needed'],
            action: 'Pull and attach transcripts for open or questionable years, then match filing status, payments, credits, penalties, and balances.',
        );
    }

    protected function passThroughRollupGate(Collection $taxDocuments, Collection $qboConnections): array
    {
        $k1Documents = $this->matchingDocuments($taxDocuments, ['k1_package'], [
            'k-1',
            'k1',
            'schedule e',
        ]);

        $basisDocuments = $this->matchingDocuments($taxDocuments, ['basis_workpaper'], [
            '7203',
            'basis',
            'qbi',
            '8995',
            'loss limitation',
        ]);

        return $this->evidencePairGate(
            code: 'pass_through_rollup',
            title: 'K-1, QBI, and basis rolled up',
            maxPoints: 16,
            primaryDocuments: $k1Documents,
            secondaryDocuments: $basisDocuments,
            evidenceSlots: ['K-1 package', 'Schedule E rollup', 'QBI/Form 8995 workpaper', 'Form 7203 or basis worksheet'],
            action: 'Reconcile business ordinary income, K-1s, Schedule E, QBI, losses, distributions, and basis into the personal return.',
            hasSignal: $qboConnections->isNotEmpty(),
        );
    }

    protected function reasonableCompensationGate(Collection $taxDocuments): array
    {
        $payrollDocuments = $this->matchingDocuments($taxDocuments, ['payroll_record', 'w2_packet'], [
            'w-2',
            'w2',
            'payroll',
            '941',
            '940',
            '1125-e',
            'officer compensation',
            'reasonable compensation',
        ]);

        $distributionDocuments = $this->matchingDocuments($taxDocuments, ['distribution_ledger', 'basis_workpaper'], [
            'distribution',
            'shareholder basis',
            'owner transfer',
            '7203',
            'basis',
        ]);

        return $this->evidencePairGate(
            code: 'reasonable_compensation',
            title: 'S-corp compensation and distributions reconciled',
            maxPoints: 16,
            primaryDocuments: $payrollDocuments,
            secondaryDocuments: $distributionDocuments,
            evidenceSlots: ['Owner W-2/payroll reports', 'Payroll tax filings', 'Distribution ledger', 'Comparable compensation support'],
            action: 'Compare owner wages, payroll filings, distributions, services performed, shareholder basis, and bank transfers before treating salary optimization as safe.',
            hasSignal: true,
        );
    }

    protected function bankBooksTieOutGate(Collection $taxDocuments, Collection $qboConnections): array
    {
        $bankDocuments = $this->matchingDocuments($taxDocuments, ['bank_statement'], [
            'bank statement',
            'statement',
            'checking',
            'savings',
        ]);

        $booksDocuments = $this->matchingDocuments($taxDocuments, ['qbo_export', 'general_ledger', 'trial_balance'], [
            'quickbooks',
            'qbo',
            'general ledger',
            'trial balance',
            'profit and loss',
            'p&l',
            'balance sheet',
        ]);

        $activeQboConnection = $qboConnections->contains(fn (QuickBooksConnection $connection): bool => (bool) $connection->sync_enabled
            && filled($connection->access_token)
            && $connection->refresh_token_expires_at?->isFuture());

        return $this->evidencePairGate(
            code: 'bank_books_tie_out',
            title: 'Bank and books tied to the return',
            maxPoints: 12,
            primaryDocuments: $bankDocuments,
            secondaryDocuments: $booksDocuments,
            evidenceSlots: ['Business bank statements', 'QuickBooks or general ledger export', 'Gross receipts reconciliation', 'Owner transfer and loan classification'],
            action: 'Tie deposits, deductions, owner transfers, reimbursements, loans, distributions, and personal charges to the return treatment.',
            hasSignal: $activeQboConnection || $booksDocuments->isNotEmpty(),
            secondarySatisfied: $activeQboConnection || $booksDocuments->isNotEmpty(),
        );
    }

    protected function openIssueClearanceGate(Collection $taxDocuments, Collection $calendarEvents): array
    {
        $overdueEvents = $calendarEvents->filter(fn (TaxCalendarEvent $event): bool => $event->isOverdue());
        $reviewDocuments = $taxDocuments->filter(fn (FinancialDocument $document): bool => (bool) $document->needs_review);
        $noticeDocuments = $taxDocuments->filter(fn (FinancialDocument $document): bool => $document->document_type === 'irs_notice'
            && $document->response_deadline !== null
            && $document->response_deadline->isFuture());

        if ($overdueEvents->isNotEmpty()) {
            return $this->gate(
                code: 'open_issue_clearance',
                title: 'Open issues cleared',
                status: 'blocked',
                maxPoints: 8,
                evidenceSlots: ['No overdue filing deadlines', 'No unresolved response deadlines', 'No unreviewed notices', 'No unreviewed extracted documents'],
                action: 'Resolve overdue tax calendar events before the system can sign off on the review year.',
                foundEvidence: $overdueEvents->map(fn (TaxCalendarEvent $event): string => ($event->form_type ?: $event->event_type).' overdue')->values()->toArray(),
            );
        }

        if ($reviewDocuments->isNotEmpty() || $noticeDocuments->isNotEmpty()) {
            return $this->gate(
                code: 'open_issue_clearance',
                title: 'Open issues cleared',
                status: 'needs_review',
                maxPoints: 8,
                evidenceSlots: ['No overdue filing deadlines', 'No unresolved response deadlines', 'No unreviewed notices', 'No unreviewed extracted documents'],
                action: 'Review open notices and extracted documents, then clear or convert each issue into a filing, payment, amendment, or abatement task.',
                foundEvidence: $this->documentLabels($reviewDocuments->merge($noticeDocuments)),
            );
        }

        return $this->gate(
            code: 'open_issue_clearance',
            title: 'Open issues cleared',
            status: 'complete',
            maxPoints: 8,
            evidenceSlots: ['No overdue filing deadlines', 'No unresolved response deadlines', 'No unreviewed notices', 'No unreviewed extracted documents'],
            action: 'All tracked open issues are clear. Preserve the final review notes in the audit trail.',
        );
    }

    protected function documentGate(
        string $code,
        string $title,
        int $maxPoints,
        Collection $documents,
        array $evidenceSlots,
        string $action,
        bool $hasSignal = false,
    ): array {
        if ($documents->isEmpty()) {
            return $this->gate(
                code: $code,
                title: $title,
                status: $hasSignal ? 'needs_evidence' : 'blocked',
                maxPoints: $maxPoints,
                evidenceSlots: $evidenceSlots,
                action: $action,
            );
        }

        return $this->gate(
            code: $code,
            title: $title,
            status: $documents->contains(fn (FinancialDocument $document): bool => (bool) $document->needs_review) ? 'needs_review' : 'complete',
            maxPoints: $maxPoints,
            evidenceSlots: $evidenceSlots,
            action: $action,
            foundEvidence: $this->documentLabels($documents),
        );
    }

    protected function evidencePairGate(
        string $code,
        string $title,
        int $maxPoints,
        Collection $primaryDocuments,
        Collection $secondaryDocuments,
        array $evidenceSlots,
        string $action,
        bool $hasSignal = false,
        ?bool $secondarySatisfied = null,
    ): array {
        $secondarySatisfied ??= $secondaryDocuments->isNotEmpty();
        $foundDocuments = $primaryDocuments->merge($secondaryDocuments)->unique('id')->values();

        if ($primaryDocuments->isEmpty() || ! $secondarySatisfied) {
            return $this->gate(
                code: $code,
                title: $title,
                status: $foundDocuments->isNotEmpty() || $hasSignal ? 'needs_evidence' : 'blocked',
                maxPoints: $maxPoints,
                evidenceSlots: $evidenceSlots,
                action: $action,
                foundEvidence: $this->documentLabels($foundDocuments),
            );
        }

        return $this->gate(
            code: $code,
            title: $title,
            status: $foundDocuments->contains(fn (FinancialDocument $document): bool => (bool) $document->needs_review) ? 'needs_review' : 'complete',
            maxPoints: $maxPoints,
            evidenceSlots: $evidenceSlots,
            action: $action,
            foundEvidence: $this->documentLabels($foundDocuments),
        );
    }

    protected function gate(
        string $code,
        string $title,
        string $status,
        int $maxPoints,
        array $evidenceSlots,
        string $action,
        array $foundEvidence = [],
    ): array {
        return [
            'code' => $code,
            'title' => $title,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'earned_points' => $this->earnedPoints($status, $maxPoints),
            'max_points' => $maxPoints,
            'evidence_slots' => $evidenceSlots,
            'found_evidence' => array_values($foundEvidence),
            'action' => $action,
        ];
    }

    protected function earnedPoints(string $status, int $maxPoints): int
    {
        return match ($status) {
            'complete' => $maxPoints,
            'needs_review' => (int) floor($maxPoints * 0.5),
            'needs_evidence' => (int) floor($maxPoints * 0.25),
            default => 0,
        };
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'complete' => 'Complete',
            'needs_review' => 'Needs review',
            'needs_evidence' => 'Needs evidence',
            default => 'Blocked',
        };
    }

    protected function matchingDocuments(Collection $taxDocuments, array $documentTypes, array $fileFragments): Collection
    {
        $types = collect($documentTypes)->map(fn (string $type): string => Str::lower($type));
        $fragments = collect($fileFragments)->map(fn (string $fragment): string => Str::lower($fragment));

        return $taxDocuments
            ->filter(function (FinancialDocument $document) use ($types, $fragments): bool {
                $documentType = Str::lower((string) $document->document_type);
                $fileName = Str::lower((string) $document->file_name);

                return $types->contains($documentType)
                    || $fragments->contains(fn (string $fragment): bool => Str::contains($fileName, $fragment));
            })
            ->values();
    }

    protected function documentLabels(Collection $documents): array
    {
        return $documents
            ->take(6)
            ->map(fn (FinancialDocument $document): string => "{$document->file_name} ({$document->document_type})")
            ->values()
            ->toArray();
    }
}
