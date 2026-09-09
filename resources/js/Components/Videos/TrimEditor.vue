<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

interface Thumbnail {
    index: number;
    time: number;
    data: string;
}

const props = defineProps<{
    videoUuid: string;
    streamUrl: string;
    duration: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'trimmed', version: any): void;
}>();

// State
const videoRef = ref<HTMLVideoElement | null>(null);
const thumbnails = ref<Thumbnail[]>([]);
const loadingThumbnails = ref(true);
const trimStart = ref(0);
const trimEnd = ref(props.duration);
const isPlaying = ref(false);
const currentTime = ref(0);
const isDragging = ref<'start' | 'end' | null>(null);
const isSaving = ref(false);
const error = ref<string | null>(null);

// Computed
const trimDuration = computed(() => trimEnd.value - trimStart.value);

const formattedTrimStart = computed(() => formatTime(trimStart.value));
const formattedTrimEnd = computed(() => formatTime(trimEnd.value));
const formattedTrimDuration = computed(() => formatTime(trimDuration.value));

const startHandlePosition = computed(() => {
    if (!props.duration) return 0;
    return (trimStart.value / props.duration) * 100;
});

const endHandlePosition = computed(() => {
    if (!props.duration) return 100;
    return (trimEnd.value / props.duration) * 100;
});

const selectedWidth = computed(() => endHandlePosition.value - startHandlePosition.value);

// Format seconds to MM:SS
function formatTime(seconds: number): string {
    if (seconds === null || seconds === undefined || !isFinite(seconds) || isNaN(seconds)) {
        return '--:--';
    }
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// Fetch thumbnails
async function fetchThumbnails() {
    loadingThumbnails.value = true;
    error.value = null;
    try {
        const response = await fetch(`/api/videos/${props.videoUuid}/thumbnails`, {
            credentials: 'include',
        });
        if (!response.ok) throw new Error('Failed to load thumbnails');
        const data = await response.json();
        thumbnails.value = data.thumbnails || [];
    } catch (e: any) {
        console.error('Failed to fetch thumbnails:', e);
        error.value = e.message;
    } finally {
        loadingThumbnails.value = false;
    }
}

// Video controls
function togglePlay() {
    if (!videoRef.value) return;
    if (isPlaying.value) {
        videoRef.value.pause();
    } else {
        // If at end of trim range, restart from trim start
        if (currentTime.value >= trimEnd.value) {
            videoRef.value.currentTime = trimStart.value;
        }
        videoRef.value.play();
    }
}

function onTimeUpdate() {
    if (!videoRef.value) return;
    currentTime.value = videoRef.value.currentTime;
    // Stop at trim end
    if (currentTime.value >= trimEnd.value && isPlaying.value) {
        videoRef.value.pause();
        videoRef.value.currentTime = trimEnd.value;
    }
}

function onPlay() { isPlaying.value = true; }
function onPause() { isPlaying.value = false; }

function jumpToStart() {
    if (videoRef.value) {
        videoRef.value.currentTime = trimStart.value;
        currentTime.value = trimStart.value;
    }
}

function jumpToEnd() {
    if (videoRef.value) {
        videoRef.value.currentTime = trimEnd.value;
        currentTime.value = trimEnd.value;
    }
}

// Preview the trim range
function previewTrim() {
    if (videoRef.value) {
        videoRef.value.currentTime = trimStart.value;
        videoRef.value.play();
    }
}

// Dragging handles
function startDrag(handle: 'start' | 'end', event: MouseEvent) {
    isDragging.value = handle;
    event.preventDefault();
}

function onMouseMove(event: MouseEvent) {
    if (!isDragging.value) return;

    const timeline = document.querySelector('.trim-timeline') as HTMLElement;
    if (!timeline) return;

    const rect = timeline.getBoundingClientRect();
    const percent = Math.max(0, Math.min(100, ((event.clientX - rect.left) / rect.width) * 100));
    const seconds = (percent / 100) * props.duration;

    if (isDragging.value === 'start') {
        trimStart.value = Math.max(0, Math.min(seconds, trimEnd.value - 1));
    } else {
        trimEnd.value = Math.min(props.duration, Math.max(seconds, trimStart.value + 1));
    }
}

function onMouseUp() {
    isDragging.value = null;
}

// Fine-tune controls
function adjustStart(delta: number) {
    const newStart = Math.max(0, Math.min(trimStart.value + delta, trimEnd.value - 1));
    trimStart.value = newStart;
    if (videoRef.value) {
        videoRef.value.currentTime = newStart;
    }
}

function adjustEnd(delta: number) {
    const newEnd = Math.max(trimStart.value + 1, Math.min(trimEnd.value + delta, props.duration));
    trimEnd.value = newEnd;
    if (videoRef.value) {
        videoRef.value.currentTime = newEnd;
    }
}

// Manual time input
function onStartTimeChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const parts = input.value.split(':');
    if (parts.length === 2) {
        const mins = parseInt(parts[0]) || 0;
        const secs = parseInt(parts[1]) || 0;
        const newStart = mins * 60 + secs;
        if (newStart >= 0 && newStart < trimEnd.value) {
            trimStart.value = newStart;
        }
    }
}

