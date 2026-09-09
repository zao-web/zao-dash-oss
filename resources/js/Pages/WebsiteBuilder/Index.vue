<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

interface WebsiteProject {
    id: number;
    name: string;
    slug: string;
    domain: string | null;
    project_type: 'autonomous' | 'guided' | 'migration' | 'redesign';
    status: string;
    overall_progress: number;
    staging_url: string | null;
    production_url: string | null;
    created_at: string;
}

interface ProjectMode {
    type: 'autonomous' | 'guided' | 'migration' | 'redesign';
    icon: string;
    title: string;
    description: string;
    recommendedFor: string;
    color: string;
}

interface WordPressSite {
    id: number;
    name: string;
    url: string;
    is_primary: boolean;
}

const modes: ProjectMode[] = [
    {
        type: 'autonomous',
        icon: '⚡',
        title: 'Autonomous Build',
        description: 'Provide a domain and brief. AI builds everything automatically with real-time updates.',
        recommendedFor: 'Clients, quick turnaround, standard business sites',
        color: 'purple',
    },
    {
        type: 'guided',
        icon: '🎨',
        title: 'Guided Design',
        description: 'Step-by-step wizard with full control over design choices and pattern selection.',
        recommendedFor: 'Designers, agencies, custom requirements',
        color: 'blue',
    },
    {
        type: 'migration',
        icon: '🔄',
        title: 'Site Migration',
        description: 'Migrate from any platform to WordPress with AI-powered content analysis.',
        recommendedFor: 'Existing WordPress/Joomla/static sites',
        color: 'green',
    },
    {
        type: 'redesign',
        icon: '✨',
        title: 'Redesign',
        description: 'Keep existing content, apply modern Ollie theme with new design.',
        recommendedFor: 'WordPress sites needing fresh look',
        color: 'orange',
    },
];

const props = defineProps<{
    projects: WebsiteProject[];
    wordpressSites: WordPressSite[];
}>();

const selectedMode = ref<ProjectMode['type'] | null>(null);
const showCreateForm = ref(false);
const deleting = ref<Record<number, boolean>>({});
const restarting = ref<Record<number, boolean>>({});

const form = ref({
    name: '',
    domain: '',
    brief: '',
    url: '',
    github_url: '',
    wordpress_site_id: null as number | null,
    hosting_type: 'existing_site',
    environment: 'staging',
    budget_limit: 50,
});

const loading = ref(false);
const errors = ref<Record<string, string[]>>({});

const selectedModeData = computed(() => {
    return modes.find(m => m.type === selectedMode.value);
});

const selectMode = (mode: ProjectMode['type']) => {
    selectedMode.value = mode;
    showCreateForm.value = true;
};

const createProject = async () => {
    if (!selectedMode.value) return;

    loading.value = true;
    errors.value = {};

    try {
        const response = await fetch('/api/website-builder/projects', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({
                ...form.value,
                project_type: selectedMode.value,
                source_type: selectedMode.value === 'migration' || selectedMode.value === 'redesign' 
                    ? 'url' 
                    : selectedMode.value === 'guided' 
                        ? 'brief' 
                        : 'domain',
            }),
        });

        const data = await response.json();

        if (data.success && data.project) {
            router.visit(`/website-builder/${data.project.id}`);
        } else {
            errors.value = data.errors || {};
        }
    } catch (error: any) {
        console.error('Failed to create project:', error);
        errors.value = { general: ['Failed to create project. Please try again.'] };
    } finally {
        loading.value = false;
    }
};

const resetForm = () => {
    selectedMode.value = null;
    showCreateForm.value = false;
    form.value = {
        name: '',
        domain: '',
        brief: '',
        url: '',
        github_url: '',
        wordpress_site_id: null,
        hosting_type: 'existing_site',
        environment: 'staging',
        budget_limit: 50,
    };
    errors.value = {};
};

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
        migration: 'badge-green',
        redesign: 'badge-yellow',
    };
    return badges[type] || 'badge-gray';
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const canRestart = (project: WebsiteProject) => {
    return project.status === 'failed' || project.status === 'created';
};

