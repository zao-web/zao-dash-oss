<x-mail::message>
# Invoice #{{ $invoice->number }} Has Been Updated

Hi,

We've made changes to your invoice from **{{ $companyName }}**. Please review the updated details below.

<x-mail::panel>
| | |
|:--|--:|
| **Invoice Number** | {{ $invoice->number }} |
| **Issue Date** | {{ $invoice->issue_date->format('M j, Y') }} |
| **Due Date** | {{ $invoice->due_date->format('M j, Y') }} |
| **Updated Amount Due** | **${{ number_format($invoice->amount_due, 2) }}** |
</x-mail::panel>

@if($invoice->subject)
**Re: {{ $invoice->subject }}**
@endif

@if($updateSummary)
**What changed:**
{{ $updateSummary }}
@endif

<x-mail::button :url="$publicUrl" color="primary">
View Updated Invoice
</x-mail::button>

@if($paymentLink && $invoice->amount_due > 0)
<x-mail::button :url="$paymentLink" color="success">
Pay Now with PayPal
</x-mail::button>
@endif

The updated invoice PDF is attached to this email.

@if($invoice->notes)
---

**Notes:**
{{ $invoice->notes }}
@endif

If you have any questions about these changes, please don't hesitate to reach out.

Thanks for your business!

{{ $companyName }}
</x-mail::message>
