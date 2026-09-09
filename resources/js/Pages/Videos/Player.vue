<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import TrimEditor from '@/Components/Videos/TrimEditor.vue';
import VersionHistory from '@/Components/Videos/VersionHistory.vue';

interface TranscriptSegment {
    start: number;
    end: number;
    text: string;
}

interface Comment {
    id: number;
    content: string;
    timestamp_seconds: number | null;
    timestamp_formatted: string | null;
    commenter_name: string;
    created_at_human: string;
    replies?: Comment[];
}

interface Video {
    uuid: string;
    title: string;
    description: string | null;
    duration: number | null;
    width: number | null;
    height: number | null;
    status: 'processing' | 'ready' | 'failed';
    thumbnail_url: string | null;
    stream_url: string;
    download_url: string;
    owner: string;
    created_at: string;
    formatted_recorded_at?: string;
    relative_time?: string;
    absolute_time?: string;
    has_transcript?: boolean;
    transcript_status?: string | null;
    transcript?: string | null;
    transcript_segments?: TranscriptSegment[] | null;
    ai_summary?: string | null;
    has_versions?: boolean;
}

interface Viewer {
    id: number;
    viewer_ip: string | null;
    viewer_email: string | null;
    device_type: string | null;
    country: string | null;
    region: string | null;
    city: string | null;
    watch_duration: number | null;
    watch_percentage: number | null;
    completed: boolean;
    referrer: string | null;
    started_at: string | null;
    started_at_human: string | null;
    user_agent: string | null;
}

const props = defineProps<{
    video: Video;
    is_owner?: boolean;
}>();

const videoRef = ref<HTMLVideoElement | null>(null);
const viewId = ref<number | null>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const duration = ref(0); // Actual duration from video element
const volume = ref(1);
const isMuted = ref(false);
const isFullscreen = ref(false);
const showControls = ref(true);
const controlsTimeout = ref<number | null>(null);

// Sidebar state
const activeTab = ref<'transcript' | 'comments' | 'viewers' | 'versions'>('transcript');
const sidebarOpen = ref(false);
const showTrimEditor = ref(false);
const versionHistoryRef = ref<InstanceType<typeof VersionHistory> | null>(null);

// Viewers state (owner only)
const viewers = ref<Viewer[]>([]);
const loadingViewers = ref(false);

// Comments state
const comments = ref<Comment[]>([]);
const commentMarkers = ref<{id: number; seconds: number; formatted: string; preview: string}[]>([]);
const loadingComments = ref(false);
const newComment = ref('');
const commenterName = ref('');
const includeTimestamp = ref(false);
const submittingComment = ref(false);

// Duration parsed from file header (fallback when server and browser both fail)
const parsedDuration = ref(0);

// Use video element duration, falling back to server-provided, then parsed from header
const effectiveDuration = computed(() => {
    const elDuration = duration.value;
    if (elDuration && isFinite(elDuration) && elDuration > 0) {
        return elDuration;
    }
    if (props.video.duration && props.video.duration > 0) {
        return props.video.duration;
    }
    return parsedDuration.value || 0;
});

const progress = computed(() => {
    // During scrubbing, use the scrub position for instant visual feedback
    if (scrubPercent.value !== null) return scrubPercent.value;
    if (!effectiveDuration.value) return 0;
    return (currentTime.value / effectiveDuration.value) * 100;
});

// Check if video can be trimmed (has valid duration)
const canTrim = computed(() => {
    return props.video.status === 'ready' && effectiveDuration.value > 0;
});

// Find active transcript segment
const activeSegmentIndex = computed(() => {
    if (!props.video.transcript_segments) return -1;
    return props.video.transcript_segments.findIndex(
        seg => currentTime.value >= seg.start && currentTime.value < seg.end
    );
});

/**
 * Probe video duration from WebM file when server/browser can't provide it.
 * Strategy: first check the header for a Duration element, then fall back to
 * scanning the last 64KB for the final Cluster timestamp (works for Chrome
 * MediaRecorder files that lack a Duration element).
 */
