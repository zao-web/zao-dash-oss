<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalAccount;
use App\Models\PersonalTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersonalFinanceService
{
    /**
     * Import transactions from an OFX/QFX file.
     *
     * @return array{accounts_created: int, transactions_imported: int, duplicates_skipped: int}
     */
    public function importFromOfx(string $filePath, int $userId): array
    {
        $parser = new \OfxParser\Parser;
        $ofx = $parser->loadFromFile($filePath);

        $stats = ['accounts_created' => 0, 'transactions_imported' => 0, 'duplicates_skipped' => 0];

        foreach ($ofx->bankAccounts as $bankAccount) {
            $account = PersonalAccount::firstOrCreate(
                [
                    'user_id' => $userId,
                    'institution_name' => $bankAccount->institution ?? 'Unknown',
                    'name' => $bankAccount->accountType ?? 'Checking',
                ],
                [
                    'account_type' => $this->mapOfxAccountType($bankAccount->accountType ?? ''),
                    'current_balance' => $bankAccount->balance ?? 0,
                ]
            );

            if ($account->wasRecentlyCreated) {
                $stats['accounts_created']++;
            }

            $batchId = Str::uuid()->toString();

            foreach ($bankAccount->statement->transactions as $tx) {
                $exists = PersonalTransaction::where('personal_account_id', $account->id)
                    ->where('transaction_date', $tx->date->format('Y-m-d'))
                    ->where('amount', $tx->amount)
                    ->where('description', $tx->name)
                    ->exists();

                if ($exists) {
                    $stats['duplicates_skipped']++;

                    continue;
                }

                PersonalTransaction::create([
                    'personal_account_id' => $account->id,
                    'transaction_date' => $tx->date->format('Y-m-d'),
                    'amount' => $tx->amount,
                    'description' => $tx->name,
                    'original_description' => $tx->name,
                    'merchant_name' => $tx->name,
                    'import_source' => 'ofx',
                    'import_batch_id' => $batchId,
                ]);

                $stats['transactions_imported']++;
            }

            if ($bankAccount->balance) {
                $account->update(['current_balance' => $bankAccount->balance, 'last_synced_at' => now()]);
            }
        }

        Log::info('PersonalFinanceService: OFX import complete', $stats);

        return $stats;
    }

    /**
     * Import transactions from a CSV file.
     *
     * @param  array{date?: int, description?: int, amount?: int, debit?: int|null, credit?: int|null}  $columnMapping
     * @return array{transactions_imported: int, duplicates_skipped: int, errors: int}
     */
    public function importFromCsv(string $filePath, array $columnMapping, int $accountId, string $dateFormat = 'Y-m-d'): array
    {
        $stats = ['transactions_imported' => 0, 'duplicates_skipped' => 0, 'errors' => 0];
        $batchId = Str::uuid()->toString();

        $handle = fopen($filePath, 'r');
        if (! $handle) {
            throw new \RuntimeException("Cannot open CSV file: {$filePath}");
        }

        $headerSkipped = false;

        while (($row = fgetcsv($handle)) !== false) {
            if (! $headerSkipped) {
                $headerSkipped = true;
                $dateCol = $columnMapping['date'] ?? 0;
                if (isset($row[$dateCol]) && ! is_numeric(str_replace(['-', '/', '.'], '', $row[$dateCol]))) {
                    continue;
                }
            }

            try {
                $dateCol = $columnMapping['date'] ?? 0;
                $descCol = $columnMapping['description'] ?? 1;
                $amountCol = $columnMapping['amount'] ?? 2;
                $debitCol = $columnMapping['debit'] ?? null;
                $creditCol = $columnMapping['credit'] ?? null;

                $dateStr = trim($row[$dateCol] ?? '');
                $description = trim($row[$descCol] ?? '');

                if (empty($dateStr) || empty($description)) {
                    continue;
                }

                if ($debitCol !== null && $creditCol !== null) {
                    $debit = $this->parseAmount($row[$debitCol] ?? '');
                    $credit = $this->parseAmount($row[$creditCol] ?? '');
                    $amount = $debit > 0 ? $debit : -$credit;
                } else {
                    $amount = $this->parseAmount($row[$amountCol] ?? '');
                }

                $date = Carbon::createFromFormat($dateFormat, $dateStr);
                if (! $date) {
                    $date = Carbon::parse($dateStr);
                }

                $exists = PersonalTransaction::where('personal_account_id', $accountId)
                    ->where('transaction_date', $date->format('Y-m-d'))
                    ->where('amount', $amount)
                    ->where('description', $description)
                    ->exists();

                if ($exists) {
                    $stats['duplicates_skipped']++;

                    continue;
                }

                PersonalTransaction::create([
                    'personal_account_id' => $accountId,
                    'transaction_date' => $date->format('Y-m-d'),
                    'amount' => $amount,
                    'description' => $description,
                    'original_description' => $description,
                    'import_source' => 'csv',
                    'import_batch_id' => $batchId,
                ]);

                $stats['transactions_imported']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::warning('PersonalFinanceService: CSV row import failed', ['error' => $e->getMessage()]);
            }
        }

        fclose($handle);
        Log::info('PersonalFinanceService: CSV import complete', $stats);

        return $stats;
    }

    /**
     * Get account summary for a user.
     *
     * @return array{accounts: \Illuminate\Database\Eloquent\Collection, total_assets: float, total_liabilities: float, net_worth: float}
     */
    public function getAccountSummary(int $userId): array
    {
        $accounts = PersonalAccount::where('user_id', $userId)
            ->where('is_closed', false)
            ->withoutInactive()
            ->orderBy('account_type')
            ->get();

        $totalAssets = $accounts->whereIn('account_type', ['checking', 'savings', 'investment'])
            ->sum('current_balance');
        $totalLiabilities = $accounts->whereIn('account_type', ['credit_card', 'loan', 'collections', 'tax_debt'])
            ->sum(fn ($a) => abs($a->current_balance));

        return [
            'accounts' => $accounts,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'net_worth' => $totalAssets - $totalLiabilities,
        ];
    }

    protected function mapOfxAccountType(string $type): string
    {
        return match (strtolower($type)) {
            'checking' => 'checking',
            'savings' => 'savings',
            'creditcard', 'credit card' => 'credit_card',
            'moneymrkt', 'money market' => 'savings',
            default => 'checking',
        };
    }

    protected function parseAmount(string $value): float
    {
        $value = trim($value);
        $isNegative = str_contains($value, '(') || str_starts_with($value, '-');
        $value = preg_replace('/[^0-9.]/', '', $value);
        $amount = (float) $value;

        return $isNegative ? -$amount : $amount;
    }
}
