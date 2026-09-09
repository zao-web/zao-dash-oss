<x-mail::message>
{!! nl2br(e($body)) !!}

<x-mail::panel>
| | |
|:--|--:|
| **Proposal** | {{ $proposal->title }} |
| **Organization** | {{ $opportunity->issuing_organization }} |
@if($proposal->total_price)
| **Proposed Investment** | **${{ number_format($proposal->total_price, 0) }}** |
@endif
</x-mail::panel>

The full proposal is attached as a PDF.

{{ $companyName }}
</x-mail::message>
