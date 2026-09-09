<?php

namespace App\Services\PersonalFinance;

use Illuminate\Support\Carbon;

class StatementLineItemParserService
{
    /**
     * @return array{
     *     line_items: array<int, array{
     *         transaction_date: string,
     *         description: string,
     *         amount: float,
     *         signed_amount: float|null,
     *         direction: string,
     *         balance: float|null,
     *         raw_line: string,
     *     }>,
     *     line_item_summary: array{
     *         parser: string,
     *         parsed_count: int,
     *         inflow_count: int,
     *         outflow_count: int,
     *         unknown_direction_count: int,
     *         parsed_deposits: float,
     *         parsed_withdrawals: float,
     *         usable_for_matching: bool,
     *     },
     * }
     */
    public function parse(string $text, ?int $statementYear = null): array
    {
        $lineItems = [];

        foreach ($this->candidateLines($text) as $line) {
            $parsedLineItem = $this->parseLine($line, $statementYear);

            if ($parsedLineItem === null) {
                continue;
            }

            $lineItems[] = $parsedLineItem;
        }

        $lineItems = $this->dedupe($lineItems);
        $summary = $this->summary($lineItems);

        return [
            'line_items' => $lineItems,
            'line_item_summary' => $summary,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function candidateLines(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $candidates = [];
        $buffer = null;

        foreach ($lines as $line) {
            $normalizedLine = $this->normalizeLine($line);

            if ($normalizedLine === '') {
                continue;
            }

            if ($this->startsWithDate($normalizedLine)) {
                if ($buffer !== null) {
                    $candidates[] = $buffer;
                }

                $buffer = $normalizedLine;

                if ($this->hasTrailingAmount($buffer)) {
                    $candidates[] = $buffer;
                    $buffer = null;
                }

                continue;
            }

            if ($buffer === null) {
                continue;
            }

            $buffer = trim($buffer.' '.$normalizedLine);

            if ($this->hasTrailingAmount($buffer)) {
                $candidates[] = $buffer;
                $buffer = null;
            }
        }

        if ($buffer !== null) {
            $candidates[] = $buffer;
        }

        return $candidates;
    }

    protected function normalizeLine(string $line): string
    {
        $normalized = str_replace("\t", ' ', trim($line));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    protected function startsWithDate(string $line): bool
    {
        return preg_match('/^\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/', $line) === 1;
    }

    protected function hasTrailingAmount(string $line): bool
    {
        return preg_match(
            '/\s-?\$?\(?\d[\d,]*\.\d{2}\)?(?:\s-?\$?\(?\d[\d,]*\.\d{2}\)?)?\s*$/',
            $line,
        ) === 1;
    }

    /**
     * @return array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }|null
     */
    protected function parseLine(string $line, ?int $statementYear = null): ?array
    {
        if ($this->shouldIgnoreLine($line)) {
            return null;
        }

        if (preg_match('/^(?<date>\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?)\s+(?<rest>.+)$/', $line, $matches) !== 1) {
            return null;
        }

        $transactionDate = $this->parseDate((string) $matches['date'], $statementYear);

        if (! $transactionDate instanceof Carbon) {
            return null;
        }

        if (preg_match(
            '/^(?<description>.+?)\s+(?<amount>-?\$?\(?\d[\d,]*\.\d{2}\)?)(?:\s+(?<balance>-?\$?\(?\d[\d,]*\.\d{2}\)?))?\s*$/',
            (string) $matches['rest'],
            $restMatches,
        ) !== 1) {
            return null;
        }

        $description = trim((string) ($restMatches['description'] ?? ''));

        if ($description === '' || $this->shouldIgnoreDescription($description)) {
            return null;
        }

        $rawAmountToken = (string) ($restMatches['amount'] ?? '');
        $amount = $this->parseMoneyValue($rawAmountToken);

        if ($amount === null || $amount === 0.0) {
            return null;
        }

        $balance = $this->parseMoneyValue((string) ($restMatches['balance'] ?? ''));
        $direction = $this->inferDirection($description, $rawAmountToken);
        $signedAmount = $this->signedAmount($amount, $direction, $rawAmountToken);

        return [
            'transaction_date' => $transactionDate->toDateString(),
            'description' => $description,
            'amount' => round(abs($amount), 2),
            'signed_amount' => $signedAmount,
            'direction' => $direction,
            'balance' => $balance !== null ? round($balance, 2) : null,
            'raw_line' => $line,
        ];
    }

    protected function shouldIgnoreLine(string $line): bool
    {
        $normalized = strtolower($line);

        return str_contains($normalized, 'opening balance')
            || str_contains($normalized, 'closing balance')
            || str_contains($normalized, 'ending balance')
            || str_contains($normalized, 'beginning balance')
            || str_contains($normalized, 'total deposits')
            || str_contains($normalized, 'total withdrawals')
            || str_contains($normalized, 'daily balance')
            || str_contains($normalized, 'average balance')
            || str_contains($normalized, 'interest paid')
            || str_contains($normalized, 'interest earned')
            || str_contains($normalized, 'account number')
            || str_contains($normalized, 'page ');
    }

    protected function shouldIgnoreDescription(string $description): bool
    {
        $normalized = strtolower($description);

        return str_contains($normalized, 'daily balance')
            || str_contains($normalized, 'balance brought forward')
            || str_contains($normalized, 'balance carried forward')
            || str_contains($normalized, 'subtotal');
    }

    protected function parseDate(string $value, ?int $statementYear = null): ?Carbon
    {
        $parts = preg_split('/[\/-]/', $value) ?: [];

        if (count($parts) < 2) {
            return null;
        }

        $month = (int) $parts[0];
        $day = (int) $parts[1];
        $year = $statementYear;

        if (isset($parts[2]) && is_numeric($parts[2])) {
            $parsedYear = (int) $parts[2];
            $year = $parsedYear < 100 ? 2000 + $parsedYear : $parsedYear;
        }

        if ($year === null || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        try {
            return Carbon::create($year, $month, $day)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseMoneyValue(string $value): ?float
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $isNegative = str_contains($trimmed, '(') || str_starts_with($trimmed, '-');
        $normalized = preg_replace('/[^0-9.]/', '', $trimmed);

        if (! is_string($normalized) || $normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        $amount = (float) $normalized;

        return $isNegative ? -$amount : $amount;
    }

    protected function inferDirection(string $description, string $rawAmountToken): string
    {
        if (str_contains($rawAmountToken, '(') || str_starts_with(trim($rawAmountToken), '-')) {
            return 'outflow';
        }

        $normalized = strtolower($description);

        foreach ([
            'deposit',
            'credit',
            'payment from',
            'payment received',
            'refund',
            'reversal',
            'wire in',
            'transfer from',
            'incoming',
            'ach credit',
            'zelle from',
            'venmo cashout',
        ] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'inflow';
            }
        }

        foreach ([
            'purchase',
            'debit',
            'withdrawal',
            'payment',
            'fee',
            'charge',
            'autopay',
            'bill pay',
            'check',
            'transfer to',
            'online transfer to',
            'card',
            'pos',
            'atm',
            'ach debit',
            'wire out',
        ] as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'outflow';
            }
        }

        return 'unknown';
    }

    protected function signedAmount(float $amount, string $direction, string $rawAmountToken): ?float
    {
        if (str_contains($rawAmountToken, '(') || str_starts_with(trim($rawAmountToken), '-')) {
            return round(-abs($amount), 2);
        }

        return match ($direction) {
            'inflow' => round(abs($amount), 2),
            'outflow' => round(-abs($amount), 2),
            default => null,
        };
    }

    /**
     * @param  array<int, array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }>  $lineItems
     * @return array<int, array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }>
     */
    protected function dedupe(array $lineItems): array
    {
        $unique = [];
        $seen = [];

        foreach ($lineItems as $lineItem) {
            $key = implode('|', [
                $lineItem['transaction_date'],
                strtolower($lineItem['description']),
                number_format((float) $lineItem['amount'], 2, '.', ''),
                $lineItem['direction'],
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $lineItem;
        }

        return $unique;
    }

    /**
     * @param  array<int, array{
     *     transaction_date: string,
     *     description: string,
     *     amount: float,
     *     signed_amount: float|null,
     *     direction: string,
     *     balance: float|null,
     *     raw_line: string,
     * }>  $lineItems
     * @return array{
     *     parser: string,
     *     parsed_count: int,
     *     inflow_count: int,
     *     outflow_count: int,
     *     unknown_direction_count: int,
     *     parsed_deposits: float,
     *     parsed_withdrawals: float,
     *     usable_for_matching: bool,
     * }
     */
    protected function summary(array $lineItems): array
    {
        $inflowCount = 0;
        $outflowCount = 0;
        $unknownDirectionCount = 0;
        $parsedDeposits = 0.0;
        $parsedWithdrawals = 0.0;

        foreach ($lineItems as $lineItem) {
            if ($lineItem['direction'] === 'inflow') {
                $inflowCount++;
                $parsedDeposits += (float) $lineItem['amount'];

                continue;
            }

            if ($lineItem['direction'] === 'outflow') {
                $outflowCount++;
                $parsedWithdrawals += (float) $lineItem['amount'];

                continue;
            }

            $unknownDirectionCount++;
        }

        return [
            'parser' => 'heuristic_v1',
            'parsed_count' => count($lineItems),
            'inflow_count' => $inflowCount,
            'outflow_count' => $outflowCount,
            'unknown_direction_count' => $unknownDirectionCount,
            'parsed_deposits' => round($parsedDeposits, 2),
            'parsed_withdrawals' => round($parsedWithdrawals, 2),
            'usable_for_matching' => count($lineItems) > 0,
        ];
    }
}
