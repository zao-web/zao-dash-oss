<x-mail::message>
# Your {{ $report->period_label }} Report

Hi there,

Your monthly progress report for **{{ $client->name }}** is ready! Here's a quick summary of what we accomplished together:

<x-mail::panel>
{{ $report->executive_summary }}
</x-mail::panel>

## Quick Stats

| Metric | Value |
|:-------|------:|
| Hours Invested | {{ number_format($report->total_hours, 1) }} |
| Tasks Completed | {{ $report->tasks_completed }} |
| PRs Merged | {{ $report->prs_merged }} |
| Issues Resolved | {{ $report->issues_closed }} |

@if($report->highlights && count($report->highlights) > 0)
## Key Achievements

@foreach($report->highlights as $highlight)
- **{{ $highlight['title'] }}**: {{ $highlight['description'] }}
@endforeach
@endif

The full PDF report is attached to this email for your records.

Thanks,<br>
Team Zao
</x-mail::message>
