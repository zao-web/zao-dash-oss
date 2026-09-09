<script setup lang="ts">
import { Head, usePage, router } from '@inertiajs/vue3';
import { computed } from 'vue';

interface Project {
    id: number;
    name: string;
    status: string;
    progress: number;
    next_milestone: string | null;
    due_date: string | null;
}

interface Invoice {
    id: number;
    number: string;
    date: string;
    amount: number;
    status: string;
}

interface Props {
    client: {
        id: number;
        name: string;
    };
    activeProjects: Project[];
    recentInvoices: Invoice[];
    stats: {
        total_projects: number;
        active_projects: number;
        open_invoices: number;
    };
}

defineProps<Props>();

const page = usePage();
const impersonating = computed(() => page.props.impersonating as { active: boolean; clientName?: string } | undefined);
const userName = computed(() => {
    const name = (page.props.auth as any)?.user?.name || '';
    return name.split(' ')[0];
});

const exitImpersonation = () => {
    router.post('/impersonate/stop');
};

const statusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-100 text-green-800',
        in_progress: 'bg-blue-100 text-blue-800',
        completed: 'bg-gray-100 text-gray-800',
        open: 'bg-yellow-100 text-yellow-800',
        paid: 'bg-green-100 text-green-800',
        overdue: 'bg-red-100 text-red-800',
    };
    return colors[status] || 'bg-gray-100 text-gray-800';
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
    }).format(amount);
};
</script>

<template>
    <Head :title="`${client.name} Portal`" />

    <div class="min-h-screen bg-gray-50">
        <!-- Impersonation Banner -->
        <div v-if="impersonating?.active" class="bg-amber-500 text-white px-4 py-2">
            <div class="max-w-7xl mx-auto flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 2C5.58 2 2 5.58 2 10s3.58 8 8 8 8-3.58 8-8-3.58-8-8-8zm0 14.5c-3.58 0-6.5-2.92-6.5-6.5S6.42 3.5 10 3.5s6.5 2.92 6.5 6.5-2.92 6.5-6.5 6.5z"/>
                        <circle cx="10" cy="10" r="3"/>
                    </svg>
                    <span class="font-medium">
                        Viewing as <strong>{{ impersonating.clientName }}</strong>
                    </span>
                </div>
                <button
                    @click="exitImpersonation"
                    class="px-4 py-1 bg-white/20 hover:bg-white/30 rounded text-sm font-medium transition"
                >
                    Exit Preview
                </button>
            </div>
        </div>

        <header class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto px-4 py-6">
                <h1 class="text-2xl font-bold text-gray-900">
                    Welcome, {{ userName || client.name }}
                </h1>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 py-8">
            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-sm text-gray-500">Total Projects</div>
                    <div class="text-3xl font-bold text-gray-900">{{ stats.total_projects }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-sm text-gray-500">Active Projects</div>
                    <div class="text-3xl font-bold text-blue-600">{{ stats.active_projects }}</div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="text-sm text-gray-500">Open Invoices</div>
                    <div class="text-3xl font-bold text-yellow-600">{{ stats.open_invoices }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Active Projects -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-900">Active Projects</h2>
                        <a href="/portal/projects" class="text-sm text-blue-600 hover:text-blue-800">
                            View All
                        </a>
                    </div>
                    <div class="divide-y divide-gray-200">
                        <div
                            v-for="project in activeProjects"
                            :key="project.id"
                            class="px-6 py-4"
                        >
                            <div class="flex justify-between items-start mb-2">
                                <a
                                    :href="`/portal/projects/${project.id}`"
                                    class="font-medium text-gray-900 hover:text-blue-600"
                                >
                                    {{ project.name }}
                                </a>
                                <span
                                    :class="statusColor(project.status)"
                                    class="text-xs px-2 py-1 rounded-full"
                                >
                                    {{ project.status }}
                                </span>
                            </div>
                            <div class="mb-2">
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
                            <div class="flex justify-between text-sm text-gray-500">
                                <span v-if="project.next_milestone">
                                    Next: {{ project.next_milestone }}
                                </span>
                                <span v-if="project.due_date">
                                    Due: {{ project.due_date }}
                                </span>
                            </div>
                        </div>
                        <div v-if="!activeProjects.length" class="px-6 py-8 text-center text-gray-500">
                            No active projects
                        </div>
                    </div>
                </div>

                <!-- Recent Invoices -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-900">Recent Invoices</h2>
                        <div class="flex gap-4">
                            <a href="/portal/statement" class="text-sm text-gray-500 hover:text-gray-700">
                                Statement
                            </a>
                            <a href="/portal/invoices" class="text-sm text-blue-600 hover:text-blue-800">
                                View All
                            </a>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200">
                        <div
                            v-for="invoice in recentInvoices"
                            :key="invoice.id"
                            class="px-6 py-4 flex justify-between items-center"
                        >
                            <div>
                                <div class="font-medium text-gray-900">
                                    Invoice #{{ invoice.number }}
                                </div>
                                <div class="text-sm text-gray-500">{{ invoice.date }}</div>
                            </div>
                            <div class="text-right">
                                <div class="font-medium text-gray-900">
                                    {{ formatCurrency(invoice.amount) }}
                                </div>
                                <span
                                    :class="statusColor(invoice.status)"
                                    class="text-xs px-2 py-1 rounded-full"
                                >
                                    {{ invoice.status }}
                                </span>
                            </div>
                        </div>
                        <div v-if="!recentInvoices.length" class="px-6 py-8 text-center text-gray-500">
                            No invoices yet
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
