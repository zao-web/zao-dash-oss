<?php

namespace App\Services\Tax;

use App\Models\EstimatedTaxPayment;
use App\Models\QuickBooksConnection;
use App\Models\TaxProfile;
use App\Models\TaxReturnWorkpaper;
use App\Services\PersonalFinance\BookkeepingReadinessService;
use App\Services\Tax\Agency\TaxAgencyEvidenceService;
use Illuminate\Support\Collection;

class TaxReturnPreparationService
{
    public function __construct(
        protected BookkeepingReadinessService $bookkeepingReadinessService,
        protected TaxAgencyEvidenceService $taxAgencyEvidenceService,
        protected FinancialArchiveEvidenceService $financialArchiveEvidenceService,
        protected TaxDocumentEvidenceService $taxDocumentEvidenceService,
        protected PriorYearTaxReturnFactService $priorYearTaxReturnFactService,
        protected OwnerPaymentReviewService $ownerPaymentReviewService,
        protected RevenueRecognitionService $revenueRecognitionService,
        protected DraftReturnComputationService $draftReturnComputationService,
    ) {}

    /**
     * Build a deterministic draft-return workpaper packet from source facts.
     *
     * @return array{
     *     status: string,
     *     status_label: string,
     *     next_action: string,
     *     can_generate_draft_forms: bool,
     *     readiness_percent: int,
     *     mapped_field_count: int,
     *     total_field_count: int,
     *     source_facts: array<int, array<string, mixed>>,
     *     forms: array<int, array<string, mixed>>,
     *     validation_gates: array<int, array<string, mixed>>,
     *     owner_signoff: array<string, mixed>,
     *     agency_evidence: array<string, mixed>,
     *     document_evidence: array<string, mixed>,
     *     archive_evidence: array<string, mixed>,
     *     bookkeeping_readiness: array<string, mixed>,
     *     owner_payment_review: array<string, mixed>,
     *     historical_return_context: array<string, mixed>,
     *     revenue_recognition: array<string, mixed>,
     * }
     */
    public function build(int $userId, int $year, ?array $annualProjection = null): array
    {
        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->first();
        $activeQboConnections = QuickBooksConnection::active()->where('user_id', $userId)->get();
        $bookkeepingReadiness = $this->bookkeepingReadinessService->summarize($userId, $year);
        $sourceSummary = $bookkeepingReadiness['ledger_summary'];

        $payments = EstimatedTaxPayment::ytdPaymentsByJurisdiction($userId, $year);
        $agencyEvidence = $this->taxAgencyEvidenceService->summarize($userId, $year);
        $uploadedDocumentEvidence = $this->taxDocumentEvidenceService->summarize($userId, $year);
        $archiveEvidence = $this->financialArchiveEvidenceService->summarize($year, $userId);
        $historicalReturnContext = $this->priorYearTaxReturnFactService->summarize($userId, $year);
        $ownerPaymentReview = $this->ownerPaymentReviewService->summarize($userId, $year);
        $revenueRecognition = $this->revenueRecognitionService->summarize(
            userId: $userId,
            year: $year,
            annualProjection: $annualProjection ?? [],
            bookkeepingReadiness: $bookkeepingReadiness,
        );
        $draftReturnComputation = $this->draftReturnComputationService->compute(
            profile: $profile,
            year: $year,
            annualProjection: $annualProjection ?? [],
            bookkeepingReadiness: $bookkeepingReadiness,
            revenueRecognition: $revenueRecognition,
            ownerPaymentReview: $ownerPaymentReview,
        );
        $documentEvidence = $this->mergeDocumentEvidence(
            $uploadedDocumentEvidence,
            $archiveEvidence['current_year_document_evidence'] ?? [],
        );
        $sourceFacts = $this->sourceFacts(
            $profile,
            $annualProjection,
            $payments,
            $sourceSummary,
            $agencyEvidence,
            $documentEvidence,
            $archiveEvidence,
            $ownerPaymentReview,
            $historicalReturnContext,
            $draftReturnComputation,
        );
        $factsByKey = collect($sourceFacts)->keyBy('key');
        $forms = $this->forms($profile, $payments, $factsByKey, $historicalReturnContext, $draftReturnComputation);
        $validationGates = $this->validationGates(
            profile: $profile,
            annualProjection: $annualProjection,
            sourceSummary: $sourceSummary,
            agencyEvidence: $agencyEvidence,
            documentEvidence: $documentEvidence,
            archiveEvidence: $archiveEvidence,
            bookkeepingReadiness: $bookkeepingReadiness,
            ownerPaymentReview: $ownerPaymentReview,
            historicalReturnContext: $historicalReturnContext,
            revenueRecognition: $revenueRecognition,
            hasBooks: (bool) ($sourceSummary['has_books'] ?? false),
            hasBankFeed: (bool) ($sourceSummary['has_bank_feed'] ?? false),
        );

        $fieldCounts = $this->fieldCounts($forms);
        $blockingGateCount = collect($validationGates)
            ->filter(fn (array $gate): bool => $gate['blocks_packet'] && $gate['status'] !== 'passed')
            ->count();
        $status = $this->status(
            $profile,
            $annualProjection,
            (bool) ($sourceSummary['has_books'] ?? false),
            (bool) ($sourceSummary['has_bank_feed'] ?? false),
            $blockingGateCount,
        );

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'next_action' => $this->nextAction($status),
            'can_generate_draft_forms' => $status === 'draft_ready_for_owner_review',
            'readiness_percent' => $fieldCounts['readiness_percent'],
            'mapped_field_count' => $fieldCounts['mapped'],
            'total_field_count' => $fieldCounts['total'],
            'source_facts' => $sourceFacts,
            'forms' => $forms,
            'validation_gates' => $validationGates,
            'owner_signoff' => [
                'status' => $status === 'draft_ready_for_owner_review' ? 'ready_to_request' : 'not_ready',
                'required' => true,
                'required_for' => [
                    'Filing returns',
                    'Making tax payments',
                    'Submitting IRS or Oregon portal actions',
                    'Exporting or changing accounting records',
                ],
                'review_posture' => 'Review source-linked workpapers, assumptions, and aggressive-but-defensible positions before any external action.',
            ],
            'agency_evidence' => $agencyEvidence,
            'document_evidence' => $documentEvidence,
            'archive_evidence' => $archiveEvidence,
            'bookkeeping_readiness' => $bookkeepingReadiness,
            'owner_payment_review' => $ownerPaymentReview,
            'historical_return_context' => $historicalReturnContext,
            'revenue_recognition' => $revenueRecognition,
            'draft_return_computation' => $draftReturnComputation,
        ];
    }

    public function persist(int $userId, int $year, ?array $annualProjection = null): TaxReturnWorkpaper
    {
        $packet = $this->build($userId, $year, $annualProjection);
        $packetHash = hash('sha256', json_encode($packet, JSON_THROW_ON_ERROR));
        $ownerApprovalReady = $this->packetEligibleForOwnerApproval($packet);

        $existing = TaxReturnWorkpaper::where('user_id', $userId)
            ->where('tax_year', $year)
            ->first();
        $packetChanged = $existing && $existing->packet_hash !== $packetHash;

        return TaxReturnWorkpaper::updateOrCreate(
            [
                'user_id' => $userId,
                'tax_year' => $year,
            ],
            [
                'status' => $packet['status'],
                'readiness_percent' => $packet['readiness_percent'],
                'mapped_field_count' => $packet['mapped_field_count'],
                'total_field_count' => $packet['total_field_count'],
                'packet_hash' => $packetHash,
                'packet' => $packet,
                'signoff_document_id' => $packetChanged ? null : $existing?->signoff_document_id,
                'generated_at' => $packetChanged ? null : $existing?->generated_at,
                'approved_at' => $packetChanged || ! $ownerApprovalReady ? null : $existing?->approved_at,
                'superseded_at' => $packetChanged && $existing?->generated_at ? now() : $existing?->superseded_at,
            ],
        );
    }

    /**
     * @param  array{federal: float, state_or: float, local_portland: float, local_multnomah: float, total: float}  $payments
     * @param  array<string, mixed>  $sourceSummary
     * @param  array<string, mixed>  $agencyEvidence
     * @param  array<string, mixed>  $documentEvidence
     * @param  array<string, mixed>  $archiveEvidence
     * @param  array<string, mixed>  $ownerPaymentReview
     * @param  array<string, mixed>  $historicalReturnContext
     * @param  array<string, mixed>  $draftReturnComputation
     * @return array<int, array<string, mixed>>
     */
    protected function sourceFacts(
        ?TaxProfile $profile,
        ?array $annualProjection,
        array $payments,
        array $sourceSummary,
        array $agencyEvidence,
        array $documentEvidence,
        array $archiveEvidence,
        array $ownerPaymentReview,
        array $historicalReturnContext,
        array $draftReturnComputation,
    ): array {
        $taxComputation = $draftReturnComputation['tax_computation'] ?? [];
        $isSCorp = ($profile?->entity_type ?? null) === 's_corp';
        $irsEvidence = $agencyEvidence['agencies']['irs'] ?? [];
        $oregonEvidence = $agencyEvidence['agencies']['oregon_dor'] ?? [];
        $bookSourceLabel = (string) ($draftReturnComputation['basis_label'] ?? ($sourceSummary['book_source_label'] ?? 'Books'));

        return [
            $this->fact('profile.filing_status', 'Filing status', $profile?->filing_status, 'Tax profile', $profile?->filing_status !== null, 95),
            $this->fact('profile.entity_type', 'Entity type', $profile?->entity_type, 'Tax profile', $profile?->entity_type !== null, 95),
            $this->fact('profile.residency', 'Resident jurisdiction', $this->residencyLabel($profile), 'Tax profile', $profile?->resident_state !== null, 95),
            $this->fact('profile.reasonable_salary', 'Reasonable compensation', (float) ($profile?->reasonable_salary ?? 0), 'Tax profile', ! $isSCorp || (float) ($profile?->reasonable_salary ?? 0) > 0, $isSCorp ? 80 : 90),
            $this->fact('profile.mortgage_interest_paid', 'Mortgage interest paid', $profile?->mortgage_interest_paid !== null ? (float) $profile->mortgage_interest_paid : null, 'Tax profile', $profile?->mortgage_interest_paid !== null, 90),
            $this->fact('profile.property_tax_paid', 'Property tax paid', $profile?->property_tax_paid !== null ? (float) $profile->property_tax_paid : null, 'Tax profile', $profile?->property_tax_paid !== null, 90),
            $this->fact('profile.charitable_contributions_paid', 'Charitable contributions paid', $profile?->charitable_contributions_paid !== null ? (float) $profile->charitable_contributions_paid : null, 'Tax profile', $profile?->charitable_contributions_paid !== null, 90),
            $this->fact('profile.medical_expenses_paid', 'Medical expenses paid', $profile?->medical_expenses_paid !== null ? (float) $profile->medical_expenses_paid : null, 'Tax profile', $profile?->medical_expenses_paid !== null, 88),
            $this->fact('profile.hsa_contributions_paid', 'HSA contributions paid', $profile?->hsa_contributions_paid !== null ? (float) $profile->hsa_contributions_paid : null, 'Tax profile', $profile?->hsa_contributions_paid !== null, 90),
            $this->fact('profile.education_expenses_paid', 'Education expenses paid', $profile?->education_expenses_paid !== null ? (float) $profile->education_expenses_paid : null, 'Tax profile', $profile?->education_expenses_paid !== null, 88),
            $this->fact('profile.oregon_kicker_credit', 'Oregon kicker credit', $profile?->oregon_kicker_credit !== null ? (float) $profile->oregon_kicker_credit : null, 'Tax profile (DOR What\'s My Kicker? lookup)', $profile?->oregon_kicker_credit !== null, 95),
            $this->fact('books.ytd_income', 'Book income', (float) ($draftReturnComputation['gross_receipts'] ?? 0), $bookSourceLabel, (float) ($draftReturnComputation['gross_receipts'] ?? 0) > 0, 88),
            $this->fact('books.ytd_expenses', 'Book expenses', (float) ($draftReturnComputation['business_expenses'] ?? 0), $bookSourceLabel, (float) ($draftReturnComputation['business_expenses'] ?? 0) > 0, 88),
            $this->fact('bank.ytd_deposits', 'Bank deposits', (float) $sourceSummary['bank_deposits'], 'Bank transactions', $sourceSummary['has_bank_feed'] && (float) $sourceSummary['bank_deposits'] > 0, 80),
            $this->fact('bank.ytd_outflows', 'Bank outflows', (float) $sourceSummary['bank_outflows'], 'Bank transactions', $sourceSummary['has_bank_feed'] && (float) $sourceSummary['bank_outflows'] > 0, 75),
            $this->fact('projection.annual_revenue', 'Projected annual revenue', (float) ($annualProjection['projected_annual_revenue'] ?? 0), 'Annual tax projection', (float) ($annualProjection['projected_annual_revenue'] ?? 0) > 0, 70),
            $this->fact('projection.annual_net', 'Projected annual net income', (float) ($annualProjection['projected_annual_net'] ?? 0), 'Annual tax projection', (float) ($annualProjection['projected_annual_net'] ?? 0) > 0, 70),
            $this->fact('tax.salary', 'Officer wages used in draft returns', (float) ($taxComputation['salary'] ?? 0), 'Draft return computation', array_key_exists('salary', $taxComputation), 90),
            $this->fact('tax.distributions', 'S-corp pass-through income / K-1 ordinary income', (float) ($taxComputation['k1_pass_through_income'] ?? 0), 'Draft return computation', array_key_exists('k1_pass_through_income', $taxComputation), 90),
            $this->fact('tax.agi', 'Adjusted gross income', (float) ($taxComputation['agi'] ?? 0), 'Tax computation', (float) ($taxComputation['agi'] ?? 0) > 0, 75),
            $this->fact('tax.qbi_deduction', 'QBI deduction', (float) ($taxComputation['qbi_deduction'] ?? 0), 'Tax computation', array_key_exists('qbi_deduction', $taxComputation), 70),
            $this->fact('tax.federal_taxable_income', 'Federal taxable income', (float) ($taxComputation['federal_taxable_income'] ?? 0), 'Tax computation', array_key_exists('federal_taxable_income', $taxComputation), 75),
            $this->fact('tax.federal_tax', 'Federal income tax after credits', (float) ($taxComputation['federal_tax'] ?? 0), 'Tax computation', array_key_exists('federal_tax', $taxComputation), 75),
            $this->fact('tax.oregon_taxable_income', 'Oregon taxable income', (float) ($taxComputation['oregon_taxable_income'] ?? 0), 'Tax computation', array_key_exists('oregon_taxable_income', $taxComputation), 75),
            $this->fact('tax.oregon_tax', 'Oregon income tax', (float) ($taxComputation['oregon_tax'] ?? 0), 'Tax computation', array_key_exists('oregon_tax', $taxComputation), 75),
            $this->fact('tax.multnomah_pfa_tax', 'Multnomah PFA tax', (float) ($taxComputation['multnomah_pfa_tax'] ?? 0), 'Tax computation', array_key_exists('multnomah_pfa_tax', $taxComputation), 75),
            $this->fact('tax.portland_arts_tax', 'Portland Arts Tax', (float) ($taxComputation['portland_arts_tax'] ?? 0), 'Tax computation', array_key_exists('portland_arts_tax', $taxComputation), 75),
            $this->fact('payments.federal_estimates', 'Federal estimated payments', (float) $payments['federal'], 'Estimated payment ledger', true, 85),
            $this->fact('payments.oregon_estimates', 'Oregon estimated payments', (float) $payments['state_or'], 'Estimated payment ledger', true, 85),
            $this->fact('agency.federal_portal_payments', 'IRS payment history', (float) ($irsEvidence['payment_total'] ?? 0), 'IRS Individual Online Account', (bool) ($irsEvidence['has_payment_history'] ?? false), 92),
            $this->fact('agency.oregon_portal_payments', 'Oregon payment history', (float) ($oregonEvidence['payment_total'] ?? 0), 'Revenue Online', (bool) ($oregonEvidence['has_payment_history'] ?? false), 92),
            $this->fact('agency.irs_balance_due', 'IRS portal balance due', $irsEvidence['latest_balance_amount'] ?? null, 'IRS Individual Online Account', (bool) ($irsEvidence['is_synced'] ?? false) && ($irsEvidence['latest_balance_amount'] ?? null) !== null, 90),
            $this->fact('agency.oregon_balance_due', 'Oregon portal balance due', $oregonEvidence['latest_balance_amount'] ?? null, 'Revenue Online', (bool) ($oregonEvidence['is_synced'] ?? false) && ($oregonEvidence['latest_balance_amount'] ?? null) !== null, 90),
            $this->fact('agency.transcript_count', 'Agency transcripts on file', (int) ($agencyEvidence['transcript_count'] ?? 0), 'IRS / Oregon portal sync', (bool) ($agencyEvidence['transcript_count'] ?? 0) > 0, 90),
            $this->fact('agency.open_notice_count', 'Open agency notices', (int) ($agencyEvidence['open_notice_count'] ?? 0), 'IRS / Oregon portal sync', (bool) ($agencyEvidence['has_synced_coverage'] ?? false), 90),
            $this->fact('documents.reviewed_current_year_support', 'Reviewed current-year tax support docs', (int) ($documentEvidence['reviewed_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['reviewed_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.return_packets', 'Current-year return packets', (int) ($documentEvidence['return_packet_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['return_packet_count'] ?? 0) > 0, 92),
            $this->fact('documents.acceptance_records', 'Acceptance records', (int) ($documentEvidence['acceptance_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['acceptance_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.payment_confirmations', 'Payment confirmations', (int) ($documentEvidence['payment_confirmation_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['payment_confirmation_count'] ?? 0) > 0, 92),
            $this->fact('documents.transcripts', 'Uploaded transcripts', (int) ($documentEvidence['transcript_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['transcript_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.payroll_packets', 'Payroll and W-2 packets', (int) ($documentEvidence['payroll_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['payroll_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.k1_packages', 'K-1 packages', (int) ($documentEvidence['k1_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['k1_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.basis_workpapers', 'Basis and QBI workpapers', (int) ($documentEvidence['basis_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['basis_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.distribution_ledgers', 'Distribution ledgers', (int) ($documentEvidence['distribution_document_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['distribution_document_count'] ?? 0) > 0, 92),
            $this->fact('documents.entity_closure_records', 'Entity closure records', (int) ($documentEvidence['entity_closure_record_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['entity_closure_record_count'] ?? 0) > 0, 92),
            $this->fact('documents.bank_statements', 'Current-year bank statements', (int) ($documentEvidence['bank_statement_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['bank_statement_count'] ?? 0) > 0, 90),
            $this->fact('documents.books_exports', 'Current-year books exports', (int) ($documentEvidence['books_export_count'] ?? 0), 'Uploaded financial documents', (int) ($documentEvidence['books_export_count'] ?? 0) > 0, 90),
            $this->fact('owner_payments.reviewed_distributions', 'Reviewed owner distributions', (float) data_get($ownerPaymentReview, 'metrics.distribution_total', 0), 'Owner-payment review', (bool) data_get($ownerPaymentReview, 'review_complete', false), 88),
            $this->fact('owner_payments.reviewed_contributions', 'Reviewed owner contributions', (float) data_get($ownerPaymentReview, 'metrics.owner_contribution_total', 0), 'Owner-payment review', (bool) data_get($ownerPaymentReview, 'review_complete', false), 88),
            $this->fact('owner_payments.reviewed_loans', 'Reviewed owner loans', (float) data_get($ownerPaymentReview, 'metrics.owner_loan_total', 0), 'Owner-payment review', (bool) data_get($ownerPaymentReview, 'review_complete', false), 88),
            $this->fact('owner_payments.reviewed_reimbursements', 'Reviewed owner reimbursements', (float) data_get($ownerPaymentReview, 'metrics.owner_reimbursement_total', 0), 'Owner-payment review', (bool) data_get($ownerPaymentReview, 'review_complete', false), 88),
            $this->fact('owner_payments.potential_compensation', 'Potential compensation candidates', (float) data_get($ownerPaymentReview, 'metrics.potential_compensation_total', 0), 'Owner-payment review', (bool) data_get($ownerPaymentReview, 'review_complete', false), 88),
            $this->fact('archive.business_return_years', 'Prior business return years', count($archiveEvidence['prior_business_return_years'] ?? []), 'Financial archive inventory', (bool) ($archiveEvidence['prior_business_return_years'] ?? []), 88),
            $this->fact('archive.personal_return_years', 'Prior personal return years', count($archiveEvidence['prior_personal_return_years'] ?? []), 'Financial archive inventory', (bool) ($archiveEvidence['prior_personal_return_years'] ?? []), 88),
            $this->fact('archive.bank_statement_years', 'Bank statement years in archive', count($archiveEvidence['bank_statement_years'] ?? []), 'Financial archive inventory', (bool) ($archiveEvidence['bank_statement_years'] ?? []), 84),
            $this->fact('archive.payroll_document_count', 'Payroll filings in archive', (int) ($archiveEvidence['payroll_document_count'] ?? 0), 'Financial archive inventory', (bool) ($archiveEvidence['payroll_document_count'] ?? 0), 84),
            $this->historicalReturnFact('prior_return.latest_personal_filing_status', $historicalReturnContext, 'latest_personal_filing_status'),
            $this->historicalReturnFact('prior_return.latest_personal_agi', $historicalReturnContext, 'latest_personal_agi'),
            $this->historicalReturnFact('prior_return.latest_personal_federal_taxable_income', $historicalReturnContext, 'latest_personal_federal_taxable_income'),
            $this->historicalReturnFact('prior_return.latest_personal_federal_tax', $historicalReturnContext, 'latest_personal_federal_tax'),
            $this->historicalReturnFact('prior_return.latest_personal_oregon_tax', $historicalReturnContext, 'latest_personal_oregon_tax'),
            $this->historicalReturnFact('prior_return.latest_personal_qbi_deduction', $historicalReturnContext, 'latest_personal_qbi_deduction'),
            $this->historicalReturnFact('prior_return.latest_personal_wages', $historicalReturnContext, 'latest_personal_wages'),
            $this->historicalReturnFact('prior_return.latest_business_officer_compensation', $historicalReturnContext, 'latest_business_officer_compensation'),
            $this->historicalReturnFact('prior_return.latest_business_ordinary_income', $historicalReturnContext, 'latest_business_ordinary_income'),
            $this->historicalReturnFact('prior_return.latest_business_shareholder_basis', $historicalReturnContext, 'latest_business_shareholder_basis'),
            $this->historicalReturnFact('prior_return.latest_business_distributions', $historicalReturnContext, 'latest_business_distributions'),
            $this->historicalReturnFact('prior_return.federal_overpayment_applied', $historicalReturnContext, 'federal_overpayment_applied'),
            $this->historicalReturnFact('prior_return.oregon_overpayment_applied', $historicalReturnContext, 'oregon_overpayment_applied'),
            $this->historicalReturnFact('prior_return.capital_loss_carryforward', $historicalReturnContext, 'capital_loss_carryforward'),
            $this->historicalReturnFact('prior_return.nol_carryforward', $historicalReturnContext, 'nol_carryforward'),
            $this->historicalReturnFact('prior_return.charitable_carryforward', $historicalReturnContext, 'charitable_carryforward'),
            $this->fact(
                'prior_return.final_return_entities',
                'Final-return entities',
                $this->entityListValue($historicalReturnContext['final_return_entities'] ?? []),
                'Prior-year return packets + lifecycle decisions',
                count($historicalReturnContext['final_return_entities'] ?? []) > 0,
                90,
            ),
            $this->fact(
                'prior_return.dissolution_entities',
                'Dissolution entities',
                $this->entityListValue($historicalReturnContext['dissolution_entities'] ?? []),
                'Prior-year return packets + lifecycle decisions',
                count($historicalReturnContext['dissolution_entities'] ?? []) > 0,
                90,
            ),
            $this->fact('safe_harbor.target', 'Safe harbor target', (float) ($annualProjection['safe_harbor_target'] ?? 0), 'Annual tax projection', array_key_exists('safe_harbor_target', $annualProjection ?? []), 70),
        ];
    }

    protected function fact(string $key, string $label, mixed $value, string $source, bool $ready, int $confidence): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'display_value' => $this->displayValue($value),
            'source' => $source,
            'status' => $ready ? 'ready' : 'missing',
            'confidence' => $ready ? $confidence : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function historicalReturnFact(string $key, array $historicalReturnContext, string $factKey): array
    {
        $fact = $historicalReturnContext['facts'][$factKey] ?? [];

        return $this->fact(
            key: $key,
            label: (string) ($fact['label'] ?? str_replace('_', ' ', $factKey)),
            value: $fact['value'] ?? null,
            source: (string) ($fact['source'] ?? 'Prior-year return packet'),
            ready: ($fact['status'] ?? 'missing') === 'ready',
            confidence: ($fact['status'] ?? 'missing') === 'ready' ? 90 : 0,
        );
    }

    /**
     * @param  array<string, mixed>  $uploadedDocumentEvidence
     * @param  array<string, mixed>  $archiveDocumentEvidence
     * @return array<string, mixed>
     */
    protected function mergeDocumentEvidence(array $uploadedDocumentEvidence, array $archiveDocumentEvidence): array
    {
        $categoryKeys = [
            'return_packets',
            'acceptance_records',
            'payment_confirmations',
            'transcripts',
            'payroll_packets',
            'k1_packages',
            'basis_workpapers',
            'distribution_ledgers',
            'entity_closure_records',
            'bank_statements',
            'books_exports',
        ];

        $categories = [];

        foreach ($categoryKeys as $key) {
            $uploadedCategory = $uploadedDocumentEvidence['categories'][$key] ?? [];
            $archiveCategory = $archiveDocumentEvidence['categories'][$key] ?? [];
            $uploadedNeedsReviewCount = (int) ($uploadedCategory['needs_review_count'] ?? 0);

            $categories[$key] = [
                'label' => $uploadedCategory['label'] ?? $archiveCategory['label'] ?? ucfirst(str_replace('_', ' ', $key)),
                'count' => (int) ($uploadedCategory['count'] ?? 0) + (int) ($archiveCategory['count'] ?? 0),
                'reviewed_count' => (int) ($uploadedCategory['reviewed_count'] ?? 0) + (int) ($archiveCategory['reviewed_count'] ?? 0),
                'needs_review_count' => $uploadedNeedsReviewCount,
                'blocks_review_queue' => $this->documentCategoryBlocksReviewQueue($key),
                'blocking_needs_review_count' => $this->documentCategoryBlocksReviewQueue($key) ? $uploadedNeedsReviewCount : 0,
                'documents' => array_values(array_merge(
                    is_array($uploadedCategory['documents'] ?? null) ? $uploadedCategory['documents'] : [],
                    is_array($archiveCategory['documents'] ?? null) ? $archiveCategory['documents'] : [],
                )),
            ];
        }

        $missingCoreCategories = collect($categories)
            ->filter(fn (array $category): bool => $category['count'] === 0)
            ->map(fn (array $category): string => $category['label'])
            ->values()
            ->all();

        $coveredCoreCategoryCount = collect($categories)
            ->filter(fn (array $category): bool => $category['count'] > 0)
            ->count();
        $documentCount = array_sum(array_map(fn (array $category): int => (int) $category['count'], $categories));
        $needsReviewCount = array_sum(array_map(
            fn (array $category): int => (int) ($category['blocking_needs_review_count'] ?? 0),
            $categories,
        ));
        $reviewedDocumentCount = array_sum(array_map(fn (array $category): int => (int) $category['reviewed_count'], $categories));
        $coverageStatus = match (true) {
            $documentCount === 0 => 'missing',
            $coveredCoreCategoryCount >= 5 => 'current',
            default => 'partial',
        };

        return [
            'tax_year' => $uploadedDocumentEvidence['tax_year'] ?? $archiveDocumentEvidence['tax_year'] ?? null,
            'document_count' => $documentCount,
            'reviewed_document_count' => $reviewedDocumentCount,
            'needs_review_count' => $needsReviewCount,
            'coverage_status' => $coverageStatus,
            'next_action' => $this->mergedDocumentNextAction($coverageStatus, $needsReviewCount, $missingCoreCategories),
            'missing_core_categories' => $missingCoreCategories,
            'return_packet_count' => $categories['return_packets']['count'],
            'acceptance_document_count' => $categories['acceptance_records']['count'],
            'payment_confirmation_count' => $categories['payment_confirmations']['count'],
            'transcript_document_count' => $categories['transcripts']['count'],
            'payroll_document_count' => $categories['payroll_packets']['count'],
            'k1_document_count' => $categories['k1_packages']['count'],
            'basis_document_count' => $categories['basis_workpapers']['count'],
            'distribution_document_count' => $categories['distribution_ledgers']['count'],
            'entity_closure_record_count' => $categories['entity_closure_records']['count'],
            'bank_statement_count' => $categories['bank_statements']['count'],
            'books_export_count' => $categories['books_exports']['count'],
            'uploaded_document_count' => (int) ($uploadedDocumentEvidence['document_count'] ?? 0),
            'archive_document_count' => (int) ($archiveDocumentEvidence['document_count'] ?? 0),
            'categories' => $categories,
        ];
    }

    protected function documentCategoryBlocksReviewQueue(string $categoryKey): bool
    {
        return ! in_array($categoryKey, ['bank_statements', 'books_exports'], true);
    }

    /**
     * @param  array<int, string>  $missingCoreCategories
     */
    protected function mergedDocumentNextAction(string $coverageStatus, int $needsReviewCount, array $missingCoreCategories): string
    {
        if ($needsReviewCount > 0) {
            return 'Review uploaded current-year tax documents before owner signoff so the packet cannot ignore contradictory evidence.';
        }

        if ($coverageStatus === 'current') {
            return 'Current-year tax support is available across uploads and the Financials archive for payroll, books, bank, payments, and pass-through corroboration.';
        }

        if ($coverageStatus === 'partial') {
            return 'Fill the remaining current-year support gaps across uploads or the Financials archive: '.implode(', ', $missingCoreCategories).'.';
        }

        return 'No current-year support documents were found in uploads or the Financials archive. Add payroll packets, books exports, bank statements, payment confirmations, and pass-through workpapers.';
    }

    protected function residencyLabel(?TaxProfile $profile): ?string
    {
        if (! $profile) {
            return null;
        }

        return collect([$profile->resident_city, $profile->resident_state])
            ->filter()
            ->implode(', ');
    }

    /**
     * @param  array{federal: float, state_or: float, local_portland: float, local_multnomah: float, total: float}  $payments
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     * @param  array<string, mixed>  $historicalReturnContext
     * @param  array<string, mixed>  $draftReturnComputation
     * @return array<int, array<string, mixed>>
     */
    protected function forms(?TaxProfile $profile, array $payments, Collection $factsByKey, array $historicalReturnContext, array $draftReturnComputation): array
    {
        $taxComputation = $draftReturnComputation['tax_computation'] ?? [];
        $isPortlandResident = strtolower((string) ($profile?->resident_city ?? '')) === 'portland';
        $hasLatestPersonalReturn = ($historicalReturnContext['latest_personal_return_year'] ?? null) !== null;
        $hasLatestBusinessReturn = ($historicalReturnContext['latest_business_return_year'] ?? null) !== null;

        return [
            $this->form('1040', 'Form 1040 draft', [
                $this->field(
                    'filing_status',
                    'Filing status',
                    '1040 filing status',
                    $profile?->filing_status,
                    ['profile.filing_status'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_filing_status' : null,
                    ]))
                ),
                $this->field(
                    'wages',
                    'Wages / officer salary',
                    '1040 wage income workpaper',
                    (float) ($draftReturnComputation['officer_compensation'] ?? 0),
                    ['tax.salary', 'profile.reasonable_salary'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        'documents.payroll_packets',
                        'owner_payments.potential_compensation',
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_wages' : null,
                        $hasLatestBusinessReturn ? 'prior_return.latest_business_officer_compensation' : null,
                    ]))
                ),
                $this->field(
                    'pass_through_income',
                    'S-corp pass-through income',
                    'Schedule E / K-1 bridge',
                    (float) ($draftReturnComputation['pass_through_income'] ?? 0),
                    ['tax.distributions'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        'documents.k1_packages',
                        'documents.basis_workpapers',
                        $hasLatestBusinessReturn ? 'prior_return.latest_business_ordinary_income' : null,
                        $hasLatestBusinessReturn ? 'prior_return.latest_business_distributions' : null,
                    ]))
                ),
                $this->field('adjusted_gross_income', 'Adjusted gross income', '1040 AGI workpaper', (float) ($taxComputation['agi'] ?? 0), ['tax.agi'], $factsByKey),
                $this->field(
                    'qbi_deduction',
                    'QBI deduction',
                    '8995 / 8995-A bridge',
                    (float) ($taxComputation['qbi_deduction'] ?? 0),
                    ['tax.qbi_deduction'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        'documents.basis_workpapers',
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_qbi_deduction' : null,
                    ]))
                ),
                $this->field(
                    'taxable_income',
                    'Federal taxable income',
                    '1040 taxable income workpaper',
                    (float) ($taxComputation['federal_taxable_income'] ?? 0),
                    ['tax.federal_taxable_income'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_federal_taxable_income' : null,
                    ]))
                ),
                $this->field(
                    'mortgage_interest',
                    'Mortgage interest',
                    'Schedule A mortgage interest workpaper',
                    $this->factValue($factsByKey, 'profile.mortgage_interest_paid'),
                    ['profile.mortgage_interest_paid'],
                    $factsByKey,
                    $this->factValue($factsByKey, 'profile.mortgage_interest_paid') !== null,
                ),
                $this->field(
                    'property_taxes',
                    'Property taxes',
                    'Schedule A property tax workpaper',
                    $this->factValue($factsByKey, 'profile.property_tax_paid'),
                    ['profile.property_tax_paid'],
                    $factsByKey,
                    $this->factValue($factsByKey, 'profile.property_tax_paid') !== null,
                ),
                $this->field(
                    'charitable_contributions',
                    'Charitable contributions',
                    'Schedule A charitable contributions workpaper',
                    $this->factValue($factsByKey, 'profile.charitable_contributions_paid'),
                    ['profile.charitable_contributions_paid'],
                    $factsByKey,
                    $this->factValue($factsByKey, 'profile.charitable_contributions_paid') !== null,
                ),
                $this->field(
                    'medical_expenses',
                    'Medical expenses',
                    'Schedule A medical expenses workpaper',
                    $this->factValue($factsByKey, 'profile.medical_expenses_paid'),
                    ['profile.medical_expenses_paid'],
                    $factsByKey,
                    $this->factValue($factsByKey, 'profile.medical_expenses_paid') !== null,
                ),
                $this->field(
                    'hsa_contributions',
                    'HSA contributions',
                    'Form 8889 contribution workpaper',
                    $this->factValue($factsByKey, 'profile.hsa_contributions_paid'),
                    ['profile.hsa_contributions_paid'],
                    $factsByKey,
                    $this->factValue($factsByKey, 'profile.hsa_contributions_paid') !== null,
                ),
                $this->field(
                    'education_expenses',
                    'Education expenses',
                    'Education credit / tuition workpaper',
                    $this->factValue($factsByKey, 'profile.education_expenses_paid'),
                    ['profile.education_expenses_paid'],
                    $factsByKey,
                    $this->factValue($factsByKey, 'profile.education_expenses_paid') !== null,
                ),
                $this->field(
                    'federal_income_tax',
                    'Federal income tax after credits',
                    '1040 tax workpaper',
                    (float) ($taxComputation['federal_tax'] ?? 0),
                    ['tax.federal_tax'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_federal_tax' : null,
                    ]))
                ),
                $this->field('estimated_tax_payments', 'Federal estimated payments', '1040 payments workpaper', (float) $payments['federal'], ['payments.federal_estimates'], $factsByKey, true, ['documents.payment_confirmations', 'agency.federal_portal_payments']),
                $this->field(
                    'prior_year_federal_overpayment_credit',
                    'Prior-year federal overpayment applied',
                    '1040 prior-year overpayment credit workpaper',
                    $this->factValue($factsByKey, 'prior_return.federal_overpayment_applied'),
                    ['prior_return.federal_overpayment_applied'],
                    $factsByKey,
                    $hasLatestPersonalReturn && $this->factValue($factsByKey, 'prior_return.federal_overpayment_applied') !== null
                ),
                $this->field(
                    'capital_loss_carryforward',
                    'Capital loss carryforward',
                    '1040 capital loss carryforward workpaper',
                    $this->factValue($factsByKey, 'prior_return.capital_loss_carryforward'),
                    ['prior_return.capital_loss_carryforward'],
                    $factsByKey,
                    $hasLatestPersonalReturn && $this->factValue($factsByKey, 'prior_return.capital_loss_carryforward') !== null
                ),
                $this->field(
                    'net_operating_loss_carryforward',
                    'Net operating loss carryforward',
                    '1040 NOL carryforward workpaper',
                    $this->factValue($factsByKey, 'prior_return.nol_carryforward'),
                    ['prior_return.nol_carryforward'],
                    $factsByKey,
                    $hasLatestPersonalReturn && $this->factValue($factsByKey, 'prior_return.nol_carryforward') !== null
                ),
            ]),
            $this->form('1120s_k1_bridge', '1120-S / K-1 draft', [
                $this->field('gross_receipts', 'Gross receipts', '1120-S gross receipts workpaper', (float) ($draftReturnComputation['gross_receipts'] ?? 0), ['books.ytd_income'], $factsByKey, true, ['projection.annual_revenue', 'documents.books_exports', 'documents.bank_statements']),
                $this->field('business_expenses', 'Business expenses', '1120-S deductions workpaper', (float) ($draftReturnComputation['business_expenses'] ?? 0), ['books.ytd_expenses'], $factsByKey, true, ['documents.books_exports']),
                $this->field(
                    'ordinary_business_income',
                    'Ordinary business income',
                    'K-1 ordinary income bridge',
                    (float) ($draftReturnComputation['ordinary_business_income'] ?? 0),
                    ['tax.distributions'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        'projection.annual_net',
                        'documents.k1_packages',
                        $hasLatestBusinessReturn ? 'prior_return.latest_business_ordinary_income' : null,
                    ]))
                ),
                $this->field(
                    'officer_compensation',
                    'Officer compensation',
                    '1120-S officer compensation workpaper',
                    (float) ($draftReturnComputation['officer_compensation'] ?? 0),
                    ['tax.salary', 'profile.reasonable_salary'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        'documents.payroll_packets',
                        'owner_payments.potential_compensation',
                        $hasLatestBusinessReturn ? 'prior_return.latest_business_officer_compensation' : null,
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_wages' : null,
                    ]))
                ),
                $this->field(
                    'shareholder_distributions',
                    'Shareholder distributions',
                    'Distribution ledger bridge',
                    (float) ($draftReturnComputation['shareholder_distributions'] ?? 0),
                    ['owner_payments.reviewed_distributions'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        'documents.distribution_ledgers',
                        'documents.basis_workpapers',
                        'owner_payments.reviewed_distributions',
                        $hasLatestBusinessReturn ? 'prior_return.latest_business_distributions' : null,
                    ]))
                ),
                $this->field('qbi_wage_basis', 'QBI wage basis', '199A W-2 wage bridge', (float) ($draftReturnComputation['qbi_wage_basis'] ?? 0), ['tax.salary'], $factsByKey, true, ['documents.payroll_packets']),
                $this->field(
                    'shareholder_basis_carryforward',
                    'Shareholder basis carryforward',
                    '1120-S shareholder basis carryforward workpaper',
                    $this->factValue($factsByKey, 'prior_return.latest_business_shareholder_basis'),
                    ['prior_return.latest_business_shareholder_basis'],
                    $factsByKey,
                    $hasLatestBusinessReturn && $this->factValue($factsByKey, 'prior_return.latest_business_shareholder_basis') !== null
                ),
                $this->field(
                    'charitable_carryforward',
                    'Charitable carryforward',
                    '1120-S charitable carryforward workpaper',
                    $this->factValue($factsByKey, 'prior_return.charitable_carryforward'),
                    ['prior_return.charitable_carryforward'],
                    $factsByKey,
                    $hasLatestBusinessReturn && $this->factValue($factsByKey, 'prior_return.charitable_carryforward') !== null
                ),
            ]),
            $this->form('oregon_or40', 'Oregon OR-40 draft', [
                $this->field('resident_jurisdiction', 'Resident jurisdiction', 'OR-40 residency workpaper', $this->residencyLabel($profile), ['profile.residency'], $factsByKey),
                $this->field('federal_agi', 'Federal AGI', 'OR-40 federal AGI bridge', (float) ($taxComputation['agi'] ?? 0), ['tax.agi'], $factsByKey),
                $this->field('oregon_taxable_income', 'Oregon taxable income', 'OR-40 taxable income workpaper', (float) ($taxComputation['oregon_taxable_income'] ?? 0), ['tax.oregon_taxable_income'], $factsByKey),
                $this->field(
                    'oregon_income_tax',
                    'Oregon income tax',
                    'OR-40 tax workpaper',
                    (float) ($taxComputation['oregon_tax'] ?? 0),
                    ['tax.oregon_tax'],
                    $factsByKey,
                    true,
                    array_values(array_filter([
                        $hasLatestPersonalReturn ? 'prior_return.latest_personal_oregon_tax' : null,
                    ]))
                ),
                $this->field('oregon_estimated_payments', 'Oregon estimated payments', 'OR-40 payments workpaper', (float) $payments['state_or'], ['payments.oregon_estimates'], $factsByKey, true, ['documents.payment_confirmations', 'agency.oregon_portal_payments']),
                $this->field(
                    'prior_year_oregon_overpayment_credit',
                    'Prior-year Oregon overpayment applied',
                    'OR-40 prior-year overpayment credit workpaper',
                    $this->factValue($factsByKey, 'prior_return.oregon_overpayment_applied'),
                    ['prior_return.oregon_overpayment_applied'],
                    $factsByKey,
                    $hasLatestPersonalReturn && $this->factValue($factsByKey, 'prior_return.oregon_overpayment_applied') !== null
                ),
                $this->field(
                    'oregon_kicker_credit',
                    'Oregon kicker credit',
                    'OR-40 kicker workpaper (DOR "What\'s My Kicker?" lookup at https://revenueonline.dor.oregon.gov)',
                    (float) ($profile?->oregon_kicker_credit ?? 0),
                    ['profile.oregon_kicker_credit'],
                    $factsByKey,
                    true
                ),
            ]),
            $this->form('local_tax_workpapers', 'Local tax workpapers', [
                $this->field('portland_residency', 'Portland residency', 'Local residency workpaper', $isPortlandResident ? 'Portland resident' : 'Not Portland resident', ['profile.residency'], $factsByKey),
                $this->field('multnomah_pfa_tax', 'Multnomah PFA tax', 'Multnomah PFA workpaper', (float) ($taxComputation['multnomah_pfa_tax'] ?? 0), ['tax.multnomah_pfa_tax'], $factsByKey, $isPortlandResident),
                $this->field('portland_arts_tax', 'Portland Arts Tax', 'Portland Arts Tax workpaper', (float) ($taxComputation['portland_arts_tax'] ?? 0), ['tax.portland_arts_tax'], $factsByKey, $isPortlandResident),
                $this->field(
                    'final_return_entities',
                    'Final-return entities',
                    'Entity final-return workpaper',
                    $this->factValue($factsByKey, 'prior_return.final_return_entities'),
                    ['prior_return.final_return_entities'],
                    $factsByKey,
                    count($historicalReturnContext['final_return_entities'] ?? []) > 0
                ),
                $this->field(
                    'dissolution_closure_support',
                    'Dissolution closure support',
                    'Entity dissolution / closure support workpaper',
                    $this->factValue($factsByKey, 'prior_return.dissolution_entities'),
                    ['prior_return.dissolution_entities'],
                    $factsByKey,
                    count($historicalReturnContext['dissolution_entities'] ?? []) > 0,
                    ['documents.entity_closure_records']
                ),
            ]),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    protected function form(string $code, string $title, array $fields): array
    {
        $requiredFields = collect($fields)->filter(fn (array $field): bool => $field['required']);
        $mappedFields = $requiredFields->filter(fn (array $field): bool => $field['status'] === 'mapped');
        $readinessPercent = $requiredFields->isNotEmpty()
            ? (int) round(($mappedFields->count() / $requiredFields->count()) * 100)
            : 100;

        return [
            'code' => $code,
            'title' => $title,
            'status' => $readinessPercent === 100 ? 'mapped' : 'needs_facts',
            'status_label' => $readinessPercent === 100 ? 'Mapped' : 'Needs facts',
            'readiness_percent' => $readinessPercent,
            'mapped_field_count' => $mappedFields->count(),
            'total_field_count' => $requiredFields->count(),
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<int, string>  $sourceFactKeys
     * @param  array<int, string>  $corroborationFactKeys
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     */
    protected function field(
        string $key,
        string $label,
        string $formLine,
        mixed $value,
        array $sourceFactKeys,
        Collection $factsByKey,
        bool $required = true,
        array $corroborationFactKeys = [],
    ): array {
        $sourceFacts = collect($sourceFactKeys)
            ->map(fn (string $factKey): ?array => $factsByKey->get($factKey))
            ->filter()
            ->values();
        $corroborationFacts = collect($corroborationFactKeys)
            ->map(fn (string $factKey): ?array => $factsByKey->get($factKey))
            ->filter()
            ->values();
        $missingSourceKeys = collect($sourceFactKeys)
            ->filter(fn (string $factKey): bool => ! $factsByKey->has($factKey) || $factsByKey->get($factKey)['status'] !== 'ready')
            ->values()
            ->all();
        $missingCorroborationKeys = collect($corroborationFactKeys)
            ->filter(fn (string $factKey): bool => ! $factsByKey->has($factKey) || $factsByKey->get($factKey)['status'] !== 'ready')
            ->values()
            ->all();
        $hasReadySource = $sourceFacts->contains(fn (array $fact): bool => $fact['status'] === 'ready');
        $hasReadyCorroboration = $corroborationFacts->contains(fn (array $fact): bool => $fact['status'] === 'ready');
        $status = match (true) {
            ! $required => 'not_applicable',
            ! $hasReadySource => 'missing',
            ! empty($missingSourceKeys) => 'needs_corroboration',
            ! empty($corroborationFactKeys) && ! $hasReadyCorroboration => 'needs_corroboration',
            $hasReadySource && empty($missingSourceKeys) => 'mapped',
            $hasReadySource => 'needs_corroboration',
            default => 'missing',
        };

        return [
            'key' => $key,
            'label' => $label,
            'form_line' => $formLine,
            'value' => $value,
            'display_value' => $this->displayValue($value),
            'status' => $status,
            'status_label' => $this->fieldStatusLabel($status),
            'required' => $required,
            'source_fact_keys' => $sourceFactKeys,
            'missing_source_fact_keys' => $missingSourceKeys,
            'corroboration_fact_keys' => $corroborationFactKeys,
            'missing_corroboration_fact_keys' => $hasReadyCorroboration ? [] : $missingCorroborationKeys,
            'confidence' => $sourceFacts->merge($corroborationFacts)->isNotEmpty() ? (int) $sourceFacts->merge($corroborationFacts)->min('confidence') : 0,
        ];
    }

    protected function fieldStatusLabel(string $status): string
    {
        return match ($status) {
            'mapped' => 'Mapped',
            'needs_corroboration' => 'Needs corroboration',
            'not_applicable' => 'Not applicable',
            default => 'Missing',
        };
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $factsByKey
     */
    protected function factValue(Collection $factsByKey, string $key): mixed
    {
        return $factsByKey->get($key)['value'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $sourceSummary
     * @param  array<string, mixed>  $agencyEvidence
     * @param  array<string, mixed>  $documentEvidence
     * @param  array<string, mixed>  $bookkeepingReadiness
     * @param  array<string, mixed>  $ownerPaymentReview
     * @param  array<string, mixed>  $historicalReturnContext
     * @return array<int, array<string, mixed>>
     */
    protected function validationGates(
        ?TaxProfile $profile,
        ?array $annualProjection,
        array $sourceSummary,
        array $agencyEvidence,
        array $documentEvidence,
        array $archiveEvidence,
        array $bookkeepingReadiness,
        array $ownerPaymentReview,
        array $historicalReturnContext,
        array $revenueRecognition,
        bool $hasBooks,
        bool $hasBankFeed,
    ): array {
        $salary = (float) ($annualProjection['tax_computation']['salary'] ?? $profile?->reasonable_salary ?? 0);
        $netIncome = (float) ($annualProjection['projected_annual_net'] ?? 0);
        $salaryPercent = $netIncome > 0 ? ($salary / $netIncome) * 100 : null;
        $needsReasonableComp = ($profile?->entity_type ?? null) === 's_corp';
        $portalReconciliation = $agencyEvidence['payment_reconciliation'] ?? [];
        $federalComparison = $portalReconciliation['federal'] ?? [];
        $oregonComparison = $portalReconciliation['state_or'] ?? [];

        return [
            $this->gate(
                code: 'tax_profile',
                title: 'Tax profile complete',
                status: $profile ? 'passed' : 'needs_evidence',
                action: $profile ? 'Profile facts are available for field mapping.' : 'Complete filing status, entity, residency, salary, dependents, and safe harbor facts.',
                blocksPacket: ! $profile,
            ),
            $this->gate(
                code: 'books_available',
                title: 'Books available',
                status: $hasBooks ? 'passed' : 'needs_evidence',
                action: $hasBooks
                    ? ((string) ($sourceSummary['book_source_label'] ?? 'Books')).' are available as a source ledger.'
                    : 'Connect or refresh QuickBooks, or complete internal categorized books, before annual return mapping.',
                blocksPacket: ! $hasBooks,
            ),
            $this->gate(
                code: 'bank_feed_available',
                title: 'Bank feed available',
                status: $hasBankFeed ? 'passed' : 'needs_evidence',
                action: $hasBankFeed ? 'Bank accounts are available for payment and deposit proof.' : 'Connect or refresh bank accounts for the books-to-bank tie-out.',
                blocksPacket: ! $hasBankFeed,
            ),
            $this->gate(
                code: 'agency_portal_coverage',
                title: 'IRS and Oregon portal coverage',
                status: $this->agencyCoverageStatus($agencyEvidence),
                action: $this->agencyCoverageAction($agencyEvidence),
                blocksPacket: false,
                metrics: [
                    ['label' => 'Active portals', 'value' => "{$agencyEvidence['active_connection_count']}/2"],
                    ['label' => 'Current portals', 'value' => "{$agencyEvidence['synced_connection_count']}/2"],
                ],
            ),
            $this->gate(
                code: 'live_projection',
                title: 'Live projection computed',
                status: $annualProjection ? 'passed' : 'needs_evidence',
                action: $annualProjection ? 'Projection facts are available for draft workpapers.' : 'Compute the live projection from invoices, books, budget, and payments.',
                blocksPacket: ! $annualProjection,
            ),
            $this->gate(
                code: 'bookkeeping_close',
                title: 'Bookkeeping close posture',
                status: $this->bookkeepingCloseStatus($bookkeepingReadiness),
                action: (string) ($bookkeepingReadiness['next_action'] ?? 'Close the books before relying on them for filing.'),
                blocksPacket: ! (bool) ($bookkeepingReadiness['close_ready'] ?? false),
                metrics: [
                    ['label' => 'Source of truth', 'value' => (string) data_get($bookkeepingReadiness, 'source_of_truth.label', 'Unknown')],
                    ['label' => 'Uncategorized outflows', 'value' => (string) data_get($bookkeepingReadiness, 'metrics.uncategorized_outflow_count', 0)],
                    ['label' => 'Statement backup', 'value' => (string) data_get($bookkeepingReadiness, 'metrics.statement_document_count', 0)],
                    ['label' => 'Replacement posture', 'value' => (string) data_get($bookkeepingReadiness, 'replacement_readiness.status_label', 'Unknown')],
                ],
            ),
            $this->gate(
                code: 'owner_payment_review',
                title: 'Owner-payment review',
                status: ((int) data_get($ownerPaymentReview, 'metrics.unresolved_count', 0) > 0) ? 'needs_review' : 'passed',
                action: (string) data_get($ownerPaymentReview, 'next_action', 'Review owner-related business cash movement before finalizing the filing posture.'),
                blocksPacket: (int) data_get($ownerPaymentReview, 'metrics.unresolved_count', 0) > 0,
                metrics: [
                    ['label' => 'Unresolved items', 'value' => (string) data_get($ownerPaymentReview, 'metrics.unresolved_count', 0)],
                    ['label' => 'Reviewed distributions', 'value' => $this->displayValue(data_get($ownerPaymentReview, 'metrics.distribution_total', 0))],
                    ['label' => 'Potential compensation', 'value' => $this->displayValue(data_get($ownerPaymentReview, 'metrics.potential_compensation_total', 0))],
                ],
            ),
            $this->gate(
                code: 'books_to_bank_tie_out',
                title: 'Books-to-bank tie-out',
                status: $this->tieOutStatus($sourceSummary, $revenueRecognition),
                action: $this->tieOutAction($sourceSummary, $revenueRecognition),
                blocksPacket: ! $this->booksToBankTieOutPassed($sourceSummary, $revenueRecognition),
                metrics: [
                    ['label' => 'Book income', 'value' => $this->displayValue($sourceSummary['book_income'])],
                    ['label' => 'Bank deposits', 'value' => $this->displayValue($sourceSummary['bank_deposits'])],
                    ['label' => 'Difference', 'value' => $this->displayValue($sourceSummary['deposit_tie_out_difference'])],
                ],
            ),
            $this->gate(
                code: 'reasonable_compensation',
                title: 'Reasonable compensation posture',
                status: $this->reasonableCompStatus($needsReasonableComp, $salary, $salaryPercent),
                action: $this->reasonableCompAction($needsReasonableComp, $salary, $salaryPercent),
                blocksPacket: $needsReasonableComp && $salary <= 0,
                metrics: [
                    ['label' => 'Salary', 'value' => $this->displayValue($salary)],
                    ['label' => 'Salary / net', 'value' => $salaryPercent === null ? 'Not calculated' : number_format($salaryPercent, 1).'%'],
                ],
            ),
            $this->gate(
                code: 'agency_payment_reconciliation',
                title: 'Agency payment history reconciled',
                status: $portalReconciliation['status'] ?? 'needs_review',
                action: $this->agencyPaymentAction($portalReconciliation),
                blocksPacket: (bool) ($portalReconciliation['blocking'] ?? false),
                metrics: [
                    ['label' => 'Federal ledger / portal', 'value' => $this->paymentComparisonValue($federalComparison)],
                    ['label' => 'Oregon ledger / portal', 'value' => $this->paymentComparisonValue($oregonComparison)],
                ],
            ),
            $this->gate(
                code: 'agency_notice_clearance',
                title: 'Agency notices cleared',
                status: $this->agencyNoticeStatus($agencyEvidence),
                action: $this->agencyNoticeAction($agencyEvidence),
                blocksPacket: (int) ($agencyEvidence['open_notice_count'] ?? 0) > 0,
                metrics: [
                    ['label' => 'Open notices', 'value' => (string) ($agencyEvidence['open_notice_count'] ?? 0)],
                    ['label' => 'Transcripts on file', 'value' => (string) ($agencyEvidence['transcript_count'] ?? 0)],
                ],
            ),
            $this->gate(
                code: 'financial_archive_coverage',
                title: 'Financial archive coverage',
                status: ($archiveEvidence['coverage_status'] ?? 'missing') === 'current' ? 'passed' : 'needs_evidence',
                action: (string) ($archiveEvidence['next_action'] ?? 'Inventory prior returns, bank statements, and payroll packets.'),
                blocksPacket: false,
                metrics: [
                    ['label' => 'Business return years', 'value' => $this->yearListValue($archiveEvidence['prior_business_return_years'] ?? [])],
                    ['label' => 'Personal return years', 'value' => $this->yearListValue($archiveEvidence['prior_personal_return_years'] ?? [])],
                    ['label' => 'Bank statement years', 'value' => $this->yearListValue($archiveEvidence['bank_statement_years'] ?? [])],
                    ['label' => 'Payroll docs', 'value' => (string) ($archiveEvidence['payroll_document_count'] ?? 0)],
                ],
            ),
            $this->gate(
                code: 'prior_year_return_context',
                title: 'Prior-year return context extracted',
                status: $this->priorYearReturnStatus($historicalReturnContext),
                action: (string) ($historicalReturnContext['next_action'] ?? 'Extract prior-year return facts before relying on carryovers and overpayment credits.'),
                blocksPacket: false,
                metrics: [
                    ['label' => 'Latest required year', 'value' => (string) ($historicalReturnContext['latest_required_year'] ?? 'Unknown')],
                    ['label' => 'Return packets', 'value' => (string) ($historicalReturnContext['return_packet_count'] ?? 0)],
                    ['label' => 'Extracted returns', 'value' => (string) ($historicalReturnContext['extracted_return_count'] ?? 0)],
                    ['label' => 'Missing extraction', 'value' => (string) ($historicalReturnContext['documents_missing_fact_extraction'] ?? 0)],
                ],
            ),
            $this->gate(
                code: 'personal_return_inputs',
                title: 'Personal-return inputs captured',
                status: $this->personalReturnInputsComplete($profile) ? 'passed' : 'needs_evidence',
                action: $this->personalReturnInputsComplete($profile)
                    ? 'Mortgage, property tax, charity, medical, HSA, and education inputs are explicit for the 1040 workpapers.'
                    : 'Record the current-year personal-return inputs before relying on the 1040 bridge.',
                blocksPacket: false,
                metrics: [
                    ['label' => 'Answered inputs', 'value' => count(array_filter($this->personalReturnInputFieldValues($profile), fn (mixed $value): bool => $value !== null)).'/6'],
                    ['label' => 'Mortgage interest', 'value' => $this->displayValue($profile?->mortgage_interest_paid)],
                    ['label' => 'Property tax', 'value' => $this->displayValue($profile?->property_tax_paid)],
                    ['label' => 'Charity', 'value' => $this->displayValue($profile?->charitable_contributions_paid)],
                ],
            ),
            $this->gate(
                code: 'entity_lifecycle_review',
                title: 'Entity lifecycle review',
                status: (string) ($historicalReturnContext['entity_lifecycle_status'] ?? 'passed'),
                action: (string) ($historicalReturnContext['entity_lifecycle_next_action'] ?? 'No extra entity lifecycle review is needed.'),
                blocksPacket: ! (bool) ($historicalReturnContext['all_entity_decisions_recorded'] ?? true),
                metrics: [
                    ['label' => 'Business entities', 'value' => (string) count($historicalReturnContext['business_entities'] ?? [])],
                    ['label' => 'Decisions recorded', 'value' => ($historicalReturnContext['all_entity_decisions_recorded'] ?? false) ? 'Yes' : 'No'],
                    ['label' => 'Final returns', 'value' => (string) count($historicalReturnContext['final_return_entities'] ?? [])],
                ],
            ),
            $this->gate(
                code: 'final_return_support',
                title: 'Final-return closure support',
                status: $this->finalReturnSupportStatus($historicalReturnContext),
                action: $this->finalReturnSupportAction($historicalReturnContext),
                blocksPacket: $this->finalReturnSupportBlocksPacket($historicalReturnContext),
                metrics: [
                    ['label' => 'Final returns', 'value' => $this->entityListValue($historicalReturnContext['final_return_entities'] ?? [])],
                    ['label' => 'Dissolutions', 'value' => $this->entityListValue($historicalReturnContext['dissolution_entities'] ?? [])],
                    ['label' => 'Missing closure docs', 'value' => (string) count($historicalReturnContext['entity_lifecycle_document_requests'] ?? [])],
                ],
            ),
            $this->gate(
                code: 'tax_document_coverage',
                title: 'Current-year tax-document coverage',
                status: $this->taxDocumentCoverageStatus($documentEvidence),
                action: (string) ($documentEvidence['next_action'] ?? 'Upload the current-year support documents used to corroborate the return packet.'),
                blocksPacket: false,
                metrics: [
                    ['label' => 'Reviewed docs', 'value' => (string) ($documentEvidence['reviewed_document_count'] ?? 0)],
                    ['label' => 'Pending review', 'value' => (string) ($documentEvidence['needs_review_count'] ?? 0)],
                    ['label' => 'Payroll / W-2', 'value' => (string) ($documentEvidence['payroll_document_count'] ?? 0)],
                    ['label' => 'K-1 / basis', 'value' => (string) ((int) ($documentEvidence['k1_document_count'] ?? 0) + (int) ($documentEvidence['basis_document_count'] ?? 0))],
                    ['label' => 'Books / bank', 'value' => (string) ((int) ($documentEvidence['books_export_count'] ?? 0) + (int) ($documentEvidence['bank_statement_count'] ?? 0))],
                    ['label' => 'Closure docs', 'value' => (string) ($documentEvidence['entity_closure_record_count'] ?? 0)],
                ],
            ),
            $this->gate(
                code: 'tax_document_review_queue',
                title: 'Uploaded tax documents reviewed',
                status: ((int) ($documentEvidence['needs_review_count'] ?? 0) > 0) ? 'needs_evidence' : 'passed',
                action: ((int) ($documentEvidence['needs_review_count'] ?? 0) > 0)
                    ? 'Review the uploaded current-year tax documents before owner signoff so the packet cannot ignore contradictory evidence.'
                    : 'No uploaded current-year tax documents are waiting for review.',
                blocksPacket: (int) ($documentEvidence['needs_review_count'] ?? 0) > 0,
            ),
            $this->gate(
                code: 'source_law_review',
                title: 'Current source-law review',
                status: 'needs_evidence',
                action: 'Before filing, verify final-year IRS, Oregon, and local instructions against the mapped draft packet.',
                blocksPacket: false,
            ),
        ];
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $metrics
     */
    protected function gate(
        string $code,
        string $title,
        string $status,
        string $action,
        bool $blocksPacket,
        array $metrics = [],
    ): array {
        return [
            'code' => $code,
            'title' => $title,
            'status' => $status,
            'status_label' => $status === 'passed' ? 'Passed' : ($blocksPacket ? 'Blocking' : 'Review needed'),
            'blocks_packet' => $blocksPacket,
            'action' => $action,
            'metrics' => $metrics,
        ];
    }

    /**
     * @param  array<string, mixed>  $sourceSummary
     */
    protected function tieOutStatus(array $sourceSummary, array $revenueRecognition): string
    {
        if ($this->booksToBankTieOutPassed($sourceSummary, $revenueRecognition)) {
            return 'passed';
        }

        if (! $sourceSummary['has_tie_out_data']) {
            return 'needs_evidence';
        }

        if ((int) data_get($revenueRecognition, 'metrics.unresolved_inflow_count', 0) > 0) {
            return 'needs_review';
        }

        return 'needs_evidence';
    }

    /**
     * @param  array<string, mixed>  $bookkeepingReadiness
     */
    protected function bookkeepingCloseStatus(array $bookkeepingReadiness): string
    {
        if (($bookkeepingReadiness['close_ready'] ?? false) === true) {
            return 'passed';
        }

        return match ($bookkeepingReadiness['status'] ?? 'needs_review') {
            'needs_close', 'needs_review' => 'needs_review',
            default => 'needs_evidence',
        };
    }

    /**
     * @param  array<string, mixed>  $documentEvidence
     */
    protected function taxDocumentCoverageStatus(array $documentEvidence): string
    {
        return match ($documentEvidence['coverage_status'] ?? 'missing') {
            'current' => 'passed',
            'partial' => 'needs_review',
            default => 'needs_evidence',
        };
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function priorYearReturnStatus(array $historicalReturnContext): string
    {
        return match ($historicalReturnContext['coverage_status'] ?? 'missing') {
            'current' => 'passed',
            'partial' => 'needs_review',
            default => 'needs_evidence',
        };
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function finalReturnSupportStatus(array $historicalReturnContext): string
    {
        $finalReturnEntities = $historicalReturnContext['final_return_entities'] ?? [];
        $missingClosureDocuments = $historicalReturnContext['entity_lifecycle_document_requests'] ?? [];

        if ($finalReturnEntities === []) {
            return 'passed';
        }

        if ($missingClosureDocuments !== []) {
            return 'needs_evidence';
        }

        return 'passed';
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function finalReturnSupportBlocksPacket(array $historicalReturnContext): bool
    {
        return count($historicalReturnContext['entity_lifecycle_document_requests'] ?? []) > 0;
    }

    /**
     * @param  array<string, mixed>  $historicalReturnContext
     */
    protected function finalReturnSupportAction(array $historicalReturnContext): string
    {
        $finalReturnEntities = $historicalReturnContext['final_return_entities'] ?? [];
        $dissolutionEntities = $historicalReturnContext['dissolution_entities'] ?? [];
        $missingClosureDocuments = $historicalReturnContext['entity_lifecycle_document_requests'] ?? [];

        if ($finalReturnEntities === []) {
            return 'No final-return or dissolution support is needed for extra entities.';
        }

        if ($missingClosureDocuments !== []) {
            return collect($missingClosureDocuments)
                ->filter(fn (mixed $request): bool => is_string($request) && $request !== '')
                ->implode(' ');
        }

        if ($dissolutionEntities !== []) {
            return 'Final-return and dissolution support is on file for: '.$this->entityListValue($dissolutionEntities).'.';
        }

        return 'Final-return support is recorded for: '.$this->entityListValue($finalReturnEntities).'.';
    }

    /**
     * @param  array<string, mixed>  $sourceSummary
     */
    protected function tieOutAction(array $sourceSummary, array $revenueRecognition): string
    {
        if ((bool) ($sourceSummary['deposit_tie_out_passes'] ?? false)) {
            return 'Book income and bank deposits tie within tolerance.';
        }

        if ($this->revenueRecognitionClearsTieOut($revenueRecognition)) {
            return 'All business inflows have been reviewed and are either recognized revenue or explicitly excluded from revenue.';
        }

        if ((int) data_get($revenueRecognition, 'metrics.unresolved_inflow_count', 0) > 0) {
            return 'Review the remaining business inflows so each deposit is explicitly confirmed as revenue or excluded from revenue.';
        }

        if (! $sourceSummary['has_tie_out_data']) {
            return 'Sync QuickBooks transactions and bank deposits before treating gross receipts as return-ready.';
        }

        return 'Resolve the book-income to bank-deposit difference before generating a signoff packet.';
    }

    protected function booksToBankTieOutPassed(array $sourceSummary, array $revenueRecognition): bool
    {
        if ((bool) ($sourceSummary['deposit_tie_out_passes'] ?? false)) {
            return true;
        }

        return $this->revenueRecognitionClearsTieOut($revenueRecognition);
    }

    protected function revenueRecognitionClearsTieOut(array $revenueRecognition): bool
    {
        if (($revenueRecognition['status'] ?? null) === 'needs_sources') {
            return false;
        }

        return (int) data_get($revenueRecognition, 'metrics.unresolved_inflow_count', 0) === 0;
    }

    protected function reasonableCompStatus(bool $needsReasonableComp, float $salary, ?float $salaryPercent): string
    {
        if (! $needsReasonableComp) {
            return 'passed';
        }

        if ($salary <= 0) {
            return 'needs_evidence';
        }

        if ($salaryPercent !== null && ($salaryPercent < 25 || $salaryPercent > 70)) {
            return 'needs_review';
        }

        return 'passed';
    }

    protected function reasonableCompAction(bool $needsReasonableComp, float $salary, ?float $salaryPercent): string
    {
        if (! $needsReasonableComp) {
            return 'Reasonable compensation gate is not required for this entity type.';
        }

        if ($salary <= 0) {
            return 'Add reasonable compensation before S-corp return mapping can be trusted.';
        }

        if ($salaryPercent !== null && $salaryPercent < 25) {
            return 'Salary is aggressive relative to projected net income; keep support before owner signoff.';
        }

        if ($salaryPercent !== null && $salaryPercent > 70) {
            return 'Salary may be conservative for FICA optimization; review whether distributions should carry more profit.';
        }

        return 'Reasonable compensation is present and within the current review band.';
    }

    /**
     * @param  array<string, mixed>  $agencyEvidence
     */
    protected function agencyCoverageStatus(array $agencyEvidence): string
    {
        if ((bool) ($agencyEvidence['has_synced_coverage'] ?? false)) {
            return 'passed';
        }

        if ((int) ($agencyEvidence['active_connection_count'] ?? 0) > 0) {
            return 'needs_review';
        }

        return 'needs_evidence';
    }

    /**
     * @param  array<string, mixed>  $agencyEvidence
     */
    protected function agencyCoverageAction(array $agencyEvidence): string
    {
        if ((bool) ($agencyEvidence['has_synced_coverage'] ?? false)) {
            return 'IRS and Oregon portals are current and available as supporting evidence.';
        }

        if ((int) ($agencyEvidence['active_connection_count'] ?? 0) > 0) {
            return 'Finish syncing both IRS and Oregon portals so agency balances, payments, transcripts, and notices can corroborate the packet.';
        }

        return 'Add IRS and Oregon authenticated portal connections for full agency-side evidence coverage.';
    }

    /**
     * @param  array<string, mixed>  $portalReconciliation
     */
    protected function agencyPaymentAction(array $portalReconciliation): string
    {
        if (($portalReconciliation['blocking'] ?? false) === true) {
            return 'Resolve the difference between the estimated-payment ledger and the agency-reported payment history before owner signoff.';
        }

        if (($portalReconciliation['status'] ?? null) === 'passed') {
            return 'Internal estimated-payment records match the latest IRS and Oregon portal history within tolerance.';
        }

        return 'Portal payment history is not available yet. Once synced, use it to corroborate the internal payment ledger.';
    }

    /**
     * @param  array<string, mixed>  $agencyEvidence
     */
    protected function agencyNoticeStatus(array $agencyEvidence): string
    {
        if ((int) ($agencyEvidence['open_notice_count'] ?? 0) > 0) {
            return 'needs_evidence';
        }

        if ((bool) ($agencyEvidence['has_synced_coverage'] ?? false)) {
            return 'passed';
        }

        return 'needs_review';
    }

    /**
     * @param  array<string, mixed>  $agencyEvidence
     */
    protected function agencyNoticeAction(array $agencyEvidence): string
    {
        if ((int) ($agencyEvidence['open_notice_count'] ?? 0) > 0) {
            return 'Review and clear synced IRS/Oregon notices before generating an owner signoff packet.';
        }

        if ((bool) ($agencyEvidence['has_synced_coverage'] ?? false)) {
            return 'No open synced IRS or Oregon notices are blocking this packet.';
        }

        return 'Once the agency portals are synced, use notices and transcripts as part of final return corroboration.';
    }

    /**
     * @param  array<string, mixed>  $comparison
     */
    protected function paymentComparisonValue(array $comparison): string
    {
        if (($comparison['has_portal_history'] ?? false) !== true) {
            return 'No portal history';
        }

        return $this->displayValue($comparison['internal'] ?? 0).' / '.$this->displayValue($comparison['portal'] ?? 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $forms
     * @return array{mapped: int, total: int, readiness_percent: int}
     */
    protected function fieldCounts(array $forms): array
    {
        $fields = collect($forms)->flatMap(fn (array $form): array => $form['fields']);
        $requiredFields = $fields->filter(fn (array $field): bool => $field['required']);
        $mappedFields = $requiredFields->filter(fn (array $field): bool => $field['status'] === 'mapped');

        return [
            'mapped' => $mappedFields->count(),
            'total' => $requiredFields->count(),
            'readiness_percent' => $requiredFields->isNotEmpty()
                ? (int) round(($mappedFields->count() / $requiredFields->count()) * 100)
                : 100,
        ];
    }

    protected function status(?TaxProfile $profile, ?array $annualProjection, bool $hasBooks, bool $hasBankFeed, int $blockingGateCount): string
    {
        if (! $profile) {
            return 'needs_profile';
        }

        if (! $hasBooks || ! $hasBankFeed) {
            return 'needs_source_data';
        }

        if (! $annualProjection) {
            return 'needs_projection';
        }

        if ($blockingGateCount > 0) {
            return 'needs_validation';
        }

        return 'draft_ready_for_owner_review';
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    protected function packetEligibleForOwnerApproval(array $packet): bool
    {
        return ($packet['status'] ?? null) === 'draft_ready_for_owner_review'
            && (int) ($packet['total_field_count'] ?? 0) > 0
            && (int) ($packet['mapped_field_count'] ?? 0) === (int) ($packet['total_field_count'] ?? 0)
            && (int) ($packet['readiness_percent'] ?? 0) === 100;
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'draft_ready_for_owner_review' => 'Draft ready for owner review',
            'needs_profile' => 'Tax profile needed',
            'needs_source_data' => 'Source data needed',
            'needs_projection' => 'Projection needed',
            'needs_validation' => 'Validation needed',
            default => 'Not ready',
        };
    }

    protected function nextAction(string $status): string
    {
        return match ($status) {
            'draft_ready_for_owner_review' => 'Review the mapped fields, source facts, and validation gates before generating draft annual forms for owner approval.',
            'needs_profile' => 'Complete the tax profile so entity, filing status, residency, dependents, and S-corp assumptions can map into forms.',
            'needs_source_data' => 'Connect or refresh QuickBooks and bank feeds before form fields can be source-linked.',
            'needs_projection' => 'Run the live annual projection before annual return fields can be computed.',
            'needs_validation' => 'Resolve blocking validation gates before generating a draft signoff packet.',
            default => 'Resolve missing return-prep inputs.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function personalReturnInputFieldValues(?TaxProfile $profile): array
    {
        return [
            'mortgage_interest_paid' => $profile?->mortgage_interest_paid,
            'property_tax_paid' => $profile?->property_tax_paid,
            'charitable_contributions_paid' => $profile?->charitable_contributions_paid,
            'medical_expenses_paid' => $profile?->medical_expenses_paid,
            'hsa_contributions_paid' => $profile?->hsa_contributions_paid,
            'education_expenses_paid' => $profile?->education_expenses_paid,
        ];
    }

    protected function personalReturnInputsComplete(?TaxProfile $profile): bool
    {
        foreach ($this->personalReturnInputFieldValues($profile) as $value) {
            if ($value === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int>  $years
     */
    protected function yearListValue(array $years): string
    {
        if ($years === []) {
            return 'None';
        }

        return implode(', ', array_map(static fn (int $year): string => (string) $year, $years));
    }

    /**
     * @param  array<int, string>  $entities
     */
    protected function entityListValue(array $entities): string
    {
        if ($entities === []) {
            return 'None';
        }

        return implode(', ', array_values(array_filter($entities, fn (mixed $entity): bool => is_string($entity) && $entity !== '')));
    }

    protected function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'Missing';
        }

        if (is_float($value)) {
            return '$'.number_format((float) $value, 0);
        }

        if (is_int($value)) {
            return number_format($value);
        }

        return (string) $value;
    }
}