function onEndTimeChange(event: Event) {
    const input = event.target as HTMLInputElement;
    const parts = input.value.split(':');
    if (parts.length === 2) {
        const mins = parseInt(parts[0]) || 0;
        const secs = parseInt(parts[1]) || 0;
        const newEnd = mins * 60 + secs;
        if (newEnd > trimStart.value && newEnd <= props.duration) {
            trimEnd.value = newEnd;
        }
    }
}

// Save trim
async function saveTrim() {
    if (isSaving.value) return;

    isSaving.value = true;
    error.value = null;

    try {
        const response = await fetch(`/api/videos/${props.videoUuid}/trim`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                start_seconds: trimStart.value,
                end_seconds: trimEnd.value,
            }),
        });

        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.error || 'Failed to save trim');
        }

        const data = await response.json();
        emit('trimmed', data.version);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        isSaving.value = false;
    }
}

// Lifecycle
onMounted(() => {
    fetchThumbnails();
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
});

onUnmounted(() => {
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
});

// Sync video position when trim points change
watch(trimStart, (val) => {
    if (videoRef.value && currentTime.value < val) {
        videoRef.value.currentTime = val;
    }
});
</script>

<template>
    <div class="trim-editor">
        <div class="trim-header">
            <h3>Trim Video</h3>
            <button class="close-btn" @click="emit('close')">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <!-- Video Preview -->
        <div class="video-preview">
            <video
                ref="videoRef"
                :src="streamUrl"
                @timeupdate="onTimeUpdate"
                @play="onPlay"
                @pause="onPause"
                @click="togglePlay"
            ></video>
            <div v-if="!isPlaying" class="play-overlay" @click="togglePlay">
                <svg class="h-16 w-16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M8 5v14l11-7z" />
                </svg>
            </div>
        </div>

        <!-- Timeline -->
        <div class="trim-timeline-container">
            <div class="trim-timeline" :class="{ dragging: isDragging }">
                <!-- Thumbnails -->
                <div class="thumbnails">
                    <div
                        v-for="thumb in thumbnails"
                        :key="thumb.index"
                        class="thumbnail"
                        :style="{ backgroundImage: `url(${thumb.data})` }"
                    ></div>
                    <div v-if="loadingThumbnails" class="thumbnails-loading">
                        Loading thumbnails...
                    </div>
                </div>

                <!-- Trim overlay -->
                <div class="trim-overlay">
                    <!-- Dimmed areas -->
                    <div class="dim-left" :style="{ width: startHandlePosition + '%' }"></div>
                    <div class="dim-right" :style="{ width: (100 - endHandlePosition) + '%' }"></div>

                    <!-- Selected area -->
                    <div
                        class="selected-area"
                        :style="{ left: startHandlePosition + '%', width: selectedWidth + '%' }"
                    ></div>

                    <!-- Handles -->
                    <div
                        class="handle handle-start"
                        :style="{ left: startHandlePosition + '%' }"
                        @mousedown="startDrag('start', $event)"
                    >
                        <div class="handle-bar"></div>
                    </div>
                    <div
                        class="handle handle-end"
                        :style="{ left: endHandlePosition + '%' }"
                        @mousedown="startDrag('end', $event)"
                    >
                        <div class="handle-bar"></div>
                    </div>

                    <!-- Playhead -->
                    <div
                        v-if="duration"
                        class="playhead"
                        :style="{ left: (currentTime / duration * 100) + '%' }"
                    ></div>
                </div>
            </div>

            <!-- Time labels -->
            <div class="time-labels">
                <span>0:00</span>
                <span>{{ formatTime(duration) }}</span>
            </div>
        </div>

        <!-- Controls -->
        <div class="trim-controls">
            <div class="time-inputs">
                <div class="time-input-group">
                    <label>Start</label>
                    <div class="input-with-buttons">
                        <button class="adjust-btn" @click="adjustStart(-1)" title="Back 1 second">-1s</button>
                        <input
                            type="text"
                            :value="formattedTrimStart"
                            @change="onStartTimeChange"
                            class="time-input"
                        />
                        <button class="adjust-btn" @click="adjustStart(1)" title="Forward 1 second">+1s</button>
                    </div>
                </div>

                <div class="time-input-group">
                    <label>End</label>
                    <div class="input-with-buttons">
                        <button class="adjust-btn" @click="adjustEnd(-1)" title="Back 1 second">-1s</button>
                        <input
                            type="text"
                            :value="formattedTrimEnd"
                            @change="onEndTimeChange"
                            class="time-input"
                        />
                        <button class="adjust-btn" @click="adjustEnd(1)" title="Forward 1 second">+1s</button>
                    </div>
                </div>

                <div class="duration-display">
                    <label>Duration</label>
                    <span class="duration-value">{{ formattedTrimDuration }}</span>
                </div>
            </div>

            <div class="playback-controls">
                <button class="control-btn" @click="jumpToStart" title="Jump to start">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M6 6h2v12H6zm3.5 6l8.5 6V6z" />
                    </svg>
                </button>
                <button class="control-btn play-btn" @click="togglePlay">
                    <svg v-if="isPlaying" class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z" />
                    </svg>
                    <svg v-else class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z" />
                    </svg>
                </button>
                <button class="control-btn" @click="jumpToEnd" title="Jump to end">
                    <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z" />
                    </svg>
                </button>
                <button class="control-btn preview-btn" @click="previewTrim" title="Preview trim">
                    Preview
                </button>
            </div>
        </div>

        <!-- Error -->
        <div v-if="error" class="error-message">
            {{ error }}
        </div>

        <!-- Actions -->
        <div class="trim-actions">
            <button class="btn-cancel" @click="emit('close')">Cancel</button>
            <button class="btn-save" @click="saveTrim" :disabled="isSaving || trimDuration < 1">
                {{ isSaving ? 'Saving...' : 'Save as New Version' }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.trim-editor {
    background: #111;
    border-radius: 12px;
    padding: 1.5rem;
    max-width: 900px;
    margin: 0 auto;
}

.trim-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.trim-header h3 {
    color: white;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0;
}

.close-btn {
    color: #888;
    padding: 0.5rem;
    transition: color 0.15s;
}

.close-btn:hover {
    color: white;
}

/* Video Preview */
.video-preview {
    position: relative;
    aspect-ratio: 16/9;
    background: #000;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 1.5rem;
}

.video-preview video {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.play-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    cursor: pointer;
}

.play-overlay:hover {
    background: rgba(0, 0, 0, 0.5);
}

/* Timeline */
.trim-timeline-container {
    margin-bottom: 1.5rem;
}

.trim-timeline {
    position: relative;
    height: 60px;
    background: #222;
    border-radius: 6px;
    overflow: hidden;
    cursor: pointer;
}

.trim-timeline.dragging {
    cursor: ew-resize;
}

.thumbnails {
    display: flex;
    height: 100%;
}

.thumbnail {
    flex: 1;
    background-size: cover;
    background-position: center;
    opacity: 0.8;
}

.thumbnails-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
    font-size: 0.875rem;
}

