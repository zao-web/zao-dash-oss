<?php

namespace App\Services\PersonalFinance;

use App\Models\Invoice;

class InvoiceVelocityService
{
    /**
     * Calculate Days Sales Outstanding (DSO) per client.
     *
     * @return array<int, array{
     *     client_id: int,
     *     client_name: string,
     *     avg_dso: float,
     *     median_dso: float,
     *     invoice_count: int,
     *     category: string,
     * }>
     */
    public function getDsoByClient(): array
    {
        return Invoice::whereNotNull('paid_at')
            ->whereNotNull('issue_date')
            ->with('client')
            ->get()
            ->groupBy('client_id')
            ->map(function ($invoices, $clientId) {
                $dsos = $invoices->map(fn ($inv) => $inv->issue_date->diffInDays($inv->paid_at))->filter();

                $avgDso = $dsos->avg() ?? 0;

                return [
                    'client_id' => $clientId,
                    'client_name' => $invoices->first()->client?->name ?? 'Unknown',
                    'avg_dso' => round($avgDso, 1),
                    'median_dso' => round($dsos->median() ?? 0, 1),
                    'invoice_count' => $invoices->count(),
                    'category' => match (true) {
                        $avgDso <= 15 => 'fast',
                        $avgDso <= 30 => 'on_time',
                        $avgDso <= 60 => 'slow',
                        default => 'very_slow',
                    },
                ];
            })
            ->sortBy('avg_dso')
            ->values()
            ->toArray();
    }

    /**
     * Calculate overall DSO across all clients.
     */
    public function getOverallDso(): float
    {
        $invoices = Invoice::whereNotNull('paid_at')
            ->whereNotNull('issue_date')
            ->get();

        $dsos = $invoices->map(fn ($inv) => $inv->issue_date->diffInDays($inv->paid_at))->filter();

        return round($dsos->avg() ?? 0, 1);
    }

    /**
     * Analyze whether offering a quick-pay discount is financially worthwhile.
     *
     * @return array{
     *     discount_cost: float,
     *     days_accelerated: int,
     *     interest_saved_on_debt: float,
     *     net_benefit: float,
     *     worth_it: bool,
     * }
     */
    public function calculateQuickPayDiscount(
        float $invoiceAmount,
        float $discountPercent,
        float $currentDso,
        float $highestDebtRate
    ): array {
        $discountCost = $invoiceAmount * ($discountPercent / 100);
        $daysAccelerated = (int) max(0, $currentDso - 10);
        $interestSaved = ($invoiceAmount * ($highestDebtRate / 100 / 365)) * $daysAccelerated;

        return [
            'discount_cost' => round($discountCost, 2),
            'days_accelerated' => $daysAccelerated,
            'interest_saved_on_debt' => round($interestSaved, 2),
            'net_benefit' => round($interestSaved - $discountCost, 2),
            'worth_it' => $interestSaved > $discountCost,
        ];
    }
}
