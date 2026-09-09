<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

interface Asset {
    id: number;
    filename: string;
    original_filename?: string;
    type: string;
    category: string | null;
    description: string | null;
    mime_type: string;
    size: string;
    local_path: string;
    wordpress_url: string | null;
    metadata: Record<string, unknown> | null;
}

interface AssetsResponse {
    images: Asset[];
    documents: Asset[];
    videos: Asset[];
    audio: Asset[];
    other: Asset[];
    total_count: number;
    total_size: number;
}

const props = defineProps<{
    projectId: number;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    assetsChanged: [assets: AssetsResponse];
}>();

const assets = ref<AssetsResponse | null>(null);
const isLoading = ref(false);
const isUploading = ref(false);
const uploadProgress = ref(0);
const error = ref<string | null>(null);
const isDragging = ref(false);
const fileInputRef = ref<HTMLInputElement | null>(null);



const allAssets = computed(() => {
    if (!assets.value) return [];
    return [
        ...assets.value.images,
        ...assets.value.documents,
        ...assets.value.videos,
        ...assets.value.audio,
        ...assets.value.other,
    ];
});

const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
};

const getFileIcon = (type: string, mimeType: string) => {
    if (type === 'image') return 'image';
    if (mimeType === 'application/pdf') return 'pdf';
    if (type === 'document') return 'document';
    if (type === 'video') return 'video';
    if (type === 'audio') return 'audio';
    return 'file';
};

const fetchAssets = async () => {
    isLoading.value = true;
    error.value = null;
    try {
        const response = await axios.get(`/api/website-builder/projects/${props.projectId}/assets`);
        assets.value = response.data.assets;
        emit('assetsChanged', response.data.assets);
    } catch (e) {
        error.value = 'Failed to load assets';
        console.error(e);
    } finally {
        isLoading.value = false;
    }
};

const handleDragOver = (e: DragEvent) => {
    e.preventDefault();
    if (!props.disabled) {
        isDragging.value = true;
    }
};

const handleDragLeave = (e: DragEvent) => {
    e.preventDefault();
    isDragging.value = false;
};

const handleDrop = (e: DragEvent) => {
    e.preventDefault();
    isDragging.value = false;
    if (props.disabled) return;
    
    const files = Array.from(e.dataTransfer?.files || []);
    if (files.length > 0) {
        uploadFiles(files);
    }
};

const handleFileSelect = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const files = Array.from(input.files || []);
    if (files.length > 0) {
        uploadFiles(files);
    }
    input.value = '';
};

const openFilePicker = () => {
    if (!props.disabled) {
        fileInputRef.value?.click();
    }
};

const uploadFiles = async (files: File[]) => {
    if (isUploading.value || props.disabled) return;
    
    isUploading.value = true;
    uploadProgress.value = 0;
    error.value = null;
    
    const formData = new FormData();
    files.forEach((file) => {
        formData.append('files[]', file);
    });
    
    try {
        await axios.post(
            `/api/website-builder/projects/${props.projectId}/assets`,
            formData,
            {
                headers: { 'Content-Type': 'multipart/form-data' },
                onUploadProgress: (progressEvent) => {
                    if (progressEvent.total) {
                        uploadProgress.value = Math.round((progressEvent.loaded * 100) / progressEvent.total);
                    }
                },
            }
        );
        await fetchAssets();
    } catch (e: unknown) {
        const axiosError = e as { response?: { data?: { message?: string } } };
        error.value = axiosError.response?.data?.message || 'Upload failed';
        console.error(e);
    } finally {
        isUploading.value = false;
        uploadProgress.value = 0;
    }
};

const deleteAsset = async (assetId: number) => {
    if (props.disabled) return;
    
    try {
        await axios.delete(`/api/website-builder/projects/${props.projectId}/assets/${assetId}`);
        await fetchAssets();
    } catch (e) {
        error.value = 'Failed to delete asset';
        console.error(e);
    }
};

onMounted(() => {
    fetchAssets();
});
</script>

