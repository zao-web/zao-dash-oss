<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface Milestone {
    id: number;
    name: string;
    status: string;
    due_date: string | null;
}

interface Update {
    id: number;
    content: string;
    created_at: string;
}

interface Project {
    id: number;
    name: string;
    description: string | null;
    status: string;
    progress: number;
    start_date: string | null;
    due_date: string | null;
    milestones: Milestone[];
    recent_updates: Update[];
}

interface Props {
    projects: Project[];
}

defineProps<Props>();

const statusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-100 text-green-800',
        in_progress: 'bg-blue-100 text-blue-800',
        completed: 'bg-gray-100 text-gray-800',
        on_hold: 'bg-yellow-100 text-yellow-800',
        pending: 'bg-orange-100 text-orange-800',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
};
</script>

<template>
    <Head title="My Projects" />

    <div class="min-h-screen bg-gray-50">
        <header class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <div class="flex items-center gap-4">
                    <a href="/portal" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </a>
                    <h1 class="text-2xl font-bold text-gray-900">My Projects</h1>
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <div class="space-y-6">
                <div
                    v-for="project in projects"
                    :key="project.id"
                    class="bg-white rounded-lg shadow overflow-hidden"
                >
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex justify-between items-start">
                            <div>
                                <a
                                    :href="`/portal/projects/${project.id}`"
                                    class="text-lg font-semibold text-gray-900 hover:text-blue-600"
                                >
                                    {{ project.name }}
                                </a>
                                <p v-if="project.description" class="text-sm text-gray-500 mt-1">
                                    {{ project.description }}
                                </p>
                            </div>
                            <span
                                :class="statusColor(project.status)"
                                class="text-xs px-2 py-1 rounded-full"
                            >
                                {{ project.status }}
                            </span>
                        </div>
                    </div>

                    <div class="px-6 py-4">
                        <!-- Progress -->
                        <div class="mb-4">
                            <div class="flex justify-between text-sm text-gray-500 mb-1">
                                <span>Progress</span>
                                <span>{{ project.progress }}%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div
                                    class="bg-blue-600 h-2 rounded-full transition-all"
                                    :style="{ width: `${project.progress}%` }"
                                />
                            </div>
                        </div>

                        <!-- Dates -->
                        <div class="flex gap-6 text-sm text-gray-500 mb-4">
                            <span v-if="project.start_date">Started: {{ project.start_date }}</span>
                            <span v-if="project.due_date">Due: {{ project.due_date }}</span>
                        </div>

                        <!-- Milestones -->
                        <div v-if="project.milestones.length" class="mb-4">
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Milestones</h4>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="milestone in project.milestones"
                                    :key="milestone.id"
                                    :class="statusColor(milestone.status)"
                                    class="text-xs px-2 py-1 rounded-full"
                                >
                                    {{ milestone.name }}
                                    <span v-if="milestone.due_date" class="opacity-75">
                                        ({{ milestone.due_date }})
                                    </span>
                                </span>
                            </div>
                        </div>

                        <!-- Recent Updates -->
                        <div v-if="project.recent_updates.length">
                            <h4 class="text-sm font-medium text-gray-700 mb-2">Recent Updates</h4>
                            <div class="space-y-2">
                                <div
                                    v-for="update in project.recent_updates"
                                    :key="update.id"
                                    class="text-sm text-gray-600 bg-gray-50 rounded p-2"
                                >
                                    <p>{{ update.content }}</p>
                                    <span class="text-xs text-gray-400">{{ update.created_at }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="!projects.length" class="bg-white rounded-lg shadow px-6 py-12 text-center text-gray-500">
                    No projects found
                </div>
            </div>
        </main>
    </div>
</template>
