<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

interface Invoice {
    id: number;
    number: string;
    client: string | null;
    client_id: number;
    issue_date: string;
    due_date: string;
    total: number;
    amount_due: number;
    days_overdue: number;
    bucket: string;
    status: string;
}

const props = defineProps<{
    invoices: Invoice[];
    byBucket: Record<string, { count: number; amount: number }>;
    byClient: { client: string; count: number; total: number; oldest_days: number }[];
    totals: { count: number; total: number; overdue_count: number; overdue_amount: number };
}>();

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const bucketLabels: Record<string, string> = {
    'current': 'Current',
    '1-30': '1-30 Days',
    '31-60': '31-60 Days',
    '61-90': '61-90 Days',
    '90+': '90+ Days',
};

const getBucketClass = (bucket: string) => {
    if (bucket === 'current') return 'bucket-current';
    if (bucket === '1-30') return 'bucket-30';
    if (bucket === '31-60') return 'bucket-60';
    if (bucket === '61-90') return 'bucket-90';
    return 'bucket-90plus';
};
</script>

<template>
    <AppLayout title="AR Aging Report">
        <div class="page-header">
            <div>
                <Link href="/reports" class="back-link">
                    <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    Reports
                </Link>
                <h1 class="page-title">Accounts Receivable Aging</h1>
            </div>
        </div>

        <!-- Summary -->
        <div class="summary-row">
            <div class="summary-stat">
                <span class="stat-value outstanding">{{ formatCurrency(totals.total) }}</span>
                <span class="stat-label">Total Outstanding</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value">{{ totals.count }}</span>
                <span class="stat-label">Open Invoices</span>
            </div>
            <div class="summary-stat">
                <span class="stat-value overdue">{{ formatCurrency(totals.overdue_amount) }}</span>
                <span class="stat-label">Overdue ({{ totals.overdue_count }})</span>
            </div>
        </div>

        <!-- Aging Buckets -->
        <div class="aging-buckets">
            <div
                v-for="(bucket, key) in byBucket"
                :key="key"
                class="bucket-card"
                :class="getBucketClass(key)"
            >
                <div class="bucket-label">{{ bucketLabels[key] }}</div>
                <div class="bucket-amount">{{ formatCurrency(bucket.amount) }}</div>
                <div class="bucket-count">{{ bucket.count }} invoices</div>
            </div>
        </div>

        <!-- By Client -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
            <div class="card">
                <div class="card-header"><span class="card-title">By Client</span></div>
                <div class="table-container">
                    <table class="data-table compact">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th class="text-right">Invoices</th>
                                <th class="text-right">Outstanding</th>
                                <th class="text-right">Oldest</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in byClient" :key="item.client">
                                <td>{{ item.client }}</td>
                                <td class="text-right">{{ item.count }}</td>
                                <td class="text-right tabular">{{ formatCurrency(item.total) }}</td>
                                <td class="text-right">
                                    <span v-if="item.oldest_days > 0" class="overdue-badge">{{ item.oldest_days }}d</span>
                                    <span v-else class="current-badge">Current</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Invoice Details -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">Outstanding Invoices</span>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Client</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th class="text-right">Amount Due</th>
                            <th class="text-center">Age</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices" :key="invoice.id">
                            <td>
                                <Link :href="`/invoices/${invoice.id}`" class="invoice-link">#{{ invoice.number }}</Link>
                            </td>
                            <td>{{ invoice.client || '-' }}</td>
                            <td class="whitespace-nowrap">{{ invoice.issue_date }}</td>
                            <td class="whitespace-nowrap">{{ invoice.due_date }}</td>
                            <td class="text-right tabular">{{ formatCurrency(invoice.amount_due) }}</td>
                            <td class="text-center">
                                <span class="age-badge" :class="getBucketClass(invoice.bucket)">
                                    {{ invoice.days_overdue > 0 ? `${invoice.days_overdue}d overdue` : 'Current' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="invoices.length === 0">
                            <td colspan="6" class="text-center text-muted">No outstanding invoices</td>
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
.summary-row { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.summary-stat { display: flex; flex-direction: column; align-items: center; padding: 1rem 1.5rem; background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 10px; flex: 1; min-width: 150px; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--color-text-primary); font-variant-numeric: tabular-nums; }
.stat-value.outstanding { color: var(--color-status-red); }
.stat-value.overdue { color: var(--color-status-yellow); }
.stat-label { font-size: 0.75rem; color: var(--color-text-tertiary); margin-top: 0.25rem; }
.aging-buckets { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem; margin-bottom: 1.5rem; }
@media (max-width: 768px) { .aging-buckets { grid-template-columns: repeat(2, 1fr); } }
.bucket-card { padding: 1rem; border-radius: 10px; text-align: center; }
.bucket-card.bucket-current { background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); }
.bucket-card.bucket-30 { background: rgba(234, 179, 8, 0.1); border: 1px solid rgba(234, 179, 8, 0.3); }
.bucket-card.bucket-60 { background: rgba(249, 115, 22, 0.1); border: 1px solid rgba(249, 115, 22, 0.3); }
.bucket-card.bucket-90 { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); }
.bucket-card.bucket-90plus { background: rgba(220, 38, 38, 0.15); border: 1px solid rgba(220, 38, 38, 0.4); }
.bucket-label { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-tertiary); }
.bucket-amount { font-size: 1.25rem; font-weight: 700; color: var(--color-text-primary); margin-top: 0.25rem; font-variant-numeric: tabular-nums; }
.bucket-count { font-size: 0.75rem; color: var(--color-text-tertiary); }
.grid { display: grid; }
.grid-cols-1 { grid-template-columns: 1fr; }
@media (min-width: 1024px) { .lg\:grid-cols-2 { grid-template-columns: repeat(2, 1fr); } }
.gap-4 { gap: 1rem; }
.mb-6 { margin-bottom: 1.5rem; }
.card { background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle); border-radius: 12px; overflow: hidden; }
.card-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid var(--color-border-subtle); }
.card-title { font-weight: 600; color: var(--color-text-primary); }
.table-container { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 0.75rem 1rem; text-align: left; border-bottom: 1px solid var(--color-border-subtle); }
.data-table.compact th, .data-table.compact td { padding: 0.5rem 0.75rem; }
.data-table th { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-text-tertiary); background: var(--color-bg-tertiary); }
.data-table tbody tr:hover { background: var(--color-bg-tertiary); }
.text-right { text-align: right; }
.text-center { text-align: center; }
.tabular { font-variant-numeric: tabular-nums; }
.text-muted { color: var(--color-text-tertiary); }
.whitespace-nowrap { white-space: nowrap; }
.invoice-link { color: var(--color-accent); text-decoration: none; }
.invoice-link:hover { text-decoration: underline; }
.age-badge, .overdue-badge, .current-badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 4px; font-size: 0.6875rem; font-weight: 500; }
.age-badge.bucket-current, .current-badge { background: rgba(34, 197, 94, 0.15); color: var(--color-status-green); }
.age-badge.bucket-30 { background: rgba(234, 179, 8, 0.15); color: var(--color-status-yellow); }
.age-badge.bucket-60 { background: rgba(249, 115, 22, 0.15); color: #f97316; }
.age-badge.bucket-90, .age-badge.bucket-90plus, .overdue-badge { background: rgba(239, 68, 68, 0.15); color: var(--color-status-red); }
</style>
