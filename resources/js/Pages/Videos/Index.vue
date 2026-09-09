<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface ActionItem {
    task: string;
    owner: string | null;
    deadline: string | null;
    priority: 'high' | 'medium' | 'low';
}

interface TranscriptSegment {
    start: number;
    end: number;
    text: string;
}

interface Video {
    id: number;
    uuid: string;
    title: string;
    folder_path: string | null;
    duration: number | null;
    status: 'processing' | 'ready' | 'failed';
    view_count: number;
    share_url: string;
    thumbnail_url: string | null;
    project: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    task: { id: number; title: string } | null;
    created_at: string;
    formatted_recorded_at?: string;
    relative_time?: string;
    absolute_time?: string;
    transcript_status?: string | null;
    has_transcript?: boolean;
    transcript?: string | null;
    transcript_segments?: TranscriptSegment[] | null;
    ai_suggested_title?: string | null;
    ai_action_items?: ActionItem[] | null;
}

interface Folder {
    path: string;
    count: number;
}

interface Stats {
    total: number;
    total_views: number;
    total_duration: number;
}

interface Client {
    id: number;
    name: string;
}

interface Project {
    id: number;
    name: string;
    client_id: number;
    client?: { id: number; name: string };
}

const props = defineProps<{
    videos: Video[];
    folders: Folder[];
    stats: Stats;
    clients: Client[];
    projects: Project[];
}>();

const selectedFolder = ref<string | null>(null);
const showShareModal = ref(false);
const showDeleteModal = ref(false);
const showActionItemsModal = ref(false);
const showTranscriptModal = ref(false);
const selectedVideo = ref<Video | null>(null);
const isSubmitting = ref(false);
const copySuccess = ref(false);
const transcriptSearch = ref('');

const openActionItemsModal = (video: Video, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    selectedVideo.value = video;
    showActionItemsModal.value = true;
};

const openTranscriptModal = (video: Video, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    selectedVideo.value = video;
    transcriptSearch.value = '';
    showTranscriptModal.value = true;
};

const filteredTranscriptSegments = computed(() => {
    if (!selectedVideo.value?.transcript_segments) return [];
    if (!transcriptSearch.value.trim()) return selectedVideo.value.transcript_segments;
    const search = transcriptSearch.value.toLowerCase();
    return selectedVideo.value.transcript_segments.filter(seg =>
        seg.text.toLowerCase().includes(search)
    );
});

const formatTimestamp = (seconds: number) => {
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const openVideoAtTimestamp = (video: Video, seconds: number) => {
    window.open(`${video.share_url}?t=${Math.floor(seconds)}`, '_blank');
};

const getPriorityClass = (priority: string) => {
    return {
        high: 'priority-high',
        medium: 'priority-medium',
        low: 'priority-low',
    }[priority] || 'priority-medium';
};

// Filter projects by client
const projectsForClient = (clientId: number | null) => {
    if (!clientId) return props.projects;
    return props.projects.filter(p => p.client_id === clientId);
};

// Options for FormSelect components
const clientOptions = computed(() => [
    { value: null, label: 'No Client' },
    ...props.clients.map(c => ({ value: c.id, label: c.name }))
]);

const projectOptionsForClient = (clientId: number | null) => {
    const projects = projectsForClient(clientId);
    return [
        { value: null, label: 'No Project' },
        ...projects.map(p => ({ value: p.id, label: p.name }))
    ];
};

const fullShareUrl = computed(() => {
    if (!selectedVideo.value) return '';
    return window.location.origin + selectedVideo.value.share_url;
});

const filteredVideos = computed(() => {
    if (!selectedFolder.value) return props.videos;
    if (selectedFolder.value === 'Unfiled') {
        return props.videos.filter(v => !v.folder_path);
    }
    return props.videos.filter(v => v.folder_path === selectedFolder.value);
});

const formatDuration = (seconds: number | null) => {
    if (!seconds) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

const formatTotalDuration = (seconds: number) => {
    const hours = Math.floor(seconds / 3600);
    const mins = Math.floor((seconds % 3600) / 60);
    if (hours > 0) return `${hours}h ${mins}m`;
    return `${mins}m`;
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        ready: 'badge-green',
        processing: 'badge-yellow',
        failed: 'badge-red',
    };
    return badges[status] || 'badge-gray';
};

const openShareModal = (video: Video, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    selectedVideo.value = video;
    showShareModal.value = true;
};

const openDeleteModal = (video: Video, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    selectedVideo.value = video;
    showDeleteModal.value = true;
};

const copyShareUrl = async () => {
    if (!fullShareUrl.value) return;
    await navigator.clipboard.writeText(fullShareUrl.value);
    copySuccess.value = true;
    setTimeout(() => copySuccess.value = false, 2000);
};

const deleteVideo = async () => {
    if (!selectedVideo.value) return;
    isSubmitting.value = true;

    try {
        const response = await fetch(`/api/videos/${selectedVideo.value.uuid}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        if (response.ok) {
            showDeleteModal.value = false;
            selectedVideo.value = null;
            router.reload({ only: ['videos', 'folders', 'stats'] });
        }
    } catch (e) {
        console.error('Failed to delete video:', e);
    } finally {
        isSubmitting.value = false;
    }
};

const regenerateToken = async () => {
    if (!selectedVideo.value) return;
    isSubmitting.value = true;

    try {
        const response = await fetch(`/api/videos/${selectedVideo.value.uuid}/regenerate-token`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        if (response.ok) {
            router.reload({ only: ['videos'] });
        }
    } catch (e) {
        console.error('Failed to regenerate token:', e);
    } finally {
        isSubmitting.value = false;
    }
};

const acceptSuggestedTitle = async (video: Video, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (!video.ai_suggested_title) return;

    try {
        const response = await fetch(`/api/videos/${video.uuid}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                title: video.ai_suggested_title,
                ai_suggested_title: null, // Clear the suggestion
            }),
        });
        if (response.ok) {
            router.reload({ only: ['videos'] });
        }
    } catch (e) {
        console.error('Failed to accept title:', e);
    }
};

