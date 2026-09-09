<x-mail::message>
@if($isOverdue)
# Payment Overdue

Your payment for Invoice #{{ $invoice->number }} is now **{{ $daysOverdue }} days overdue**.
@elseif($reminder->type === \App\Models\InvoiceReminder::TYPE_ON_DUE)
# Payment Due Today

Invoice #{{ $invoice->number }} is due today.
@else
# Payment Reminder

Invoice #{{ $invoice->number }} is due in **{{ abs($reminder->days_offset) }} days**.
@endif

<x-mail::panel>
| | |
|:--|--:|
| **Invoice Number** | {{ $invoice->number }} |
| **Due Date** | {{ $invoice->due_date->format('M j, Y') }} |
| **Amount Due** | **${{ number_format($invoice->amount_due, 2) }}** |
</x-mail::panel>

@if($paymentLink)
<x-mail::button :url="$paymentLink" color="primary">
Pay Now
</x-mail::button>
@endif

@if($isOverdue)
Please remit payment at your earliest convenience to avoid any service interruptions.
@else
Please let us know if you have any questions about this invoice.
@endif

Thanks,

{{ $companyName }}
</x-mail::message>
