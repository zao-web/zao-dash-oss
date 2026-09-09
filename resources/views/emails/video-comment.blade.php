<x-mail::message>
# New Comment on Your Video

**{{ $comment->commenter_name }}** left feedback on **{{ $video->title }}**.

@if($comment->timestamp_formatted)
<div style="display: inline-block; background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); padding: 6px 14px; border-radius: 20px; margin-bottom: 16px;">
<span style="font-size: 13px; color: #4f46e5; font-weight: 600;">@ {{ $comment->timestamp_formatted }}</span>
</div>
@endif

<div style="background-color: #f8fafc; border-radius: 16px; padding: 28px 32px; margin: 20px 0; position: relative;">
<div style="font-size: 48px; color: #e2e8f0; position: absolute; top: 12px; left: 20px; font-family: Georgia, serif; line-height: 1;">"</div>
<p style="font-size: 16px; color: #334155; line-height: 1.7; margin: 0; padding-left: 24px; font-style: italic;">
{{ $comment->content }}
</p>
</div>

@if(!$comment->is_approved)
<div style="background-color: #fef3c7; border: 1px solid #fcd34d; border-radius: 10px; padding: 16px 20px; margin: 24px 0;">
<p style="color: #92400e; margin: 0; font-size: 14px; font-weight: 500;">
This comment is pending your approval before it's visible to others.
</p>
</div>
@endif

<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin: 24px 0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; width: 100px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">From</span>
</td>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">
<span style="font-size: 14px; color: #0f172a; font-weight: 500;">{{ $comment->commenter_name }}</span>
@if($comment->is_authenticated)
<span style="display: inline-block; background-color: #dcfce7; color: #16a34a; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; margin-left: 8px;">VERIFIED</span>
@endif
</td>
</tr>
@if($comment->viewer_email)
<tr>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; width: 100px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Email</span>
</td>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">
<span style="font-size: 14px; color: #334155;">{{ $comment->viewer_email }}</span>
</td>
</tr>
@endif
<tr>
<td style="padding: 14px 20px; width: 100px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Posted</span>
</td>
<td style="padding: 14px 20px;">
<span style="font-size: 14px; color: #334155;">{{ $comment->created_at->format('M j, Y \a\t g:i A') }}</span>
</td>
</tr>
</table>
</div>

@if(!$comment->is_approved)
<x-mail::button :url="$videoUrl" color="success">
Review & Approve
</x-mail::button>
@else
<x-mail::button :url="$videoUrl">
View Comment
</x-mail::button>
@endif

<p style="text-align: center; color: #94a3b8; font-size: 13px; margin-top: 8px;">
Keep the conversation going.
</p>

Thanks,<br>
Team Zao
</x-mail::message>
