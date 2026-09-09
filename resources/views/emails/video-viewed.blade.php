<x-mail::message>
# Someone's Watching

Your video **{{ $video->title }}** just got a new viewer.

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0;">
<tr>
<td width="50%" style="padding-right: 8px;">
<div style="background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); border-radius: 12px; padding: 24px; text-align: center;">
<span style="font-size: 32px; font-weight: 700; color: #4f46e5; display: block; line-height: 1.2;">{{ $video->view_count }}</span>
<span style="font-size: 11px; color: #6366f1; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Total Views</span>
</div>
</td>
<td width="50%" style="padding-left: 8px;">
<div style="background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-radius: 12px; padding: 24px; text-align: center;">
<span style="font-size: 32px; font-weight: 700; color: #16a34a; display: block; line-height: 1.2;">{{ $video->unique_view_count }}</span>
<span style="font-size: 11px; color: #22c55e; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 600;">Unique Viewers</span>
</div>
</td>
</tr>
</table>

## Viewer Details

<div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin: 20px 0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
@if($view->viewer_email)
<tr>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; width: 120px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Email</span>
</td>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">
<span style="font-size: 14px; color: #0f172a; font-weight: 500;">{{ $view->viewer_email }}</span>
</td>
</tr>
@endif
<tr>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; width: 120px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Device</span>
</td>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">
<span style="font-size: 14px; color: #334155;">{{ ucfirst($view->device_type ?? 'Unknown') }}</span>
</td>
</tr>
@if($view->country)
<tr>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0; width: 120px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Location</span>
</td>
<td style="padding: 14px 20px; border-bottom: 1px solid #e2e8f0;">
<span style="font-size: 14px; color: #334155;">{{ $view->country }}</span>
</td>
</tr>
@endif
<tr>
<td style="padding: 14px 20px; width: 120px;">
<span style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600;">Watched</span>
</td>
<td style="padding: 14px 20px;">
<span style="font-size: 14px; color: #334155;">{{ $view->started_at?->format('M j, Y \a\t g:i A') ?? 'Just now' }}</span>
</td>
</tr>
</table>
</div>

<x-mail::button :url="$videoUrl">
View Analytics
</x-mail::button>

<p style="text-align: center; color: #94a3b8; font-size: 13px; margin-top: 8px;">
Your content is making an impact.
</p>

Thanks,<br>
Team Zao
</x-mail::message>
