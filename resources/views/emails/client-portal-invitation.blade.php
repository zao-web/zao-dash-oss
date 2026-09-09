<x-mail::message>
# Welcome to the {{ $clientName }} Client Portal

{{ $inviterName }} has invited you to access the client portal where you can:

- View your projects and their progress
- Track tasks and milestones
- Access invoices and billing history
- Communicate with the team

<x-mail::button :url="$acceptUrl">
Accept Invitation
</x-mail::button>

This invitation expires on **{{ $expiresAt }}**.

If you have any questions, please reply to this email.

Thanks,<br>
Team Zao
</x-mail::message>