const probeWebmDuration = async () => {
    try {
        // First, get file size via HEAD request
        const headResp = await fetch(props.video.stream_url, { method: 'HEAD' });
        const fileSize = parseInt(headResp.headers.get('content-length') || '0', 10);
        if (!fileSize) return;

        // Read the last 64KB to find the last Cluster timestamp
        const tailSize = Math.min(65536, fileSize);
        const tailStart = fileSize - tailSize;
        const tailResp = await fetch(props.video.stream_url, {
            headers: { 'Range': `bytes=${tailStart}-${fileSize - 1}` },
        });
        if (!tailResp.ok && tailResp.status !== 206) return;

        const buffer = await tailResp.arrayBuffer();
        const view = new DataView(buffer);
        const len = buffer.byteLength;

        // Scan for Cluster headers (0x1F 0x43 0xB6 0x75) and read their Timestamps
        let lastTimestampMs = 0;
        for (let i = 0; i < len - 10; i++) {
            if (view.getUint8(i) === 0x1F &&
                view.getUint8(i + 1) === 0x43 &&
                view.getUint8(i + 2) === 0xB6 &&
                view.getUint8(i + 3) === 0x75) {
                // Skip Cluster ID (4 bytes) and size (variable)
                let pos = i + 4;
                const first = view.getUint8(pos);
                let width = 0;
                let mask = 0x80;
                while (width < 8 && !(first & mask)) { width++; mask >>= 1; }
                width++;
                pos += width;

                // Look for Timestamp element (0xE7) immediately after
                if (pos < len - 5 && view.getUint8(pos) === 0xE7) {
                    pos++;
                    const tFirst = view.getUint8(pos);
                    let tWidth = 0;
                    let tMask = 0x80;
                    while (tWidth < 8 && !(tFirst & tMask)) { tWidth++; tMask >>= 1; }
                    tWidth++;
                    let tSize = tFirst & (tMask - 1);
                    for (let j = 1; j < tWidth; j++) {
                        tSize = tSize * 256 + view.getUint8(pos + j);
                    }
                    pos += tWidth;

                    let ts = 0;
                    for (let j = 0; j < tSize; j++) {
                        ts = ts * 256 + view.getUint8(pos + j);
                    }
                    if (ts > lastTimestampMs) {
                        lastTimestampMs = ts;
                    }
                }
            }
        }

        if (lastTimestampMs > 0) {
            // Cluster timestamps are in milliseconds (TimecodeScale default)
            const seconds = lastTimestampMs / 1000;
            if (seconds > 0 && seconds < 86400) {
                parsedDuration.value = seconds;
            }
        }
    } catch (e) {
        // Silently fail — duration will show as --:--
    }
};