<template>
    <div class="asset-uploader">
        <div class="uploader-header">
            <h3 class="uploader-title">Project Assets</h3>
            <span v-if="assets" class="asset-count">
                {{ assets.total_count }} file{{ assets.total_count !== 1 ? 's' : '' }}
            </span>
        </div>

        <div v-if="error" class="error-message">
            {{ error }}
            <button class="error-dismiss" @click="error = null">&times;</button>
        </div>

        <div
            class="drop-zone"
            :class="{ 
                'is-dragging': isDragging, 
                'is-uploading': isUploading,
                'is-disabled': disabled 
            }"
            @dragover="handleDragOver"
            @dragleave="handleDragLeave"
            @drop="handleDrop"
            @click="openFilePicker"
        >
            <input
                ref="fileInputRef"
                type="file"
                multiple
                accept="image/*,.pdf,.doc,.docx,.txt,.csv"
                class="file-input"
                @change="handleFileSelect"
            >
            
            <div v-if="isUploading" class="upload-progress">
                <div class="progress-bar">
                    <div class="progress-fill" :style="{ width: uploadProgress + '%' }"></div>
                </div>
                <span class="progress-text">Uploading... {{ uploadProgress }}%</span>
            </div>
            
            <div v-else class="drop-zone-content">
                <svg class="drop-icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <p class="drop-text">
                    <span class="drop-text-primary">Drop files here</span>
                    <span class="drop-text-secondary">or click to browse</span>
                </p>
                <p class="drop-hint">Images, PDFs, documents up to 50MB each</p>
            </div>
        </div>

        <div v-if="isLoading" class="loading-state">
            <div class="loading-spinner"></div>
            <span>Loading assets...</span>
        </div>

        <div v-else-if="allAssets.length > 0" class="asset-list">
            <div v-for="asset in allAssets" :key="asset.id" class="asset-item">
                <div class="asset-icon" :class="'icon-' + getFileIcon(asset.type, asset.mime_type)">
                    <svg v-if="asset.type === 'image'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <circle cx="8.5" cy="8.5" r="1.5"/>
                        <polyline points="21 15 16 10 5 21"/>
                    </svg>
                    <svg v-else-if="asset.mime_type === 'application/pdf'" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                    <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/>
                        <polyline points="13 2 13 9 20 9"/>
                    </svg>
                </div>
                <div class="asset-info">
                    <span class="asset-name" :title="asset.original_filename || asset.filename">
                        {{ asset.original_filename || asset.filename }}
                    </span>
                    <span class="asset-meta">
                        {{ asset.size }}
                        <span v-if="asset.category" class="asset-category">{{ asset.category }}</span>
                    </span>
                </div>
                <button 
                    class="asset-delete" 
                    :disabled="disabled"
                    @click.stop="deleteAsset(asset.id)"
                    title="Delete asset"
                >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                </button>
            </div>
        </div>

        <div v-else-if="!isLoading" class="empty-state">
            <p>No assets uploaded yet</p>
            <p class="empty-hint">Upload images, logos, PDFs, or briefs to include in your website build</p>
        </div>
    </div>
</template>

<style scoped>
.asset-uploader {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.uploader-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.uploader-title {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin: 0;
}

.asset-count {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
}

.error-message {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0.75rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    border-radius: 6px;
    font-size: 0.75rem;
    color: var(--color-status-red);
}

.error-dismiss {
    background: none;
    border: none;
    color: inherit;
    font-size: 1rem;
    cursor: pointer;
    padding: 0 0.25rem;
}

.drop-zone {
    position: relative;
    border: 2px dashed var(--color-border-default);
    border-radius: 8px;
    padding: 1.25rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: var(--color-bg-tertiary);
}

.drop-zone:hover:not(.is-disabled) {
    border-color: var(--color-accent);
    background: rgba(139, 92, 246, 0.05);
}

.drop-zone.is-dragging {
    border-color: var(--color-accent);
    background: rgba(139, 92, 246, 0.1);
    border-style: solid;
}

.drop-zone.is-uploading {
    cursor: default;
    pointer-events: none;
}

.drop-zone.is-disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.file-input {
    position: absolute;
    width: 0;
    height: 0;
    opacity: 0;
    pointer-events: none;
}

.drop-zone-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.drop-icon {
    color: var(--color-text-tertiary);
}

.drop-text {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    margin: 0;
}

.drop-text-primary {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.drop-text-secondary {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.drop-hint {
    font-size: 0.6875rem;
    color: var(--color-text-quaternary);
    margin: 0;
}

.upload-progress {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 0;
}

.progress-bar {
    width: 100%;
    height: 4px;
    background: var(--color-bg-elevated);
    border-radius: 2px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-accent);
    transition: width 0.2s ease;
}

.progress-text {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.loading-state {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 1rem;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.loading-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid var(--color-border-default);
    border-top-color: var(--color-accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.asset-list {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    max-height: 240px;
    overflow-y: auto;
}

.asset-item {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.5rem;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    transition: background 0.15s ease;
}

.asset-item:hover {
    background: var(--color-bg-elevated);
}

.asset-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 6px;
    flex-shrink: 0;
}

.asset-icon.icon-image {
    background: rgba(59, 130, 246, 0.15);
    color: #3b82f6;
}

.asset-icon.icon-pdf {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.asset-icon.icon-document {
    background: rgba(34, 197, 94, 0.15);
    color: #22c55e;
}

.asset-icon.icon-video {
    background: rgba(168, 85, 247, 0.15);
    color: #a855f7;
}

.asset-icon.icon-audio {
    background: rgba(249, 115, 22, 0.15);
    color: #f97316;
}

.asset-icon.icon-file {
    background: rgba(107, 114, 128, 0.15);
    color: #6b7280;
}

.asset-info {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.asset-name {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.asset-meta {
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.asset-category {
    background: var(--color-bg-elevated);
    padding: 0.0625rem 0.375rem;
    border-radius: 4px;
    text-transform: capitalize;
}

.asset-delete {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.asset-delete:hover:not(:disabled) {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.asset-delete:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.empty-state {
    text-align: center;
    padding: 1rem;
    color: var(--color-text-tertiary);
}

.empty-state p {
    margin: 0;
    font-size: 0.75rem;
}

.empty-hint {
    margin-top: 0.25rem !important;
    font-size: 0.6875rem !important;
    color: var(--color-text-quaternary) !important;
}

.asset-list::-webkit-scrollbar {
    width: 4px;
}

.asset-list::-webkit-scrollbar-track {
    background: transparent;
}

.asset-list::-webkit-scrollbar-thumb {
    background: var(--color-border-strong);
    border-radius: 2px;
}
</style>