const dismissSuggestedTitle = async (video: Video, e: Event) => {
    e.preventDefault();
    e.stopPropagation();

    try {
        const response = await fetch(`/api/videos/${video.uuid}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                ai_suggested_title: null,
            }),
        });
        if (response.ok) {
            router.reload({ only: ['videos'] });
        }
    } catch (e) {
        console.error('Failed to dismiss suggestion:', e);
    }
};

const updateVideoAssignment = async (video: Video, field: 'client_id' | 'project_id', value: number | null) => {
    try {
        const response = await fetch(`/api/videos/${video.uuid}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ [field]: value }),
        });
        if (response.ok) {
            router.reload({ only: ['videos'] });
        }
    } catch (e) {
        console.error('Failed to update assignment:', e);
    }
};
</script>

<template>
    <AppLayout title="Videos">
        <!-- Stats -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL VIDEOS</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL VIEWS</div>
                <div class="metric-value">{{ stats.total_views.toLocaleString() }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL DURATION</div>
                <div class="metric-value">{{ formatTotalDuration(stats.total_duration) }}</div>
            </div>
        </div>

        <div class="flex gap-6">
            <!-- Folders Sidebar -->
            <div class="w-64 flex-shrink-0" v-if="folders.length > 1">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Folders</span>
                    </div>
                    <div class="card-body p-0">
                        <button
                            class="folder-item"
                            :class="{ active: !selectedFolder }"
                            @click="selectedFolder = null"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776" />
                            </svg>
                            <span>All Videos</span>
                            <span class="folder-count">{{ videos.length }}</span>
                        </button>
                        <button
                            v-for="folder in folders"
                            :key="folder.path"
                            class="folder-item"
                            :class="{ active: selectedFolder === folder.path }"
                            @click="selectedFolder = folder.path"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                            </svg>
                            <span class="truncate">{{ folder.path }}</span>
                            <span class="folder-count">{{ folder.count }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Videos Grid -->
            <div class="flex-1">
                <div class="card video-list-card">
                    <div class="card-header">
                        <span class="card-title">
                            {{ selectedFolder || 'All Videos' }}
                        </span>
                    </div>

                    <div class="card-body">
                        <div class="video-grid" v-if="filteredVideos.length > 0">
                            <div
                                v-for="video in filteredVideos"
                                :key="video.id"
                                class="video-card"
                            >
                                <!-- Thumbnail -->
                                <a
                                    :href="video.share_url"
                                    target="_blank"
                                    class="video-thumbnail"
                                >
                                    <img
                                        v-if="video.thumbnail_url"
                                        :src="video.thumbnail_url"
                                        :alt="video.title"
                                        class="thumbnail-img"
                                    />
                                    <div v-else class="thumbnail-placeholder">
                                        <svg class="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.91 11.672a.375.375 0 010 .656l-5.603 3.113a.375.375 0 01-.557-.328V8.887c0-.286.307-.466.557-.327l5.603 3.112z" />
                                        </svg>
                                    </div>
                                    <div class="duration-badge" v-if="video.duration">
                                        {{ formatDuration(video.duration) }}
                                    </div>
                                    <div class="play-overlay">
                                        <svg class="h-12 w-12" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M8 5v14l11-7z" />
                                        </svg>
                                    </div>
                                </a>

                                <!-- Info -->
                                <div class="video-info">
                                    <!-- Title Row -->
                                    <div class="video-header">
                                        <h3 class="video-title">{{ video.title }}</h3>
                                        <span v-if="video.status !== 'ready'" :class="['status-dot', `status-${video.status}`]" :title="video.status"></span>
                                    </div>

                                    <!-- AI Title Suggestion (only shows if exists) -->
                                    <div
                                        v-if="video.ai_suggested_title && video.ai_suggested_title !== video.title"
                                        class="title-suggestion"
                                    >
                                        <span class="suggestion-text">{{ video.ai_suggested_title }}</span>
                                        <button
                                            class="suggestion-btn suggestion-accept"
                                            @click="acceptSuggestedTitle(video, $event)"
                                            title="Use this title"
                                        >✓</button>
                                        <button
                                            class="suggestion-btn suggestion-dismiss"
                                            @click="dismissSuggestedTitle(video, $event)"
                                            title="Dismiss"
                                        >×</button>
                                    </div>

                                    <!-- Meta Row: Assignment + Stats -->
                                    <div class="video-meta-row">
                                        <span class="video-assignment">
                                            <template v-if="video.client || video.project">
                                                {{ video.client?.name }}<template v-if="video.client && video.project"> · </template>{{ video.project?.name }}
                                            </template>
                                            <span v-else class="unassigned">Unassigned</span>
                                        </span>
                                        <span class="video-time" :title="video.absolute_time">{{ video.relative_time || 'Just now' }}</span>
                                    </div>

                                    <!-- Stats Row -->
                                    <div class="video-stats-row">
                                        <span class="stat-item">
                                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            </svg>
                                            {{ video.view_count }}
                                        </span>

                                        <!-- Spacer -->
                                        <span class="stats-spacer"></span>

                                        <!-- Feature Icons -->
                                        <div class="feature-icons">
                                            <button
                                                v-if="video.has_transcript"
                                                class="feature-btn"
                                                title="View transcript"
                                                @click="openTranscriptModal(video, $event)"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                            </button>
                                            <span v-else-if="video.transcript_status === 'processing'" class="feature-btn processing" title="Generating transcript...">
                                                <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                                </svg>
                                            </span>
                                            <button
                                                v-if="video.ai_action_items?.length"
                                                class="feature-btn has-badge"
                                                :title="`${video.ai_action_items.length} action items`"
                                                @click="openActionItemsModal(video, $event)"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                                                </svg>
                                                <span class="feature-badge">{{ video.ai_action_items.length }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Actions (visible on hover) -->
                                    <div class="video-actions">
                                        <button class="action-btn action-share" @click="openShareModal(video, $event)">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
                                            </svg>
                                            Share
                                        </button>
                                        <div class="action-spacer"></div>
                                        <div class="action-select-wrapper" @click.stop>
                                            <FormSelect
                                                :model-value="video.client?.id || null"
                                                :options="clientOptions"
                                                placeholder="No Client"
                                                variant="inline"
                                                size="sm"
                                                searchable
                                                @update:model-value="(v) => updateVideoAssignment(video, 'client_id', v)"
                                            />
                                        </div>
                                        <div v-if="video.client" class="action-select-wrapper" @click.stop>
                                            <FormSelect
                                                :model-value="video.project?.id || null"
                                                :options="projectOptionsForClient(video.client?.id || null)"
                                                placeholder="No Project"
                                                variant="inline"
                                                size="sm"
                                                searchable
                                                @update:model-value="(v) => updateVideoAssignment(video, 'project_id', v)"
                                            />
                                        </div>
                                        <button class="action-btn action-delete" @click="openDeleteModal(video, $event)" title="Delete">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div v-else class="p-12 text-center">
                            <div class="avatar avatar-lg avatar-muted mx-auto mb-4">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m15.75 10.5 4.72-4.72a.75.75 0 0 1 1.28.53v11.38a.75.75 0 0 1-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25h-9A2.25 2.25 0 0 0 2.25 7.5v9a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                            </div>
                            <p class="text-body">No videos yet</p>
                            <p class="text-caption mt-1">Record your first video using the Chrome extension</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Share Modal -->
        <Modal
            :show="showShareModal"
            title="Share Video"
            size="sm"
            @close="showShareModal = false"
        >
            <div v-if="selectedVideo" class="space-y-4">
                <div>
                    <label class="form-label">Share Link</label>
                    <div class="share-url-box">
                        <input
                            type="text"
                            readonly
                            :value="fullShareUrl"
                            class="share-url-input"
                        />
                        <button
                            class="btn btn-primary btn-sm"
                            @click="copyShareUrl"
                        >
                            {{ copySuccess ? 'Copied!' : 'Copy' }}
                        </button>
                    </div>
                </div>

                <div class="text-caption">
                    Anyone with this link can view the video.
                </div>
            </div>

            <template #footer>
                <button
                    class="btn btn-secondary btn-sm"
                    @click="regenerateToken"
                    :disabled="isSubmitting"
                >
                    Regenerate Link
                </button>
                <button class="btn btn-primary" @click="showShareModal = false">
                    Done
                </button>
            </template>
        </Modal>

        <!-- Delete Modal -->
        <Modal
            :show="showDeleteModal"
            title="Delete Video"
            size="sm"
            @close="showDeleteModal = false"
        >
            <div v-if="selectedVideo" class="space-y-4">
                <p class="text-body">
                    Are you sure you want to delete <strong>{{ selectedVideo.title }}</strong>?
                </p>
                <div class="warning-box">
                    <svg class="h-5 w-5 flex-shrink-0" style="color: var(--color-status-red)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div>
                        <p class="text-body" style="color: var(--color-status-red)">This action cannot be undone</p>
                        <p class="text-caption">The video file and all view analytics will be permanently deleted.</p>
                    </div>
                </div>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showDeleteModal = false">
                    Cancel
                </button>
                <button
                    class="btn"
                    style="background: var(--color-status-red); color: white"
                    :disabled="isSubmitting"
                    @click="deleteVideo"
                >
                    {{ isSubmitting ? 'Deleting...' : 'Delete Video' }}
                </button>
            </template>
        </Modal>

        <!-- Action Items Modal -->
        <Modal
            :show="showActionItemsModal"
            :title="`Action Items - ${selectedVideo?.title || ''}`"
            size="md"
            @close="showActionItemsModal = false"
        >
            <div v-if="selectedVideo?.ai_action_items?.length" class="action-items-list">
                <div
                    v-for="(item, index) in selectedVideo.ai_action_items"
                    :key="index"
                    class="action-item"
                >
                    <div class="action-item-header">
                        <span :class="['priority-badge', getPriorityClass(item.priority)]">
                            {{ item.priority }}
                        </span>
                        <span v-if="item.owner" class="action-item-owner">{{ item.owner }}</span>
                    </div>
                    <p class="action-item-task">{{ item.task }}</p>
                    <p v-if="item.deadline" class="action-item-deadline">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        {{ item.deadline }}
                    </p>
                </div>
            </div>
            <div v-else class="text-center py-8 text-caption">
                No action items found.
            </div>
        </Modal>

        <!-- Transcript Modal -->
        <Modal
            :show="showTranscriptModal"
            :title="`Transcript - ${selectedVideo?.title || ''}`"
            size="lg"
            @close="showTranscriptModal = false"
        >
            <div class="transcript-modal-content">
                <!-- Search -->
                <div class="transcript-search">
                    <svg class="h-4 w-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input
                        v-model="transcriptSearch"
                        type="text"
                        placeholder="Search transcript..."
                        class="transcript-search-input"
                    />
                </div>

                <!-- Segments -->
                <div v-if="selectedVideo?.transcript_segments?.length" class="transcript-segments-list">
                    <div
                        v-for="(segment, index) in filteredTranscriptSegments"
                        :key="index"
                        class="transcript-segment-item"
                        @click="openVideoAtTimestamp(selectedVideo!, segment.start)"
                    >
                        <span class="segment-timestamp">{{ formatTimestamp(segment.start) }}</span>
                        <span class="segment-text" v-html="transcriptSearch
                            ? segment.text.replace(new RegExp(`(${transcriptSearch})`, 'gi'), '<mark>$1</mark>')
                            : segment.text
                        "></span>
                    </div>
                    <div v-if="filteredTranscriptSegments.length === 0" class="text-center py-8 text-caption">
                        No matches found for "{{ transcriptSearch }}"
                    </div>
                </div>

                <!-- Plain transcript fallback -->
                <div v-else-if="selectedVideo?.transcript" class="transcript-plain-text">
                    {{ selectedVideo.transcript }}
                </div>

                <div v-else class="text-center py-8 text-caption">
                    No transcript available.
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Override card overflow to allow dropdowns to escape */
.video-list-card {
    overflow: visible;
}

.video-list-card :deep(.card-body) {
    overflow: visible;
}

.folder-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.75rem 1rem;
    text-align: left;
    color: var(--color-text-secondary);
    transition: all 0.15s;
    border-bottom: 1px solid var(--color-border-subtle);
}

.folder-item:hover {
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
}

.folder-item.active {
    background: var(--color-accent-subtle);
    color: var(--color-accent);
}

.folder-item:last-child {
    border-bottom: none;
}

.folder-count {
    margin-left: auto;
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.video-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}

.video-card {
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    background: var(--color-bg-elevated);
    transition: all 0.15s;
    position: relative;
}

.video-card:hover {
    border-color: var(--color-border-default);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.video-thumbnail {
    position: relative;
    display: block;
    aspect-ratio: 16/9;
    background: var(--color-bg-surface);
    overflow: hidden;
}

.thumbnail-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.thumbnail-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: var(--color-text-muted);
}

.duration-badge {
    position: absolute;
    bottom: 0.5rem;
    right: 0.5rem;
    padding: 0.125rem 0.375rem;
    background: rgba(0, 0, 0, 0.8);
    color: white;
    font-size: 0.75rem;
    font-family: var(--font-mono);
    border-radius: 4px;
}

.play-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    opacity: 0;
    transition: opacity 0.15s;
}

.video-thumbnail:hover .play-overlay {
    opacity: 1;
}

/* Video Info Section */
.video-info {
    padding: 0.875rem 1rem 1rem;
}

.video-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.video-title {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--color-text-primary);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
}

