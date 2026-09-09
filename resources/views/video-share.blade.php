<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $video->title ?? 'Video' }} - {{ config('app.name', 'Zao Dash') }}</title>

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="video.other">
    <meta property="og:title" content="{{ $video->title ?? 'Video' }}">
    <meta property="og:description" content="{{ $video->ai_summary ?? $video->description ?? 'Watch this video on ' . config('app.name') }}">
    <meta property="og:url" content="{{ $video->share_url }}">
    @if($video->thumbnail_url)
    <meta property="og:image" content="{{ url("/v/{$video->share_token}/thumbnail") }}">
    <meta property="og:image:width" content="{{ $video->width ?? 1280 }}">
    <meta property="og:image:height" content="{{ $video->height ?? 720 }}">
    @endif
    <meta property="og:video" content="{{ url("/v/{$video->share_token}/stream") }}">
    <meta property="og:video:type" content="{{ $video->mime_type ?? 'video/webm' }}">
    @if($video->width && $video->height)
    <meta property="og:video:width" content="{{ $video->width }}">
    <meta property="og:video:height" content="{{ $video->height }}">
    @endif
    <meta property="og:site_name" content="{{ config('app.name', 'Zao Dash') }}">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="player">
    <meta name="twitter:title" content="{{ $video->title ?? 'Video' }}">
    <meta name="twitter:description" content="{{ $video->ai_summary ?? $video->description ?? 'Watch this video' }}">
    @if($video->thumbnail_url)
    <meta name="twitter:image" content="{{ url("/v/{$video->share_token}/thumbnail") }}">
    @endif
    <meta name="twitter:player" content="{{ url("/v/{$video->share_token}/embed") }}">
    <meta name="twitter:player:width" content="{{ $video->width ?? 1280 }}">
    <meta name="twitter:player:height" content="{{ $video->height ?? 720 }}">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ $video->share_url }}">

    <script>
        (function() {
            var theme = localStorage.getItem('theme');
            if (theme === 'light') {
                document.documentElement.classList.add('light');
            }
        })();
    </script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
