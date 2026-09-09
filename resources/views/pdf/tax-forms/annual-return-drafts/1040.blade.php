@php
    $fieldMap = collect($form['fields'] ?? [])->keyBy('key');
    $value = function (string $key, mixed $default = 0) use ($fieldMap) {
        return data_get($fieldMap->get($key), 'value', $default);
    };
    $display = function (string $key, string $default = 'Missing') use ($fieldMap) {
        return (string) data_get($fieldMap->get($key), 'display_value', $default);
    };
    $money = function (float $amount): string {
        return '$'.number_format($amount, 2);
    };

    $filingStatus = (string) ($value('filing_status', $profile?->filing_status) ?? 'single');
    $wages = (float) $value('wages', 0);
    $passThroughIncome = (float) $value('pass_through_income', 0);
    $totalIncome = round($wages + $passThroughIncome, 2);
    $agi = (float) $value('adjusted_gross_income', 0);
    $adjustments = round(max($totalIncome - $agi, 0), 2);
    $mortgageInterest = (float) $value('mortgage_interest', 0);
    $propertyTaxes = (float) $value('property_taxes', 0);
    $charitableContributions = (float) $value('charitable_contributions', 0);
    $medicalExpenses = (float) $value('medical_expenses', 0);
    $itemizedDeductions = round($mortgageInterest + $propertyTaxes + $charitableContributions + $medicalExpenses, 2);
    $qbiDeduction = (float) $value('qbi_deduction', 0);
    $totalDeductions = round($itemizedDeductions + $qbiDeduction, 2);
    $taxableIncome = (float) $value('taxable_income', 0);
    $federalTax = (float) $value('federal_income_tax', 0);
    $estimatedPayments = (float) $value('estimated_tax_payments', 0);
    $priorYearCredit = (float) $value('prior_year_federal_overpayment_credit', 0);
    $line26Payments = round($estimatedPayments + $priorYearCredit, 2);
    $totalPayments = $line26Payments;
    $refund = round(max($totalPayments - $federalTax, 0), 2);
    $amountOwed = round(max($federalTax - $totalPayments, 0), 2);
    $taxpayerName = $workpaper->user->name ?? 'Taxpayer name not stored';
    $spouseName = $profile?->spouse_name ?: 'Spouse name not stored';
    $addressParts = array_filter([$profile?->address, $profile?->city, $profile?->state, $profile?->zip]);
    $mailingAddress = $addressParts !== [] ? implode(', ', $addressParts) : 'Mailing address not stored';
    $identityGaps = collect([
        blank($profile?->ssn) ? 'Taxpayer SSN' : null,
        $filingStatus === 'mfj' && blank($profile?->spouse_ssn) ? 'Spouse SSN' : null,
        blank($profile?->address) ? 'Mailing address' : null,
    ])->filter()->values()->all();

    $lines = [
        ['line' => '1a', 'description' => 'Total amount from Form(s) W-2, box 1', 'amount' => $wages],
        ['line' => '1z', 'description' => 'Total wages', 'amount' => $wages],
        ['line' => '8', 'description' => 'Additional income from Schedule 1, line 10', 'amount' => $passThroughIncome],
        ['line' => '9', 'description' => 'Total income', 'amount' => $totalIncome],
        ['line' => '10', 'description' => 'Adjustments to income from Schedule 1, line 26', 'amount' => $adjustments],
        ['line' => '11a', 'description' => 'Adjusted gross income', 'amount' => $agi],
        ['line' => '12e', 'description' => 'Itemized deductions from Schedule A', 'amount' => $itemizedDeductions],
        ['line' => '13a', 'description' => 'Qualified business income deduction', 'amount' => $qbiDeduction],
        ['line' => '14', 'description' => 'Total deductions', 'amount' => $totalDeductions],
        ['line' => '15', 'description' => 'Taxable income', 'amount' => $taxableIncome],
        ['line' => '24', 'description' => 'Total tax', 'amount' => $federalTax],
        ['line' => '26', 'description' => '2025 estimated tax payments and amount applied from 2024 return', 'amount' => $line26Payments],
        ['line' => '33', 'description' => 'Total payments', 'amount' => $totalPayments],
        ['line' => '34', 'description' => 'Overpayment / refund', 'amount' => $refund],
        ['line' => '37', 'description' => 'Amount you owe', 'amount' => $amountOwed],
    ];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @page { size: letter; margin: 0.45in; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #0f172a; }
        .line-table th, .line-table td { border-bottom: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: top; }
        .line-table th { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; text-align: left; }
        .line-table td { font-size: 12px; }
    </style>
</head>
<body class="bg-white">
    <main class="mx-auto max-w-[7.2in]">
        <header class="border-b border-slate-300 pb-4">
            <div class="flex items-start justify-between gap-6">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Department of the Treasury - Internal Revenue Service</p>
                    <h1 class="mt-1 text-3xl font-bold">Form 1040</h1>
                    <p class="text-sm font-medium">U.S. Individual Income Tax Return - Tax Year {{ $workpaper->tax_year }}</p>
                </div>
                <div class="w-64 rounded border border-slate-300 p-3 text-right">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Draft posture</p>
                    <p class="mt-1 text-lg font-bold">{{ $form['readiness_percent'] }}%</p>
                    <p class="text-[11px] text-slate-500">{{ $form['mapped_field_count'] }} of {{ $form['total_field_count'] }} mapped</p>
                    <p class="mt-3 text-[10px] font-semibold uppercase tracking-wide text-slate-500">Packet hash</p>
                    <p class="mt-1 break-all text-[10px] font-medium text-slate-700">{{ $workpaper->packet_hash }}</p>
                </div>
            </div>
        </header>

        <section class="mt-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
            This is a line-mapped Form 1040 draft generated from the current workpaper packet. It is materially closer to the filed form than the old bridge packet, but it is still a review copy, not e-file output.
        </section>

        @if($identityGaps !== [])
            <section class="mt-3 rounded border border-rose-300 bg-rose-50 p-3 text-sm text-rose-950">
                Missing identity data on this draft: {{ implode(', ', $identityGaps) }}.
            </section>
        @endif

        <section class="mt-5 grid grid-cols-2 gap-4">
            <article class="rounded border border-slate-300 p-3">
                <h2 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Taxpayer</h2>
                <p class="mt-2 text-sm font-semibold">{{ $taxpayerName }}</p>
                <p class="text-sm text-slate-600">SSN: {{ $profile?->ssn ?: 'Not stored' }}</p>
                <p class="mt-2 text-sm text-slate-600">{{ $mailingAddress }}</p>
            </article>
            <article class="rounded border border-slate-300 p-3">
                <h2 class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Joint return / filing posture</h2>
                <p class="mt-2 text-sm font-semibold">{{ $filingStatus === 'mfj' ? $spouseName : 'No spouse on return' }}</p>
                <p class="text-sm text-slate-600">Spouse SSN: {{ $filingStatus === 'mfj' ? ($profile?->spouse_ssn ?: 'Not stored') : 'N/A' }}</p>
                <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
                    @foreach(['single' => 'Single', 'mfj' => 'Married filing jointly', 'mfs' => 'Married filing separately', 'hoh' => 'Head of household'] as $code => $label)
                        <div class="flex items-center gap-2 rounded border border-slate-200 px-2 py-1">
                            <span class="inline-flex h-4 w-4 items-center justify-center border border-slate-400 text-[10px] font-bold">{{ $filingStatus === $code ? 'X' : '' }}</span>
                            <span>{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
            <h2 class="text-base font-bold">Income, deductions, tax, and payments</h2>
            <table class="line-table mt-3 w-full">
                <thead>
                    <tr>
                        <th class="w-16">Line</th>
                        <th>Description</th>
                        <th class="w-36 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lines as $line)
                        <tr>
                            <td class="font-semibold text-slate-900">{{ $line['line'] }}</td>
                            <td>{{ $line['description'] }}</td>
                            <td class="text-right font-mono text-slate-900">{{ $money($line['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="mt-5 grid grid-cols-2 gap-4">
            <article class="rounded border border-slate-300 p-4">
                <h2 class="text-base font-bold">Schedule A / supporting deductions</h2>
                <table class="line-table mt-3 w-full">
                    <tbody>
                        <tr>
                            <td>Mortgage interest</td>
                            <td class="w-36 text-right font-mono">{{ $money($mortgageInterest) }}</td>
                        </tr>
                        <tr>
                            <td>Property taxes</td>
                            <td class="text-right font-mono">{{ $money($propertyTaxes) }}</td>
                        </tr>
                        <tr>
                            <td>Charitable contributions</td>
                            <td class="text-right font-mono">{{ $money($charitableContributions) }}</td>
                        </tr>
                        <tr>
                            <td>Medical expenses tracked</td>
                            <td class="text-right font-mono">{{ $money($medicalExpenses) }}</td>
                        </tr>
                        <tr>
                            <td class="font-semibold">Itemized deduction basis used</td>
                            <td class="text-right font-mono font-semibold">{{ $money($itemizedDeductions) }}</td>
                        </tr>
                    </tbody>
                </table>
            </article>

            <article class="rounded border border-slate-300 p-4">
                <h2 class="text-base font-bold">Source-linked fields</h2>
                <table class="line-table mt-3 w-full">
                    <tbody>
                        @foreach(($form['fields'] ?? []) as $field)
                            <tr>
                                <td class="font-semibold">{{ $field['label'] }}</td>
                                <td class="w-36 text-right font-mono">{{ $field['display_value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </article>
        </section>

        <footer class="mt-6 border-t border-slate-300 pt-3 text-[11px] text-slate-500">
            Generated {{ now()->format('F j, Y g:i A') }} from tax return workpaper #{{ $workpaper->id }}.
        </footer>
    </main>
</body>
</html>
