<x-mail::message>
# Welcome to {{ $companyName }}!

Hi {{ $user->name }},

You've been added to our team! As a team member, you'll have access to:

- Internal projects and tasks
- Client information and communications
- Team collaboration tools
- Time tracking and reporting

<x-mail::button :url="$resetUrl">
Set Up Your Account
</x-mail::button>

Please set up your account within 48 hours using the link above.

If you have any questions about getting started, feel free to ask!

Thanks,<br>
{{ $companyName }}
</x-mail::message>
