<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import ProjectFormModal from '@/Components/ProjectFormModal.vue';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Client {
    id: number;
    name: string;
    slug: string;
}

interface TeamMember {
    id: number;
    name: string;
    role: string;
}

interface Project {
    id: number;
    name: string;
    slug: string;
    description?: string;
    status: 'active' | 'completed' | 'on_hold' | 'cancelled' | 'archived';
    type: 'project' | 'retainer' | 'time_materials';
    budget: number;
    hourly_rate?: number;
    estimated_hours?: number;
    start_date?: string;
    end_date?: string;
    client: Client | null;
    tasks_count: number;
    completed_tasks_count: number;
    created_at: string;
    harvest_project_id?: number | null;
}

interface Stats {
    total: number;
    active: number;
    archived: number;
    completed: number;
    total_budget: number;
}

interface ClientGroup {
    client_name: string;
    client_slug: string | null;
    projects: Project[];
}

const props = defineProps<{
    projects: Project[];
    projectsByClient: ClientGroup[];
    stats: Stats;
    showArchived?: boolean;
    clients?: Client[];
    teamMembers?: TeamMember[];
}>();

// Toggle archived view
const toggleArchived = () => {
    router.get('/projects', { archived: !props.showArchived ? 1 : undefined }, { preserveState: true });
};

// Modal State
const showProjectModal = ref(false);
const showDeleteModal = ref(false);
const selectedProject = ref<Project | null>(null);
const isSubmitting = ref(false);

const openNewProjectModal = () => {
    selectedProject.value = null;
    showProjectModal.value = true;
};

const openEditProjectModal = (project: Project, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    selectedProject.value = project;
    showProjectModal.value = true;
};

const openDeleteModal = (project: Project, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    selectedProject.value = project;
    showDeleteModal.value = true;
};

const handleProjectModalClose = () => {
    showProjectModal.value = false;
    selectedProject.value = null;
};

const deleteProject = () => {
    if (!selectedProject.value) return;
    isSubmitting.value = true;

    router.delete(`/projects/${selectedProject.value.id}`, {
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedProject.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const syncingProjectId = ref<number | null>(null);

const syncToHarvest = (project: Project, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    syncingProjectId.value = project.id;

    router.post(`/projects/${project.id}/sync-harvest`, {}, {
        onFinish: () => {
            syncingProjectId.value = null;
        },
    });
};

const formatCurrency = (value: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
    }).format(value);
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        active: 'badge-green',
        completed: 'badge-blue',
        on_hold: 'badge-yellow',
        cancelled: 'badge-red',
    };
    return badges[status] || 'badge-gray';
};

const getTypeBadge = (type: string) => {
    const badges: Record<string, string> = {
        retainer: 'badge-blue',
        time_materials: 'badge-purple',
        project: 'badge-gray',
    };
    return badges[type] || 'badge-gray';
};

const getTypeLabel = (type: string) => {
    const labels: Record<string, string> = {
        project: 'Fixed-price',
        time_materials: 'T&M',
        retainer: 'Retainer',
    };
    return labels[type] || type;
};

const getProgress = (project: Project) => {
    if (!project.tasks_count) return 0;
    return Math.round((project.completed_tasks_count / project.tasks_count) * 100);
};
</script>

