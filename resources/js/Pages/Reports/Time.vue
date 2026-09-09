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
    is_billable: boolean;
    is_billed: boolean;
    client: string | null;
    project: string | null;
    task: string | null;
    amount: number;
}

interface GroupedData {
    label: string;
    hours: number;
    billable_hours: number;
    amount: number;
}

interface Client {
    id: number;
    name: string;
}

interface Project {
    id: number;
    name: string;
    client_id: number;
}

const props = defineProps<{
    entries: TimeEntry[];
    grouped: GroupedData[];
    totals: {
        hours: number;
        billable_hours: number;
        non_billable_hours: number;
        amount: number;
        utilization: number;
    };
    filters: {
        start_date: string;
        end_date: string;
        client_id: string | null;
        project_id: string | null;
        group_by: string;
    };
    clients: Client[];
    projects: Project[];
}>();

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const clientId = ref(props.filters.client_id || '');
const projectId = ref(props.filters.project_id || '');
const groupBy = ref(props.filters.group_by);

const groupByOptions = [
    { value: 'date', label: 'By Date' },
    { value: 'client', label: 'By Client' },
    { value: 'project', label: 'By Project' },
    { value: 'task', label: 'By Task' },
];

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
    router.get('/reports/time', {
        start_date: startDate.value,
        end_date: endDate.value,
        client_id: clientId.value || undefined,
        project_id: projectId.value || undefined,
        group_by: groupBy.value,
    }, { preserveState: true });
};

watch(groupBy, applyFilters);
watch(clientId, () => {
    projectId.value = '';
    applyFilters();
});
watch(projectId, applyFilters);

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const formatHours = (hours: number) => {
    return hours.toFixed(2);
};
</script>

<template>
    <AppLayout title="Time Report">
        <div class="page-header">
            <div>
                <Link href="/reports" class="back-link">
                    <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    Reports
                </Link>
                <h1 class="page-title">Time Report</h1>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <label class="filter-label">From</label>
                <input
                    type="date"
                    v-model="startDate"
                    @change="applyFilters"
                    class="filter-input"
                />
            </div>
            <div class="filter-group">
                <label class="filter-label">To</label>
                <input
                    type="date"
                    v-model="endDate"
                    @change="applyFilters"
                    class="filter-input"
                />
            </div>
            <div class="filter-group">
                <label class="filter-label">Client</label>
                <FormSelect
                    v-model="clientId"
                    :options="clientOptions"
                    class="filter-select"
                />
            </div>
            <div class="filter-group">
                <label class="filter-label">Project</label>
                <FormSelect
                    v-model="projectId"
                    :options="projectOptions"
                    class="filter-select"
                />
            </div>
            <div class="filter-group">
                <label class="filter-label">Group by</label>
                <FormSelect
                    v-model="groupBy"
                    :options="groupByOptions"
                    class="filter-select"
                />
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-stat">
                <span class="stat-value">{{ formatHours(totals.hours) }}</span>
                <span class="stat-label">Total Hours</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ formatHours(totals.billable_hours) }}</span>
                <span class="stat-label">Billable</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ formatHours(totals.non_billable_hours) }}</span>
                <span class="stat-label">Non-Billable</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ totals.utilization }}%</span>
                <span class="stat-label">Utilization</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ formatCurrency(totals.amount) }}</span>
                <span class="stat-label">Billable Amount</span>
            </div>
        </div>

        <!-- Grouped Data -->
        <div class="card mb-6">
            <div class="card-header">
                <span class="card-title">{{ groupByOptions.find(o => o.value === groupBy)?.label }}</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>{{ groupBy === 'date' ? 'Date' : 'Name' }}</th>
                            <th class="text-right">Hours</th>
                            <th class="text-right">Billable</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, index) in grouped" :key="index">
                            <td>{{ item.label }}</td>
                            <td class="text-right tabular">{{ formatHours(item.hours) }}</td>
                            <td class="text-right tabular">{{ formatHours(item.billable_hours) }}</td>
                            <td class="text-right tabular">{{ formatCurrency(item.amount) }}</td>
                        </tr>
                        <tr v-if="grouped.length === 0">
                            <td colspan="4" class="text-center text-muted">No time entries found</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="grouped.length > 0">
                        <tr>
                            <td><strong>Total</strong></td>
                            <td class="text-right tabular"><strong>{{ formatHours(totals.hours) }}</strong></td>
                            <td class="text-right tabular"><strong>{{ formatHours(totals.billable_hours) }}</strong></td>
                            <td class="text-right tabular"><strong>{{ formatCurrency(totals.amount) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Detailed Entries -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Time Entries</span>
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
                            <th class="text-right">Amount</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="entry in entries" :key="entry.id">
                            <td class="whitespace-nowrap">{{ entry.date }}</td>
                            <td>{{ entry.client || '-' }}</td>
                            <td>{{ entry.project || '-' }}</td>
                            <td class="notes-cell">{{ entry.notes || '-' }}</td>
                            <td class="text-right tabular">{{ formatHours(entry.hours) }}</td>
                            <td class="text-right tabular">{{ entry.is_billable ? formatCurrency(entry.amount) : '-' }}</td>
                            <td class="text-center">
                                <span v-if="entry.is_billed" class="badge badge-success">Billed</span>
                                <span v-else-if="entry.is_billable" class="badge badge-warning">Unbilled</span>
                                <span v-else class="badge badge-muted">Non-billable</span>
                            </td>
                        </tr>
                        <tr v-if="entries.length === 0">
                            <td colspan="7" class="text-center text-muted">No time entries found</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.page-header {
    margin-bottom: 1.5rem;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    text-decoration: none;
    margin-bottom: 0.5rem;
}

.back-link:hover {
    color: var(--color-accent);
}

.back-icon {
    width: 16px;
    height: 16px;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.filters-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    margin-bottom: 1.5rem;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.filter-label {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-tertiary);
}

.filter-input {
    padding: 0.5rem 0.75rem;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.filter-select {
    min-width: 150px;
}

.summary-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.summary-stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 10px;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.stat-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

.card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    overflow: hidden;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.card-title {
    font-weight: 600;
    color: var(--color-text-primary);
}

.table-container {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th,
.data-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    border-bottom: 1px solid var(--color-border-subtle);
}

.data-table th {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
}

.data-table tbody tr:hover {
    background: var(--color-bg-tertiary);
}

.data-table tfoot td {
    background: var(--color-bg-tertiary);
    border-top: 2px solid var(--color-border-default);
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.tabular {
    font-variant-numeric: tabular-nums;
}

.text-muted {
    color: var(--color-text-tertiary);
}

.notes-cell {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.whitespace-nowrap {
    white-space: nowrap;
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.6875rem;
    font-weight: 500;
    border-radius: 4px;
}

.badge-success {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.badge-warning {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.badge-muted {
    background: var(--color-bg-tertiary);
    color: var(--color-text-tertiary);
}

.mb-6 {
    margin-bottom: 1.5rem;
}
</style>
