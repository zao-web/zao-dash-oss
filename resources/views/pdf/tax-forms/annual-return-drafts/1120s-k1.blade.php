@php
    $fieldMap = collect($form['fields'] ?? [])->keyBy('key');
    $value = function (string $key, mixed $default = 0) use ($fieldMap) {
        return data_get($fieldMap->get($key), 'value', $default);
    };
    $money = fn (float $amount): string => '$'.number_format($amount, 2);

    $grossReceipts = (float) $value('gross_receipts', 0);
    $businessExpenses = (float) $value('business_expenses', 0);
    $ordinaryBusinessIncome = (float) $value('ordinary_business_income', 0);
    $officerCompensation = (float) $value('officer_compensation', 0);
    $otherDeductions = round(max($businessExpenses - $officerCompensation, 0), 2);
    $shareholderDistributions = (float) $value('shareholder_distributions', 0);
    $qbiWageBasis = (float) $value('qbi_wage_basis', 0);
    $shareholderBasis = (float) $value('shareholder_basis_carryforward', 0);
    $entityName = $profile?->entity_name ?: config('app.name');
    $entityAddress = $profile?->entity_address ?: collect([$profile?->address, $profile?->city, $profile?->state, $profile?->zip])->filter()->implode(', ');
    $shareholderName = $workpaper->user->name ?? 'Shareholder name not stored';
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
                    <h1 class="mt-1 text-3xl font-bold">Form 1120-S / Schedule K-1</h1>
                    <p class="text-sm font-medium">U.S. Income Tax Return for an S Corporation - Tax Year {{ $workpaper->tax_year }}</p>
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
            This draft now follows the actual Form 1120-S and Schedule K-1 line structure. It is still a review copy and not an e-file submission package.
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
            <h2 class="text-base font-bold">Corporation information</h2>
            <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="font-semibold">Name</p>
                    <p>{{ $entityName }}</p>
                </div>
                <div>
                    <p class="font-semibold">Employer identification number</p>
                    <p>{{ $profile?->entity_ein ?: 'Not stored in tax profile' }}</p>
                </div>
                <div class="col-span-2">
                    <p class="font-semibold">Business address</p>
                    <p>{{ $entityAddress ?: 'Business address not stored' }}</p>
                </div>
            </div>
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
            <h2 class="text-base font-bold">Form 1120-S page 1 lines</h2>
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
                        <td class="font-semibold">1a</td>
                        <td>Gross receipts or sales</td>
                        <td class="text-right font-mono">{{ $money($grossReceipts) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">6</td>
                        <td>Total income (loss)</td>
                        <td class="text-right font-mono">{{ $money($grossReceipts) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">7</td>
                        <td>Compensation of officers</td>
                        <td class="text-right font-mono">{{ $money($officerCompensation) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">20</td>
                        <td>Other deductions</td>
                        <td class="text-right font-mono">{{ $money($otherDeductions) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">21</td>
                        <td>Total deductions</td>
                        <td class="text-right font-mono">{{ $money($businessExpenses) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">22</td>
                        <td>Ordinary business income (loss)</td>
                        <td class="text-right font-mono font-semibold">{{ $money($ordinaryBusinessIncome) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="mt-5 rounded border border-slate-300 p-4">
            <h2 class="text-base font-bold">Schedule K-1 draft for shareholder</h2>
            <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="font-semibold">Shareholder</p>
                    <p>{{ $shareholderName }}</p>
                </div>
                <div>
                    <p class="font-semibold">Shareholder identifying number</p>
                    <p>{{ $profile?->ssn ?: 'Not stored in tax profile' }}</p>
                </div>
            </div>
            <table class="line-table mt-3 w-full">
                <thead>
                    <tr>
                        <th class="w-24">Box / code</th>
                        <th>Description</th>
                        <th class="w-36 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="font-semibold">Box 1</td>
                        <td>Ordinary business income (loss)</td>
                        <td class="text-right font-mono">{{ $money($ordinaryBusinessIncome) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">Stmt A</td>
                        <td>Section 199A W-2 wage basis</td>
                        <td class="text-right font-mono">{{ $money($qbiWageBasis) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">Basis sch.</td>
                        <td>Ending shareholder basis carryforward</td>
                        <td class="text-right font-mono">{{ $money($shareholderBasis) }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold">Dist. sch.</td>
                        <td>Cash distributions reviewed outside box 1</td>
                        <td class="text-right font-mono">{{ $money($shareholderDistributions) }}</td>
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
