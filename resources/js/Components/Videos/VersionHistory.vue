<script setup lang="ts">
import { ref, onMounted } from 'vue';

interface Version {
    id: number;
    version_number: number;
    status: 'pending' | 'processing' | 'ready' | 'failed';
    is_current: boolean;
    is_original: boolean;
    duration: number | null;
    formatted_duration: string;
    trim_range: string | null;
    trim_start_seconds: number | null;
    trim_end_seconds: number | null;
    file_size: number | null;
    formatted_file_size: string;
    created_at: string;
    created_at_human: string;
}

const props = defineProps<{
    videoUuid: string;
}>();

const emit = defineEmits<{
    (e: 'versionChanged', version: Version): void;
}>();

const versions = ref<Version[]>([]);
const loading = ref(true);
const activating = ref<number | null>(null);
const deleting = ref<number | null>(null);
const error = ref<string | null>(null);

async function fetchVersions() {
    loading.value = true;
    error.value = null;
    try {
        const response = await fetch(`/api/videos/${props.videoUuid}/versions`, {
            credentials: 'include',
        });
        if (!response.ok) throw new Error('Failed to load versions');
        const data = await response.json();
        versions.value = data.versions || [];
    } catch (e: any) {
        error.value = e.message;
    } finally {
        loading.value = false;
    }
}

async function activateVersion(version: Version) {
    if (version.is_current || version.status !== 'ready') return;

    activating.value = version.id;
    try {
        const response = await fetch(`/api/videos/${props.videoUuid}/versions/${version.id}/activate`, {
            method: 'POST',
            credentials: 'include',
        });
        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.error || 'Failed to activate version');
        }
        await fetchVersions();
        emit('versionChanged', version);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        activating.value = null;
    }
}

async function deleteVersion(version: Version) {
    if (version.is_current || version.is_original) return;

    if (!confirm(`Delete version ${version.version_number}? This cannot be undone.`)) {
        return;
    }

    deleting.value = version.id;
    try {
        const response = await fetch(`/api/videos/${props.videoUuid}/versions/${version.id}`, {
            method: 'DELETE',
            credentials: 'include',
        });
        if (!response.ok) {
            const data = await response.json();
            throw new Error(data.error || 'Failed to delete version');
        }
        await fetchVersions();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        deleting.value = null;
    }
}

function getStatusBadge(status: string) {
    switch (status) {
        case 'ready': return { class: 'status-ready', label: 'Ready' };
        case 'processing': return { class: 'status-processing', label: 'Processing' };
        case 'pending': return { class: 'status-pending', label: 'Pending' };
        case 'failed': return { class: 'status-failed', label: 'Failed' };
        default: return { class: '', label: status };
    }
}

onMounted(fetchVersions);

// Expose refresh method
defineExpose({ refresh: fetchVersions });
</script>

