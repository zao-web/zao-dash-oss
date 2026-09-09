<x-mail::message>
# Invoice #{{ $invoice->number }}

Hi,

Please find attached your invoice from **{{ $companyName }}**.

<x-mail::panel>
| | |
|:--|--:|
| **Invoice Number** | {{ $invoice->number }} |
| **Issue Date** | {{ $invoice->issue_date->format('M j, Y') }} |
| **Due Date** | {{ $invoice->due_date->format('M j, Y') }} |
| **Amount Due** | **${{ number_format($invoice->amount_due, 2) }}** |
</x-mail::panel>

@if($invoice->subject)
**Re: {{ $invoice->subject }}**
@endif

@if($paymentLink)
<x-mail::button :url="$paymentLink" color="primary">
Pay Now with PayPal
</x-mail::button>
@endif

The full invoice PDF is attached to this email.

@isset($retainerReportUrl)
---

@if(isset($retainerReportPeriod) && $retainerReportPeriod)
**Retainer report for {{ \Carbon\Carbon::parse($retainerReportPeriod->period_start)->format('F Y') }}:**
@else
**Retainer report for the previous period:**
@endif
The detailed retainer report — including hours used, meetings, and a project breakdown — is attached and viewable online:

<x-mail::button :url="$retainerReportUrl" color="success">
View Retainer Report
</x-mail::button>
@endisset

@if($invoice->notes)
---

**Notes:**
{{ $invoice->notes }}
@endif

Thanks for your business!

{{ $companyName }}
</x-mail::message>
