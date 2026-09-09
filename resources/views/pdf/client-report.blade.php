{{--
    Beautiful Client Report PDF Template using Tailwind CSS

    Usage:
    TailwindPdf::view('pdf.client-report', [
        'report' => $report,
        'settings' => $settings,
    ])->primaryColor('#2563eb')->save('reports/client-123.pdf');
--}}

@php
    $primaryColor = $settings->primary_color ?? '#2563eb';
    $accentColor = $settings->accent_color ?? '#4f46e5';

    // Helper for semi-transparent primary color
    $primaryLight = $primaryColor . '15';
@endphp

{{-- Page 1: Overview --}}
<div class="w-[8.5in] min-h-[11in] bg-white p-10 break-after-page">
    {{-- Header --}}
    <div class="flex justify-between items-center mb-5 pb-4 border-b-2" style="border-color: {{ $primaryColor }}">
        <div class="flex items-center gap-3">
            @if($settings->logo_url)
                <img src="{{ $settings->logo_url }}" alt="" class="h-10 max-w-36 object-contain">
            @endif
            <span class="text-lg font-bold text-gray-900">{{ $report->client->name }}</span>
        </div>
        <div class="px-4 py-2 text-sm font-semibold text-white" style="background-color: {{ $primaryColor }}">
            {{ $report->period_label }}
        </div>
    </div>

    {{-- Hero Section --}}
    <div class="text-center py-10 mb-6 bg-gray-50 border-l-4" style="border-color: {{ $primaryColor }}">
        <h1 class="text-3xl font-bold mb-2" style="color: {{ $primaryColor }}">Your Month in Review</h1>
        <p class="text-gray-500">Here's what we accomplished together</p>
    </div>

    {{-- Executive Summary --}}
    @if($report->executive_summary)
    <div class="bg-gray-50 p-5 mb-6 border-l-4" style="border-color: {{ $accentColor }}">
        <div class="text-xs uppercase tracking-wider font-bold mb-3" style="color: {{ $primaryColor }}">Executive Summary</div>
        <p class="text-gray-700 leading-relaxed">{{ $report->executive_summary }}</p>
    </div>
    @endif

    {{-- Hero Metrics --}}
    @if($report->metrics && count($report->metrics) > 0)
    <div class="grid grid-cols-3 gap-1 mb-6">
        @foreach(array_slice($report->metrics, 0, 3) as $metric)
        <div class="bg-gray-50 py-6 px-4 text-center">
            <div class="text-4xl font-bold mb-1" style="color: {{ $primaryColor }}">{{ $metric['value'] ?? '—' }}</div>
            <div class="text-sm text-gray-600">{{ $metric['label'] ?? '' }}</div>
            @if(!empty($metric['context']))
            <div class="text-xs text-gray-400 mt-1">{{ $metric['context'] }}</div>
            @endif
        </div>
        @endforeach
        @for($i = count($report->metrics); $i < 3; $i++)
        <div class="bg-gray-50 py-6 px-4"></div>
        @endfor
    </div>
    @endif

    {{-- Key Achievements --}}
    @if($report->highlights && count($report->highlights) > 0)
    <div class="mb-6">
        <div class="text-xs uppercase tracking-wider font-bold mb-4" style="color: {{ $primaryColor }}">Key Achievements</div>
        <div class="space-y-3">
            @foreach(array_slice($report->highlights, 0, 4) as $highlight)
            <div class="bg-gray-50 p-4 flex gap-4 items-start">
                <div class="w-10 h-10 flex items-center justify-center text-xl shrink-0" style="background-color: {{ $primaryLight }}">
                    {{ $highlight['icon'] ?? '✓' }}
                </div>
                <div>
                    <div class="font-bold text-gray-900 mb-1">{{ $highlight['title'] ?? '' }}</div>
                    <div class="text-sm text-gray-600">{{ $highlight['description'] ?? '' }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

{{-- Page 2: Detailed Breakdown --}}
<div class="w-[8.5in] min-h-[11in] bg-white p-10">
    {{-- Header (repeated) --}}
    <div class="flex justify-between items-center mb-5 pb-4 border-b-2" style="border-color: {{ $primaryColor }}">
        <div class="flex items-center gap-3">
            @if($settings->logo_url)
                <img src="{{ $settings->logo_url }}" alt="" class="h-10 max-w-36 object-contain">
            @endif
            <span class="text-lg font-bold text-gray-900">{{ $report->client->name }}</span>
        </div>
        <div class="px-4 py-2 text-sm font-semibold text-white" style="background-color: {{ $primaryColor }}">
            {{ $report->period_label }}
        </div>
    </div>

    {{-- Time Breakdown --}}
    @if($settings->include_time_breakdown && $report->hours_by_category && count($report->hours_by_category) > 0)
    <div class="bg-gray-50 p-5 mb-6">
        <div class="flex justify-between items-center mb-4">
            <div class="text-xs uppercase tracking-wider font-bold" style="color: {{ $primaryColor }}">Time Investment</div>
            <div>
                <span class="text-3xl font-bold" style="color: {{ $primaryColor }}">{{ number_format($report->total_hours ?? 0, 1) }}</span>
                <span class="text-gray-600 ml-1">hours</span>
            </div>
        </div>

        @php
            $maxHours = collect($report->hours_by_category)->max('hours') ?: 1;
        @endphp

        <div class="space-y-2">
            @foreach(array_slice($report->hours_by_category, 0, 6) as $category)
            <div class="flex items-center gap-3 py-2 border-b border-gray-200">
                <div class="w-32 text-sm text-gray-700 truncate">{{ $category['name'] ?? 'Other' }}</div>
                <div class="flex-1 h-4 bg-gray-200">
                    <div class="h-full" style="background-color: {{ $primaryColor }}; width: {{ ($category['hours'] / $maxHours) * 100 }}%"></div>
                </div>
                <div class="w-16 text-right text-sm font-semibold text-gray-900">{{ number_format($category['hours'] ?? 0, 1) }}h</div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- GitHub Activity --}}
    @if($settings->include_github_activity && (($report->prs_merged ?? 0) > 0 || ($report->issues_closed ?? 0) > 0))
    <div class="mb-6">
        <div class="text-xs uppercase tracking-wider font-bold mb-4" style="color: {{ $primaryColor }}">Development Activity</div>

        <div class="grid grid-cols-2 gap-1 mb-3">
            <div class="bg-gray-50 py-6 text-center">
                <div class="text-4xl font-bold" style="color: {{ $primaryColor }}">{{ $report->prs_merged ?? 0 }}</div>
                <div class="text-sm text-gray-600 mt-1">Pull Requests Merged</div>
            </div>
            <div class="bg-gray-50 py-6 text-center">
                <div class="text-4xl font-bold" style="color: {{ $primaryColor }}">{{ $report->issues_closed ?? 0 }}</div>
                <div class="text-sm text-gray-600 mt-1">Issues Resolved</div>
            </div>
        </div>

        @if(($report->data_snapshot['github']['total_lines_added'] ?? 0) > 0)
        <div class="bg-gray-50 p-4 text-center">
            <span class="text-emerald-600 font-bold">+{{ number_format($report->data_snapshot['github']['total_lines_added']) }} lines added</span>
            <span class="mx-4 text-gray-300">|</span>
            <span class="text-red-600 font-bold">-{{ number_format($report->data_snapshot['github']['total_lines_removed'] ?? 0) }} lines removed</span>
        </div>
        @endif
    </div>
    @endif

    {{-- Tasks Completed --}}
    @if($settings->include_tasks_completed && ($report->tasks_completed ?? 0) > 0)
    <div class="mb-6">
        <div class="text-xs uppercase tracking-wider font-bold mb-4" style="color: {{ $primaryColor }}">Tasks Completed</div>
        <div class="bg-gray-50 py-8 text-center">
            <div class="text-4xl font-bold" style="color: {{ $primaryColor }}">{{ $report->tasks_completed }}</div>
            <div class="text-sm text-gray-600 mt-1">tasks delivered this period</div>
        </div>
    </div>
    @endif

    {{-- Meetings --}}
    @if(($report->meetings_held ?? 0) > 0)
    <div class="mb-6">
        <div class="text-xs uppercase tracking-wider font-bold mb-4" style="color: {{ $primaryColor }}">Collaboration</div>
        <div class="bg-gray-50 py-8 text-center">
            <div class="text-4xl font-bold" style="color: {{ $primaryColor }}">{{ $report->meetings_held }}</div>
            <div class="text-sm text-gray-600 mt-1">meetings & sync calls</div>
        </div>
    </div>
    @endif

    {{-- Footer --}}
    <div class="border-t border-gray-200 pt-6 mt-auto text-center">
        <p class="text-sm text-gray-400">Report generated on {{ now()->format('F j, Y') }}</p>
        <p class="text-gray-600 mt-1">Powered by <span class="font-medium">Zao</span></p>
    </div>
</div>
