@php
    $fieldMap = collect($form['fields'] ?? [])->keyBy('key');
    $value = function (string $key, mixed $default = 0) use ($fieldMap) {
        return data_get($fieldMap->get($key), 'value', $default);
    };
    $money = fn (float $amount): string => '$'.number_format($amount, 2);
    $filingStatus = (string) ($profile?->filing_status ?? 'single');
    $federalAgi = (float) $value('federal_agi', 0);
    $oregonTaxableIncome = (float) $value('oregon_taxable_income', 0);
    $oregonIncomeTax = (float) $value('oregon_income_tax', 0);
    $priorYearCredit = (float) $value('prior_year_oregon_overpayment_credit', 0);
    $estimatedPayments = (float) $value('oregon_estimated_payments', 0);
    $withholding = 0.0;
    $totalPayments = round($withholding + $priorYearCredit + $estimatedPayments, 2);
    $refund = round(max($totalPayments - $oregonIncomeTax, 0), 2);
    $amountDue = round(max($oregonIncomeTax - $totalPayments, 0), 2);
    $itemizedDeductions = round(
        (float) ($profile?->mortgage_interest_paid ?? 0)
        + (float) ($profile?->property_tax_paid ?? 0)
        + (float) ($profile?->charitable_contributions_paid ?? 0)
        + (float) ($profile?->medical_expenses_paid ?? 0),
        2,
    );
    $standardDeduction = match ($filingStatus) {
        'mfj' => 5670.0,
        'hoh' => 4560.0,
        'mfs' => 2835.0,
        default => 2835.0,
    };
    $deductionUsed = round(max($itemizedDeductions, $standardDeduction), 2);
    $taxpayerName = $workpaper->user->name ?? 'Taxpayer name not stored';
    $spouseName = $profile?->spouse_name ?: 'Spouse name not stored';
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
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Oregon Department of Revenue</p>
                    <h1 class="mt-1 text-3xl font-bold">Form OR-40</h1>
                    <p class="text-sm font-medium">Oregon Individual Income Tax Return for Full-year Residents - Tax Year {{ $workpaper->tax_year }}</p>
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
            This is a line-mapped OR-40 draft built from the same workpaper packet as the federal return. It is a review copy and not a state e-file export.
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
            <h2 class="text-base font-bold">Resident return identity</h2>
            <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="font-semibold">Taxpayer</p>
                    <p>{{ $taxpayerName }}</p>
                    <p class="text-slate-600">SSN: {{ $profile?->ssn ?: 'Not stored' }}</p>
                </div>
                <div>
                    <p class="font-semibold">Spouse</p>
                    <p>{{ $filingStatus === 'mfj' ? $spouseName : 'N/A' }}</p>
                    <p class="text-slate-600">Spouse SSN: {{ $filingStatus === 'mfj' ? ($profile?->spouse_ssn ?: 'Not stored') : 'N/A' }}</p>
                </div>
                <div class="col-span-2">
                    <p class="font-semibold">Resident jurisdiction</p>
                    <p>{{ $profile?->city ?: $profile?->resident_city ?: 'City not stored' }}, {{ $profile?->state ?: $profile?->resident_state ?: 'OR' }} {{ $profile?->zip ?: '' }}</p>
                </div>
            </div>
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
            <h2 class="text-base font-bold">OR-40 line mapping</h2>
            <table class="line-table mt-3 w-full">
                <thead>
                    <tr>
                        <th class="w-16">Line</th>
                        <th>Description</th>
                        <th class="w-36 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="font-semibold">7</td>
                        <td>Federal adjusted gross income from Form 1040, line 11a</td>
                        <td class="text-right font-mono">{{ $money($federalAgi) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">16</td>
                        <td>Oregon itemized deductions</td>
                        <td class="text-right font-mono">{{ $money($itemizedDeductions) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">17</td>
                        <td>Standard deduction</td>
                        <td class="text-right font-mono">{{ $money($standardDeduction) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">18</td>
                        <td>Larger of line 16 or 17</td>
                        <td class="text-right font-mono">{{ $money($deductionUsed) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">19</td>
                        <td>Oregon taxable income</td>
                        <td class="text-right font-mono">{{ $money($oregonTaxableIncome) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">20</td>
                        <td>Tax</td>
                        <td class="text-right font-mono">{{ $money($oregonIncomeTax) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">31</td>
                        <td>Tax after standard and carryforward credits</td>
                        <td class="text-right font-mono">{{ $money($oregonIncomeTax) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">34</td>
                        <td>Prior-year refund applied as estimated payment</td>
                        <td class="text-right font-mono">{{ $money($priorYearCredit) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">35</td>
                        <td>Estimated tax payments for {{ $workpaper->tax_year }}</td>
                        <td class="text-right font-mono">{{ $money($estimatedPayments) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">40</td>
                        <td>Total payments and refundable credits</td>
                        <td class="text-right font-mono">{{ $money($totalPayments) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">41</td>
                        <td>Overpayment of tax</td>
                        <td class="text-right font-mono">{{ $money($refund) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">42</td>
                        <td>Net tax due</td>
                        <td class="text-right font-mono">{{ $money($amountDue) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
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
        </section>

        <footer class="mt-6 border-t border-slate-300 pt-3 text-[11px] text-slate-500">
            Generated {{ now()->format('F j, Y g:i A') }} from tax return workpaper #{{ $workpaper->id }}.
        </footer>
    </main>
</body>
</html>
