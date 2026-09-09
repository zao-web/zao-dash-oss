<?php

namespace App\Services\Tax;

use App\Models\EstimatedTaxPayment;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use Illuminate\Support\Facades\Log;

class EstimatedPaymentDetectionService
{
    /**
     * Tax payment keywords for auto-detection.
     *
     * @var array<string, string>
     */
    protected array $taxPaymentPatterns = [
        'irs' => 'federal',
        'internal revenue' => 'federal',
        'eftps' => 'federal',
        'us treasury' => 'federal',
        'united states treasury' => 'federal',
        'federal tax' => 'federal',
        'oregon department of revenue' => 'state_or',
        'oregon dor' => 'state_or',
        'or dept of revenue' => 'state_or',
        'state of oregon' => 'state_or',
        'portland revenue' => 'local_portland',
        'city of portland' => 'local_portland',
        'multnomah county' => 'local_multnomah',
    ];

    /**
     * Check if a transaction looks like an estimated tax payment.
     * Creates an auto-detected record if it matches.
     */
    public function detectAndRecord(PersonalTransaction $transaction): ?EstimatedTaxPayment
    {
        $text = strtolower(trim(($transaction->merchant_name ?? '').' '.($transaction->description ?? '')));

        if (empty($text)) {
            return null;
        }

        // Check against tax payment patterns
        $jurisdiction = null;
        foreach ($this->taxPaymentPatterns as $pattern => $jur) {
            if (str_contains($text, $pattern)) {
                $jurisdiction = $jur;
                break;
            }
        }

        if (! $jurisdiction) {
            // Also check if the transaction category is a tax payment type
            $category = $transaction->category;
            if ($category && $category->type === 'tax_payment') {
                $jurisdiction = str_contains(strtolower($category->name), 'state') ? 'state_or' : 'federal';
            }
        }

        if (! $jurisdiction) {
            return null;
        }

        // Only detect payments above $100 (filter out small fees).
        if (abs((float) $transaction->amount) < 100) {
            return null;
        }

        // Check for duplicate — same transaction shouldn't create two records
        $existing = EstimatedTaxPayment::where('personal_transaction_id', $transaction->id)->first();
        if ($existing) {
            return $existing;
        }

        // Determine quarter from payment date
        $quarter = $this->determineQuarter($transaction->transaction_date);
        $taxYear = $this->determineTaxYear($transaction->transaction_date, $quarter);

        $payment = EstimatedTaxPayment::create([
            'user_id' => $transaction->account->user_id,
            'tax_year' => $taxYear,
            'quarter' => $quarter,
            'jurisdiction' => $jurisdiction,
            'payment_date' => $transaction->transaction_date,
            'amount' => abs((float) $transaction->amount),
            'payment_method' => 'auto_detected',
            'status' => 'auto_detected',
            'personal_transaction_id' => $transaction->id,
            'notes' => "Auto-detected from: {$transaction->merchant_name} — {$transaction->description}",
        ]);

        Log::info('EstimatedPaymentDetection: auto-detected tax payment', [
            'transaction_id' => $transaction->id,
            'jurisdiction' => $jurisdiction,
            'amount' => $payment->amount,
            'quarter' => $quarter,
            'tax_year' => $taxYear,
        ]);

        return $payment;
    }

    /**
     * Scan all unchecked tax-categorized transactions for a user.
     */
    public function scanForPayments(int $userId, int $year): int
    {
        $taxCategoryIds = TransactionCategory::where('type', 'tax_payment')->pluck('id');

        $transactions = PersonalTransaction::whereHas('account', fn ($q) => $q->where('user_id', $userId))
            ->whereBetween('transaction_date', ["{$year}-02-01", ($year + 1).'-01-31'])
            ->where(function ($q) use ($taxCategoryIds) {
                $q->whereIn('category_id', $taxCategoryIds)
                    ->orWhere(function ($q2) {
                        $q2->where(function ($q3) {
                            foreach (array_keys($this->taxPaymentPatterns) as $pattern) {
                                $q3->orWhereRaw('LOWER(merchant_name) LIKE ?', ["%{$pattern}%"])
                                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$pattern}%"]);
                            }
                        });
                    });
            })
            ->whereDoesntHave('estimatedTaxPayment')
            ->where(function ($q) {
                $q->where('amount', '>=', 100)
                    ->orWhere('amount', '<=', -100);
            })
            ->get();

        $detected = 0;
        foreach ($transactions as $txn) {
            if ($this->detectAndRecord($txn)) {
                $detected++;
            }
        }

        return $detected;
    }

    protected function determineQuarter(\Carbon\Carbon|string $date): int
    {
        $month = $date instanceof \Carbon\Carbon ? $date->month : (int) date('n', strtotime($date));

        // Payment-date heuristic: most estimates are paid near the IRS due dates.
        // January payments are the prior tax year's Q4 estimate.
        return match (true) {
            $month === 1 => 4,
            $month <= 4 => 1,
            $month <= 6 => 2,
            $month <= 9 => 3,
            default => 4,
        };
    }

    protected function determineTaxYear(\Carbon\Carbon|string $date, int $quarter): int
    {
        $year = $date instanceof \Carbon\Carbon ? $date->year : (int) date('Y', strtotime($date));

        // Q4 payments in January are for the prior year
        $month = $date instanceof \Carbon\Carbon ? $date->month : (int) date('n', strtotime($date));
        if ($month === 1 && $quarter === 4) {
            return $year - 1;
        }

        return $year;
    }
}