<template>
    <AppLayout title="Projects">
        <!-- Stats -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL PROJECTS</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE</div>
                <div class="metric-value">{{ stats.active }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">COMPLETED</div>
                <div class="metric-value">{{ stats.completed }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL BUDGET</div>
                <div class="metric-value">{{ formatCurrency(stats.total_budget) }}</div>
            </div>
        </div>

        <!-- Projects List -->
        <div class="card">
            <div class="card-header flex-col gap-3 sm:flex-row">
                <span class="card-title">{{ showArchived ? 'Archived Projects' : 'All Projects' }}</span>
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <button
                        class="btn btn-secondary flex-1 sm:flex-initial"
                        @click="toggleArchived"
                    >
                        <svg v-if="!showArchived" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                        </svg>
                        <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                        </svg>
                        <span class="hidden sm:inline">{{ showArchived ? 'View Active' : `Archived (${stats.archived})` }}</span>
                        <span class="sm:hidden">{{ showArchived ? 'Active' : `Archived` }}</span>
                    </button>
                    <button v-if="!showArchived" class="btn btn-primary flex-1 sm:flex-initial" @click="openNewProjectModal">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        <span class="hidden sm:inline">New Project</span>
                        <span class="sm:hidden">New</span>
                    </button>
                </div>
            </div>

            <div class="card-body">
                <template v-for="(clientGroup, groupIndex) in projectsByClient" :key="clientGroup.client_name">
                    <!-- Client Group Section -->
                    <div :class="['client-group', { 'client-group--subsequent': groupIndex > 0 }]">
                        <div class="client-group-header">
                            <div class="client-group-accent"></div>
                            <div class="client-group-info">
                                <Link
                                    v-if="clientGroup.client_slug"
                                    :href="`/clients/${clientGroup.client_slug}`"
                                    class="client-group-title"
                                >
                                    {{ clientGroup.client_name }}
                                    <svg class="client-group-arrow" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </Link>
                                <span v-else class="client-group-title">{{ clientGroup.client_name }}</span>
                                <span class="client-group-count">{{ clientGroup.projects.length }} {{ clientGroup.projects.length === 1 ? 'project' : 'projects' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Projects in this group -->
                    <div
                        v-for="project in clientGroup.projects"
                        :key="project.id"
                        class="list-item group"
                    >
                    <!-- Mobile Layout -->
                    <div class="md:hidden w-full">
                        <Link :href="`/clients/${project.client?.slug}/projects/${project.slug}`" class="block">
                            <div class="flex items-start justify-between gap-2 mb-2">
                                <div class="flex-1 min-w-0">
                                    <div class="list-item-title truncate">{{ project.name }}</div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span :class="['badge badge-sm', getStatusBadge(project.status)]">{{ project.status.replace('_', ' ') }}</span>
                                        <span :class="['badge badge-sm', getTypeBadge(project.type)]">{{ getTypeLabel(project.type) }}</span>
                                    </div>
                                </div>
                                <svg class="h-4 w-4 flex-shrink-0 mt-1" style="color: var(--color-text-quaternary)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                            <div class="list-item-subtitle mb-3">
                                {{ project.client?.name ?? 'No Client' }} · {{ formatCurrency(project.budget) }}
                            </div>
                            <div class="flex items-center gap-4">
                                <div class="flex-1">
                                    <div class="progress-bar">
                                        <div class="progress-bar-fill" :style="{ width: getProgress(project) + '%' }"></div>
                                    </div>
                                </div>
                                <span class="text-mono text-caption whitespace-nowrap">{{ getProgress(project) }}% · {{ project.completed_tasks_count }}/{{ project.tasks_count }} tasks</span>
                            </div>
                        </Link>
                        <div class="flex items-center gap-2 mt-3 pt-3" style="border-top: 1px solid var(--color-border-subtle)">
                            <button
                                class="btn btn-secondary btn-sm flex-1"
                                @click="openEditProjectModal(project, $event)"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                </svg>
                                Edit
                            </button>
                            <button
                                v-if="!project.harvest_project_id"
                                class="btn btn-secondary btn-sm"
                                :disabled="syncingProjectId === project.id"
                                @click="syncToHarvest(project, $event)"
                                title="Sync to Harvest"
                            >
                                <svg v-if="syncingProjectId === project.id" class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                            </button>
                            <button
                                class="btn btn-secondary btn-sm"
                                @click="openDeleteModal(project, $event)"
                                style="color: var(--color-status-red)"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Desktop Layout -->
                    <Link :href="`/clients/${project.client?.slug}/projects/${project.slug}`" class="list-item-content flex-1 hidden md:block">
                        <div class="flex items-center gap-3">
                            <div class="list-item-title">{{ project.name }}</div>
                            <span :class="['badge', getStatusBadge(project.status)]">{{ project.status.replace('_', ' ') }}</span>
                            <span :class="['badge', getTypeBadge(project.type)]">{{ getTypeLabel(project.type) }}</span>
                        </div>
                        <div class="list-item-subtitle">
                            {{ project.client?.name ?? 'No Client' }} · {{ formatCurrency(project.budget) }} budget
                        </div>
                    </Link>

                    <div class="hidden md:flex items-center gap-6">
                        <!-- Progress -->
                        <div class="w-32">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-caption">Progress</span>
                                <span class="text-mono text-caption">{{ getProgress(project) }}%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-bar-fill" :style="{ width: getProgress(project) + '%' }"></div>
                            </div>
                        </div>

                        <!-- Tasks -->
                        <div class="text-right">
                            <div class="text-mono" style="color: var(--color-text-primary)">{{ project.completed_tasks_count }}/{{ project.tasks_count }}</div>
                            <div class="text-caption">tasks</div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <button
                                class="btn btn-ghost btn-sm"
                                @click="openEditProjectModal(project, $event)"
                                title="Edit project"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                </svg>
                            </button>
                            <button
                                v-if="!project.harvest_project_id"
                                class="btn btn-ghost btn-sm"
                                :disabled="syncingProjectId === project.id"
                                @click="syncToHarvest(project, $event)"
                                title="Sync to Harvest"
                            >
                                <svg v-if="syncingProjectId === project.id" class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                </svg>
                                <svg v-else class="h-4 w-4" style="color: var(--color-status-orange)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                            </button>
                            <button
                                class="btn btn-ghost btn-sm"
                                @click="openDeleteModal(project, $event)"
                                title="Delete project"
                            >
                                <svg class="h-4 w-4" style="color: var(--color-status-red)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </button>
                        </div>

                        <Link :href="`/clients/${project.client?.slug}/projects/${project.slug}`" class="btn btn-ghost">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </Link>
                    </div>
                </div>
                </template>

                <div v-if="projectsByClient.length === 0" class="p-8 text-center">
                    <div class="avatar avatar-lg avatar-muted mx-auto mb-4">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z" />
                        </svg>
                    </div>
                    <p class="text-body">No projects yet</p>
                    <p class="text-caption mt-1 mb-4">Create your first project to get started</p>
                    <button class="btn btn-primary" @click="openNewProjectModal">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        New Project
                    </button>
                </div>
            </div>
        </div>

        <!-- New/Edit Project Modal -->
        <ProjectFormModal
            :show="showProjectModal"
            :clients="clients || []"
            :project="selectedProject"
            @close="handleProjectModalClose"
        />

        <!-- Delete Confirmation Modal -->
        <Modal
            :show="showDeleteModal"
            title="Delete Project"
            size="sm"
            @close="showDeleteModal = false"
        >
            <div v-if="selectedProject" class="space-y-4">
                <p class="text-body">
                    Are you sure you want to delete <strong>{{ selectedProject.name }}</strong>?
                </p>
                <div class="warning-box">
                    <svg class="h-5 w-5 flex-shrink-0" style="color: var(--color-status-red)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    <div>
                        <p class="text-body" style="color: var(--color-status-red)">This action cannot be undone</p>
                        <p class="text-caption">All tasks, time entries, and files associated with this project will be permanently deleted.</p>
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
                    @click="deleteProject"
                >
                    {{ isSubmitting ? 'Deleting...' : 'Delete Project' }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
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
    padding: 6px 8px;
}

.list-item {
    display: flex;
    align-items: center;
}

.client-group {
    margin-top: 0;
}

.client-group--subsequent {
    margin-top: 0;
}

.client-group-header {
    display: flex;
    align-items: stretch;
    background: linear-gradient(to right, var(--color-bg-tertiary), var(--color-bg-secondary));
    border-bottom: 1px solid var(--color-border-subtle);
    overflow: hidden;
}

.client-group:first-child .client-group-header {
    border-radius: 8px 8px 0 0;
}

.client-group-accent {
    width: 4px;
    background: var(--color-accent);
    flex-shrink: 0;
}

.client-group-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    flex: 1;
}

.client-group-title {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    text-decoration: none;
    transition: color 0.15s ease;
}

.client-group-title:hover {
    color: var(--color-accent);
}

.client-group-arrow {
    width: 0.875rem;
    height: 0.875rem;
    opacity: 0;
    transform: translateX(-4px);
    transition: all 0.15s ease;
}

.client-group-title:hover .client-group-arrow {
    opacity: 0.6;
    transform: translateX(0);
}

.client-group-count {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-tertiary);
    background: var(--color-bg-primary);
    padding: 0.25rem 0.625rem;
    border-radius: 9999px;
    border: 1px solid var(--color-border-subtle);
}
</style>
