<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface ClientData {
    id: number;
    name: string;
    hours: number;
    billable_hours: number;
    revenue: number;
    cost: number;
    profit: number;
    margin: number;
    effective_rate: number;
}

const props = defineProps<{
    byClient: ClientData[];
    totals: {
        hours: number;
        billable_hours: number;
        revenue: number;
        cost: number;
        profit: number;
        margin: number;
    };
    filters: {
        period: string;
        year: number;
        month: number;
    };
    dateRange: {
        start: string;
        end: string;
    };
}>();

const period = ref(props.filters.period);
const year = ref(String(props.filters.year));
const month = ref(String(props.filters.month));

const periodOptions = [
    { value: 'month', label: 'Monthly' },
    { value: 'quarter', label: 'Quarterly' },
    { value: 'year', label: 'Yearly' },
];

const yearOptions = Array.from({ length: 5 }, (_, i) => {
    const y = new Date().getFullYear() - i;
    return { value: String(y), label: String(y) };
});

const monthOptions = [
    { value: '1', label: 'January' },
    { value: '2', label: 'February' },
    { value: '3', label: 'March' },
    { value: '4', label: 'April' },
    { value: '5', label: 'May' },
    { value: '6', label: 'June' },
    { value: '7', label: 'July' },
    { value: '8', label: 'August' },
    { value: '9', label: 'September' },
    { value: '10', label: 'October' },
    { value: '11', label: 'November' },
    { value: '12', label: 'December' },
];

const applyFilters = () => {
    router.get('/reports/profitability', {
        period: period.value,
        year: year.value,
        month: month.value,
    }, { preserveState: true });
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const formatHours = (hours: number) => hours.toFixed(1);

const getMarginClass = (margin: number) => {
    if (margin >= 50) return 'margin-great';
    if (margin >= 30) return 'margin-good';
    if (margin >= 0) return 'margin-okay';
    return 'margin-bad';
};
</script>

<template>
    <AppLayout title="Profitability Report">
        <div class="page-header">
            <div>
                <Link href="/reports" class="back-link">
                    <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    Reports
                </Link>
                <h1 class="page-title">Profitability Report</h1>
                <p class="page-subtitle">{{ dateRange.start }} to {{ dateRange.end }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <label class="filter-label">Period</label>
                <FormSelect v-model="period" :options="periodOptions" @update:modelValue="applyFilters" />
            </div>
            <div class="filter-group">
                <label class="filter-label">Year</label>
                <FormSelect v-model="year" :options="yearOptions" @update:modelValue="applyFilters" />
            </div>
            <div class="filter-group" v-if="period === 'month' || period === 'quarter'">
                <label class="filter-label">{{ period === 'quarter' ? 'Quarter Start' : 'Month' }}</label>
                <FormSelect v-model="month" :options="monthOptions" @update:modelValue="applyFilters" />
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-stat">
                <span class="stat-value">{{ formatCurrency(totals.revenue) }}</span>
                <span class="stat-label">Revenue</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ formatCurrency(totals.cost) }}</span>
                <span class="stat-label">Cost</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value" :class="getMarginClass(totals.margin)">{{ formatCurrency(totals.profit) }}</span>
                <span class="stat-label">Profit</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value" :class="getMarginClass(totals.margin)">{{ totals.margin }}%</span>
                <span class="stat-label">Margin</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ formatHours(totals.hours) }}h</span>
                <span class="stat-label">Total Hours</span>
            </div>
        </div>

        <!-- By Client Table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">By Client</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th class="text-right">Hours</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">Cost</th>
                            <th class="text-right">Profit</th>
                            <th class="text-right">Margin</th>
                            <th class="text-right">Eff. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in byClient" :key="client.id">
                            <td>
                                <Link :href="`/clients/${client.id}`" class="client-link">{{ client.name }}</Link>
                            </td>
                            <td class="text-right tabular">{{ formatHours(client.hours) }}</td>
                            <td class="text-right tabular">{{ formatCurrency(client.revenue) }}</td>
                            <td class="text-right tabular">{{ formatCurrency(client.cost) }}</td>
                            <td class="text-right tabular" :class="getMarginClass(client.margin)">
                                {{ formatCurrency(client.profit) }}
                            </td>
                            <td class="text-right tabular">
                                <span class="margin-badge" :class="getMarginClass(client.margin)">
                                    {{ client.margin }}%
                                </span>
                            </td>
                            <td class="text-right tabular">{{ formatCurrency(client.effective_rate) }}/hr</td>
                        </tr>
                        <tr v-if="byClient.length === 0">
                            <td colspan="7" class="text-center text-muted">No data for this period</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="byClient.length > 0">
                        <tr>
                            <td><strong>Total</strong></td>
                            <td class="text-right tabular"><strong>{{ formatHours(totals.hours) }}</strong></td>
                            <td class="text-right tabular"><strong>{{ formatCurrency(totals.revenue) }}</strong></td>
                            <td class="text-right tabular"><strong>{{ formatCurrency(totals.cost) }}</strong></td>
                            <td class="text-right tabular"><strong>{{ formatCurrency(totals.profit) }}</strong></td>
                            <td class="text-right tabular"><strong>{{ totals.margin }}%</strong></td>
                            <td></td>
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
.summary-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.summary-stat { display: flex; flex-direction: column; align-items: center; padding: 1rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 10px; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
.stat-label { font-size: 0.75rem; color: var(--color-text-tertiary); margin-top: 0.25rem; }
.card { background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 12px; overflow: hidden; }
.card-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border-subtle); }
.card-title { font-weight: 600; color: var(--color-text-primary); }
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
.client-link { color: var(--color-accent); text-decoration: none; }
.client-link:hover { text-decoration: underline; }
.margin-great { color: var(--color-status-green); }
.margin-good { color: #22c55e; }
.margin-okay { color: var(--color-status-yellow); }
.margin-bad { color: var(--color-status-red); }
.margin-badge { display: inline-block; padding: 0.125rem 0.375rem; border-radius: 4px; font-size: 0.75rem; font-weight: 500; }
.margin-badge.margin-great { background: rgba(34, 197, 94, 0.15); }
.margin-badge.margin-good { background: rgba(34, 197, 94, 0.1); }
.margin-badge.margin-okay { background: rgba(234, 179, 8, 0.15); }
.margin-badge.margin-bad { background: rgba(239, 68, 68, 0.15); }
</style>
