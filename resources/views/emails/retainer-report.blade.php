<x-mail::message>
# Retainer Report — {{ \Carbon\Carbon::parse($period->period_start)->format('F Y') }}

Hi,

Attached is your retainer report for **{{ $client->name ?? 'this period' }}**, covering {{ \Carbon\Carbon::parse($period->period_start)->format('M j') }} – {{ \Carbon\Carbon::parse($period->period_end)->format('M j, Y') }}.

The report includes hours used, project activity (commits, PRs, issues, meetings), and a breakdown of work delivered during the period.

<x-mail::button :url="$reportUrl" color="primary">
View Report Online
</x-mail::button>

You can also find the report attached as a PDF.

Thanks,

{{ $companyName }}
</x-mail::message>
