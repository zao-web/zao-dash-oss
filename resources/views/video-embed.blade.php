<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $video->title }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { width: 100%; height: 100%; overflow: hidden; background: #000; }
        video {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <video
        id="player"
        controls
        playsinline
        preload="metadata"
        @if($autoplay) autoplay @endif
        @if($muted) muted @endif
        @if($loop) loop @endif
        @if($video->thumbnail_url) poster="{{ url("/v/{$video->share_token}/thumbnail") }}" @endif
    >
        <source src="{{ url("/v/{$video->share_token}/stream") }}" type="{{ $video->mime_type ?? 'video/webm' }}">
        Your browser does not support the video tag.
    </video>

    <script>
        // Notify parent window of video events
        const video = document.getElementById('player');
        const postMessage = (type, data = {}) => {
            if (window.parent !== window) {
                window.parent.postMessage({ type, ...data }, '*');
            }
        };

        video.addEventListener('play', () => postMessage('play'));
        video.addEventListener('pause', () => postMessage('pause'));
        video.addEventListener('ended', () => postMessage('ended'));
        video.addEventListener('timeupdate', () => {
            postMessage('timeupdate', {
                currentTime: video.currentTime,
                duration: video.duration
            });
        });
    </script>
</body>
</html>