const formatTime = (seconds: number) => {
    // Handle null, NaN, Infinity
    if (seconds === null || seconds === undefined || !isFinite(seconds) || isNaN(seconds)) {
        return '--:--';
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

// Fetch comments and markers
const fetchComments = async () => {
    loadingComments.value = true;
    try {
        const shareToken = window.location.pathname.split('/').pop();
        const [commentsRes, markersRes] = await Promise.all([
            fetch(`/v/${shareToken}/comments`),
            fetch(`/v/${shareToken}/comments/markers`),
        ]);
        const commentsData = await commentsRes.json();
        const markersData = await markersRes.json();
        comments.value = commentsData.comments || [];
        commentMarkers.value = markersData.markers || [];
    } catch (e) {
        console.error('Failed to fetch comments:', e);
    } finally {
        loadingComments.value = false;
    }
};

// Fetch viewers (owner only)
const fetchViewers = async () => {
    if (!props.is_owner) return;
    loadingViewers.value = true;
    try {
        const response = await fetch(`/api/videos/${props.video.uuid}/views`, {
            credentials: 'include',
        });
        if (response.ok) {
            const data = await response.json();
            viewers.value = data.views || [];
        }
    } catch (e) {
        console.error('Failed to fetch viewers:', e);
    } finally {
        loadingViewers.value = false;
    }
};

// Format watch duration
const formatWatchDuration = (seconds: number | null): string => {
    if (!seconds) return '0s';
    if (seconds < 60) return `${seconds}s`;
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}m ${secs}s`;
};

// Get device icon
const getDeviceIcon = (deviceType: string | null): string => {
    switch (deviceType?.toLowerCase()) {
        case 'mobile': return '📱';
        case 'tablet': return '📱';
        case 'desktop': return '💻';
        default: return '🖥️';
    }
};

// Safe hostname extraction from referrer
const getReferrerHostname = (referrer: string | null): string => {
    if (!referrer) return '';
    try {
        return new URL(referrer).hostname;
    } catch {
        return referrer;
    }
};

// Format location from city, region, country
const formatLocation = (viewer: Viewer): string => {
    const parts = [];
    if (viewer.city) parts.push(viewer.city);
    if (viewer.region) parts.push(viewer.region);
    if (viewer.country && !viewer.city && !viewer.region) {
        // Only show country if we don't have more specific data
        parts.push(viewer.country);
    }
    return parts.join(', ') || 'Unknown location';
};

// Submit comment
const submitComment = async () => {
    if (!newComment.value.trim() || !commenterName.value.trim()) return;
    submittingComment.value = true;
    try {
        const shareToken = window.location.pathname.split('/').pop();
        const payload: any = {
            content: newComment.value,
            viewer_name: commenterName.value,
        };
        if (includeTimestamp.value && videoRef.value) {
            payload.timestamp_seconds = Math.floor(videoRef.value.currentTime);
        }
        const res = await fetch(`/v/${shareToken}/comments`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        if (res.ok) {
            newComment.value = '';
            includeTimestamp.value = false;
            alert('Comment submitted for approval!');
        }
    } catch (e) {
        console.error('Failed to submit comment:', e);
    } finally {
        submittingComment.value = false;
    }
};

// Jump to timestamp
const jumpToTime = (seconds: number) => {
    if (videoRef.value) {
        videoRef.value.currentTime = seconds;
        videoRef.value.play();
    }
};

// Record view on mount
onMounted(async () => {
    try {
        const shareToken = window.location.pathname.split('/').pop();
        const response = await fetch(`/v/${shareToken}/view`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                fingerprint: await generateFingerprint(),
            }),
        });
        const data = await response.json();
        viewId.value = data.view_id;
        startPingInterval();
    } catch (e) {
        console.error('Failed to record view:', e);
    }

    // Probe duration from file header if not provided by server
    if (!props.video.duration) {
        probeWebmDuration();
    }

    // Fetch comments
    fetchComments();

    // Fetch viewers if owner
    if (props.is_owner) {
        fetchViewers();
    }

    // Auto-open sidebar
    if (props.is_owner) {
        // Owners see viewers tab first
        sidebarOpen.value = true;
        activeTab.value = 'viewers';
    } else if (props.video.has_transcript) {
        sidebarOpen.value = true;
        activeTab.value = 'transcript';
    }

    // Handle timestamp deep linking (?t=123)
    const urlParams = new URLSearchParams(window.location.search);
    const timestampParam = urlParams.get('t');
    if (timestampParam) {
        const seconds = parseInt(timestampParam, 10);
        if (!isNaN(seconds) && seconds >= 0) {
            // Wait for video metadata to load, then seek
            if (videoRef.value) {
                videoRef.value.addEventListener('loadedmetadata', () => {
                    jumpToTime(seconds);
                }, { once: true });
                // If already loaded, seek immediately
                if (videoRef.value.readyState >= 1) {
                    jumpToTime(seconds);
                }
            }
        }
    }
});

let pingInterval: number | null = null;

const startPingInterval = () => {
    pingInterval = window.setInterval(async () => {
        if (!viewId.value || !videoRef.value) return;
        const shareToken = window.location.pathname.split('/').pop();
        try {
            await fetch(`/v/${shareToken}/ping`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    view_id: viewId.value,
                    watch_duration: Math.floor(videoRef.value.currentTime),
                }),
            });
        } catch (e) {
            console.error('Failed to ping view:', e);
        }
    }, 5000);
};

onUnmounted(() => {
    if (pingInterval) {
        clearInterval(pingInterval);
    }
    document.removeEventListener('mousemove', onScrubMove);
    document.removeEventListener('mouseup', onScrubEnd);
    document.removeEventListener('touchmove', onTouchScrubMove);
    document.removeEventListener('touchend', onTouchScrubEnd);
});

const generateFingerprint = async () => {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    if (ctx) {
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillText('fingerprint', 2, 2);
    }
    return canvas.toDataURL().slice(-32);
};

const togglePlay = () => {
    if (!videoRef.value) return;
    if (isPlaying.value) {
        videoRef.value.pause();
    } else {
        videoRef.value.play();
    }
};

const onTimeUpdate = () => {
    if (videoRef.value) {
        currentTime.value = videoRef.value.currentTime;
    }
};

const onPlay = () => isPlaying.value = true;
const onPause = () => isPlaying.value = false;

const updateDuration = () => {
    if (videoRef.value) {
        const d = videoRef.value.duration;
        if (d && isFinite(d) && d > 0) {
            duration.value = d;
        }
    }
};

const onLoadedMetadata = () => updateDuration();
const onDurationChange = () => updateDuration();

// Scrubbing / seeking
const isScrubbing = ref(false);
const scrubPercent = ref<number | null>(null);
const progressContainerRef = ref<HTMLElement | null>(null);

const seekToPosition = (clientX: number) => {
    if (!videoRef.value || !effectiveDuration.value || !progressContainerRef.value) return;
    const rect = progressContainerRef.value.getBoundingClientRect();
    const percent = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));

    // Update visual progress immediately during scrub
    scrubPercent.value = percent * 100;

    const targetTime = percent * effectiveDuration.value;
    videoRef.value.currentTime = targetTime;
    // Also update currentTime ref immediately for responsive UI
    currentTime.value = targetTime;
};

const seek = (e: MouseEvent) => {
    seekToPosition(e.clientX);
};

const onScrubStart = (e: MouseEvent) => {
    e.preventDefault();
    isScrubbing.value = true;
    seekToPosition(e.clientX);
    document.addEventListener('mousemove', onScrubMove);
    document.addEventListener('mouseup', onScrubEnd);
};

const onScrubMove = (e: MouseEvent) => {
    if (!isScrubbing.value) return;
    seekToPosition(e.clientX);
};

const onScrubEnd = () => {
    isScrubbing.value = false;
    scrubPercent.value = null;
    document.removeEventListener('mousemove', onScrubMove);
    document.removeEventListener('mouseup', onScrubEnd);
    document.removeEventListener('touchmove', onTouchScrubMove);
    document.removeEventListener('touchend', onTouchScrubEnd);
};

// Touch support for mobile
const onTouchScrubStart = (e: TouchEvent) => {
    if (e.touches.length !== 1) return;
    isScrubbing.value = true;
    seekToPosition(e.touches[0].clientX);
    document.addEventListener('touchmove', onTouchScrubMove, { passive: false });
    document.addEventListener('touchend', onTouchScrubEnd);
};

const onTouchScrubMove = (e: TouchEvent) => {
    e.preventDefault();
    if (!isScrubbing.value || !e.touches.length) return;
    seekToPosition(e.touches[0].clientX);
};

const onTouchScrubEnd = () => {
    onScrubEnd();
};

const toggleMute = () => {
    if (!videoRef.value) return;
    isMuted.value = !isMuted.value;
    videoRef.value.muted = isMuted.value;
};

const setVolume = (e: Event) => {
    if (!videoRef.value) return;
    const target = e.target as HTMLInputElement;
    volume.value = parseFloat(target.value);
    videoRef.value.volume = volume.value;
    isMuted.value = volume.value === 0;
};

const toggleFullscreen = async () => {
    const container = document.querySelector('.video-container') as HTMLElement;
    if (!container) return;
    if (!document.fullscreenElement) {
        await container.requestFullscreen();
        isFullscreen.value = true;
    } else {
        await document.exitFullscreen();
        isFullscreen.value = false;
    }
};

const showControlsTemporarily = () => {
    showControls.value = true;
    if (controlsTimeout.value) {
        clearTimeout(controlsTimeout.value);
    }
    controlsTimeout.value = window.setTimeout(() => {
        if (isPlaying.value) {
            showControls.value = false;
        }
    }, 3000);
};

const copyLink = async () => {
    // Copy clean URL without timestamp
    const url = new URL(window.location.href);
    url.searchParams.delete('t');
    await navigator.clipboard.writeText(url.toString());
    alert('Link copied!');
};

const copyLinkAtTimestamp = async () => {
    const url = new URL(window.location.href);
    url.searchParams.set('t', Math.floor(currentTime.value).toString());
    await navigator.clipboard.writeText(url.toString());
    alert(`Link copied at ${formatTime(currentTime.value)}!`);
};

const toggleSidebar = () => {
    sidebarOpen.value = !sidebarOpen.value;
};

// Handle trim completion
const onTrimmed = (version: any) => {
    showTrimEditor.value = false;
    // Refresh version history
    if (versionHistoryRef.value) {
        versionHistoryRef.value.refresh();
    }
    // Show versions tab
    activeTab.value = 'versions';
    sidebarOpen.value = true;
    alert('Video trim queued! You\'ll be notified when it\'s ready.');
};

// Handle version change
const onVersionChanged = () => {
    // Reload the page to get the new video version
    window.location.reload();
};
</script>

<template>
    <div class="player-page" :class="{ 'sidebar-open': sidebarOpen }">
        <!-- Processing Banner -->
        <div v-if="video.status === 'processing'" class="processing-banner">
            <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span>Video is processing... It will be ready shortly.</span>
        </div>

        <div class="main-content">
            <div class="video-area">
                <div class="video-container" @mousemove="showControlsTemporarily">
                    <video
                        ref="videoRef"
                        :src="video.stream_url"
                        :poster="video.thumbnail_url || undefined"
                        preload="metadata"
                        @loadedmetadata="onLoadedMetadata"
                        @durationchange="onDurationChange"
                        @timeupdate="onTimeUpdate"
                        @play="onPlay"
                        @pause="onPause"
                        @click="togglePlay"
                        class="video-element"
                    ></video>

                    <!-- Center Play Button -->
                    <div v-if="!isPlaying" class="center-play" @click="togglePlay">
                        <svg class="h-20 w-20" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </div>

                    <!-- Controls -->
                    <div class="video-controls" :class="{ visible: showControls || !isPlaying }">
                        <!-- Progress Bar with Comment Markers -->
                        <div
                            ref="progressContainerRef"
                            class="progress-container"
                            :class="{ 'is-scrubbing': isScrubbing }"
                            @mousedown="onScrubStart"
                            @touchstart.prevent="onTouchScrubStart"
                        >
                            <div class="progress-track">
                                <div
                                    class="progress-fill"
                                    :class="{ 'no-transition': isScrubbing }"
                                    :style="{ width: progress + '%' }"
                                >
                                    <div class="progress-thumb"></div>
                                </div>
                                <!-- Comment markers on timeline -->
                                <div
                                    v-for="marker in commentMarkers"
                                    :key="marker.id"
                                    class="timeline-marker"
                                    :style="{ left: ((marker.seconds / (effectiveDuration || 1)) * 100) + '%' }"
                                    :title="marker.preview"
                                    @click.stop="jumpToTime(marker.seconds)"
                                ></div>
                            </div>
                        </div>

                        <div class="controls-row">
                            <button class="control-btn" @click="togglePlay">
                                <svg v-if="isPlaying" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
                                </svg>
                                <svg v-else class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                            </button>

                            <div class="volume-controls">
                                <button class="control-btn" @click="toggleMute">
                                    <svg v-if="isMuted || volume === 0" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                                    </svg>
                                    <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>
                                    </svg>
                                </button>
                                <input
                                    type="range"
                                    min="0"
                                    max="1"
                                    step="0.1"
                                    :value="isMuted ? 0 : volume"
                                    @input="setVolume"
                                    class="volume-slider"
                                />
                            </div>

                            <div class="time-display">
                                {{ formatTime(currentTime) }} / {{ formatTime(effectiveDuration) }}
                            </div>

                            <div class="flex-1"></div>

                            <!-- Sidebar Toggle -->
                            <button class="control-btn" @click="toggleSidebar" title="Toggle sidebar">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>

                            <button class="control-btn" @click="toggleFullscreen">
                                <svg v-if="!isFullscreen" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                                </svg>
                                <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Video Info -->
                <div class="video-info-section">
                    <div class="video-info-content">
                        <h1 class="video-page-title">{{ video.title }}</h1>

                        <div class="video-page-meta">
                            <span>Shared by {{ video.owner }}</span>
                            <span>·</span>
                            <span :title="video.absolute_time">{{ video.formatted_recorded_at || formatDate(video.created_at) }}</span>
                        </div>

                        <p v-if="video.ai_summary" class="video-summary">
                            {{ video.ai_summary }}
                        </p>

                        <p v-if="video.description" class="video-description">
                            {{ video.description }}
                        </p>

                        <div v-if="video.transcript_status" class="transcript-status">
                            <template v-if="video.transcript_status === 'processing'">
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>Generating transcript...</span>
                            </template>
                            <template v-else-if="video.has_transcript">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Transcript available</span>
                            </template>
                        </div>

                        <div class="share-buttons">
                            <button class="share-btn" @click="copyLink">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                </svg>
                                Copy Link
                            </button>
                            <button class="share-btn share-btn-secondary" @click="copyLinkAtTimestamp">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Copy at {{ formatTime(currentTime) }}
                            </button>
                            <a :href="video.download_url" class="share-btn share-btn-secondary" download>
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                                </svg>
                                Download
                            </a>
                            <button v-if="is_owner && canTrim" class="share-btn share-btn-secondary" @click="showTrimEditor = true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.121 14.121L19 19m-7-7l7-7m-7 7l-2.879 2.879M12 12L9.121 9.121m0 5.758a3 3 0 10-4.243 4.243 3 3 0 004.243-4.243zm0-5.758a3 3 0 10-4.243-4.243 3 3 0 004.243 4.243z" />
                                </svg>
                                Trim Video
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div v-if="sidebarOpen" class="sidebar">
                <!-- Tabs -->
                <div class="sidebar-tabs">
                    <button
                        v-if="is_owner"
                        class="sidebar-tab"
                        :class="{ active: activeTab === 'viewers' }"
                        @click="activeTab = 'viewers'"
                    >
                        Viewers ({{ viewers.length }})
                    </button>
                    <button
                        class="sidebar-tab"
                        :class="{ active: activeTab === 'transcript' }"
                        @click="activeTab = 'transcript'"
                        :disabled="!video.has_transcript"
                    >
                        Transcript
                    </button>
                    <button
                        class="sidebar-tab"
                        :class="{ active: activeTab === 'comments' }"
                        @click="activeTab = 'comments'"
                    >
                        Comments ({{ comments.length }})
                    </button>
                    <button
                        v-if="is_owner && video.has_versions"
                        class="sidebar-tab"
                        :class="{ active: activeTab === 'versions' }"
                        @click="activeTab = 'versions'"
                    >
                        Versions
                    </button>
                </div>

                <!-- Viewers Panel (Owner Only) -->
                <div v-if="activeTab === 'viewers' && is_owner" class="sidebar-content">
                    <div v-if="loadingViewers" class="sidebar-empty">
                        <svg class="animate-spin h-6 w-6 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p>Loading viewers...</p>
                    </div>
                    <div v-else-if="viewers.length === 0" class="sidebar-empty">
                        <p>No viewers yet</p>
                        <p class="text-xs mt-1">Views will appear here when someone watches this video</p>
                    </div>
                    <div v-else class="viewers-list">
                        <div v-for="viewer in viewers" :key="viewer.id" class="viewer-item">
                            <div class="viewer-header">
                                <span class="viewer-device">{{ getDeviceIcon(viewer.device_type) }}</span>
                                <span class="viewer-location">{{ formatLocation(viewer) }}</span>
                            </div>
                            <div class="viewer-meta">
                                <span class="viewer-ip">{{ viewer.viewer_ip || 'Unknown IP' }}</span>
                                <span v-if="viewer.viewer_email" class="viewer-email">· {{ viewer.viewer_email }}</span>
                            </div>
                            <div class="viewer-meta">
                                <span class="viewer-time">{{ viewer.started_at_human }}</span>
                            </div>
                            <div class="viewer-stats">
                                <span class="viewer-stat">
                                    <span class="stat-label">Watched:</span>
                                    <span class="stat-value">{{ formatWatchDuration(viewer.watch_duration) }}</span>
                                </span>
                                <span class="viewer-stat">
                                    <span class="stat-label">Progress:</span>
                                    <span class="stat-value">{{ Math.round(viewer.watch_percentage || 0) }}%</span>
                                </span>
                                <span v-if="viewer.completed" class="viewer-completed">✓ Completed</span>
                            </div>
                            <div v-if="viewer.referrer" class="viewer-referrer" :title="viewer.referrer">
                                via {{ getReferrerHostname(viewer.referrer) }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transcript Panel -->
                <div v-if="activeTab === 'transcript'" class="sidebar-content">
                    <div v-if="video.transcript_status === 'processing'" class="sidebar-empty">
                        <svg class="animate-spin h-6 w-6 mx-auto mb-2" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        <p>Generating transcript...</p>
                    </div>
                    <div v-else-if="video.transcript_segments?.length" class="transcript-segments">
                        <div
                            v-for="(segment, index) in video.transcript_segments"
                            :key="index"
                            class="transcript-segment"
                            :class="{ active: index === activeSegmentIndex }"
                            @click="jumpToTime(segment.start)"
                        >
                            <span class="segment-time">{{ formatTime(segment.start) }}</span>
                            <span class="segment-text">{{ segment.text }}</span>
                        </div>
                    </div>
                    <div v-else-if="video.transcript" class="transcript-plain">
                        {{ video.transcript }}
                    </div>
                    <div v-else class="sidebar-empty">
                        <p>No transcript available</p>
                    </div>
                </div>

                <!-- Comments Panel -->
                <div v-if="activeTab === 'comments'" class="sidebar-content">
                    <!-- Comment Form -->
                    <div class="comment-form">
                        <input
                            v-model="commenterName"
                            type="text"
                            placeholder="Your name"
                            class="comment-input"
                        />
                        <textarea
                            v-model="newComment"
                            placeholder="Add a comment..."
                            rows="3"
                            class="comment-textarea"
                        ></textarea>
                        <div class="comment-actions">
                            <label class="timestamp-checkbox">
                                <input type="checkbox" v-model="includeTimestamp" />
                                Add timestamp ({{ formatTime(currentTime) }})
                            </label>
                            <button
                                @click="submitComment"
                                :disabled="!newComment.trim() || !commenterName.trim() || submittingComment"
                                class="comment-submit"
                            >
                                {{ submittingComment ? 'Submitting...' : 'Submit' }}
                            </button>
                        </div>
                    </div>

                    <!-- Comments List -->
                    <div v-if="loadingComments" class="sidebar-empty">
                        <p>Loading comments...</p>
                    </div>
                    <div v-else-if="comments.length === 0" class="sidebar-empty">
                        <p>No comments yet. Be the first!</p>
                    </div>
                    <div v-else class="comments-list">
                        <div v-for="comment in comments" :key="comment.id" class="comment-item">
                            <div class="comment-header">
                                <span class="comment-author">{{ comment.commenter_name }}</span>
                                <span
                                    v-if="comment.timestamp_formatted"
                                    class="comment-timestamp"
                                    @click="jumpToTime(comment.timestamp_seconds!)"
                                >
                                    {{ comment.timestamp_formatted }}
                                </span>
                            </div>
                            <p class="comment-body">{{ comment.content }}</p>
                            <span class="comment-date">{{ comment.created_at_human }}</span>

                            <!-- Replies -->
                            <div v-if="comment.replies?.length" class="comment-replies">
                                <div v-for="reply in comment.replies" :key="reply.id" class="comment-reply">
                                    <span class="comment-author">{{ reply.commenter_name }}</span>
                                    <p class="comment-body">{{ reply.content }}</p>
                                    <span class="comment-date">{{ reply.created_at_human }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Versions Panel (Owner Only) -->
                <div v-if="activeTab === 'versions' && is_owner" class="sidebar-content">
                    <VersionHistory
                        ref="versionHistoryRef"
                        :video-uuid="video.uuid"
                        @version-changed="onVersionChanged"
                    />
                </div>
            </div>
        </div>

        <!-- Trim Editor Modal -->
        <div v-if="showTrimEditor && is_owner" class="trim-modal-overlay" @click.self="showTrimEditor = false">
            <TrimEditor
                :video-uuid="video.uuid"
                :stream-url="video.stream_url"
                :duration="duration || video.duration || 0"
                @close="showTrimEditor = false"
                @trimmed="onTrimmed"
            />
        </div>

        <!-- Footer -->
        <div class="player-footer">
            <span>Powered by Zao</span>
        </div>
    </div>
</template>

<style scoped>
.player-page {
    min-height: 100vh;
    background: #000;
    display: flex;
    flex-direction: column;
}

.processing-banner {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    padding: 0.75rem 1rem;
    background: linear-gradient(90deg, #3b82f6, #6366f1);
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

.main-content {
    display: flex;
    flex: 1;
}

.video-area {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.video-container {
    position: relative;
    width: 100%;
    max-height: 70vh;
    aspect-ratio: 16/9;
    background: #000;
    margin: 0 auto;
}

.sidebar-open .video-container {
    max-height: 60vh;
}

.video-element {
    width: 100%;
    height: 100%;
    object-fit: contain;
    cursor: pointer;
}

.center-play {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    background: rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: background 0.2s;
}

.center-play:hover {
    background: rgba(0, 0, 0, 0.5);
}

.video-controls {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
    padding: 1rem;
    opacity: 0;
    transition: opacity 0.2s;
}

.video-controls.visible {
    opacity: 1;
}

.progress-container {
    cursor: pointer;
    padding: 0.75rem 0;
    user-select: none;
    -webkit-user-select: none;
    touch-action: none;
}

.progress-track {
    position: relative;
    height: 4px;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    overflow: visible;
    transition: height 0.1s ease;
}

.progress-fill {
    height: 100%;
    background: #fff;
    border-radius: 2px;
    position: relative;
    overflow: visible;
}

.progress-fill.no-transition {
    transition: none;
}

.progress-thumb {
    position: absolute;
    right: -7px;
    top: 50%;
    transform: translateY(-50%);
    width: 14px;
    height: 14px;
    background: #fff;
    border-radius: 50%;
    opacity: 0;
    transition: opacity 0.15s, transform 0.15s;
    z-index: 3;
    box-shadow: 0 0 4px rgba(0, 0, 0, 0.5);
    pointer-events: none;
}

.progress-container:hover .progress-thumb,
.progress-container.is-scrubbing .progress-thumb {
    opacity: 1;
    transform: translateY(-50%) scale(1.1);
}

.progress-container.is-scrubbing .progress-fill {
    transition: none;
}

.timeline-marker {
    position: absolute;
    top: -2px;
    width: 8px;
    height: 8px;
    background: #6366f1;
    border-radius: 50%;
    transform: translateX(-50%);
    cursor: pointer;
    z-index: 2;
}

.timeline-marker:hover {
    transform: translateX(-50%) scale(1.3);
}

.progress-container:hover .progress-track,
.progress-container.is-scrubbing .progress-track {
    height: 6px;
}

.controls-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 0.5rem;
}

.control-btn {
    color: white;
    padding: 0.25rem;
    opacity: 0.9;
    transition: opacity 0.15s;
}

.control-btn:hover {
    opacity: 1;
}

.volume-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.volume-slider {
    width: 80px;
    height: 4px;
    -webkit-appearance: none;
    background: rgba(255, 255, 255, 0.3);
    border-radius: 2px;
    cursor: pointer;
}

.volume-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    background: white;
    border-radius: 50%;
}

.time-display {
    color: white;
    font-size: 0.875rem;
    font-family: monospace;
}

.video-info-section {
    background: #111;
    padding: 2rem 0;
    flex: 1;
}

.video-info-content {
    max-width: 900px;
    margin: 0 auto;
    padding: 0 1.5rem;
}

.video-page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: white;
    margin-bottom: 0.5rem;
}

.video-page-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #888;
    font-size: 0.875rem;
    margin-bottom: 1rem;
}

.video-summary {
    color: #9ca3af;
    font-size: 0.9375rem;
    line-height: 1.5;
    margin-bottom: 0.75rem;
    padding: 0.75rem 1rem;
    background: rgba(99, 102, 241, 0.1);
    border-left: 3px solid #6366f1;
    border-radius: 0 6px 6px 0;
}

.video-description {
    color: #ccc;
    line-height: 1.6;
    margin-bottom: 1.5rem;
}

.transcript-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #9ca3af;
    font-size: 0.8125rem;
    margin-bottom: 1rem;
}

.transcript-status svg {
    color: #4ade80;
}

.share-buttons {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.share-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: #222;
    color: white;
    border: 1px solid #333;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: background 0.15s;
}

.share-btn:hover {
    background: #333;
}

.share-btn-secondary {
    background: transparent;
    border-color: #444;
    color: #aaa;
}

.share-btn-secondary:hover {
    background: #222;
    color: white;
}

/* Sidebar */
.sidebar {
    width: 360px;
    background: #111;
    border-left: 1px solid #222;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.sidebar-tabs {
    display: flex;
    border-bottom: 1px solid #222;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.sidebar-tabs::-webkit-scrollbar {
    display: none;
}

.sidebar-tab {
    flex: 0 0 auto;
    padding: 0.75rem 0.75rem;
    color: #888;
    font-size: 0.8125rem;
    font-weight: 500;
    background: transparent;
    border: none;
    cursor: pointer;
    transition: color 0.15s, background 0.15s;
    white-space: nowrap;
}

.sidebar-tab:hover:not(:disabled) {
    color: #ccc;
}

.sidebar-tab.active {
    color: white;
    background: rgba(99, 102, 241, 0.1);
    border-bottom: 2px solid #6366f1;
}

.sidebar-tab:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.sidebar-content {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.sidebar-empty {
    text-align: center;
    color: #666;
    padding: 2rem;
    font-size: 0.875rem;
}

/* Transcript */
.transcript-segments {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.transcript-segment {
    display: flex;
    gap: 0.75rem;
    padding: 0.5rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
}

.transcript-segment:hover {
    background: rgba(255, 255, 255, 0.05);
}

.transcript-segment.active {
    background: rgba(99, 102, 241, 0.15);
    border-left: 2px solid #6366f1;
}

.segment-time {
    color: #6366f1;
    font-size: 0.75rem;
    font-family: monospace;
    white-space: nowrap;
}

.segment-text {
    color: #ccc;
    font-size: 0.875rem;
    line-height: 1.4;
}

.transcript-plain {
    color: #ccc;
    font-size: 0.875rem;
    line-height: 1.6;
    white-space: pre-wrap;
}

/* Comments */
.comment-form {
    margin-bottom: 1.5rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid #222;
}

.comment-input,
.comment-textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 6px;
    color: white;
    font-size: 0.875rem;
    margin-bottom: 0.5rem;
}

.comment-input:focus,
.comment-textarea:focus {
    outline: none;
    border-color: #6366f1;
}

.comment-textarea {
    resize: vertical;
    min-height: 80px;
}

.comment-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.timestamp-checkbox {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: #888;
    font-size: 0.8125rem;
    cursor: pointer;
}

.timestamp-checkbox input {
    accent-color: #6366f1;
}

.comment-submit {
    padding: 0.5rem 1rem;
    background: #6366f1;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.15s;
}

.comment-submit:hover:not(:disabled) {
    background: #4f46e5;
}

.comment-submit:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.comments-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.comment-item {
    padding-bottom: 1rem;
    border-bottom: 1px solid #222;
}

.comment-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.comment-author {
    color: white;
    font-weight: 500;
    font-size: 0.875rem;
}

.comment-timestamp {
    padding: 0.125rem 0.375rem;
    background: rgba(99, 102, 241, 0.2);
    color: #6366f1;
    font-size: 0.75rem;
    font-family: monospace;
    border-radius: 4px;
    cursor: pointer;
}

.comment-timestamp:hover {
    background: rgba(99, 102, 241, 0.3);
}

.comment-body {
    color: #ccc;
    font-size: 0.875rem;
    line-height: 1.5;
    margin: 0.25rem 0;
}

.comment-date {
    color: #666;
    font-size: 0.75rem;
}

.comment-replies {
    margin-top: 0.75rem;
    padding-left: 1rem;
    border-left: 2px solid #333;
}

.comment-reply {
    padding: 0.5rem 0;
}

/* Viewers Panel */
.viewers-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.viewer-item {
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 8px;
    border: 1px solid #222;
}

.viewer-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.25rem;
}

.viewer-device {
    font-size: 1rem;
}

.viewer-location {
    color: white;
    font-size: 0.875rem;
    font-weight: 500;
}

.viewer-ip {
    color: #888;
    font-size: 0.75rem;
    font-family: monospace;
}

.viewer-meta {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.viewer-email {
    color: #6366f1;
    font-size: 0.8125rem;
}

.viewer-time {
    color: #888;
    font-size: 0.75rem;
}

.viewer-stats {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.viewer-stat {
    display: flex;
    gap: 0.25rem;
    font-size: 0.8125rem;
}

.stat-label {
    color: #666;
}

.stat-value {
    color: #ccc;
    font-family: monospace;
}

.viewer-completed {
    color: #4ade80;
    font-size: 0.75rem;
    font-weight: 500;
}

.viewer-referrer {
    margin-top: 0.5rem;
    color: #666;
    font-size: 0.75rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.player-footer {
    padding: 1rem;
    text-align: center;
    color: #555;
    font-size: 0.75rem;
    background: #0a0a0a;
}

@media (max-width: 768px) {
    .main-content {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        max-height: 50vh;
        border-left: none;
        border-top: 1px solid #222;
    }
}

/* Trim Modal Overlay */
.trim-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 2rem;
    overflow-y: auto;
}
</style>