.trim-overlay {
    position: absolute;
    inset: 0;
}

.dim-left,
.dim-right {
    position: absolute;
    top: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.7);
}

.dim-left {
    left: 0;
}

.dim-right {
    right: 0;
}

.selected-area {
    position: absolute;
    top: 0;
    bottom: 0;
    border: 2px solid #6366f1;
    box-sizing: border-box;
}

.handle {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 16px;
    margin-left: -8px;
    cursor: ew-resize;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 2;
}

.handle-bar {
    width: 4px;
    height: 24px;
    background: #6366f1;
    border-radius: 2px;
}

.handle:hover .handle-bar {
    background: #818cf8;
}

.playhead {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #fff;
    margin-left: -1px;
    pointer-events: none;
    z-index: 3;
}

.time-labels {
    display: flex;
    justify-content: space-between;
    margin-top: 0.5rem;
    color: #666;
    font-size: 0.75rem;
    font-family: monospace;
}

/* Controls */
.trim-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.time-inputs {
    display: flex;
    gap: 1.5rem;
}

.time-input-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.time-input-group label {
    color: #888;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.input-with-buttons {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.adjust-btn {
    padding: 0.25rem 0.5rem;
    background: #333;
    color: #ccc;
    border-radius: 4px;
    font-size: 0.75rem;
    transition: background 0.15s;
}