.status-processing {
    background: var(--color-status-yellow);
    animation: pulse 2s infinite;
}

.status-failed {
    background: var(--color-status-red);
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* AI Title Suggestion */
.title-suggestion {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    margin-top: 0.375rem;
    padding: 0.25rem 0.5rem;
    background: var(--color-accent-subtle);
    border-radius: 4px;
    font-size: 0.6875rem;
}

.suggestion-text {
    color: var(--color-text-secondary);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.suggestion-btn {
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 3px;
    font-size: 0.75rem;
    font-weight: 600;
    transition: all 0.15s;
}

.suggestion-accept {
    color: var(--color-status-green);
}

.suggestion-accept:hover {
    background: rgba(34, 197, 94, 0.2);
}

.suggestion-dismiss {
    color: var(--color-text-muted);
}

.suggestion-dismiss:hover {
    background: var(--color-bg-secondary);
}

/* Meta Row */
.video-meta-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 0.375rem;
    font-size: 0.75rem;
}

.video-assignment {
    color: var(--color-text-secondary);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.video-assignment .unassigned {
    color: var(--color-text-quaternary);
    font-style: italic;
}

.video-time {
    color: var(--color-text-muted);
    white-space: nowrap;
    flex-shrink: 0;
}

/* Stats Row */
.video-stats-row {
    display: flex;
    align-items: center;
    margin-top: 0.625rem;
    padding-top: 0.625rem;
    border-top: 1px solid var(--color-border-subtle);
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.stat-icon {
    width: 14px;
    height: 14px;
}

.stats-spacer {
    flex: 1;
}

/* Feature Icons */
.feature-icons {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.feature-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    color: var(--color-text-muted);
    transition: all 0.15s;
    position: relative;
}

.feature-btn:hover {
    background: var(--color-bg-secondary);
    color: var(--color-accent);
}

.feature-btn.processing {
    cursor: default;
}

.feature-btn.has-badge {
    color: var(--color-status-green);
}

.feature-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    font-size: 0.5625rem;
    font-weight: 600;
    background: var(--color-status-green);
    color: white;
    padding: 0 3px;
    border-radius: 3px;
    min-width: 12px;
    text-align: center;
    line-height: 1.3;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}

/* Actions Row - visible on hover */
.video-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.375rem;
    margin-top: 0.625rem;
    padding-top: 0.625rem;
    border-top: 1px solid var(--color-border-subtle);
    opacity: 0;
    transition: opacity 0.15s;
}

.video-card:hover .video-actions {
    opacity: 1;
}

.action-btn {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.625rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 500;
    transition: all 0.15s;
}

.action-share {
    background: var(--color-bg-secondary);
    color: var(--color-text-primary);
}

.action-share:hover {
    background: var(--color-accent);
    color: white;
}

.action-spacer {
    flex: 1;
    min-width: 0;
}

.action-select-wrapper {
    position: relative;
    z-index: 10;
}

.action-select-wrapper :deep(.form-select-wrapper) {
    margin-bottom: 0;
}

.action-select-wrapper :deep(.dropdown) {
    z-index: 200;
}

.action-select-wrapper :deep(.select-trigger) {
    padding: 0.375rem 0.5rem;
    background: var(--color-bg-secondary);
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 0.6875rem;
    min-width: 70px;
    max-width: 110px;
}

.action-select-wrapper :deep(.select-trigger:hover) {
    border-color: var(--color-border);
}

/* Remove focus ring when dropdown is open */
.action-select-wrapper :deep(.select-container.is-open .select-trigger) {
    box-shadow: none;
    border-color: var(--color-border-hover);
}

/* Ensure search input has no outline */
.action-select-wrapper :deep(.search-input),
.action-select-wrapper :deep(.search-input:focus),
.action-select-wrapper :deep(.search-input:focus-visible) {
    outline: none !important;
    box-shadow: none !important;
    border: none !important;
}

.action-select-wrapper :deep(.dropdown) {
    min-width: 160px;
}

.action-select-wrapper :deep(.selected-label),
.action-select-wrapper :deep(.placeholder) {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.action-delete {
    color: var(--color-text-muted);
    padding: 0.375rem;
}

.action-delete:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.share-url-box {
    display: flex;
    gap: 0.5rem;
}

.share-url-input {
    flex: 1;
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    font-size: 0.8125rem;
    font-family: var(--font-mono);
    color: var(--color-text-primary);
}

.warning-box {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.8125rem;
}

/* Action Items Modal */
.action-items-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.action-item {
    padding: 1rem;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
}

.action-item-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.priority-badge {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
}

.priority-high {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.priority-medium {
    background: rgba(245, 158, 11, 0.15);
    color: var(--color-status-yellow);
}

.priority-low {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.action-item-owner {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.action-item-task {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    line-height: 1.5;
    margin: 0;
}

.action-item-deadline {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--color-text-muted);
    margin: 0.5rem 0 0;
}

/* Transcript Modal */
.transcript-modal-content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    max-height: 60vh;
}

.transcript-search {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
}

.transcript-search-input {
    flex: 1;
    border: none;
    background: transparent;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    outline: none;
}

.transcript-search-input::placeholder {
    color: var(--color-text-muted);
}

.transcript-segments-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    overflow-y: auto;
    max-height: 50vh;
}

.transcript-segment-item {
    display: flex;
    gap: 0.75rem;
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;
}

.transcript-segment-item:hover {
    background: var(--color-bg-secondary);
}

.segment-timestamp {
    font-size: 0.75rem;
    font-family: var(--font-mono);
    color: var(--color-accent);
    white-space: nowrap;
    min-width: 3rem;
}

.segment-text {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    line-height: 1.5;
}

.segment-text :deep(mark) {
    background: rgba(99, 102, 241, 0.3);
    color: inherit;
    padding: 0 0.125rem;
    border-radius: 2px;
}

.transcript-plain-text {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    line-height: 1.6;
    white-space: pre-wrap;
    max-height: 50vh;
    overflow-y: auto;
}

.text-muted {
    color: var(--color-text-muted);
}
</style>