<template>
    <div class="version-history">
        <div class="history-header">
            <h4>Version History</h4>
            <button class="refresh-btn" @click="fetchVersions" :disabled="loading" title="Refresh">
                <svg class="h-4 w-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </button>
        </div>

        <div v-if="error" class="error-message">
            {{ error }}
        </div>

        <div v-if="loading && versions.length === 0" class="loading-state">
            Loading versions...
        </div>

        <div v-else-if="versions.length === 0" class="empty-state">
            No versions yet. Use the trim tool to create a new version.
        </div>

        <div v-else class="versions-list">
            <div
                v-for="version in versions"
                :key="version.id"
                class="version-item"
                :class="{ current: version.is_current }"
            >
                <div class="version-header">
                    <div class="version-title">
                        <span class="version-indicator" :class="{ active: version.is_current }">
                            {{ version.is_current ? '●' : '○' }}
                        </span>
                        <span class="version-number">v{{ version.version_number }}</span>
                        <span v-if="version.is_current" class="current-badge">current</span>
                        <span v-if="version.is_original" class="original-badge">original</span>
                    </div>
                    <span class="version-duration">{{ version.formatted_duration }}</span>
                </div>

                <div class="version-meta">
                    <span v-if="version.trim_range" class="trim-info">
                        Trimmed {{ version.trim_range }}
                    </span>
                    <span v-else class="trim-info">Full video</span>
                </div>

                <div class="version-footer">
                    <span class="version-date">{{ version.created_at_human }}</span>
                    <span
                        v-if="version.status !== 'ready'"
                        class="status-badge"
                        :class="getStatusBadge(version.status).class"
                    >
                        {{ getStatusBadge(version.status).label }}
                    </span>
                </div>

                <div class="version-actions">
                    <button
                        v-if="!version.is_current && version.status === 'ready'"
                        class="action-btn activate-btn"
                        @click="activateVersion(version)"
                        :disabled="activating === version.id"
                    >
                        {{ activating === version.id ? 'Activating...' : 'Activate' }}
                    </button>
                    <button
                        v-if="!version.is_current && !version.is_original"
                        class="action-btn delete-btn"
                        @click="deleteVersion(version)"
                        :disabled="deleting === version.id"
                    >
                        {{ deleting === version.id ? 'Deleting...' : 'Delete' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.version-history {
    background: #111;
    border-radius: 8px;
    padding: 1rem;
}

.history-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.history-header h4 {
    color: white;
    font-size: 0.875rem;
    font-weight: 600;
    margin: 0;
}

.refresh-btn {
    color: #888;
    padding: 0.25rem;
    transition: color 0.15s;
}

.refresh-btn:hover:not(:disabled) {
    color: white;
}

.refresh-btn:disabled {
    opacity: 0.5;
}

.error-message {
    padding: 0.5rem 0.75rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 4px;
    color: #f87171;
    font-size: 0.75rem;
    margin-bottom: 1rem;
}

.loading-state,
.empty-state {
    text-align: center;
    padding: 2rem 1rem;
    color: #666;
    font-size: 0.875rem;
}

.versions-list {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.version-item {
    padding: 0.75rem;
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid #222;
    border-radius: 6px;
    transition: border-color 0.15s;
}

.version-item.current {
    border-color: rgba(99, 102, 241, 0.5);
    background: rgba(99, 102, 241, 0.05);
}

.version-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.25rem;
}

.version-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.version-indicator {
    color: #666;
    font-size: 0.75rem;
}

.version-indicator.active {
    color: #6366f1;
}

.version-number {
    color: white;
    font-weight: 500;
    font-size: 0.875rem;
}

.current-badge {
    padding: 0.125rem 0.375rem;
    background: rgba(99, 102, 241, 0.2);
    color: #818cf8;
    font-size: 0.625rem;
    text-transform: uppercase;
    border-radius: 3px;
}

.original-badge {
    padding: 0.125rem 0.375rem;
    background: rgba(74, 222, 128, 0.2);
    color: #4ade80;
    font-size: 0.625rem;
    text-transform: uppercase;
    border-radius: 3px;
}

.version-duration {
    color: #888;
    font-family: monospace;
    font-size: 0.8125rem;
}

.version-meta {
    margin-bottom: 0.5rem;
}

.trim-info {
    color: #666;
    font-size: 0.75rem;
}

.version-footer {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.version-date {
    color: #555;
    font-size: 0.75rem;
}

.status-badge {
    padding: 0.125rem 0.375rem;
    font-size: 0.625rem;
    text-transform: uppercase;
    border-radius: 3px;
}

.status-ready {
    background: rgba(74, 222, 128, 0.2);
    color: #4ade80;
}

.status-processing {
    background: rgba(251, 191, 36, 0.2);
    color: #fbbf24;
}

.status-pending {
    background: rgba(156, 163, 175, 0.2);
    color: #9ca3af;
}

.status-failed {
    background: rgba(239, 68, 68, 0.2);
    color: #f87171;
}

.version-actions {
    display: flex;
    gap: 0.5rem;
}

.action-btn {
    padding: 0.375rem 0.75rem;
    font-size: 0.75rem;
    border-radius: 4px;
    transition: all 0.15s;
}

.activate-btn {
    background: #333;
    color: #ccc;
}

.activate-btn:hover:not(:disabled) {
    background: #444;
    color: white;
}

.delete-btn {
    background: transparent;
    color: #888;
    border: 1px solid #333;
}

.delete-btn:hover:not(:disabled) {
    background: rgba(239, 68, 68, 0.1);
    border-color: #f87171;
    color: #f87171;
}

.action-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.animate-spin {
    animation: spin 1s linear infinite;
}
</style>