.adjust-btn:hover {
    background: #444;
}

.time-input {
    width: 60px;
    padding: 0.375rem 0.5rem;
    background: #222;
    border: 1px solid #333;
    border-radius: 4px;
    color: white;
    font-family: monospace;
    text-align: center;
}

.time-input:focus {
    outline: none;
    border-color: #6366f1;
}

.duration-display {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.duration-display label {
    color: #888;
    font-size: 0.75rem;
    text-transform: uppercase;
}

.duration-value {
    padding: 0.375rem 0.75rem;
    background: rgba(99, 102, 241, 0.2);
    color: #818cf8;
    border-radius: 4px;
    font-family: monospace;
    font-weight: 500;
}

.playback-controls {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.control-btn {
    padding: 0.5rem;
    color: #888;
    transition: color 0.15s;
}

.control-btn:hover {
    color: white;
}

.play-btn {
    width: 48px;
    height: 48px;
    background: #6366f1;
    border-radius: 50%;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
}

.play-btn:hover {
    background: #4f46e5;
}

.preview-btn {
    padding: 0.5rem 1rem;
    background: #333;
    border-radius: 6px;
    font-size: 0.875rem;
}

.preview-btn:hover {
    background: #444;
    color: white;
}

/* Error */
.error-message {
    padding: 0.75rem 1rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 6px;
    color: #f87171;
    font-size: 0.875rem;
    margin-bottom: 1rem;
}

/* Actions */
.trim-actions {
    display: flex;
    justify-content: flex-end;
    gap: 1rem;
}

.btn-cancel {
    padding: 0.75rem 1.5rem;
    background: #333;
    color: #ccc;
    border-radius: 6px;
    font-size: 0.875rem;
    transition: background 0.15s;
}

.btn-cancel:hover {
    background: #444;
}

.btn-save {
    padding: 0.75rem 1.5rem;
    background: #6366f1;
    color: white;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    transition: background 0.15s;
}

.btn-save:hover:not(:disabled) {
    background: #4f46e5;
}

.btn-save:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
