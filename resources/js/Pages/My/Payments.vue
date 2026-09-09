<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';

interface Payment {
    id: number;
    amount: number;
    currency: string;
    source_amount: number;
    source_currency: string;
    exchange_rate: number;
    fee: number;
    reference: string | null;
    payment_type: string;
    invoice_number: string | null;
    completed_at: string;
}

const props = defineProps<{
    payments: {
        data: Payment[];
        links: any[];
        meta: any;
    };
    summary: {
        total_received: number;
        pending_invoices: number;
        this_year: number;
    };
}>();

const formatCurrency = (amount: number, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
};
</script>

<template>
    <AppLayout title="My Payments">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-[var(--color-text-primary)]">My Payments</h1>
                <p class="text-sm text-[var(--color-text-tertiary)]">View your payment history</p>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="card p-6">
                    <div class="text-sm text-[var(--color-text-tertiary)] mb-1">Total Received</div>
                    <div class="text-2xl font-bold text-[var(--color-text-primary)]">{{ formatCurrency(summary.total_received) }}</div>
                </div>
                <div class="card p-6">
                    <div class="text-sm text-[var(--color-text-tertiary)] mb-1">This Year</div>
                    <div class="text-2xl font-bold text-green-600">{{ formatCurrency(summary.this_year) }}</div>
                </div>
                <div class="card p-6">
                    <div class="text-sm text-[var(--color-text-tertiary)] mb-1">Pending Invoices</div>
                    <div class="text-2xl font-bold text-yellow-600">{{ formatCurrency(summary.pending_invoices) }}</div>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="card overflow-hidden">
                <table class="min-w-full divide-y divide-[var(--color-border-default)]">
                    <thead class="bg-[var(--color-bg-tertiary)]">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Reference</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-[var(--color-text-tertiary)] uppercase">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-default)]">
                        <tr v-for="payment in payments.data" :key="payment.id" class="hover:bg-[var(--color-bg-tertiary)]">
                            <td class="px-6 py-4 text-sm text-[var(--color-text-primary)]">{{ payment.completed_at }}</td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-[var(--color-text-primary)]">{{ formatCurrency(payment.amount, payment.currency) }}</div>
                                <div v-if="payment.source_currency !== payment.currency" class="text-xs text-[var(--color-text-tertiary)]">
                                    Sent: {{ formatCurrency(payment.source_amount, payment.source_currency) }}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 text-xs font-medium rounded-full capitalize"
                                    :class="payment.payment_type === 'recurring' ? 'bg-purple-500/20 text-purple-400' : 'bg-blue-500/20 text-blue-400'">
                                    {{ payment.payment_type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-[var(--color-text-tertiary)]">
                                {{ payment.reference || payment.invoice_number || '-' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-[var(--color-text-tertiary)]">
                                <div v-if="payment.exchange_rate !== 1">Rate: {{ payment.exchange_rate.toFixed(4) }}</div>
                                <div v-if="payment.fee">Fee: {{ formatCurrency(payment.fee, payment.source_currency) }}</div>
                            </td>
                        </tr>
                        <tr v-if="payments.data.length === 0">
                            <td colspan="5" class="px-6 py-12 text-center text-[var(--color-text-tertiary)]">
                                <div class="text-lg mb-2">No payments yet</div>
                                <div class="text-sm">Payments will appear here once processed</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Tax Note -->
            <div class="mt-6 p-4 bg-[var(--color-bg-tertiary)] rounded-lg">
                <p class="text-sm text-[var(--color-text-secondary)]">
                    <strong>Tax Note:</strong> This payment history is for your records. If you received $600 or more during the tax year, you may receive a 1099-NEC form.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
