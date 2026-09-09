<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface Payment {
    id: number;
    date: string;
    amount: number;
    method: string;
    transaction_id: string | null;
    reference: string | null;
    invoice_number: string | null;
    invoice_id: number;
    client: string | null;
}

const props = defineProps<{
    payments: Payment[];
    byMonth: { month: string; count: number; total: number }[];
    byMethod: { method: string; count: number; total: number }[];
    byClient: { client: string; count: number; total: number }[];
    totals: { count: number; total: number };
    filters: { start_date: string; end_date: string; client_id: string | null };
    clients: { id: number; name: string }[];
}>();

const startDate = ref(props.filters.start_date);
const endDate = ref(props.filters.end_date);
const clientId = ref(props.filters.client_id || '');

const clientOptions = computed(() => [
    { value: '', label: 'All Clients' },
    ...props.clients.map(c => ({ value: String(c.id), label: c.name })),
]);

const applyFilters = () => {
    router.get('/reports/payments', {
        start_date: startDate.value,
        end_date: endDate.value,
        client_id: clientId.value || undefined,
    }, { preserveState: true });
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const formatMethod = (method: string) => {
    return method.charAt(0).toUpperCase() + method.slice(1).replace('_', ' ');
};
</script>

<template>
    <AppLayout title="Payments Report">
        <div class="page-header">
            <div>
                <Link href="/reports" class="back-link">
                    <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    Reports
                </Link>
                <h1 class="page-title">Payments Received</h1>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-bar">
            <div class="filter-group">
                <label class="filter-label">From</label>
                <input type="date" v-model="startDate" @change="applyFilters" class="filter-input" />
            </div>
            <div class="filter-group">
                <label class="filter-label">To</label>
                <input type="date" v-model="endDate" @change="applyFilters" class="filter-input" />
            </div>
            <div class="filter-group">
                <label class="filter-label">Client</label>
                <FormSelect v-model="clientId" :options="clientOptions" @update:modelValue="applyFilters" />
            </div>
        </div>

        <!-- Summary -->
        <div class="summary-row">
            <div class="summary-stat large">
                <span class="stat-value">{{ formatCurrency(totals.total) }}</span>
                <span class="stat-label">Total Received</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ totals.count }}</span>
                <span class="stat-label">Payments</span>
            </div>
        </div>

        <!-- Breakdowns -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <!-- By Month -->
            <div class="card">
                <div class="card-header"><span class="card-title">By Month</span></div>
                <div class="breakdown-list">
                    <div v-for="item in byMonth" :key="item.month" class="breakdown-item">
                        <span class="breakdown-label">{{ item.month }}</span>
                        <span class="breakdown-value">{{ formatCurrency(item.total) }}</span>
                    </div>
                    <div v-if="byMonth.length === 0" class="breakdown-empty">No payments</div>
                </div>
            </div>

            <!-- By Method -->
            <div class="card">
                <div class="card-header"><span class="card-title">By Method</span></div>
                <div class="breakdown-list">
                    <div v-for="item in byMethod" :key="item.method" class="breakdown-item">
                        <span class="breakdown-label">{{ formatMethod(item.method) }}</span>
                        <span class="breakdown-value">{{ formatCurrency(item.total) }}</span>
                    </div>
                    <div v-if="byMethod.length === 0" class="breakdown-empty">No payments</div>
                </div>
            </div>

            <!-- By Client -->
            <div class="card">
                <div class="card-header"><span class="card-title">By Client</span></div>
                <div class="breakdown-list">
                    <div v-for="item in byClient" :key="item.client" class="breakdown-item">
                        <span class="breakdown-label">{{ item.client }}</span>
                        <span class="breakdown-value">{{ formatCurrency(item.total) }}</span>
                    </div>
                    <div v-if="byClient.length === 0" class="breakdown-empty">No payments</div>
                </div>
            </div>
        </div>

        <!-- Payment Details -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Payment Details</span>
                <span class="text-caption">{{ payments.length }} payments</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Invoice</th>
                            <th>Method</th>
                            <th>Reference</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="payment in payments" :key="payment.id">
                            <td class="whitespace-nowrap">{{ payment.date }}</td>
                            <td>{{ payment.client || '-' }}</td>
                            <td>
                                <Link v-if="payment.invoice_id" :href="`/invoices/${payment.invoice_id}`" class="invoice-link">
                                    #{{ payment.invoice_number }}
                                </Link>
                                <span v-else>-</span>
                            </td>
                            <td>{{ formatMethod(payment.method) }}</td>
                            <td class="text-muted">{{ payment.transaction_id || payment.reference || '-' }}</td>
                            <td class="text-right tabular">{{ formatCurrency(payment.amount) }}</td>
                        </tr>
                        <tr v-if="payments.length === 0">
                            <td colspan="6" class="text-center text-muted">No payments found</td>
                        </tr>
                    </tbody>
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
.filters-bar { display: flex; flex-wrap: wrap; gap: 1rem; padding: 1rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 12px; margin-bottom: 1.5rem; }
.filter-group { display: flex; flex-direction: column; gap: 0.25rem; }
.filter-label { font-size: 0.75rem; font-weight: 500; color: var(--color-text-tertiary); }
.filter-input { padding: 0.5rem 0.75rem; background: var(--color-bg-primary); border: 1px solid var(--color-border-default); border-radius: 6px; font-size: 0.875rem; color: var(--color-text-primary); }
.summary-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
.summary-stat { display: flex; flex-direction: column; align-items: center; padding: 1rem 1.5rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 10px; }
.summary-stat.large { flex: 1; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
.summary-stat.large .stat-value { font-size: 2rem; color: var(--color-status-green); }
.stat-label { font-size: 0.75rem; color: var(--color-text-tertiary); margin-top: 0.25rem; }
.grid { display: grid; }
.grid-cols-1 { grid-template-columns: 1fr; }
@media (min-width: 768px) { .md\:grid-cols-3 { grid-template-columns: repeat(3, 1fr); } }
.gap-4 { gap: 1rem; }
.mb-6 { margin-bottom: 1.5rem; }
.card { background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 12px; overflow: hidden; }
.card-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border-subtle); }
.card-title { font-weight: 600; color: var(--color-text-primary); }
.breakdown-list { padding: 0.5rem 0; }
.breakdown-item { display: flex; justify-content: space-between; padding: 0.5rem 1.25rem; }
.breakdown-item:hover { background: var(--color-bg-tertiary); }
.breakdown-label { color: var(--color-text-secondary); }
.breakdown-value { font-weight: 600; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
.breakdown-empty { padding: 1rem; text-align: center; color: var(--color-text-tertiary); }
.table-container { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid var(--color-border-subtle); }
.data-table th { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-tertiary); background: var(--color-bg-tertiary); }
.data-table tbody tr:hover { background: var(--color-bg-tertiary); }
.text-right { text-align: right; }
.text-center { text-align: center; }
.tabular { font-variant-numeric: tabular-nums; }
.text-muted { color: var(--color-text-tertiary); }
.whitespace-nowrap { white-space: nowrap; }
.invoice-link { color: var(--color-accent); text-decoration: none; }
.invoice-link:hover { text-decoration: underline; }
</style>
