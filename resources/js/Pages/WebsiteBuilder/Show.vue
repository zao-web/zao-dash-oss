<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ChatInterface from '@/Components/SiteBuilder/ChatInterface.vue';
import AssetUploader from '@/Components/WebsiteBuilder/AssetUploader.vue';
import { useWebsiteBuilder } from '@/composables/useWebsiteBuilder';

interface User {
    id: number;
    name: string;
}

interface WebsiteProject {
    id: number;
    name: string;
    slug: string;
    project_type: 'autonomous' | 'guided' | 'migration' | 'redesign';
    source_type: string;
    domain: string;
    status: string;
    overall_progress: number;
    environment: string;
    staging_url?: string;
    production_url?: string;
    last_error?: string;
    cost_incurred?: number;
    budget_allocated?: number;
    created_at: string;
    updated_at: string;
    user: User;
}

const props = defineProps<{
    project: WebsiteProject;
}>();

const {
    messages,
    isStreaming,
    currentStreamingContent,
    error,
    sendMessage,
    retryMessage,
    phases,
    overallProgress,
    isConnected,
    isComplete,
    hasFailed,
    buildState,
} = useWebsiteBuilder(props.project.id);

const sidebarCollapsed = ref(false);

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        created: 'badge-gray',
        analyzing: 'badge-blue',
        designing: 'badge-yellow',
        building: 'badge-purple',
        reviewing: 'badge-yellow',
        deploying: 'badge-blue',
        complete: 'badge-green',
        failed: 'badge-red',
    };
    return badges[status] || 'badge-gray';
};

const getTypeBadge = (type: string) => {
    const badges: Record<string, string> = {
        autonomous: 'badge-purple',
        guided: 'badge-blue',
        migration: 'badge-yellow',
        redesign: 'badge-green',
    };
    return badges[type] || 'badge-gray';
};

const getEnvironmentBadge = (env: string) => {
    return env === 'production' ? 'badge-green' : 'badge-yellow';
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
};

const currentLiveUrl = computed(() => {
    return buildState.value.stagingUrl || buildState.value.productionUrl || props.project.staging_url || props.project.production_url;
});

const progressPercentage = computed(() => {
    // Prefer real-time values: buildState (direct from websocket) > computed overallProgress > props
    return buildState.value.progress || overallProgress.value || props.project.overall_progress || 0;
});

const budgetProgress = computed(() => {
    if (!props.project.budget_allocated) return 0;
    return Math.min(100, (props.project.cost_incurred || 0) / props.project.budget_allocated * 100);
});

const isOverBudget = computed(() => {
    if (!props.project.budget_allocated) return false;
    return (props.project.cost_incurred || 0) > props.project.budget_allocated;
});

