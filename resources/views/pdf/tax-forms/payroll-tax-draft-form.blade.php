<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @page { size: letter; margin: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    </style>
</head>
<body class="bg-white text-slate-950">
    <main class="p-10">
        <header class="border-b border-slate-300 pb-6 mb-8">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Zao Dash Tax Office</p>
            <div class="mt-2 flex items-start justify-between gap-6">
                <div>
                    <h1 class="text-3xl font-bold">{{ $definition['inventory_label'] }}</h1>
                    <p class="mt-2 text-sm text-slate-600">
                        {{ $definition['official_form'] }} draft prepared for tax year {{ $profile->tax_year }} from the current salary posture and live tax projection.
                    </p>
                </div>
                <div class="rounded-lg border border-slate-300 p-4 text-right">
                    <p class="text-xs font-bold uppercase text-slate-500">Employer</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ $payload['employer_name'] }}</p>
                    <p class="mt-3 text-xs font-bold uppercase text-slate-500">Annual wages</p>
                    <p class="mt-1 text-3xl font-bold">${{ number_format((float) $payload['salary'], 2) }}</p>
                    <p class="mt-1 text-xs text-slate-500">Source: {{ $payload['salary_source'] }}</p>
                </div>
            </div>
        </header>

        <section class="mb-8 rounded-lg border border-blue-200 bg-blue-50 p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide text-blue-900">Draft filing posture</h2>
            <p class="mt-2 text-sm leading-6 text-blue-950">
                These payroll drafts assume the current reasonable-salary setting is the owner W-2 posture for the year.
                Federal and Oregon withholding values are recommended targets based on the live annual tax projection and any
                estimated payments already recorded.
            </p>
        </section>

        <section class="mb-8 rounded-lg border border-slate-200 p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Employer record</h2>
            <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Employer EIN</p>
                    <p class="mt-1 text-slate-900">{{ $payload['employer_ein_label'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-bold uppercase text-slate-500">Employer address</p>
                    <p class="mt-1 whitespace-pre-line text-slate-900">{{ $payload['employer_address'] }}</p>
                </div>
            </div>
        </section>

        <section class="mb-8">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 pb-3">
                <div>
                    <h2 class="text-lg font-bold">{{ $definition['official_form'] }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $definition['description'] }}</p>
                </div>
                <span class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-bold uppercase text-amber-700">
                    Draft review required
                </span>
            </div>

            <div class="mt-4 space-y-4">
                @foreach($sections as $section)
                    <article class="rounded-lg border border-slate-200 p-4">
                        <h3 class="text-sm font-bold uppercase tracking-wide text-slate-500">{{ $section['title'] }}</h3>
                        <table class="mt-3 w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 text-slate-500">
                                    <th class="py-2 pr-3 font-semibold">Prepared line</th>
                                    <th class="py-2 pr-3 font-semibold">Value</th>
                                    <th class="py-2 pr-3 font-semibold">Maps to</th>
                                    <th class="py-2 pr-3 font-semibold">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($section['fields'] as $field)
                                    <tr class="border-b border-slate-100">
                                        <td class="py-2 pr-3 font-semibold text-slate-800">{{ $field['label'] }}</td>
                                        <td class="py-2 pr-3 font-mono text-slate-800">{{ $field['value'] }}</td>
                                        <td class="py-2 pr-3 text-slate-600">{{ $field['line'] }}</td>
                                        <td class="py-2 pr-3 text-slate-600">{{ $field['note'] ?? 'Review before filing' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Annual payroll totals</h2>
            <div class="mt-3 grid grid-cols-2 gap-4 text-sm">
                <article class="rounded-lg border border-slate-200 p-3">
                    <p class="text-xs font-bold uppercase text-slate-500">Federal withholding target</p>
                    <p class="mt-1 font-mono text-slate-950">${{ number_format((float) $payload['federal_withholding_target'], 2) }}</p>
                </article>
                <article class="rounded-lg border border-slate-200 p-3">
                    <p class="text-xs font-bold uppercase text-slate-500">Oregon withholding target</p>
                    <p class="mt-1 font-mono text-slate-950">${{ number_format((float) $payload['oregon_withholding_target'], 2) }}</p>
                </article>
                <article class="rounded-lg border border-slate-200 p-3">
                    <p class="text-xs font-bold uppercase text-slate-500">Employer FICA</p>
                    <p class="mt-1 font-mono text-slate-950">${{ number_format((float) ($payload['employer_social_security_tax'] + $payload['employer_medicare_tax']), 2) }}</p>
                </article>
                <article class="rounded-lg border border-slate-200 p-3">
                    <p class="text-xs font-bold uppercase text-slate-500">Statewide transit tax</p>
                    <p class="mt-1 font-mono text-slate-950">${{ number_format((float) $payload['oregon_statewide_transit_tax'], 2) }}</p>
                </article>
            </div>
        </section>

        <footer class="mt-10 border-t border-slate-200 pt-4 text-xs text-slate-400">
            Generated {{ now()->format('F j, Y g:i A') }} from the current tax profile and annual projection.
        </footer>
    </main>
</body>
</html>
