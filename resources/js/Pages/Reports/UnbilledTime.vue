<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { ref, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface TimeEntry {
    id: number;
    date: string;
    hours: number;
    notes: string | null;
    client: string | null;
    client_id: number | null;
    project: string | null;
    project_id: number | null;
    task: string | null;
    hourly_rate: number;
    amount: number;
}

interface GroupedData {
    id: number;
    name: string;
    hours: number;
    amount: number;
    entry_count: number;
}

const props = defineProps<{
    entries: TimeEntry[];
    byClient: GroupedData[];
    byProject: GroupedData[];
    totals: { hours: number; amount: number; entry_count: number };
    filters: { client_id: string | null; project_id: string | null };
    clients: { id: number; name: string }[];
    projects: { id: number; name: string; client_id: number }[];
}>();

const clientId = ref(props.filters.client_id || '');
const projectId = ref(props.filters.project_id || '');

const clientOptions = computed(() => [
    { value: '', label: 'All Clients' },
    ...props.clients.map(c => ({ value: String(c.id), label: c.name })),
]);

const projectOptions = computed(() => {
    let projects = props.projects;
    if (clientId.value) {
        projects = projects.filter(p => p.client_id === Number(clientId.value));
    }
    return [
        { value: '', label: 'All Projects' },
        ...projects.map(p => ({ value: String(p.id), label: p.name })),
    ];
});

const applyFilters = () => {
    router.get('/reports/unbilled-time', {
        client_id: clientId.value || undefined,
        project_id: projectId.value || undefined,
    }, { preserveState: true });
};

watch(clientId, () => {
    projectId.value = '';
    applyFilters();
});
watch(projectId, applyFilters);

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const formatHours = (hours: number) => hours.toFixed(2);
</script>

<template>
    <AppLayout title="Unbilled Time Report">
        <div class="page-header">
            <div>
                <Link href="/reports" class="back-link">
                    <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    Reports
                </Link>
                <h1 class="page-title">Unbilled Time</h1>
                <p class="page-subtitle">Billable time entries not yet invoiced</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <label class="filter-label">Client</label>
                <FormSelect v-model="clientId" :options="clientOptions" />
            </div>
            <div class="filter-group">
                <label class="filter-label">Project</label>
                <FormSelect v-model="projectId" :options="projectOptions" />
            </div>
        </div>

        <!-- Summary -->
        <div class="summary-row">
            <div class="summary-stat large">
                <span class="stat-value highlight">{{ formatCurrency(totals.amount) }}</span>
                <span class="stat-label">Unbilled Amount</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ formatHours(totals.hours) }}</span>
                <span class="stat-label">Unbilled Hours</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ totals.entry_count }}</span>
                <span class="stat-label">Entries</span>
            </div>
        </div>

        <!-- Breakdowns -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <!-- By Client -->
            <div class="card">
                <div class="card-header"><span class="card-title">By Client</span></div>
                <div class="breakdown-list">
                    <Link
                        v-for="item in byClient"
                        :key="item.id"
                        :href="`/clients/${item.id}`"
                        class="breakdown-item clickable"
                    >
                        <div class="breakdown-info">
                            <span class="breakdown-label">{{ item.name }}</span>
                            <span class="breakdown-meta">{{ item.entry_count }} entries / {{ formatHours(item.hours) }}h</span>
                        </div>
                        <span class="breakdown-value">{{ formatCurrency(item.amount) }}</span>
                    </Link>
                    <div v-if="byClient.length === 0" class="breakdown-empty">No unbilled time</div>
                </div>
            </div>

            <!-- By Project -->
            <div class="card">
                <div class="card-header"><span class="card-title">By Project</span></div>
                <div class="breakdown-list">
                    <Link
                        v-for="item in byProject"
                        :key="item.id"
                        :href="`/projects/${item.id}`"
                        class="breakdown-item clickable"
                    >
                        <div class="breakdown-info">
                            <span class="breakdown-label">{{ item.name }}</span>
                            <span class="breakdown-meta">{{ item.entry_count }} entries / {{ formatHours(item.hours) }}h</span>
                        </div>
                        <span class="breakdown-value">{{ formatCurrency(item.amount) }}</span>
                    </Link>
                    <div v-if="byProject.length === 0" class="breakdown-empty">No unbilled time</div>
                </div>
            </div>
        </div>

        <!-- Entry Details -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Unbilled Entries</span>
                <span class="text-caption">{{ entries.length }} entries</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Project</th>
                            <th>Notes</th>
                            <th class="text-right">Hours</th>
                            <th class="text-right">Rate</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries" :key="entry.id">
                            <td class="whitespace-nowrap">{{ entry.date }}</td>
                            <td>
                                <Link v-if="entry.client_id" :href="`/clients/${entry.client_id}`" class="link">
                                    {{ entry.client }}
                                </Link>
                                <span v-else>-</span>
                            </td>
                            <td>
                                <Link v-if="entry.project_id" :href="`/projects/${entry.project_id}`" class="link">
                                    {{ entry.project }}
                                </Link>
                                <span v-else>-</span>
                            </td>
                            <td class="notes-cell">{{ entry.notes || '-' }}</td>
                            <td class="text-right tabular">{{ formatHours(entry.hours) }}</td>
                            <td class="text-right tabular text-muted">{{ formatCurrency(entry.hourly_rate) }}/hr</td>
                            <td class="text-right tabular">{{ formatCurrency(entry.amount) }}</td>
                        </tr>
                        <tr v-if="entries.length === 0">
                            <td colspan="7" class="text-center text-muted">No unbilled time entries</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="entries.length > 0">
                        <tr>
                            <td colspan="4"><strong>Total</strong></td>
                            <td class="text-right tabular"><strong>{{ formatHours(totals.hours) }}</strong></td>
                            <td></td>
                            <td class="text-right tabular"><strong>{{ formatCurrency(totals.amount) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-header { margin-bottom: 1.5rem; }
.back-link { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.8125rem; color: var(--color-text-tertiary); text-decoration: none; margin-bottom: 0.5rem; }
.back-link:hover { color: var(--color-accent); }
.back-icon { width: 16px; height: 16px; }
.page-title { font-size: 1.5rem; font-weight: 600; color: var(--color-text-primary); }
.page-subtitle { font-size: 0.875rem; color: var(--color-text-tertiary); margin-top: 0.25rem; }
.filters-bar { display: flex; flex-wrap: wrap; gap: 1rem; padding: 1rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 12px; margin-bottom: 1.5rem; }
.filter-group { display: flex; flex-direction: column; gap: 0.25rem; }
.filter-label { font-size: 0.75rem; font-weight: 500; color: var(--color-text-tertiary); }
.summary-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.summary-stat { display: flex; flex-direction: column; align-items: center; padding: 1rem 1.5rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 10px; }
.summary-stat.large { flex: 1; min-width: 200px; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
.stat-value.highlight { font-size: 2rem; color: var(--color-status-yellow); }
.stat-label { font-size: 0.75rem; color: var(--color-text-tertiary); margin-top: 0.25rem; }
.grid { display: grid; }
.grid-cols-1 { grid-template-columns: 1fr; }
@media (min-width: 768px) { .md\:grid-cols-2 { grid-template-columns: repeat(2, 1fr); } }
.gap-4 { gap: 1rem; }
.mb-6 { margin-bottom: 1.5rem; }
.card { background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 12px; overflow: hidden; }
.card-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border-subtle); }
.card-title { font-weight: 600; color: var(--color-text-primary); }
.breakdown-list { padding: 0.5rem 0; }
.breakdown-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1.25rem; }
.breakdown-item.clickable { text-decoration: none; cursor: pointer; }
.breakdown-item.clickable:hover { background: var(--color-bg-tertiary); }
.breakdown-info { display: flex; flex-direction: column; gap: 0.125rem; }
.breakdown-label { color: var(--color-text-primary); font-weight: 500; }
.breakdown-meta { font-size: 0.75rem; color: var(--color-text-tertiary); }
.breakdown-value { font-weight: 600; color: var(--color-status-yellow); font-variant-numeric: tabular-nums; }
.breakdown-empty { padding: 1rem; text-align: center; color: var(--color-text-tertiary); }
.table-container { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid var(--color-border-subtle); }
.data-table th { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-tertiary); background: var(--color-bg-tertiary); }
.data-table tbody tr:hover { background: var(--color-bg-tertiary); }
.data-table tfoot td { background: var(--color-bg-tertiary); border-top: 2px solid var(--color-border-default); }
.text-right { text-align: right; }
.text-center { text-align: center; }
.tabular { font-variant-numeric: tabular-nums; }
.text-muted { color: var(--color-text-tertiary); }
.text-caption { font-size: 0.8125rem; color: var(--color-text-tertiary); }
.whitespace-nowrap { white-space: nowrap; }
.notes-cell { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.link { color: var(--color-accent); text-decoration: none; }
.link:hover { text-decoration: underline; }
</style>
