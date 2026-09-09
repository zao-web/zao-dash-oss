<x-mail::message>
Hi {{ $contactName }},

{!! nl2br(e($body)) !!}

Best regards,<br>
{{ $senderName }}

<x-mail::subcopy>
This email was sent regarding {{ $clientName }}.
</x-mail::subcopy>
</x-mail::message>