const formatCurrency = (amount: number | undefined) => {
    if (!amount) return '$0.00';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const handleSendMessage = (message: string) => {
    sendMessage(message);
};

const handleRetryMessage = (messageId: string) => {
    retryMessage(messageId);
};

// Computed: Determine which view component to show
const showAutonomousView = computed(() => props.project.project_type === 'autonomous');
const showGuidedView = computed(() => props.project.project_type === 'guided');
const showMigrationView = computed(() => props.project.project_type === 'migration');
const showRedesignView = computed(() => props.project.project_type === 'redesign');
</script>

<template>
    <AppLayout :title="project.name">
        <div class="website-builder-show">
            <!-- Header -->
            <header class="page-header">
                <div class="header-left">
                    <Link href="/website-builder" class="back-button">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                    </Link>
                    <div class="header-info">
                        <div class="header-title-row">
                            <h1 class="page-title">{{ project.name }}</h1>
                            <span :class="['badge', getTypeBadge(project.project_type)]">
                                {{ project.project_type }}
                            </span>
                            <span :class="['badge', getStatusBadge(buildState.status || project.status)]">
                                {{ (buildState.status || project.status).replace('_', ' ') }}
                            </span>
                            <span :class="['badge', getEnvironmentBadge(project.environment)]">
                                {{ project.environment }}
                            </span>
                        </div>
                        <p v-if="project.domain" class="page-subtitle">{{ project.domain }}</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a
                        v-if="currentLiveUrl"
                        :href="currentLiveUrl"
                        target="_blank"
                        class="btn btn-secondary"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        Preview Site
                    </a>
                    <button class="btn btn-ghost" @click="sidebarCollapsed = !sidebarCollapsed">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <line x1="9" y1="3" x2="9" y2="21"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Main Content -->
            <div class="main-content" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
                <!-- Content Panel (Adaptive based on project type) -->
                <div class="content-panel">
                    <!-- Autonomous View: Chat Interface -->
                    <div v-if="showAutonomousView" class="view-autonomous">
                        <ChatInterface
                            :messages="messages"
                            :is-streaming="isStreaming"
                            :current-streaming-content="currentStreamingContent"
                            :phases="phases"
                            :overall-progress="progressPercentage"
                            :is-connected="isConnected"
                            :error="error"
                            @send="handleSendMessage"
                            @retry="handleRetryMessage"
                        />
                    </div>

                    <!-- Guided View: Wizard/Stepper Interface -->
                    <div v-else-if="showGuidedView" class="view-guided">
                        <div class="guided-placeholder">
                            <div class="placeholder-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <h3 class="placeholder-title">Guided Builder</h3>
                            <p class="placeholder-text">
                                Step-by-step wizard interface for creating WordPress sites with full control over each decision.
                            </p>
                            <p class="placeholder-note">This view is under construction. The guided workflow will include: Brief Upload → Design Configuration → Page Selection → Pattern Composition → Build & Deploy</p>
                        </div>
                    </div>

                    <!-- Migration View: Analysis & Migration Planning -->
                    <div v-else-if="showMigrationView" class="view-migration">
                        <div class="migration-placeholder">
                            <div class="placeholder-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M8 7h12M8 12h12M8 17h12M3 7h.01M3 12h.01M3 17h.01"/>
                                </svg>
                            </div>
                            <h3 class="placeholder-title">Migration Analyzer</h3>
                            <p class="placeholder-text">
                                Deep site analysis with content extraction, URL mapping, and automated migration planning.
                            </p>
                            <p class="placeholder-note">This view will show: Site Structure Analysis → Content Extraction → SEO Preservation → Migration Preview → Execute Migration</p>
                        </div>
                    </div>

                    <!-- Redesign View: Content Preservation + New Design -->
                    <div v-else-if="showRedesignView" class="view-redesign">
                        <div class="redesign-placeholder">
                            <div class="placeholder-icon">
                                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <h3 class="placeholder-title">Redesign Studio</h3>
                            <p class="placeholder-text">
                                Preserve existing content while applying fresh designs, patterns, and layouts.
                            </p>
                            <p class="placeholder-note">This view will feature: Content Import → Design Selection → Layout Customization → Brand Updates → Preview & Deploy</p>
                        </div>
                    </div>
                </div>

                <!-- Sidebar (Project Details) -->
                <aside class="details-sidebar" :class="{ collapsed: sidebarCollapsed }">
                    <!-- Quick Stats -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Progress</h3>
                        <div class="progress-visual">
                            <svg class="progress-circle" width="80" height="80">
                                <circle
                                    cx="40"
                                    cy="40"
                                    r="36"
                                    fill="none"
                                    stroke="var(--color-bg-elevated)"
                                    stroke-width="8"
                                />
                                <circle
                                    cx="40"
                                    cy="40"
                                    r="36"
                                    fill="none"
                                    stroke="var(--color-accent)"
                                    stroke-width="8"
                                    stroke-dasharray="226"
                                    :stroke-dashoffset="226 - (226 * progressPercentage / 100)"
                                    stroke-linecap="round"
                                    transform="rotate(-90 40 40)"
                                />
                                <text x="40" y="46" text-anchor="middle" font-size="18" font-weight="700" fill="var(--color-text-primary)" font-family="system-ui, -apple-system, sans-serif">
                                    {{ progressPercentage }}%
                                </text>
                            </svg>
                        </div>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <span class="stat-value">{{ phases.filter(p => p.status === 'completed').length }}/{{ phases.length }}</span>
                                <span class="stat-label">Phases</span>
                            </div>
                            <div v-if="project.budget_allocated" class="stat-item">
                                <span class="stat-value" :class="{ 'text-warning': isOverBudget }">
                                    {{ formatCurrency(project.cost_incurred) }}
                                </span>
                                <span class="stat-label">of {{ formatCurrency(project.budget_allocated) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Asset Uploader -->
                    <div class="sidebar-section">
                        <AssetUploader 
                            :project-id="project.id"
                            :disabled="project.status !== 'created' && project.status !== 'failed'"
                        />
                    </div>

                    <!-- Project Details -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Project Details</h3>
                        <div class="detail-list">
                            <div v-if="project.domain" class="detail-item">
                                <span class="detail-label">Domain</span>
                                <span class="detail-value">{{ project.domain }}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Type</span>
                                <span class="detail-value capitalize">{{ project.project_type }}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Source</span>
                                <span class="detail-value capitalize">{{ project.source_type }}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Created</span>
                                <span class="detail-value">{{ formatDate(project.created_at) }}</span>
                            </div>
                            <div v-if="project.updated_at !== project.created_at" class="detail-item">
                                <span class="detail-label">Last Updated</span>
                                <span class="detail-value">{{ formatDate(project.updated_at) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Live URL -->
                    <div v-if="currentLiveUrl" class="sidebar-section">
                        <h3 class="sidebar-title">Live Site</h3>
                        <a :href="currentLiveUrl" target="_blank" class="live-url-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>
                            </svg>
                            {{ currentLiveUrl }}
                        </a>
                    </div>

                    <!-- Error State -->
                    <div v-if="project.last_error || hasFailed" class="sidebar-section error-section">
                        <h3 class="sidebar-title error-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            Error
                        </h3>
                        <p class="error-text">{{ project.last_error || buildState.error || 'Build failed. Check the logs for details.' }}</p>
                    </div>

                    <!-- Actions -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Actions</h3>
                        <div class="action-buttons">
                            <button class="btn btn-outline btn-sm" disabled>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                                    <polyline points="7 10 12 15 17 10"/>
                                    <line x1="12" y1="15" x2="12" y2="3"/>
                                </svg>
                                Download Theme
                            </button>
                            <button v-if="project.status === 'reviewing'" class="btn btn-primary btn-sm" disabled>
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/>
                                    <polyline points="22 4 12 14.01 9 11.01"/>
                                </svg>
                                Deploy to Production
                            </button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.website-builder-show {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 64px);
    overflow: hidden;
}

/* Header */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.5rem;
    background: var(--color-bg-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
    flex-shrink: 0;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.back-button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    color: var(--color-text-secondary);
    transition: all 0.15s ease;
}

.back-button:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.header-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.header-title-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.page-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.page-subtitle {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin: 0;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Main Content */
.main-content {
    display: grid;
    grid-template-columns: 1fr 320px;
    flex: 1;
    overflow: hidden;
    transition: grid-template-columns 0.3s ease;
}

.main-content.sidebar-collapsed {
    grid-template-columns: 1fr 0;
}

/* Content Panel */
.content-panel {
    overflow: hidden;
    border-right: 1px solid var(--color-border-subtle);
}

.view-autonomous {
    height: 100%;
}

/* Placeholder Views (for guided, migration, redesign) */
.view-guided,
.view-migration,
.view-redesign {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem;
}

.guided-placeholder,
.migration-placeholder,
.redesign-placeholder {
    max-width: 480px;
    text-align: center;
}

.placeholder-icon {
    width: 96px;
    height: 96px;
    margin: 0 auto 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(236, 72, 153, 0.1) 100%);
    border-radius: 24px;
    color: var(--color-accent);
}

.placeholder-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.75rem;
}

.placeholder-text {
    font-size: 1rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
    margin-bottom: 1rem;
}

.placeholder-note {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
    line-height: 1.5;
    padding: 1rem;
    background: var(--color-bg-secondary);
    border-radius: 8px;
    border-left: 3px solid var(--color-accent);
}

/* Sidebar */
.details-sidebar {
    display: flex;
    flex-direction: column;
    gap: 0;
    background: var(--color-bg-secondary);
    overflow-y: auto;
    overflow-x: hidden;
    transition: all 0.3s ease;
}

.details-sidebar.collapsed {
    width: 0;
    opacity: 0;
    pointer-events: none;
}

.sidebar-section {
    padding: 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.sidebar-section:last-child {
    border-bottom: none;
}

.sidebar-title {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin: 0 0 0.875rem 0;
}

/* Progress Circle */
.progress-visual {
    display: flex;
    justify-content: center;
    margin-bottom: 1rem;
}

.progress-visual {
    display: flex;
    justify-content: center;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
}

.stat-value {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.stat-value.text-warning {
    color: var(--color-status-red);
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* Detail List */
.detail-list {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.detail-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.detail-value {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.capitalize {
    text-transform: capitalize;
}

/* Live URL */
.live-url-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    font-size: 0.75rem;
    color: var(--color-accent);
    text-decoration: none;
    transition: all 0.15s ease;
    word-break: break-all;
}

.live-url-link:hover {
    background: var(--color-bg-elevated);
}

/* Error Section */
.error-section {
    background: rgba(239, 68, 68, 0.05);
}

.error-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-status-red);
}

.error-text {
    font-size: 0.8125rem;
    color: var(--color-status-red);
    line-height: 1.5;
    margin: 0;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.5rem 0.875rem;
    font-size: 0.8125rem;
}

/* Responsive */
@media (max-width: 1024px) {
    .main-content {
        grid-template-columns: 1fr;
    }
    
    .details-sidebar {
        position: fixed;
        right: 0;
        top: 64px;
        bottom: 0;
        width: 320px;
        z-index: 50;
        transform: translateX(100%);
        box-shadow: -4px 0 20px rgba(0, 0, 0, 0.2);
    }
    
    .details-sidebar:not(.collapsed) {
        transform: translateX(0);
    }
    
    .content-panel {
        border-right: none;
    }
}

@media (max-width: 640px) {
    .page-header {
        padding: 0.75rem 1rem;
    }
    
    .header-title-row {
        gap: 0.5rem;
    }
    
    .page-title {
        font-size: 1rem;
    }
    
    .details-sidebar {
        width: 100%;
    }
}

/* Scrollbar */
.details-sidebar::-webkit-scrollbar {
    width: 4px;
}

.details-sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.details-sidebar::-webkit-scrollbar-thumb {
    background: var(--color-border-strong);
    border-radius: 2px;
}
</style>
