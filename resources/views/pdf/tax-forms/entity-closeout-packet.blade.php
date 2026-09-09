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
                    <h1 class="text-3xl font-bold">Entity Closeout Package</h1>
                    <p class="mt-2 text-sm text-slate-600">Final-return and dissolution support packet for tax year {{ $filingYear }}.</p>
                </div>
                <div class="rounded-lg border border-slate-300 p-4 text-right">
                    <p class="text-xs font-bold uppercase text-slate-500">Entities</p>
                    <p class="mt-1 text-sm font-semibold text-slate-800">{{ implode(', ', $summary['entity_names']) ?: 'None' }}</p>
                    <p class="mt-3 text-xs font-bold uppercase text-slate-500">Status</p>
                    <p class="mt-1 text-3xl font-bold">{{ $summary['package_status_label'] }}</p>
                </div>
            </div>
        </header>

        <section class="mb-8 rounded-lg border border-amber-300 bg-amber-50 p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide text-amber-900">Owner signoff boundary</h2>
            <p class="mt-2 text-sm leading-6 text-amber-950">
                This packet organizes the final-return and dissolution support for entities leaving the filing year.
                Review the closing tasks, closure records, final payroll filings, and state shutdown steps before treating
                the entity as fully closed.
            </p>
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-bold">Closeout Scope</h2>
            <div class="grid grid-cols-2 gap-4 text-sm">
                <article class="rounded-lg border border-slate-200 p-4">
                    <p class="text-xs font-bold uppercase text-slate-500">Final-return entities</p>
                    <p class="mt-2 text-slate-900">{{ implode(', ', $summary['entity_names']) ?: 'None' }}</p>
                </article>
                <article class="rounded-lg border border-slate-200 p-4">
                    <p class="text-xs font-bold uppercase text-slate-500">Dissolution entities</p>
                    <p class="mt-2 text-slate-900">{{ implode(', ', $summary['dissolution_entities']) ?: 'None' }}</p>
                </article>
            </div>
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-bold">Closing Tasks</h2>
            <ul class="space-y-2 text-sm text-slate-800">
                @foreach($summary['tasks'] as $task)
                    <li class="rounded-lg border border-slate-200 px-4 py-3">{{ $task }}</li>
                @endforeach
            </ul>
        </section>

        <section class="mb-8">
            <h2 class="mb-3 text-lg font-bold">Closure Documents on File</h2>
            @if($summary['closure_documents'] === [])
                <article class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                    No closure documents are on file yet.
                </article>
            @else
                <div class="space-y-3">
                    @foreach($summary['closure_documents'] as $document)
                        <article class="rounded-lg border border-slate-200 px-4 py-3">
                            <p class="font-semibold text-slate-900">{{ $document['file_name'] }}</p>
                            <p class="mt-1 text-xs text-slate-500">Financial document #{{ $document['id'] }}</p>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        @if($summary['missing_document_requests'] !== [])
            <section class="mb-8">
                <h2 class="mb-3 text-lg font-bold">Still Missing</h2>
                <ul class="space-y-2 text-sm text-slate-800">
                    @foreach($summary['missing_document_requests'] as $request)
                        <li class="rounded-lg border border-red-200 bg-red-50 px-4 py-3">{{ $request }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="rounded-lg border border-slate-200 p-5">
            <h2 class="text-sm font-bold uppercase tracking-wide text-slate-500">Next action</h2>
            <p class="mt-2 text-sm leading-6 text-slate-900">{{ $summary['next_action'] }}</p>
        </section>

        <footer class="mt-10 border-t border-slate-200 pt-4 text-xs text-slate-400">
            Generated {{ now()->format('F j, Y g:i A') }} for tax year {{ $filingYear }}.
        </footer>
    </main>
</body>
</html>
