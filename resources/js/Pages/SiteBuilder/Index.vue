<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';

interface Project {
    id: number;
    project_name: string;
    domain: string;
    company_type: string;
    status: string;
    progress_percentage: number;
    live_url?: string;
}

const form = ref({
    domain: '',
    brief: '',
    company_type: 'active',
    target_hosting: 'wordpress_com',
    environment: 'staging',
    timeline: 'standard'
});

const loading = ref(false);
const projects = ref<Project[]>([]);

const companyTypeOptions = [
    { value: 'active', label: 'Active Company' },
    { value: 'defunct', label: 'Defunct Company' },
    { value: 'startup', label: 'Startup' },
    { value: 'enterprise', label: 'Enterprise' },
];

const hostingOptions = [
    { value: 'wordpress_com', label: 'WordPress.com' },
    { value: 'self_hosted', label: 'Self-Hosted' },
    { value: 'existing_site', label: 'Existing Site' },
];

const environmentOptions = [
    { value: 'staging', label: 'Staging First' },
    { value: 'production', label: 'Production Direct' },
];

const timelineOptions = [
    { value: 'rush', label: 'Rush (Fast)' },
    { value: 'standard', label: 'Standard' },
    { value: 'extended', label: 'Extended (Thorough)' },
];

const createProject = async () => {
    loading.value = true;

    try {
        const response = await fetch('/api/site-builder/projects', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify(form.value)
        });

        const result = await response.json();

        if (result.success) {
            router.visit(`/site-builder/${result.project.id}`);
        } else {
            alert('Error: ' + JSON.stringify(result.errors));
        }
    } catch (error: any) {
        alert('Error creating project: ' + error.message);
    } finally {
        loading.value = false;
    }
};

const openUrl = (url: string) => {
    window.open(url, '_blank');
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        pending: 'badge-yellow',
        in_progress: 'badge-blue',
        completed: 'badge-green',
        failed: 'badge-red',
    };
    return badges[status] || 'badge-gray';
};

onMounted(async () => {
    try {
        const response = await fetch('/api/site-builder/projects');
        const result = await response.json();

        if (result.success) {
            projects.value = result.projects?.data || [];
        }
    } catch (error) {
        console.error('Error loading projects:', error);
    }
});
</script>

<template>
    <AppLayout title="Site Builder">
        <!-- Header -->
        <div class="page-header mb-6">
            <div>
                <h1 class="page-title">Autonomous Site Builder</h1>
                <p class="text-caption">Build professional WordPress websites from domain names and briefs using AI agents.</p>
            </div>
        </div>

        <!-- Create Project Form -->
        <div class="card mb-6">
            <div class="card-header">
                <span class="card-title">Start New Project</span>
            </div>
            <div class="card-body">
                <form @submit.prevent="createProject" class="form-container">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <FormInput
                            v-model="form.domain"
                            label="Domain Name"
                            placeholder="example.com"
                            required
                        />
                        <FormSelect
                            v-model="form.company_type"
                            label="Company Type"
                            :options="companyTypeOptions"
                            required
                        />
                    </div>

                    <FormTextarea
                        v-model="form.brief"
                        label="Project Brief"
                        placeholder="Describe the company and what kind of website you want to build..."
                        :rows="4"
                        required
                    />

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <FormSelect
                            v-model="form.target_hosting"
                            label="Hosting Type"
                            :options="hostingOptions"
                            required
                        />
                        <FormSelect
                            v-model="form.environment"
                            label="Environment"
                            :options="environmentOptions"
                            required
                        />
                        <FormSelect
                            v-model="form.timeline"
                            label="Timeline"
                            :options="timelineOptions"
                            required
                        />
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="loading"
                            class="btn btn-primary"
                        >
                            <svg v-if="loading" class="h-4 w-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            {{ loading ? 'Building...' : 'Build Website' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Projects List -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Your Projects</span>
            </div>
            <div class="card-body">
                <div v-if="projects.length === 0" class="p-8 text-center">
                    <div class="avatar avatar-lg avatar-muted mx-auto mb-4">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                        </svg>
                    </div>
                    <p class="text-body">No projects yet</p>
                    <p class="text-caption mt-1">Create your first website using the form above</p>
                </div>

                <div v-else>
                    <div
                        v-for="project in projects"
                        :key="project.id"
                        class="list-item group"
                    >
                        <div class="list-item-content flex-1">
                            <div class="flex items-center gap-3">
                                <div class="list-item-title">{{ project.project_name }}</div>
                                <span :class="['badge', getStatusBadge(project.status)]">
                                    {{ project.status.replace('_', ' ') }}
                                </span>
                            </div>
                            <div class="list-item-subtitle">
                                {{ project.domain }} &middot; {{ project.company_type }}
                            </div>
                        </div>

                        <div class="flex items-center gap-6">
                            <!-- Progress -->
                            <div class="w-32 hidden md:block">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-caption">Progress</span>
                                    <span class="text-mono text-caption">{{ project.progress_percentage }}%</span>
                                </div>
                                <div class="progress-bar">
                                    <div
                                        class="progress-bar-fill"
                                        :style="{ width: project.progress_percentage + '%' }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2">
                                <button
                                    v-if="project.live_url"
                                    @click="openUrl(project.live_url)"
                                    class="btn btn-secondary btn-sm"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                    </svg>
                                    <span class="hidden sm:inline">View Site</span>
                                </button>
                                <Link
                                    :href="`/site-builder/${project.id}`"
                                    class="btn btn-ghost"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                    </svg>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.form-container {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}
</style>