const deleteProject = async (project: WebsiteProject) => {
    if (!confirm(`Delete "${project.name}"? This cannot be undone.`)) return;

    deleting.value[project.id] = true;
    try {
        const response = await fetch(`/api/website-builder/projects/${project.id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        const data = await response.json();
        if (data.success) {
            router.reload();
        }
    } catch (error) {
        console.error('Delete failed:', error);
    } finally {
        deleting.value[project.id] = false;
    }
};

const restartProject = async (project: WebsiteProject) => {
    restarting.value[project.id] = true;
    try {
        const response = await fetch(`/api/website-builder/projects/${project.id}/restart`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        const data = await response.json();
        if (data.success) {
            router.visit(`/website-builder/${project.id}`);
        }
    } catch (error) {
        console.error('Restart failed:', error);
    } finally {
        restarting.value[project.id] = false;
    }
};
</script>

<template>
    <AppLayout title="Website Builder">
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">Website Builder</h1>
                <p class="text-caption">Create professional WordPress websites with AI assistance</p>
            </div>
        </div>

        <!-- Mode Selection -->
        <div v-if="!showCreateForm" class="mode-selector">
            <h2 class="section-title">Choose Your Build Mode</h2>
            <div class="modes-grid">
                <button
                    v-for="mode in modes"
                    :key="mode.type"
                    @click="selectMode(mode.type)"
                    class="mode-card"
                    :class="`mode-card-${mode.color}`"
                >
                    <div class="mode-icon">{{ mode.icon }}</div>
                    <h3 class="mode-title">{{ mode.title }}</h3>
                    <p class="mode-description">{{ mode.description }}</p>
                    <div class="mode-recommended">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        <span>{{ mode.recommendedFor }}</span>
                    </div>
                </button>
            </div>

            <!-- Existing Projects -->
            <div v-if="projects.length > 0" class="projects-section">
                <h2 class="section-title">Your Projects</h2>
                <div class="card">
                    <div
                        v-for="project in projects"
                        :key="project.id"
                        class="project-row"
                    >
                        <div class="project-info">
                            <div class="project-header">
                                <span class="project-name">{{ project.name }}</span>
                                <span :class="['badge', getTypeBadge(project.project_type)]">
                                    {{ project.project_type }}
                                </span>
                                <span :class="['badge', getStatusBadge(project.status)]">
                                    {{ project.status.replace('_', ' ') }}
                                </span>
                            </div>
                            <div class="project-meta">
                                <span v-if="project.domain">{{ project.domain }}</span>
                                <span v-if="project.domain"> &middot; </span>
                                <span>{{ formatDate(project.created_at) }}</span>
                            </div>
                        </div>

                        <div class="project-progress">
                            <div class="progress-label">{{ project.overall_progress }}%</div>
                            <div class="progress-bar">
                                <div
                                    class="progress-fill"
                                    :style="{ width: project.overall_progress + '%' }"
                                ></div>
                            </div>
                        </div>

                        <div class="project-actions">
                            <Link
                                :href="`/website-builder/${project.id}`"
                                class="btn btn-secondary btn-sm"
                            >
                                View
                            </Link>
                            <button
                                v-if="canRestart(project)"
                                class="btn btn-secondary btn-sm"
                                :disabled="restarting[project.id]"
                                @click="restartProject(project)"
                            >
                                {{ restarting[project.id] ? 'Restarting...' : 'Restart' }}
                            </button>
                            <button
                                class="btn btn-ghost btn-sm text-red"
                                :disabled="deleting[project.id]"
                                @click="deleteProject(project)"
                            >
                                {{ deleting[project.id] ? 'Deleting...' : 'Delete' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Project Creation Form -->
        <div v-else class="card">
            <div class="card-header">
                <div class="flex items-center gap-3">
                    <span class="text-2xl">{{ selectedModeData?.icon }}</span>
                    <div>
                        <h2 class="card-title">{{ selectedModeData?.title }}</h2>
                        <p class="text-caption">{{ selectedModeData?.description }}</p>
                    </div>
                </div>
                <button @click="resetForm" class="btn btn-ghost btn-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    Change Mode
                </button>
            </div>

            <div class="card-body p-6">
                <form @submit.prevent="createProject" class="space-y-6">
                    <!-- Common Fields -->
                    <div>
                        <label class="form-label">Project Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            class="form-input"
                            placeholder="Acme Corp Website"
                            required
                        />
                        <p v-if="errors.name" class="form-error">{{ errors.name[0] }}</p>
                    </div>

                    <!-- Autonomous/Guided: Domain + Brief -->
                    <template v-if="selectedMode === 'autonomous' || selectedMode === 'guided'">
                        <div>
                            <label class="form-label">Domain Name</label>
                            <input
                                v-model="form.domain"
                                type="text"
                                class="form-input"
                                placeholder="example.com"
                            />
                            <p v-if="errors.domain" class="form-error">{{ errors.domain[0] }}</p>
                        </div>

                        <div>
                            <label class="form-label">Project Brief</label>
                            <textarea
                                v-model="form.brief"
                                class="form-input"
                                rows="6"
                                placeholder="Describe the company, target audience, key features, and any specific requirements..."
                                required
                            ></textarea>
                            <p v-if="errors.brief" class="form-error">{{ errors.brief[0] }}</p>
                            <p class="form-hint">
                                {{ selectedMode === 'autonomous' 
                                    ? 'AI will build the site automatically based on this brief' 
                                    : 'AI will analyze and suggest patterns for you to review'
                                }}
                            </p>
                        </div>
                    </template>

                    <!-- Migration/Redesign: Source URL -->
                    <template v-if="selectedMode === 'migration' || selectedMode === 'redesign'">
                        <div>
                            <label class="form-label">Source Website URL</label>
                            <input
                                v-model="form.url"
                                type="url"
                                class="form-input"
                                placeholder="https://current-site.com"
                                required
                            />
                            <p v-if="errors.url" class="form-error">{{ errors.url[0] }}</p>
                            <p class="form-hint">
                                {{ selectedMode === 'migration' 
                                    ? 'AI will analyze and migrate content from this site' 
                                    : 'AI will preserve content and apply modern Ollie design'
                                }}
                            </p>
                        </div>

                        <div>
                            <label class="form-label">Migration Notes (Optional)</label>
                            <textarea
                                v-model="form.brief"
                                class="form-input"
                                rows="4"
                                placeholder="Any specific requirements or pages to preserve..."
                            ></textarea>
                        </div>
                    </template>

                    <!-- WordPress Site Selection -->
                    <div v-if="wordpressSites.length > 0">
                        <label class="form-label">Deploy to WordPress Site</label>
                        <select v-model="form.wordpress_site_id" class="form-select">
                            <option :value="null">Select a staging site...</option>
                            <option 
                                v-for="site in wordpressSites" 
                                :key="site.id" 
                                :value="site.id"
                            >
                                {{ site.name }} ({{ site.url }}){{ site.is_primary ? ' - Primary' : '' }}
                            </option>
                        </select>
                        <p v-if="errors.wordpress_site_id" class="form-error">{{ errors.wordpress_site_id[0] }}</p>
                        <p class="form-hint">
                            The AI will deploy pages directly to this WordPress site's staging environment.
                        </p>
                    </div>
                    <div v-else class="p-4 rounded-lg bg-yellow-500/10 border border-yellow-500/20">
                        <p class="text-sm text-yellow-400">
                            No WordPress sites connected. 
                            <a href="/settings/integrations" class="underline hover:text-yellow-300">
                                Connect a WordPress site
                            </a> 
                            to enable deployment.
                        </p>
                    </div>

                    <!-- Additional Options -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Environment</label>
                            <select v-model="form.environment" class="form-select">
                                <option value="staging">Staging First</option>
                                <option value="production">Production Direct</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Budget Limit (Optional)</label>
                        <div class="flex items-center gap-3">
                            <span class="text-body">$</span>
                            <input
                                v-model.number="form.budget_limit"
                                type="number"
                                min="10"
                                max="500"
                                class="form-input"
                                placeholder="50"
                            />
                            <span class="text-caption">AI costs for this project</span>
                        </div>
                        <p v-if="errors.budget_limit" class="form-error">{{ errors.budget_limit[0] }}</p>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end gap-3">
                        <button type="button" @click="resetForm" class="btn btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" :disabled="loading" class="btn btn-primary">
                            <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span v-else>{{ selectedMode === 'autonomous' ? 'Start Build' : 'Continue' }}</span>
                        </button>
                    </div>

                    <p v-if="errors.general" class="form-error">{{ errors.general[0] }}</p>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 1.5rem;
}

.modes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.mode-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    padding: 2rem;
    background: var(--color-bg-secondary);
    border: 2px solid var(--color-border-subtle);
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
    text-align: left;
}

.mode-card:hover {
    border-color: var(--color-accent);
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

.mode-card-purple:hover {
    border-color: #8b5cf6;
}

.mode-card-blue:hover {
    border-color: #3b82f6;
}

.mode-card-green:hover {
    border-color: #10b981;
}

.mode-card-orange:hover {
    border-color: #f59e0b;
}

.mode-icon {
    font-size: 3rem;
    margin-bottom: 1rem;
}

.mode-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.mode-description {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin-bottom: 1rem;
    flex: 1;
}

.mode-recommended {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
    width: 100%;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.form-input,
.form-select {
    width: 100%;
    padding: 0.625rem 0.875rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-strong);
    border-radius: 6px;
    font-size: 0.875rem;
    color: var(--color-text-primary);
    transition: border-color 0.15s ease;
}

.form-input:focus,
.form-select:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-error {
    font-size: 0.75rem;
    color: var(--color-status-red);
    margin-top: 0.25rem;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

/* Override global card-body padding:0 */
.card .card-body {
    padding: 1.5rem;
}

/* Projects Section */
.projects-section {
    margin-top: 2rem;
}

.project-row {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.project-row:last-child {
    border-bottom: none;
}

.project-info {
    flex: 1;
    min-width: 0;
}

.project-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 0.25rem;
}

.project-name {
    font-weight: 600;
    color: var(--color-text-primary);
}

.project-meta {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

.project-progress {
    width: 120px;
    flex-shrink: 0;
}

.progress-label {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.25rem;
    text-align: right;
}

.progress-bar {
    height: 6px;
    background: var(--color-bg-tertiary);
    border-radius: 3px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--color-accent);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.project-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.text-red {
    color: var(--color-status-red) !important;
}

.text-red:hover {
    background: rgba(239, 68, 68, 0.1) !important;
}
</style>
