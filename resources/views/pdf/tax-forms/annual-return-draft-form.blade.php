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
                        {{ $definition['official_form'] }} draft prepared from tax year {{ $workpaper->tax_year }} source-linked workpapers.
                    </p>
                </div>
                <div class="rounded-lg border border-slate-300 p-4 text-right">
                    <p class="text-xs font-bold uppercase text-slate-500">Packet hash</p>
                    <p class="mt-1 text-xs font-semibold text-slate-800">{{ $workpaper->packet_hash }}</p>
                    <p class="mt-3 text-xs font-bold uppercase text-slate-500">Readiness</p>
                    <p class="mt-1 text-3xl font-bold">{{ $form['readiness_percent'] }}%</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $form['mapped_field_count'] }} of {{ $form['total_field_count'] }} fields mapped</p>
                </div>
            </div>
        </header>

        <section class="mb-8 rounded-lg border border-blue-200 bg-blue-50 p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide text-blue-900">Prepared return posture</h2>
            <p class="mt-2 text-sm leading-6 text-blue-950">
                This draft is prepared from the current deterministic workpaper packet. It is intended to be
                reviewed, approved, and then checked against current IRS, Oregon, and local instructions before
                filing or payment submission.
            </p>
        </section>

        <section class="mb-8">
            <div class="flex items-start justify-between gap-3 border-b border-slate-200 pb-3">
                <div>
                    <h2 class="text-lg font-bold">{{ $form['title'] }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $definition['description'] }}</p>
                </div>
                <span class="rounded-full px-2 py-1 text-[10px] font-bold uppercase {{ $form['status'] === 'mapped' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                    {{ $form['status_label'] }}
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
                                    <th class="py-2 pr-3 font-semibold">Source-linked value</th>
                                    <th class="py-2 pr-3 font-semibold">Mapped to</th>
                                    <th class="py-2 pr-3 font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($section['fields'] as $field)
                                    <tr class="border-b border-slate-100">
                                        <td class="py-2 pr-3 font-semibold text-slate-800">{{ $field['label'] }}</td>
                                        <td class="py-2 pr-3 font-mono text-slate-800">{{ $field['display_value'] }}</td>
                                        <td class="py-2 pr-3 text-slate-600">{{ $field['form_line'] }}</td>
                                        <td class="py-2 pr-3 text-slate-600">{{ $field['status_label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-bold">Source Facts Feeding This Draft</h2>
            <div class="grid grid-cols-2 gap-3">
                @foreach(($packet['source_facts'] ?? []) as $fact)
                    <article class="rounded-lg border border-slate-200 p-3">
                        <p class="text-xs font-bold text-slate-800">{{ $fact['label'] }}</p>
                        <p class="mt-1 font-mono text-sm text-slate-950">{{ $fact['display_value'] }}</p>
                        <p class="mt-1 text-[11px] text-slate-500">{{ $fact['source'] }} - {{ $fact['status'] }} - {{ $fact['confidence'] }}%</p>
                    </article>
                @endforeach
            </div>
        </section>

        <footer class="mt-10 border-t border-slate-200 pt-4 text-xs text-slate-400">
            Generated {{ now()->format('F j, Y g:i A') }} from tax return workpaper #{{ $workpaper->id }}.
        </footer>
    </main>
</body>
</html>
