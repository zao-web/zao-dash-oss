<x-mail::message>
# Welcome to {{ $companyName }}

You've been invited to join our contractor portal. As a contractor, you'll be able to:

- Track your 1099 tax forms and documentation
- Submit time and work performed
- View compensation and project details
- Access important contractor resources

<x-mail::button :url="$resetUrl">
Set Up Your Account
</x-mail::button>

Please set up your account within 48 hours using the link above.

If you have any questions, please don't hesitate to reach out.

Thanks,<br>
{{ $companyName }}
</x-mail::message>
